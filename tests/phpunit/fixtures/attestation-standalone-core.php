<?php

declare(strict_types=1);

use FlavorAgent\Attestation\StatementBuilder;
use FlavorAgent\Attestation\Verifier;

$root = dirname( __DIR__, 3 );

require $root . '/inc/Attestation/Signer.php';
require $root . '/inc/Attestation/StatementBuilder.php';
require $root . '/inc/Attestation/StatementValidator.php';
require $root . '/inc/Attestation/Verifier.php';

$subject_bytes = 'standalone-subject';
$secret_key    = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
$public_key    = sodium_crypto_sign_publickey_from_secretkey( $secret_key );
$key_id        = substr( hash( 'sha256', $public_key ), 0, 32 );
$statement     = StatementBuilder::build(
	[
		'attestationId'      => 'att_standalone',
		'surface'            => 'global-styles',
		'scope'              => 'global-styles',
		'subjectName'        => 'wp_global_styles:81',
		'governanceClaim'    => 'governed-change',
		'governanceLane'     => 'external-style-apply-v1',
		'approvalSurface'    => 'settings-ai-activity',
		'executor'           => 'bounded-server-style-apply',
		'operations'         => [],
		'beforeDigest'       => str_repeat( '0', 64 ),
		'afterDigest'        => hash( 'sha256', $subject_bytes ),
		'freshnessSignature' => 'standalone',
		'actorRole'          => 'administrator',
		'proposerVia'        => 'mcp/flavor-agent',
		'decision'           => 'approve',
		'requestedAt'        => '2026-07-18T00:00:00+00:00',
		'decidedAt'          => '2026-07-18T00:01:00+00:00',
		'siteUrl'            => 'https://example.test',
		'keyId'              => $key_id,
		'relatedActivityId'  => 'activity-standalone',
	]
);
$signature     = sodium_crypto_sign_detached( $statement, $secret_key );
$result        = Verifier::verify(
	[
		'statement_b64' => rtrim( strtr( base64_encode( $statement ), '+/', '-_' ), '=' ),
		'signature_b64' => rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' ),
		'key_id'        => $key_id,
	],
	[
		'keys' => [
			[
				'kty' => 'OKP',
				'crv' => 'Ed25519',
				'x'   => rtrim( strtr( base64_encode( $public_key ), '+/', '-_' ), '=' ),
				'kid' => $key_id,
				'use' => 'sig',
				'alg' => 'EdDSA',
			],
		],
	],
	$subject_bytes,
	'att_standalone',
	'https://example.test'
);

echo json_encode( $result, JSON_UNESCAPED_SLASHES ), "\n";
exit( (int) $result['exitCode'] );
