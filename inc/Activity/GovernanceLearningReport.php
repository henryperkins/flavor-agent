<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

final class GovernanceLearningReport {

	public const VERSION = 'governance-learning-report-v1';

	private const DEFAULT_ROW_LIMIT = 500;
	private const MAX_ROW_LIMIT     = 1000;

	private const APPLY_TYPES = [
		'apply_suggestion',
		'apply_block_structural_suggestion',
		'apply_template_suggestion',
		'apply_template_part_suggestion',
		'apply_post_blocks_suggestion',
		'apply_global_styles_suggestion',
		'apply_style_book_suggestion',
	];

	private const PATTERN_APPLIED_EVENTS = [
		'pattern_inserted_from_shelf',
		'adapted_inserted_from_preview',
	];

	private const PATTERN_INSERT_FAILED_EVENTS = [
		'insert_failed',
		'adapted_insert_failed',
	];

	/**
	 * @param array<int, mixed>         $entries
	 * @param array<string, mixed>      $args
	 * @return array<string, mixed>
	 */
	public static function build( array $entries, array $args = [] ): array {
		$sample = array_values(
			array_filter(
				$entries,
				static fn ( mixed $entry ): bool => is_array( $entry )
			)
		);

		$row_limit = self::normalize_row_limit( $args['rowLimit'] ?? self::DEFAULT_ROW_LIMIT );

		return [
			'version'     => self::VERSION,
			'generatedAt' => self::normalize_generated_at( $args['generatedAt'] ?? '' ),
			'sampleSize'  => count( $sample ),
			'rowLimit'    => $row_limit,
			'truncated'   => true === ( $args['truncated'] ?? false ),
			'summary'     => self::build_summary( $sample ),
			'groups'      => [
				'surfaces'          => self::build_group_rows( $sample, [ __CLASS__, 'surface_groups' ] ),
				'operationTypes'    => self::build_group_rows( $sample, [ __CLASS__, 'operation_type_groups' ] ),
				'providerModels'    => self::build_group_rows( $sample, [ __CLASS__, 'provider_model_groups' ] ),
				'validationReasons' => self::build_group_rows( $sample, [ __CLASS__, 'validation_reason_groups' ] ),
				'guidelineVersions' => self::build_group_rows( $sample, [ __CLASS__, 'guideline_version_groups' ] ),
				'rankingSignals'    => self::build_group_rows( $sample, [ __CLASS__, 'ranking_signal_groups' ] ),
				'patternTraits'     => self::build_group_rows( $sample, [ __CLASS__, 'pattern_trait_groups' ] ),
			],
		];
	}

	private static function normalize_row_limit( mixed $value ): int {
		$limit = is_numeric( $value ) ? (int) $value : self::DEFAULT_ROW_LIMIT;

		if ( $limit <= 0 ) {
			return self::DEFAULT_ROW_LIMIT;
		}

		return min( self::MAX_ROW_LIMIT, $limit );
	}

	private static function normalize_generated_at( mixed $value ): string {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return substr( sanitize_text_field( $value ), 0, 64 );
		}

		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<string, int|float>
	 */
	private static function build_summary( array $entries ): array {
		$metrics = RecommendationOutcomeMetrics::evaluate( $entries );
		$counts  = self::count_entries( $entries );

		return [
			'shownCount'            => (int) ( $metrics['shownCount'] ?? 0 ),
			'reviewSelectionRate'   => (float) ( $metrics['reviewSelectionRate'] ?? 0.0 ),
			'applyConversionRate'   => (float) ( $metrics['applyConversionRate'] ?? 0.0 ),
			'undoRate'              => self::rate( $counts['undoneApplyCount'], $counts['applyCount'] ),
			'validationBlockedRate' => (float) ( $metrics['validationBlockedRate'] ?? 0.0 ),
			'insertFailedRate'      => self::rate( $counts['insertFailedCount'], $counts['patternEngagementAttemptCount'] ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @return array{
	 *   selectedForReviewCount: int,
	 *   appliedCount: int,
	 *   applyCount: int,
	 *   undoneApplyCount: int,
	 *   staleBlockedCount: int,
	 *   validationBlockedCount: int,
	 *   insertFailedCount: int,
	 *   patternEngagementAttemptCount: int
	 * }
	 */
	private static function count_entries( array $entries ): array {
		$selected_sets = [];
		$counts        = [
			'selectedForReviewCount'        => 0,
			'appliedCount'                  => 0,
			'applyCount'                    => 0,
			'undoneApplyCount'              => 0,
			'staleBlockedCount'             => 0,
			'validationBlockedCount'        => 0,
			'insertFailedCount'             => 0,
			'patternEngagementAttemptCount' => 0,
		];

		foreach ( $entries as $entry ) {
			if ( self::is_apply_entry( $entry ) ) {
				++$counts['appliedCount'];
				++$counts['applyCount'];
				if ( self::is_undone_entry( $entry ) ) {
					++$counts['undoneApplyCount'];
				}
				continue;
			}

			$outcome = self::outcome_payload( $entry );
			$event   = (string) ( $outcome['event'] ?? '' );

			if ( '' === $event ) {
				continue;
			}

			$set_key = self::entry_set_key( $entry, $outcome );
			if ( 'selected_for_review' === $event && '' !== $set_key ) {
				$selected_sets[ $set_key ] = true;
			}

			if ( 'stale_blocked' === $event ) {
				++$counts['staleBlockedCount'];
			}

			if ( 'validation_blocked' === $event ) {
				++$counts['validationBlockedCount'];
			}

			if ( in_array( $event, self::PATTERN_APPLIED_EVENTS, true ) ) {
				++$counts['appliedCount'];
				++$counts['patternEngagementAttemptCount'];
			}

			if ( in_array( $event, self::PATTERN_INSERT_FAILED_EVENTS, true ) ) {
				++$counts['insertFailedCount'];
				++$counts['patternEngagementAttemptCount'];
			}
		}

		$counts['selectedForReviewCount'] = count( $selected_sets );

		return $counts;
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @param callable(array<string, mixed>): array<string, string> $group_callback
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_group_rows( array $entries, callable $group_callback ): array {
		$groups = [];

		foreach ( $entries as $entry ) {
			$entry_groups = $group_callback( $entry );
			foreach ( $entry_groups as $key => $label ) {
				if ( '' === $key || '' === $label ) {
					continue;
				}

				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = [
						'key'                      => $key,
						'label'                    => $label,
						'entries'                  => [],
						'representativeActivityId' => self::activity_id( $entry ),
					];
				}

				$groups[ $key ]['entries'][] = $entry;
			}
		}

		$rows = [];
		foreach ( $groups as $group ) {
			$group_entries = $group['entries'];
			$metrics       = RecommendationOutcomeMetrics::evaluate( $group_entries );
			$counts        = self::count_entries( $group_entries );
			$row           = [
				'key'                    => $group['key'],
				'label'                  => $group['label'],
				'sampleSize'             => count( $group_entries ),
				'shownCount'             => (int) ( $metrics['shownCount'] ?? 0 ),
				'selectedForReviewCount' => $counts['selectedForReviewCount'],
				'appliedCount'           => $counts['appliedCount'],
				'undoneCount'            => $counts['undoneApplyCount'],
				'staleBlockedCount'      => $counts['staleBlockedCount'],
				'validationBlockedCount' => $counts['validationBlockedCount'],
				'insertFailedCount'      => $counts['insertFailedCount'],
				'reviewSelectionRate'    => (float) ( $metrics['reviewSelectionRate'] ?? 0.0 ),
				'applyConversionRate'    => (float) ( $metrics['applyConversionRate'] ?? 0.0 ),
				'undoRate'               => self::rate( $counts['undoneApplyCount'], $counts['applyCount'] ),
				'validationBlockedRate'  => (float) ( $metrics['validationBlockedRate'] ?? 0.0 ),
				'insertFailedRate'       => self::rate( $counts['insertFailedCount'], $counts['patternEngagementAttemptCount'] ),
			];

			if ( '' !== $group['representativeActivityId'] ) {
				$row['representativeActivityId'] = $group['representativeActivityId'];
			}

			$rows[] = $row;
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				if ( (int) $left['sampleSize'] !== (int) $right['sampleSize'] ) {
					return (int) $right['sampleSize'] <=> (int) $left['sampleSize'];
				}

				return strcmp( (string) $left['key'], (string) $right['key'] );
			}
		);

		return $rows;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, string>
	 */
	private static function surface_groups( array $entry ): array {
		$key = sanitize_key( (string) ( $entry['surface'] ?? '' ) );

		return '' !== $key ? [ $key => self::labelize( $key ) ] : [];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, string>
	 */
	private static function operation_type_groups( array $entry ): array {
		$admin = is_array( $entry['admin'] ?? null ) ? $entry['admin'] : [];
		$key   = sanitize_key( (string) ( $admin['operationType'] ?? $entry['operationType'] ?? $entry['type'] ?? '' ) );
		$label = sanitize_text_field( (string) ( $admin['operationTypeLabel'] ?? '' ) );

		return '' !== $key ? [ $key => ( '' !== $label ? $label : self::labelize( $key ) ) ] : [];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, string>
	 */
	private static function provider_model_groups( array $entry ): array {
		$admin       = is_array( $entry['admin'] ?? null ) ? $entry['admin'] : [];
		$attribution = self::learning_attribution( $entry );
		$provider    = self::bounded_string( $attribution['provider'] ?? $admin['provider'] ?? '' );
		$model       = self::bounded_string( $attribution['model'] ?? $admin['model'] ?? '' );

		if ( '' === $provider && '' === $model ) {
			return [];
		}

		$key   = sanitize_key( $provider ) . ':' . sanitize_key( $model );
		$label = trim( ( '' !== $provider ? self::labelize( $provider ) : '' ) . ( '' !== $provider && '' !== $model ? ' / ' : '' ) . $model );

		return '' !== trim( $key, ':' ) ? [ $key => $label ] : [];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, string>
	 */
	private static function validation_reason_groups( array $entry ): array {
		$outcome = self::outcome_payload( $entry );
		if ( 'validation_blocked' !== (string) ( $outcome['event'] ?? '' ) ) {
			return [];
		}

		$key = sanitize_key( (string) ( $outcome['reason'] ?? '' ) );

		return '' !== $key ? [ $key => self::labelize( $key ) ] : [];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, string>
	 */
	private static function guideline_version_groups( array $entry ): array {
		$attribution = self::learning_attribution( $entry );
		$key         = self::bounded_string( $attribution['guidelineVersion'] ?? '' );

		return '' !== $key ? [ $key => $key ] : [];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, string>
	 */
	private static function ranking_signal_groups( array $entry ): array {
		$outcome  = self::outcome_payload( $entry );
		$rankings = [];

		if ( is_array( $outcome['ranking'] ?? null ) ) {
			$rankings[] = $outcome['ranking'];
		}

		if ( is_array( $outcome['rankingSet'] ?? null ) ) {
			foreach ( $outcome['rankingSet'] as $item ) {
				if ( is_array( $item['ranking'] ?? null ) ) {
					$rankings[] = $item['ranking'];
				}
			}
		}

		$groups = [];
		foreach ( $rankings as $ranking ) {
			foreach ( [ 'contextEvidence', 'contextPenalties' ] as $map_key ) {
				if ( ! is_array( $ranking[ $map_key ] ?? null ) ) {
					continue;
				}

				foreach ( array_keys( $ranking[ $map_key ] ) as $signal ) {
					$key = sanitize_key( (string) $signal );
					if ( '' !== $key ) {
						$groups[ $key ] = self::labelize( $key );
					}
				}
			}

			$version = sanitize_key( (string) ( $ranking['rankingVersion'] ?? '' ) );
			if ( '' !== $version ) {
				$groups[ $version ] = $version;
			}
		}

		return $groups;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, string>
	 */
	private static function pattern_trait_groups( array $entry ): array {
		$outcome = self::outcome_payload( $entry );
		$traits  = [];

		if ( is_array( $outcome['patternTraits'] ?? null ) ) {
			$traits = array_merge( $traits, $outcome['patternTraits'] );
		}

		if ( is_array( $outcome['rankingSet'] ?? null ) ) {
			foreach ( $outcome['rankingSet'] as $item ) {
				if ( is_array( $item['patternTraits'] ?? null ) ) {
					$traits = array_merge( $traits, $item['patternTraits'] );
				}
			}
		}

		$groups = [];
		foreach ( $traits as $trait ) {
			$key = sanitize_key( (string) $trait );
			if ( '' !== $key ) {
				$groups[ $key ] = self::labelize( $key );
			}
		}

		return $groups;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function outcome_payload( array $entry ): array {
		return is_array( $entry['after']['outcome'] ?? null ) ? $entry['after']['outcome'] : [];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function learning_attribution( array $entry ): array {
		$outcome = self::outcome_payload( $entry );
		if ( is_array( $outcome['learningAttribution'] ?? null ) ) {
			return $outcome['learningAttribution'];
		}

		if ( is_array( $entry['request']['recommendation']['learningAttribution'] ?? null ) ) {
			return $entry['request']['recommendation']['learningAttribution'];
		}

		return is_array( $entry['learningAttribution'] ?? null ) ? $entry['learningAttribution'] : [];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $outcome
	 */
	private static function entry_set_key( array $entry, array $outcome ): string {
		$surface = (string) ( $entry['surface'] ?? '' );
		$set_id  = (string) ( $outcome['recommendationSetId'] ?? $entry['target']['recommendationSetId'] ?? '' );

		return '' !== $surface && '' !== $set_id ? $surface . ':' . $set_id : '';
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function is_apply_entry( array $entry ): bool {
		return in_array( (string) ( $entry['type'] ?? '' ), self::APPLY_TYPES, true );
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function is_undone_entry( array $entry ): bool {
		return 'undone' === (string) ( $entry['status'] ?? '' )
			|| 'undone' === (string) ( $entry['undo']['status'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function activity_id( array $entry ): string {
		return self::bounded_string( $entry['id'] ?? '' );
	}

	private static function bounded_string( mixed $value ): string {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return '';
		}

		return substr( sanitize_text_field( (string) $value ), 0, 191 );
	}

	private static function labelize( string $key ): string {
		$label = str_replace( [ '-', '_' ], ' ', $key );

		return ucfirst( strtolower( $label ) );
	}

	private static function rate( int $numerator, int $denominator ): float {
		if ( 0 === $denominator ) {
			return 0.0;
		}

		return round( $numerator / $denominator, 4 );
	}
}
