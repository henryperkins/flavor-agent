<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Apply\TemplateApplyExecutor;
use FlavorAgent\Apply\MaterializationLock;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class TemplateApplyExecutorTest extends TestCase {

	private const TEMPLATE_REF = 'twentytwentyfive//home';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$active_theme = [ 'stylesheet' => 'twentytwentyfive' ];
	}

	/**
	 * Seed the live template into the get_block_template(s) store so the bound
	 * TemplateRepository::resolve_template resolves it. When $wp_id > 0 also seed
	 * a wp_template post as the wp_update_post target.
	 */
	private function seed_template( string $content, int $wp_id = 0, string $slug = 'home' ): void {
		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'          => self::TEMPLATE_REF,
				'wp_id'       => $wp_id,
				'slug'        => $slug,
				'title'       => 'Home',
				'description' => 'Home template description',
				'source'      => 'theme',
				'content'     => $content,
			],
		];

		if ( $wp_id > 0 ) {
			WordPressTestState::$posts[ $wp_id ] = new \WP_Post(
				[
					'ID'           => $wp_id,
					'post_type'    => 'wp_template',
					'post_name'    => $slug,
					'post_content' => $content,
				]
			);
		}
	}

	public function test_resolve_template_for_apply_returns_the_live_template(): void {
		$this->seed_template( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );

		$template = ServerCollector::resolve_template_for_apply( self::TEMPLATE_REF );

		$this->assertIsObject( $template );
		$this->assertSame( self::TEMPLATE_REF, $template->id );
	}

	public function test_resolve_template_for_apply_returns_null_when_missing(): void {
		$this->assertNull( ServerCollector::resolve_template_for_apply( 'twentytwentyfive//does-not-exist' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<string, mixed>
	 */
	private function entry( array $operations ): array {
		return [
			'surface'  => 'template',
			'target'   => [
				'templateRef'  => self::TEMPLATE_REF,
				'templateType' => 'home',
			],
			'document' => [
				'entityId' => self::TEMPLATE_REF,
				'postType' => 'wp_template',
				'scopeKey' => 'wp_template:' . self::TEMPLATE_REF,
			],
			'apply'    => [ 'operations' => $operations ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function executed_entry( string $before, string $after ): array {
		return [
			'surface'  => 'template',
			'target'   => [
				'templateRef'  => self::TEMPLATE_REF,
				'templateType' => 'home',
			],
			'document' => [
				'entityId' => self::TEMPLATE_REF,
				'postType' => 'wp_template',
				'scopeKey' => 'wp_template:' . self::TEMPLATE_REF,
			],
			'before'   => [ 'content' => $before ],
			'after'    => [ 'content' => $after ],
		];
	}

	public function test_resolve_target_identity_returns_the_canonical_template_subject(): void {
		$identity = TemplateApplyExecutor::resolve_target_identity( $this->entry( [] ) );

		$this->assertSame(
			[
				'target'   => [
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'home',
				],
				'document' => [
					'entityId' => self::TEMPLATE_REF,
					'postType' => 'wp_template',
					'scopeKey' => 'wp_template:' . self::TEMPLATE_REF,
				],
			],
			$identity
		);
	}

	public function test_authorize_target_rejects_each_divergent_template_document_field_before_capability(): void {
		$mutations = [
			'entityId' => 'twentytwentyfive//single',
			'postType' => 'wp_template_part',
			'scopeKey' => 'wp_template:twentytwentyfive//single',
		];

		foreach ( $mutations as $field => $value ) {
			$entry                                 = $this->entry( [] );
			$entry['document'][ $field ]           = $value;
			$reads                                 = 0;
			WordPressTestState::$capability_checks = [];
			WordPressTestState::$block_templates_read_hook = static function () use ( &$reads ): void {
				++$reads;
			};

			$result = TemplateApplyExecutor::authorize_target( $entry );

			$this->assertInstanceOf( \WP_Error::class, $result, $field );
			$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code(), $field );
			$this->assertSame( 409, $result->get_error_data()['status'] ?? null, $field );
			$this->assertSame( 0, $reads, $field );
			$this->assertSame( [], WordPressTestState::$capability_checks, $field );
		}

		foreach ( array_keys( $mutations ) as $field ) {
			$entry = $this->entry( [] );
			unset( $entry['document'][ $field ] );
			$reads                                 = 0;
			WordPressTestState::$capability_checks = [];
			WordPressTestState::$block_templates_read_hook = static function () use ( &$reads ): void {
				++$reads;
			};

			$result = TemplateApplyExecutor::authorize_target( $entry );

			$this->assertInstanceOf( \WP_Error::class, $result, 'missing ' . $field );
			$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code(), 'missing ' . $field );
			$this->assertSame( 0, $reads, 'missing ' . $field );
			$this->assertSame( [], WordPressTestState::$capability_checks, 'missing ' . $field );
		}
	}

	public function test_authorize_target_requires_edit_theme_options_for_a_canonical_template(): void {
		$result = TemplateApplyExecutor::authorize_target( $this->entry( [] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
		$this->assertSame(
			[
				[
					'capability' => 'edit_theme_options',
					'args'       => [],
				],
			],
			WordPressTestState::$capability_checks
		);

		WordPressTestState::$capabilities['edit_theme_options'] = true;
		$this->assertTrue( TemplateApplyExecutor::authorize_target( $this->entry( [] ) ) );
	}

	public function test_authorize_target_allows_non_security_document_metadata_when_canonical_fields_match(): void {
		$entry                           = $this->entry( [] );
		$entry['document']['entityKind'] = 'postType';
		$entry['document']['entityName'] = 'wp_template';
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		$this->assertTrue( TemplateApplyExecutor::authorize_target( $entry ) );
	}

	public function test_authorize_target_rejects_non_string_template_refs_before_reads_or_capability(): void {
		$fixtures = [ [], false, 17, new \stdClass() ];

		foreach ( $fixtures as $template_ref ) {
			$entry                          = $this->entry( [] );
			$entry['target']['templateRef'] = $template_ref;
			$canonical_template_ref         = is_array( $template_ref ) ? 'Array' : ( is_int( $template_ref ) ? (string) $template_ref : '' );

			if ( '' !== $canonical_template_ref ) {
				$entry['document'] = [
					'entityId' => $canonical_template_ref,
					'postType' => 'wp_template',
					'scopeKey' => 'wp_template:' . $canonical_template_ref,
				];
			}

			$reads = 0;

			WordPressTestState::$capability_checks         = [];
			WordPressTestState::$block_templates_read_hook = static function () use ( &$reads ): void {
				++$reads;
			};

			$result = TemplateApplyExecutor::authorize_target( $entry );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] ?? null );
			$this->assertSame( 0, $reads );
			$this->assertSame( [], WordPressTestState::$capability_checks );
		}
	}

	public function test_resolve_baseline_rejects_repository_slug_fallback_to_a_different_template_id(): void {
		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => 'othertheme//home',
				'wp_id'   => 0,
				'slug'    => 'home',
				'title'   => 'Other Home',
				'content' => '<!-- wp:paragraph --><p>Wrong subject</p><!-- /wp:paragraph -->',
			],
		];

		$result = TemplateApplyExecutor::resolve_baseline( $this->entry( [] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
	}

	private function register_pattern( string $name, string $content ): void {
		\WP_Block_Patterns_Registry::get_instance()->register(
			$name,
			[
				'title'   => $name,
				'content' => $content,
			]
		);
	}

	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	/** @return array<string, mixed> */
	private static function materialization_locks(): array {
		return array_filter(
			WordPressTestState::$options,
			static fn( string $key ): bool => str_starts_with( $key, 'flavor_agent_materialization_lock_' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	private static function assert_before( string $first, string $second, string $haystack, string $message ): void {
		$a = strpos( $haystack, $first );
		$b = strpos( $haystack, $second );
		self::assertNotFalse( $a, $message . ' (missing ' . $first . ')' );
		self::assertNotFalse( $b, $message . ' (missing ' . $second . ')' );
		self::assertLessThan( $b, $a, $message );
	}

	public function test_resolve_baseline_hashes_reserialized_template_content(): void {
		$content = '<!-- wp:navigation /-->';
		$this->seed_template( $content );

		$hash = \FlavorAgent\Apply\TemplateApplyExecutor::resolve_baseline( $this->entry( [] ) );

		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
			$hash
		);
	}

	public function test_execute_inserts_pattern_at_start_and_persists_in_place(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 9100 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::TEMPLATE_REF, $result['target']['templateRef'] );
		$this->assertSame( 'home', $result['target']['templateType'] );
		$this->assertStringContainsString( 'Hero', $result['after']['content'] );
		$this->assertStringContainsString( 'Body', $result['after']['content'] );
		$this->assertStringStartsWith( '<!-- wp:paragraph --><p>Hero', $result['after']['content'] );
		$this->assertSame( $this->paragraph( 'Body' ), $result['before']['content'] );
		$this->assertSame( $result['after']['content'], (string) WordPressTestState::$posts[9100]->post_content );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_uses_raw_database_bytes_when_block_hooks_transform_the_template_view(): void {
		$before       = $this->paragraph( 'Body' );
		$hooked_block = $this->paragraph( 'Dynamic Block Hook' );
		$hook_marker  = $this->paragraph( 'Ignored Hook Metadata' );
		$this->seed_template( $before, 9428 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$object_terms[9428]['wp_theme']   = [ 'twentytwentyfive' ];
		WordPressTestState::$block_template_content_transform = static function ( string $content ) use ( $hooked_block, $hook_marker ): string {
			return str_contains( $content, 'Ignored Hook Metadata' )
				? str_replace( $hook_marker, $hooked_block, $content )
				: $hooked_block . $content;
		};
		$prepared = false;
		WordPressTestState::$block_template_write_preparer = static function ( object $changes ) use ( &$prepared, $hooked_block, $hook_marker ): object {
			$prepared              = true;
			$changes->post_content = str_replace( $hooked_block, $hook_marker, (string) $changes->post_content );

			return $changes;
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $prepared );
		$this->assertStringContainsString( 'Dynamic Block Hook', $result['before']['content'] );
		$this->assertStringContainsString( 'Dynamic Block Hook', $result['after']['content'] );
		$this->assertStringNotContainsString( 'Dynamic Block Hook', WordPressTestState::$posts[9428]->post_content );
		$this->assertStringContainsString( 'Ignored Hook Metadata', WordPressTestState::$posts[9428]->post_content );

		$undo = TemplateApplyExecutor::undo(
			self::executed_entry( $result['before']['content'], $result['after']['content'] )
		);

		$this->assertIsArray( $undo );
		$this->assertSame( 'undone', $undo['result'] );
		$this->assertSame(
			serialize_blocks( parse_blocks( $result['before']['content'] ) ),
			serialize_blocks( parse_blocks( $undo['after']['content'] ) )
		);
	}

	public function test_execute_preserves_exact_backslashes_through_wp_update_post(): void {
		$path   = 'C:\\workspace\\theme';
		$before = $this->paragraph( 'Path ' . $path );
		$this->seed_template( $before, 9429 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( $path, WordPressTestState::$posts[9429]->post_content );
		$this->assertSame( $result['after']['content'], WordPressTestState::$posts[9429]->post_content );
	}

	public function test_execute_returns_the_post_persist_content_after_a_save_filter_changes_it(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 9199 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( isset( $data['post_content'] ) ) {
					$data['post_content'] = str_replace( '>Hero<', '>Hero saved<', (string) $data['post_content'] );
				}

				return $data;
			},
			10,
			4
		);

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Hero saved', (string) WordPressTestState::$posts[9199]->post_content );
		$this->assertSame( WordPressTestState::$posts[9199]->post_content, $result['after']['content'] );
	}

	public function test_execute_fails_when_a_save_hook_moves_the_post_off_the_canonical_ref(): void {
		$before = $this->paragraph( 'Body' );
		$this->seed_template( $before, 9414 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9414]->post_name         = 'home';
		WordPressTestState::$object_terms[9414]['wp_theme'] = [ 'twentytwentyfive' ];
		add_filter(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ): array {
				if ( 9414 === (int) ( $postarr['ID'] ?? 0 ) ) {
					$data['post_name'] = 'home-moved';
					WordPressTestState::$block_templates['wp_template'][0]->id   = 'twentytwentyfive//home-moved';
					WordPressTestState::$block_templates['wp_template'][0]->slug = 'home-moved';
				}

				return $data;
			},
			10,
			2
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( 'home', WordPressTestState::$posts[9414]->post_name );
		$this->assertSame( $before, WordPressTestState::$posts[9414]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_does_not_adopt_exact_intended_bytes_after_the_database_guard_misses(): void {
		$before   = $this->paragraph( 'Body' );
		$intended = $this->paragraph( 'Hero' ) . $before;
		$this->seed_template( $before, 9424 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9424]->post_name                = 'home';
		WordPressTestState::$object_terms[9424]['wp_theme']        = [ 'twentytwentyfive' ];
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ) use ( $intended ): void {
			WordPressTestState::$posts[ $post_id ]->post_content = $intended;
		};
		$post_updated_fired                                        = false;
		add_action(
			'post_updated',
			static function ( int $post_id ) use ( &$post_updated_fired ): void {
				if ( $post_updated_fired ) {
					return;
				}

				$post_updated_fired = true;
				wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => '<!-- wp:paragraph --><p>Hook-owned follow-up</p><!-- /wp:paragraph -->',
					],
					true
				);
			},
			10,
			3
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertFalse( $post_updated_fired );
		$this->assertSame( $intended, WordPressTestState::$posts[9424]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_requires_recovery_when_the_guarded_row_is_deleted_before_the_update(): void {
		$before = $this->paragraph( 'Body' );
		$this->seed_template( $before, 9431 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9431]->post_name                       = 'home';
		WordPressTestState::$object_terms[9431]['wp_theme']               = [ 'twentytwentyfive' ];
		WordPressTestState::$post_meta[9431]['_wp_ignored_hooked_blocks'] = '["plugin/old"]';
		WordPressTestState::$block_template_write_preparer                = static function ( object $changes ): object {
			$changes->meta_input['_wp_ignored_hooked_blocks'] = '["plugin/dynamic"]';

			return $changes;
		};
		WordPressTestState::$before_conditional_post_content_write        = static function ( int $post_id ): void {
			unset( WordPressTestState::$posts[ $post_id ], WordPressTestState::$post_meta[ $post_id ] );
		};
		$post_updated_fired = false;
		add_action(
			'post_updated',
			static function () use ( &$post_updated_fired ): void {
				$post_updated_fired = true;
			},
			10,
			3
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertFalse( $post_updated_fired );
		$this->assertArrayNotHasKey( 9431, WordPressTestState::$posts );
		$this->assertArrayNotHasKey( 9431, WordPressTestState::$post_meta );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_fails_before_an_annotated_core_update_can_bypass_the_guard(): void {
		$before = $this->paragraph( 'Body' );
		$this->seed_template( $before, 9425 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9425]->post_name         = 'home';
		WordPressTestState::$object_terms[9425]['wp_theme'] = [ 'twentytwentyfive' ];
		add_filter(
			'query',
			static function ( string $query ): string {
				return str_starts_with( ltrim( $query ), 'UPDATE wp_posts SET' )
					? $query . ' /* drop-in annotation */'
					: $query;
			}
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_write_failed', $result->get_error_code() );
		$this->assertSame( $before, WordPressTestState::$posts[9425]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_fails_before_a_hook_time_blog_switch_can_write_the_wrong_posts_table(): void {
		global $wpdb;

		$before         = $this->paragraph( 'Body' );
		$original_table = (string) $wpdb->posts;
		$this->seed_template( $before, 9426 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9426]->post_name         = 'home';
		WordPressTestState::$object_terms[9426]['wp_theme'] = [ 'twentytwentyfive' ];
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ) use ( $wpdb ): array {
				$wpdb->posts = 'wp_2_posts';

				return $data;
			}
		);

		try {
			$result = TemplateApplyExecutor::execute(
				$this->entry(
					[
						[
							'type'        => 'insert_pattern',
							'patternName' => 'tt5/hero',
							'placement'   => 'start',
						],
					]
				)
			);
		} finally {
			$wpdb->posts = $original_table;
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_write_failed', $result->get_error_code() );
		$this->assertSame( $before, WordPressTestState::$posts[9426]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_blocks_a_hook_time_database_object_replacement_before_the_write(): void {
		global $wpdb;

		$before            = $this->paragraph( 'Body' );
		$original_database = $wpdb;
		$original_blog     = WordPressTestState::$current_blog_id;
		$this->seed_template( $before, 9436 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9436]->post_name         = 'home';
		WordPressTestState::$object_terms[9436]['wp_theme'] = [ 'twentytwentyfive' ];
		add_action(
			'pre_post_update',
			static function (): void {
				$replacement                         = new \wpdb();
				$replacement->prefix                 = 'wp_2_';
				$replacement->posts                  = 'wp_2_posts';
				$replacement->options                = 'wp_2_options';
				$GLOBALS['wpdb']                     = $replacement;
				WordPressTestState::$current_blog_id = 2;
			},
			10,
			2
		);

		try {
			$result = TemplateApplyExecutor::execute(
				$this->entry(
					[
						[
							'type'        => 'insert_pattern',
							'patternName' => 'tt5/hero',
							'placement'   => 'start',
						],
					]
				)
			);
		} finally {
			$GLOBALS['wpdb']                     = $original_database;
			WordPressTestState::$current_blog_id = $original_blog;
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_write_failed', $result->get_error_code() );
		$this->assertSame( $before, WordPressTestState::$posts[9436]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_fails_before_block_hooks_preparation_can_escape_the_locked_site(): void {
		global $wpdb;

		$before           = $this->paragraph( 'Body' );
		$original_posts   = (string) $wpdb->posts;
		$original_options = (string) $wpdb->options;
		$original_blog    = WordPressTestState::$current_blog_id;
		$this->seed_template( $before, 9433 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9433]->post_name         = 'home';
		WordPressTestState::$object_terms[9433]['wp_theme'] = [ 'twentytwentyfive' ];
		WordPressTestState::$block_template_write_preparer  = static function ( object $changes ) use ( $wpdb ): object {
			$wpdb->posts                         = 'wp_2_posts';
			$wpdb->options                       = 'wp_2_options';
			WordPressTestState::$current_blog_id = 2;

			return $changes;
		};

		try {
			$result = TemplateApplyExecutor::execute(
				$this->entry(
					[
						[
							'type'        => 'insert_pattern',
							'patternName' => 'tt5/hero',
							'placement'   => 'start',
						],
					]
				)
			);
		} finally {
			$wpdb->posts                         = $original_posts;
			$wpdb->options                       = $original_options;
			WordPressTestState::$current_blog_id = $original_blog;
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_write_context_changed', $result->get_error_code() );
		$this->assertSame( $before, WordPressTestState::$posts[9433]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_returns_recovery_required_when_post_updated_leaves_the_site_context_switched(): void {
		global $wpdb;

		$before           = $this->paragraph( 'Body' );
		$original_posts   = (string) $wpdb->posts;
		$original_options = (string) $wpdb->options;
		$original_blog    = WordPressTestState::$current_blog_id;
		$this->seed_template( $before, 9434 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9434]->post_name         = 'home';
		WordPressTestState::$object_terms[9434]['wp_theme'] = [ 'twentytwentyfive' ];
		add_action(
			'post_updated',
			static function () use ( $wpdb ): void {
				$wpdb->posts                         = 'wp_2_posts';
				$wpdb->options                       = 'wp_2_options';
				WordPressTestState::$current_blog_id = 2;
			},
			10,
			3
		);

		try {
			$result = TemplateApplyExecutor::execute(
				$this->entry(
					[
						[
							'type'        => 'insert_pattern',
							'patternName' => 'tt5/hero',
							'placement'   => 'start',
						],
					]
				)
			);
		} finally {
			$wpdb->posts                         = $original_posts;
			$wpdb->options                       = $original_options;
			WordPressTestState::$current_blog_id = $original_blog;
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertStringContainsString( 'Hero', WordPressTestState::$posts[9434]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_does_not_let_a_nested_pre_update_consume_the_outer_guard(): void {
		$before       = $this->paragraph( 'Body' );
		$hook_content = $this->paragraph( 'Nested hook owner' );
		$this->seed_template( $before, 9427 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9427]->post_name         = 'home';
		WordPressTestState::$object_terms[9427]['wp_theme'] = [ 'twentytwentyfive' ];
		$nested = false;
		add_action(
			'pre_post_update',
			static function ( int $post_id ) use ( &$nested, $hook_content ): void {
				if ( $nested ) {
					return;
				}

				$nested = true;
				wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $hook_content,
					],
					true
				);
			},
			10,
			2
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $hook_content, WordPressTestState::$posts[9427]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_does_not_let_a_nested_query_filter_consume_the_outer_guard(): void {
		$before       = $this->paragraph( 'Body' );
		$hook_content = $this->paragraph( 'Nested query-filter owner' );
		$this->seed_template( $before, 9430 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9430]->post_name         = 'home';
		WordPressTestState::$object_terms[9430]['wp_theme'] = [ 'twentytwentyfive' ];
		$nested        = false;
		$nested_result = null;
		add_filter(
			'query',
			static function ( string $query ) use ( &$nested, &$nested_result, $hook_content ): string {
				if ( $nested || ! str_starts_with( ltrim( $query ), 'UPDATE wp_posts SET' ) ) {
					return $query;
				}

				$nested        = true;
				$nested_result = wp_update_post(
					wp_slash(
						[
							'ID'           => 9430,
							'post_content' => $hook_content,
						]
					),
					true
				);

				return $query;
			},
			10
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertSame( 9430, $nested_result );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $hook_content, WordPressTestState::$posts[9430]->post_content );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_does_not_let_a_late_direct_database_update_consume_the_outer_guard(): void {
		global $wpdb;

		$before       = $this->paragraph( 'Body' );
		$hook_content = $this->paragraph( 'Late direct database owner' );
		$this->seed_template( $before, 9432 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9432]->post_name         = 'home';
		WordPressTestState::$object_terms[9432]['wp_theme'] = [ 'twentytwentyfive' ];
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ) use ( $wpdb, $hook_content ): array {
				add_action(
					'pre_post_update',
					static function ( int $post_id ) use ( $wpdb, $hook_content ): void {
						$wpdb->update(
							$wpdb->posts,
							[ 'post_content' => $hook_content ],
							[ 'ID' => $post_id ]
						);
					},
					PHP_INT_MAX,
					2
				);

				return $data;
			}
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $hook_content, WordPressTestState::$posts[9432]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_does_not_adopt_content_changed_after_the_final_gate(): void {
		$before     = $this->paragraph( 'Body' );
		$concurrent = $this->paragraph( 'Concurrent editor after gate' );
		$this->seed_template( $before, 9423 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9423]->post_name         = 'home';
		WordPressTestState::$object_terms[9423]['wp_theme'] = [ 'twentytwentyfive' ];
		WordPressTestState::$before_raw_post_row            = static function ( int $post_id ) use ( $concurrent ): void {
			WordPressTestState::$posts[ $post_id ]->post_content = $concurrent;
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $concurrent, WordPressTestState::$posts[9423]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_blocks_core_slug_repair_after_a_filter_clears_the_canonical_name(): void {
		$before = $this->paragraph( 'Body' );
		$this->seed_template( $before, 9422 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9422]->post_name         = 'home';
		WordPressTestState::$object_terms[9422]['wp_theme'] = [ 'twentytwentyfive' ];
		add_filter(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ): array {
				if ( 9422 === (int) ( $postarr['ID'] ?? 0 ) ) {
					$data['post_name'] = '';
				}

				return $data;
			},
			10,
			2
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( 'home', WordPressTestState::$posts[9422]->post_name );
		$this->assertSame( $before, WordPressTestState::$posts[9422]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_does_not_overwrite_content_changed_at_the_conditional_write_boundary(): void {
		$before     = $this->paragraph( 'Body' );
		$concurrent = $this->paragraph( 'Concurrent editor content' );
		$this->seed_template( $before, 9420 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9420]->post_name                = 'home';
		WordPressTestState::$object_terms[9420]['wp_theme']        = [ 'twentytwentyfive' ];
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ) use ( $concurrent ): void {
			WordPressTestState::$posts[ $post_id ]->post_content = $concurrent;
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $concurrent, WordPressTestState::$posts[9420]->post_content );
		$guarded_queries = array_values(
			array_filter(
				WordPressTestState::$db_queries,
				static fn( string $query ): bool => str_contains( $query, 'SET `ID` = CASE WHEN' )
			)
		);
		$this->assertNotEmpty( $guarded_queries );
		$guarded_query = implode( "\n", $guarded_queries );
		$this->assertStringContainsString( 'OCTET_LENGTH(`post_content`)', $guarded_query );
		$this->assertStringContainsString( 'MD5(CONCAT(', $guarded_query );
		$this->assertStringNotContainsString( 'ON DUPLICATE KEY UPDATE', $guarded_query );
		$this->assertStringNotContainsString( strtoupper( bin2hex( $before ) ), strtoupper( $guarded_query ) );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_guard_mismatch_redacts_content_from_persistent_wpdb_diagnostics(): void {
		global $EZSQL_ERROR, $wpdb;

		$before     = $this->paragraph( 'PRIVATE BEFORE BYTES 7eec19' );
		$concurrent = $this->paragraph( 'Concurrent editor content' );
		$this->seed_template( $before, 9435 );
		$this->register_pattern( 'tt5/private-hero', $this->paragraph( 'PRIVATE AFTER BYTES 9b12c4' ) );
		WordPressTestState::$posts[9435]->post_name                = 'home';
		WordPressTestState::$object_terms[9435]['wp_theme']        = [ 'twentytwentyfive' ];
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ) use ( $concurrent ): void {
			WordPressTestState::$posts[ $post_id ]->post_content = $concurrent;
		};
		$EZSQL_ERROR      = [];
		$wpdb->queries    = [];
		$wpdb->last_query = '';
		$wpdb->func_call  = '';

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/private-hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$diagnostics = (string) wp_json_encode(
			[
				'errors'    => $EZSQL_ERROR,
				'lastQuery' => $wpdb->last_query,
				'funcCall'  => $wpdb->func_call,
				'queries'   => $wpdb->queries,
			]
		);
		$this->assertStringNotContainsString( 'PRIVATE BEFORE BYTES 7eec19', $diagnostics );
		$this->assertStringNotContainsString( 'PRIVATE AFTER BYTES 9b12c4', $diagnostics );
		$this->assertStringContainsString( 'guarded query redacted', $diagnostics );
	}

	public function test_existing_write_requires_recovery_when_its_immediate_readback_is_unavailable(): void {
		$before = $this->paragraph( 'Body' );
		$this->seed_template( $before, 9421 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9421]->post_name         = 'home';
		WordPressTestState::$object_terms[9421]['wp_theme'] = [ 'twentytwentyfive' ];
		WordPressTestState::$after_wp_update_post           = static function (): void {
			WordPressTestState::$next_raw_post_row_returns_null = true;
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertStringContainsString( '>Hero<', WordPressTestState::$posts[9421]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_fails_when_exact_post_write_resolution_selects_another_post(): void {
		$before = $this->paragraph( 'Body' );
		$this->seed_template( $before, 9416 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		add_filter(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ): array {
				if ( 9416 === (int) ( $postarr['ID'] ?? 0 ) ) {
					WordPressTestState::$posts[9499]                    = new \WP_Post(
						[
							'ID'           => 9499,
							'post_type'    => 'wp_template',
							'post_name'    => 'home',
							'post_content' => '<!-- wp:paragraph --><p>Other row</p><!-- /wp:paragraph -->',
						]
					);
					WordPressTestState::$object_terms[9499]['wp_theme'] = [ 'twentytwentyfive' ];
				}

				return $data;
			},
			10,
			2
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( 9499, ServerCollector::resolve_template_for_attestation( self::TEMPLATE_REF )?->wp_id );
		$this->assertSame( $before, WordPressTestState::$posts[9416]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_post_write_identity_change_requires_recovery_without_compensation(): void {
		$before                 = $this->paragraph( 'Body' );
		$written                = $this->paragraph( 'Hero' ) . $before;
		$compensation_attempted = false;
		$this->seed_template( $before, 9417 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9417]->post_name           = 'home';
		WordPressTestState::$object_terms[9417]['wp_theme']   = [ 'twentytwentyfive' ];
		WordPressTestState::$after_wp_update_post             = static function ( int $post_id ): void {
			WordPressTestState::$posts[ $post_id ]->post_name          = 'home-moved';
			WordPressTestState::$block_templates['wp_template'][0]->id = 'twentytwentyfive//home-moved';
		};
		WordPressTestState::$before_post_content_compensation = static function () use ( &$compensation_attempted ): void {
			$compensation_attempted = true;
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertFalse( $compensation_attempted );
		$this->assertSame( $written, WordPressTestState::$posts[9417]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_post_write_content_change_requires_recovery_without_compensation(): void {
		$before     = $this->paragraph( 'Body' );
		$concurrent = $this->paragraph( 'Concurrent owner before capture' );
		$this->seed_template( $before, 9418 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9418]->post_name         = 'home';
		WordPressTestState::$object_terms[9418]['wp_theme'] = [ 'twentytwentyfive' ];
		WordPressTestState::$after_wp_update_post           = static function ( int $post_id ) use ( $concurrent ): void {
			WordPressTestState::$posts[ $post_id ]->post_name          = 'home-moved';
			WordPressTestState::$posts[ $post_id ]->post_content       = $concurrent;
			WordPressTestState::$block_templates['wp_template'][0]->id = 'twentytwentyfive//home-moved';
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( $concurrent, WordPressTestState::$posts[9418]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_post_write_identity_change_does_not_start_a_second_compensation_cas(): void {
		$before                 = $this->paragraph( 'Body' );
		$written                = $this->paragraph( 'Hero' ) . $before;
		$compensation_attempted = false;
		$this->seed_template( $before, 9419 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$posts[9419]->post_name           = 'home';
		WordPressTestState::$object_terms[9419]['wp_theme']   = [ 'twentytwentyfive' ];
		WordPressTestState::$after_wp_update_post             = static function ( int $post_id ): void {
			WordPressTestState::$posts[ $post_id ]->post_name          = 'home-moved';
			WordPressTestState::$block_templates['wp_template'][0]->id = 'twentytwentyfive//home-moved';
		};
		WordPressTestState::$before_post_content_compensation = static function () use ( &$compensation_attempted ): void {
			$compensation_attempted = true;
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertFalse( $compensation_attempted );
		$this->assertSame( $written, WordPressTestState::$posts[9419]->post_content );
		$this->assertSame(
			[],
			array_filter(
				WordPressTestState::$db_queries,
				static fn( string $query ): bool => str_contains( $query, 'HEX(post_content)' )
			)
		);
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_regates_live_content_after_acquiring_the_target_lock(): void {
		$before     = $this->paragraph( 'Body' );
		$concurrent = $before . $this->paragraph( 'Concurrent edit' );
		$this->seed_template( $before, 9410 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$before_materialization_lock_insert = function () use ( $concurrent ): void {
			$this->seed_template( $concurrent, 9410 );
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $concurrent, WordPressTestState::$posts[9410]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_captures_persisted_content_before_releasing_the_target_lock(): void {
		$before = $this->paragraph( 'Body' );
		$later  = $this->paragraph( 'Later writer' );
		$this->seed_template( $before, 9411 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$after_materialization_lock_delete = static function () use ( $later ): void {
			WordPressTestState::$posts[9411]->post_content                  = $later;
			WordPressTestState::$block_templates['wp_template'][0]->content = $later;
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Hero', $result['after']['content'] );
		$this->assertStringNotContainsString( 'Later writer', $result['after']['content'] );
		$this->assertSame( $later, WordPressTestState::$posts[9411]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_inserts_pattern_before_and_after_anchors(): void {
		$content = $this->paragraph( 'First' ) . $this->paragraph( 'Second' );
		$this->seed_template( $content, 9101 );
		$this->register_pattern( 'tt5/intro', $this->paragraph( 'Intro' ) );

		$before_result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/intro',
						'placement'   => 'before_block_path',
						'targetPath'  => [ 1 ],
					],
				]
			)
		);

		$this->assertIsArray( $before_result );
		self::assert_before( 'First', 'Intro', (string) WordPressTestState::$posts[9101]->post_content, 'Existing first block remains first.' );
		self::assert_before( 'Intro', 'Second', (string) WordPressTestState::$posts[9101]->post_content, 'before_block_path must insert ahead of the anchor.' );

		$after_content = $this->paragraph( 'First' ) . $this->paragraph( 'Second' );
		$this->seed_template( $after_content, 9102 );
		$this->register_pattern( 'tt5/outro', $this->paragraph( 'Outro' ) );

		$after_result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			[
				'surface' => 'template',
				'target'  => [
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'home',
				],
				'apply'   => [
					'operations' => [
						[
							'type'        => 'insert_pattern',
							'patternName' => 'tt5/outro',
							'placement'   => 'after_block_path',
							'targetPath'  => [ 0 ],
						],
					],
				],
			]
		);

		$this->assertIsArray( $after_result );
		self::assert_before( 'First', 'Outro', (string) WordPressTestState::$posts[9102]->post_content, 'after_block_path must insert behind the anchor.' );
		self::assert_before( 'Outro', 'Second', (string) WordPressTestState::$posts[9102]->post_content, 'after insertion must precede the next sibling.' );
	}

	/**
	 * v1 is single-insert_pattern: TemplatePrompt::validate_operations_for_apply
	 * caps insert_pattern at one op per request (repeated_pattern_insert), and
	 * execute() re-runs that validator against the live contract before any
	 * mutation. So a second insert_pattern fails closed with zero writes. This
	 * also pins the v1 boundary: apply_operations()'s multi-op
	 * descending-pass machinery (effective_order_path/compare_paths) is structural
	 * parity with the template-part lane and is unreachable here — a future change
	 * that lifts the single-insert cap must land its own multi-op ordering tests.
	 */
	public function test_execute_rejects_a_second_insert_pattern_op_in_v1(): void {
		$this->seed_template( $this->paragraph( 'First' ) . $this->paragraph( 'Second' ), 9110 );
		$this->register_pattern( 'tt5/alpha', $this->paragraph( 'Alpha' ) );
		$this->register_pattern( 'tt5/beta', $this->paragraph( 'Beta' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/alpha',
						'placement'   => 'start',
					],
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/beta',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertContains( 'repeated_pattern_insert', $result->get_error_data()['validationReasons'] ?? [] );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_inserts_pattern_at_end(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 9103 );
		$this->register_pattern( 'tt5/footer', $this->paragraph( 'Footer' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/footer',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		self::assert_before( 'Body', 'Footer', (string) WordPressTestState::$posts[9103]->post_content, 'end must append the pattern.' );
	}

	public function test_execute_rejects_non_insert_pattern_ops_fail_closed(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 9104 );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'       => 'remove_block',
						'targetPath' => [ 0 ],
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame( $this->paragraph( 'Body' ), WordPressTestState::$posts[9104]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_fails_closed_on_anchored_target_drift_without_writing(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 9105 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'           => 'insert_pattern',
						'patternName'    => 'tt5/hero',
						'placement'      => 'before_block_path',
						'targetPath'     => [ 0 ],
						'expectedTarget' => [
							'name'       => 'core/heading',
							'childCount' => 0,
						],
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_fails_closed_on_unregistered_pattern_without_writing(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 9106 );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/missing',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_materializes_a_theme_file_template_once(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts, 'Exactly one wp_template row may be created.' );
		$inserted = WordPressTestState::$inserted_posts[0];
		$this->assertSame( 'wp_template', $inserted['post_type'] );
		$this->assertSame( [ 'wp_theme' => 'twentytwentyfive' ], $inserted['tax_input'] );
		$this->assertArrayNotHasKey( 'wp_template_part_area', $inserted['tax_input'] );
		$this->assertSame( 'Home template description', $inserted['post_excerpt'] );
		$this->assertSame( 'theme', WordPressTestState::$post_meta[ (int) $inserted['ID'] ]['origin'] ?? null );
		$this->assertStringContainsString( 'Hero', (string) $inserted['post_content'] );
		$this->assertNotEmpty( WordPressTestState::$cleaned_post_caches );
	}

	public function test_materialization_preserves_exact_backslashes_through_wp_insert_post(): void {
		$path   = 'C:\\workspace\\theme';
		$before = $this->paragraph( 'Path ' . $path );
		$this->seed_template( $before, 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( $path, (string) WordPressTestState::$inserted_posts[0]['post_content'] );
		$this->assertSame( $result['after']['content'], WordPressTestState::$inserted_posts[0]['post_content'] );
	}

	public function test_materialization_prepares_block_hooks_and_returns_semantic_content(): void {
		$before       = $this->paragraph( 'Body' );
		$hooked_block = $this->paragraph( 'Dynamic Block Hook' );
		$hook_marker  = $this->paragraph( 'Ignored Hook Metadata' );
		$this->seed_template( $before, 0 );
		WordPressTestState::$block_templates['wp_template'][0]->source = 'plugin';
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$block_template_content_transform = static function ( string $content ) use ( $hooked_block, $hook_marker ): string {
			return str_contains( $content, 'Ignored Hook Metadata' )
				? str_replace( $hook_marker, $hooked_block, $content )
				: $hooked_block . $content;
		};
		WordPressTestState::$block_template_write_preparer    = static function ( object $changes ) use ( $hooked_block, $hook_marker ): object {
			$changes->post_content = str_replace( $hooked_block, $hook_marker, (string) $changes->post_content );

			return $changes;
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Ignored Hook Metadata', (string) WordPressTestState::$inserted_posts[0]['post_content'] );
		$this->assertStringNotContainsString( 'Dynamic Block Hook', (string) WordPressTestState::$inserted_posts[0]['post_content'] );
		$this->assertStringContainsString( 'Dynamic Block Hook', $result['after']['content'] );
		$this->assertSame(
			'plugin',
			WordPressTestState::$post_meta[ (int) WordPressTestState::$inserted_posts[0]['ID'] ]['origin'] ?? null
		);
	}

	public function test_materialization_rejects_an_active_theme_switch_before_the_write(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$block_templates_read_hook = static function (): void {
			WordPressTestState::$active_theme = [ 'stylesheet' => 'othertheme' ];
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_materialization_fails_retryably_when_the_canonical_target_is_locked(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		$lock = MaterializationLock::acquire( 'template', self::TEMPLATE_REF );
		$this->assertInstanceOf( MaterializationLock::class, $lock );

		try {
			$result = TemplateApplyExecutor::execute(
				$this->entry(
					[
						[
							'type'        => 'insert_pattern',
							'patternName' => 'tt5/hero',
							'placement'   => 'start',
						],
					]
				)
			);
		} finally {
			$lock->release();
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_materialization_locked', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_materialization_releases_the_lock_when_the_insert_throws(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				unset( $data );

				throw new \RuntimeException( 'insert interrupted' );
			}
		);

		try {
			TemplateApplyExecutor::execute(
				$this->entry(
					[
						[
							'type'        => 'insert_pattern',
							'patternName' => 'tt5/hero',
							'placement'   => 'start',
						],
					]
				)
			);
			$this->fail( 'Expected the insert hook to interrupt materialization.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'insert interrupted', $error->getMessage() );
		}

		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_materialization_lock_blocks_a_nested_update_before_attestation(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		$entry  = $this->entry(
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'tt5/hero',
					'placement'   => 'start',
				],
			]
		);
		$nested = null;
		add_action(
			'wp_after_insert_post',
			static function ( int $post_id, \WP_Post $post ) use ( $entry, &$nested ): void {
				unset( $post_id );

				if ( 'wp_template' === $post->post_type && null === $nested ) {
					$nested = TemplateApplyExecutor::execute( $entry );
				}
			},
			10,
			2
		);

		$result = TemplateApplyExecutor::execute( $entry );

		$this->assertIsArray( $result );
		$this->assertInstanceOf( \WP_Error::class, $nested );
		$this->assertSame( 'flavor_agent_apply_materialization_locked', $nested->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_materialization_preserves_the_exact_mixed_case_stylesheet_identity(): void {
		$ref = 'MyTheme//home';
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		WordPressTestState::$block_templates['wp_template'][0]->id = $ref;
		WordPressTestState::$active_theme                          = [ 'stylesheet' => 'MyTheme' ];
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		$entry                          = $this->entry(
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'tt5/hero',
					'placement'   => 'start',
				],
			]
		);
		$entry['target']['templateRef'] = $ref;
		$entry['document']['entityId']  = $ref;
		$entry['document']['scopeKey']  = 'wp_template:' . $ref;

		$result = TemplateApplyExecutor::execute( $entry );

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertSame( [ 'MyTheme' ], WordPressTestState::$object_terms[ $post_id ]['wp_theme'] );
	}

	public function test_materialization_leaves_the_row_when_taxonomy_assignment_is_skipped(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$skip_insert_taxonomy_assignment = true;

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertSame( [], WordPressTestState::$deleted_posts );
		$this->assertArrayHasKey( $post_id, WordPressTestState::$posts );
		$this->assertArrayNotHasKey( $post_id, WordPressTestState::$object_terms );
	}

	public function test_materialization_requires_the_origin_metadata_side_effect(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		add_action(
			'wp_after_insert_post',
			static function ( int $post_id ): void {
				unset( WordPressTestState::$post_meta[ $post_id ]['origin'] );
			},
			10,
			1
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertSame( [], WordPressTestState::$deleted_posts );
		$this->assertArrayHasKey( $post_id, WordPressTestState::$posts );
		$this->assertArrayNotHasKey( 'origin', WordPressTestState::$post_meta[ $post_id ] ?? [] );
	}

	public function test_materialization_blocks_a_captured_wrong_site_postmeta_table_after_context_restore(): void {
		global $wpdb;

		$original_postmeta = (string) $wpdb->postmeta;
		$wrong_table       = 'wp_2_postmeta';
		$expected_post_id  = 5000;
		$wrong_before      = 'wrong-site-origin';
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		$redirect = static function ( int $post_id, array $terms, array $tt_ids, string $taxonomy ) use ( $wpdb, $wrong_table, $expected_post_id, $wrong_before ): void {
			unset( $post_id, $terms, $tt_ids );

			if ( 'wp_theme' !== $taxonomy ) {
				return;
			}

			$wpdb->postmeta                                = $wrong_table;
			WordPressTestState::$db_tables[ $wrong_table ] = [
				[
					'meta_id'    => 7001,
					'post_id'    => $expected_post_id,
					'meta_key'   => 'origin',
					'meta_value' => $wrong_before,
				],
			];
		};
		$restore  = static function ( mixed $check ) use ( $wpdb, $original_postmeta ): mixed {
			$wpdb->postmeta = $original_postmeta;

			return $check;
		};
		add_action( 'set_object_terms', $redirect, 10, 4 );
		add_filter( 'update_post_metadata', $restore, 10, 1 );

		try {
			$result = TemplateApplyExecutor::execute(
				$this->entry(
					[
						[
							'type'        => 'insert_pattern',
							'patternName' => 'tt5/hero',
							'placement'   => 'start',
						],
					]
				)
			);
		} finally {
			$wpdb->postmeta = $original_postmeta;
			remove_action( 'set_object_terms', $redirect, 10 );
			remove_filter( 'update_post_metadata', $restore, 10 );
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( $wrong_before, WordPressTestState::$db_tables[ $wrong_table ][0]['meta_value'] ?? null );
		$this->assertArrayNotHasKey( 'origin', WordPressTestState::$post_meta[ $expected_post_id ] ?? [] );
	}

	public function test_materialization_ignores_filter_spoofing_and_requires_exact_raw_side_effects(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		add_action(
			'wp_after_insert_post',
			static function ( int $post_id ): void {
				WordPressTestState::$object_terms[ $post_id ]['wp_theme'] = [ 'twentytwentyfive', 'spoofed-theme' ];
				WordPressTestState::$post_meta[ $post_id ]['origin']      = 'spoofed-origin';
			},
			10,
			1
		);
		add_filter(
			'get_object_terms',
			static fn(): array => [ 'twentytwentyfive' ]
		);
		add_filter(
			'get_post_metadata',
			static fn(): string => 'theme'
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertSame( [ 'twentytwentyfive', 'spoofed-theme' ], WordPressTestState::$object_terms[ $post_id ]['wp_theme'] );
		$this->assertSame( 'spoofed-origin', WordPressTestState::$post_meta[ $post_id ]['origin'] );
	}

	public function test_materialization_identity_failure_never_attempts_automatic_deletion(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$skip_insert_taxonomy_assignment = true;
		WordPressTestState::$delete_post_short_circuits      = true;

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertSame( [], WordPressTestState::$deleted_posts );
		$this->assertArrayHasKey( $post_id, WordPressTestState::$posts );
	}

	public function test_same_content_materialization_race_updates_existing_row_in_place(): void {
		$content = $this->paragraph( 'Body' );
		$this->seed_template( $content, 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		WordPressTestState::$block_templates_read_hook = function () use ( $content ): void {
			$this->seed_template( $content, 9200 );
		};

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], WordPressTestState::$inserted_posts, 'A same-content materialization must not insert a duplicate row.' );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( 9200, WordPressTestState::$updated_posts[0]['ID'] );
		$this->assertStringContainsString( 'Hero', (string) WordPressTestState::$posts[9200]->post_content );
	}

	public function test_materialization_reconcile_requires_origin_before_updating_the_existing_row(): void {
		$content = $this->paragraph( 'Body' );
		$this->seed_template( $content, 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		WordPressTestState::$before_block_templates_query = static function ( array $query, string $template_type ) use ( $content ): void {
			if ( 'wp_template' !== $template_type || empty( $query['slug__in'] ) ) {
				return;
			}

			WordPressTestState::$before_block_templates_query     = null;
			WordPressTestState::$block_templates['wp_template'][] = (object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => 9230,
				'slug'    => 'home',
				'title'   => 'Home',
				'source'  => 'theme',
				'content' => $content,
			];
			WordPressTestState::$posts[9230]                      = new \WP_Post(
				[
					'ID'           => 9230,
					'post_type'    => 'wp_template',
					'post_name'    => 'home',
					'post_content' => $content,
				]
			);
			WordPressTestState::$object_terms[9230]['wp_theme']   = [ 'twentytwentyfive' ];
		};

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( $content, WordPressTestState::$posts[9230]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertArrayNotHasKey( 'origin', WordPressTestState::$post_meta[9230] ?? [] );
	}

	/**
	 * Directly exercises persist()'s duplicate-row guard, which the race test
	 * above does NOT reach: there the read-hook re-seeds wp_id>0 on execute()'s
	 * first store read, so the concurrency gate already returns a wp_id>0 entity
	 * and persist() takes the in-place wp_update_post branch before the slug__in
	 * guard. Here the theme-file template resolves with wp_id=0 through BOTH
	 * execute() reads (resolve + concurrency gate) — its id matches the ref — while
	 * a concurrent actor has materialized a SEPARATE wp_template row for the same
	 * slug+theme (a distinct id, wp_id>0) that only the persist() slug__in query
	 * surfaces. The guard must update that row in place; deleting it would insert
	 * a duplicate and fail the inserted_posts assertion.
	 */
	public function test_persist_duplicate_row_guard_updates_concurrent_materialization_in_place(): void {
		$content = $this->paragraph( 'Body' );

		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => 0,
				'slug'    => 'home',
				'title'   => 'Home',
				'source'  => 'theme',
				'content' => $content,
			],
		];
		WordPressTestState::$before_block_templates_query   = static function ( array $query, string $template_type ) use ( $content ): void {
			if ( 'wp_template' !== $template_type || empty( $query['slug__in'] ) ) {
				return;
			}

			WordPressTestState::$before_block_templates_query     = null;
			WordPressTestState::$block_templates['wp_template'][] = (object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => 9200,
				'slug'    => 'home',
				'title'   => 'Home',
				'source'  => 'theme',
				'content' => $content,
			];
			WordPressTestState::$posts[9200]                      = new \WP_Post(
				[
					'ID'           => 9200,
					'post_type'    => 'wp_template',
					'post_name'    => 'home',
					'post_content' => $content,
				]
			);
			WordPressTestState::$object_terms[9200]['wp_theme']   = [ 'twentytwentyfive' ];
			WordPressTestState::$post_meta[9200]['origin']        = 'theme';
		};
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], WordPressTestState::$inserted_posts, 'The duplicate-row guard must update the concurrently materialized row, not insert a second wp_template.' );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( 9200, WordPressTestState::$updated_posts[0]['ID'] );
		$this->assertStringContainsString( 'Hero', (string) WordPressTestState::$posts[9200]->post_content );
	}

	public function test_persist_duplicate_row_guard_finds_the_canonical_row_after_an_unrelated_candidate(): void {
		$content = $this->paragraph( 'Body' );

		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => 'plugin//home',
				'wp_id'   => 9219,
				'slug'    => 'home',
				'title'   => 'Plugin Home',
				'content' => $this->paragraph( 'Unrelated' ),
			],
			(object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => 0,
				'slug'    => 'home',
				'title'   => 'Home',
				'source'  => 'theme',
				'content' => $content,
			],
		];
		WordPressTestState::$before_block_templates_query   = static function ( array $query, string $template_type ) use ( $content ): void {
			if ( 'wp_template' !== $template_type || empty( $query['slug__in'] ) ) {
				return;
			}

			WordPressTestState::$before_block_templates_query     = null;
			WordPressTestState::$block_templates['wp_template'][] = (object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => 9220,
				'slug'    => 'home',
				'title'   => 'Home',
				'source'  => 'theme',
				'content' => $content,
			];
			WordPressTestState::$posts[9220]                      = new \WP_Post(
				[
					'ID'           => 9220,
					'post_type'    => 'wp_template',
					'post_name'    => 'home',
					'post_content' => $content,
				]
			);
			WordPressTestState::$object_terms[9220]['wp_theme']   = [ 'twentytwentyfive' ];
			WordPressTestState::$post_meta[9220]['origin']        = 'theme';
		};
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( 9220, WordPressTestState::$updated_posts[0]['ID'] );
		$this->assertStringContainsString( 'Hero', (string) WordPressTestState::$posts[9220]->post_content );
	}

	public function test_post_write_identity_race_leaves_both_rows_for_reconciliation(): void {
		$content = $this->paragraph( 'Body' );
		$this->seed_template( $content, 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		add_action(
			'wp_after_insert_post',
			static function ( int $post_id, \WP_Post $post ) use ( $content ): void {
				if ( 'wp_template' !== $post->post_type ) {
					return;
				}

				$winner_id                               = $post_id + 1;
				WordPressTestState::$posts[ $winner_id ] = new \WP_Post(
					[
						'ID'           => $winner_id,
						'post_type'    => 'wp_template',
						'post_status'  => 'publish',
						'post_name'    => 'home',
						'post_title'   => 'Home',
						'post_content' => $content,
					]
				);
				WordPressTestState::$object_terms[ $winner_id ]['wp_theme'] = [ 'twentytwentyfive' ];
				WordPressTestState::$block_templates['wp_template'][]       = (object) [
					'id'      => self::TEMPLATE_REF,
					'wp_id'   => $winner_id,
					'slug'    => 'home',
					'title'   => 'Home',
					'content' => $content,
				];
			},
			10,
			2
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$inserted_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$winner_id   = $inserted_id + 1;
		$this->assertSame( [], WordPressTestState::$deleted_posts );
		$this->assertArrayHasKey( $inserted_id, WordPressTestState::$posts );
		$this->assertArrayHasKey( $winner_id, WordPressTestState::$posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertStringNotContainsString( 'Hero', (string) WordPressTestState::$posts[ $winner_id ]->post_content );
	}

	public function test_persist_duplicate_row_guard_rejects_a_same_slug_different_template_id_before_content_or_writes(): void {
		$content       = $this->paragraph( 'Body' );
		$content_reads = 0;
		$candidate     = new class( $content, $content_reads ) {
			public string $id   = 'twentytwentyfive//home-concurrent';
			public int $wp_id   = 9209;
			public string $slug = 'home';
			private string $stored_content;
			private $content_reads;

			public function __construct( string $stored_content, int &$content_reads ) {
				$this->stored_content = $stored_content;
				$this->content_reads  =& $content_reads;
			}

			public function __isset( string $name ): bool {
				return 'content' === $name;
			}

			public function __get( string $name ): mixed {
				if ( 'content' === $name ) {
					++$this->content_reads;
					return $this->stored_content;
				}

				return null;
			}
		};
		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => 0,
				'slug'    => 'home',
				'title'   => 'Home',
				'source'  => 'theme',
				'content' => $content,
			],
			$candidate,
		];
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
		$this->assertSame( 0, $content_reads );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	/**
	 * The duplicate-row guard must not blind-overwrite a concurrent
	 * materialization. When the row another actor created already holds our
	 * desired content, accept it idempotently and write nothing.
	 */
	public function test_persist_duplicate_row_guard_accepts_an_already_desired_state_without_rewriting(): void {
		$content         = $this->paragraph( 'Body' );
		$pattern_content = $this->paragraph( 'Hero' );
		$desired         = serialize_blocks(
			array_merge(
				parse_blocks( $pattern_content ),
				parse_blocks( $content )
			)
		);

		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => 0,
				'slug'    => 'home',
				'title'   => 'Home',
				'source'  => 'theme',
				'content' => $content,
			],
		];
		WordPressTestState::$before_block_templates_query   = static function ( array $query, string $template_type ) use ( $desired ): void {
			if ( 'wp_template' !== $template_type || empty( $query['slug__in'] ) ) {
				return;
			}

			WordPressTestState::$before_block_templates_query     = null;
			WordPressTestState::$block_templates['wp_template'][] = (object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => 9210,
				'slug'    => 'home',
				'title'   => 'Home',
				'source'  => 'theme',
				'content' => $desired,
			];
			WordPressTestState::$posts[9210]                      = new \WP_Post(
				[
					'ID'           => 9210,
					'post_type'    => 'wp_template',
					'post_name'    => 'home',
					'post_content' => $desired,
				]
			);
			WordPressTestState::$object_terms[9210]['wp_theme']   = [ 'twentytwentyfive' ];
			WordPressTestState::$post_meta[9210]['origin']        = 'theme';
		};
		$this->register_pattern( 'tt5/hero', $pattern_content );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $desired, $result['after']['content'] );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'An already-desired concurrent row must be accepted without a rewrite.' );
	}

	/**
	 * When the concurrently materialized row holds content that is neither our
	 * target nor the baseline we validated against, the write must fail closed
	 * rather than silently overwriting the other actor's content. The template
	 * lane is Ring III attested, so a silent overwrite would leave the activity
	 * row and its attestation asserting content the site no longer has.
	 */
	public function test_persist_duplicate_row_guard_rejects_divergent_concurrent_content(): void {
		$content    = $this->paragraph( 'Body' );
		$concurrent = $this->paragraph( 'Concurrent edit' );

		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => 0,
				'slug'    => 'home',
				'title'   => 'Home',
				'content' => $content,
			],
			(object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => 9211,
				'slug'    => 'home',
				'title'   => 'Home',
				'content' => $concurrent,
			],
		];
		WordPressTestState::$posts[9211]                    = new \WP_Post(
			[
				'ID'           => 9211,
				'post_type'    => 'wp_template',
				'post_name'    => 'home',
				'post_content' => $concurrent,
			]
		);
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $concurrent, (string) WordPressTestState::$posts[9211]->post_content, 'The concurrent actor\'s content must survive untouched.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	/**
	 * A suffixed slug means something already owns slug+theme. When no published
	 * row owns it, Flavor Agent cannot prove who owns the suffixed row. It leaves
	 * the row unchanged and reports recovery-required for operator inspection.
	 */
	public function test_materialization_slug_conflict_leaves_the_row_for_reconciliation(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 'wp_template' === ( $data['post_type'] ?? '' ) ) {
					$data['post_name'] = 'home-2';
				}

				return $data;
			},
			10,
			4
		);

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$deleted_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertArrayHasKey( $post_id, WordPressTestState::$posts );
		$this->assertSame( 'home-2', WordPressTestState::$posts[ $post_id ]->post_name );
	}

	public function test_undo_restores_the_before_snapshot(): void {
		$before = $this->paragraph( 'Body' );
		$after  = $this->paragraph( 'Hero' ) . $before;
		$this->seed_template( $after, 9300 );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( $before, $result['after']['content'] );
		$this->assertSame( $before, WordPressTestState::$posts[9300]->post_content );
	}

	public function test_undo_returns_the_post_persist_content_after_a_save_filter_changes_it(): void {
		$before = $this->paragraph( 'Body' );
		$after  = $this->paragraph( 'Hero' ) . $before;
		$this->seed_template( $after, 9304 );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( isset( $data['post_content'] ) ) {
					$data['post_content'] = str_replace( '>Body<', '>Body saved<', (string) $data['post_content'] );
				}

				return $data;
			},
			10,
			4
		);

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( WordPressTestState::$posts[9304]->post_content, $result['after']['content'] );
		$this->assertStringContainsString( '>Body saved<', $result['after']['content'] );
	}

	public function test_undo_fails_when_a_save_hook_moves_the_post_off_the_canonical_ref(): void {
		$before = $this->paragraph( 'Body' );
		$after  = $this->paragraph( 'Hero' ) . $before;
		$this->seed_template( $after, 9415 );
		WordPressTestState::$posts[9415]->post_name         = 'home';
		WordPressTestState::$object_terms[9415]['wp_theme'] = [ 'twentytwentyfive' ];
		add_filter(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ): array {
				if ( 9415 === (int) ( $postarr['ID'] ?? 0 ) ) {
					$data['post_name'] = 'home-moved';
					WordPressTestState::$block_templates['wp_template'][0]->id   = 'twentytwentyfive//home-moved';
					WordPressTestState::$block_templates['wp_template'][0]->slug = 'home-moved';
				}

				return $data;
			},
			10,
			2
		);

		$result = TemplateApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( 'home', WordPressTestState::$posts[9415]->post_name );
		$this->assertSame( $after, WordPressTestState::$posts[9415]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_undo_regates_live_content_after_acquiring_the_target_lock(): void {
		$before     = $this->paragraph( 'Body' );
		$after      = $this->paragraph( 'Hero' ) . $before;
		$concurrent = $after . $this->paragraph( 'Concurrent edit' );
		$this->seed_template( $after, 9412 );
		WordPressTestState::$before_materialization_lock_insert = function () use ( $concurrent ): void {
			$this->seed_template( $concurrent, 9412 );
		};

		$result = TemplateApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $concurrent, WordPressTestState::$posts[9412]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_undo_captures_persisted_content_before_releasing_the_target_lock(): void {
		$before = $this->paragraph( 'Body' );
		$after  = $this->paragraph( 'Hero' ) . $before;
		$later  = $this->paragraph( 'Later writer' );
		$this->seed_template( $after, 9413 );
		WordPressTestState::$after_materialization_lock_delete = static function () use ( $later ): void {
			WordPressTestState::$posts[9413]->post_content                  = $later;
			WordPressTestState::$block_templates['wp_template'][0]->content = $later;
		};

		$result = TemplateApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( $before, $result['after']['content'] );
		$this->assertSame( $later, WordPressTestState::$posts[9413]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_undo_returns_already_undone_when_live_matches_before(): void {
		$before = $this->paragraph( 'Body' );
		$after  = $this->paragraph( 'Hero' ) . $before;
		$this->seed_template( $before, 9301 );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'already_undone', $result['result'] );
		$this->assertSame( $before, $result['after']['content'] );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_undo_fails_closed_on_drift_without_writing(): void {
		$this->seed_template( $this->paragraph( 'Edited elsewhere' ), 9302 );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::undo(
			self::executed_entry(
				$this->paragraph( 'Body' ),
				$this->paragraph( 'Hero' ) . $this->paragraph( 'Body' )
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_drift', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_executor_implements_the_contract_and_registry_routes_template(): void {
		$this->assertTrue(
			is_subclass_of( \FlavorAgent\Apply\TemplateApplyExecutor::class, \FlavorAgent\Apply\ExternalApplyExecutor::class )
		);
		$this->assertSame(
			\FlavorAgent\Apply\TemplateApplyExecutor::class,
			\FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'template' )
		);
	}

	/**
	 * A canonical template ref is a byte-exact authorization and attestation
	 * subject. If core would normalize its slug on insert, the write would land on
	 * a different ref, so materialization must fail before creating the row.
	 */
	public function test_materialization_rejects_a_slug_that_would_change_the_canonical_ref(): void {
		$this->seed_template( $this->paragraph( 'Anchor' ), 0, 'page--wide' );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$deleted_posts );
	}

	/**
	 * target.* must describe the entity the write actually landed on, because it
	 * is what lands in the activity row and the Ring III attestation subject. The
	 * gate-2 re-resolve is the authority, not the start-of-execute read.
	 * Discriminating: reverting target to $template-> reports the pre-gate slug
	 * and title, and both assertions below fail.
	 */
	public function test_execute_reports_identity_from_the_regated_entity(): void {
		$content = $this->paragraph( 'Body' );
		$this->seed_template( $content, 9200 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		WordPressTestState::$block_templates_read_hook = static function () use ( $content ): void {
			WordPressTestState::$block_templates['wp_template'] = [
				(object) [
					'id'      => self::TEMPLATE_REF,
					'wp_id'   => 9200,
					'slug'    => 'home-renamed',
					'title'   => 'Home Renamed',
					'content' => $content,
				],
			];
		};

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'home-renamed', $result['target']['slug'] );
		$this->assertSame( 'Home Renamed', $result['target']['title'] );
	}

	/**
	 * A failed post-insert read-back is not a slug collision. Falling into the
	 * collision arm would delete a row that was almost certainly written
	 * correctly and report a cause known to be false, so the executor must fail
	 * closed and LEAVE the row for an operator to reconcile.
	 */
	public function test_materialization_read_back_failure_leaves_the_row_and_reports_accurately(): void {
		$this->seed_template( $this->paragraph( 'Anchor' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 'wp_template' === ( $data['post_type'] ?? '' ) ) {
					WordPressTestState::$next_raw_post_row_returns_null = true;
				}

				return $data;
			},
			10,
			4
		);

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$deleted_posts, 'A read-back failure must not delete the row.' );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
	}

	public function test_materialization_exact_identity_read_failure_leaves_the_row_for_reconciliation(): void {
		$this->seed_template( $this->paragraph( 'Anchor' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		add_action(
			'wp_after_insert_post',
			static function ( int $post_id, \WP_Post $post ): void {
				unset( $post_id );

				if ( 'wp_template' === $post->post_type ) {
					WordPressTestState::$next_get_block_template_returns_null = true;
				}
			},
			10,
			2
		);

		$result = TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$deleted_posts );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertArrayHasKey( $post_id, WordPressTestState::$posts );
	}

	/**
	 * Even when deletion is filterable, an uncertain suffixed row is never sent
	 * through wp_delete_post. The operator receives recovery-required instead.
	 */
	public function test_materialization_slug_conflict_does_not_attempt_filterable_deletion(): void {
		$this->seed_template( $this->paragraph( 'Anchor' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		WordPressTestState::$delete_post_short_circuits = true;

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 'wp_template' === ( $data['post_type'] ?? '' ) ) {
					$data['post_name'] = 'home-2';
				}

				return $data;
			},
			10,
			4
		);

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$deleted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertArrayHasKey( $post_id, WordPressTestState::$posts );
	}

	/**
	 * Even when a concurrent winner becomes visible after insertion, Flavor Agent
	 * cannot prove that deleting the suffixed row is safe. Both rows are left for
	 * operator reconciliation and the winner is never overwritten.
	 */
	public function test_materialization_slug_race_leaves_both_rows_for_reconciliation(): void {
		$content = $this->paragraph( 'Anchor' );
		$this->seed_template( $content, 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		// Simulate the winner committing inside our insert: force the suffix and
		// publish the concurrent row in the same step, so only the post-insert
		// re-probe can see it.
		add_filter(
			'wp_insert_post_data',
			static function ( array $data, array $postarr, array $unsanitized_postarr, bool $update ) use ( $content ): array {
				unset( $postarr, $unsanitized_postarr );

				if ( $update || 'wp_template' !== ( $data['post_type'] ?? '' ) ) {
					return $data;
				}

				$data['post_name'] = 'home-2';

				WordPressTestState::$block_templates['wp_template'][] = (object) [
					'id'      => self::TEMPLATE_REF,
					'wp_id'   => 9300,
					'slug'    => 'home',
					'title'   => 'Home',
					'content' => $content,
				];

				WordPressTestState::$posts[9300]                    = new \WP_Post(
					[
						'ID'           => 9300,
						'post_type'    => 'wp_template',
						'post_name'    => 'home',
						'post_content' => $content,
					]
				);
				WordPressTestState::$object_terms[9300]['wp_theme'] = [ 'twentytwentyfive' ];

				return $data;
			},
			10,
			4
		);

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$deleted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertArrayHasKey( $post_id, WordPressTestState::$posts );
		$this->assertArrayHasKey( 9300, WordPressTestState::$posts );
		$this->assertStringNotContainsString( 'Hero', (string) WordPressTestState::$posts[9300]->post_content );
	}
}
