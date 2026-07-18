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
		$this->assertSame( 32, strlen( (string) KeyManager::key_id() ) );

		KeyManager::ensure_registered();
		$jwks = KeyManager::jwks();

		$this->assertSame( 'OKP', $jwks['keys'][0]['kty'] );
		$this->assertSame( 'Ed25519', $jwks['keys'][0]['crv'] );
		$this->assertSame( KeyManager::key_id(), $jwks['keys'][0]['kid'] );
		$this->assertSame( 'active', $jwks['keys'][0]['status'] );
		$this->assertNotSame( '', (string) $jwks['keys'][0]['createdAt'] );
	}

	public function test_rotating_from_a_to_b_and_back_to_a_reactivates_only_a(): void {
		$key_a   = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		$key_b   = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		$current = $key_a;
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( &$current ): string {
				return $current;
			}
		);

		KeyManager::ensure_registered();
		$id_a         = (string) KeyManager::key_id();
		$first_export = $this->keys_by_id( KeyManager::jwks()['keys'] );

		$current = $key_b;
		KeyManager::ensure_registered();
		$id_b = (string) KeyManager::key_id();

		$current = $key_a;
		KeyManager::ensure_registered();
		$keys = $this->keys_by_id( KeyManager::jwks()['keys'] );

		$this->assertSame( 'active', $keys[ $id_a ]['status'] );
		$this->assertSame( 'retired', $keys[ $id_b ]['status'] );
		$this->assertSame( $first_export[ $id_a ]['createdAt'], $keys[ $id_a ]['createdAt'] );
		$this->assertCount(
			1,
			array_filter( $keys, static fn ( array $key ): bool => 'active' === $key['status'] )
		);
	}

	/**
	 * @param list<array<string, mixed>> $keys
	 * @return array<string, array<string, mixed>>
	 */
	private function keys_by_id( array $keys ): array {
		$indexed = [];

		foreach ( $keys as $key ) {
			$indexed[ (string) $key['kid'] ] = $key;
		}

		return $indexed;
	}
}
