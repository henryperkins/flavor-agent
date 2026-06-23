<?php

declare(strict_types=1);

// Usage: php tools/attestation-verify.php https://site.example att_xxx
[, $base, $id] = $argv + [ null, null, null ];

if ( ! is_string( $base ) || '' === $base || ! is_string( $id ) || '' === $id ) {
	fwrite( STDERR, "usage: attestation-verify.php <baseUrl> <attestationId>\n" );
	exit( 2 );
}

$base = rtrim( $base, '/' );

$autoload = __DIR__ . '/../vendor/autoload.php';

if ( file_exists( $autoload ) ) {
	require $autoload;
} else {
	require __DIR__ . '/../inc/Attestation/Signer.php';
	require __DIR__ . '/../inc/Attestation/Verifier.php';
	require __DIR__ . '/../inc/Attestation/RemoteVerifier.php';
}

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

$result = \FlavorAgent\Attestation\RemoteVerifier::verify( $base, $id, $get );

if ( is_string( $result['error'] ?? null ) && '' !== $result['error'] ) {
	fwrite( STDERR, 'error: ' . $result['error'] . "\n" );
}

echo json_encode(
	[
		'attestationId' => $result['attestationId'],
		'outcomes'      => $result['outcomes'],
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
),
"\n";

exit( (int) $result['exitCode'] );
