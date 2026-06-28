<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Apply\TemplatePartApplyExecutor;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class TemplatePartApplyExecutorTest extends TestCase {

	private const PART_ID = 'twentytwentyfive//header';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$active_theme = [ 'stylesheet' => 'twentytwentyfive' ];
	}

	/**
	 * Seed the live part into the get_block_template(s) stub store so the bound
	 * TemplateRepository::resolve_template_part resolves it. When $wp_id > 0 also
	 * seed a wp_template_part post as the wp_update_post write target. No filter
	 * seam: the executor re-collects + persists through the real stubbed WP APIs.
	 */
	private function seed_part( string $content, int $wp_id = 0, string $area = '', string $slug = 'header' ): void {
		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => self::PART_ID,
				'wp_id'   => $wp_id,
				'slug'    => $slug,
				'area'    => $area,
				'title'   => 'Header',
				'content' => $content,
			],
		];

		if ( $wp_id > 0 ) {
			WordPressTestState::$posts[ $wp_id ] = new \WP_Post(
				[
					'ID'           => $wp_id,
					'post_type'    => 'wp_template_part',
					'post_content' => $content,
				]
			);
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<string, mixed>
	 */
	private function entry( array $operations ): array {
		return [
			'surface' => 'template-part',
			'target'  => [ 'templatePartId' => self::PART_ID ],
			'apply'   => [ 'operations' => $operations ],
		];
	}

	/**
	 * The hydrated activity entry an executed external template-part apply leaves
	 * behind: the surface, the part target, and the before/after content snapshots
	 * that undo() drift-checks against the live part.
	 *
	 * @return array<string, mixed>
	 */
	private static function executed_entry( string $before, string $after ): array {
		return [
			'surface' => 'template-part',
			'target'  => [ 'templatePartId' => self::PART_ID ],
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

	/**
	 * Reflection seam onto the private R1 three-phase apply pipeline so the
	 * ordering + fail-closed guards can be proven without the collector/validator
	 * rebuilding the operations.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function apply_ops( array $blocks, array $operations ): array|\WP_Error {
		$method = new \ReflectionMethod( TemplatePartApplyExecutor::class, 'apply_operations' );
		$method->setAccessible( true );

		return $method->invoke( null, $blocks, $operations );
	}

	private static function assert_before( string $needle, string $first, string $second, string $haystack, string $message ): void {
		$a = strpos( $haystack, $first );
		$b = strpos( $haystack, $second );
		self::assertNotFalse( $a, $message . ' (missing ' . $first . ')' );
		self::assertNotFalse( $b, $message . ' (missing ' . $second . ')' );
		self::assertLessThan( $b, $a, $message );
	}

	// ---------------------------------------------------------------------
	// resolve_baseline (Task 4) — unchanged contract.
	// ---------------------------------------------------------------------

	public function test_resolve_baseline_hashes_reserialized_content(): void {
		$content = '<!-- wp:navigation /-->';
		$this->seed_part( $content );

		$hash = TemplatePartApplyExecutor::resolve_baseline( $this->entry( [] ) );

		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
			$hash
		);
	}

	public function test_resolve_baseline_hashes_an_available_but_empty_part(): void {
		$this->seed_part( '' );

		$hash = TemplatePartApplyExecutor::resolve_baseline( $this->entry( [] ) );

		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( '' ) ) ),
			$hash
		);
	}

	public function test_resolve_baseline_errors_when_part_missing(): void {
		$result = TemplatePartApplyExecutor::resolve_baseline(
			[
				'surface' => 'template-part',
				'target'  => [ 'templatePartId' => 'no//such' ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	public function test_resolve_baseline_fails_closed_on_missing_identifier(): void {
		$result = TemplatePartApplyExecutor::resolve_baseline( [ 'surface' => 'template-part' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	// ---------------------------------------------------------------------
	// execute() — happy paths (writes through the real stubbed WP APIs).
	// ---------------------------------------------------------------------

	public function test_execute_removes_nested_block_and_snapshots_before_after(): void {
		$content = '<!-- wp:group -->'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. $this->paragraph( 'Body' )
			. '<!-- /wp:group -->';
		$this->seed_part( $content, 4321, 'header' );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 0 ],
						'expectedBlockName' => 'core/heading',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::PART_ID, $result['target']['templatePartId'] );
		$this->assertSame( self::PART_ID, $result['target']['templatePartRef'] );
		$this->assertSame( $content, $result['before']['content'] );
		$this->assertStringNotContainsString( 'wp:heading', $result['after']['content'] );
		$this->assertStringContainsString( 'Body', $result['after']['content'] );
		$this->assertSame(
			$result['after']['content'],
			(string) WordPressTestState::$posts[4321]->post_content,
			'The persisted post_content must equal the mutated after snapshot.'
		);
		$this->assertStringNotContainsString( 'wp:heading', (string) WordPressTestState::$posts[4321]->post_content );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
	}

	public function test_execute_replaces_block_with_pattern(): void {
		$content = $this->paragraph( 'KeepMe' ) . $this->paragraph( 'ReplaceMe' );
		$this->seed_part( $content, 4322 );
		$this->register_pattern( 'fa-test/card', '<!-- wp:group -->' . $this->paragraph( 'CardBody' ) . '<!-- /wp:group -->' );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'replace_block_with_pattern',
						'patternName'       => 'fa-test/card',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$persisted = (string) WordPressTestState::$posts[4322]->post_content;
		$this->assertStringContainsString( 'KeepMe', $persisted );
		$this->assertStringContainsString( 'CardBody', $persisted );
		$this->assertStringNotContainsString( 'ReplaceMe', $persisted );
		$this->assertSame( $result['after']['content'], $persisted );
	}

	public function test_execute_inserts_pattern_before_anchor(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 4323 );
		$this->register_pattern( 'fa-test/intro', $this->paragraph( 'IntroPat' ) );

		TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/intro',
						'placement'   => 'before_block_path',
						'targetPath'  => [ 0 ],
					],
				]
			)
		);

		$persisted = (string) WordPressTestState::$posts[4323]->post_content;
		self::assert_before( 'order', 'IntroPat', 'Anchor', $persisted, 'before_block_path must land the pattern ahead of the anchor.' );
	}

	public function test_execute_inserts_pattern_after_anchor(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 4324 );
		$this->register_pattern( 'fa-test/outro', $this->paragraph( 'OutroPat' ) );

		TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/outro',
						'placement'   => 'after_block_path',
						'targetPath'  => [ 0 ],
					],
				]
			)
		);

		$persisted = (string) WordPressTestState::$posts[4324]->post_content;
		self::assert_before( 'order', 'Anchor', 'OutroPat', $persisted, 'after_block_path must land the pattern behind the anchor.' );
	}

	public function test_execute_inserts_pattern_at_start(): void {
		$this->seed_part( $this->paragraph( 'Existing' ), 4325 );
		$this->register_pattern( 'fa-test/lead', $this->paragraph( 'LeadPat' ) );

		TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/lead',
						'placement'   => 'start',
					],
				]
			)
		);

		$persisted = (string) WordPressTestState::$posts[4325]->post_content;
		self::assert_before( 'order', 'LeadPat', 'Existing', $persisted, 'start must prepend the pattern.' );
	}

	public function test_execute_inserts_pattern_at_end(): void {
		$this->seed_part( $this->paragraph( 'Existing' ), 4326 );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$persisted = (string) WordPressTestState::$posts[4326]->post_content;
		self::assert_before( 'order', 'Existing', 'TailPat', $persisted, 'end must append the pattern.' );
	}

	public function test_execute_applies_mixed_remove_and_insert_in_one_pass(): void {
		$content = $this->paragraph( 'AAA' ) . $this->paragraph( 'BBB' ) . $this->paragraph( 'CCC' );
		$this->seed_part( $content, 4327 );
		$this->register_pattern( 'fa-test/beta', $this->paragraph( 'BetaPat' ) );

		TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/paragraph',
					],
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/beta',
						'placement'   => 'after_block_path',
						'targetPath'  => [ 2 ],
					],
				]
			)
		);

		$persisted = (string) WordPressTestState::$posts[4327]->post_content;
		$this->assertStringNotContainsString( 'AAA', $persisted, 'remove [0] must drop the first paragraph.' );
		self::assert_before( 'order', 'BBB', 'CCC', $persisted, 'survivors keep their order.' );
		self::assert_before( 'order', 'CCC', 'BetaPat', $persisted, 'insert after [2] must land behind the frozen anchor.' );
	}

	public function test_execute_materializes_theme_file_part_and_invalidates_cache(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts, 'A theme-file part must be materialized via wp_insert_post.' );
		$inserted = WordPressTestState::$inserted_posts[0];
		$this->assertSame( 'wp_template_part', $inserted['post_type'] );
		$this->assertStringContainsString( 'TailPat', (string) $inserted['post_content'] );
		$this->assertSame( $result['after']['content'], (string) $inserted['post_content'] );
		$this->assertNotEmpty( WordPressTestState::$cleaned_post_caches, 'clean_post_cache must run after the write (R7).' );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'A theme-file part is inserted, never updated.' );
	}

	// ---------------------------------------------------------------------
	// execute() — fail closed, zero writes (atomicity).
	// ---------------------------------------------------------------------

	public function test_execute_fails_closed_on_block_name_mismatch_without_writing(): void {
		// The live block at [0] is a paragraph; the stored op lies that it is a heading.
		$this->seed_part( $this->paragraph( 'Body' ), 99 );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/heading',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'A re-validation failure must not write.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_fails_closed_on_unregistered_pattern_without_writing(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 99 );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/never-registered',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_returns_error_when_no_operations(): void {
		$this->seed_part( $this->paragraph( 'Body' ), 99 );

		$result = TemplatePartApplyExecutor::execute( $this->entry( [] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_fails_closed_when_part_changes_before_persist(): void {
		// Parity with StyleApplyExecutor's final unchanged gate: a concurrent Site
		// Editor / wp-cli save landing AFTER execute()'s initial read but BEFORE the
		// write must abort with zero writes, not silently clobber the live part.
		$group = '<!-- wp:group -->'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. $this->paragraph( 'Body' )
			. '<!-- /wp:group -->';
		$this->seed_part( $group, 7100, 'header' );

		// Append a sibling top-level block: the targeted remove [0,0] still
		// re-validates against the live tree, but the whole-part content hash moves.
		$changed                                       = $group . $this->paragraph( 'Concurrent edit' );
		WordPressTestState::$block_templates_read_hook = function () use ( $changed ): void {
			$this->seed_part( $changed, 7100, 'header' );
		};

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 0 ],
						'expectedBlockName' => 'core/heading',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'A pre-persist concurrent change must not write.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	// ---------------------------------------------------------------------
	// apply_operations() — R1 single descending-pass ordering + fail-closed
	// guards in isolation (no validator rebuild).
	// ---------------------------------------------------------------------

	public function test_apply_operations_multi_insert_lands_both_at_intended_gaps(): void {
		$blocks = parse_blocks( $this->paragraph( 'AAA' ) . $this->paragraph( 'BBB' ) . $this->paragraph( 'CCC' ) );
		$this->register_pattern( 'fa-test/alpha', $this->paragraph( 'AlphaPat' ) );
		$this->register_pattern( 'fa-test/beta', $this->paragraph( 'BetaPat' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/alpha',
					'placement'   => 'after_block_path',
					'targetPath'  => [ 0 ],
				],
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/beta',
					'placement'   => 'after_block_path',
					'targetPath'  => [ 2 ],
				],
			]
		);

		$this->assertIsArray( $result );
		$markup = serialize_blocks( $result );
		// Intended: AAA, AlphaPat, BBB, CCC, BetaPat — proves an earlier insert
		// never shifts a later op's frozen path (R1).
		self::assert_before( 'order', 'AAA', 'AlphaPat', $markup, 'alpha after [0]' );
		self::assert_before( 'order', 'AlphaPat', 'BBB', $markup, 'alpha sits in the [0] gap' );
		self::assert_before( 'order', 'CCC', 'BetaPat', $markup, 'beta after [2]' );
	}

	public function test_apply_operations_replace_then_insert_after_compose_correctly(): void {
		$blocks = parse_blocks(
			$this->paragraph( 'AAA' ) . $this->paragraph( 'BBB' ) . $this->paragraph( 'CCC' ) . $this->paragraph( 'DDD' )
		);
		$this->register_pattern( 'fa-test/twoblock', $this->paragraph( 'PPP' ) . $this->paragraph( 'QQQ' ) );
		$this->register_pattern( 'fa-test/beta', $this->paragraph( 'RRR' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'              => 'replace_block_with_pattern',
					'patternName'       => 'fa-test/twoblock',
					'targetPath'        => [ 1 ],
					'expectedBlockName' => 'core/paragraph',
					'expectedTarget'    => [
						'name'       => 'core/paragraph',
						'childCount' => 0,
					],
				],
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/beta',
					'placement'   => 'after_block_path',
					'targetPath'  => [ 3 ],
				],
			]
		);

		$this->assertIsArray( $result );
		$markup = serialize_blocks( $result );
		// Intended: AAA, PPP, QQQ, CCC, DDD, RRR — a 1->N replace before [3]
		// must not shift the frozen insert-after-[3] anchor (R1).
		$this->assertStringNotContainsString( 'BBB', $markup );
		self::assert_before( 'order', 'AAA', 'PPP', $markup, 'replace expands in place' );
		self::assert_before( 'order', 'QQQ', 'CCC', $markup, 'replacement precedes survivors' );
		self::assert_before( 'order', 'DDD', 'RRR', $markup, 'insert after [3] stays anchored to DDD' );
	}

	public function test_apply_operations_mixed_remove_and_insert_compose_correctly(): void {
		$blocks = parse_blocks( $this->paragraph( 'AAA' ) . $this->paragraph( 'BBB' ) . $this->paragraph( 'CCC' ) );
		$this->register_pattern( 'fa-test/beta', $this->paragraph( 'BetaPat' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/paragraph',
					'expectedTarget'    => [
						'name'       => 'core/paragraph',
						'childCount' => 0,
					],
				],
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/beta',
					'placement'   => 'after_block_path',
					'targetPath'  => [ 2 ],
				],
			]
		);

		$this->assertIsArray( $result );
		$markup = serialize_blocks( $result );
		$this->assertStringNotContainsString( 'AAA', $markup );
		self::assert_before( 'order', 'CCC', 'BetaPat', $markup, 'remove [0] must not shift the frozen insert-after-[2] anchor' );
	}

	public function test_apply_operations_fails_closed_on_child_count_drift(): void {
		$blocks = parse_blocks( '<!-- wp:group -->' . $this->paragraph( 'X' ) . '<!-- /wp:group -->' );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/group',
					'expectedTarget'    => [
						'name'       => 'core/group',
						'childCount' => 5,
					],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertStringContainsString( 'X', serialize_blocks( $blocks ), 'Phase 1 must abort before any mutation.' );
	}

	public function test_apply_operations_fails_closed_on_block_type_drift(): void {
		$blocks = parse_blocks( $this->paragraph( 'X' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/columns',
					'expectedTarget'    => [
						'name'       => 'core/columns',
						'childCount' => 0,
					],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
	}

	public function test_apply_operations_fails_closed_on_unresolved_pattern(): void {
		$blocks = parse_blocks( $this->paragraph( 'Anchor' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/missing',
					'placement'   => 'end',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_pattern_unavailable', $result->get_error_code() );
		$this->assertSame( $this->paragraph( 'Anchor' ), serialize_blocks( $blocks ), 'Phase 2 must abort before any mutation.' );
	}

	public function test_apply_operations_fails_closed_on_blockless_pattern_markup(): void {
		// A registered pattern whose markup carries no block delimiters resolves to
		// zero blocks after the freeform filter. Without the guard this would
		// silently degrade an insert into a delete; it must fail closed instead.
		$this->register_pattern( 'fa-test/blockless', 'Just plain prose, no block delimiters here.' );
		$blocks = parse_blocks( $this->paragraph( 'Anchor' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/blockless',
					'placement'   => 'end',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_pattern_unavailable', $result->get_error_code() );
		$this->assertSame( $this->paragraph( 'Anchor' ), serialize_blocks( $blocks ), 'Blockless pattern must abort before any mutation.' );
	}

	// ---------------------------------------------------------------------
	// undo() — drift-checked content restore (mirrors StyleApplyExecutor::undo).
	// Writes are captured through the same $posts/$updated_posts stub the
	// execute() tests use; there is NO filter seam (R5).
	// ---------------------------------------------------------------------

	public function test_undo_restores_before_content_when_live_matches_after(): void {
		$before = $this->paragraph( 'Original' );
		$after  = $this->paragraph( 'Changed' );
		// Live part == after: this is the row we just applied, untouched since.
		$this->seed_part( $after, 55 );

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame(
			serialize_blocks( parse_blocks( $before ) ),
			serialize_blocks( parse_blocks( (string) WordPressTestState::$posts[55]->post_content ) ),
			'undo must restore the before snapshot into the live part.'
		);
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_undo_fails_closed_on_drift_without_writing(): void {
		// Live part is neither before nor after: someone edited it after our apply.
		$this->seed_part( '<!-- wp:heading --><h2>Edited elsewhere</h2><!-- /wp:heading -->', 55 );

		$result = TemplatePartApplyExecutor::undo(
			self::executed_entry(
				$this->paragraph( 'Original' ),
				$this->paragraph( 'Changed' )
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_drift', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'A drift failure must not write.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_undo_is_idempotent_when_live_already_matches_before(): void {
		$before = $this->paragraph( 'Original' );
		$after  = $this->paragraph( 'Changed' );
		// Live part == before: already rolled back; undo must be a no-op.
		$this->seed_part( $before, 55 );

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'already_undone', $result['result'] );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'An already-undone row must not write.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_undo_snapshot_unsupported_when_content_missing(): void {
		// The part resolves fine, but the row lacks the after snapshot.
		$this->seed_part( $this->paragraph( 'Live' ), 55 );

		$result = TemplatePartApplyExecutor::undo(
			[
				'surface' => 'template-part',
				'target'  => [ 'templatePartId' => self::PART_ID ],
				'before'  => [ 'content' => $this->paragraph( 'Original' ) ],
				// 'after' content intentionally omitted.
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_snapshot_unsupported', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	/**
	 * R7 round-trip freshness: a real execute() followed by undo() must compose.
	 *
	 * execute() persists the after-content and busts the post cache; undo() then
	 * resolves the live part FRESH. We model the cache-busted DB re-read by
	 * re-seeding the same block-template stub the resolver reads. Because the
	 * static ServerCollector/TemplateRepository instances persist across both
	 * calls, the only way undo() returns 'undone' (rather than mis-reading the
	 * stale pre-apply 'before' as live and returning 'already_undone') is if
	 * resolve_part genuinely re-reads the post-apply content — guarding against a
	 * false-positive drift caused by a stale resolution cache on the round-trip.
	 */
	public function test_undo_after_execute_reads_fresh_content_and_restores_before(): void {
		$wp_id  = 770;
		$before = $this->paragraph( 'Keep' ) . $this->paragraph( 'DropMe' );
		$this->seed_part( $before, $wp_id );

		$executed = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertIsArray( $executed );
		$after = (string) $executed['after']['content'];
		$this->assertStringNotContainsString( 'DropMe', $after );
		$this->assertSame(
			$after,
			(string) WordPressTestState::$posts[ $wp_id ]->post_content,
			'execute() must persist the after-content to the live post.'
		);
		$this->assertNotEmpty( WordPressTestState::$cleaned_post_caches, 'execute() must bust the post cache (R7).' );

		// Model the cache-busted DB re-read: the live part now resolves to the
		// persisted after-content through the same stub the resolver reads.
		$this->seed_part( (string) WordPressTestState::$posts[ $wp_id ]->post_content, $wp_id );

		$undo = TemplatePartApplyExecutor::undo(
			self::executed_entry( (string) $executed['before']['content'], $after )
		);

		$this->assertIsArray( $undo );
		$this->assertSame( 'undone', $undo['result'], 'A fresh resolve must see the after-content as live and undo cleanly.' );
		$this->assertSame(
			serialize_blocks( parse_blocks( $before ) ),
			serialize_blocks( parse_blocks( (string) WordPressTestState::$posts[ $wp_id ]->post_content ) ),
			'undo must restore the original before-content into the live part.'
		);
	}

	public function test_undo_fails_closed_when_part_changes_before_restore_write(): void {
		// Parity with StyleApplyExecutor::undo's pre-write unchanged gate: even after
		// the live == after drift check passes, a concurrent save landing before the
		// restore write must abort with zero writes.
		$before = $this->paragraph( 'Original' );
		$after  = $this->paragraph( 'Changed' );
		$this->seed_part( $after, 7200 );

		$changed                                       = $after . $this->paragraph( 'Concurrent edit' );
		WordPressTestState::$block_templates_read_hook = function () use ( $changed ): void {
			$this->seed_part( $changed, 7200 );
		};

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'A pre-restore concurrent change must not write.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}
}
