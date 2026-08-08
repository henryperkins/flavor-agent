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
		$error_data = $contender->get_error_data();
		$this->assertSame( array_key_first( $owned ), $error_data['lockOptionName'] ?? null );
		$this->assertNotSame( '', (string) ( $error_data['acquiredAt'] ?? '' ) );
		$this->assertStringNotContainsString( (string) reset( $owned ), (string) wp_json_encode( $error_data ) );

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
		$this->assertArrayNotHasKey( 'acquiredAt', $contender->get_error_data() );

		$crafted_token                           = str_repeat( 'a', 32 );
		WordPressTestState::$options[ $keys[0] ] = (string) wp_json_encode(
			[
				'version'    => 1,
				'owner'      => $crafted_token,
				'acquiredAt' => $crafted_token,
			]
		);
		$crafted                                 = MaterializationLock::acquire( 'template-part', 'twentytwentyfive//header' );
		$this->assertInstanceOf( \WP_Error::class, $crafted );
		$this->assertArrayNotHasKey( 'acquiredAt', $crafted->get_error_data() );
		$this->assertStringNotContainsString( $crafted_token, (string) wp_json_encode( $crafted->get_error_data() ) );
	}

	public function test_operator_recovery_is_owner_qualified_and_cannot_delete_a_successor(): void {
		$first = MaterializationLock::acquire( 'template', 'twentytwentyfive//home' );
		$this->assertInstanceOf( MaterializationLock::class, $first );
		$owned       = self::lock_options();
		$first_key   = (string) array_key_first( $owned );
		$first_owner = (string) $owned[ $first_key ];

		$denied = MaterializationLock::recover_abandoned(
			'template',
			'twentytwentyfive//home',
			$first_owner
		);
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'flavor_agent_apply_target_forbidden', $denied->get_error_code() );
		$this->assertSame( $owned, self::lock_options() );

		WordPressTestState::$capabilities['manage_options'] = true;

		$wrong = MaterializationLock::recover_abandoned(
			'template',
			'twentytwentyfive//home',
			'not-the-observed-owner'
		);
		$this->assertInstanceOf( \WP_Error::class, $wrong );
		$this->assertSame( 'flavor_agent_apply_lock_recovery_conflict', $wrong->get_error_code() );
		$this->assertSame( $owned, self::lock_options() );

		$this->assertTrue(
			MaterializationLock::recover_abandoned( 'template', 'twentytwentyfive//home', $first_owner )
		);
		$this->assertSame( [], self::lock_options() );

		$successor = MaterializationLock::acquire( 'template', 'twentytwentyfive//home' );
		$this->assertInstanceOf( MaterializationLock::class, $successor );
		$successor_owned = self::lock_options();

		$stale = MaterializationLock::recover_abandoned(
			'template',
			'twentytwentyfive//home',
			$first_owner
		);
		$this->assertInstanceOf( \WP_Error::class, $stale );
		$this->assertSame( 'flavor_agent_apply_lock_recovery_conflict', $stale->get_error_code() );
		$this->assertSame( $successor_owned, self::lock_options() );
		$this->assertTrue( $successor->release() );
	}

	public function test_operator_recovery_captures_the_owner_site_before_capability_filters_run(): void {
		$lock = MaterializationLock::acquire( 'template', 'twentytwentyfive//home' );
		$this->assertInstanceOf( MaterializationLock::class, $lock );
		$owned         = self::lock_options();
		$origin_key    = (string) array_key_first( $owned );
		$owner         = (string) $owned[ $origin_key ];
		$original_blog = WordPressTestState::$current_blog_id;
		$wrong_key     = '';
		$wrong_lock    = null;

		try {
			WordPressTestState::$current_blog_id = 2;
			$wrong_lock                          = MaterializationLock::acquire( 'template', 'twentytwentyfive//home' );
			$this->assertInstanceOf( MaterializationLock::class, $wrong_lock );
			$wrong_site_options = array_diff_key( self::lock_options(), $owned );
			$this->assertCount( 1, $wrong_site_options );
			$wrong_key = (string) array_key_first( $wrong_site_options );
		} finally {
			if ( $wrong_lock instanceof MaterializationLock ) {
				$wrong_lock->release();
			}
			WordPressTestState::$current_blog_id = $original_blog;
		}

		$this->assertNotSame( '', $wrong_key );
		$this->assertSame( $owned, self::lock_options() );

		$original_database                             = $GLOBALS['wpdb'];
		$wrong_database                                = new \wpdb();
		$wrong_database->prefix                        = 'wp_2_';
		$wrong_database->options                       = 'wp_2_options';
		$wrong_database->posts                         = 'wp_2_posts';
		$wrong_database->postmeta                      = 'wp_2_postmeta';
		$wrong_database->terms                         = 'wp_2_terms';
		$wrong_database->term_taxonomy                 = 'wp_2_term_taxonomy';
		$wrong_database->term_relationships            = 'wp_2_term_relationships';
		WordPressTestState::$db_tables['wp_2_options'] = [
			[
				'option_name'  => $wrong_key,
				'option_value' => $owner,
				'autoload'     => 'no',
			],
		];
		$switched                                      = false;
		WordPressTestState::$capabilities['manage_options'] = static function () use ( $wrong_database, &$switched ): bool {
			if ( ! $switched ) {
				$switched                            = true;
				$GLOBALS['wpdb']                     = $wrong_database;
				WordPressTestState::$current_blog_id = 2;
			}

			return true;
		};

		try {
			$result = MaterializationLock::recover_abandoned(
				'template',
				'twentytwentyfive//home',
				$owner
			);
		} finally {
			$GLOBALS['wpdb']                     = $original_database;
			WordPressTestState::$current_blog_id = $original_blog;
		}

		$this->assertTrue( $switched );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_lock_unavailable', $result->get_error_code() );
		$this->assertSame( $origin_key, $result->get_error_data()['lockOptionName'] ?? null );
		$this->assertStringNotContainsString( $owner, (string) wp_json_encode( $result->get_error_data() ) );
		$this->assertSame( $owner, self::lock_options()[ $origin_key ] ?? null );
		$this->assertSame( $owner, WordPressTestState::$db_tables['wp_2_options'][0]['option_value'] ?? null );
	}

	public function test_release_reports_a_persisted_owner_when_delete_fails_and_can_retry(): void {
		$lock = MaterializationLock::acquire( 'template', 'twentytwentyfive//home' );
		$this->assertInstanceOf( MaterializationLock::class, $lock );
		WordPressTestState::$option_delete_fails = true;

		$this->assertFalse( $lock->release() );
		$this->assertCount( 1, self::lock_options() );

		WordPressTestState::$option_delete_fails = false;
		$this->assertTrue( $lock->release() );
		$this->assertSame( [], self::lock_options() );
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

			public string $posts = 'wp_posts';

			public string $postmeta = 'wp_postmeta';

			public string $terms = 'wp_terms';

			public string $term_taxonomy = 'wp_term_taxonomy';

			public string $term_relationships = 'wp_term_relationships';

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
