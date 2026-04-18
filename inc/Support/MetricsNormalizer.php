<?php
/**
 * Shared metric normalization utilities.
 *
 * Consolidates the normalize_metric_int logic previously duplicated in
 * WordPressAIClient and ResponsesClient into a single shared helper.
 */

declare(strict_types=1);

namespace FlavorAgent\Support;

final class MetricsNormalizer {

	/**
	 * Coerce a mixed value to a non-negative integer suitable for metric
	 * storage (token counts, latency, byte sizes, etc.).
	 *
	 * Accepts positive integers, floats (rounded), and numeric strings.
	 * Returns null for negative values, empty strings, and all other types.
	 *
	 * @param mixed $value Raw metric value from an API response.
	 * @return int|null Normalized non-negative integer, or null.
	 */
	public static function normalize_metric_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		if ( is_float( $value ) ) {
			$normalized = (int) round( $value );

			return $normalized >= 0 ? $normalized : null;
		}

		if ( is_string( $value ) && '' !== trim( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
			$normalized = (int) $value;

			return $normalized >= 0 ? $normalized : null;
		}

		return null;
	}
}
