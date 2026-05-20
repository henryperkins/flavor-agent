<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class DesignSemantics {

	private const LIST_CAP = 6;

	private const SURFACE_LEAF_CAP = 8;

	private const SURFACES = [
		'block',
		'template',
		'template-part',
		'global-styles',
		'style-book',
	];

	private const SECTION_ROLES = [
		'hero',
		'header',
		'footer',
		'card',
		'sidebar',
		'post-body',
		'cta',
		'archive-list',
		'unknown',
	];

	private const VISUAL_DENSITIES = [
		'sparse',
		'balanced',
		'dense',
		'unknown',
	];

	private const CONTRAST_CONTEXTS = [
		'dark-parent',
		'light-parent',
		'image-overlay',
		'unknown',
	];

	private const LAYOUT_RHYTHMS = [
		'constrained',
		'full-width',
		'grid',
		'stacked',
		'media-text',
		'sidebar',
		'unknown',
	];

	private const TYPOGRAPHY_ROLES = [
		'heading',
		'body',
		'metadata',
		'navigation',
		'callout',
		'unknown',
	];

	private const DESIGN_ISSUES = [
		'contrast',
		'spacing',
		'hierarchy',
		'rhythm',
		'alignment',
		'consistency',
		'accessibility',
		'none',
		'unknown',
	];

	public static function normalize( mixed $value, string $surface = '' ): array {
		$value = self::array_from_value( $value );

		if ( [] === $value ) {
			return [];
		}

		$fallback_surface = self::normalize_enum(
			$surface,
			self::SURFACES,
			'block'
		);
		$normalized       = [
			'surface'             => self::normalize_enum(
				$value['surface'] ?? $fallback_surface,
				self::SURFACES,
				$fallback_surface
			),
			'sectionRole'         => self::normalize_enum(
				$value['sectionRole'] ?? '',
				self::SECTION_ROLES
			),
			'visualDensity'       => self::normalize_enum(
				$value['visualDensity'] ?? '',
				self::VISUAL_DENSITIES
			),
			'contrastContext'     => self::normalize_enum(
				$value['contrastContext'] ?? '',
				self::CONTRAST_CONTEXTS
			),
			'layoutRhythm'        => self::normalize_enum(
				$value['layoutRhythm'] ?? '',
				self::LAYOUT_RHYTHMS
			),
			'typographyRole'      => self::normalize_enum(
				$value['typographyRole'] ?? '',
				self::TYPOGRAPHY_ROLES
			),
			'tokenAffinity'       => self::normalize_token_affinity(
				$value['tokenAffinity'] ?? []
			),
			'existingDesignScore' => self::normalize_score(
				$value['existingDesignScore'] ?? 0
			),
			'mainDesignIssue'     => self::normalize_enum(
				$value['mainDesignIssue'] ?? '',
				self::DESIGN_ISSUES
			),
			'negativeSignals'     => self::normalize_string_list(
				$value['negativeSignals'] ?? []
			),
		];

		foreach ( [ 'block', 'template', 'templatePart' ] as $key ) {
			$details = self::normalize_surface_details( $value[ $key ] ?? [] );

			if ( [] !== $details ) {
				$normalized[ $key ] = $details;
			}
		}

		return $normalized;
	}

	public static function format_prompt_lines(
		array $semantics,
		int $max_estimated_tokens = 80
	): array {
		$normalized = self::normalize(
			$semantics,
			is_string( $semantics['surface'] ?? null )
				? (string) $semantics['surface']
				: ''
		);

		if ( [] === $normalized ) {
			return [];
		}

		$candidates = [];

		self::append_enum_line(
			$candidates,
			'Role',
			$normalized['sectionRole'] ?? ''
		);
		self::append_enum_line(
			$candidates,
			'Density',
			$normalized['visualDensity'] ?? ''
		);
		self::append_enum_line(
			$candidates,
			'Contrast',
			$normalized['contrastContext'] ?? ''
		);
		self::append_enum_line(
			$candidates,
			'Rhythm',
			$normalized['layoutRhythm'] ?? ''
		);
		self::append_enum_line(
			$candidates,
			'Typography',
			$normalized['typographyRole'] ?? ''
		);

		if (
			isset( $normalized['mainDesignIssue'] )
			&& 'unknown' !== $normalized['mainDesignIssue']
			&& 'none' !== $normalized['mainDesignIssue']
		) {
			$candidates[] = 'Main issue: ' . $normalized['mainDesignIssue'];
		}

		foreach ( [ 'color', 'spacing', 'fontSize' ] as $key ) {
			$values = $normalized['tokenAffinity'][ $key ] ?? [];

			if ( is_array( $values ) && [] !== $values ) {
				$candidates[] = 'Token affinity ' . $key . ': ' . implode( ', ', $values );
			}
		}

		if ( ! empty( $normalized['negativeSignals'] ) ) {
			$candidates[] = 'Negative signals: ' . implode( ', ', $normalized['negativeSignals'] );
		}

		foreach (
			[
				'block'        => 'Block',
				'template'     => 'Template',
				'templatePart' => 'Template part',
			] as $key => $label
		) {
			if ( empty( $normalized[ $key ] ) || ! is_array( $normalized[ $key ] ) ) {
				continue;
			}

			$candidates[] = $label . ': ' . self::format_detail_pairs(
				$normalized[ $key ]
			);
		}

		return self::fit_lines_to_budget( $candidates, $max_estimated_tokens );
	}

	private static function array_from_value( mixed $value ): array {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		return is_array( $value ) ? $value : [];
	}

	/**
	 * @param array<int, string> $allowed
	 */
	private static function normalize_enum(
		mixed $value,
		array $allowed,
		string $fallback = 'unknown'
	): string {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return $fallback;
		}

		$normalized = strtolower( sanitize_text_field( (string) $value ) );

		return in_array( $normalized, $allowed, true )
			? $normalized
			: $fallback;
	}

	private static function normalize_score( mixed $value ): float {
		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}

		return min( 1.0, max( 0.0, (float) $value ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function normalize_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$seen   = [];
		$result = [];

		foreach ( $value as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				continue;
			}

			$normalized = trim( sanitize_text_field( (string) $entry ) );

			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}

			$seen[ $normalized ] = true;
			$result[]            = $normalized;

			if ( count( $result ) >= self::LIST_CAP ) {
				break;
			}
		}

		sort( $result, SORT_STRING );

		return $result;
	}

	/**
	 * @return array{color: array<int, string>, spacing: array<int, string>, fontSize: array<int, string>}
	 */
	private static function normalize_token_affinity( mixed $value ): array {
		$value = self::array_from_value( $value );

		return [
			'color'    => self::normalize_string_list( $value['color'] ?? [] ),
			'spacing'  => self::normalize_string_list( $value['spacing'] ?? [] ),
			'fontSize' => self::normalize_string_list( $value['fontSize'] ?? [] ),
		];
	}

	private static function normalize_surface_details( mixed $value ): array {
		$value = self::array_from_value( $value );

		if ( [] === $value ) {
			return [];
		}

		$leaf_count = 0;

		return self::normalize_surface_detail_map( $value, $leaf_count );
	}

	private static function normalize_surface_detail_map(
		array $value,
		int &$leaf_count
	): array {
		ksort( $value );

		$normalized = [];

		foreach ( $value as $key => $entry ) {
			if ( $leaf_count >= self::SURFACE_LEAF_CAP ) {
				break;
			}

			$key = self::sanitize_detail_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_string( $entry ) ) {
				$entry = trim( sanitize_text_field( $entry ) );

				if ( '' === $entry ) {
					continue;
				}

				$normalized[ $key ] = $entry;
				++$leaf_count;
				continue;
			}

			if ( is_bool( $entry ) ) {
				$normalized[ $key ] = $entry;
				++$leaf_count;
				continue;
			}

			if ( is_int( $entry ) || is_float( $entry ) ) {
				if ( is_finite( (float) $entry ) ) {
					$normalized[ $key ] = $entry;
					++$leaf_count;
				}
				continue;
			}

			if ( is_object( $entry ) ) {
				$entry = get_object_vars( $entry );
			}

			if ( is_array( $entry ) && ! self::is_list_array( $entry ) ) {
				$nested = self::normalize_surface_detail_map( $entry, $leaf_count );

				if ( [] !== $nested ) {
					$normalized[ $key ] = $nested;
				}
			}
		}

		return $normalized;
	}

	private static function append_enum_line(
		array &$lines,
		string $label,
		string $value
	): void {
		if ( '' === $value || 'unknown' === $value ) {
			return;
		}

		$lines[] = $label . ': ' . $value;
	}

	private static function sanitize_detail_key( string $key ): string {
		$key = trim( sanitize_text_field( $key ) );

		return (string) preg_replace( '/[^A-Za-z0-9_.-]/', '', $key );
	}

	private static function format_detail_pairs(
		array $details,
		string $prefix = ''
	): string {
		$pairs = [];

		foreach ( $details as $key => $value ) {
			$key = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;

			if ( is_array( $value ) ) {
				$nested = self::format_detail_pairs( $value, $key );

				if ( '' !== $nested ) {
					$pairs[] = $nested;
				}
				continue;
			}

			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}

			$pairs[] = $key . '=' . (string) $value;
		}

		return implode( ', ', $pairs );
	}

	/**
	 * @param array<int, string> $candidate_lines
	 * @return array<int, string>
	 */
	private static function fit_lines_to_budget(
		array $candidate_lines,
		int $max_estimated_tokens
	): array {
		if ( $max_estimated_tokens <= 0 ) {
			return [];
		}

		$lines = [];

		foreach ( $candidate_lines as $line ) {
			$next       = [ ...$lines, $line ];
			$next_text  = implode( "\n", $next );
			$next_token = (int) ceil( strlen( $next_text ) / 4 );

			if ( $next_token > $max_estimated_tokens ) {
				continue;
			}

			$lines = $next;
		}

		return $lines;
	}

	private static function is_list_array( array $value ): bool {
		if ( [] === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
