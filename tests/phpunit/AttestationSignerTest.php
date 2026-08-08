<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\Signer;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationSignerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_sign_then_verify_round_trips(): void {
		$this->configure_key();

		$bytes  = '{"hello":"world"}';
		$signed = Signer::sign( $bytes );

		$this->assertNotNull( $signed );
		$this->assertTrue( Signer::verify( $bytes, $signed['signature'], (string) KeyManager::public_key() ) );
	}

	public function test_tampered_bytes_fail_verification(): void {
		$this->configure_key();

		$signed = Signer::sign( '{"hello":"world"}' );

		$this->assertNotNull( $signed );
		$this->assertFalse( Signer::verify( '{"hello":"WORLD"}', $signed['signature'], (string) KeyManager::public_key() ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sign_clears_its_private_key_copy_when_key_derivation_throws(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- A process-local namespace shim is the only observable boundary for the native in-memory zeroing side effect.
		eval(
			'namespace FlavorAgent\\Attestation; function sodium_memzero( string &$value ): void {'
			. ' ++$GLOBALS["flavor_agent_signer_memzero_calls"]; \\sodium_memzero( $value ); }'
		);
		$GLOBALS['flavor_agent_signer_memzero_calls'] = 0;
		$invalid_private_key                          = 'invalid';

		try {
			Signer::sign( '{"hello":"world"}', null, $invalid_private_key );
			$this->fail( 'Invalid Ed25519 private key bytes must fail key derivation.' );
		} catch ( \SodiumException ) {
			// Expected: the cleanup assertion below is the behavior under test.
		}

		$this->assertSame( 1, $GLOBALS['flavor_agent_signer_memzero_calls'] );
	}

	private function configure_key(): void {
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $sk );
	}
}
