<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns;

use FlavorAgent\Support\StringArray;

final class PatternComponentScorer {

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 * @return array{semantic: float, structure: float, design: float, area: float, override: float, blended: float}
	 */
	public static function score( float $semantic_score, array $payload, array $context ): array {
		$semantic  = self::clamp( $semantic_score );
		$structure = self::structure_score( $payload, $context );
		$design    = self::design_score( $payload, $context );
		$area      = self::area_score( $payload, $context );
		$override  = self::override_score( $payload, $context );

		$blended = self::clamp(
			( 0.45 * $semantic )
			+ ( 0.25 * $structure )
			+ ( 0.15 * $design )
			+ ( 0.10 * $area )
			+ ( 0.05 * $override )
		);

		return [
			'semantic'  => round( $semantic, 4 ),
			'structure' => round( $structure, 4 ),
			'design'    => round( $design, 4 ),
			'area'      => round( $area, 4 ),
			'override'  => round( $override, 4 ),
			'blended'   => round( $blended, 4 ),
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 */
	private static function structure_score( array $payload, array $context ): float {
		$traits   = array_fill_keys( StringArray::sanitize( $payload['traits'] ?? [] ), true );
		$root     = sanitize_key( (string) ( $context['rootBlock'] ?? '' ) );
		$siblings = StringArray::sanitize( $context['nearbySiblings'] ?? [] );

		$score = 0.55;
		if ( isset( $traits['simple'] ) && in_array( $root, [ 'core/group', 'core/column' ], true ) ) {
			$score += 0.18;
		}
		if ( isset( $traits['multi-column'] ) && in_array( 'core/columns', $siblings, true ) ) {
			$score += 0.12;
		}
		if ( isset( $traits['navigation'] ) && in_array( $root, [ 'core/navigation', 'core/group' ], true ) ) {
			$score += 0.10;
		}
		if ( isset( $traits['complex'] ) && in_array( $root, [ 'core/column', 'core/buttons' ], true ) ) {
			$score -= 0.20;
		}

		return self::clamp( $score );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 */
	private static function design_score( array $payload, array $context ): float {
		$metadata     = is_array( $payload['designMetadata'] ?? null ) ? $payload['designMetadata'] : [];
		$context_role = sanitize_key( (string) ( $context['sectionRole'] ?? $context['templatePartArea'] ?? '' ) );
		$section_role = sanitize_key( (string) ( $metadata['sectionRole'] ?? '' ) );
		$density      = sanitize_key( (string) ( $metadata['visualDensity'] ?? '' ) );

		$score = 0.55;
		if ( '' !== $context_role && $context_role === $section_role ) {
			$score += 0.22;
		}
		if ( 'balanced' === $density || 'sparse' === $density ) {
			$score += 0.08;
		}
		if ( 'dense' === $density && in_array( $context_role, [ 'footer', 'sidebar', 'header' ], true ) ) {
			$score -= 0.18;
		}

		return self::clamp( $score );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 */
	private static function area_score( array $payload, array $context ): float {
		$metadata = is_array( $payload['designMetadata'] ?? null ) ? $payload['designMetadata'] : [];
		$area     = sanitize_key( (string) ( $context['templatePartArea'] ?? '' ) );
		$affinity = sanitize_key( (string) ( $metadata['templateAreaAffinity'] ?? '' ) );

		if ( '' === $area || 'unknown' === $affinity ) {
			return 0.55;
		}

		return $area === $affinity ? 0.90 : 0.40;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 */
	private static function override_score( array $payload, array $context ): float {
		$overrides       = is_array( $payload['patternOverrides'] ?? null ) ? $payload['patternOverrides'] : [];
		$custom_context  = ! empty( $context['isCustomBlockContext'] );
		$has_overrides   = ! empty( $overrides['hasOverrides'] );
		$override_blocks = is_array( $overrides['overrideAttributes'] ?? null ) ? $overrides['overrideAttributes'] : [];

		if ( $custom_context && $has_overrides && [] !== $override_blocks ) {
			return 0.90;
		}
		if ( $has_overrides ) {
			return 0.70;
		}

		return 0.55;
	}

	private static function clamp( float $score ): float {
		return max( 0.0, min( 1.0, $score ) );
	}
}
