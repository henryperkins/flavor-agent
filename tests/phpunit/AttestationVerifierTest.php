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
		$config = [
			'settings' => [],
			'styles'   => [
				'color' => [
					'background' => 'var:preset|color|parchment-100',
				],
			],
		];
		$bytes  = $this->statement_bytes( 'att_1', Canonicalizer::digest( $config ) );
		$signed = Signer::sign( $bytes );
		$this->assertNotNull( $signed );

		$outcomes = Verifier::evaluate(
			$bytes,
			$signed['signature'],
			KeyManager::jwks(),
			Canonicalizer::canonical_bytes( $config ),
			null
		);

		$this->assertContains( 'signature_valid', $outcomes );
		$this->assertContains( 'live_matches_subject', $outcomes );
	}

	public function test_tampered_statement_yields_record_tampered(): void {
		$bytes  = $this->statement_bytes( 'att_1', str_repeat( 'a', 64 ) );
		$signed = Signer::sign( $bytes );
		$this->assertNotNull( $signed );

		$outcomes = Verifier::evaluate(
			str_replace( 'att_1', 'att_2', $bytes ),
			$signed['signature'],
			KeyManager::jwks(),
			null,
			null
		);

		$this->assertContains( 'record_tampered', $outcomes );
		$this->assertNotContains( 'signature_valid', $outcomes );
	}

	public function test_reverted_change_is_accountable_not_failure(): void {
		$original = [
			'settings' => [],
			'styles'   => [ 'color' => [ 'background' => '#111111' ] ],
		];
		$live     = [
			'settings' => [],
			'styles'   => [ 'color' => [ 'background' => '#ffffff' ] ],
		];
		$bytes    = $this->statement_bytes( 'att_1', Canonicalizer::digest( $original ) );
		$signed   = Signer::sign( $bytes );
		$this->assertNotNull( $signed );

		$outcomes = Verifier::evaluate(
			$bytes,
			$signed['signature'],
			KeyManager::jwks(),
			Canonicalizer::canonical_bytes( $live ),
			'att_revert'
		);

		$this->assertContains( 'signature_valid', $outcomes );
		$this->assertContains( 'reverted_by_attestation', $outcomes );
		$this->assertNotContains( 'live_changed_since_attestation', $outcomes );
	}

	public function test_malformed_statement_with_scalar_nesting_is_handled_safely(): void {
		$signed = Signer::sign( '{}' );
		$this->assertNotNull( $signed );

		// Intermediate levels are scalars instead of arrays; access must not fatal.
		$bytes = '{"predicate":"scalar","subject":"scalar"}';

		$outcomes = Verifier::evaluate(
			$bytes,
			$signed['signature'],
			KeyManager::jwks(),
			Canonicalizer::canonical_bytes(
				[
					'settings' => [],
					'styles'   => [],
				]
			),
			null
		);

		$this->assertContains( 'record_tampered', $outcomes );
		$this->assertContains( 'live_changed_since_attestation', $outcomes );
	}

	public function test_malformed_jwks_entries_are_skipped_safely(): void {
		$config = [
			'settings' => [],
			'styles'   => [ 'color' => [ 'background' => '#111111' ] ],
		];
		$bytes  = $this->statement_bytes( 'att_1', Canonicalizer::digest( $config ) );
		$signed = Signer::sign( $bytes );
		$this->assertNotNull( $signed );

		$jwks = KeyManager::jwks();
		array_unshift( $jwks['keys'], 'not-an-array' );

		$outcomes = Verifier::evaluate(
			$bytes,
			$signed['signature'],
			$jwks,
			Canonicalizer::canonical_bytes( $config ),
			null
		);

		$this->assertContains( 'signature_valid', $outcomes );
		$this->assertContains( 'live_matches_subject', $outcomes );
	}

	private function statement_bytes( string $attestation_id, string $after_digest ): string {
		return StatementBuilder::build(
			[
				'attestationId'      => $attestation_id,
				'surface'            => 'global-styles',
				'scope'              => 'global-styles',
				'subjectName'        => 'wp_global_styles:81',
				'operations'         => [],
				'beforeDigest'       => str_repeat( 'b', 64 ),
				'afterDigest'        => $after_digest,
				'freshnessSignature' => 'f',
				'actorRole'          => 'administrator',
				'proposerVia'        => 'mcp/flavor-agent',
				'decision'           => 'approve',
				'requestedAt'        => '2026-06-22T00:00:00+00:00',
				'decidedAt'          => '2026-06-22T00:01:00+00:00',
				'siteUrl'            => 'https://example.test',
				'keyId'              => (string) KeyManager::key_id(),
			]
		);
	}

	private function configure_key(): void {
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $sk );
	}
}
