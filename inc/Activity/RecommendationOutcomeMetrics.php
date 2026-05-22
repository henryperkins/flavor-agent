<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

final class RecommendationOutcomeMetrics {

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<string, float|int>
	 */
	public static function evaluate( array $entries ): array {
		$shown_sets                = [];
		$pattern_shown_sets        = [];
		$selected_sets             = [];
		$pattern_insert_sets       = [];
		$stale_blocked_events      = [];
		$validation_blocked_events = [];
		$attempted_events          = [];
		$linked_applied_sets       = [];
		$unlinked_apply_count      = 0;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( RecommendationOutcome::TYPE === (string) ( $entry['type'] ?? '' ) ) {
				$outcome = is_array( $entry['after']['outcome'] ?? null ) ? $entry['after']['outcome'] : [];
				$event   = (string) ( $outcome['event'] ?? '' );
				$surface = (string) ( $entry['surface'] ?? '' );
				$set_id  = (string) ( $outcome['recommendationSetId'] ?? $entry['target']['recommendationSetId'] ?? '' );

				if ( '' === $set_id ) {
					continue;
				}

				$set_key   = $surface . ':' . $set_id;
				$event_key = self::event_key( $entry, $outcome );

				if ( 'shown' === $event ) {
					$shown_sets[ $set_key ] = true;
					if ( 'pattern' === $surface ) {
						$pattern_shown_sets[ $set_key ] = true;
					}
					continue;
				}

				if ( 'selected_for_review' === $event ) {
					$selected_sets[ $set_key ]      = true;
					$attempted_events[ $event_key ] = true;
					continue;
				}

				if ( 'pattern_inserted_from_shelf' === $event ) {
					$pattern_insert_sets[ $set_key ] = true;
					$attempted_events[ $event_key ]  = true;
					continue;
				}

				if ( 'stale_blocked' === $event ) {
					$stale_blocked_events[ $event_key ] = true;
					$attempted_events[ $event_key ]     = true;
					continue;
				}

				if ( 'validation_blocked' === $event ) {
					$validation_blocked_events[ $event_key ] = true;
					$attempted_events[ $event_key ]          = true;
				}

				continue;
			}

			if ( ! self::is_apply_entry( $entry ) ) {
				continue;
			}

			$recommendation = is_array( $entry['request']['recommendation'] ?? null )
				? $entry['request']['recommendation']
				: [];
			$set_id         = (string) ( $recommendation['recommendationSetId'] ?? '' );
			$suggestion_key = (string) ( $recommendation['suggestionKey'] ?? $entry['suggestionKey'] ?? '' );

			if ( '' === $set_id || '' === $suggestion_key ) {
				++$unlinked_apply_count;
				continue;
			}

			$surface                         = (string) ( $entry['surface'] ?? '' );
			$set_key                         = $surface . ':' . $set_id;
			$key                             = $set_key . ':' . $suggestion_key;
			$linked_applied_sets[ $set_key ] = true;
			$attempted_events[ $key ]        = true;
		}

		$shown_count            = count( $shown_sets );
		$selected_count         = count( $selected_sets );
		$pattern_shown_count    = count( $pattern_shown_sets );
		$attempted_count        = count( $attempted_events );
		$shown_apply_set_count  = count( array_intersect_key( $linked_applied_sets, $shown_sets ) );
		$review_apply_set_count = count( array_intersect_key( $linked_applied_sets, $selected_sets ) );

		return [
			'shownCount'                => $shown_count,
			'reviewSelectionRate'       => self::rate( $selected_count, $shown_count ),
			'patternInsertionRate'      => self::rate( count( $pattern_insert_sets ), $pattern_shown_count ),
			'staleBlockedRate'          => self::rate( count( $stale_blocked_events ), $attempted_count ),
			'validationBlockedRate'     => self::rate( count( $validation_blocked_events ), $attempted_count ),
			'applyConversionRate'       => self::rate( $shown_apply_set_count, $shown_count ),
			'reviewApplyConversionRate' => self::rate( $review_apply_set_count, $selected_count ),
			'unlinkedApplyCount'        => $unlinked_apply_count,
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function is_apply_entry( array $entry ): bool {
		return in_array(
			(string) ( $entry['type'] ?? '' ),
			[
				'apply_suggestion',
				'apply_block_structural_suggestion',
				'apply_template_suggestion',
				'apply_template_part_suggestion',
				'apply_global_styles_suggestion',
				'apply_style_book_suggestion',
			],
			true
		);
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $outcome
	 */
	private static function event_key( array $entry, array $outcome ): string {
		return implode(
			':',
			[
				(string) ( $entry['surface'] ?? '' ),
				(string) ( $outcome['event'] ?? '' ),
				(string) ( $outcome['recommendationSetId'] ?? $entry['target']['recommendationSetId'] ?? '' ),
				(string) ( $entry['suggestionKey'] ?? $entry['target']['suggestionKey'] ?? '' ),
				(string) ( $outcome['reason'] ?? '' ),
			]
		);
	}

	private static function rate( int $numerator, int $denominator ): float {
		if ( 0 === $denominator ) {
			return 0.0;
		}

		return round( $numerator / $denominator, 4 );
	}
}
