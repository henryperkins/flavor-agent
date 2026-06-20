<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

final class GovernanceLearningReport {

	public const VERSION = 'governance-learning-report-v1';

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<string, mixed>
	 */
	public static function build( array $entries, int $row_limit, bool $truncated ): array {
		$metrics = RecommendationOutcomeMetrics::evaluate( $entries );

		return [
			'version'     => self::VERSION,
			'generatedAt' => gmdate( 'c' ),
			'sampleSize'  => count( $entries ),
			'rowLimit'    => $row_limit,
			'truncated'   => $truncated,
			'summary'     => self::summary( $entries, $metrics ),
			'groups'      => self::groups( $entries ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @param array<string, mixed>             $metrics
	 * @return array<string, int|float>
	 */
	private static function summary( array $entries, array $metrics ): array {
		$apply_count          = 0;
		$undone_count         = 0;
		$insert_attempt_count = 0;
		$insert_failed_count  = 0;

		foreach ( $entries as $entry ) {
			if ( self::is_apply_entry( $entry ) ) {
				++$apply_count;

				$undo = is_array( $entry['undo'] ?? null ) ? $entry['undo'] : [];
				if ( 'undone' === (string) ( $undo['status'] ?? '' ) ) {
					++$undone_count;
				}

				continue;
			}

			$outcome = self::outcome( $entry );
			$event   = (string) ( $outcome['event'] ?? '' );

			if ( in_array( $event, [ 'pattern_inserted_from_shelf', 'insert_failed', 'adapted_inserted_from_preview', 'adapted_insert_failed' ], true ) ) {
				++$insert_attempt_count;
			}

			if ( in_array( $event, [ 'insert_failed', 'adapted_insert_failed' ], true ) ) {
				++$insert_failed_count;
			}
		}

		return [
			'shownCount'            => (int) ( $metrics['shownCount'] ?? 0 ),
			'reviewSelectionRate'   => self::metric_rate( $metrics['reviewSelectionRate'] ?? 0.0 ),
			'applyConversionRate'   => self::metric_rate( $metrics['applyConversionRate'] ?? 0.0 ),
			'undoRate'              => self::rate( $undone_count, $apply_count ),
			'validationBlockedRate' => self::metric_rate( $metrics['validationBlockedRate'] ?? 0.0 ),
			'insertFailedRate'      => self::rate( $insert_failed_count, $insert_attempt_count ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function groups( array $entries ): array {
		$buckets = [
			'surfaces'          => [],
			'operationTypes'    => [],
			'providerModels'    => [],
			'validationReasons' => [],
			'guidelineVersions' => [],
			'rankingSignals'    => [],
			'patternTraits'     => [],
		];

		foreach ( $entries as $entry ) {
			foreach ( self::entry_group_keys( $entry ) as $group => $keys ) {
				foreach ( $keys as $key ) {
					self::add_to_bucket( $buckets[ $group ], $key, $entry );
				}
			}
		}

		return [
			'surfaces'          => self::bucket_rows( $buckets['surfaces'] ),
			'operationTypes'    => self::bucket_rows( $buckets['operationTypes'] ),
			'providerModels'    => self::bucket_rows( $buckets['providerModels'] ),
			'validationReasons' => self::bucket_rows( $buckets['validationReasons'] ),
			'guidelineVersions' => self::bucket_rows( $buckets['guidelineVersions'] ),
			'rankingSignals'    => self::bucket_rows( $buckets['rankingSignals'] ),
			'patternTraits'     => self::bucket_rows( $buckets['patternTraits'] ),
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, array<int, string>>
	 */
	private static function entry_group_keys( array $entry ): array {
		$outcome     = self::outcome( $entry );
		$attribution = self::learning_attribution( $entry, $outcome );
		$provider    = self::bounded_key( $attribution['provider'] ?? '' );
		$model       = self::bounded_key( $attribution['model'] ?? '' );
		$groups      = [
			'surfaces'          => [],
			'operationTypes'    => [],
			'providerModels'    => [],
			'validationReasons' => [],
			'guidelineVersions' => [],
			'rankingSignals'    => [],
			'patternTraits'     => [],
		];

		$surface = self::bounded_key( $entry['surface'] ?? '' );
		if ( '' !== $surface ) {
			$groups['surfaces'][] = $surface;
		}

		$operation_type = self::operation_type( $entry );
		if ( '' !== $operation_type ) {
			$groups['operationTypes'][] = $operation_type;
		}

		if ( '' !== $provider || '' !== $model ) {
			$groups['providerModels'][] = '' !== $model ? $provider . '/' . $model : $provider;
		}

		$guideline_version = self::bounded_key( $attribution['guidelineVersion'] ?? '' );
		if ( '' !== $guideline_version ) {
			$groups['guidelineVersions'][] = $guideline_version;
		}

		if ( 'validation_blocked' === (string) ( $outcome['event'] ?? '' ) ) {
			$reason = self::bounded_key( $outcome['reason'] ?? '' );
			if ( '' !== $reason ) {
				$groups['validationReasons'][] = $reason;
			}
		}

		$groups['rankingSignals'] = self::ranking_signal_keys( $outcome );
		$groups['patternTraits']  = self::pattern_trait_keys( $outcome );

		return $groups;
	}

	/**
	 * @param array<string, mixed> $outcome
	 * @return array<int, string>
	 */
	private static function ranking_signal_keys( array $outcome ): array {
		$snapshots = [];

		if ( is_array( $outcome['ranking'] ?? null ) ) {
			$snapshots[] = $outcome['ranking'];
		}

		if ( is_array( $outcome['rankingSet'] ?? null ) ) {
			foreach ( $outcome['rankingSet'] as $item ) {
				if ( is_array( $item ) && is_array( $item['ranking'] ?? null ) ) {
					$snapshots[] = $item['ranking'];
				}
			}
		}

		$keys = [];
		foreach ( $snapshots as $ranking ) {
			foreach ( [ 'contextEvidence', 'contextPenalties' ] as $map_key ) {
				if ( ! is_array( $ranking[ $map_key ] ?? null ) ) {
					continue;
				}

				foreach ( array_keys( $ranking[ $map_key ] ) as $signal_key ) {
					$key = self::bounded_key( $signal_key );
					if ( '' !== $key ) {
						$keys[ $key ] = $key;
					}
				}
			}

			$ranking_version = self::bounded_key( $ranking['rankingVersion'] ?? '' );
			if ( '' !== $ranking_version ) {
				$key          = 'ranking-version:' . $ranking_version;
				$keys[ $key ] = $key;
			}
		}

		return array_values( $keys );
	}

	/**
	 * @param array<string, mixed> $outcome
	 * @return array<int, string>
	 */
	private static function pattern_trait_keys( array $outcome ): array {
		$traits = [];

		if ( is_array( $outcome['patternTraits'] ?? null ) ) {
			$traits = array_merge( $traits, $outcome['patternTraits'] );
		}

		if ( is_array( $outcome['rankingSet'] ?? null ) ) {
			foreach ( $outcome['rankingSet'] as $item ) {
				if ( is_array( $item ) && is_array( $item['patternTraits'] ?? null ) ) {
					$traits = array_merge( $traits, $item['patternTraits'] );
				}
			}
		}

		$keys = [];
		foreach ( $traits as $trait ) {
			$key = self::bounded_key( $trait );
			if ( '' !== $key ) {
				$keys[ $key ] = $key;
			}
		}

		return array_values( $keys );
	}

	/**
	 * @param array<string, array{key: string, entries: array<int, array<string, mixed>>, representativeActivityId: string}> $bucket
	 * @return array<int, array<string, mixed>>
	 */
	private static function bucket_rows( array $bucket ): array {
		$rows = [];

		foreach ( $bucket as $item ) {
			$entries = $item['entries'];
			$summary = self::summary( $entries, RecommendationOutcomeMetrics::evaluate( $entries ) );

			$rows[] = array_merge(
				[
					'key'                      => $item['key'],
					'label'                    => self::label( $item['key'] ),
					'sampleSize'               => count( $entries ),
					'representativeActivityId' => $item['representativeActivityId'],
				],
				self::counts( $entries ),
				$summary
			);
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$count_comparison = (int) ( $right['sampleSize'] ?? 0 ) <=> (int) ( $left['sampleSize'] ?? 0 );

				if ( 0 !== $count_comparison ) {
					return $count_comparison;
				}

				return strcmp( (string) ( $left['key'] ?? '' ), (string) ( $right['key'] ?? '' ) );
			}
		);

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<string, int>
	 */
	private static function counts( array $entries ): array {
		$counts = [
			'selectedForReviewCount' => 0,
			'appliedCount'           => 0,
			'undoneCount'            => 0,
			'staleBlockedCount'      => 0,
			'validationBlockedCount' => 0,
			'insertFailedCount'      => 0,
		];

		foreach ( $entries as $entry ) {
			if ( self::is_apply_entry( $entry ) ) {
				++$counts['appliedCount'];

				$undo = is_array( $entry['undo'] ?? null ) ? $entry['undo'] : [];
				if ( 'undone' === (string) ( $undo['status'] ?? '' ) ) {
					++$counts['undoneCount'];
				}

				continue;
			}

			$event = (string) ( self::outcome( $entry )['event'] ?? '' );

			if ( 'selected_for_review' === $event ) {
				++$counts['selectedForReviewCount'];
			} elseif ( 'stale_blocked' === $event ) {
				++$counts['staleBlockedCount'];
			} elseif ( 'validation_blocked' === $event ) {
				++$counts['validationBlockedCount'];
			} elseif ( in_array( $event, [ 'insert_failed', 'adapted_insert_failed' ], true ) ) {
				++$counts['insertFailedCount'];
			} elseif ( in_array( $event, [ 'pattern_inserted_from_shelf', 'adapted_inserted_from_preview' ], true ) ) {
				++$counts['appliedCount'];
			}
		}

		return $counts;
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
				'apply_block_suggestion',
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
	 * @return array<string, mixed>
	 */
	private static function outcome( array $entry ): array {
		return RecommendationOutcome::TYPE === (string) ( $entry['type'] ?? '' )
			&& is_array( $entry['after']['outcome'] ?? null )
				? $entry['after']['outcome']
				: [];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $outcome
	 * @return array<string, mixed>
	 */
	private static function learning_attribution( array $entry, array $outcome ): array {
		if ( is_array( $outcome['learningAttribution'] ?? null ) ) {
			return $outcome['learningAttribution'];
		}

		if ( is_array( $entry['request']['recommendation']['learningAttribution'] ?? null ) ) {
			return $entry['request']['recommendation']['learningAttribution'];
		}

		if ( is_array( $entry['request']['ai']['learningAttribution'] ?? null ) ) {
			return $entry['request']['ai']['learningAttribution'];
		}

		return [];
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function operation_type( array $entry ): string {
		$after = is_array( $entry['after'] ?? null ) ? $entry['after'] : [];

		if ( is_array( $after['operations'] ?? null ) && is_array( $after['operations'][0] ?? null ) ) {
			return self::bounded_key( $after['operations'][0]['type'] ?? '' );
		}

		$request = is_array( $entry['request'] ?? null ) ? $entry['request'] : [];
		if ( is_array( $request['apply']['operation'] ?? null ) ) {
			return self::bounded_key( $request['apply']['operation']['type'] ?? '' );
		}

		return '';
	}

	/**
	 * @param array<string, array{key: string, entries: array<int, array<string, mixed>>, representativeActivityId: string}> $bucket
	 * @param array<string, mixed> $entry
	 */
	private static function add_to_bucket( array &$bucket, string $key, array $entry ): void {
		if ( '' === $key ) {
			return;
		}

		if ( ! isset( $bucket[ $key ] ) ) {
			$bucket[ $key ] = [
				'key'                      => $key,
				'entries'                  => [],
				'representativeActivityId' => (string) ( $entry['id'] ?? '' ),
			];
		}

		$bucket[ $key ]['entries'][] = $entry;
	}

	private static function metric_rate( mixed $value ): float {
		return is_numeric( $value ) ? round( (float) $value, 4 ) : 0.0;
	}

	private static function rate( int $numerator, int $denominator ): float {
		if ( 0 === $denominator ) {
			return 0.0;
		}

		return round( $numerator / $denominator, 4 );
	}

	private static function bounded_key( mixed $value ): string {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return '';
		}

		$key = sanitize_key( (string) $value );

		return substr( $key, 0, 96 );
	}

	private static function label( string $key ): string {
		$label = str_replace( [ '-', '_', '/', ':' ], ' ', $key );
		$label = trim( preg_replace( '/\s+/', ' ', $label ) ?? $label );

		return '' !== $label ? ucwords( $label ) : $key;
	}
}
