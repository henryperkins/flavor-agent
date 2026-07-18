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
				'governanceClaim'    => 'governed-change',
				'governanceLane'     => 'external-style-apply-v1',
				'approvalSurface'    => 'settings-ai-activity',
				'executor'           => 'bounded-server-style-apply',
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
						'key_id'        => $signed['keyId'],
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
		$this->assertSame( 'verified', $result['verificationStatus'] );
		$this->assertSame( 0, $result['exitCode'] );
	}

	public function test_verify_preserves_signature_result_when_subject_is_unavailable(): void {
		$statement = $this->statement( 'att_abc', str_repeat( '0', 64 ), hash( 'sha256', 'subject' ) );
		$signed    = Signer::sign( $statement );
		$this->assertNotNull( $signed );
		$envelope = [
			'statement_b64' => KeyManager::b64url( $statement ),
			'signature_b64' => KeyManager::b64url( $signed['signature'] ),
			'key_id'        => $signed['keyId'],
		];

		$result = RemoteVerifier::verify(
			'https://example.test',
			'att_abc',
			static function ( string $url ) use ( $envelope ): array {
				if ( str_ends_with( $url, '/keys' ) ) {
					return [
						'status' => 200,
						'data'   => KeyManager::jwks(),
					];
				}

				if ( str_ends_with( $url, '/subject-state' ) ) {
					return [
						'status' => 409,
						'data'   => [ 'error' => 'subject_unavailable' ],
					];
				}

				return [
					'status' => 200,
					'data'   => $envelope,
				];
			}
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'live_subject_unavailable', $result['outcomes'] );
		$this->assertNotContains( 'record_tampered', $result['outcomes'] );
		$this->assertSame( 'subject_unavailable', $result['error'] );
		$this->assertSame( 'subject_unavailable', $result['subjectError'] );
		$this->assertSame( 'incomplete', $result['verificationStatus'] );
		$this->assertSame( 3, $result['exitCode'] );
	}

	public function test_verify_treats_invalid_subject_encoding_as_incomplete(): void {
		$statement = $this->statement( 'att_abc', str_repeat( '0', 64 ), hash( 'sha256', 'subject' ) );
		$envelope  = $this->envelope( $statement );

		$result = RemoteVerifier::verify(
			'https://example.test',
			'att_abc',
			static function ( string $url ) use ( $envelope ): array {
				return match ( true ) {
					str_ends_with( $url, '/keys' ) => [
						'status' => 200,
						'data'   => KeyManager::jwks(),
					],
					str_ends_with( $url, '/subject-state' ) => [
						'status' => 200,
						'data'   => [ 'subject_canonical_b64' => '%%%not-base64%%%' ],
					],
					default => [
						'status' => 200,
						'data'   => $envelope,
					],
				};
			}
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'live_subject_unavailable', $result['outcomes'] );
		$this->assertSame( 'invalid_subject_state', $result['subjectError'] );
		$this->assertSame( 'incomplete', $result['verificationStatus'] );
		$this->assertSame( 3, $result['exitCode'] );
	}

	public function test_verify_fetches_and_validates_a_signed_successor_chain(): void {
		$root_state                           = 'root';
		$live_state                           = 'live';
		$root                                 = $this->envelope(
			$this->statement( 'att_root', str_repeat( '0', 64 ), hash( 'sha256', $root_state ) )
		);
		$successor                            = $this->envelope(
			$this->statement(
				'att_successor',
				hash( 'sha256', $root_state ),
				hash( 'sha256', $live_state ),
				[ 'supersedesAttestationId' => 'att_root' ]
			)
		);
		$root['superseded_by_attestation_id'] = 'att_successor';
		$seen                                 = [];

		$result = RemoteVerifier::verify(
			'https://example.test',
			'att_root',
			static function ( string $url ) use ( &$seen, $root, $successor, $live_state ): array {
				$seen[] = $url;

				return match ( true ) {
					str_ends_with( $url, '/keys' ) => [
						'status' => 200,
						'data'   => KeyManager::jwks(),
					],
					str_ends_with( $url, '/subject-state' ) => [
						'status' => 200,
						'data'   => [ 'subject_canonical_b64' => KeyManager::b64url( $live_state ) ],
					],
					str_ends_with( $url, '/att_successor' ) => [
						'status' => 200,
						'data'   => $successor,
					],
					default => [
						'status' => 200,
						'data'   => $root,
					],
				};
			}
		);

		$this->assertContains( 'superseded_by_attestation', $result['outcomes'] );
		$this->assertSame( 'att_successor', $result['terminalAttestationId'] );
		$this->assertSame( 1, $result['chainDepth'] );
		$this->assertContains(
			'https://example.test/wp-json/flavor-agent/v1/attestations/att_successor',
			$seen
		);
	}

	public function test_verify_returns_structured_incomplete_result_when_envelope_is_unavailable(): void {
		$result = RemoteVerifier::verify(
			'https://example.test',
			'att_missing',
			static fn ( string $url ): array => [
				'status' => 404,
				'data'   => [ 'error' => 'not_found' ],
			]
		);

		$this->assertSame( [], $result['outcomes'] );
		$this->assertSame( 'envelope_unavailable', $result['error'] );
		$this->assertSame( 'incomplete', $result['verificationStatus'] );
		$this->assertSame( 3, $result['exitCode'] );
	}

	public function test_strict_core_runs_without_wordpress_bootstrap(): void {
		$script  = __DIR__ . '/fixtures/attestation-standalone-core.php';
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1';
		$output  = [];
		$status  = 1;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- A child PHP process is required to prove the standalone verifier has no WordPress bootstrap dependency.
		exec( $command, $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		$this->assertSame( 'verified', $result['verificationStatus'] ?? null );
		$this->assertContains( 'signature_valid', $result['outcomes'] ?? [] );
		$this->assertContains( 'live_matches_subject', $result['outcomes'] ?? [] );
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function statement( string $id, string $before, string $after, array $overrides = [] ): string {
		return StatementBuilder::build(
			array_merge(
				[
					'attestationId'      => $id,
					'surface'            => 'global-styles',
					'scope'              => 'global-styles',
					'subjectName'        => 'wp_global_styles:81',
					'governanceClaim'    => 'governed-change',
					'governanceLane'     => 'external-style-apply-v1',
					'approvalSurface'    => 'settings-ai-activity',
					'executor'           => 'bounded-server-style-apply',
					'operations'         => [],
					'beforeDigest'       => $before,
					'afterDigest'        => $after,
					'freshnessSignature' => 'f',
					'actorRole'          => 'administrator',
					'proposerVia'        => 'mcp/flavor-agent',
					'decision'           => 'approve',
					'requestedAt'        => '2026-06-22T00:00:00+00:00',
					'decidedAt'          => '2026-06-22T00:01:00+00:00',
					'siteUrl'            => 'https://example.test',
					'keyId'              => KeyManager::key_id(),
				],
				$overrides
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function envelope( string $statement ): array {
		$signed = Signer::sign( $statement );
		$this->assertNotNull( $signed );

		return [
			'statement_b64'                => KeyManager::b64url( $statement ),
			'signature_b64'                => KeyManager::b64url( $signed['signature'] ),
			'key_id'                       => $signed['keyId'],
			'reverted_by_attestation_id'   => null,
			'superseded_by_attestation_id' => null,
		];
	}
}
