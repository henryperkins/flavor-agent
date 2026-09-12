<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\PersistenceAssurance;
use FlavorAgent\Activity\PersistenceOccurrenceRepository;
use FlavorAgent\Activity\PersistenceOutcome;
use FlavorAgent\Activity\RecommendationOutcomeMetrics;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PersistenceAssuranceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		WordPressTestState::$capabilities    = [ 'edit_post:42' => true ];
		Repository::install();
		PersistenceOccurrenceRepository::install();
	}

	public function test_historical_or_external_applied_rows_do_not_establish_persistence(): void {
		$historical = $this->apply( 'historical', null );
		$external   = $this->apply( 'external', 'server-executed' );
		foreach ( PersistenceAssurance::enrich( [ $historical, $external ] ) as $entry ) {
			$this->assertSame( 'unknown', $entry['persistenceVerdict']['state'] );
			$this->assertSame( 'not_eligible', $entry['verificationCoverage']['state'] );
			$this->assertFalse( $entry['verificationCoverage']['eligible'] );
			$this->assertSame( 'applied', $entry['requestStatus']['state'] );
		}
	}

	public function test_capture_without_comparison_is_not_verified_and_excluded_from_persistence_rates(): void {
		$apply = $this->apply( 'queued' );
		$this->capture( 'save-queued' );
		$entry = PersistenceAssurance::enrich( [ $apply ] )[0];
		$this->assertSame( 'unknown', $entry['persistenceVerdict']['state'] );
		$this->assertSame( 'not_verified', $entry['verificationCoverage']['state'] );
		$this->assertTrue( $entry['verificationCoverage']['eligible'] );
		$this->assertFalse( $entry['verificationCoverage']['compared'] );
		$this->assertSame( 1, PersistenceAssurance::metrics( [ $apply ] )['unverifiedCoverageCount'] );
	}

	public function test_resolver_loads_durable_verdict_outside_the_supplied_page(): void {
		$apply    = $this->apply( 'outside-page' );
		$snapshot = $this->capture( 'save-outside' );
		$this->verdict( $apply, $snapshot, 'save_confirmed' );
		$entry = PersistenceAssurance::enrich( [ $apply ] )[0];
		$this->assertSame( 'save_confirmed', $entry['persistenceVerdict']['state'] );
		$this->assertSame( $snapshot['saveSequence'], $entry['persistenceVerdict']['saveSequence'] );
		$this->assertTrue( $entry['verificationCoverage']['conclusive'] );
		$this->assertSame( 1.0, PersistenceAssurance::metrics( [ $apply ] )['savePersistedRate'] );
	}

	public function test_highest_capture_sequence_wins_when_older_worker_finishes_last(): void {
		$apply = $this->apply( 'out-of-order' );
		$older = $this->capture( 'save-old' );
		$newer = $this->capture( 'save-new' );
		$this->verdict( $apply, $newer, 'save_discarded' );
		$this->verdict( $apply, $older, 'save_confirmed' );
		$entry = PersistenceAssurance::enrich( [ $apply ] )[0];
		$this->assertSame( 'save_discarded', $entry['persistenceVerdict']['state'] );
		$this->assertSame( $newer['saveSequence'], $entry['persistenceVerdict']['saveSequence'] );
		$this->assertSame( 'save-new', $entry['persistenceVerdict']['saveOccurrenceId'] );
	}

	public function test_later_unverifiable_verdict_replaces_earlier_conclusive_verdict(): void {
		$apply = $this->apply( 'later-unverifiable' );
		$older = $this->capture( 'save-conclusive' );
		$newer = $this->capture( 'save-inconclusive' );
		$this->verdict( $apply, $older, 'save_confirmed' );
		$this->verdict( $apply, $newer, 'save_unverifiable' );
		$entry = PersistenceAssurance::enrich( [ $apply ] )[0];
		$this->assertSame( 'save_unverifiable', $entry['persistenceVerdict']['state'] );
		$this->assertSame( 'compared', $entry['verificationCoverage']['state'] );
		$this->assertFalse( $entry['verificationCoverage']['conclusive'] );
		$metrics = PersistenceAssurance::metrics( [ $apply ] );
		$this->assertSame( 1.0, $metrics['saveUnverifiableRate'] );
		$this->assertSame( 0, $metrics['unverifiedCoverageCount'] );
	}

	public function test_request_failure_can_coexist_with_confirmed_persistence(): void {
		$apply    = $this->apply( 'lost-response' );
		$snapshot = $this->capture( 'save-lost-response' );
		$this->verdict( $apply, $snapshot, 'save_confirmed' );
		$this->request( $apply, 'save-lost-response', 'save_attempted' );
		$this->request( $apply, 'save-lost-response', 'save_failed' );
		$entry = PersistenceAssurance::enrich( [ $apply ] )[0];
		$this->assertSame( 'save_confirmed', $entry['persistenceVerdict']['state'] );
		$this->assertSame( 'save_failed', $entry['requestStatus']['state'] );
	}

	public function test_request_retry_cannot_hide_a_failure_from_the_same_occurrence(): void {
		$apply = $this->apply( 'failure-first' );
		$this->request( $apply, 'save-failed', 'save_failed' );
		$this->request( $apply, 'save-failed', 'save_attempted' );
		$entry = PersistenceAssurance::enrich( [ $apply ] )[0];
		$this->assertSame( 'save_failed', $entry['requestStatus']['state'] );
		$this->assertSame( 'unknown', $entry['persistenceVerdict']['state'] );
		$this->assertFalse( $entry['verificationCoverage']['eligible'] );
	}

	public function test_editor_undo_does_not_revoke_a_saved_verdict(): void {
		$apply    = $this->apply( 'undo' );
		$snapshot = $this->capture( 'save-before-undo' );
		$this->verdict( $apply, $snapshot, 'save_confirmed' );
		$apply['undo']['status'] = 'undone';
		$entry                   = PersistenceAssurance::enrich( [ $apply ] )[0];
		$this->assertSame( 'save_confirmed', $entry['persistenceVerdict']['state'] );
		$this->assertSame( 'undone', $entry['undoState']['state'] );
		$this->assertNotSame( '', $entry['undoState']['label'] );
		$this->assertSame( 1.0, PersistenceAssurance::metrics( [ $apply ] )['savePersistedRate'] );
	}

	public function test_metrics_use_server_cohorts_dedupe_applies_and_count_occurrences_once(): void {
		$confirmed  = $this->apply( 'confirmed' );
		$discarded  = $this->apply( 'discarded' );
		$uncertain  = $this->apply( 'uncertain' );
		$uncompared = $this->apply( 'uncompared' );
		$historical = $this->apply( 'historical', null );
		$snapshot   = $this->capture( 'shared-save' );
		$this->verdict( $confirmed, $snapshot, 'save_confirmed' );
		$this->verdict( $discarded, $snapshot, 'save_discarded' );
		$this->verdict( $uncertain, $snapshot, 'save_unverifiable' );
		$this->request( $confirmed, 'shared-save', 'save_attempted' );
		$this->request( $discarded, 'shared-save', 'save_attempted' );
		$entries = [ $confirmed, $discarded, $uncertain, $uncompared, $historical, $confirmed ];
		$this->assertSame(
			[
				'saveAttemptedOccurrences' => 1,
				'savePersistedRate'        => 0.5,
				'saveDiscardedRate'        => 0.5,
				'saveUnverifiableRate'     => 0.3333,
				'verificationCoverageRate' => 0.75,
				'unverifiedCoverageCount'  => 1,
			],
			PersistenceAssurance::metrics( $entries )
		);
	}

	public function test_unobserved_verdicts_cannot_push_coverage_over_one(): void {
		$apply = $this->apply( 'unobserved' );
		$this->verdict( $apply, $this->capture( 'unobserved-one' ), 'save_unverifiable' );
		$this->verdict( $apply, $this->capture( 'unobserved-two' ), 'save_confirmed' );
		$metrics = PersistenceAssurance::metrics( [ $apply ] );
		$this->assertSame( 0, $metrics['saveAttemptedOccurrences'] );
		$this->assertSame( 1.0, $metrics['verificationCoverageRate'] );
		$this->assertSame( 1.0, $metrics['savePersistedRate'] );
		$this->assertSame( 0.0, $metrics['saveUnverifiableRate'] );
	}

	public function test_supplied_assurance_fields_cannot_fabricate_a_verdict(): void {
		$apply                         = $this->apply( 'spoof' );
		$apply['persistenceVerdict']   = [ 'state' => 'save_confirmed' ];
		$apply['verificationCoverage'] = [
			'eligible'   => true,
			'compared'   => true,
			'conclusive' => true,
		];
		$entry                         = PersistenceAssurance::enrich( [ $apply ] )[0];
		$this->assertSame( 'unknown', $entry['persistenceVerdict']['state'] );
		$this->assertSame( 0.0, PersistenceAssurance::metrics( [ $apply ] )['savePersistedRate'] );
	}

	public function test_lifecycle_only_page_resolves_its_linked_apply_without_recursing_into_repository(): void {
		$apply   = $this->apply( 'lifecycle-page' );
		$verdict = $this->verdict( $apply, $this->capture( 'save-lifecycle' ), 'save_confirmed' );
		$entry   = PersistenceAssurance::enrich( [ $verdict ] )[0];
		$this->assertSame( $verdict['id'], $entry['id'] );
		$this->assertSame( 'save_confirmed', $entry['persistenceVerdict']['state'] );
		$this->assertSame( 1.0, PersistenceAssurance::metrics( [ $verdict ] )['savePersistedRate'] );
	}

	public function test_unrelated_linked_evidence_does_not_change_an_apply(): void {
		$requested = $this->apply( 'requested' );
		$other     = $this->apply( 'other' );
		$this->verdict( $other, $this->capture( 'other-save' ), 'save_confirmed' );
		$entry = PersistenceAssurance::enrich( [ $requested ] )[0];
		$this->assertSame( 'unknown', $entry['persistenceVerdict']['state'] );
		$this->assertSame( 1, PersistenceAssurance::metrics( [ $requested ] )['unverifiedCoverageCount'] );
	}

	public function test_original_metrics_keep_their_definitions_when_lifecycle_rows_are_present(): void {
		$apply          = $this->apply( 'report' );
		$verdict        = $this->verdict( $apply, $this->capture( 'save-report' ), 'save_confirmed' );
		$baseline       = RecommendationOutcomeMetrics::evaluate( [ $apply ] );
		$with_lifecycle = RecommendationOutcomeMetrics::evaluate( [ $apply, $verdict ] );
		$this->assertSame( $baseline, $with_lifecycle );
		$this->assertSame( 1, $with_lifecycle['unlinkedApplyCount'] );
		$this->assertSame( 1.0, $with_lifecycle['savePersistedRate'] );
	}

	public function test_empty_metric_denominators_are_zero(): void {
		$this->assertSame(
			[
				'saveAttemptedOccurrences' => 0,
				'savePersistedRate'        => 0.0,
				'saveDiscardedRate'        => 0.0,
				'saveUnverifiableRate'     => 0.0,
				'verificationCoverageRate' => 0.0,
				'unverifiedCoverageCount'  => 0,
			],
			PersistenceAssurance::metrics( [] )
		);
	}

	public function test_replay_survives_snapshot_expiry_and_does_not_compare_current_content(): void {
		$apply    = $this->apply( 'replay' );
		$snapshot = $this->capture( 'save-replay' );
		$this->verdict( $apply, $snapshot, 'save_confirmed' );
		PersistenceOccurrenceRepository::expire( $snapshot );
		WordPressTestState::$posts[42] = (object) [
			'ID'           => 42,
			'post_content' => 'The current content is completely different.',
		];
		$entry                         = PersistenceAssurance::enrich( [ $apply ] )[0];
		$this->assertSame( 'save_confirmed', $entry['persistenceVerdict']['state'] );
		$this->assertSame( $snapshot['saveSequence'], $entry['persistenceVerdict']['saveSequence'] );
	}

	public function test_pending_newer_capture_does_not_erase_an_existing_verdict(): void {
		$apply     = $this->apply( 'pending-newer' );
		$confirmed = $this->capture( 'save-complete' );
		$this->verdict( $apply, $confirmed, 'save_confirmed' );
		$this->capture( 'save-pending' );
		$entry = PersistenceAssurance::enrich( [ $apply ] )[0];
		$this->assertSame( 'save_confirmed', $entry['persistenceVerdict']['state'] );
		$this->assertSame( $confirmed['saveSequence'], $entry['persistenceVerdict']['saveSequence'] );
	}

	public function test_raw_arrays_and_unknown_entries_are_accepted_without_fabricated_coverage(): void {
		$entries  = [
			null,
			[
				'type'            => 'apply_suggestion',
				'executionResult' => 'applied',
			],
			[ 'type' => 'recommendation_outcome' ],
		];
		$enriched = PersistenceAssurance::enrich( $entries );
		$this->assertNull( $enriched[0] );
		$this->assertSame( 'unknown', $enriched[1]['persistenceVerdict']['state'] );
		$this->assertSame( 0.0, PersistenceAssurance::metrics( $entries )['verificationCoverageRate'] );
	}

	public function test_delayed_older_occurrence_failure_does_not_replace_newer_request_status(): void {
		$apply = $this->apply( 'ordered-request' );
		foreach ( [
			[ 'new-save', 'save_attempted', '2026-09-12T14:00:00.900Z' ],
			[ 'old-save', 'save_attempted', '2026-09-12T14:00:00.100Z' ],
			[ 'old-save', 'save_failed', '2026-09-12T14:30:00.000Z' ],
		] as [ $occurrence, $event, $observed_at ] ) {
			Repository::create(
				[
					'type'                  => 'recommendation_outcome',
					'surface'               => $apply['surface'],
					'document'              => $apply['document'],
					'linkedApplyActivityId' => $apply['id'],
					'saveOccurrenceId'      => $occurrence,
					'timestamp'             => $observed_at,
					'after'                 => [
						'outcome' => [
							'event'      => $event,
							'observedAt' => $observed_at,
						],
					],
				]
			);
		}
		$this->assertSame( 'save_attempted', PersistenceAssurance::enrich( [ $apply ] )[0]['requestStatus']['state'] );
	}

	private function apply( string $id, ?string $lane = 'editor-state' ): array {
		$result = Repository::create(
			[
				'id'        => $id,
				'type'      => 'apply_suggestion',
				'surface'   => 'block',
				'applyLane' => $lane,
				'target'    => [
					'blockName' => 'core/heading',
					'blockPath' => [ 0 ],
				],
				'before'    => [ 'attributes' => [ 'level' => 2 ] ],
				'after'     => [ 'attributes' => [ 'level' => 3 ] ],
				'document'  => [
					'scopeKey' => 'post:42',
					'postType' => 'post',
					'entityId' => '42',
				],
			]
		);
		$this->assertIsArray( $result );
		return $result;
	}

	private function capture( string $occurrence ): array {
		$result = PersistenceOccurrenceRepository::capture(
			[
				'saveOccurrenceId' => $occurrence,
				'origin'           => 'unobserved',
				'entity'           => [
					'type'     => 'post',
					'postType' => 'post',
					'postId'   => 42,
					'ref'      => '42',
				],
				'content'          => '<!-- wp:heading {"level":3} --><h3>Title</h3><!-- /wp:heading -->',
				'schemas'          => [
					'core/heading' => [
						'level' => [
							'type'    => 'number',
							'default' => 2,
						],
					],
				],
				'reason'           => 'saved',
			]
		);
		$this->assertIsArray( $result );
		return $result;
	}

	private function verdict( array $apply, array $snapshot, string $event ): array {
		$result = PersistenceOutcome::write_verdict(
			$apply,
			$snapshot,
			[
				'event'      => $event,
				'reason'     => 'comparison_fixture',
				'operations' => [
					[
						'state'  => 'save_confirmed' === $event ? 'present' : 'absent',
						'reason' => 'fixture',
					],
				],
			]
		);
		$this->assertIsArray( $result );
		return $result;
	}

	private function request( array $apply, string $occurrence, string $event ): array {
		$result = Repository::create(
			[
				'id'                    => $event . '-' . $apply['id'] . '-' . $occurrence,
				'type'                  => 'recommendation_outcome',
				'surface'               => $apply['surface'],
				'document'              => $apply['document'],
				'linkedApplyActivityId' => $apply['id'],
				'saveOccurrenceId'      => $occurrence,
				'after'                 => [ 'outcome' => [ 'event' => $event ] ],
			]
		);
		$this->assertIsArray( $result );
		return $result;
	}
}
