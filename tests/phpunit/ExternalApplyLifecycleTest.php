<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository;
use FlavorAgent\Apply\ApplyClaim;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ExternalApplyLifecycleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		Repository::install();
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function create_pending_entry( array $overrides = [] ): array {
		$entry = array_replace_recursive(
			[
				'type'            => 'apply_global_styles_suggestion',
				'surface'         => 'global-styles',
				'target'          => [ 'globalStylesId' => '17' ],
				'suggestion'      => 'Darken the palette',
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'request'         => [
					'prompt'    => 'darker',
					'reference' => 'external-apply:global_styles:17',
					'apply'     => [
						'status'           => 'pending',
						'requestedBy'      => 7,
						'requestedAt'      => gmdate( 'c' ),
						'expiresAt'        => gmdate( 'c', time() + 3600 ),
						'operations'       => [
							[
								'type'       => 'set_styles',
								'path'       => [ 'color', 'text' ],
								'value'      => 'var:preset|color|accent',
								'valueType'  => 'preset',
								'presetType' => 'color',
								'presetSlug' => 'accent',
								'cssVar'     => 'var(--wp--preset--color--accent)',
							],
						],
						'signatures'       => [
							'resolvedContextSignature' => str_repeat( 'a', 64 ),
							'reviewContextSignature'   => str_repeat( 'b', 64 ),
							'baselineConfigHash'       => str_repeat( 'c', 64 ),
						],
						'requestReference' => 'agent-req-1',
					],
				],
				'document'        => [
					'scopeKey' => 'global_styles:17',
					'postType' => 'global_styles',
					'entityId' => '17',
				],
			],
			$overrides
		);

		$created = Repository::create( $entry );
		$this->assertIsArray( $created );

		return $created;
	}

	/**
	 * Seed the live Global Styles world a style-lane approve writes against:
	 * an empty user config plus an accent/base palette with a light background
	 * complement so a solo accent text operation passes the contrast gate.
	 */
	private function seed_global_styles_world(): void {
		WordPressTestState::$posts[17]       = new \WP_Post(
			[
				'ID'           => 17,
				'post_type'    => 'wp_global_styles',
				'post_content' => (string) wp_json_encode(
					[
						'version'                     => 3,
						'isGlobalStylesUserThemeJSON' => true,
						'settings'                    => [],
						'styles'                      => [],
					]
				),
			]
		);
		WordPressTestState::$global_settings = [
			'color' => [
				'palette'    => [
					'theme' => [
						[
							'slug'  => 'accent',
							'name'  => 'Accent',
							'color' => '#111111',
						],
						[
							'slug'  => 'base',
							'name'  => 'Base',
							'color' => '#fefefe',
						],
					],
				],
				'background' => true,
				'text'       => true,
			],
		];
		WordPressTestState::$global_styles   = [ 'color' => [ 'background' => '#fefefe' ] ];
	}

	private const TEMPLATE_PART_ID = 'twentytwentyfive//header';
	private const TEMPLATE_REF     = 'twentytwentyfive//home';

	/**
	 * The live template part a template-part approve writes against. Mirrors
	 * TemplatePartApplyExecutorTest::seed_part: a DB-backed part the executor
	 * re-collects, re-validates, mutates, and persists through the stubbed WP APIs.
	 */
	private function seed_template_part( string $content, int $wp_id ): void {
		WordPressTestState::$active_theme                        = [ 'stylesheet' => 'twentytwentyfive' ];
		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => self::TEMPLATE_PART_ID,
				'wp_id'   => $wp_id,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $content,
			],
		];
		WordPressTestState::$posts[ $wp_id ]                     = new \WP_Post(
			[
				'ID'           => $wp_id,
				'post_type'    => 'wp_template_part',
				'post_content' => $content,
			]
		);
	}

	private function seed_template( string $content, int $wp_id ): void {
		WordPressTestState::$active_theme                   = [ 'stylesheet' => 'twentytwentyfive' ];
		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => $wp_id,
				'slug'    => 'home',
				'title'   => 'Home',
				'content' => $content,
			],
		];
		WordPressTestState::$posts[ $wp_id ]                = new \WP_Post(
			[
				'ID'           => $wp_id,
				'post_type'    => 'wp_template',
				'post_content' => $content,
			]
		);
	}

	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
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

	/**
	 * Seed a PENDING template-part external-apply row whose baseline matches the
	 * live part and whose single remove operation re-validates, so a decide()
	 * approve drives all the way through the executor to a successful apply.
	 *
	 * @return array<string, mixed>
	 */
	private function create_template_part_pending_entry( string $content ): array {
		$created = Repository::create(
			[
				'id'              => 'template-part-row',
				'type'            => 'apply_template_part_suggestion',
				'surface'         => 'template-part',
				'target'          => [ 'templatePartId' => self::TEMPLATE_PART_ID ],
				'suggestion'      => 'Trim the header',
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'request'         => [
					'prompt'    => 'trim',
					'reference' => 'external-apply:template-part:' . self::TEMPLATE_PART_ID,
					'apply'     => [
						'status'      => 'pending',
						'requestedBy' => 7,
						'requestedAt' => gmdate( 'c' ),
						'expiresAt'   => gmdate( 'c', time() + 3600 ),
						'operations'  => [
							[
								'type'              => 'remove_block',
								'targetPath'        => [ 0, 0 ],
								'expectedBlockName' => 'core/heading',
							],
						],
						'signatures'  => [
							'baselineConfigHash' => hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
						],
					],
				],
				'document'        => [
					'scopeKey' => 'template-part:' . self::TEMPLATE_PART_ID,
					'postType' => 'wp_template_part',
					'entityId' => self::TEMPLATE_PART_ID,
				],
			]
		);
		$this->assertIsArray( $created );

		return $created;
	}

	public function test_pending_row_round_trips_with_apply_lifecycle(): void {
		$created = $this->create_pending_entry();

		$this->assertSame( 'pending', $created['executionResult'] );
		$this->assertSame( 'pending', $created['apply']['status'] );
		$this->assertSame( 'not_applicable', $created['undo']['status'] );
		$this->assertSame( [], $created['before'] );
		$this->assertSame( [], $created['after'] );
		$this->assertSame( 7, $created['apply']['requestedBy'] );
	}

	public function test_transition_to_available_writes_snapshots_and_unlocks_undo(): void {
		$created = $this->create_pending_entry();

		$updated = Repository::transition_external_apply(
			(string) $created['id'],
			[
				'applyStatus'  => 'available',
				'decidedBy'    => 3,
				'decidedAt'    => '2026-06-10T02:00:00+00:00',
				'decisionNote' => 'Looks safe',
				'executedAt'   => '2026-06-10T02:00:01+00:00',
				'before'       => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'        => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
					'operations' => $created['apply']['operations'],
				],
				'target'       => [ 'globalStylesId' => '17' ],
			]
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'available', $updated['apply']['status'] );
		$this->assertSame( 'applied', $updated['executionResult'] );
		$this->assertSame( 'available', $updated['undo']['status'] );
		$this->assertTrue( $updated['undo']['canUndo'] );
		$this->assertSame( 3, $updated['apply']['decidedBy'] );
		$this->assertSame( 'Looks safe', $updated['apply']['decisionNote'] );
		$this->assertSame( '2026-06-10T02:00:01+00:00', $updated['apply']['executedAt'] );
		$this->assertSame(
			[ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			$updated['after']['userConfig']['styles']
		);
	}

	public function test_transition_to_rejected_keeps_snapshots_empty_and_undo_not_applicable(): void {
		$created = $this->create_pending_entry();

		$updated = Repository::transition_external_apply(
			(string) $created['id'],
			[
				'applyStatus'  => 'rejected',
				'decidedBy'    => 3,
				'decidedAt'    => '2026-06-10T02:00:00+00:00',
				'decisionNote' => 'Not now',
			]
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'rejected', $updated['apply']['status'] );
		$this->assertSame( 'rejected', $updated['executionResult'] );
		$this->assertSame( 'not_applicable', $updated['undo']['status'] );
		$this->assertSame( [], $updated['before'] );
		$this->assertSame( [], $updated['after'] );
	}

	public function test_transition_persists_the_decided_by_name_snapshot(): void {
		$created = $this->create_pending_entry();

		$updated = Repository::transition_external_apply(
			(string) $created['id'],
			[
				'applyStatus'   => 'rejected',
				'decidedBy'     => 3,
				'decidedByName' => 'Grace Hopper',
				'decidedAt'     => '2026-06-10T02:00:00+00:00',
			]
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'Grace Hopper', $updated['apply']['decidedByName'] );
	}

	public function test_decide_snapshots_the_deciding_users_display_name(): void {
		WordPressTestState::$users[42]       = [
			'display_name' => 'Ada Lovelace',
			'user_login'   => 'ada',
			'roles'        => [ 'administrator' ],
		];
		WordPressTestState::$current_user_id = 42;

		$created = $this->create_pending_entry();

		$decided = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $created['id'],
			'reject',
			'Not now'
		);

		$this->assertIsArray( $decided );
		$this->assertSame( 'rejected', $decided['apply']['status'] );
		$this->assertSame( 42, $decided['apply']['decidedBy'] );
		$this->assertSame( 'Ada Lovelace', $decided['apply']['decidedByName'] );
	}

	public function test_transition_to_failed_records_failure_metadata(): void {
		$created = $this->create_pending_entry();

		$updated = Repository::transition_external_apply(
			(string) $created['id'],
			[
				'applyStatus'    => 'failed',
				'decidedBy'      => 3,
				'decidedAt'      => '2026-06-10T02:00:00+00:00',
				'failureCode'    => 'flavor_agent_apply_stale',
				'failureMessage' => 'The Global Styles entity changed after this apply was requested.',
			]
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'failed', $updated['apply']['status'] );
		$this->assertSame( 'failed', $updated['executionResult'] );
		$this->assertSame( 'flavor_agent_apply_stale', $updated['apply']['failureCode'] );
		$this->assertSame( 'not_applicable', $updated['undo']['status'] );
	}

	public function test_transitions_are_one_way_out_of_pending(): void {
		$created = $this->create_pending_entry();
		Repository::transition_external_apply( (string) $created['id'], [ 'applyStatus' => 'rejected' ] );

		$second = Repository::transition_external_apply( (string) $created['id'], [ 'applyStatus' => 'available' ] );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'flavor_agent_apply_invalid_transition', $second->get_error_code() );
	}

	public function test_transition_requires_the_persisted_execution_result_to_still_be_pending(): void {
		$created = $this->create_pending_entry();
		$table   = Repository::table_name();

		foreach ( WordPressTestState::$db_tables[ $table ] as $index => $row ) {
			if ( (string) ( $row['activity_id'] ?? '' ) !== (string) $created['id'] ) {
				continue;
			}

			WordPressTestState::$db_tables[ $table ][ $index ]['execution_result'] = 'rejected';
			break;
		}

		$result = Repository::transition_external_apply(
			(string) $created['id'],
			[
				'applyStatus' => 'available',
				'before'      => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'       => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'target'      => [ 'globalStylesId' => '17' ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_invalid_transition', $result->get_error_code() );
	}

	public function test_transition_rejects_unknown_target_status(): void {
		$created = $this->create_pending_entry();

		$result = Repository::transition_external_apply( (string) $created['id'], [ 'applyStatus' => 'pending' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_invalid_transition', $result->get_error_code() );
	}

	public function test_maybe_expire_pending_apply_expires_overdue_rows_and_persists(): void {
		$created = $this->create_pending_entry(
			[ 'request' => [ 'apply' => [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] ] ]
		);

		$expired = Repository::maybe_expire_pending_apply( $created );

		$this->assertSame( 'expired', $expired['apply']['status'] );
		$this->assertSame( 'expired', $expired['executionResult'] );

		$stored = Repository::find( (string) $created['id'] );
		$this->assertSame( 'expired', $stored['apply']['status'] );
	}

	public function test_maybe_expire_pending_apply_leaves_unexpired_rows_untouched(): void {
		$created = $this->create_pending_entry();

		$result = Repository::maybe_expire_pending_apply( $created );

		$this->assertSame( 'pending', $result['apply']['status'] );
	}

	public function test_prune_sweeps_overdue_pending_applies(): void {
		$created = $this->create_pending_entry(
			[ 'request' => [ 'apply' => [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] ] ]
		);

		Repository::prune();

		$stored = Repository::find( (string) $created['id'] );
		$this->assertSame( 'expired', $stored['apply']['status'] );
	}

	public function test_count_active_pending_external_applies_counts_only_unexpired_rows_for_the_user(): void {
		$this->create_pending_entry( [ 'id' => 'mine-active' ] );
		$this->create_pending_entry(
			[
				'id'      => 'mine-overdue',
				'request' => [ 'apply' => [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] ],
			]
		);

		WordPressTestState::$current_user_id = 9;
		$this->create_pending_entry( [ 'id' => 'theirs-active' ] );
		WordPressTestState::$current_user_id = 7;

		$this->assertSame( 1, Repository::count_active_pending_external_applies( 7 ) );

		$overdue = Repository::find( 'mine-overdue' );
		$this->assertSame( 'expired', $overdue['apply']['status'] );
	}

	public function test_pending_external_apply_notification_snapshot_invalidates_when_new_pending_rows_are_created(): void {
		$empty_snapshot = Repository::get_pending_external_apply_notification_snapshot();

		$this->assertSame( 0, $empty_snapshot['count'] );
		$this->assertNull( $empty_snapshot['latest'] );

		$this->create_pending_entry( [ 'id' => 'pending-created-after-cache' ] );

		$snapshot = Repository::get_pending_external_apply_notification_snapshot();

		$this->assertSame( 1, $snapshot['count'] );
		$this->assertIsArray( $snapshot['latest'] );
		$this->assertSame( 'pending-created-after-cache', $snapshot['latest']['id'] );
	}

	public function test_pending_external_apply_notification_snapshot_returns_latest_active_pending_row(): void {
		$this->create_pending_entry(
			[
				'id'        => 'pending-older',
				'timestamp' => '2026-06-10T01:00:00+00:00',
			]
		);
		$this->create_pending_entry(
			[
				'id'        => 'pending-newer',
				'timestamp' => '2026-06-10T02:00:00+00:00',
			]
		);
		$this->create_pending_entry(
			[
				'id'        => 'pending-overdue',
				'timestamp' => '2026-06-10T03:00:00+00:00',
				'request'   => [ 'apply' => [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] ],
			]
		);

		$snapshot = Repository::get_pending_external_apply_notification_snapshot();

		$this->assertSame( 2, $snapshot['count'] );
		$this->assertIsArray( $snapshot['latest'] );
		$this->assertSame( 'pending-newer', $snapshot['latest']['id'] );
		$this->assertSame( 'pending', $snapshot['latest']['apply']['status'] );
		$this->assertSame( 'User #7', $snapshot['latest']['userLabel'] );

		$overdue = Repository::find( 'pending-overdue' );
		$this->assertSame( 'expired', $overdue['apply']['status'] );
	}

	public function test_non_executed_rows_do_not_block_ordered_undo_of_older_executed_rows(): void {
		$executed = Repository::create(
			[
				'type'       => 'apply_global_styles_suggestion',
				'surface'    => 'global-styles',
				'target'     => [ 'globalStylesId' => '17' ],
				'suggestion' => 'Editor apply',
				'before'     => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'      => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'undo'       => [ 'status' => 'available' ],
				'timestamp'  => '2026-06-10T01:00:00+00:00',
				'document'   => [ 'scopeKey' => 'global_styles:17' ],
			]
		);
		$this->assertIsArray( $executed );

		// A newer pending external apply on the same entity must not block undo.
		$this->create_pending_entry( [ 'timestamp' => '2026-06-10T02:00:00+00:00' ] );

		$this->assertTrue( Repository::can_perform_ordered_undo( (string) $executed['id'] ) );

		$undone = Repository::update_undo_status( (string) $executed['id'], 'undone' );
		$this->assertIsArray( $undone );
		$this->assertSame( 'undone', $undone['undo']['status'] );
	}

	public function test_executed_external_apply_blocks_ordered_undo_like_an_editor_row(): void {
		$executed = Repository::create(
			[
				'type'       => 'apply_global_styles_suggestion',
				'surface'    => 'global-styles',
				'target'     => [ 'globalStylesId' => '17' ],
				'suggestion' => 'Editor apply',
				'before'     => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'      => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'undo'       => [ 'status' => 'available' ],
				'timestamp'  => '2026-06-10T01:00:00+00:00',
				'document'   => [ 'scopeKey' => 'global_styles:17' ],
			]
		);
		$pending  = $this->create_pending_entry( [ 'timestamp' => '2026-06-10T02:00:00+00:00' ] );
		Repository::transition_external_apply(
			(string) $pending['id'],
			[
				'applyStatus' => 'available',
				'executedAt'  => '2026-06-10T02:00:01+00:00',
				'before'      => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'       => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'target'      => [ 'globalStylesId' => '17' ],
			]
		);

		$this->assertFalse( Repository::can_perform_ordered_undo( (string) $executed['id'] ) );

		$blocked = Repository::update_undo_status( (string) $executed['id'], 'undone' );
		$this->assertInstanceOf( \WP_Error::class, $blocked );
		$this->assertSame( 'flavor_agent_activity_undo_blocked', $blocked->get_error_code() );
	}

	public function test_admin_query_reports_lifecycle_statuses_and_summary_counts(): void {
		$this->create_pending_entry( [ 'id' => 'row-pending' ] );
		$rejected = $this->create_pending_entry( [ 'id' => 'row-rejected' ] );
		Repository::transition_external_apply( 'row-rejected', [ 'applyStatus' => 'rejected' ] );
		$expired = $this->create_pending_entry(
			[
				'id'      => 'row-expired',
				'request' => [ 'apply' => [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] ],
			]
		);
		Repository::maybe_expire_pending_apply( Repository::find( 'row-expired' ) );

		$result   = Repository::query_admin( [] );
		$statuses = [];

		foreach ( $result['entries'] as $entry ) {
			$statuses[ (string) $entry['id'] ] = (string) ( $entry['status'] ?? '' );
		}

		$this->assertSame( 'pending', $statuses['row-pending'] );
		$this->assertSame( 'rejected', $statuses['row-rejected'] );
		$this->assertSame( 'expired', $statuses['row-expired'] );
		$this->assertSame( 1, $result['summary']['pending'] );
		$this->assertSame( 1, $result['summary']['rejected'] );
		$this->assertSame( 1, $result['summary']['expired'] );
		unset( $rejected, $expired );
	}

	public function test_admin_query_reports_pre_execution_failed_rows_as_failed(): void {
		$failed = $this->create_pending_entry( [ 'id' => 'row-failed' ] );
		Repository::transition_external_apply(
			'row-failed',
			[
				'applyStatus'    => 'failed',
				'failureCode'    => 'flavor_agent_apply_stale',
				'failureMessage' => 'Drifted before approval.',
			]
		);
		unset( $failed );

		$result = Repository::query_admin( [] );
		$entry  = null;

		foreach ( $result['entries'] as $candidate ) {
			if ( 'row-failed' === (string) ( $candidate['id'] ?? '' ) ) {
				$entry = $candidate;
			}
		}

		$this->assertIsArray( $entry );
		$this->assertSame( 'failed', $entry['status'] );
		$this->assertSame( 'Apply failed', $entry['admin']['statusLabel'] );
	}

	public function test_pending_rows_project_operation_metadata_from_the_apply_payload(): void {
		$created = $this->create_pending_entry( [ 'id' => 'row-projected' ] );
		unset( $created );

		$table = Repository::table_name();
		$row   = null;

		foreach ( WordPressTestState::$db_tables[ $table ] ?? [] as $candidate ) {
			if ( 'row-projected' === (string) ( $candidate['activity_id'] ?? '' ) ) {
				$row = $candidate;
			}
		}

		$this->assertIsArray( $row );
		$this->assertNotSame(
			'',
			trim( (string) ( $row['admin_operation_type'] ?? '' ) ),
			'Pending rows must derive admin operation metadata from request.apply.operations.'
		);
	}

	public function test_committed_transition_clears_an_active_claim(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		ApplyClaim::claim( $id, 7 );
		$this->assertNotNull( ApplyClaim::get( $id ) );

		Repository::transition_external_apply(
			$id,
			[
				'applyStatus'  => 'rejected',
				'decidedBy'    => 7,
				'decidedAt'    => gmdate( 'c' ),
				'decisionNote' => '',
			]
		);

		$this->assertNull( ApplyClaim::get( $id ) );
	}

	public function test_non_committing_transition_leaves_the_claim_untouched(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];

		// First transition commits the row out of pending.
		Repository::transition_external_apply( $id, [ 'applyStatus' => 'rejected' ] );

		// A claim placed afterwards (directly, since the row is no longer pending)
		// must survive a second, non-committing transition attempt.
		$key = 'flavor_agent_apply_claim_' . md5( $id );
		set_transient(
			$key,
			[
				'userId'    => 7,
				'claimedAt' => gmdate( 'c' ),
			],
			ApplyClaim::TTL
		);

		$second = Repository::transition_external_apply( $id, [ 'applyStatus' => 'available' ] );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'flavor_agent_apply_invalid_transition', $second->get_error_code() );
		$this->assertNotNull( ApplyClaim::get( $id ) );
	}

	public function test_foreign_claim_does_not_block_a_transition(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];

		// A claim held by another user must not gate the decision path.
		ApplyClaim::claim( $id, 9 );

		WordPressTestState::$current_user_id = 7;
		$updated                             = Repository::transition_external_apply(
			$id,
			[
				'applyStatus'  => 'rejected',
				'decidedBy'    => 7,
				'decidedAt'    => gmdate( 'c' ),
				'decisionNote' => '',
			]
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'rejected', $updated['apply']['status'] );
	}

	public function test_attestation_is_recorded_for_style_template_part_and_template_applies(): void {
		$secret_key = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $secret_key );
		\FlavorAgent\Attestation\Repository::install();

		$failures = [];
		add_action(
			'flavor_agent_attestation_record_failed',
			static function ( array $event ) use ( &$failures ): void {
				$failures[] = $event;
			}
		);

		// --- Style lane regression guard. ---
		$this->seed_global_styles_world();
		$style = $this->create_pending_entry(
			[
				'id'      => 'style-row',
				'request' => [
					'apply' => [
						'signatures' => [
							'baselineConfigHash' => \FlavorAgent\Apply\StyleApplyExecutor::comparable_config_hash(
								[
									'settings' => [],
									'styles'   => [],
								]
							),
						],
					],
				],
			]
		);

		$style_decided = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $style['id'], 'approve' );

		$this->assertIsArray( $style_decided );
		$this->assertSame( 'available', $style_decided['apply']['status'] );
		$style_attestation = \FlavorAgent\Attestation\Repository::find_by_related_activity( 'style-row' );
		$this->assertIsArray( $style_attestation );

		$style_statement = json_decode( (string) $style_attestation['statement_bytes'], true );
		$this->assertIsArray( $style_statement );
		$this->assertSame( 'external-style-apply-v1', $style_statement['predicate']['governance']['lane'] );

		// --- Template-part lane. ---
		$content = '<!-- wp:group -->'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
			. '<!-- /wp:group -->';
		$this->seed_template_part( $content, 8801 );
		$part = $this->create_template_part_pending_entry( $content );

		$part_decided = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $part['id'], 'approve' );

		$this->assertIsArray( $part_decided );
		$this->assertSame(
			'available',
			$part_decided['apply']['status'],
			'The template-part executor must succeed so the post-apply attestation branch is actually exercised.'
		);
		$part_attestation = \FlavorAgent\Attestation\Repository::find_by_related_activity( (string) $part['id'] );
		$this->assertIsArray( $part_attestation );

		$part_statement = json_decode( (string) $part_attestation['statement_bytes'], true );
		$this->assertIsArray( $part_statement );
		$this->assertSame( 'external-template-part-apply-v1', $part_statement['predicate']['governance']['lane'] );
		$this->assertSame( 'wp_template_part:' . self::TEMPLATE_PART_ID, $part_statement['subject'][0]['name'] );

		// --- Template lane. ---
		$template_content = $this->template_content();
		$this->seed_template( $template_content, 8802 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		$template_signatures = $this->resolve_template_signatures();
		$template_request    = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'      => [
					'surface'      => 'template',
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'home',
					'slug'         => 'home',
					'title'        => 'Home',
				],
				'prompt'     => 'add a hero',
				'operations' => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => (string) $template_signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $template_signatures['reviewContextSignature'],
				],
			]
		);
		$this->assertIsArray( $template_request );

		$template_decided = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $template_request['activityId'],
			'approve'
		);

		$this->assertIsArray( $template_decided );
		$this->assertSame(
			'available',
			$template_decided['apply']['status'],
			'The template executor must succeed so the post-apply attestation branch is actually exercised.'
		);
		$template_attestation = \FlavorAgent\Attestation\Repository::find_by_related_activity( (string) $template_request['activityId'] );
		$this->assertIsArray( $template_attestation );

		$template_statement = json_decode( (string) $template_attestation['statement_bytes'], true );
		$this->assertIsArray( $template_statement );
		$this->assertSame( 'external-template-apply-v1', $template_statement['predicate']['governance']['lane'] );
		$this->assertSame( 'wp_template:' . self::TEMPLATE_REF, $template_statement['subject'][0]['name'] );
		$this->assertSame( [], $failures );
	}

	public function test_style_apply_executor_implements_the_external_apply_contract(): void {
		$this->assertInstanceOf(
			\ReflectionClass::class,
			new \ReflectionClass( \FlavorAgent\Apply\StyleApplyExecutor::class )
		);
		$this->assertTrue(
			is_subclass_of( \FlavorAgent\Apply\StyleApplyExecutor::class, \FlavorAgent\Apply\ExternalApplyExecutor::class ),
			'StyleApplyExecutor must implement ExternalApplyExecutor.'
		);
		$this->assertSame(
			\FlavorAgent\Apply\StyleApplyExecutor::class,
			\FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'global-styles' )
		);
		$this->assertSame(
			\FlavorAgent\Apply\TemplatePartApplyExecutor::class,
			\FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'template-part' )
		);
	}

	public function test_registry_routes_template_part_to_its_executor(): void {
		$this->assertSame(
			\FlavorAgent\Apply\TemplatePartApplyExecutor::class,
			\FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'template-part' )
		);
	}

	/**
	 * The single source of validatable header content the request handler and the
	 * signature probe both read: a group wrapping a heading + paragraph, where a
	 * remove of the heading at path [0,0] re-validates against the live contract.
	 */
	private function template_part_content(): string {
		return '<!-- wp:group -->'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
			. '<!-- /wp:group -->';
	}

	private function template_content(): string {
		return $this->paragraph( 'Body' );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function resolve_template_signatures( array $overrides = [] ): array {
		$signatures = \FlavorAgent\Abilities\TemplateAbilities::recommend_template(
			array_replace_recursive(
				[
					'templateRef'          => self::TEMPLATE_REF,
					'templateType'         => 'home',
					'prompt'               => 'add a hero',
					'resolveSignatureOnly' => true,
				],
				$overrides
			)
		);
		$this->assertIsArray( $signatures );

		return $signatures;
	}

	public function test_request_template_part_apply_rejects_stale_signatures(): void {
		// Seed the part so the signature-only probe RESOLVES and returns real
		// signatures; the provided 'stale' values then fail hash_equals.
		$this->seed_template_part( $this->template_part_content(), 8810 );

		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_part_apply(
			[
				'scope'      => [
					'surface'        => 'template-part',
					'templatePartId' => self::TEMPLATE_PART_ID,
				],
				'operations' => [
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/navigation',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => 'stale',
					'reviewContextSignature'   => 'stale',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );
	}

	public function test_request_template_part_apply_rejects_non_template_part_scope(): void {
		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_part_apply(
			[
				'scope'      => [
					'surface'        => 'global-styles',
					'templatePartId' => self::TEMPLATE_PART_ID,
				],
				'operations' => [
					[
						'type'       => 'remove_block',
						'targetPath' => [ 0 ],
					],
				],
				'signatures' => [
					'resolvedContextSignature' => str_repeat( 'a', 64 ),
					'reviewContextSignature'   => str_repeat( 'b', 64 ),
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_template_part_scope', $result->get_error_code() );
	}

	public function test_request_template_part_apply_creates_pending_row_with_baseline_hash(): void {
		$content = $this->template_part_content();
		$this->seed_template_part( $content, 8811 );

		// Mirror the style happy path: read the REAL signatures from the same
		// signature-only probe the handler recomputes, then hand them back.
		$signatures = \FlavorAgent\Abilities\TemplateAbilities::recommend_template_part(
			[
				'templatePartRef'      => self::TEMPLATE_PART_ID,
				'prompt'               => 'trim the header',
				'resolveSignatureOnly' => true,
			]
		);
		$this->assertIsArray( $signatures );

		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_part_apply(
			[
				'scope'      => [
					'surface'        => 'template-part',
					'templatePartId' => self::TEMPLATE_PART_ID,
					'slug'           => 'header',
					'area'           => 'header',
				],
				'prompt'     => 'trim the header',
				'operations' => [
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 0 ],
						'expectedBlockName' => 'core/heading',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertNotSame( '', (string) $result['activityId'] );
		$this->assertNotSame( '', (string) $result['expiresAt'] );

		$stored = Repository::find( (string) $result['activityId'] );
		$this->assertIsArray( $stored );
		$this->assertSame( 'template-part', $stored['surface'] );
		$this->assertSame( 'apply_template_part_suggestion', $stored['type'] );
		$this->assertSame( 'pending', $stored['executionResult'] );
		$this->assertSame( 'not_applicable', $stored['undo']['status'] );
		$this->assertSame( self::TEMPLATE_PART_ID, $stored['target']['templatePartId'] );
		$this->assertSame( self::TEMPLATE_PART_ID, $stored['target']['templatePartRef'] );
		$this->assertSame( 'header', $stored['target']['slug'] );
		$this->assertSame( 'header', $stored['target']['area'] );
		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
			$stored['apply']['signatures']['baselineContentHash']
		);
		$this->assertSame(
			(string) $signatures['resolvedContextSignature'],
			$stored['apply']['signatures']['resolvedContextSignature']
		);
		$this->assertSame( 'wp_template_part:' . self::TEMPLATE_PART_ID, $stored['document']['scopeKey'] );
		$this->assertSame( 'wp_template_part', $stored['document']['postType'] );
		$this->assertNotEmpty( $stored['apply']['operations'] );

		// Producer -> consumer: the stored row must derive a template-part entity
		// whose ref is the part ref, not the document-key fallback. This locks the
		// audit/ordered-undo key against the templatePartId-only regression.
		$entity = \FlavorAgent\Activity\Serializer::derive_entity( $stored );
		$this->assertSame( 'template-part', $entity['type'] );
		$this->assertSame( self::TEMPLATE_PART_ID, $entity['ref'] );
	}

	public function test_registry_routes_template_to_its_executor(): void {
		$this->assertSame(
			\FlavorAgent\Apply\TemplateApplyExecutor::class,
			\FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'template' )
		);
	}

	public function test_request_template_apply_rejects_invalid_scope(): void {
		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'      => [ 'surface' => 'template' ],
				'operations' => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => 'a',
					'reviewContextSignature'   => 'b',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_template_scope', $result->get_error_code() );
	}

	public function test_request_template_apply_rejects_non_insert_pattern_ops(): void {
		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'      => [
					'surface'      => 'template',
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'home',
				],
				'operations' => [
					[
						'type'       => 'remove_block',
						'targetPath' => [ 0 ],
					],
				],
				'signatures' => [
					'resolvedContextSignature' => 'a',
					'reviewContextSignature'   => 'b',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
	}

	public function test_request_template_apply_rejects_stale_signatures(): void {
		$this->seed_template( $this->template_content(), 8820 );

		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'      => [
					'surface'      => 'template',
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'home',
				],
				'operations' => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => 'stale',
					'reviewContextSignature'   => 'stale',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );
	}

	public function test_request_template_apply_accepts_signed_replay_with_design_semantics(): void {
		$this->seed_template( $this->template_content(), 8821 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		$design_semantics = [
			'sectionRole' => 'hero',
		];
		$signatures       = $this->resolve_template_signatures(
			[
				'designSemantics' => $design_semantics,
			]
		);

		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'           => [
					'surface'      => 'template',
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'home',
					'slug'         => 'home',
					'title'        => 'Home',
				],
				'prompt'          => 'add a hero',
				'designSemantics' => $design_semantics,
				'operations'      => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				],
				'signatures'      => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
	}

	public function test_request_template_apply_accepts_signed_replay_with_editor_overlays(): void {
		$this->seed_template( $this->template_content(), 8822 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		$overlays   = [
			'editorSlots'     => [
				'emptyAreas' => [ 'footer' ],
			],
			'editorStructure' => [
				'topLevelBlockTree' => [
					[
						'path'       => [ 0 ],
						'name'       => 'core/heading',
						'label'      => 'Heading',
						'childCount' => 0,
					],
				],
				'structureStats'    => [
					'blockCount'         => 1,
					'topLevelBlockCount' => 1,
					'firstTopLevelBlock' => 'core/heading',
					'lastTopLevelBlock'  => 'core/heading',
				],
			],
		];
		$signatures = $this->resolve_template_signatures( $overlays );

		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'           => [
					'surface'      => 'template',
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'home',
				],
				'prompt'          => 'add a hero',
				'editorSlots'     => $overlays['editorSlots'],
				'editorStructure' => $overlays['editorStructure'],
				'operations'      => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				],
				'signatures'      => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
	}

	public function test_request_template_apply_replays_a_non_canonical_template_type_without_false_staleness(): void {
		// recommend_template folds templateType RAW into the resolved signature
		// (prepare_template_signature_context takes it verbatim), so a faithful
		// external replay carrying a non-canonical type (uppercase, here
		// 'Home-Page') must recompute byte-identically. sanitize_key'ing it inside
		// the request handler's signature probe recomputes a different resolved
		// hash and false-fails the replay as stale even though nothing drifted.
		$this->seed_template( $this->template_content(), 8824 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		$signatures = $this->resolve_template_signatures(
			[
				'templateType' => 'Home-Page',
			]
		);

		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'      => [
					'surface'      => 'template',
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'Home-Page',
					'slug'         => 'home',
					'title'        => 'Home',
				],
				'prompt'     => 'add a hero',
				'operations' => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
	}

	public function test_request_template_apply_creates_pending_row_with_baseline_hash(): void {
		$content = $this->template_content();
		$this->seed_template( $content, 8823 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		$signatures = $this->resolve_template_signatures();

		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'      => [
					'surface'      => 'template',
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'home',
					'slug'         => 'home',
					'title'        => 'Home',
				],
				'prompt'     => 'add a hero',
				'operations' => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertNotSame( '', (string) $result['activityId'] );
		$this->assertNotSame( '', (string) $result['expiresAt'] );

		$stored = Repository::find( (string) $result['activityId'] );
		$this->assertIsArray( $stored );
		$this->assertSame( 'template', $stored['surface'] );
		$this->assertSame( 'apply_template_suggestion', $stored['type'] );
		$this->assertSame( 'pending', $stored['executionResult'] );
		$this->assertSame( 'not_applicable', $stored['undo']['status'] );
		$this->assertSame( self::TEMPLATE_REF, $stored['target']['templateRef'] );
		$this->assertSame( 'home', $stored['target']['templateType'] );
		$this->assertSame( 'home', $stored['target']['slug'] );
		$this->assertSame( 'Home', $stored['target']['title'] );
		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
			$stored['apply']['signatures']['baselineContentHash']
		);
		$this->assertSame(
			(string) $signatures['resolvedContextSignature'],
			$stored['apply']['signatures']['resolvedContextSignature']
		);
		$this->assertSame( 'wp_template:' . self::TEMPLATE_REF, $stored['document']['scopeKey'] );
		$this->assertSame( 'wp_template', $stored['document']['postType'] );
		$this->assertNotEmpty( $stored['apply']['operations'] );

		$entity = \FlavorAgent\Activity\Serializer::derive_entity( $stored );
		$this->assertSame( 'template', $entity['type'] );
		$this->assertSame( self::TEMPLATE_REF, $entity['ref'] );
	}

	public function test_request_template_apply_approves_executes_and_undoes(): void {
		$content = $this->template_content();
		$this->seed_template( $content, 8824 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );
		$signatures = $this->resolve_template_signatures();

		$pending = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'      => [
					'surface'      => 'template',
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'home',
					'slug'         => 'home',
					'title'        => 'Home',
				],
				'prompt'     => 'add a hero',
				'operations' => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
			]
		);
		$this->assertIsArray( $pending );

		$approved = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'approve'
		);

		$this->assertIsArray( $approved );
		$this->assertSame( 'available', $approved['apply']['status'] );
		$this->assertStringStartsWith(
			'<!-- wp:paragraph --><p>Hero',
			(string) WordPressTestState::$posts[8824]->post_content
		);
		$this->seed_template(
			(string) WordPressTestState::$posts[8824]->post_content,
			8824
		);

		$undone = \FlavorAgent\Abilities\ApplyAbilities::undo_activity(
			[
				'activityId' => (string) $pending['activityId'],
			]
		);

		$this->assertIsArray( $undone );
		$this->assertSame( 'undone', $undone['result'] );
		$this->assertSame( $content, (string) WordPressTestState::$posts[8824]->post_content );
	}

	// -----------------------------------------------------------------
	// post-blocks lane
	// -----------------------------------------------------------------

	private const POST_BLOCKS_POST_ID = 9400;

	private function seed_post_blocks_document( string $content, int $post_id = self::POST_BLOCKS_POST_ID, string $post_type = 'post', string $post_status = 'publish' ): void {
		WordPressTestState::$posts[ $post_id ] = new \WP_Post(
			[
				'ID'           => $post_id,
				'post_type'    => $post_type,
				'post_status'  => $post_status,
				'post_title'   => 'Doc ' . $post_id,
				'post_content' => $content,
			]
		);
	}

	/**
	 * A group wrapping a heading + paragraph, where a remove of the heading at
	 * path [0,0] re-validates against the live document target contract.
	 */
	private function post_blocks_content(): string {
		return '<!-- wp:group -->'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
			. '<!-- /wp:group -->';
	}

	public function test_request_post_blocks_apply_rejects_stale_signatures(): void {
		$this->seed_post_blocks_document( $this->post_blocks_content() );

		$result = \FlavorAgent\Abilities\ApplyAbilities::request_post_blocks_apply(
			[
				'scope'      => [
					'surface' => 'post-blocks',
					'postId'  => self::POST_BLOCKS_POST_ID,
				],
				'operations' => [
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 0 ],
						'expectedBlockName' => 'core/heading',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => 'stale',
					'reviewContextSignature'   => 'stale',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );
	}

	public function test_request_post_blocks_apply_rejects_non_post_blocks_scope(): void {
		$result = \FlavorAgent\Abilities\ApplyAbilities::request_post_blocks_apply(
			[
				'scope'      => [
					'surface' => 'global-styles',
					'postId'  => self::POST_BLOCKS_POST_ID,
				],
				'operations' => [
					[
						'type'       => 'remove_block',
						'targetPath' => [ 0 ],
					],
				],
				'signatures' => [
					'resolvedContextSignature' => str_repeat( 'a', 64 ),
					'reviewContextSignature'   => str_repeat( 'b', 64 ),
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_post_blocks_scope', $result->get_error_code() );
	}

	public function test_request_post_blocks_apply_rejects_missing_post_id(): void {
		$result = \FlavorAgent\Abilities\ApplyAbilities::request_post_blocks_apply(
			[
				'scope'      => [ 'surface' => 'post-blocks' ],
				'operations' => [
					[
						'type'       => 'remove_block',
						'targetPath' => [ 0 ],
					],
				],
				'signatures' => [
					'resolvedContextSignature' => str_repeat( 'a', 64 ),
					'reviewContextSignature'   => str_repeat( 'b', 64 ),
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_post_blocks_scope', $result->get_error_code() );
	}

	public function test_request_post_blocks_apply_creates_pending_row_with_baseline_hash(): void {
		$content = $this->post_blocks_content();
		$this->seed_post_blocks_document( $content );

		// Mirror the template-part happy path: read the REAL signatures from
		// the same signature-only probe the handler recomputes.
		$signatures = \FlavorAgent\Abilities\PostBlocksAbilities::recommend_post_blocks(
			[
				'postId'               => self::POST_BLOCKS_POST_ID,
				'prompt'               => 'trim the heading',
				'resolveSignatureOnly' => true,
			]
		);
		$this->assertIsArray( $signatures );

		$result = \FlavorAgent\Abilities\ApplyAbilities::request_post_blocks_apply(
			[
				'scope'      => [
					'surface' => 'post-blocks',
					'postId'  => self::POST_BLOCKS_POST_ID,
				],
				'prompt'     => 'trim the heading',
				'operations' => [
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 0 ],
						'expectedBlockName' => 'core/heading',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertNotSame( '', (string) $result['activityId'] );
		$this->assertNotSame( '', (string) $result['expiresAt'] );

		$stored = Repository::find( (string) $result['activityId'] );
		$this->assertIsArray( $stored );
		$this->assertSame( 'post-blocks', $stored['surface'] );
		$this->assertSame( 'apply_post_blocks_suggestion', $stored['type'] );
		$this->assertSame( 'pending', $stored['executionResult'] );
		$this->assertSame( 'not_applicable', $stored['undo']['status'] );
		$this->assertSame( self::POST_BLOCKS_POST_ID, $stored['target']['postId'] );
		$this->assertSame( 'post', $stored['target']['postType'] );
		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
			$stored['apply']['signatures']['baselineContentHash']
		);
		$this->assertSame(
			(string) $signatures['resolvedContextSignature'],
			$stored['apply']['signatures']['resolvedContextSignature']
		);
		$this->assertSame( 'post:' . self::POST_BLOCKS_POST_ID, $stored['document']['scopeKey'] );
		$this->assertSame( 'post', $stored['document']['postType'] );
		$this->assertNotEmpty( $stored['apply']['operations'] );

		// Producer -> consumer: the stored row must derive a post-blocks entity
		// keyed by postId, not the document-key fallback.
		$entity = \FlavorAgent\Activity\Serializer::derive_entity( $stored );
		$this->assertSame( 'post-blocks', $entity['type'] );
		$this->assertSame( (string) self::POST_BLOCKS_POST_ID, $entity['ref'] );
	}

	public function test_request_post_blocks_apply_rejects_unsupported_post_type(): void {
		$this->seed_post_blocks_document( $this->post_blocks_content(), self::POST_BLOCKS_POST_ID, 'wp_template_part' );

		$result = \FlavorAgent\Abilities\ApplyAbilities::request_post_blocks_apply(
			[
				'scope'      => [
					'surface' => 'post-blocks',
					'postId'  => self::POST_BLOCKS_POST_ID,
				],
				'operations' => [
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 0 ],
						'expectedBlockName' => 'core/heading',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => str_repeat( 'a', 64 ),
					'reviewContextSignature'   => str_repeat( 'b', 64 ),
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_registry_routes_post_blocks_to_its_executor(): void {
		$this->assertSame(
			\FlavorAgent\Apply\PostBlocksApplyExecutor::class,
			\FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'post-blocks' )
		);
	}

	public function test_request_post_blocks_apply_approves_executes_and_undoes(): void {
		$content = $this->post_blocks_content();
		$this->seed_post_blocks_document( $content );

		$signatures = \FlavorAgent\Abilities\PostBlocksAbilities::recommend_post_blocks(
			[
				'postId'               => self::POST_BLOCKS_POST_ID,
				'prompt'               => 'trim the heading',
				'resolveSignatureOnly' => true,
			]
		);
		$this->assertIsArray( $signatures );

		$pending = \FlavorAgent\Abilities\ApplyAbilities::request_post_blocks_apply(
			[
				'scope'      => [
					'surface' => 'post-blocks',
					'postId'  => self::POST_BLOCKS_POST_ID,
				],
				'prompt'     => 'trim the heading',
				'operations' => [
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 0 ],
						'expectedBlockName' => 'core/heading',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
			]
		);
		$this->assertIsArray( $pending );

		$approved = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'approve'
		);

		$this->assertIsArray( $approved );
		$this->assertSame( 'available', $approved['apply']['status'] );
		$this->assertStringNotContainsString(
			'Title',
			(string) WordPressTestState::$posts[ self::POST_BLOCKS_POST_ID ]->post_content
		);
		$this->assertStringContainsString(
			'Body',
			(string) WordPressTestState::$posts[ self::POST_BLOCKS_POST_ID ]->post_content
		);

		$undone = \FlavorAgent\Abilities\ApplyAbilities::undo_activity(
			[
				'activityId' => (string) $pending['activityId'],
			]
		);

		$this->assertIsArray( $undone );
		$this->assertSame( 'undone', $undone['result'] );
		$this->assertSame(
			serialize_blocks( parse_blocks( $content ) ),
			serialize_blocks( parse_blocks( (string) WordPressTestState::$posts[ self::POST_BLOCKS_POST_ID ]->post_content ) )
		);
	}

	public function test_attestation_is_not_recorded_for_post_blocks_apply(): void {
		$secret_key = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $secret_key );
		\FlavorAgent\Attestation\Repository::install();

		$failures = [];
		add_action(
			'flavor_agent_attestation_record_failed',
			static function ( array $event ) use ( &$failures ): void {
				$failures[] = $event;
			}
		);

		$content = $this->post_blocks_content();
		$this->seed_post_blocks_document( $content );

		$signatures = \FlavorAgent\Abilities\PostBlocksAbilities::recommend_post_blocks(
			[
				'postId'               => self::POST_BLOCKS_POST_ID,
				'prompt'               => 'trim the heading',
				'resolveSignatureOnly' => true,
			]
		);
		$this->assertIsArray( $signatures );

		$pending = \FlavorAgent\Abilities\ApplyAbilities::request_post_blocks_apply(
			[
				'scope'      => [
					'surface' => 'post-blocks',
					'postId'  => self::POST_BLOCKS_POST_ID,
				],
				'prompt'     => 'trim the heading',
				'operations' => [
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 0 ],
						'expectedBlockName' => 'core/heading',
					],
				],
				'signatures' => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
			]
		);
		$this->assertIsArray( $pending );

		$approved = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $pending['activityId'], 'approve' );

		$this->assertIsArray( $approved );
		$this->assertSame( 'available', $approved['apply']['status'] );
		$this->assertNull(
			\FlavorAgent\Attestation\Repository::find_by_related_activity( (string) $pending['activityId'] ),
			'Post-blocks stays frozen because the public subject-state contract cannot expose non-public post content.'
		);
		$this->assertSame( [], $failures );
	}
}
