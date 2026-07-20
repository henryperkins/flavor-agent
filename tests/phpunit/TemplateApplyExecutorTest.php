<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

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
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => $wp_id,
				'slug'    => $slug,
				'title'   => 'Home',
				'content' => $content,
			],
		];

		if ( $wp_id > 0 ) {
			WordPressTestState::$posts[ $wp_id ] = new \WP_Post(
				[
					'ID'           => $wp_id,
					'post_type'    => 'wp_template',
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
			'surface' => 'template',
			'target'  => [
				'templateRef'  => self::TEMPLATE_REF,
				'templateType' => 'home',
			],
			'apply'   => [ 'operations' => $operations ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function executed_entry( string $before, string $after ): array {
		return [
			'surface' => 'template',
			'target'  => [
				'templateRef'  => self::TEMPLATE_REF,
				'templateType' => 'home',
			],
			'before'  => [ 'content' => $before ],
			'after'   => [ 'content' => $after ],
		];
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
		$this->assertSame( [ 'wp_theme' => [ 'twentytwentyfive' ] ], $inserted['tax_input'] );
		$this->assertArrayNotHasKey( 'wp_template_part_area', $inserted['tax_input'] );
		$this->assertStringContainsString( 'Hero', (string) $inserted['post_content'] );
		$this->assertNotEmpty( WordPressTestState::$cleaned_post_caches );
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
				'content' => $content,
			],
			(object) [
				'id'      => 'twentytwentyfive//home-concurrent',
				'wp_id'   => 9200,
				'slug'    => 'home',
				'title'   => 'Home',
				'content' => $content,
			],
		];
		WordPressTestState::$posts[9200]                    = new \WP_Post(
			[
				'ID'           => 9200,
				'post_type'    => 'wp_template',
				'post_content' => $content,
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

		$this->assertIsArray( $result );
		$this->assertSame( [], WordPressTestState::$inserted_posts, 'The duplicate-row guard must update the concurrently materialized row, not insert a second wp_template.' );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( 9200, WordPressTestState::$updated_posts[0]['ID'] );
		$this->assertStringContainsString( 'Hero', (string) WordPressTestState::$posts[9200]->post_content );
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
				'content' => $content,
			],
			(object) [
				'id'      => 'twentytwentyfive//home-concurrent',
				'wp_id'   => 9210,
				'slug'    => 'home',
				'title'   => 'Home',
				'content' => $desired,
			],
		];
		WordPressTestState::$posts[9210]                    = new \WP_Post(
			[
				'ID'           => 9210,
				'post_type'    => 'wp_template',
				'post_name'    => 'home',
				'post_content' => $desired,
			]
		);
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
				'id'      => 'twentytwentyfive//home-concurrent',
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
	 * row owns it, the collision is NOT a concurrent materialization (core's
	 * uniquifier also counts private rows, which the publish-only probe cannot
	 * see), so the orphan is removed and the failure is reported as a slug
	 * conflict rather than as phantom concurrency.
	 */
	public function test_materialization_slug_conflict_removes_the_orphan_and_reports_accurately(): void {
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
		$this->assertSame( 'flavor_agent_apply_slug_conflict', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$this->assertCount( 1, WordPressTestState::$deleted_posts, 'The suffixed orphan row must be removed.' );
		$this->assertArrayNotHasKey( WordPressTestState::$deleted_posts[0], WordPressTestState::$posts );
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
	 * Core writes post_name through sanitize_title(), which collapses repeated
	 * dashes and trims edge dashes; sanitize_key() does neither. Comparing the
	 * two normalizers read a legitimate slug as a collision, force-deleted the
	 * correctly written row, and failed the apply with a slug conflict that does
	 * not exist -- permanently, because reconcile_existing_row() then probes a
	 * slug that is not what got stored. Discriminating: reverting persist() to
	 * sanitize_key() makes this fail with flavor_agent_apply_slug_conflict.
	 */
	public function test_materialization_accepts_a_slug_core_renormalizes(): void {
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

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$deleted_posts, 'A renormalized slug is not a collision and must not delete the row.' );
		$this->assertSame( 'page-wide', (string) WordPressTestState::$inserted_posts[0]['post_name'] );
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
					WordPressTestState::$next_get_post_returns_null = true;
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
		$this->assertSame( 'flavor_agent_apply_post_write_read_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$deleted_posts, 'A read-back failure must not delete the row.' );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
	}

	/**
	 * wp_delete_post can return a WP_Post while a pre_delete_post filter
	 * short-circuits the actual deletion. Trusting the return value would strand
	 * the duplicate row AND then update the winning row too, so the executor
	 * must confirm the row is gone and fail closed when it is not.
	 */
	public function test_materialization_slug_conflict_fails_closed_when_the_orphan_cannot_be_removed(): void {
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
		$this->assertSame( 'flavor_agent_apply_write_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'Failing to remove the orphan must not also update a winning row.' );
	}

	/**
	 * When the slug suffix WAS caused by a genuine concurrent materialization,
	 * the winning row becomes visible only after our insert. The post-insert
	 * re-probe must drop our orphan and reconcile against the winner rather than
	 * failing the operator out on a race the guard can safely resolve.
	 */
	public function test_materialization_slug_race_reconciles_against_the_winning_row(): void {
		$content = $this->paragraph( 'Anchor' );
		$this->seed_template( $content, 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		// Simulate the winner committing inside our insert: force the suffix and
		// publish the concurrent row in the same step, so only the post-insert
		// re-probe can see it.
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ) use ( $content ): array {
				if ( 'wp_template' !== ( $data['post_type'] ?? '' ) ) {
					return $data;
				}

				$data['post_name'] = 'home-2';

				WordPressTestState::$block_templates['wp_template'][] = (object) [
					'id'      => 'twentytwentyfive//home-winner',
					'wp_id'   => 9300,
					'slug'    => 'home',
					'title'   => 'Home',
					'content' => $content,
				];

				WordPressTestState::$posts[9300] = new \WP_Post(
					[
						'ID'           => 9300,
						'post_type'    => 'wp_template',
						'post_content' => $content,
					]
				);

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
		$this->assertCount( 1, WordPressTestState::$deleted_posts, 'The suffixed orphan must be removed.' );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( 9300, WordPressTestState::$updated_posts[0]['ID'], 'The winning row must be updated in place.' );
	}
}
