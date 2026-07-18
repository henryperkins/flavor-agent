<?php

declare(strict_types=1);

// Usage: php tools/attestation-verify.php https://site.example att_xxx
[, $base, $id] = $argv + [ null, null, null ];

if ( ! is_string( $base ) || '' === $base || ! is_string( $id ) || '' === $id ) {
	fwrite( STDERR, "usage: attestation-verify.php <baseUrl> <attestationId>\n" );
	exit( 2 );
}

$base = rtrim( $base, '/' );

require_once __DIR__ . '/../inc/Attestation/Signer.php';
require_once __DIR__ . '/../inc/Attestation/StatementBuilder.php';
require_once __DIR__ . '/../inc/Attestation/StatementValidator.php';
require_once __DIR__ . '/../inc/Attestation/Verifier.php';
require_once __DIR__ . '/../inc/Attestation/RemoteVerifier.php';

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
		return [
			'status' => 502,
			'data'   => [ 'error' => 'request_failed' ],
		];
	}

	$status_line = $http_response_header[0] ?? '';
	$status      = 200;

	if ( '' !== $status_line && preg_match( '#\s(\d{3})\s#', $status_line, $matches ) ) {
		$status = (int) $matches[1];
	}

	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return [
			'status' => 502,
			'data'   => [ 'error' => 'invalid_json' ],
		];
	}

	return [
		'status' => $status,
		'data'   => $data,
	];
};

$result = \FlavorAgent\Attestation\RemoteVerifier::verify( $base, $id, $get );

if ( is_string( $result['error'] ?? null ) && '' !== $result['error'] ) {
	fwrite( STDERR, 'error: ' . $result['error'] . "\n" );
}

echo json_encode(
	[
		'attestationId'         => $result['attestationId'],
		'outcomes'              => $result['outcomes'],
		'verificationStatus'    => $result['verificationStatus'],
		'terminalAttestationId' => $result['terminalAttestationId'],
		'chainDepth'            => $result['chainDepth'],
		'subjectError'          => $result['subjectError'] ?? null,
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
),
"\n";

exit( (int) $result['exitCode'] );
