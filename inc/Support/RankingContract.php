<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class RankingContract {

	public const CONTEXTUAL_RANKING_VERSION = 'contextual-ranking-v1';

	private const CONTEXTUAL_SCORE_KEYS = [
		'modelScore',
		'deterministicScore',
		'contextScore',
		'blendedScore',
	];

	private const CONTEXT_EVIDENCE_KEYS = [
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
		'contrast_preserved',
		'preset_adherence',
		'spacing_scale_fit',
		'typography_readability',
		'responsive_sanity',
		'complexity_fit',
	];

	private const CONTEXT_PENALTY_KEYS = [
		'possible_no_op',
		'weak_prompt_match',
		'unsupported_control',
		'stale_docs',
		'validation_risk',
		'failed_contrast',
		'raw_value_when_preset_available',
		'duplicate_or_noop',
		'responsive_visibility_risk',
		'excessive_visual_complexity',
	];

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $defaults
	 * @return array<string, mixed>
	 */
	public static function normalize( array $input, array $defaults = [] ): array {
		$score = self::resolve_score_candidate(
			$input['score'] ?? null,
			$input['confidence'] ?? null,
			$defaults['score'] ?? null,
			$defaults['confidence'] ?? null
		) ?? 0.0;

		$reason = sanitize_text_field(
			(string) ( $input['reason'] ?? $defaults['reason'] ?? '' )
		);

		$source_signals = self::merge_source_signals(
			$defaults['sourceSignals'] ?? [],
			$input['sourceSignals'] ?? []
		);

		$safety_mode = sanitize_key(
			(string) ( $input['safetyMode'] ?? $defaults['safetyMode'] ?? 'standard' )
		);

		if ( '' === $safety_mode ) {
			$safety_mode = 'standard';
		}

		$freshness_meta = self::normalize_freshness_meta(
			$input['freshnessMeta'] ?? $defaults['freshnessMeta'] ?? []
		);

		$contract = [
			'score'         => $score,
			'reason'        => $reason,
			'sourceSignals' => $source_signals,
			'safetyMode'    => $safety_mode,
			'freshnessMeta' => $freshness_meta,
		];

		foreach ( [ 'designPrinciple', 'risk' ] as $context_key ) {
			$context_value = sanitize_text_field(
				(string) ( $input[ $context_key ] ?? $defaults[ $context_key ] ?? '' )
			);

			if ( '' !== $context_value ) {
				$contract[ $context_key ] = $context_value;
			}
		}

		$operations = self::normalize_operations( $input['operations'] ?? $defaults['operations'] ?? null );
		if ( null !== $operations ) {
			$contract['operations'] = $operations;
		}

		$ranking_hint = self::normalize_ranking_hint( $input['rankingHint'] ?? null, $defaults['rankingHint'] ?? null );
		if ( [] !== $ranking_hint ) {
			$contract['rankingHint'] = $ranking_hint;
		}

		$advisory_type = sanitize_key(
			(string) ( $input['advisoryType'] ?? $defaults['advisoryType'] ?? '' )
		);
		if ( '' !== $advisory_type ) {
			$contract['advisoryType'] = $advisory_type;
		}

		foreach ( self::CONTEXTUAL_SCORE_KEYS as $component_key ) {
			if ( isset( $defaults[ $component_key ] ) && is_scalar( $defaults[ $component_key ] ) && is_numeric( $defaults[ $component_key ] ) ) {
				$contract[ $component_key ] = self::coerce_score( $defaults[ $component_key ] );
			}
		}

		$context_evidence = self::normalize_numeric_ranking_map( $defaults['contextEvidence'] ?? null, 'evidence' );
		if ( [] !== $context_evidence ) {
			$contract['contextEvidence'] = $context_evidence;
		}

		$context_penalties = self::normalize_numeric_ranking_map( $defaults['contextPenalties'] ?? null, 'penalty' );
		if ( [] !== $context_penalties ) {
			$contract['contextPenalties'] = $context_penalties;
		}

		$ranking_version = sanitize_key( (string) ( $defaults['rankingVersion'] ?? '' ) );
		if ( '' !== $ranking_version ) {
			$contract['rankingVersion'] = $ranking_version;
		}

		return $contract;
	}

	public static function resolve_score_candidate( mixed ...$candidates ): ?float {
		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate ) && is_numeric( $candidate ) ) {
				return self::coerce_score( $candidate );
			}
		}

		return null;
	}

	/**
	 * @param array<string, float|int> $signals
	 */
	public static function derive_score( float $baseline, array $signals ): float {
		$score = $baseline;

		foreach ( $signals as $signal ) {
			if ( is_bool( $signal ) ) {
				continue;
			}

			$score += (float) $signal;
		}

		return self::coerce_score( $score );
	}

	/**
	 * @param array{model?: mixed, deterministic?: mixed, context?: mixed} $components
	 * @param array{model?: float, deterministic?: float, context?: float} $weights
	 */
	public static function blend_score( array $components, array $weights = [] ): float {
		$weights = array_merge(
			[
				'model'         => 0.30,
				'deterministic' => 0.45,
				'context'       => 0.25,
			],
			$weights
		);

		$weighted_score = 0.0;
		$total_weight   = 0.0;

		foreach ( [ 'model', 'deterministic', 'context' ] as $component ) {
			$value  = $components[ $component ] ?? null;
			$weight = (float) ( $weights[ $component ] ?? 0.0 );

			if ( $weight <= 0.0 || ! is_scalar( $value ) || ! is_numeric( $value ) ) {
				continue;
			}

			$weighted_score += self::coerce_score( $value ) * $weight;
			$total_weight   += $weight;
		}

		if ( $total_weight <= 0.0 ) {
			return 0.0;
		}

		return self::coerce_score( round( $weighted_score / $total_weight, 4 ) );
	}

	/**
	 * @param array{score?: mixed, evidence?: mixed, penalties?: mixed}|null $contextual_result
	 * @return array<string, mixed>
	 */
	public static function contextual_component_defaults( ?float $model_score, float $deterministic_score, ?array $contextual_result, float $blended_score ): array {
		$defaults = [
			'deterministicScore' => $deterministic_score,
			'blendedScore'       => $blended_score,
		];

		if ( null !== $model_score ) {
			$defaults['modelScore'] = $model_score;
		}

		if ( is_array( $contextual_result ) ) {
			$defaults['contextScore']     = self::resolve_score_candidate( $contextual_result['score'] ?? null ) ?? 0.0;
			$defaults['contextEvidence']  = is_array( $contextual_result['evidence'] ?? null ) ? $contextual_result['evidence'] : [];
			$defaults['contextPenalties'] = is_array( $contextual_result['penalties'] ?? null ) ? $contextual_result['penalties'] : [];
			$defaults['rankingVersion']   = self::CONTEXTUAL_RANKING_VERSION;
		}

		return $defaults;
	}

	/**
	 * @return array<string, float>
	 */
	public static function normalize_numeric_ranking_map( mixed $value, string $kind = 'evidence' ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$allowed    = 'penalty' === $kind ? self::CONTEXT_PENALTY_KEYS : self::CONTEXT_EVIDENCE_KEYS;
		$allowed    = array_fill_keys( $allowed, true );
		$normalized = [];

		foreach ( $value as $key => $entry ) {
			$normalized_key = sanitize_key( (string) $key );
			if ( ! isset( $allowed[ $normalized_key ] ) || ! is_scalar( $entry ) || ! is_numeric( $entry ) ) {
				continue;
			}

			$normalized[ $normalized_key ] = self::coerce_score( $entry );
			if ( count( $normalized ) >= 12 ) {
				break;
			}
		}

		return $normalized;
	}

	private static function coerce_score( mixed $value ): float {
		if ( is_bool( $value ) ) {
			$numeric_value = (float) $value;
		} elseif ( is_scalar( $value ) && is_numeric( $value ) ) {
			$numeric_value = (float) $value;
		} else {
			$numeric_value = 0.0;
		}

		return max( 0.0, min( 1.0, $numeric_value ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function normalize_source_signals( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$signals = [];
		foreach ( $value as $signal ) {
			$normalized = sanitize_key( (string) $signal );
			if ( '' !== $normalized ) {
				$signals[ $normalized ] = $normalized;
			}
		}

		return array_values( $signals );
	}

	/**
	 * @return array<int, string>
	 */
	private static function merge_source_signals( mixed ...$values ): array {
		$merged = [];

		foreach ( $values as $value ) {
			foreach ( self::normalize_source_signals( $value ) as $signal ) {
				$merged[ $signal ] = $signal;
			}
		}

		return array_values( $merged );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_freshness_meta( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$meta = [];
		foreach ( $value as $key => $entry ) {
			$normalized_key = sanitize_text_field( (string) $key );
			if ( '' === $normalized_key ) {
				continue;
			}

			$meta[ $normalized_key ] = self::sanitize_structured_value( $entry );
		}

		return $meta;
	}

	/**
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function normalize_operations( mixed $operations ): ?array {
		if ( ! is_array( $operations ) ) {
			return null;
		}

		$normalized = [];
		foreach ( $operations as $operation ) {
			if ( is_array( $operation ) ) {
				$normalized[] = self::sanitize_structured_value( $operation );
			}
		}

		return $normalized;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_ranking_hint( mixed $ranking_hint, mixed $default_ranking_hint = null ): array {
		$default_hint = is_array( $default_ranking_hint ) ? self::sanitize_structured_value( $default_ranking_hint ) : [];
		$input_hint   = is_array( $ranking_hint ) ? self::sanitize_structured_value( $ranking_hint ) : [];
		unset( $input_hint['componentScores'] );

		$normalized = array_merge( $default_hint, $input_hint );

		if ( is_array( $default_ranking_hint['componentScores'] ?? null ) ) {
			$normalized['componentScores'] = self::normalize_component_scores( $default_ranking_hint['componentScores'] );
		} else {
			unset( $normalized['componentScores'] );
		}

		return $normalized;
	}

	/**
	 * @return array{semantic: float, structure: float, design: float, area: float, override: float, blended: float}
	 */
	private static function normalize_component_scores( array $scores ): array {
		$normalized = [];

		foreach ( [ 'semantic', 'structure', 'design', 'area', 'override', 'blended' ] as $key ) {
			$normalized[ $key ] = self::coerce_score( $scores[ $key ] ?? 0.0 );
		}

		return $normalized;
	}

	private static function sanitize_structured_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$normalized = [];
			foreach ( $value as $key => $entry ) {
				if ( is_int( $key ) ) {
					$normalized[] = self::sanitize_structured_value( $entry );
					continue;
				}

				$sanitized_key = sanitize_text_field( (string) $key );
				if ( '' === $sanitized_key ) {
					continue;
				}

				$normalized[ $sanitized_key ] = self::sanitize_structured_value( $entry );
			}

			return $normalized;
		}

		if ( is_bool( $value ) || is_numeric( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}
}
