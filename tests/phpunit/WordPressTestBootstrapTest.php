<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class WordPressTestBootstrapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
	}

	public function test_nonconditional_post_update_detects_a_row_removed_by_the_query_filter(): void {
		global $wpdb;

		$post_id                               = 9701;
		WordPressTestState::$posts[ $post_id ] = new \WP_Post(
			[
				'ID'         => $post_id,
				'post_title' => 'Before',
			]
		);
		add_filter(
			'query',
			static function ( string $query ) use ( $post_id ): string {
				if ( str_starts_with( $query, 'UPDATE wp_posts SET ' ) ) {
					unset( WordPressTestState::$posts[ $post_id ] );
				}

				return $query;
			}
		);

		$updated = $wpdb->update(
			$wpdb->posts,
			[ 'post_title' => 'After' ],
			[ 'ID' => $post_id ]
		);

		$this->assertSame( 0, $updated );
		$this->assertArrayNotHasKey( $post_id, WordPressTestState::$posts );
	}

	public function test_posts_select_applies_type_name_and_status_predicates(): void {
		global $wpdb;

		WordPressTestState::$posts = [
			9702 => new \WP_Post(
				[
					'ID'          => 9702,
					'post_type'   => 'wp_template',
					'post_name'   => 'home',
					'post_status' => 'publish',
				]
			),
			9703 => new \WP_Post(
				[
					'ID'          => 9703,
					'post_type'   => 'wp_template',
					'post_name'   => 'footer',
					'post_status' => 'publish',
				]
			),
			9704 => new \WP_Post(
				[
					'ID'          => 9704,
					'post_type'   => 'wp_template_part',
					'post_name'   => 'home',
					'post_status' => 'publish',
				]
			),
			9705 => new \WP_Post(
				[
					'ID'          => 9705,
					'post_type'   => 'wp_template',
					'post_name'   => 'home',
					'post_status' => 'draft',
				]
			),
		];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ID, post_type, post_name, post_status FROM %i WHERE post_type = %s AND post_name = %s AND post_status = %s',
				$wpdb->posts,
				'wp_template',
				'home',
				'publish'
			),
			ARRAY_A
		);

		$this->assertSame( [ 9702 ], array_column( $rows, 'ID' ) );
	}

	public function test_guarded_meta_digest_uses_the_serialized_database_value(): void {
		global $wpdb;

		$post_id                               = 9706;
		$meta_key                              = 'structured';
		$current                               = [ 'nested' => [ 'value' ] ];
		$current_raw                           = (string) maybe_serialize( $current );
		$next_raw                              = 'next';
		$post                                  = new \WP_Post(
			[
				'ID'           => $post_id,
				'post_type'    => 'wp_template_part',
				'post_name'    => 'header',
				'post_content' => 'Body',
			]
		);
		WordPressTestState::$posts[ $post_id ] = $post;
		WordPressTestState::$post_meta[ $post_id ][ $meta_key ] = $current;

		$query  = 'UPDATE `wp_postmeta` SET `meta_value` = \'' . $next_raw . '\''
			. ' WHERE `meta_id` = ' . flavor_agent_test_meta_id( $post_id, $meta_key )
			. ' AND `post_id` = ' . $post_id
			. ' AND HEX(`meta_key`) = \'' . strtoupper( bin2hex( $meta_key ) ) . '\''
			. ' AND ' . self::guarded_digest_clause( '`meta_value`', $current_raw )
			. ' AND EXISTS (SELECT 1 FROM `wp_posts` AS `owner` WHERE `owner`.`ID` = ' . $post_id
			. ' AND HEX(`owner`.`post_type`) = \'' . strtoupper( bin2hex( $post->post_type ) ) . '\''
			. ' AND HEX(`owner`.`post_name`) = \'' . strtoupper( bin2hex( $post->post_name ) ) . '\''
			. ' AND HEX(`owner`.`post_status`) = \'' . strtoupper( bin2hex( $post->post_status ) ) . '\''
			. ' AND HEX(`owner`.`post_password`) = \'' . strtoupper( bin2hex( $post->post_password ) ) . '\''
			. ' AND HEX(`owner`.`post_modified`) = \'' . strtoupper( bin2hex( $post->post_modified ) ) . '\''
			. ' AND HEX(`owner`.`post_modified_gmt`) = \'' . strtoupper( bin2hex( $post->post_modified_gmt ) ) . '\''
			. ' AND ' . self::guarded_digest_clause( '`owner`.`post_content`', $post->post_content )
			. ')';
		$method = new \ReflectionMethod( $wpdb, 'guarded_meta_update_matches' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $wpdb, $query, $post_id, $meta_key, $next_raw ) );
	}

	public function test_update_metadata_treats_an_equal_structured_value_as_a_noop(): void {
		$post_id                               = 9707;
		$value                                 = [ 'nested' => [ 'value' ] ];
		WordPressTestState::$posts[ $post_id ] = new \WP_Post( [ 'ID' => $post_id ] );
		$this->assertIsInt( add_post_meta( $post_id, 'structured', $value ) );
		$update_hooks = 0;
		add_action(
			'update_post_meta',
			static function () use ( &$update_hooks ): void {
				++$update_hooks;
			}
		);

		$updated = update_post_meta( $post_id, 'structured', $value );

		$this->assertFalse( $updated );
		$this->assertSame( 0, $update_hooks );
	}

	public function test_add_option_invalidates_a_primary_cached_miss(): void {
		$option_name = 'bootstrap_added_option';

		$this->assertSame( 'missing', get_option( $option_name, 'missing' ) );
		$this->assertArrayHasKey( $option_name, WordPressTestState::$option_notoptions_cache );

		$this->assertTrue( add_option( $option_name, 'stored', '', 'no' ) );
		$this->assertArrayNotHasKey( $option_name, WordPressTestState::$option_notoptions_cache );
		$this->assertSame( 'stored', get_option( $option_name, 'missing' ) );
	}

	public function test_raw_primary_option_insert_requires_explicit_cache_invalidation(): void {
		global $wpdb;

		$option_name = 'bootstrap_raw_option';
		$this->assertSame( 'missing', get_option( $option_name, 'missing' ) );

		$this->assertSame(
			1,
			$wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => $option_name,
					'option_value' => 'stored',
					'autoload'     => 'no',
				]
			)
		);
		$this->assertSame( 'missing', get_option( $option_name, 'missing' ) );

		$this->assertTrue( wp_cache_delete( 'notoptions', 'options' ) );
		$this->assertSame( 'stored', get_option( $option_name, 'missing' ) );
	}

	public function test_raw_option_insert_and_delete_use_the_selected_alternate_table(): void {
		global $wpdb;

		$original_table = $wpdb->options;
		$wpdb->options  = 'wp_2_options';

		try {
			$this->assertSame(
				1,
				$wpdb->insert(
					$wpdb->options,
					[
						'option_name'  => 'bootstrap_alternate_raw_option',
						'option_value' => 'alternate-owner',
						'autoload'     => 'no',
					]
				)
			);
			$this->assertSame( 'alternate-owner', get_option( 'bootstrap_alternate_raw_option', 'missing' ) );
			$this->assertArrayNotHasKey( 'bootstrap_alternate_raw_option', WordPressTestState::$options );

			$this->assertSame(
				1,
				$wpdb->delete(
					$wpdb->options,
					[
						'option_name'  => 'bootstrap_alternate_raw_option',
						'option_value' => 'alternate-owner',
					]
				)
			);
			$this->assertSame( 'missing', get_option( 'bootstrap_alternate_raw_option', 'missing' ) );
		} finally {
			$wpdb->options = $original_table;
		}
	}

	public function test_add_option_uses_the_selected_alternate_table(): void {
		global $wpdb;

		$original_table = $wpdb->options;
		$wpdb->options  = 'wp_2_options';

		try {
			$this->assertTrue( add_option( 'bootstrap_alternate_option', 'alternate-value', '', 'no' ) );
			$this->assertArrayNotHasKey( 'bootstrap_alternate_option', WordPressTestState::$options );
			$this->assertSame( 'alternate-value', get_option( 'bootstrap_alternate_option', 'missing' ) );
			$this->assertFalse( add_option( 'bootstrap_alternate_option', 'replacement', '', 'yes' ) );
			$this->assertSame( 'alternate-value', get_option( 'bootstrap_alternate_option', 'missing' ) );
		} finally {
			$wpdb->options = $original_table;
		}
	}

	private static function guarded_digest_clause( string $column, string $value ): string {
		$prefix_one = 'a1';
		$suffix_one = 'b2';
		$prefix_two = 'c3';
		$suffix_two = 'd4';
		$value_hex  = strtoupper( bin2hex( $value ) );

		return 'OCTET_LENGTH(' . $column . ') = ' . strlen( $value )
			. ' AND MD5(CONCAT(\'' . $prefix_one . '\', HEX(' . $column . '), \'' . $suffix_one . '\')) = \''
			. md5( $prefix_one . $value_hex . $suffix_one ) . '\''
			. ' AND MD5(CONCAT(\'' . $prefix_two . '\', HEX(' . $column . '), \'' . $suffix_two . '\')) = \''
			. md5( $prefix_two . $value_hex . $suffix_two ) . '\'';
	}
}
