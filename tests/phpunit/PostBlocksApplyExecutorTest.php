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
		WordPressTestState::$capabilities['edit_post'] = true;
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
	private function entry( int $post_id, array $operations, string $post_type = 'post' ): array {
		return [
			'surface'  => 'post-blocks',
			'target'   => [
				'postId'   => $post_id,
				'postType' => $post_type,
			],
			'document' => [
				'entityId' => (string) $post_id,
				'postType' => $post_type,
				'scopeKey' => $post_type . ':' . $post_id,
			],
			'apply'    => [ 'operations' => $operations ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function executed_entry( int $post_id, string $before, string $after, string $post_type = 'post' ): array {
		return [
			'surface'  => 'post-blocks',
			'target'   => [
				'postId'   => $post_id,
				'postType' => $post_type,
			],
			'document' => [
				'entityId' => (string) $post_id,
				'postType' => $post_type,
				'scopeKey' => $post_type . ':' . $post_id,
			],
			'before'   => [ 'content' => $before ],
			'after'    => [ 'content' => $after ],
		];
	}

	public function test_resolve_target_identity_binds_post_id_to_actual_post_type(): void {
		$this->seed_post( 9300, $this->paragraph( 'Body' ), 'page' );

		$identity = PostBlocksApplyExecutor::resolve_target_identity( $this->entry( 9300, [], 'page' ) );

		$this->assertSame(
			[
				'target'   => [
					'postId'   => 9300,
					'postType' => 'page',
				],
				'document' => [
					'entityId' => '9300',
					'postType' => 'page',
					'scopeKey' => 'page:9300',
				],
			],
			$identity
		);
		$this->assertSame( [ 9300 ], WordPressTestState::$get_post_type_calls );
		$this->assertSame( [], WordPressTestState::$get_post_calls );
	}

	public function test_authorize_target_rejects_a_document_for_one_post_and_target_for_another_even_when_both_are_editable(): void {
		$this->seed_post( 100, $this->paragraph( 'Allowed' ) );
		$this->seed_post( 200, $this->paragraph( 'Target' ) );
		$entry                         = $this->entry( 200, [] );
		$entry['document']['entityId'] = '100';
		$entry['document']['scopeKey'] = 'post:100';
		WordPressTestState::$capabilities['edit_post:100'] = true;
		WordPressTestState::$capabilities['edit_post:200'] = true;

		$result = PostBlocksApplyExecutor::authorize_target( $entry );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? null );
		$this->assertSame( [ 200 ], WordPressTestState::$get_post_type_calls );
		$this->assertSame( [], WordPressTestState::$get_post_calls );
		$this->assertSame( [], WordPressTestState::$capability_checks );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_authorize_target_rejects_a_stored_post_type_that_disagrees_with_actual_metadata_before_capability(): void {
		$this->seed_post( 200, $this->paragraph( 'Page' ), 'page' );

		$result = PostBlocksApplyExecutor::authorize_target( $this->entry( 200, [], 'post' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
		$this->assertSame( [ 200 ], WordPressTestState::$get_post_type_calls );
		$this->assertSame( [], WordPressTestState::$get_post_calls );
		$this->assertSame( [], WordPressTestState::$capability_checks );
	}

	public function test_authorize_target_requires_edit_post_on_the_canonical_target_without_collecting_content(): void {
		$this->seed_post( 200, $this->paragraph( 'Page' ), 'page' );
		WordPressTestState::$capabilities['edit_post:200'] = false;

		$result = PostBlocksApplyExecutor::authorize_target( $this->entry( 200, [], 'page' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
		$this->assertSame( [ 200 ], WordPressTestState::$get_post_type_calls );
		$this->assertSame( [], WordPressTestState::$get_post_calls );
		$this->assertSame(
			[
				[
					'capability' => 'edit_post',
					'args'       => [ 200 ],
				],
			],
			WordPressTestState::$capability_checks
		);
		$this->assertSame( [], WordPressTestState::$updated_posts );

		WordPressTestState::$capabilities['edit_post:200'] = true;
		$this->assertTrue( PostBlocksApplyExecutor::authorize_target( $this->entry( 200, [], 'page' ) ) );
	}

	public function test_execute_rechecks_edit_permission_at_the_final_write_boundary(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9360, $content );
		$entry = $this->entry(
			9360,
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 1 ],
					'expectedBlockName' => 'core/paragraph',
				],
			]
		);
		$this->assertTrue( PostBlocksApplyExecutor::authorize_target( $entry ) );
		WordPressTestState::$capabilities['edit_post:9360'] = false;

		$result = PostBlocksApplyExecutor::execute( $entry );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_forbidden', $result->get_error_code() );
		$this->assertSame( $content, $this->live_content( 9360 ) );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_authorize_target_rejects_missing_or_non_positive_post_identity(): void {
		$this->seed_post( 200, $this->paragraph( 'Post' ) );
		$missing_target_type = $this->entry( 200, [] );
		unset( $missing_target_type['target']['postType'] );
		$malformed_id                     = $this->entry( 200, [] );
		$malformed_id['target']['postId'] = '200junk';
		$missing_document_entity          = $this->entry( 200, [] );
		$missing_document_type            = $this->entry( 200, [] );
		$missing_document_scope           = $this->entry( 200, [] );
		$noncanonical_document_scope      = $this->entry( 200, [] );
		unset( $missing_document_entity['document']['entityId'] );
		unset( $missing_document_type['document']['postType'] );
		unset( $missing_document_scope['document']['scopeKey'] );
		$noncanonical_document_scope['document']['scopeKey'] = 'post:0200';
		$entries = [
			$this->entry( 0, [] ),
			$missing_target_type,
			$malformed_id,
			$missing_document_entity,
			$missing_document_type,
			$missing_document_scope,
			$noncanonical_document_scope,
		];

		foreach ( $entries as $entry ) {
			WordPressTestState::$get_post_type_calls = [];
			WordPressTestState::$capability_checks   = [];
			$result                                  = PostBlocksApplyExecutor::authorize_target( $entry );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
			$this->assertSame( [], WordPressTestState::$capability_checks );
		}
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
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
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
		$this->assertSame( 'Doc 9350', WordPressTestState::$posts[9350]->post_title );
	}

	/**
	 * @dataProvider invalid_existing_post_save_data
	 */
	public function test_execute_rejects_out_of_contract_save_data_before_the_posts_write(
		string $mutation,
		int $post_id
	): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( $post_id, $content );
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ) use ( $mutation ): array {
				if ( 'title' === $mutation ) {
					$data['post_title'] = 'Out-of-scope filtered title';
				} elseif ( 'missing' === $mutation ) {
					unset( $data['post_excerpt'] );
				} elseif ( 'unknown' === $mutation ) {
					$data['unexpected_db_field'] = 'not a Core posts-table field';
				} else {
					$data['post_modified'] = '1999-01-01 00:00:00';
				}

				return $data;
			},
			10,
			4
		);

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				$post_id,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $content, $this->live_content( $post_id ) );
		$this->assertSame( 'Doc ' . $post_id, WordPressTestState::$posts[ $post_id ]->post_title );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function invalid_existing_post_save_data(): array {
		return [
			'protected title'           => [ 'title', 9352 ],
			'missing standard field'    => [ 'missing', 9353 ],
			'unknown database field'    => [ 'unknown', 9354 ],
			'filtered modified instant' => [ 'modified', 9355 ],
		];
	}

	public function test_execute_preserves_core_modified_timestamp_updates(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9356, $content );
		WordPressTestState::$posts[9356]->post_modified     = '2020-01-02 03:04:05';
		WordPressTestState::$posts[9356]->post_modified_gmt = '2020-01-02 03:04:05';

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9356,
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
		$this->assertNotSame( '2020-01-02 03:04:05', WordPressTestState::$posts[9356]->post_modified );
		$this->assertNotSame( '2020-01-02 03:04:05', WordPressTestState::$posts[9356]->post_modified_gmt );
		$this->assertSame(
			WordPressTestState::$posts[9356]->post_modified,
			WordPressTestState::$updated_posts[0]['post_modified'] ?? null
		);
		$this->assertSame(
			WordPressTestState::$posts[9356]->post_modified_gmt,
			WordPressTestState::$updated_posts[0]['post_modified_gmt'] ?? null
		);
	}

	public function test_execute_does_not_write_over_a_concurrently_modified_post_version(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9357, $content );
		WordPressTestState::$posts[9357]->post_modified            = '2020-01-02 03:04:05';
		WordPressTestState::$posts[9357]->post_modified_gmt        = '2020-01-02 03:04:05';
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ): void {
			WordPressTestState::$posts[ $post_id ]->post_modified     = '2021-02-03 04:05:06';
			WordPressTestState::$posts[ $post_id ]->post_modified_gmt = '2021-02-03 04:05:06';
		};

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9357,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $content, $this->live_content( 9357 ) );
		$this->assertSame( '2021-02-03 04:05:06', WordPressTestState::$posts[9357]->post_modified );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_execute_does_not_overwrite_a_concurrent_content_change_after_save_filters_run(): void {
		$content    = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$concurrent = $this->paragraph( 'Concurrent editor save' );
		$this->seed_post( 9361, $content );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( isset( $data['post_content'] ) ) {
					$data['post_content'] = str_replace( '>Keep<', '>Keep filtered<', (string) $data['post_content'] );
				}

				return $data;
			},
			10,
			4
		);
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ) use ( $concurrent ): void {
			WordPressTestState::$posts[ $post_id ]->post_content = $concurrent;
		};

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9361,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $concurrent, $this->live_content( 9361 ) );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_execute_does_not_write_when_the_post_type_changes_at_the_database_boundary(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9362, $content );
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ): void {
			WordPressTestState::$posts[ $post_id ]->post_type = 'page';
		};

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9362,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( 'page', WordPressTestState::$posts[9362]->post_type );
		$this->assertSame( $content, $this->live_content( 9362 ) );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_execute_does_not_write_when_the_post_status_changes_at_the_database_boundary(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9364, $content );
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ): void {
			WordPressTestState::$posts[ $post_id ]->post_status = 'trash';
		};

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9364,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( 'trash', WordPressTestState::$posts[9364]->post_status );
		$this->assertSame( $content, $this->live_content( 9364 ) );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_execute_rejects_a_save_filter_that_changes_post_eligibility(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9366, $content );
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				$data['post_status'] = 'trash';

				return $data;
			},
			10,
			4
		);

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9366,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( 'publish', WordPressTestState::$posts[9366]->post_status );
		$this->assertSame( $content, $this->live_content( 9366 ) );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_execute_does_not_write_when_the_post_password_changes_at_the_database_boundary(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9365, $content );
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ): void {
			WordPressTestState::$posts[ $post_id ]->post_password = 'other-owner';
		};

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9365,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( 'other-owner', WordPressTestState::$posts[9365]->post_password );
		$this->assertSame( $content, $this->live_content( 9365 ) );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_execute_allows_a_post_updated_direct_posts_update_after_the_guarded_content_write(): void {
		global $wpdb;

		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( 9367, $content );
		add_action(
			'post_updated',
			static function ( int $post_id ) use ( $wpdb ): void {
				$wpdb->update(
					$wpdb->posts,
					[ 'post_title' => 'Updated by post_updated' ],
					[ 'ID' => $post_id ]
				);
			},
			10,
			1
		);

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9367,
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
		$this->assertSame( 'Updated by post_updated', WordPressTestState::$posts[9367]->post_title );
		$this->assertSame( $this->paragraph( 'Keep' ), WordPressTestState::$posts[9367]->post_content );
	}

	public function test_execute_requires_recovery_when_later_hook_context_drift_is_restored_before_return(): void {
		global $wpdb;

		$content        = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$original_posts = (string) $wpdb->posts;
		$this->seed_post( 9368, $content );
		add_action(
			'post_updated',
			static function ( int $post_id ) use ( $wpdb, $original_posts ): void {
				$wpdb->posts                         = 'wp_2_posts';
				WordPressTestState::$current_blog_id = 2;

				try {
					$wpdb->update(
						$wpdb->posts,
						[ 'post_title' => 'Other site hook write' ],
						[ 'ID' => $post_id ]
					);
				} finally {
					$wpdb->posts                         = $original_posts;
					WordPressTestState::$current_blog_id = 1;
				}
			},
			10,
			1
		);

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9368,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( $this->paragraph( 'Keep' ), WordPressTestState::$posts[9368]->post_content );
	}

	/**
	 * @dataProvider post_write_identity_changes
	 */
	public function test_execute_requires_recovery_when_a_later_hook_changes_target_identity(
		string $field,
		string $value,
		int $post_id
	): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$this->seed_post( $post_id, $content );
		WordPressTestState::$after_wp_update_post = static function ( int $updated_post_id ) use ( $field, $value ): void {
			WordPressTestState::$posts[ $updated_post_id ]->{$field} = $value;
		};

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				$post_id,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( $value, WordPressTestState::$posts[ $post_id ]->{$field} );
		$this->assertSame( $this->paragraph( 'Keep' ), WordPressTestState::$posts[ $post_id ]->post_content );
	}

	/** @return array<string, array{0: string, 1: string, 2: int}> */
	public static function post_write_identity_changes(): array {
		return [
			'post type' => [ 'post_type', 'page', 9369 ],
			'post name' => [ 'post_name', 'moved-by-hook', 9370 ],
		];
	}

	public function test_execute_requires_recovery_when_a_failed_guard_readback_matches_the_intended_content(): void {
		$before = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$after  = $this->paragraph( 'Keep' );
		$this->seed_post( 9371, $before );
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ) use ( $after ): void {
			WordPressTestState::$posts[ $post_id ]->post_content = $after;
		};

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9371,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( $after, WordPressTestState::$posts[9371]->post_content );
	}

	public function test_execute_requires_recovery_when_a_failed_guard_cannot_be_read_back(): void {
		$before     = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$concurrent = $this->paragraph( 'Concurrent editor' );
		$this->seed_post( 9372, $before );
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ) use ( $concurrent ): void {
			WordPressTestState::$posts[ $post_id ]->post_content = $concurrent;
			WordPressTestState::$next_raw_post_row_returns_null  = true;
		};

		$result = PostBlocksApplyExecutor::execute(
			$this->entry(
				9372,
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( $concurrent, WordPressTestState::$posts[9372]->post_content );
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
				],
				'wp_template_part'
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

	public function test_undo_uses_the_final_post_read_as_its_guarded_write_baseline(): void {
		$before           = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$after            = $this->paragraph( 'Keep' );
		$equivalent_after = '<!--   wp:paragraph   --><p>Keep</p><!-- /wp:paragraph -->';
		$post_id          = 9373;
		$this->seed_post( $post_id, $after );
		WordPressTestState::$posts[ $post_id ]->post_name = 'original-slug';
		WordPressTestState::$before_get_post              = static function () use ( $equivalent_after, $post_id ): void {
			WordPressTestState::$before_get_post = static function () use ( $equivalent_after, $post_id ): void {
				$fresh               = clone WordPressTestState::$posts[ $post_id ];
				$fresh->post_name    = 'renamed-slug';
				$fresh->post_content = $equivalent_after;

				WordPressTestState::$posts[ $post_id ] = $fresh;
			};
		};

		$result = PostBlocksApplyExecutor::undo( self::executed_entry( $post_id, $before, $after ) );

		$this->assertSame( [ 'result' => 'undone' ], $result );
		$this->assertSame( $before, $this->live_content( $post_id ) );
		$this->assertSame( 'renamed-slug', WordPressTestState::$posts[ $post_id ]->post_name );
	}

	public function test_undo_does_not_overwrite_a_concurrent_content_change_at_the_database_boundary(): void {
		$before     = $this->paragraph( 'Keep' ) . $this->paragraph( 'Drop' );
		$after      = $this->paragraph( 'Keep' );
		$concurrent = $this->paragraph( 'Concurrent editor save' );
		$this->seed_post( 9363, $after );
		WordPressTestState::$before_conditional_post_content_write = static function ( int $post_id ) use ( $concurrent ): void {
			WordPressTestState::$posts[ $post_id ]->post_content = $concurrent;
		};

		$result = PostBlocksApplyExecutor::undo( self::executed_entry( 9363, $before, $after ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $concurrent, $this->live_content( 9363 ) );
		$this->assertSame( [], WordPressTestState::$updated_posts );
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
				'surface'  => 'post-blocks',
				'target'   => [
					'postId'   => 9313,
					'postType' => 'post',
				],
				'document' => [
					'entityId' => '9313',
					'postType' => 'post',
					'scopeKey' => 'post:9313',
				],
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
