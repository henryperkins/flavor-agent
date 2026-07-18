<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\Canonicalizer;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\Signer;
use FlavorAgent\Attestation\StatementBuilder;
use FlavorAgent\Attestation\Verifier;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationVerifierTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->configure_key();
	}

	public function test_intact_change_yields_signature_valid_and_live_match(): void {
		$config   = [
			'settings' => [],
			'styles'   => [
				'color' => [
					'background' => 'var:preset|color|parchment-100',
				],
			],
		];
		$envelope = $this->signed_envelope(
			'att_1',
			str_repeat( 'b', 64 ),
			Canonicalizer::digest( $config )
		);
		$result   = Verifier::verify(
			$envelope,
			KeyManager::jwks(),
			Canonicalizer::canonical_bytes( $config ),
			'att_1',
			'https://example.test'
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'live_matches_subject', $result['outcomes'] );
	}

	public function test_tampered_statement_yields_record_tampered(): void {
		$envelope                  = $this->signed_envelope( 'att_1', str_repeat( 'b', 64 ), str_repeat( 'a', 64 ) );
		$bytes                     = $this->decode_b64url( $envelope['statement_b64'] );
		$envelope['statement_b64'] = KeyManager::b64url( str_replace( 'att_1', 'att_2', $bytes ) );

		$result = Verifier::verify(
			$envelope,
			KeyManager::jwks(),
			null,
			'att_1',
			'https://example.test'
		);

		$this->assertContains( 'record_tampered', $result['outcomes'] );
		$this->assertNotContains( 'signature_valid', $result['outcomes'] );
	}

	public function test_malformed_statement_with_scalar_nesting_is_handled_safely(): void {
		$bytes  = '{"predicate":"scalar","subject":"scalar"}';
		$signed = Signer::sign( $bytes );
		$this->assertNotNull( $signed );
		$envelope = [
			'statement_b64' => KeyManager::b64url( $bytes ),
			'signature_b64' => KeyManager::b64url( $signed['signature'] ),
			'key_id'        => $signed['keyId'],
		];

		$result = Verifier::verify(
			$envelope,
			KeyManager::jwks(),
			Canonicalizer::canonical_bytes(
				[
					'settings' => [],
					'styles'   => [],
				]
			),
			'att_1',
			'https://example.test'
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'statement_invalid', $result['outcomes'] );
	}

	public function test_malformed_jwks_entries_are_skipped_safely(): void {
		$config   = [
			'settings' => [],
			'styles'   => [ 'color' => [ 'background' => '#111111' ] ],
		];
		$envelope = $this->signed_envelope(
			'att_1',
			str_repeat( 'b', 64 ),
			Canonicalizer::digest( $config )
		);

		$jwks = KeyManager::jwks();
		array_unshift( $jwks['keys'], 'not-an-array' );

		$result = Verifier::verify(
			$envelope,
			$jwks,
			Canonicalizer::canonical_bytes( $config ),
			'att_1',
			'https://example.test'
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'live_matches_subject', $result['outcomes'] );
	}

	public function test_strict_verification_rejects_cross_site_and_alias_replay(): void {
		$subject_bytes = 'original subject';
		$envelope      = $this->signed_envelope(
			'att_original',
			str_repeat( '0', 64 ),
			hash( 'sha256', $subject_bytes ),
			[ 'siteUrl' => 'https://source.example' ]
		);

		$result = Verifier::verify(
			$envelope,
			KeyManager::jwks(),
			$subject_bytes,
			'att_alias',
			'https://mirror.example'
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'attestation_identity_mismatch', $result['outcomes'] );
		$this->assertNotContains( 'live_matches_subject', $result['outcomes'] );
		$this->assertSame( 'invalid', $result['verificationStatus'] );
		$this->assertSame( 1, $result['exitCode'] );
	}

	public function test_strict_verification_rejects_signed_statement_with_inconsistent_after_digest(): void {
		$subject_bytes = 'subject';
		$envelope      = $this->signed_envelope(
			'att_schema',
			str_repeat( '0', 64 ),
			hash( 'sha256', $subject_bytes )
		);
		$statement     = json_decode( $this->decode_b64url( $envelope['statement_b64'] ), true );
		$this->assertIsArray( $statement );
		$statement['predicate']['after']['sha256'] = str_repeat( 'f', 64 );
		$bytes                                     = StatementBuilder::canonical_json( $statement );
		$signed                                    = Signer::sign( $bytes );
		$this->assertNotNull( $signed );
		$envelope['statement_b64'] = KeyManager::b64url( $bytes );
		$envelope['signature_b64'] = KeyManager::b64url( $signed['signature'] );

		$result = Verifier::verify(
			$envelope,
			KeyManager::jwks(),
			$subject_bytes,
			'att_schema',
			'https://example.test'
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'statement_invalid', $result['outcomes'] );
		$this->assertNotContains( 'live_matches_subject', $result['outcomes'] );
		$this->assertSame( 'invalid', $result['verificationStatus'] );
	}

	public function test_strict_profile_rejects_revert_decisions_without_an_exclusive_revert_link(): void {
		$subject_bytes = 'subject';
		$envelope      = $this->signed_envelope(
			'att_badrelationship',
			str_repeat( '0', 64 ),
			hash( 'sha256', $subject_bytes ),
			[
				'decision'                => 'revert',
				'supersedesAttestationId' => 'att_parent',
			]
		);

		$result = Verifier::verify(
			$envelope,
			KeyManager::jwks(),
			$subject_bytes,
			'att_badrelationship',
			'https://example.test'
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'statement_invalid', $result['outcomes'] );
		$this->assertSame( 'invalid', $result['verificationStatus'] );
		$this->assertSame( 1, $result['exitCode'] );
	}

	public function test_strict_profile_rejects_signed_non_allowlisted_operation_fields(): void {
		$subject_bytes = 'subject';
		$envelope      = $this->signed_envelope(
			'att_privatefield',
			str_repeat( '0', 64 ),
			hash( 'sha256', $subject_bytes )
		);
		$statement     = json_decode( $this->decode_b64url( $envelope['statement_b64'] ), true );
		$this->assertIsArray( $statement );
		$statement['predicate']['operations'] = [
			[
				'type'          => 'set_styles',
				'blockName'     => '',
				'path'          => [ 'color', 'text' ],
				'value'         => '#111111',
				'valueType'     => 'custom',
				'presetType'    => '',
				'presetSlug'    => '',
				'cssVar'        => '',
				'privateTarget' => 'must-not-verify',
			],
		];
		$envelope                             = $this->resign_envelope( $envelope, $statement );

		$result = Verifier::verify(
			$envelope,
			KeyManager::jwks(),
			$subject_bytes,
			'att_privatefield',
			'https://example.test'
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'statement_invalid', $result['outcomes'] );
		$this->assertSame( 'invalid', $result['verificationStatus'] );
	}

	public function test_strict_profile_rejects_signed_non_profile_statement_fields(): void {
		$subject_bytes = 'subject';
		$envelope      = $this->signed_envelope(
			'att_extra',
			str_repeat( '0', 64 ),
			hash( 'sha256', $subject_bytes )
		);
		$statement     = json_decode( $this->decode_b64url( $envelope['statement_b64'] ), true );
		$this->assertIsArray( $statement );
		$statement['predicate']['privateMetadata'] = [ 'secret' => true ];
		$envelope                                  = $this->resign_envelope( $envelope, $statement );

		$result = Verifier::verify(
			$envelope,
			KeyManager::jwks(),
			$subject_bytes,
			'att_extra',
			'https://example.test'
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'statement_invalid', $result['outcomes'] );
		$this->assertSame( 'invalid', $result['verificationStatus'] );
	}

	public function test_strict_profile_rejects_wrong_type_schema_governance_and_key_claims(): void {
		$mutations = [
			static function ( array &$statement ): void {
				$statement['_type'] = 'https://in-toto.io/Statement/v0.1';
			},
			static function ( array &$statement ): void {
				$statement['predicateType'] = 'https://example.test/other-predicate';
			},
			static function ( array &$statement ): void {
				$statement['predicate']['schemaVersion'] = 2;
			},
			static function ( array &$statement ): void {
				$statement['predicate']['governance']['lane'] = 'another-lane';
			},
			static function ( array &$statement ): void {
				$statement['predicate']['site']['keyId'] = 'another-key';
			},
		];

		foreach ( $mutations as $index => $mutate ) {
			$id            = 'att_profile' . $index;
			$subject_bytes = 'subject ' . $index;
			$envelope      = $this->signed_envelope( $id, str_repeat( '0', 64 ), hash( 'sha256', $subject_bytes ) );
			$statement     = json_decode( $this->decode_b64url( $envelope['statement_b64'] ), true );
			$this->assertIsArray( $statement );
			$mutate( $statement );
			$envelope = $this->resign_envelope( $envelope, $statement );

			$result = Verifier::verify(
				$envelope,
				KeyManager::jwks(),
				$subject_bytes,
				$id,
				'https://example.test'
			);

			$this->assertContains( 'signature_valid', $result['outcomes'] );
			$this->assertContains( 'statement_invalid', $result['outcomes'] );
			$this->assertSame( 'invalid', $result['verificationStatus'] );
		}
	}

	public function test_unsigned_successor_hint_cannot_turn_drift_into_accountable_supersession(): void {
		$envelope                                 = $this->signed_envelope(
			'att_root',
			str_repeat( '0', 64 ),
			hash( 'sha256', 'root state' )
		);
		$envelope['superseded_by_attestation_id'] = 'att_fake';

		$result = Verifier::verify(
			$envelope,
			KeyManager::jwks(),
			'changed state',
			'att_root',
			'https://example.test',
			static fn ( string $id ): ?array => 'att_fake' === $id
				? [
					'statement_b64' => KeyManager::b64url( '{}' ),
					'signature_b64' => KeyManager::b64url( 'not-a-signature' ),
				]
				: null
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'live_changed_since_attestation', $result['outcomes'] );
		$this->assertContains( 'chain_invalid', $result['outcomes'] );
		$this->assertNotContains( 'superseded_by_attestation', $result['outcomes'] );
		$this->assertSame( 'invalid', $result['verificationStatus'] );
		$this->assertSame( 1, $result['exitCode'] );
	}

	public function test_signed_revert_chain_resolves_to_the_live_pre_apply_state(): void {
		$before                             = 'before state';
		$after                              = 'after state';
		$root                               = $this->signed_envelope(
			'att_apply',
			hash( 'sha256', $before ),
			hash( 'sha256', $after )
		);
		$revert                             = $this->signed_envelope(
			'att_revert',
			hash( 'sha256', $after ),
			hash( 'sha256', $before ),
			[
				'decision'             => 'revert',
				'revertsAttestationId' => 'att_apply',
			]
		);
		$root['reverted_by_attestation_id'] = 'att_revert';

		$result = Verifier::verify(
			$root,
			KeyManager::jwks(),
			$before,
			'att_apply',
			'https://example.test',
			static fn ( string $id ): ?array => 'att_revert' === $id ? $revert : null
		);

		$this->assertContains( 'reverted_by_attestation', $result['outcomes'] );
		$this->assertSame( 'verified', $result['verificationStatus'] );
		$this->assertSame( 'att_revert', $result['terminalAttestationId'] );
		$this->assertSame( 1, $result['chainDepth'] );
		$this->assertSame( 0, $result['exitCode'] );
	}

	public function test_signed_chain_with_broken_digest_continuity_is_invalid(): void {
		$root                                 = $this->signed_envelope( 'att_root', str_repeat( '0', 64 ), hash( 'sha256', 'root state' ) );
		$child                                = $this->signed_envelope(
			'att_child',
			hash( 'sha256', 'different state' ),
			hash( 'sha256', 'live state' ),
			[ 'supersedesAttestationId' => 'att_root' ]
		);
		$root['superseded_by_attestation_id'] = 'att_child';

		$result = Verifier::verify(
			$root,
			KeyManager::jwks(),
			'live state',
			'att_root',
			'https://example.test',
			static fn ( string $id ): ?array => 'att_child' === $id ? $child : null
		);

		$this->assertContains( 'chain_invalid', $result['outcomes'] );
		$this->assertNotContains( 'superseded_by_attestation', $result['outcomes'] );
		$this->assertSame( 'invalid', $result['verificationStatus'] );
		$this->assertSame( 1, $result['exitCode'] );
	}

	public function test_signed_chain_child_from_another_site_is_invalid(): void {
		$root                                 = $this->signed_envelope( 'att_root', str_repeat( '0', 64 ), hash( 'sha256', 'root state' ) );
		$child                                = $this->signed_envelope(
			'att_child',
			hash( 'sha256', 'root state' ),
			hash( 'sha256', 'live state' ),
			[
				'siteUrl'                 => 'https://other.example',
				'supersedesAttestationId' => 'att_root',
			]
		);
		$root['superseded_by_attestation_id'] = 'att_child';

		$result = Verifier::verify(
			$root,
			KeyManager::jwks(),
			'live state',
			'att_root',
			'https://example.test',
			static fn ( string $id ): ?array => 'att_child' === $id ? $child : null
		);

		$this->assertContains( 'chain_invalid', $result['outcomes'] );
		$this->assertSame( 'invalid', $result['verificationStatus'] );
		$this->assertSame( 1, $result['exitCode'] );
	}

	public function test_recursive_signed_chain_resolves_to_the_live_terminal_state(): void {
		$state_one                              = 'state one';
		$state_two                              = 'state two';
		$state_three                            = 'state three';
		$root                                   = $this->signed_envelope(
			'att_root',
			str_repeat( '0', 64 ),
			hash( 'sha256', $state_one )
		);
		$middle                                 = $this->signed_envelope(
			'att_middle',
			hash( 'sha256', $state_one ),
			hash( 'sha256', $state_two ),
			[ 'supersedesAttestationId' => 'att_root' ]
		);
		$terminal                               = $this->signed_envelope(
			'att_terminal',
			hash( 'sha256', $state_two ),
			hash( 'sha256', $state_three ),
			[ 'supersedesAttestationId' => 'att_middle' ]
		);
		$root['superseded_by_attestation_id']   = 'att_middle';
		$middle['superseded_by_attestation_id'] = 'att_terminal';
		$envelopes                              = [
			'att_middle'   => $middle,
			'att_terminal' => $terminal,
		];

		$result = Verifier::verify(
			$root,
			KeyManager::jwks(),
			$state_three,
			'att_root',
			'https://example.test',
			static fn ( string $id ): ?array => $envelopes[ $id ] ?? null
		);

		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'superseded_by_attestation', $result['outcomes'] );
		$this->assertNotContains( 'live_changed_since_attestation', $result['outcomes'] );
		$this->assertSame( 'verified', $result['verificationStatus'] );
		$this->assertSame( 'att_terminal', $result['terminalAttestationId'] );
		$this->assertSame( 2, $result['chainDepth'] );
		$this->assertSame( 0, $result['exitCode'] );
	}

	public function test_recursive_chain_cycles_fail_closed(): void {
		$root                                   = $this->signed_envelope(
			'att_root',
			hash( 'sha256', 'middle state' ),
			hash( 'sha256', 'root state' ),
			[ 'supersedesAttestationId' => 'att_middle' ]
		);
		$middle                                 = $this->signed_envelope(
			'att_middle',
			hash( 'sha256', 'root state' ),
			hash( 'sha256', 'middle state' ),
			[ 'supersedesAttestationId' => 'att_root' ]
		);
		$root['superseded_by_attestation_id']   = 'att_middle';
		$middle['superseded_by_attestation_id'] = 'att_root';
		$envelopes                              = [
			'att_root'   => $root,
			'att_middle' => $middle,
		];

		$result = Verifier::verify(
			$root,
			KeyManager::jwks(),
			'unreachable live state',
			'att_root',
			'https://example.test',
			static fn ( string $id ): ?array => $envelopes[ $id ] ?? null
		);

		$this->assertContains( 'live_changed_since_attestation', $result['outcomes'] );
		$this->assertContains( 'chain_invalid', $result['outcomes'] );
		$this->assertNotContains( 'superseded_by_attestation', $result['outcomes'] );
		$this->assertSame( 1, $result['exitCode'] );
	}

	public function test_recursive_chain_stops_after_fifty_total_records(): void {
		$root      = $this->signed_envelope( 'att_0', str_repeat( '0', 64 ), hash( 'sha256', 'state 0' ) );
		$envelopes = [];
		$previous  = 'att_0';

		for ( $index = 1; $index <= 50; ++$index ) {
			$id               = 'att_' . $index;
			$envelopes[ $id ] = $this->signed_envelope(
				$id,
				hash( 'sha256', 'state ' . ( $index - 1 ) ),
				hash( 'sha256', 'state ' . $index ),
				[ 'supersedesAttestationId' => $previous ]
			);

			if ( 1 === $index ) {
				$root['superseded_by_attestation_id'] = $id;
			} else {
				$envelopes[ $previous ]['superseded_by_attestation_id'] = $id;
			}

			$previous = $id;
		}

		$result = Verifier::verify(
			$root,
			KeyManager::jwks(),
			'state 50',
			'att_0',
			'https://example.test',
			static fn ( string $id ): ?array => $envelopes[ $id ] ?? null
		);

		$this->assertContains( 'chain_resolution_incomplete', $result['outcomes'] );
		$this->assertSame( 'incomplete', $result['verificationStatus'] );
		$this->assertNull( $result['terminalAttestationId'] );
		$this->assertSame( 3, $result['exitCode'] );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function signed_envelope( string $id, string $before_digest, string $after_digest, array $overrides = [] ): array {
		$params    = array_merge(
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
				'beforeDigest'       => $before_digest,
				'afterDigest'        => $after_digest,
				'freshnessSignature' => 'f',
				'actorRole'          => 'administrator',
				'proposerVia'        => 'mcp/flavor-agent',
				'decision'           => 'approve',
				'requestedAt'        => '2026-06-22T00:00:00+00:00',
				'decidedAt'          => '2026-06-22T00:01:00+00:00',
				'siteUrl'            => 'https://example.test',
				'keyId'              => (string) KeyManager::key_id(),
			],
			$overrides
		);
		$statement = StatementBuilder::build( $params );
		$signed    = Signer::sign( $statement );
		$this->assertNotNull( $signed );

		return [
			'statement_b64'                => KeyManager::b64url( $statement ),
			'signature_b64'                => KeyManager::b64url( $signed['signature'] ),
			'key_id'                       => $signed['keyId'],
			'reverted_by_attestation_id'   => null,
			'superseded_by_attestation_id' => null,
		];
	}

	private function decode_b64url( string $value ): string {
		$decoded = base64_decode(
			strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
			true
		);

		return false === $decoded ? '' : $decoded;
	}

	/**
	 * @param array<string, mixed> $envelope
	 * @param array<string, mixed> $statement
	 * @return array<string, mixed>
	 */
	private function resign_envelope( array $envelope, array $statement ): array {
		$bytes  = StatementBuilder::canonical_json( $statement );
		$signed = Signer::sign( $bytes );
		$this->assertNotNull( $signed );

		$envelope['statement_b64'] = KeyManager::b64url( $bytes );
		$envelope['signature_b64'] = KeyManager::b64url( $signed['signature'] );

		return $envelope;
	}

	private function configure_key(): void {
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn(): string => $sk );
	}
}
