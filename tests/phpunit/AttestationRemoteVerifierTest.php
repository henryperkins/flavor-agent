<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\Canonicalizer;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\RemoteVerifier;
use FlavorAgent\Attestation\Signer;
use FlavorAgent\Attestation\StatementBuilder;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationRemoteVerifierTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $sk );
		KeyManager::ensure_registered();
	}

	public function test_verify_fetches_public_urls_and_returns_outcomes(): void {
		$config    = [
			'settings' => [],
			'styles'   => [ 'color' => [ 'background' => '#111111' ] ],
		];
		$statement = StatementBuilder::build(
			[
				'attestationId'      => 'att_abc',
				'surface'            => 'global-styles',
				'scope'              => 'global-styles',
				'subjectName'        => 'wp_global_styles:81',
				'operations'         => [],
				'beforeDigest'       => str_repeat( '0', 64 ),
				'afterDigest'        => Canonicalizer::digest( $config ),
				'freshnessSignature' => 'f',
				'actorRole'          => 'administrator',
				'requestedAt'        => '2026-06-22T00:00:00+00:00',
				'decidedAt'          => '2026-06-22T00:01:00+00:00',
				'siteUrl'            => 'https://example.test',
				'keyId'              => KeyManager::key_id(),
			]
		);
		$signed    = Signer::sign( $statement );
		$this->assertNotNull( $signed );

		$seen   = [];
		$result = RemoteVerifier::verify(
			'https://example.test/',
			'att_abc',
			static function ( string $url ) use ( &$seen, $statement, $signed, $config ): array {
				$seen[] = $url;

				return match ( $url ) {
					'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc' => [
						'statement_b64' => KeyManager::b64url( $statement ),
						'signature_b64' => KeyManager::b64url( $signed['signature'] ),
					],
					'https://example.test/wp-json/flavor-agent/v1/attestations/keys' => KeyManager::jwks(),
					'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc/subject-state' => [
						'subject_canonical_b64' => KeyManager::b64url( Canonicalizer::canonical_bytes( $config ) ),
					],
					default => [],
				};
			}
		);

		$this->assertSame(
			[
				'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc',
				'https://example.test/wp-json/flavor-agent/v1/attestations/keys',
				'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc/subject-state',
			],
			$seen
		);
		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'live_matches_subject', $result['outcomes'] );
	}

	public function test_verify_reports_invalid_subject_state(): void {
		$result = RemoteVerifier::verify(
			'https://example.test',
			'att_abc',
			static fn ( string $url ): array => str_ends_with( $url, '/subject-state' ) ? [] : [ 'keys' => [] ]
		);

		$this->assertSame( 'invalid_subject_state', $result['error'] );
		$this->assertSame( 3, $result['exitCode'] );
	}
}
