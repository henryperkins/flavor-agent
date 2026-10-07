<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Activity\ActivityStorageContext;
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

	public function test_removed_signer_exports_verification_only_without_changing_the_registry(): void {
		$current = $this->seeded_key( 'a' );
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( &$current ): string {
				return $current;
			}
		);
		KeyManager::ensure_registered();
		$before  = WordPressTestState::$options;
		$updates = WordPressTestState::$updated_options;
		$current = '';

		$jwks = KeyManager::jwks();

		$this->assertFalse( KeyManager::configured() );
		$this->assertSame( 'verification-only', $jwks['keys'][0]['status'] );
		$this->assertSame( $before, WordPressTestState::$options );
		$this->assertSame( $updates, WordPressTestState::$updated_options );
	}

	/** @dataProvider malformed_key_sources */
	public function test_malformed_signer_is_unconfigured_and_cannot_advertise_active( mixed $source ): void {
		add_filter( 'flavor_agent_attest_private_key', static fn (): mixed => $source );
		$this->store_active_seeded_key();

		$this->assertFalse( KeyManager::configured() );
		$this->assertSame( 'verification-only', KeyManager::jwks()['keys'][0]['status'] );
	}

	/** @return array<string, array{mixed}> */
	public static function malformed_key_sources(): array {
		return [
			'invalid base64' => [ 'not base64!' ],
			'wrong length'   => [ base64_encode( 'short' ) ],
			'array'          => [ [ 'private' => 'must-not-coerce' ] ],
			'object'         => [ new \stdClass() ],
			'null'           => [ null ],
		];
	}

	/** @dataProvider inconsistent_sized_secret_keys */
	public function test_inconsistent_sized_signer_is_unconfigured_without_registry_writes( string $secret ): void {
		$source = base64_encode( $secret );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $source );

		$this->assertFalse( KeyManager::configured() );
		$this->assertSame( [], WordPressTestState::$options );
		$this->assertSame( [], WordPressTestState::$updated_options );
	}

	/** @dataProvider inconsistent_sized_secret_keys */
	public function test_inconsistent_sized_signer_downgrades_matching_public_key_without_registry_writes( string $secret ): void {
		$public = sodium_crypto_sign_publickey_from_secretkey( $secret );
		$id     = substr( hash( 'sha256', $public ), 0, 32 );
		update_option(
			'flavor_agent_attestation_public_keys',
			[
				$id => [
					'kid'       => $id,
					'x'         => rtrim( strtr( base64_encode( $public ), '+/', '-_' ), '=' ),
					'status'    => 'active',
					'createdAt' => '2026-10-07T00:00:00+00:00',
				],
			]
		);
		$source  = base64_encode( $secret );
		$before  = WordPressTestState::$options;
		$updates = WordPressTestState::$updated_options;
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $source );

		$this->assertSame( 'verification-only', KeyManager::jwks()['keys'][0]['status'] );
		$this->assertSame( $before, WordPressTestState::$options );
		$this->assertSame( $updates, WordPressTestState::$updated_options );
	}

	/** @return array<string, array{string}> */
	public static function inconsistent_sized_secret_keys(): array {
		$public_b = sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( str_repeat( 'b', 32 ) ) );

		return [
			'repeated bytes'                => [ str_repeat( 'a', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) ],
			'valid seed with different key' => [ str_repeat( 'a', 32 ) . $public_b ],
		];
	}

	public function test_throwing_signer_filter_is_unconfigured_and_exports_only_public_material(): void {
		$this->store_active_seeded_key();
		add_filter(
			'flavor_agent_attest_private_key',
			static function (): never {
				throw new \RuntimeException( 'private-filter-secret' );
			}
		);

		$this->assertFalse( KeyManager::configured() );
		$jwks = KeyManager::jwks();
		$this->assertSame( 'verification-only', $jwks['keys'][0]['status'] );
		$this->assertStringNotContainsString( 'private-filter-secret', (string) json_encode( $jwks ) );
		$this->assertStringNotContainsString( $this->seeded_key( 'a' ), (string) json_encode( $jwks ) );
	}

	public function test_unregistered_rotation_downgrades_old_active_without_registering_new_signer(): void {
		$current = $this->seeded_key( 'a' );
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( &$current ): string {
				return $current;
			}
		);
		KeyManager::ensure_registered();
		$before  = WordPressTestState::$options;
		$updates = WordPressTestState::$updated_options;
		$current = $this->seeded_key( 'b' );

		$this->assertTrue( KeyManager::configured() );
		$jwks = KeyManager::jwks();
		$this->assertCount( 1, $jwks['keys'] );
		$this->assertSame( 'verification-only', $jwks['keys'][0]['status'] );
		$this->assertSame( $before, WordPressTestState::$options );
		$this->assertSame( $updates, WordPressTestState::$updated_options );
	}

	public function test_registered_rotation_keeps_retired_keys_after_signer_removal(): void {
		$current = $this->seeded_key( 'a' );
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( &$current ): string {
				return $current;
			}
		);
		KeyManager::ensure_registered();
		$current = $this->seeded_key( 'b' );
		KeyManager::ensure_registered();
		$this->assertSame( [ 'retired', 'active' ], array_column( KeyManager::jwks()['keys'], 'status' ) );
		$current = '';

		$this->assertSame( [ 'retired', 'verification-only' ], array_column( KeyManager::jwks()['keys'], 'status' ) );
		$current = $this->seeded_key( 'a' );
		$this->assertSame( [ 'retired', 'verification-only' ], array_column( KeyManager::jwks()['keys'], 'status' ) );
	}

	public function test_matching_public_key_with_different_kid_cannot_remain_active(): void {
		$this->store_active_seeded_key();
		$registry               = WordPressTestState::$options['flavor_agent_attestation_public_keys'];
		$id                     = array_key_first( $registry );
		$registry[ $id ]['kid'] = 'different-key-id';
		update_option( 'flavor_agent_attestation_public_keys', $registry );
		$source = $this->seeded_key( 'a' );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $source );

		$this->assertSame( 'verification-only', KeyManager::jwks()['keys'][0]['status'] );
	}

	public function test_matching_kid_with_different_public_key_cannot_remain_active(): void {
		$this->store_active_seeded_key();
		$registry             = WordPressTestState::$options['flavor_agent_attestation_public_keys'];
		$id                   = array_key_first( $registry );
		$registry[ $id ]['x'] = rtrim( strtr( base64_encode( str_repeat( 'x', 32 ) ), '+/', '-_' ), '=' );
		update_option( 'flavor_agent_attestation_public_keys', $registry );
		$source = $this->seeded_key( 'a' );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $source );

		$this->assertSame( 'verification-only', KeyManager::jwks()['keys'][0]['status'] );
	}

	/** @dataProvider owner_drift_modes */
	public function test_foreign_owner_does_not_invoke_the_ambient_signer_filter( string $mode ): void {
		$this->store_active_seeded_key();
		$owner = ActivityRepository::capture_storage_context();
		$this->assertInstanceOf( ActivityStorageContext::class, $owner );
		$database = $GLOBALS['wpdb'];
		$blog_id  = WordPressTestState::$current_blog_id;
		if ( 'blog' === $mode ) {
			WordPressTestState::$current_blog_id = 2;
		} else {
			$GLOBALS['wpdb'] = new \wpdb();
		}
		$calls  = 0;
		$source = $this->seeded_key( 'a' );
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( &$calls, $source ): string {
				++$calls;
				return $source;
			}
		);
		try {
			$jwks = KeyManager::jwks( $owner );
		} finally {
			$GLOBALS['wpdb']                     = $database;
			WordPressTestState::$current_blog_id = $blog_id;
		}

		$this->assertSame( 0, $calls );
		$this->assertSame( 'verification-only', $jwks['keys'][0]['status'] );
	}

	public function test_current_owner_jwks_read_never_attempts_a_database_write(): void {
		$this->store_active_seeded_key();
		$source = $this->seeded_key( 'a' );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $source );
		$database        = $GLOBALS['wpdb'];
		$read_database   = new class() extends \wpdb {
			public int $write_attempts = 0;

			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ) {
				++$this->write_attempts;
				return parent::update( $table, $data, $where, $format, $where_format );
			}

			public function insert( string $table, array $data, array $format = [] ) {
				++$this->write_attempts;
				return parent::insert( $table, $data, $format );
			}
		};
		$GLOBALS['wpdb'] = $read_database;
		try {
			$jwks = KeyManager::jwks();
		} finally {
			$GLOBALS['wpdb'] = $database;
		}

		$this->assertSame( 'active', $jwks['keys'][0]['status'] );
		$this->assertSame( 0, $read_database->write_attempts );
	}

	/** @dataProvider owner_drift_modes */
	public function test_mid_filter_owner_drift_cannot_advertise_current_signer( string $mode ): void {
		$this->store_active_seeded_key();
		$owner = ActivityRepository::capture_storage_context();
		$this->assertInstanceOf( ActivityStorageContext::class, $owner );
		$database = $GLOBALS['wpdb'];
		$blog_id  = WordPressTestState::$current_blog_id;
		$source   = $this->seeded_key( 'a' );
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( $source, $mode ): string {
				if ( 'blog' === $mode ) {
					WordPressTestState::$current_blog_id = 2;
				} else {
					$GLOBALS['wpdb'] = new \wpdb();
				}
				return $source;
			}
		);
		$before = WordPressTestState::$options;
		try {
			$jwks = KeyManager::jwks( $owner );
		} finally {
			$GLOBALS['wpdb']                     = $database;
			WordPressTestState::$current_blog_id = $blog_id;
		}

		$this->assertSame( 'verification-only', $jwks['keys'][0]['status'] );
		$this->assertSame( $before, WordPressTestState::$options );
	}

	/** @return array<string, array{string}> */
	public static function owner_drift_modes(): array {
		return [
			'blog'     => [ 'blog' ],
			'database' => [ 'database' ],
		];
	}

	/** @dataProvider owner_drift_modes */
	public function test_configured_fails_closed_when_signer_filter_changes_owner( string $mode ): void {
		$database = $GLOBALS['wpdb'];
		$blog_id  = WordPressTestState::$current_blog_id;
		$source   = $this->seeded_key( 'a' );
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( $source, $mode ): string {
				if ( 'blog' === $mode ) {
					WordPressTestState::$current_blog_id = 2;
				} else {
					$GLOBALS['wpdb'] = new \wpdb();
				}
				return $source;
			}
		);
		$before = WordPressTestState::$options;
		try {
			$this->assertFalse( KeyManager::configured() );
			$this->assertSame( $before, WordPressTestState::$options );
		} finally {
			$GLOBALS['wpdb']                     = $database;
			WordPressTestState::$current_blog_id = $blog_id;
		}
	}

	public function test_configured_fails_closed_without_a_capturable_owner(): void {
		$database = $GLOBALS['wpdb'];
		$calls    = 0;
		$source   = $this->seeded_key( 'a' );
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( &$calls, $source ): string {
				++$calls;
				return $source;
			}
		);
		$GLOBALS['wpdb'] = null;
		try {
			$this->assertFalse( KeyManager::configured() );
			$this->assertSame( 0, $calls );
		} finally {
			$GLOBALS['wpdb'] = $database;
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_status_reads_wipe_their_private_key_copy_even_when_derivation_throws(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Process-local shims observe native cleanup and a native derivation failure.
		eval(
			'namespace FlavorAgent\\Attestation; function sodium_memzero( string &$value ): void {'
			. ' ++$GLOBALS["flavor_agent_status_memzero_calls"]; \\sodium_memzero( $value ); }'
			. ' function sodium_crypto_sign_publickey_from_secretkey( string $value ): string {'
			. ' if ( $GLOBALS["flavor_agent_status_derivation_throws"] ) { throw new \\SodiumException( "private-derivation-secret" ); }'
			. ' return \\sodium_crypto_sign_publickey_from_secretkey( $value ); }'
		);
		$GLOBALS['flavor_agent_status_memzero_calls']     = 0;
		$GLOBALS['flavor_agent_status_derivation_throws'] = false;
		$this->store_active_seeded_key();
		$source = $this->seeded_key( 'a' );
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( &$source ): string {
				return $source;
			}
		);

		$this->assertTrue( KeyManager::configured() );
		$this->assertSame( 1, $GLOBALS['flavor_agent_status_memzero_calls'] );
		$this->assertSame( 'active', KeyManager::jwks()['keys'][0]['status'] );
		$this->assertSame( 2, $GLOBALS['flavor_agent_status_memzero_calls'] );
		$source = base64_encode( str_repeat( 'a', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) );
		$this->assertFalse( KeyManager::configured() );
		$this->assertSame( 3, $GLOBALS['flavor_agent_status_memzero_calls'] );
		$this->assertSame( 'verification-only', KeyManager::jwks()['keys'][0]['status'] );
		$this->assertSame( 4, $GLOBALS['flavor_agent_status_memzero_calls'] );
		$source = $this->seeded_key( 'a' );
		$GLOBALS['flavor_agent_status_derivation_throws'] = true;
		$this->assertFalse( KeyManager::configured() );
		$this->assertSame( 5, $GLOBALS['flavor_agent_status_memzero_calls'] );
		$this->assertSame( 'verification-only', KeyManager::jwks()['keys'][0]['status'] );
		$this->assertSame( 6, $GLOBALS['flavor_agent_status_memzero_calls'] );
	}

	public function test_empty_registry_does_not_obtain_a_private_key_or_register_one_on_read(): void {
		$calls  = 0;
		$source = $this->seeded_key( 'a' );
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( &$calls, $source ): string {
				++$calls;
				return $source;
			}
		);

		$this->assertSame( [ 'keys' => [] ], KeyManager::jwks() );
		$this->assertSame( 0, $calls );
		$this->assertSame( [], WordPressTestState::$options );
		$this->assertSame( [], WordPressTestState::$updated_options );
	}

	private function seeded_key( string $seed_byte ): string {
		return base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( str_repeat( $seed_byte, 32 ) ) ) );
	}

	private function store_active_seeded_key(): void {
		$pk = sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( str_repeat( 'a', 32 ) ) );
		$id = substr( hash( 'sha256', $pk ), 0, 32 );
		update_option(
			'flavor_agent_attestation_public_keys',
			[
				$id => [
					'kid'       => $id,
					'x'         => rtrim( strtr( base64_encode( $pk ), '+/', '-_' ), '=' ),
					'status'    => 'active',
					'createdAt' => '2026-10-07T00:00:00+00:00',
				],
			]
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
