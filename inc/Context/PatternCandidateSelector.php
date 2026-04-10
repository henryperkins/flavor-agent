<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class PatternCandidateSelector {

	public const TEMPLATE_PATTERN_CANDIDATE_CAP = 30;

	public function __construct(
		private PatternCatalog $pattern_catalog
	) {
	}

	/**
	 * @param string[]|null $visible_pattern_names
	 * @return array<int, array<string, mixed>>
	 */
	public function collect_template_candidate_patterns( ?string $template_type, ?array $visible_pattern_names = null ): array {
		$all_patterns = $this->pattern_catalog->for_patterns();
		$typed        = [];
		$generic      = [];
		$unfiltered   = [];
		$seen         = [];

		foreach ( $all_patterns as $pattern ) {
			$name = (string) ( $pattern['name'] ?? '' );

			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}

			unset( $pattern['content'] );

			$types = $pattern['templateTypes'] ?? [];
			if ( ! is_array( $types ) ) {
				$types = [];
			}

			if ( null === $template_type ) {
				$unfiltered[]  = $pattern;
				$seen[ $name ] = true;
				continue;
			}

			if ( in_array( $template_type, $types, true ) ) {
				$pattern['matchType'] = 'typed';
				$typed[]              = $pattern;
				$seen[ $name ]        = true;
				continue;
			}

			if ( empty( $types ) ) {
				$pattern['matchType'] = 'generic';
				$generic[]            = $pattern;
				$seen[ $name ]        = true;
			}
		}

		$candidates = null === $template_type
			? $unfiltered
			: array_merge( $typed, $generic );

		if ( is_array( $visible_pattern_names ) ) {
			$visible_lookup = array_fill_keys( $visible_pattern_names, true );
			$candidates     = array_values(
				array_filter(
					$candidates,
					static function ( array $pattern ) use ( $visible_lookup ): bool {
						$name = (string) ( $pattern['name'] ?? '' );

						return '' !== $name && isset( $visible_lookup[ $name ] );
					}
				)
			);
		}

		return array_slice( $candidates, 0, self::TEMPLATE_PATTERN_CANDIDATE_CAP );
	}

	/**
	 * @param string[]|null $visible_pattern_names
	 * @return array<int, array<string, mixed>>
	 */
	public function collect_template_part_candidate_patterns( ?string $area, ?array $visible_pattern_names = null ): array {
		$all_patterns = $this->pattern_catalog->for_patterns();
		$area_key     = sanitize_key( (string) $area );
		$area_terms   = $this->template_part_area_terms( $area_key );
		$matched      = [];
		$generic      = [];
		$unfiltered   = [];
		$seen         = [];
		$index        = 0;

		foreach ( $all_patterns as $pattern ) {
			$name = (string) ( $pattern['name'] ?? '' );

			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}

			unset( $pattern['content'] );
			$pattern['_sortIndex'] = $index++;

			if ( '' === $area_key ) {
				$unfiltered[]  = $pattern;
				$seen[ $name ] = true;
				continue;
			}

			$score = $this->score_template_part_pattern_candidate(
				$pattern,
				$area_key,
				$area_terms
			);

			if ( $score > 0 ) {
				$pattern['matchType']  = 'area';
				$pattern['_sortScore'] = $score;
				$matched[]             = $pattern;
				$seen[ $name ]         = true;
				continue;
			}

			$block_types    = $pattern['blockTypes'] ?? [];
			$template_types = $pattern['templateTypes'] ?? [];

			if (
				( ! is_array( $block_types ) || 0 === count( $block_types ) ) &&
				( ! is_array( $template_types ) || 0 === count( $template_types ) )
			) {
				$pattern['matchType'] = 'generic';
				$generic[]            = $pattern;
				$seen[ $name ]        = true;
			}
		}

		$sort_candidates = static function ( array &$candidates ): void {
			usort(
				$candidates,
				static function ( array $left, array $right ): int {
					$score_compare = (int) ( $right['_sortScore'] ?? 0 ) <=> (int) ( $left['_sortScore'] ?? 0 );

					if ( 0 !== $score_compare ) {
						return $score_compare;
					}

					return (int) ( $left['_sortIndex'] ?? 0 ) <=> (int) ( $right['_sortIndex'] ?? 0 );
				}
			);
		};

		$strip_sort_fields = static function ( array $pattern ): array {
			unset( $pattern['_sortIndex'], $pattern['_sortScore'] );
			return $pattern;
		};

		if ( '' === $area_key ) {
			$candidates = $unfiltered;
		} else {
			$sort_candidates( $matched );
			$candidates = array_merge( $matched, $generic );
		}

		if ( is_array( $visible_pattern_names ) ) {
			$visible_lookup = array_fill_keys( $visible_pattern_names, true );
			$candidates     = array_values(
				array_filter(
					$candidates,
					static function ( array $pattern ) use ( $visible_lookup ): bool {
						$name = (string) ( $pattern['name'] ?? '' );

						return '' !== $name && isset( $visible_lookup[ $name ] );
					}
				)
			);
		}

		return array_map(
			$strip_sort_fields,
			array_slice( $candidates, 0, self::TEMPLATE_PATTERN_CANDIDATE_CAP )
		);
	}

	/**
	 * @return string[]
	 */
	private function template_part_area_terms( string $area ): array {
		$map = [
			'header'             => [ 'header', 'masthead' ],
			'footer'             => [ 'footer' ],
			'sidebar'            => [ 'sidebar', 'aside' ],
			'navigation-overlay' => [ 'navigation overlay', 'overlay', 'navigation' ],
		];

		if ( isset( $map[ $area ] ) ) {
			return $map[ $area ];
		}

		if ( '' === $area ) {
			return [];
		}

		$spaced = str_replace( '-', ' ', $area );

		return array_values(
			array_unique(
				array_filter(
					[ $area, $spaced ],
					static fn( string $term ): bool => '' !== $term
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @param string[]             $terms
	 */
	private function score_template_part_pattern_candidate( array $pattern, string $area, array $terms ): int {
		$score       = 0;
		$block_types = array_map(
			static fn( string $value ): string => strtolower( trim( $value ) ),
			array_filter(
				array_map(
					'strval',
					is_array( $pattern['blockTypes'] ?? null ) ? $pattern['blockTypes'] : []
				)
			)
		);

		if ( in_array( 'core/template-part/' . $area, $block_types, true ) ) {
			$score += 8;
		}

		if ( in_array( 'core/template-part', $block_types, true ) ) {
			$score += 2;
		}

		$categories = is_array( $pattern['categories'] ?? null )
			? array_map( 'strval', $pattern['categories'] )
			: [];
		$haystack   = strtolower(
			implode(
				' ',
				array_filter(
					[
						(string) ( $pattern['name'] ?? '' ),
						(string) ( $pattern['title'] ?? '' ),
						(string) ( $pattern['description'] ?? '' ),
						implode( ' ', $categories ),
					]
				)
			)
		);

		foreach ( $terms as $term ) {
			$normalized_term = strtolower( $term );

			if ( '' === $normalized_term ) {
				continue;
			}

			$matches = str_contains( $normalized_term, ' ' )
				? str_contains( $haystack, $normalized_term )
				: (bool) preg_match( '/\b' . preg_quote( $normalized_term, '/' ) . '\b/u', $haystack );

			if ( $matches ) {
				$score += 4;
			}
		}

		if (
			'navigation-overlay' === $area &&
			str_contains( $haystack, 'navigation' ) &&
			str_contains( $haystack, 'overlay' )
		) {
			$score += 2;
		}

		return $score;
	}
}
