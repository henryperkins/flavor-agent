<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\RecommendationOutcomeMetrics;
use PHPUnit\Framework\TestCase;

final class RecommendationOutcomeEvaluationTest extends TestCase {

	public function test_metrics_dedupe_and_join_apply_rows(): void {
		$entries = [
			self::outcome( 'shown', 'block', 'set-1' ),
			self::outcome( 'shown', 'block', 'set-1' ),
			self::outcome( 'selected_for_review', 'block', 'set-1', 's1', 'review_opened' ),
			self::outcome( 'selected_for_review', 'block', 'set-1', 's1', 'review_opened' ),
			self::apply( 'block', 'set-1', 's1' ),
			self::outcome( 'shown', 'pattern', 'set-2' ),
			self::outcome( 'pattern_inserted_from_shelf', 'pattern', 'set-2', 'p1', 'insert_blocks_success' ),
			self::outcome( 'stale_blocked', 'template', 'set-3', 's3', 'resolved_context_changed' ),
			self::outcome( 'validation_blocked', 'template', 'set-3', 's3', 'disallowed_operation' ),
			self::apply( 'template', '', '' ),
		];

		$this->assertSame(
			[
				'shownCount'                => 2,
				'reviewSelectionRate'       => 0.5,
				'patternInsertionRate'      => 1.0,
				'staleBlockedRate'          => 0.2,
				'validationBlockedRate'     => 0.2,
				'validationBlockedByReason' => [ 'disallowed_operation' => 1 ],
				'applyConversionRate'       => 0.5,
				'reviewApplyConversionRate' => 1.0,
				'unlinkedApplyCount'        => 1,
			],
			RecommendationOutcomeMetrics::evaluate( $entries )
		);
	}

	public function test_validation_blocked_breakdown_is_per_reason_and_excludes_pattern(): void {
		$entries = [
			self::outcome( 'validation_blocked', 'template', 'set_x', 'sk_x', 'invalid_template_area' ),
			self::outcome( 'validation_blocked', 'global-styles', 'set_y', 'sk_y', 'failed_contrast' ),
			self::outcome( 'validation_blocked', 'pattern', 'set_z', 'sk_z', 'empty_pattern_blocks' ), // EXCLUDED
		];

		$metrics = RecommendationOutcomeMetrics::evaluate( $entries );

		$this->assertSame(
			[
				'invalid_template_area' => 1,
				'failed_contrast'       => 1,
			],
			$metrics['validationBlockedByReason']
		);
		$this->assertArrayNotHasKey( 'empty_pattern_blocks', $metrics['validationBlockedByReason'] );
	}

	public function test_apply_conversion_rates_count_applied_sets(): void {
		$entries = [
			self::outcome( 'shown', 'block', 'set-1' ),
			self::outcome( 'selected_for_review', 'block', 'set-1', 's1', 'review_opened' ),
			self::apply( 'block', 'set-1', 's1' ),
			self::apply( 'block', 'set-1', 's2' ),
			self::apply( 'block', 'set-2', 's3' ),
		];

		$metrics = RecommendationOutcomeMetrics::evaluate( $entries );

		$this->assertSame( 1.0, $metrics['applyConversionRate'] );
		$this->assertSame( 1.0, $metrics['reviewApplyConversionRate'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function outcome(
		string $event,
		string $surface,
		string $set_id,
		string $suggestion_key = '',
		string $reason = ''
	): array {
		return [
			'type'          => 'recommendation_outcome',
			'surface'       => $surface,
			'suggestionKey' => '' !== $suggestion_key ? $suggestion_key : null,
			'target'        => [
				'recommendationSetId' => $set_id,
				'suggestionKey'       => $suggestion_key,
			],
			'after'         => [
				'outcome' => [
					'event'               => $event,
					'recommendationSetId' => $set_id,
					'reason'              => $reason,
				],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function apply( string $surface, string $set_id, string $suggestion_key ): array {
		return [
			'type'          => 'apply_suggestion',
			'surface'       => $surface,
			'suggestionKey' => $suggestion_key,
			'request'       => [
				'recommendation' => [
					'recommendationSetId' => $set_id,
					'suggestionKey'       => $suggestion_key,
				],
			],
		];
	}
}
