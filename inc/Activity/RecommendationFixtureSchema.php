<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

use FlavorAgent\Support\RankingContract;
use FlavorAgent\Support\ValidationReason;

/** Complete, closed vocabulary for the local recommendation candidate. */
final class RecommendationFixtureSchema {

	public const VERSION        = 'recommendation-fixture-export-v1';
	public const MAX_ROWS       = 1000;
	public const APPLY_TYPES    = [
		'apply_suggestion',
		'apply_block_structural_suggestion',
		'apply_template_suggestion',
		'apply_template_part_suggestion',
		'apply_post_blocks_suggestion',
		'apply_global_styles_suggestion',
		'apply_style_book_suggestion',
	];
	public const METRICS        = [
		'reviewSelectionRate',
		'applyConversionRate',
		'patternInsertionRate',
		'undoRate',
		'validationBlockedRate',
		'insertFailedRate',
		'savePersistedRate',
		'saveDiscardedRate',
		'saveUnverifiableRate',
		'verificationCoverageRate',
	];
	public const EVIDENCE_KEYS  = [
		'prompt_match',
		'operation_fit',
		'supports_fit',
		'section_role_match',
		'docs_freshness',
		'pattern_readiness',
		'visible_scope_match',
		'native_preset_fit',
		'accessibility_fit',
		'design_semantics_fit',
	];
	public const PENALTY_KEYS   = [ 'possible_no_op', 'weak_prompt_match', 'unsupported_control', 'stale_docs', 'validation_risk' ];
	public const STATE_ENUMS    = [
		'executionResult'      => [ 'applied', 'blocked', 'diagnostic', 'failed', 'review', 'undone', 'pending', 'rejected', 'expired' ],
		'undoStatus'           => [ 'available', 'failed', 'not_applicable', 'review', 'undone' ],
		'persistenceVerdict'   => [ 'unknown', 'save_confirmed', 'save_discarded', 'save_unverifiable' ],
		'verificationCoverage' => [ 'compared', 'not_verified', 'not_eligible' ],
	];
	public const FIXED_REASONS  = [
		'user_dismissed',
		'review_opened',
		'recommendation_set_visible',
		'client',
		'advisory_only',
		'insert_blocks_success',
		'insert_blocks_wrong_target',
		'insert_blocks_noop',
		'insert_blocks_exception',
		'insertion_target_changed',
		'disallowed_block_types',
		'missing_resolved_context',
		'resolved_context_changed',
		'revalidation_failed',
		'empty_pattern_blocks',
		'adapted_preview_stale',
		'operation_validation_failed',
		'not_visible_in_inserter',
		'pattern_not_found',
		'adaptation_unavailable',
	];
	private const ALIASES       = [ 'row', 'set', 'suggestion', 'generation', 'sourceSignature', 'guideline', 'docsContent', 'docsRuntime', 'linkedApply', 'saveOccurrence' ];
	private const SAMPLE_COUNTS = [ 'rowLimit', 'eligibleRowCount', 'sampleSize', 'exportedRowCount', 'excludedRowCount', 'shownSetCount', 'shownSuggestionCount', 'unlinkedApplyCount', 'missingIdentityCount' ];

	public static function validate( mixed $candidate ): bool {
		if ( ! self::object_keys( $candidate, [ 'schemaVersion', 'provenance', 'sample', 'rows', 'coverage', 'metrics' ], [ 'schemaVersion', 'provenance', 'sample', 'rows', 'coverage', 'metrics' ] )
			|| self::VERSION !== $candidate['schemaVersion'] || ! self::provenance( $candidate['provenance'] ) ) {
			return false;
		}
		$sample = $candidate['sample'];
		if ( ! self::object_keys( $sample, [ ...self::SAMPLE_COUNTS, 'truncated' ], array_diff( [ ...self::SAMPLE_COUNTS, 'truncated' ], [ 'eligibleRowCount' ] ) ) || ! is_bool( $sample['truncated'] ) ) {
			return false;
		}
		foreach ( self::SAMPLE_COUNTS as $key ) {
			if ( array_key_exists( $key, $sample ) && ! self::count( $sample[ $key ] ) ) {
				return false;
			}
		}
		if ( $sample['rowLimit'] < 1 || $sample['rowLimit'] > self::MAX_ROWS || $sample['sampleSize'] > $sample['rowLimit']
			|| $sample['exportedRowCount'] + $sample['excludedRowCount'] !== $sample['sampleSize']
			|| ( isset( $sample['eligibleRowCount'] ) && $sample['eligibleRowCount'] < $sample['sampleSize'] )
			|| ! self::list( $candidate['rows'], self::MAX_ROWS ) || count( $candidate['rows'] ) !== $sample['exportedRowCount'] ) {
			return false;
		}
		$seen_rows = [];
		foreach ( $candidate['rows'] as $row ) {
			if ( ! self::row( $row ) || isset( $seen_rows[ $row['aliases']['row'] ] ) ) {
				return false;
			}
			$seen_rows[ $row['aliases']['row'] ] = true;
		}
		$coverage = $candidate['coverage'];
		if ( ! self::object_keys( $coverage, [ 'surfaces', 'missingShownLinks', 'missingApplyLinks', 'unverifiedCoverageCount' ], [ 'surfaces', 'missingShownLinks', 'missingApplyLinks', 'unverifiedCoverageCount' ] )
			|| ! self::surfaces( $coverage['surfaces'] ) ) {
			return false;
		}
		foreach ( [ 'missingShownLinks', 'missingApplyLinks', 'unverifiedCoverageCount' ] as $key ) {
			if ( ! self::count( $coverage[ $key ] ) ) {
				return false;
			}
		}
		if ( ! self::list( $candidate['metrics'], count( self::METRICS ) ) ) {
			return false;
		}
		$seen_metrics = [];
		foreach ( $candidate['metrics'] as $metric ) {
			if ( ! self::object_keys( $metric, [ 'name', 'numerator', 'denominator', 'value', 'coverage' ], [ 'name', 'numerator', 'denominator', 'coverage' ] )
				|| ! in_array( $metric['name'], self::METRICS, true ) || isset( $seen_metrics[ $metric['name'] ] )
				|| ! self::count( $metric['numerator'] ) || ! self::count( $metric['denominator'] )
				|| ! in_array( $metric['coverage'], [ 'complete_sample', 'missing_links', 'no_denominator' ], true ) ) {
				return false;
			}
			if ( 0 === $metric['denominator'] ) {
				if ( isset( $metric['value'] ) || array_key_exists( 'value', $metric ) || 'no_denominator' !== $metric['coverage'] ) {
					return false;
				}
			} elseif ( ! isset( $metric['value'] ) || ! self::number( $metric['value'], 0, self::MAX_ROWS )
				|| abs( $metric['value'] - round( $metric['numerator'] / $metric['denominator'], 4 ) ) > 0.00001
				|| 'no_denominator' === $metric['coverage'] ) {
				return false;
			}
			$seen_metrics[ $metric['name'] ] = true;
		}
		return true;
	}

	public static function provenance( mixed $value ): bool {
		if ( ! self::object_keys( $value, [ 'kind', 'implementationSha', 'publicVersions', 'config' ], [ 'kind', 'implementationSha', 'publicVersions', 'config' ] )
			|| ! in_array( $value['kind'], [ 'synthetic_fixture', 'local_runtime', 'real_site' ], true )
			|| ! is_string( $value['implementationSha'] ) || 1 !== preg_match( '/^[a-f0-9]{40}$/D', $value['implementationSha'] ) ) {
			return false;
		}
		$versions = $value['publicVersions'];
		if ( ! self::object_keys( $versions, [ 'plugin', 'wordpress', 'gutenberg', 'ranking', 'validationVocabulary', 'report' ], [ 'plugin', 'wordpress', 'ranking', 'validationVocabulary', 'report' ] ) ) {
			return false;
		}
		foreach ( [ 'plugin', 'wordpress', 'gutenberg' ] as $key ) {
			if ( isset( $versions[ $key ] ) && ( ! is_string( $versions[ $key ] ) || strlen( $versions[ $key ] ) > 32 || 1 !== preg_match( '/^\d+\.\d+(?:\.\d+)?$/D', $versions[ $key ] ) ) ) {
				return false;
			}
			if ( array_key_exists( $key, $versions ) && null === $versions[ $key ] ) {
				return false;
			}
		}
		return RankingContract::CONTEXTUAL_RANKING_VERSION === $versions['ranking']
			&& ValidationReason::VERSION === $versions['validationVocabulary'] && GovernanceLearningReport::VERSION === $versions['report']
			&& self::object_keys( $value['config'], [ 'surfaces', 'rankingMode' ], [ 'surfaces', 'rankingMode' ] )
			&& self::surfaces( $value['config']['surfaces'] ) && 'static' === $value['config']['rankingMode'];
	}

	public static function object_keys( mixed $value, array $allowed, array $required = [] ): bool {
		return is_array( $value ) && [] === array_diff( array_keys( $value ), $allowed ) && [] === array_diff( $required, array_keys( $value ) );
	}

	public static function surfaces( mixed $value ): bool {
		if ( ! self::list( $value, count( RecommendationOutcome::SURFACES ) ) ) {
			return false;
		}
		foreach ( $value as $surface ) {
			if ( ! is_string( $surface ) || ! in_array( $surface, RecommendationOutcome::SURFACES, true ) ) {
				return false;
			}
		}
		return count( array_unique( $value ) ) === count( $value );
	}

	public static function numeric_map( mixed $value, array $allowed ): bool {
		if ( ! self::object_keys( $value, $allowed ) ) {
			return false;
		}
		foreach ( $value as $number ) {
			if ( ! self::number( $number, 0, 1 ) ) {
				return false;
			}
		}
		return true;
	}

	public static function number( mixed $value, float $min = 0, float $max = 1 ): bool {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) && $value >= $min && $value <= $max;
	}

	private static function count( mixed $value ): bool {
		return is_int( $value ) && $value >= 0 && $value <= 2147483647;
	}

	private static function list( mixed $value, int $limit ): bool {
		return is_array( $value ) && array_is_list( $value ) && count( $value ) <= $limit;
	}

	private static function row( mixed $row ): bool {
		if ( ! self::object_keys( $row, [ 'aliases', 'surface', 'event', 'reason', 'state', 'ranking', 'validationReason', 'patternTraits' ], [ 'aliases', 'surface', 'event' ] )
			|| ! in_array( $row['surface'], RecommendationOutcome::SURFACES, true )
			|| ! in_array( $row['event'], [ ...RecommendationOutcome::EVENTS, ...self::APPLY_TYPES ], true )
			|| ! self::object_keys( $row['aliases'], self::ALIASES, [ 'row' ] ) ) {
			return false;
		}
		foreach ( $row['aliases'] as $key => $alias ) {
			if ( ! is_string( $alias ) || 1 !== preg_match( '/^' . $key . '-[a-f0-9]{16}-[1-9][0-9]{0,5}$/D', $alias ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'reason', $row ) && ! in_array( $row['reason'], [ ...self::FIXED_REASONS, ...array_keys( ValidationReason::vocabulary() ) ], true ) ) {
			return false;
		}
		if ( 'dismissed' === $row['event'] && ( 'user_dismissed' !== ( $row['reason'] ?? '' ) || ! isset( $row['aliases']['set'], $row['aliases']['suggestion'] ) ) ) {
			return false;
		}
		if ( array_key_exists( 'state', $row ) ) {
			if ( ! self::object_keys( $row['state'], array_keys( self::STATE_ENUMS ) ) ) {
				return false;
			}
			foreach ( $row['state'] as $key => $value ) {
				if ( ! in_array( $value, self::STATE_ENUMS[ $key ], true ) ) {
					return false;
				}
			}
		}
		if ( array_key_exists( 'ranking', $row ) ) {
			$ranking = $row['ranking'];
			if ( ! self::object_keys( $ranking, [ 'rank', 'score', 'contextScore', 'contextEvidence', 'contextPenalties' ] ) ) {
				return false;
			}
			foreach ( [ 'score', 'contextScore' ] as $key ) {
				if ( array_key_exists( $key, $ranking ) && ! self::number( $ranking[ $key ] ) ) {
					return false;
				}
			}
			if ( array_key_exists( 'rank', $ranking ) && ( ! is_int( $ranking['rank'] ) || $ranking['rank'] < 1 || $ranking['rank'] > self::MAX_ROWS ) ) {
				return false;
			}
			foreach ( [
				'contextEvidence'  => self::EVIDENCE_KEYS,
				'contextPenalties' => self::PENALTY_KEYS,
			] as $key => $allowed ) {
				if ( array_key_exists( $key, $ranking ) && ! self::numeric_map( $ranking[ $key ], $allowed ) ) {
					return false;
				}
			}
		}
		if ( array_key_exists( 'validationReason', $row ) && ( ! is_string( $row['validationReason'] ) || ! array_key_exists( $row['validationReason'], ValidationReason::vocabulary() ) ) ) {
			return false;
		}
		if ( array_key_exists( 'patternTraits', $row ) ) {
			if ( ! self::list( $row['patternTraits'], 8 ) ) {
				return false;
			}
			foreach ( $row['patternTraits'] as $trait ) {
				if ( ! in_array( $trait, RecommendationOutcome::PATTERN_TRAITS, true ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
