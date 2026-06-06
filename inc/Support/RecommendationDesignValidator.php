<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class RecommendationDesignValidator {

	/**
	 * @param array<string, mixed> $suggestion
	 * @param array<string, mixed> $context
	 * @return array{qualitySignals: array<string, mixed>, validationReasons: array<int, array<string, string>>}
	 */
	public static function analyze( array $suggestion, array $context ): array {
		$operations = is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [];
		$signals    = [
			'contrastPreserved'  => self::contrast_preserved( $suggestion ),
			'presetBacked'       => self::uses_preset_values( $operations, $suggestion ),
			'typographyReadable' => self::typography_readable( $operations ),
			'spacingScaleFit'    => self::spacing_scale_fit( $operations ),
			'noOp'               => self::is_no_op( $suggestion, $context ),
			'responsiveSane'     => self::responsive_sane( $operations ),
			'complexityFit'      => self::complexity_fit( $suggestion, $context ),
		];

		$reasons = [];
		if ( false === $signals['contrastPreserved'] ) {
			$reasons[] = [ 'code' => 'failed_contrast' ];
		}
		if ( false === $signals['presetBacked'] && self::touches_preset_candidate_path( $operations ) ) {
			$reasons[] = [ 'code' => 'raw_value_when_preset_available' ];
		}
		if ( true === $signals['noOp'] ) {
			$reasons[] = [ 'code' => 'duplicate_or_noop' ];
		}
		if ( false === $signals['responsiveSane'] ) {
			$reasons[] = [ 'code' => 'responsive_visibility_risk' ];
		}
		if ( false === $signals['complexityFit'] ) {
			$reasons[] = [ 'code' => 'excessive_visual_complexity' ];
		}

		return [
			'qualitySignals'    => $signals,
			'validationReasons' => ValidationReason::normalize( $reasons ),
		];
	}

	/**
	 * @param array<string, mixed> $suggestion
	 */
	private static function contrast_preserved( array $suggestion ): ?bool {
		foreach ( (array) ( $suggestion['validationReasons'] ?? [] ) as $reason ) {
			if ( is_array( $reason ) && 'failed_contrast' === sanitize_key( (string) ( $reason['code'] ?? '' ) ) ) {
				return false;
			}
		}

		$quality_signals = is_array( $suggestion['qualitySignals'] ?? null ) ? $suggestion['qualitySignals'] : [];
		if ( array_key_exists( 'contrastPreserved', $quality_signals ) ) {
			if ( null === $quality_signals['contrastPreserved'] ) {
				return null;
			}

			return ! empty( $quality_signals['contrastPreserved'] );
		}

		return null;
	}

	/**
	 * @param array<int, mixed>    $operations
	 * @param array<string, mixed> $suggestion
	 */
	private static function uses_preset_values( array $operations, array $suggestion ): bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$value_type = is_string( $operation['valueType'] ?? null ) ? $operation['valueType'] : '';
			$value      = is_string( $operation['value'] ?? null ) ? $operation['value'] : '';
			if ( 'preset' === $value_type || str_starts_with( $value, 'var:preset|' ) || str_starts_with( $value, 'var(--wp--preset--' ) ) {
				return true;
			}
		}

		return ! self::touches_preset_candidate_path( $operations ) && empty( $suggestion['attributeUpdates'] );
	}

	/**
	 * @param array<int, mixed> $operations
	 */
	private static function touches_preset_candidate_path( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$path = is_array( $operation['path'] ?? null ) ? implode( '.', array_map( 'strval', $operation['path'] ) ) : '';
			if ( str_contains( $path, 'color' ) || str_contains( $path, 'spacing' ) || str_contains( $path, 'typography' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, mixed> $operations
	 */
	private static function typography_readable( array $operations ): ?bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$path  = is_array( $operation['path'] ?? null ) ? implode( '.', array_map( 'strval', $operation['path'] ) ) : '';
			$value = is_scalar( $operation['value'] ?? null ) ? (string) $operation['value'] : '';
			if ( str_contains( $path, 'typography.fontSize' ) && preg_match( '/^([0-9.]+)px$/', $value, $matches ) ) {
				$size = (float) ( $matches[1] ?? 0 );
				return $size >= 12.0 && $size <= 96.0;
			}
		}

		return null;
	}

	/**
	 * @param array<int, mixed> $operations
	 */
	private static function spacing_scale_fit( array $operations ): ?bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$path  = is_array( $operation['path'] ?? null ) ? implode( '.', array_map( 'strval', $operation['path'] ) ) : '';
			$value = is_scalar( $operation['value'] ?? null ) ? (string) $operation['value'] : '';
			if ( str_contains( $path, 'spacing' ) && preg_match( '/^[0-9.]+(px|rem|em)$/', $value ) ) {
				return false;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $suggestion
	 * @param array<string, mixed> $context
	 */
	private static function is_no_op( array $suggestion, array $context ): bool {
		$updates = $suggestion['attributeUpdates'] ?? null;
		$current = $context['currentState']['attributes'] ?? $context['block']['attributes'] ?? null;

		if ( ! is_array( $updates ) || ! is_array( $current ) ) {
			return false;
		}

		return self::normalize_comparison_array( $updates ) === self::normalize_comparison_array( $current );
	}

	/**
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private static function normalize_comparison_array( array $value ): array {
		foreach ( $value as $key => $entry ) {
			if ( is_array( $entry ) ) {
				$value[ $key ] = self::normalize_comparison_array( $entry );
			}
		}

		if ( ! self::is_list_array( $value ) ) {
			ksort( $value );
		}

		return $value;
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function is_list_array( array $value ): bool {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * @param array<int, mixed> $operations
	 */
	private static function responsive_sane( array $operations ): ?bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$path = is_array( $operation['path'] ?? null ) ? implode( '.', array_map( 'strval', $operation['path'] ) ) : '';
			if ( str_contains( $path, 'visibility' ) || str_contains( $path, 'display' ) ) {
				return false;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $suggestion
	 * @param array<string, mixed> $context
	 */
	private static function complexity_fit( array $suggestion, array $context ): ?bool {
		$block_count = isset( $suggestion['contentBlockCount'] ) && is_numeric( $suggestion['contentBlockCount'] )
			? (int) $suggestion['contentBlockCount']
			: null;
		$role        = sanitize_key( (string) ( $context['designSemantics']['sectionRole'] ?? $context['templatePartArea'] ?? '' ) );

		if ( null === $block_count ) {
			return null;
		}

		return ! ( $block_count > 10 && in_array( $role, [ 'header', 'footer', 'sidebar' ], true ) );
	}
}
