<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\GovernanceLearningReport;
use FlavorAgent\Activity\RecommendationOutcomeMetrics;
use PHPUnit\Framework\TestCase;

final class GovernanceLearningReportTest extends TestCase {

	public function test_builds_bounded_report_with_summary_parity_and_groups(): void {
		$entries = [
			self::outcome(
				'shown-block',
				'shown',
				'block',
				'set-1',
				's1',
				[
					'learningAttribution' => [
						'generationId'        => 'recgen:block:11111111-1111-4111-8111-111111111111',
						'provider'            => 'anthropic',
						'model'               => 'claude-sonnet-4-6',
						'guidelineVersion'    => 'gv1:abc123',
						'recommendationSetId' => 'set-1',
					],
					'rankingSet'          => [
						[
							'suggestionKey' => 'block:suggestions:1',
							'ranking'       => [
								'contextEvidence'  => [
									'prompt_match' => 0.8,
								],
								'contextPenalties' => [
									'stale_docs' => 0.2,
								],
								'rankingVersion'   => 'contextual-ranking-v1',
							],
						],
					],
				]
			),
			self::outcome(
				'selected-block',
				'selected_for_review',
				'block',
				'set-1',
				's1',
				[
					'reason'              => 'review_opened',
					'learningAttribution' => [
						'generationId'        => 'recgen:block:11111111-1111-4111-8111-111111111111',
						'provider'            => 'anthropic',
						'model'               => 'claude-sonnet-4-6',
						'guidelineVersion'    => 'gv1:abc123',
						'recommendationSetId' => 'set-1',
					],
				]
			),
			self::apply( 'apply-block', 'block', 'set-1', 's1', 'undone' ),
			self::outcome( 'validation-template', 'validation_blocked', 'template', 'set-2', 's2', [ 'reason' => 'disallowed_operation' ] ),
			self::outcome(
				'shown-pattern',
				'shown',
				'pattern',
				'set-3',
				'p1',
				[
					'rankingSet' => [
						[
							'suggestionKey' => 'theme/hero',
							'ranking'       => [
								'blendedScore' => 0.9,
							],
							'patternTraits' => [ 'hero-banner', 'complex' ],
						],
					],
				]
			),
			self::outcome( 'insert-pattern', 'pattern_inserted_from_shelf', 'pattern', 'set-3', 'p1', [ 'patternTraits' => [ 'hero-banner' ] ] ),
			self::outcome( 'adapt-failed-pattern', 'adapted_insert_failed', 'pattern', 'set-4', 'p2', [ 'patternTraits' => [ 'complex' ] ] ),
			self::outcome( 'insert-failed-pattern', 'insert_failed', 'pattern', 'set-5', 'p3', [ 'patternTraits' => [ 'gallery' ] ] ),
			self::outcome( 'stale-template', 'stale_blocked', 'template', 'set-6', 's6', [ 'reason' => 'resolved_context_changed' ] ),
		];

		$metrics = RecommendationOutcomeMetrics::evaluate( $entries );
		$report  = GovernanceLearningReport::build(
			$entries,
			[
				'generatedAt' => '2026-06-19T00:00:00Z',
				'rowLimit'    => 500,
				'truncated'   => false,
			]
		);

		$this->assertSame( 'governance-learning-report-v1', $report['version'] );
		$this->assertSame( '2026-06-19T00:00:00Z', $report['generatedAt'] );
		$this->assertSame( 9, $report['sampleSize'] );
		$this->assertSame( 500, $report['rowLimit'] );
		$this->assertFalse( $report['truncated'] );
		$this->assertSame( $metrics['shownCount'], $report['summary']['shownCount'] );
		$this->assertSame( $metrics['reviewSelectionRate'], $report['summary']['reviewSelectionRate'] );
		$this->assertSame( $metrics['applyConversionRate'], $report['summary']['applyConversionRate'] );
		$this->assertSame( $metrics['validationBlockedRate'], $report['summary']['validationBlockedRate'] );
		$this->assertSame( 1.0, $report['summary']['undoRate'] );
		$this->assertSame( 0.6667, $report['summary']['insertFailedRate'] );

		$pattern_surface = self::find_group( $report, 'surfaces', 'pattern' );
		$this->assertSame( 'Pattern', $pattern_surface['label'] );
		$this->assertSame( 4, $pattern_surface['sampleSize'] );
		$this->assertSame( 1, $pattern_surface['shownCount'] );
		$this->assertSame( 1, $pattern_surface['appliedCount'] );
		$this->assertSame( 2, $pattern_surface['insertFailedCount'] );
		$this->assertSame( 'shown-pattern', $pattern_surface['representativeActivityId'] );

		$this->assertSame( 1, self::find_group( $report, 'validationReasons', 'disallowed_operation' )['validationBlockedCount'] );
		$this->assertSame( 2, self::find_group( $report, 'providerModels', 'anthropic:claude-sonnet-4-6' )['sampleSize'] );
		$this->assertSame( 2, self::find_group( $report, 'guidelineVersions', 'gv1:abc123' )['sampleSize'] );
		$this->assertSame( 1, self::find_group( $report, 'rankingSignals', 'prompt_match' )['shownCount'] );
		$this->assertSame( 1, self::find_group( $report, 'rankingSignals', 'stale_docs' )['shownCount'] );
		$this->assertSame( 1, self::find_group( $report, 'rankingSignals', 'contextual-ranking-v1' )['shownCount'] );

		$hero_trait = self::find_group( $report, 'patternTraits', 'hero-banner' );
		$this->assertSame( 'Hero banner', $hero_trait['label'] );
		$this->assertSame( 2, $hero_trait['sampleSize'] );
		$this->assertSame( 1, $hero_trait['shownCount'] );
		$this->assertSame( 1, $hero_trait['appliedCount'] );
		$this->assertSame( 0, $hero_trait['insertFailedCount'] );
		$this->assertSame( 1, self::find_group( $report, 'patternTraits', 'complex' )['insertFailedCount'] );
		$this->assertSame( 1, self::find_group( $report, 'patternTraits', 'gallery' )['insertFailedCount'] );
	}

	public function test_build_handles_malformed_rows_and_truncation_metadata(): void {
		$report = GovernanceLearningReport::build(
			[
				null,
				'bad-row',
				self::outcome( 'shown-block', 'shown', 'block', 'set-1' ),
			],
			[
				'rowLimit'  => 1,
				'truncated' => true,
			]
		);

		$this->assertSame( 1, $report['sampleSize'] );
		$this->assertSame( 1, $report['rowLimit'] );
		$this->assertTrue( $report['truncated'] );
		$this->assertSame( 1, $report['summary']['shownCount'] );
		$this->assertSame( [], $report['groups']['patternTraits'] );
	}

	/**
	 * @param array<string, mixed> $outcome
	 * @return array<string, mixed>
	 */
	private static function outcome(
		string $id,
		string $event,
		string $surface,
		string $set_id,
		string $suggestion_key = '',
		array $outcome = []
	): array {
		return [
			'id'            => $id,
			'type'          => 'recommendation_outcome',
			'surface'       => $surface,
			'suggestionKey' => '' !== $suggestion_key ? $suggestion_key : null,
			'target'        => [
				'recommendationSetId' => $set_id,
				'suggestionKey'       => $suggestion_key,
			],
			'after'         => [
				'outcome' => array_merge(
					[
						'event'               => $event,
						'recommendationSetId' => $set_id,
					],
					$outcome
				),
			],
			'admin'         => [
				'operationType'      => 'recommendation_outcome',
				'operationTypeLabel' => 'Recommendation outcome',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function apply( string $id, string $surface, string $set_id, string $suggestion_key, string $status ): array {
		return [
			'id'            => $id,
			'type'          => 'apply_suggestion',
			'surface'       => $surface,
			'status'        => $status,
			'suggestionKey' => $suggestion_key,
			'request'       => [
				'recommendation' => [
					'recommendationSetId' => $set_id,
					'suggestionKey'       => $suggestion_key,
				],
			],
			'admin'         => [
				'operationType'      => 'apply_suggestion',
				'operationTypeLabel' => 'Apply suggestion',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function find_group( array $report, string $group, string $key ): array {
		foreach ( $report['groups'][ $group ] ?? [] as $row ) {
			if ( $key === ( $row['key'] ?? '' ) ) {
				return $row;
			}
		}

		self::fail( sprintf( 'Missing %s group row for %s.', $group, $key ) );
	}
}
