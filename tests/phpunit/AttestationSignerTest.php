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

	private function configure_key(): void {
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $sk );
	}
}
