<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Apply\PostBlocksApplyExecutor;
use FlavorAgent\Apply\StructuralOperationsApplier;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * Note: the stub harness's wp_update_post does not create revisions, so the
 * expected-revision behavior of a real WordPress runtime is environment-limited
 * here and covered by the persist write + cache-invalidation assertions instead.
 */
final class PostBlocksApplyExecutorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
	}

	private function seed_post( int $id, string $content, string $post_type = 'post', string $post_status = 'publish' ): void {
		WordPressTestState::$posts[ $id ] = new \WP_Post(
			[
				'ID'           => $id,
				'post_type'    => $post_type,
				'post_status'  => $post_status,
				'post_title'   => 'Doc ' . $id,
				'post_content' => $content,
			]
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<string, mixed>
	 */
	private function entry( int $post_id, array $operations ): array {
		return [
			'surface' => 'post-blocks',
			'target'  => [ 'postId' => $post_id ],
			'apply'   => [ 'operations' => $operations ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function executed_entry( int $post_id, string $before, string $after ): array {
		return [
			'surface' => 'post-blocks',
			'target'  => [ 'postId' => $post_id ],
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

	private function live_content( int $post_id ): string {
		return (string) WordPressTestState::$posts[ $post_id ]->post_content;
	}

	private static function assert_before( string $first, string $second, string $haystack, string $message ): void {
		$a = strpos( $haystack, $first );
		$b = strpos( $haystack, $second );
		self::assertNotFalse( $a, $message . ' (missing ' . $first . ')' );
		self::assertNotFalse( $b, $message . ' (missing ' . $second . ')' );
		self::assertLessThan( $b, $a, $message );
	}

	// ---------------------------------------------------------------------
	// resolve_baseline
	// ---------------------------------------------------------------------

	public function test_resolve_baseline_hashes_reserialized_content(): void {
		$content = $this->paragraph( 'Base' );
		$this->seed_post( 9300, $content );

		$hash = PostBlocksApplyExecutor::resolve_baseline( $this->entry( 9300, [] ) );

		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
			$hash
		);
	}

	public function test_resolve_baseline_fails_closed_for_missing_post(): void {
		$result = PostBlocksApplyExecutor::resolve_baseline( $this->entry( 424242, [] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	// ---------------------------------------------------------------------
	// execute — success matrix
	// ---------------------------------------------------------------------

	/**
	 * Pins the recorded after-snapshot to what wp_update_post actually stored.
	 *
	 * wp_filter_post_kses runs whenever the approving user lacks unfiltered_html
	 * — every non-super-admin on multisite, and everyone under
	 * DISALLOW_UNFILTERED_HTML — and it rewrites ordinary block markup (real
	 * wp_kses_post normalizes `<img ... />`). Recording the locally serialized
	 * string instead leaves the activity row describing content that is not on
	 * the site, and undo's hash_equals( live, after ) guard can then never match.
	 */
	public function test_execute_records_the_persisted_content_after_a_save_filter_changes_it(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9350, $content );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( isset( $data['post_content'] ) ) {
					$data['post_content'] = str_replace( '>Keep<', '>Keep saved<', (string) $data['post_content'] );
				}

				return $data;
			},
			10,
			4
		);

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9350,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			$this->live_content( 9350 ),
			$result['after']['content'],
			'The recorded after-snapshot must equal live content, or undo can never match it.'
		);
		$this->assertStringContainsString( 'Keep saved', $result['after']['content'] );
	}

	/**
	 * The consequence of the above: undo must still reverse a governed apply on a
	 * site whose save pipeline rewrites content. Before the read-back this
	 * returned flavor_agent_undo_drift permanently.
	 */
	public function test_undo_succeeds_when_a_save_filter_rewrote_the_applied_content(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9351, $content );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( isset( $data['post_content'] ) ) {
					$data['post_content'] = str_replace( '>Keep<', '>Keep saved<', (string) $data['post_content'] );
				}

				return $data;
			},
			10,
			4
		);

		$executed = PostBlocksApplyExecutor::execute(
			$this->entry(
				9351,
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

		$undone = PostBlocksApplyExecutor::undo(
			self::executed_entry(
				9351,
				(string) $executed['before']['content'],
				(string) $executed['after']['content']
			)
		);

		$this->assertIsArray( $undone, 'Undo must not report drift for a change the save pipeline itself made.' );
		$this->assertSame( 'undone', $undone['result'] ?? null );
	}

	public function test_execute_removes_a_block(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9301, $content );

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9301,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 9301, $result['target']['postId'] );
		$this->assertSame( 'post', $result['target']['postType'] );
		$this->assertSame( $content, $result['before']['content'] );
		$this->assertStringNotContainsString( 'Drop', $this->live_content( 9301 ) );
		$this->assertStringContainsString( 'Keep', $this->live_content( 9301 ) );
		$this->assertContains( 9301, WordPressTestState::$cleaned_post_caches );
	}

	public function test_execute_removes_a_nested_block(): void {
		$content = '<!-- wp:group --><div class="wp-block-group">'
			. $this->paragraph( 'Inner keep' )
			. $this->paragraph( 'Inner drop' )
			. '</div><!-- /wp:group -->';
		$this->seed_post( 9302, $content );

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9302,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( 'Inner drop', $this->live_content( 9302 ) );
		$this->assertStringContainsString( 'Inner keep', $this->live_content( 9302 ) );
	}

	public function test_execute_replaces_a_block_with_a_multi_block_pattern(): void {
		$this->register_pattern( 'demo/two-up', $this->paragraph( 'PatA' ) . $this->paragraph( 'PatB' ) );
		$content = $this->paragraph( 'Old' ) . $this->paragraph( 'Tail' );
		$this->seed_post( 9303, $content );

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9303,
				[
					[
						'type'              => 'replace_block_with_pattern',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/paragraph',
						'patternName'       => 'demo/two-up',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$live = $this->live_content( 9303 );
		$this->assertStringNotContainsString( 'Old', $live );
		self::assert_before( 'PatA', 'PatB', $live, 'Pattern blocks keep their order.' );
		self::assert_before( 'PatB', 'Tail', $live, 'Replacement lands ahead of the tail block.' );
	}

	public function test_execute_mixed_remove_and_insert_after_composes(): void {
		$this->register_pattern( 'demo/cta', $this->paragraph( 'CTA' ) );
		$content = $this->paragraph( 'First' ) . $this->paragraph( 'Second' ) . $this->paragraph( 'Third' );
		$this->seed_post( 9304, $content );

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9304,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/paragraph',
					],
					[
						'type'        => 'insert_pattern',
						'patternName' => 'demo/cta',
						'placement'   => 'after_block_path',
						'targetPath'  => [ 2 ],
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$live = $this->live_content( 9304 );
		$this->assertStringNotContainsString( 'First', $live );
		self::assert_before( 'Third', 'CTA', $live, 'Insert lands after the original [2] anchor.' );
	}

	public function test_execute_multi_insert_start_and_end(): void {
		$this->register_pattern( 'demo/intro', $this->paragraph( 'Intro' ) );
		$this->register_pattern( 'demo/outro', $this->paragraph( 'Outro' ) );
		$content = $this->paragraph( 'Body' );
		$this->seed_post( 9305, $content );

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9305,
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'demo/intro',
						'placement'   => 'start',
					],
					[
						'type'        => 'insert_pattern',
						'patternName' => 'demo/outro',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$live = $this->live_content( 9305 );
		self::assert_before( 'Intro', 'Body', $live, 'start inserts ahead of the body.' );
		self::assert_before( 'Body', 'Outro', $live, 'end appends behind the body.' );
	}

	// ---------------------------------------------------------------------
	// execute — fail closed, zero writes
	// ---------------------------------------------------------------------

	public function test_execute_rejects_unregistered_pattern_with_zero_writes(): void {
		$content = $this->paragraph( 'Body' );
		$this->seed_post( 9306, $content );

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9306,
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'demo/not-registered',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $content, $this->live_content( 9306 ) );
	}

	public function test_execute_rejects_locked_target_even_when_stored_request_contains_it(): void {
		$content = '<!-- wp:paragraph {"lock":{"remove":true,"move":false}} --><p>Pinned</p><!-- /wp:paragraph -->'
			. $this->paragraph( 'Free' );
		$this->seed_post( 9307, $content );

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9307,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$codes = array_column( (array) ( $result->get_error_data()['validationReasons'] ?? [] ), 'code' );
		$this->assertContains( 'target_locked', $codes );
		$this->assertSame( $content, $this->live_content( 9307 ) );
	}

	public function test_execute_enforces_the_request_time_expected_target(): void {
		$content = '<!-- wp:group -->'
			. $this->paragraph( 'Child' )
			. '<!-- /wp:group -->';
		$this->seed_post( 9308, $content );

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9308,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/group',
						'expectedTarget'    => [
							'name'       => 'core/group',
							'childCount' => 0,
						],
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $content, $this->live_content( 9308 ) );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_execute_rejects_wrong_post_type_with_zero_writes(): void {
		$content = $this->paragraph( 'Body' );
		$this->seed_post( 9308, $content, 'wp_template_part' );

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9308,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
		$this->assertSame( $content, $this->live_content( 9308 ) );
	}

	public function test_execute_rejects_trashed_post_with_zero_writes(): void {
		$content = $this->paragraph( 'Body' );
		$this->seed_post( 9309, $content, 'post', 'trash' );

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9309,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $content, $this->live_content( 9309 ) );
	}

	public function test_shared_applier_rejects_child_count_mismatch(): void {
		$blocks = parse_blocks(
			'<!-- wp:group --><div class="wp-block-group">' . $this->paragraph( 'Only child' ) . '</div><!-- /wp:group -->'
		);

		$result = StructuralOperationsApplier::apply_operations(
			$blocks,
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/group',
					'expectedTarget'    => [
						'name'       => 'core/group',
						'childCount' => 3,
					],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
	}

	public function test_shared_applier_rejects_block_name_mismatch(): void {
		$blocks = parse_blocks( $this->paragraph( 'Body' ) );

		$result = StructuralOperationsApplier::apply_operations(
			$blocks,
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/heading',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
	}

	// ---------------------------------------------------------------------
	// undo
	// ---------------------------------------------------------------------

	public function test_undo_restores_the_before_snapshot(): void {
		$before = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9310, $before );

		$executed = PostBlocksApplyExecutor::execute(
			$this->entry(
				9310,
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

		$undone = PostBlocksApplyExecutor::undo(
			self::executed_entry( 9310, $executed['before']['content'], $executed['after']['content'] )
		);

		$this->assertSame( [ 'result' => 'undone' ], $undone );
		$this->assertSame(
			serialize_blocks( parse_blocks( $before ) ),
			serialize_blocks( parse_blocks( $this->live_content( 9310 ) ) ),
			'Undo reads the fresh live content and restores the before snapshot.'
		);
	}

	public function test_undo_reports_already_undone_without_writing(): void {
		$before = $this->paragraph( 'Keep' );
		$after  = $this->paragraph( 'Keep' ) . $this->paragraph( 'CTA' );
		$this->seed_post( 9311, $before );

		$result = PostBlocksApplyExecutor::undo( self::executed_entry( 9311, $before, $after ) );

		$this->assertSame( [ 'result' => 'already_undone' ], $result );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_undo_fails_closed_on_drift(): void {
		$before  = $this->paragraph( 'Keep' );
		$after   = $this->paragraph( 'Keep' ) . $this->paragraph( 'CTA' );
		$drifted = $this->paragraph( 'Someone edited this since' );
		$this->seed_post( 9312, $drifted );

		$result = PostBlocksApplyExecutor::undo( self::executed_entry( 9312, $before, $after ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_drift', $result->get_error_code() );
		$this->assertSame( $drifted, $this->live_content( 9312 ) );
	}

	public function test_undo_requires_snapshots(): void {
		$this->seed_post( 9313, $this->paragraph( 'Body' ) );

		$result = PostBlocksApplyExecutor::undo(
			[
				'surface' => 'post-blocks',
				'target'  => [ 'postId' => 9313 ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_snapshot_unsupported', $result->get_error_code() );
	}

	// ---------------------------------------------------------------------
	// registry dispatch
	// ---------------------------------------------------------------------

	public function test_registry_dispatches_post_blocks_surface(): void {
		$this->assertSame(
			PostBlocksApplyExecutor::class,
			\FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'post-blocks' )
		);
	}
}
