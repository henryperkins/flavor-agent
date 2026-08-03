<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Apply\MaterializationLock;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class MaterializationLockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
	}

	public function test_contender_cannot_overwrite_owner_and_old_owner_cannot_release_successor(): void {
		$first = MaterializationLock::acquire( 'template', 'Theme.V2//home' );

		$this->assertInstanceOf( MaterializationLock::class, $first );
		$owned = self::lock_options();
		$this->assertCount( 1, $owned );

		$contender = MaterializationLock::acquire( 'template', 'Theme.V2//home' );

		$this->assertInstanceOf( \WP_Error::class, $contender );
		$this->assertSame( 'flavor_agent_apply_materialization_locked', $contender->get_error_code() );
		$this->assertSame( $owned, self::lock_options() );

		$lock_key   = (string) array_key_first( $owned );
		$lock_owner = (string) $owned[ $lock_key ];
		$GLOBALS['wpdb']->delete(
			$GLOBALS['wpdb']->options,
			[
				'option_name'  => $lock_key,
				'option_value' => $lock_owner,
			],
			[ '%s', '%s' ]
		);
		$this->assertSame( [], self::lock_options() );

		$successor = MaterializationLock::acquire( 'template', 'Theme.V2//home' );
		$this->assertInstanceOf( MaterializationLock::class, $successor );
		$successor_owned = self::lock_options();
		$this->assertCount( 1, $successor_owned );
		$this->assertNotSame( $owned, $successor_owned );

		$first->release();
		$this->assertSame( $successor_owned, self::lock_options() );

		$successor->release();
		$this->assertSame( [], self::lock_options() );
	}

	public function test_existing_or_corrupt_lock_fails_closed_without_takeover(): void {
		$owner = MaterializationLock::acquire( 'template-part', 'twentytwentyfive//header' );
		$this->assertInstanceOf( MaterializationLock::class, $owner );
		$keys = array_keys( self::lock_options() );
		$this->assertCount( 1, $keys );
		$owner->release();

		WordPressTestState::$options[ $keys[0] ] = 'corrupt-or-abandoned-owner';

		$contender = MaterializationLock::acquire( 'template-part', 'twentytwentyfive//header' );

		$this->assertInstanceOf( \WP_Error::class, $contender );
		$this->assertSame( 'flavor_agent_apply_materialization_locked', $contender->get_error_code() );
		$this->assertSame( 'corrupt-or-abandoned-owner', WordPressTestState::$options[ $keys[0] ] );
	}

	public function test_lock_key_partitions_by_site_surface_and_exact_canonical_ref(): void {
		$locks = [];

		try {
			$locks[] = MaterializationLock::acquire( 'template', 'twentytwentyfive//home' );
			$locks[] = MaterializationLock::acquire( 'template', 'twentytwentyfive//single' );
			$locks[] = MaterializationLock::acquire( 'template-part', 'twentytwentyfive//home' );

			WordPressTestState::$current_blog_id = 2;
			$locks[]                             = MaterializationLock::acquire( 'template', 'twentytwentyfive//home' );

			foreach ( $locks as $lock ) {
				$this->assertInstanceOf( MaterializationLock::class, $lock );
			}

			$this->assertCount( 4, self::lock_options() );
		} finally {
			foreach ( array_reverse( $locks ) as $lock ) {
				if ( $lock instanceof MaterializationLock ) {
					$lock->release();
				}
			}
		}

		$this->assertSame( [], self::lock_options() );
	}

	public function test_database_insert_failure_is_not_reported_as_contention(): void {
		WordPressTestState::$option_insert_fails = true;

		$result = MaterializationLock::acquire( 'template', 'twentytwentyfive//home' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_lock_unavailable', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( [], self::lock_options() );
	}

	public function test_release_uses_the_database_and_options_table_captured_at_acquisition(): void {
		$original_database = $GLOBALS['wpdb'];
		$acquisition_store = new class() {

			public string $options = 'wp_options';

			/** @var array<string, array<string, string>> */
			public array $rows = [];

			public function insert( string $table, array $data, array $format = [] ): int|false {
				unset( $format );
				$option_name = (string) ( $data['option_name'] ?? '' );

				if ( '' === $option_name || isset( $this->rows[ $table ][ $option_name ] ) ) {
					return false;
				}

				$this->rows[ $table ][ $option_name ] = (string) ( $data['option_value'] ?? '' );

				return 1;
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query . ' ' . implode( ' ', array_map( 'strval', $args ) );
			}

			public function get_var( string $query ): ?string {
				unset( $query );

				return null;
			}

			public function delete( string $table, array $where, array $format = [] ): int|false {
				unset( $format );
				$option_name = (string) ( $where['option_name'] ?? '' );
				$owner       = (string) ( $where['option_value'] ?? '' );

				if ( ( $this->rows[ $table ][ $option_name ] ?? null ) !== $owner ) {
					return 0;
				}

				unset( $this->rows[ $table ][ $option_name ] );

				return 1;
			}
		};

		try {
			$GLOBALS['wpdb'] = $acquisition_store;
			$lock            = MaterializationLock::acquire( 'template', 'twentytwentyfive//home' );

			$this->assertInstanceOf( MaterializationLock::class, $lock );
			$this->assertCount( 1, $acquisition_store->rows['wp_options'] ?? [] );

			$acquisition_store->options = 'wp_2_options';
			$GLOBALS['wpdb']            = new \wpdb();
			$GLOBALS['wpdb']->options   = 'wp_2_options';

			$lock->release();

			$this->assertSame( [], $acquisition_store->rows['wp_options'] ?? [] );
		} finally {
			$GLOBALS['wpdb'] = $original_database;
		}
	}

	public function test_object_cache_exception_cannot_strand_or_mask_the_sql_lock(): void {
		WordPressTestState::$wp_cache_delete_throws = true;

		$lock = MaterializationLock::acquire( 'template', 'twentytwentyfive//home' );

		$this->assertInstanceOf( MaterializationLock::class, $lock );
		$this->assertCount( 1, self::lock_options() );

		$lock->release();

		$this->assertSame( [], self::lock_options() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function lock_options(): array {
		return array_filter(
			WordPressTestState::$options,
			static fn( string $key ): bool => str_starts_with( $key, 'flavor_agent_materialization_lock_' ),
			ARRAY_FILTER_USE_KEY
		);
	}
}
