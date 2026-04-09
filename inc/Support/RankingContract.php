<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class RankingContract {

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

		$source_signals = self::normalize_source_signals(
			$input['sourceSignals'] ?? $defaults['sourceSignals'] ?? []
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

		$operations = self::normalize_operations( $input['operations'] ?? $defaults['operations'] ?? null );
		if ( null !== $operations ) {
			$contract['operations'] = $operations;
		}

		$ranking_hint = self::normalize_ranking_hint( $input['rankingHint'] ?? $defaults['rankingHint'] ?? null );
		if ( [] !== $ranking_hint ) {
			$contract['rankingHint'] = $ranking_hint;
		}

		$advisory_type = sanitize_key(
			(string) ( $input['advisoryType'] ?? $defaults['advisoryType'] ?? '' )
		);
		if ( '' !== $advisory_type ) {
			$contract['advisoryType'] = $advisory_type;
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
	private static function normalize_ranking_hint( mixed $ranking_hint ): array {
		if ( ! is_array( $ranking_hint ) ) {
			return [];
		}

		return self::sanitize_structured_value( $ranking_hint );
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
