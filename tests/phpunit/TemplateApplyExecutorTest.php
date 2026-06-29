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

	public function test_undo_restores_the_before_snapshot(): void {
		$before = $this->paragraph( 'Body' );
		$after  = $this->paragraph( 'Hero' ) . $before;
		$this->seed_template( $after, 9300 );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertSame( [ 'result' => 'undone' ], $result );
		$this->assertSame( $before, WordPressTestState::$posts[9300]->post_content );
	}

	public function test_undo_returns_already_undone_when_live_matches_before(): void {
		$before = $this->paragraph( 'Body' );
		$after  = $this->paragraph( 'Hero' ) . $before;
		$this->seed_template( $before, 9301 );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertSame( [ 'result' => 'already_undone' ], $result );
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
}
