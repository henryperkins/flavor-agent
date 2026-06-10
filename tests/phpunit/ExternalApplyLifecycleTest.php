<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository;
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
				'before'       => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
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
}
