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
		$this->assertNull( \FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'template-part' ) );
	}
}
