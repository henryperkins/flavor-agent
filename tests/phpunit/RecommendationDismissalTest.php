<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\RecommendationOutcome;
use FlavorAgent\Activity\RecommendationOutcomeMetrics;
use FlavorAgent\Activity\Repository;
use FlavorAgent\REST\Agent_Controller;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class RecommendationDismissalTest extends TestCase {

	protected function setUp(): void {
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		WordPressTestState::$capabilities    = [
			'edit_post'  => true,
			'edit_posts' => true,
		];
		Repository::install();
	}

	public function test_dismissal_is_generic_nonterminal_and_not_undoable(): void {
		$entry                                   = $this->entry( 'dismissed' );
		$entry['suggestion']                     = 'Secret generated label';
		$entry['before']                         = [ 'secret' => 'before' ];
		$entry['document']['prompt']             = 'Secret nested prompt';
		$entry['rawPayload']                     = [ 'prompt' => 'Secret root prompt' ];
		$entry['after']['outcome']['operations'] = [ 'secret operation' ];
		$entry['after']['outcome']['ranking']    = [ 'contextScore' => 0.9 ];
		$entry['undo']                           = [
			'status'  => 'available',
			'canUndo' => true,
		];
		$result                                  = RecommendationOutcome::normalize_entry( $entry );
		$this->assertIsArray( $result );
		$this->assertSame( 'Recommendation dismissed', $result['suggestion'] );
		$this->assertSame( 'diagnostic', $result['executionResult'] );
		$this->assertSame(
			[
				'canUndo' => false,
				'status'  => 'not_applicable',
			],
			$result['undo']
		);
		$this->assertSame( 'user_dismissed', $result['after']['outcome']['reason'] );
		$this->assertArrayNotHasKey( 'operations', $result['after']['outcome'] );
		$this->assertArrayNotHasKey( 'ranking', $result['after']['outcome'] );
		$this->assertStringNotContainsString( 'Secret', json_encode( $result ) );
	}

	public function test_repository_never_coerces_or_truncates_raw_dismissal_identities(): void {
		$shown = $this->entry( 'shown' );
		$shown['after']['outcome']['shownSuggestionKeys'] = [ 's1' ];
		Repository::create( $shown );
		foreach ( [ 1, ' s1 ', '<b>s1</b>' ] as $key ) {
			$entry                  = $this->entry( 'dismissed' );
			$entry['suggestionKey'] = $key;
			$this->assertInstanceOf( \WP_Error::class, Repository::create( $entry ) );
		}
	}

	/** @dataProvider invalid_identity */
	public function test_missing_conflicting_or_free_text_dismissal_is_rejected( string $identity_case ): void {
		$entry = $this->entry( 'dismissed' );
		if ( 'set' === $identity_case ) {
			unset( $entry['after']['outcome']['recommendationSetId'] );
		} elseif ( 'suggestion' === $identity_case ) {
			unset( $entry['suggestionKey'] );
		} elseif ( 'signature' === $identity_case ) {
			unset( $entry['after']['outcome']['sourceRequestSignature'] );
		} elseif ( 'reason' === $identity_case ) {
			$entry['after']['outcome']['reason'] = 'Private user reason';
		} else {
			$entry['target']['recommendationSetId'] = 'different';
		}
		$this->assertInstanceOf( \WP_Error::class, RecommendationOutcome::normalize_entry( $entry ) );
	}

	public static function invalid_identity(): array {
		return [ [ 'set' ], [ 'suggestion' ], [ 'signature' ], [ 'reason' ], [ 'conflict' ] ];
	}

	public function test_explicit_shown_identity_list_is_not_the_top_three_snapshot(): void {
		$entry = $this->entry( 'shown' );
		$entry['after']['outcome']['shownSuggestionKeys'] = [ 's1', 's2', 's3', 's4', 's5' ];
		$entry['after']['outcome']['topSuggestionKeys']   = [ 's1', 's2', 's3', 's4' ];
		$result = RecommendationOutcome::normalize_entry( $entry );
		$this->assertSame( [ 's1', 's2', 's3', 's4', 's5' ], $result['after']['outcome']['shownSuggestionKeys'] ?? null );
		$this->assertCount( 3, $result['after']['outcome']['topSuggestionKeys'] );
	}

	public function test_dismissal_requires_a_shown_identity_and_retries_dedupe_different_client_ids(): void {
		$shown = $this->entry( 'shown' );
		$shown['after']['outcome']['shownSuggestionKeys'] = [ 's1', 's4' ];
		$this->assertIsArray( Repository::create( $shown ) );
		$first = $this->create( $this->entry( 'dismissed' ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $first );
		$retry       = $this->entry( 'dismissed' );
		$retry['id'] = 'new-client-id';
		$second      = $this->create( $retry );
		$this->assertInstanceOf( \WP_REST_Response::class, $second );
		$this->assertSame( $first->get_data()['entry']['id'], $second->get_data()['entry']['id'] );
		$this->assertCount( 2, WordPressTestState::$db_tables[ Repository::table_name() ] );
		$unshown                  = $retry;
		$unshown['suggestionKey'] = 's2';
		$this->assertInstanceOf( \WP_Error::class, $this->create( $unshown ) );
	}

	public function test_absent_stale_and_other_owner_shown_observations_block_recording(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->create( $this->entry( 'dismissed' ) ) );
		$shown = $this->entry( 'shown' );
		$shown['after']['outcome']['shownSuggestionKeys'] = [ 's1' ];
		Repository::create( $shown );
		$stale = $this->entry( 'dismissed' );
		$stale['after']['outcome']['sourceRequestSignature'] = 'hash_stale';
		$this->assertInstanceOf( \WP_Error::class, $this->create( $stale ) );
		WordPressTestState::$current_user_id = 8;
		$this->assertInstanceOf( \WP_Error::class, $this->create( $this->entry( 'dismissed' ) ) );
		WordPressTestState::$current_user_id              = 7;
		$shown['id']                                      = 'new-shown';
		$shown['timestamp']                               = '2026-10-07T12:01:00Z';
		$shown['after']['outcome']['recommendationSetId'] = 'new-set';
		Repository::create( $shown );
		$this->assertInstanceOf( \WP_Error::class, $this->create( $this->entry( 'dismissed' ) ) );
	}

	public function test_dismissal_permissions_use_canonical_document_scope(): void {
		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$result                           = $this->create( $this->entry( 'dismissed' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_newest_same_set_with_changed_source_signature_blocks_older_identity(): void {
		$shown = $this->entry( 'shown' );
		$shown['after']['outcome']['shownSuggestionKeys'] = [ 's1' ];
		Repository::create( $shown );
		$shown['id']        = 'changed-source';
		$shown['timestamp'] = '2026-10-07T12:01:00Z';
		$shown['after']['outcome']['sourceRequestSignature'] = 'hash_changed';
		Repository::create( $shown );
		$this->assertInstanceOf( \WP_Error::class, $this->create( $this->entry( 'dismissed' ) ) );
	}

	public function test_dismissals_have_their_own_identifiable_suggestion_denominator(): void {
		$shown = $this->entry( 'shown' );
		$shown['after']['outcome']['shownSuggestionKeys'] = [ 's1', 's2', 's3', 's4' ];
		$shown['after']['outcome']['resultCount']         = 6;
		$dismissed                                        = $this->entry( 'dismissed' );
		$metrics = RecommendationOutcomeMetrics::evaluate( [ $shown, $dismissed, $dismissed ] );
		$this->assertSame( 4, $metrics['shownSuggestionCount'] ?? null );
		$this->assertSame( 1, $metrics['dismissedSuggestionCount'] ?? null );
		$this->assertSame( 2, $metrics['dismissalExcludedCount'] ?? null );
		$this->assertSame( 0.25, $metrics['dismissalRate'] ?? null );
		$this->assertSame( 0.0, $metrics['validationBlockedRate'] );
		$this->assertSame( 0.0, $metrics['applyConversionRate'] );
		$this->assertSame( 0.0, $metrics['savePersistedRate'] );
	}

	public function test_colon_bearing_attempt_tuples_do_not_merge_distinct_runtime_events(): void {
		$blocked = [];
		$applied = [];
		foreach ( [ [ 'a:b', 'c' ], [ 'a', 'b:c' ] ] as $index => [ $set, $suggestion ] ) {
			$entry                  = $this->entry( 'validation_blocked' );
			$entry['id']            = 'blocked-' . $index;
			$entry['suggestionKey'] = $suggestion;
			$entry['after']['outcome']['recommendationSetId'] = $set;
			$entry['after']['outcome']['reason']              = 'operation_validation_failed';
			$blocked[]                                        = $entry;
			$applied[]                                        = [
				'type'          => 'apply_suggestion',
				'surface'       => 'block',
				'suggestionKey' => $suggestion,
				'request'       => [
					'recommendation' => [
						'recommendationSetId' => $set,
						'suggestionKey'       => $suggestion,
					],
				],
			];
		}
		$single_apply = $applied[0];
		$single_apply['request']['recommendation']['recommendationSetId'] = 'distinct';
		$single_block = $blocked[0];
		$single_block['after']['outcome']['recommendationSetId'] = 'distinct';
		$blocked_metrics = RecommendationOutcomeMetrics::evaluate( [ ...$blocked, $single_apply ] );
		$apply_metrics   = RecommendationOutcomeMetrics::evaluate( [ ...$applied, $single_block ] );
		$this->assertSame( 0.6667, $blocked_metrics['validationBlockedRate'] );
		$this->assertSame( 0.3333, $apply_metrics['validationBlockedRate'] );
	}

	private function create( array $entry ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity' );
		$request->set_param( 'entry', $entry );
		return Agent_Controller::handle_create_activity( $request );
	}

	private function entry( string $event ): array {
		return [
			'id'            => $event . '-client-id',
			'schemaVersion' => 1,
			'type'          => 'recommendation_outcome',
			'surface'       => 'block',
			'suggestionKey' => 'dismissed' === $event ? 's1' : null,
			'document'      => [
				'scopeKey' => 'post:5',
				'postType' => 'post',
				'entityId' => '5',
			],
			'timestamp'     => '2026-10-07T12:00:00Z',
			'after'         => [
				'outcome' => [
					'event'                  => $event,
					'recommendationSetId'    => 'set-1',
					'sourceRequestSignature' => 'hash_fresh',
					'reason'                 => 'dismissed' === $event ? 'user_dismissed' : '',
				],
			],
		];
	}
}
