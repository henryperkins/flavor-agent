<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\RecommendationOutcome;
use PHPUnit\Framework\TestCase;

final class RecommendationOutcomeTest extends TestCase {

	public function test_normalizes_privacy_safe_outcome_entry(): void {
		$entry = RecommendationOutcome::normalize_entry(
			[
				'type'       => 'recommendation_outcome',
				'surface'    => 'pattern',
				'suggestion' => str_repeat( 'Pattern inserted from shelf ', 10 ),
				'target'     => [
					'recommendationSetId' => 'set-1',
					'suggestionKey'       => 'suggestion-1',
					'patternKey'          => 'theme/hero',
					'patternTitle'        => 'Private launch hero',
					'rank'                => 2,
				],
				'after'      => [
					'outcome' => [
						'event'                  => 'pattern_inserted_from_shelf',
						'visibility'             => 'public',
						'recommendationSetId'    => 'set-1',
						'sourceRequestSignature' => 'hash_abc123',
						'reason'                 => 'insert blocks success',
						'topSuggestionKeys'      => [ 'a', 'b', 'c', 'd' ],
						'resultCount'            => 4,
						'rawPrompt'              => 'Make my private content better.',
					],
				],
				'request'    => [
					'prompt' => 'Make my private content better.',
				],
				'undo'       => [
					'status' => 'available',
				],
			]
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'recommendation_outcome', $entry['type'] );
		$this->assertSame( 'diagnostic', $entry['executionResult'] );
		$this->assertSame( [ 'status' => 'not_applicable' ], $entry['undo'] );
		$this->assertTrue( $entry['diagnostic'] );
		$this->assertSame( 'diagnostic', $entry['after']['outcome']['visibility'] );
		$this->assertSame( 'insert_blocks_success', $entry['after']['outcome']['reason'] );
		$this->assertSame( [ 'a', 'b', 'c' ], $entry['after']['outcome']['topSuggestionKeys'] );
		$this->assertArrayNotHasKey( 'patternTitle', $entry['target'] );
		$this->assertArrayNotHasKey( 'prompt', $entry['request'] );
		$this->assertArrayNotHasKey( 'rawPrompt', $entry['after']['outcome'] );
		$this->assertLessThanOrEqual( 96, strlen( $entry['suggestion'] ) );
	}

	public function test_rejects_unknown_outcome_event(): void {
		$result = RecommendationOutcome::normalize_entry(
			[
				'type'    => 'recommendation_outcome',
				'surface' => 'block',
				'after'   => [
					'outcome' => [
						'event' => 'dismissed',
					],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'flavor_agent_activity_invalid_outcome_event',
			$result->get_error_code()
		);
	}

	/**
	 * @dataProvider provide_adapted_events
	 */
	public function test_accepts_adapted_outcome_event( string $event, string $label ): void {
		$entry = RecommendationOutcome::normalize_entry(
			[
				'type'    => 'recommendation_outcome',
				'surface' => 'pattern',
				'after'   => [
					'outcome' => [
						'event'  => $event,
						'reason' => 'adapted preview stale',
					],
				],
			]
		);

		$this->assertIsArray( $entry );
		$this->assertSame( $event, $entry['after']['outcome']['event'] );
		$this->assertSame( $label, $entry['suggestion'] );
		$this->assertSame( 'adapted_preview_stale', $entry['after']['outcome']['reason'] );
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function provide_adapted_events(): array {
		return [
			[ 'adapted_preview_shown', 'Adapted pattern preview shown' ],
			[ 'adapted_inserted_from_preview', 'Adapted pattern inserted from preview' ],
			[ 'adaptation_blocked', 'Pattern adaptation blocked' ],
			[ 'adapted_insert_failed', 'Adapted pattern insertion failed' ],
		];
	}

	public function test_normalizes_insert_failure_outcome(): void {
		$result = RecommendationOutcome::normalize_entry(
			[
				'type'    => 'recommendation_outcome',
				'surface' => 'pattern',
				'target'  => [
					'recommendationSetId' => 'set-1',
					'suggestionKey'       => 'theme/hero',
				],
				'after'   => [
					'outcome' => [
						'event'               => 'insert_failed',
						'recommendationSetId' => 'set-1',
						'reason'              => 'insert_blocks_noop',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'insert_failed', $result['after']['outcome']['event'] );
		$this->assertSame( 'Pattern insertion failed', $result['suggestion'] );
		$this->assertSame( 'diagnostic', $result['executionResult'] );
		$this->assertSame( [ 'status' => 'not_applicable' ], $result['undo'] );
	}

	public function test_normalizes_bounded_ranking_snapshots_without_generated_text(): void {
		$result = RecommendationOutcome::normalize_entry(
			[
				'type'    => 'recommendation_outcome',
				'surface' => 'block',
				'target'  => [
					'recommendationSetId' => 'set-1',
				],
				'after'   => [
					'outcome' => [
						'event'               => 'shown',
						'recommendationSetId' => 'set-1',
						'rankingSet'          => [
							[
								'suggestionKey' => 'suggestion:1',
								'ranking'       => [
									'contextScore'     => 0.72,
									'contextEvidence'  => [
										'prompt_match' => 0.8,
										'rawText'      => 'Use secret launch copy',
									],
									'contextPenalties' => [
										'stale_docs' => 0.15,
									],
									'rankingVersion'   => 'contextual-ranking-v1',
								],
							],
							[
								'suggestionKey' => 'Use secret launch copy',
								'ranking'       => [
									'contextScore' => 1,
								],
							],
						],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['after']['outcome']['rankingSet'] );
		$this->assertSame( 'suggestion:1', $result['after']['outcome']['rankingSet'][0]['suggestionKey'] );
		$this->assertSame( 0.72, $result['after']['outcome']['rankingSet'][0]['ranking']['contextScore'] );
		$this->assertSame( [ 'prompt_match' => 0.8 ], $result['after']['outcome']['rankingSet'][0]['ranking']['contextEvidence'] );
		$this->assertSame( 'suggestion:2', $result['after']['outcome']['rankingSet'][1]['suggestionKey'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'launch', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'copy', wp_json_encode( $result ) );
	}

	public function test_keeps_shown_ranking_sets_separate_from_single_suggestion_ranking_snapshots(): void {
		$result = RecommendationOutcome::normalize_entry(
			[
				'type'    => 'recommendation_outcome',
				'surface' => 'block',
				'target'  => [
					'recommendationSetId' => 'set-1',
					'suggestionKey'       => 'block:suggestions:1',
				],
				'after'   => [
					'outcome' => [
						'event'               => 'shown',
						'recommendationSetId' => 'set-1',
						'ranking'             => [
							'contextScore'   => 0.99,
							'rankingVersion' => 'contextual-ranking-v1',
						],
						'rankingSet'          => [
							[
								'suggestionKey' => 'block:suggestions:1',
								'ranking'       => [
									'contextScore'   => 0.72,
									'blendedScore'   => 0.81,
									'rankingVersion' => 'contextual-ranking-v1',
								],
							],
						],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'rankingSet', $result['after']['outcome'] );
		$this->assertArrayNotHasKey( 'ranking', $result['after']['outcome'] );
		$this->assertArrayHasKey( 'rankingSet', $result['request']['recommendation'] );
		$this->assertArrayNotHasKey( 'ranking', $result['request']['recommendation'] );
		$this->assertSame( 0.72, $result['after']['outcome']['rankingSet'][0]['ranking']['contextScore'] );
	}

	public function test_keeps_selected_ranking_snapshots_separate_from_aggregate_ranking_sets(): void {
		$result = RecommendationOutcome::normalize_entry(
			[
				'type'    => 'recommendation_outcome',
				'surface' => 'block',
				'target'  => [
					'recommendationSetId' => 'set-1',
					'suggestionKey'       => 'block:suggestions:1',
				],
				'after'   => [
					'outcome' => [
						'event'               => 'selected_for_review',
						'recommendationSetId' => 'set-1',
						'ranking'             => [
							'contextScore'   => 0.72,
							'blendedScore'   => 0.81,
							'rankingVersion' => 'contextual-ranking-v1',
						],
						'rankingSet'          => [
							[
								'suggestionKey' => 'block:suggestions:1',
								'ranking'       => [
									'contextScore' => 0.99,
								],
							],
						],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ranking', $result['after']['outcome'] );
		$this->assertArrayNotHasKey( 'rankingSet', $result['after']['outcome'] );
		$this->assertArrayHasKey( 'ranking', $result['request']['recommendation'] );
		$this->assertArrayNotHasKey( 'rankingSet', $result['request']['recommendation'] );
		$this->assertSame( 0.72, $result['after']['outcome']['ranking']['contextScore'] );
	}

	public function test_replaces_prose_like_ranking_set_keys_with_set_local_fallbacks(): void {
		$result = RecommendationOutcome::normalize_entry(
			[
				'type'    => 'recommendation_outcome',
				'surface' => 'block',
				'target'  => [
					'recommendationSetId' => 'set-1',
				],
				'after'   => [
					'outcome' => [
						'event'               => 'shown',
						'recommendationSetId' => 'set-1',
						'rankingSet'          => [
							[
								'suggestionKey' => 'use-secret-launch-copy',
								'ranking'       => [
									'contextScore'   => 0.72,
									'rankingVersion' => 'contextual-ranking-v1',
								],
							],
							[
								'suggestionKey' => 'hash_abc123',
								'ranking'       => [
									'contextScore'   => 0.64,
									'rankingVersion' => 'contextual-ranking-v1',
								],
							],
						],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'suggestion:1', $result['after']['outcome']['rankingSet'][0]['suggestionKey'] );
		$this->assertSame( 'hash_abc123', $result['after']['outcome']['rankingSet'][1]['suggestionKey'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $result['after']['outcome'] ) );
		$this->assertStringNotContainsString( 'launch', wp_json_encode( $result['after']['outcome'] ) );
		$this->assertStringNotContainsString( 'copy', wp_json_encode( $result['after']['outcome'] ) );
	}

	public function test_ranking_set_item_carries_validation_reason_and_version(): void {
		$entry = $this->shownEntry(
			[
				'rankingSet' => [
					[
						'suggestionKey'               => 'block:block:1',
						'ranking'                     => [ 'blendedScore' => 0.5 ],
						'validationReason'            => 'failed_contrast',
						'validationVocabularyVersion' => 'validation-reasons-v1',
					],
				],
			]
		);

		$out  = RecommendationOutcome::normalize_entry( $entry );
		$item = $out['after']['outcome']['rankingSet'][0];

		$this->assertSame( 'failed_contrast', $item['validationReason'] );
		$this->assertSame( 'validation-reasons-v1', $item['validationVocabularyVersion'] );
	}

	public function test_validation_blocked_keeps_primary_reason_and_version(): void {
		$entry = $this->outcomeEntry(
			'validation_blocked',
			[
				'reason'                      => 'unsupported_path',
				'validationVocabularyVersion' => 'validation-reasons-v1',
			]
		);
		$out   = RecommendationOutcome::normalize_entry( $entry );

		$this->assertSame( 'unsupported_path', $out['after']['outcome']['reason'] );
		$this->assertSame( 'validation-reasons-v1', $out['after']['outcome']['validationVocabularyVersion'] );
	}

	public function test_selected_for_review_carries_sibling_validation_reason_without_touching_reason_slot(): void {
		$entry = $this->outcomeEntry(
			'selected_for_review',
			[
				'reason'                      => 'review_opened',
				'validationReason'            => 'failed_contrast',
				'validationVocabularyVersion' => 'validation-reasons-v1',
			]
		);
		$out   = RecommendationOutcome::normalize_entry( $entry );

		$this->assertSame( 'review_opened', $out['after']['outcome']['reason'] ); // dedupe-bearing slot unchanged.
		$this->assertSame( 'failed_contrast', $out['after']['outcome']['validationReason'] ); // sibling.
		// The client co-locates the vocab version with the sibling reason; it must round-trip.
		$this->assertSame( 'validation-reasons-v1', $out['after']['outcome']['validationVocabularyVersion'] );
	}

	public function test_learning_attribution_preserves_join_fields_without_private_payloads(): void {
		$entry = $this->outcomeEntry(
			'selected_for_review',
			[
				'reason'              => 'review_opened',
				'learningAttribution' => [
					'generationId'                => 'recgen:block:11111111-1111-4111-8111-111111111111',
					'recommendationSetId'         => 'set-1',
					'sourceRequestSignature'      => 'hash_source',
					'guidelineVersion'            => 'gv1:abc123',
					'docsContentFingerprint'      => 'docs-content:v1',
					'docsRuntimeFingerprint'      => 'docs-runtime:v1',
					'provider'                    => 'anthropic',
					'model'                       => 'claude-sonnet-4-6',
					'rankingVersion'              => 'contextual-ranking-v1',
					'validationVocabularyVersion' => 'validation-reasons-v1',
					'rawPrompt'                   => 'Use secret launch copy.',
				],
			]
		);
		$out   = RecommendationOutcome::normalize_entry( $entry );

		$expected = [
			'generationId'                => 'recgen:block:11111111-1111-4111-8111-111111111111',
			'recommendationSetId'         => 'set-1',
			'sourceRequestSignature'      => 'hash_source',
			'guidelineVersion'            => 'gv1:abc123',
			'docsContentFingerprint'      => 'docs-content:v1',
			'docsRuntimeFingerprint'      => 'docs-runtime:v1',
			'provider'                    => 'anthropic',
			'model'                       => 'claude-sonnet-4-6',
			'rankingVersion'              => 'contextual-ranking-v1',
			'validationVocabularyVersion' => 'validation-reasons-v1',
		];

		$this->assertArrayHasKey( 'learningAttribution', $out['after']['outcome'] );
		$this->assertArrayHasKey( 'learningAttribution', $out['request']['recommendation'] );
		$this->assertSame( $expected, $out['after']['outcome']['learningAttribution'] );
		$this->assertSame( $expected, $out['request']['recommendation']['learningAttribution'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $out['after']['outcome']['learningAttribution'] ) );
		$this->assertStringNotContainsString( 'launch', wp_json_encode( $out['after']['outcome']['learningAttribution'] ) );
		$this->assertStringNotContainsString( 'copy', wp_json_encode( $out['after']['outcome']['learningAttribution'] ) );
	}

	public function test_learning_attribution_without_generation_id_is_dropped(): void {
		$entry = $this->outcomeEntry(
			'selected_for_review',
			[
				'reason'              => 'review_opened',
				'learningAttribution' => [
					'recommendationSetId' => 'set-1',
					'provider'            => 'anthropic',
					'model'               => 'claude-sonnet-4-6',
				],
			]
		);
		$out   = RecommendationOutcome::normalize_entry( $entry );

		$this->assertArrayNotHasKey( 'learningAttribution', $out['after']['outcome'] );
		$this->assertArrayNotHasKey( 'learningAttribution', $out['request']['recommendation'] );
	}

	/**
	 * @param array<string, mixed> $outcome
	 * @return array<string, mixed>
	 */
	private function shownEntry( array $outcome ): array {
		return [
			'type'    => 'recommendation_outcome',
			'surface' => 'block',
			'target'  => [
				'recommendationSetId' => 'set-1',
			],
			'after'   => [
				'outcome' => array_merge(
					[
						'event'               => 'shown',
						'recommendationSetId' => 'set-1',
					],
					$outcome
				),
			],
		];
	}

	/**
	 * @param array<string, mixed> $outcome
	 * @return array<string, mixed>
	 */
	private function outcomeEntry( string $event, array $outcome ): array {
		return [
			'type'    => 'recommendation_outcome',
			'surface' => 'block',
			'target'  => [
				'recommendationSetId' => 'set-1',
				'suggestionKey'       => 'block:suggestions:1',
			],
			'after'   => [
				'outcome' => array_merge(
					[
						'event'               => $event,
						'recommendationSetId' => 'set-1',
					],
					$outcome
				),
			],
		];
	}
}
