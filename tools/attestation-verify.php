<?php

declare(strict_types=1);

// Usage: php tools/attestation-verify.php https://site.example att_xxx
[ , $base, $id ] = $argv + [ null, null, null ];

if ( ! is_string( $base ) || '' === $base || ! is_string( $id ) || '' === $id ) {
	fwrite( STDERR, "usage: attestation-verify.php <baseUrl> <attestationId>\n" );
	exit( 2 );
}

$base = rtrim( $base, '/' );

$get = static function ( string $url ): array {
	$context = stream_context_create(
		[
			'http' => [
				'timeout'       => 15,
				'ignore_errors' => true,
			],
		]
	);
	$raw     = @file_get_contents( $url, false, $context );

	if ( false === $raw ) {
		fwrite( STDERR, "error: request_failed: {$url}\n" );
		exit( 3 );
	}

	$status_line = $http_response_header[0] ?? '';

	if ( '' !== $status_line && ! preg_match( '#\s2\d\d\s#', $status_line ) ) {
		fwrite( STDERR, "error: http_error: {$status_line}\n" );
		exit( 3 );
	}

	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		fwrite( STDERR, "error: invalid_json: {$url}\n" );
		exit( 3 );
	}

	return $data;
};

$b64url_decode = static function ( string $value ): string {
	$decoded = base64_decode(
		strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
		true
	);

	return false === $decoded ? '' : $decoded;
};

$env  = $get( "{$base}/wp-json/flavor-agent/v1/attestations/{$id}" );
$jwks = $get( "{$base}/wp-json/flavor-agent/v1/attestations/keys" );
$subj = $get( "{$base}/wp-json/flavor-agent/v1/attestations/{$id}/subject-state" );

$statement = $b64url_decode( (string) ( $env['statement_b64'] ?? '' ) );
$signature = $b64url_decode( (string) ( $env['signature_b64'] ?? '' ) );
$live      = isset( $subj['subject_canonical_b64'] )
	? $b64url_decode( (string) $subj['subject_canonical_b64'] )
	: null;

require __DIR__ . '/../inc/Attestation/Signer.php';
require __DIR__ . '/../inc/Attestation/Verifier.php';

$outcomes = \FlavorAgent\Attestation\Verifier::evaluate(
	$statement,
	$signature,
	$jwks,
	$live,
	$env['reverted_by_attestation_id'] ?? null
);

echo json_encode(
	[
		'attestationId' => $id,
		'outcomes'      => $outcomes,
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
),
"\n";

exit( in_array( 'record_tampered', $outcomes, true ) ? 1 : 0 );
