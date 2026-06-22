<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationKeyManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_unconfigured_when_no_key_source(): void {
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => '' );

		$this->assertFalse( KeyManager::configured() );
	}

	public function test_registers_active_public_key_and_exports_jwks(): void {
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $sk );

		$this->assertTrue( KeyManager::configured() );

		KeyManager::ensure_registered();
		$jwks = KeyManager::jwks();

		$this->assertSame( 'OKP', $jwks['keys'][0]['kty'] );
		$this->assertSame( 'Ed25519', $jwks['keys'][0]['crv'] );
		$this->assertSame( KeyManager::key_id(), $jwks['keys'][0]['kid'] );
	}
}
