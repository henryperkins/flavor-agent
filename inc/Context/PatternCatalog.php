<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class PatternCatalog {

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $pattern_override_cache = [];

	/**
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $pattern_query_cache = [];

	private ?string $registry_fingerprint = null;

	public function __construct(
		private PatternOverrideAnalyzer $pattern_override_analyzer
	) {
	}

	public function for_patterns(
		?array $categories = null,
		?array $block_types = null,
		?array $template_types = null,
		bool $include_content = true,
		?int $limit = null,
		int $offset = 0,
		?string $search = null
	): array {
		$registry    = \WP_Block_Patterns_Registry::get_instance();
		$all         = $registry->get_all_registered();
		$fingerprint = $this->build_registry_fingerprint( $all );

		if ( $this->registry_fingerprint !== $fingerprint ) {
			$this->pattern_query_cache  = [];
			$this->registry_fingerprint = $fingerprint;
		}

		$filters_key = wp_json_encode(
			[
				'categories'     => array_values( $categories ?? [] ),
				'blockTypes'     => array_values( $block_types ?? [] ),
				'templateTypes'  => array_values( $template_types ?? [] ),
				'includeContent' => $include_content,
				'search'         => is_string( $search ) ? sanitize_text_field( $search ) : '',
			]
		);
		$cache_key   = false !== $filters_key
			? $fingerprint . '|' . $filters_key
			: false;

		if ( false !== $cache_key && isset( $this->pattern_query_cache[ $cache_key ] ) ) {
			$result = $this->pattern_query_cache[ $cache_key ];
		} else {
			$result = [];

			foreach ( $all as $pattern ) {
				if ( null !== $categories ) {
					$pattern_categories = $pattern['categories'] ?? [];
					if ( empty( array_intersect( $categories, $pattern_categories ) ) ) {
						continue;
					}
				}

				if ( null !== $block_types ) {
					$pattern_block_types = $pattern['blockTypes'] ?? [];
					if ( empty( array_intersect( $block_types, $pattern_block_types ) ) ) {
						continue;
					}
				}

				if ( null !== $template_types ) {
					$pattern_template_types = $pattern['templateTypes'] ?? [];
					if ( empty( array_intersect( $template_types, $pattern_template_types ) ) ) {
						continue;
					}
				}

				if ( ! $this->matches_search_filter( $pattern, $search ) ) {
					continue;
				}

				$content = (string) ( $pattern['content'] ?? '' );

				$result[] = $this->build_pattern_entry( $pattern, $content, $include_content );
			}

			if ( false !== $cache_key ) {
				$this->pattern_query_cache[ $cache_key ] = $result;
			}
		}

		if ( $offset > 0 || null !== $limit ) {
			$result = array_slice(
				$result,
				max( 0, $offset ),
				null !== $limit ? max( 0, $limit ) : null
			);
		}

		return $result;
	}

	public function count_patterns(
		?array $categories = null,
		?array $block_types = null,
		?array $template_types = null,
		?string $search = null
	): int {
		return count(
			$this->for_patterns(
				$categories,
				$block_types,
				$template_types,
				false,
				null,
				0,
				$search
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_pattern( string $pattern_name ): ?array {
		$requested_name = sanitize_text_field( $pattern_name );

		if ( '' === $requested_name ) {
			return null;
		}

		foreach ( $this->for_patterns() as $pattern ) {
			if ( (string) ( $pattern['name'] ?? '' ) === $requested_name ) {
				return $pattern;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $patterns
	 */
	private function build_registry_fingerprint( array $patterns ): string {
		$identity = array_map(
			static fn( array $pattern ): string => (string) ( $pattern['name'] ?? '' ) . ':' . hash( 'sha256', (string) ( $pattern['content'] ?? '' ) ),
			$patterns
		);

		sort( $identity );

		return hash( 'sha256', implode( '|', $identity ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_cached_pattern_override_metadata( string $content ): array {
		$cache_key = hash( 'sha256', $content );

		if ( ! isset( $this->pattern_override_cache[ $cache_key ] ) ) {
			$this->pattern_override_cache[ $cache_key ] = $this->pattern_override_analyzer->collect_pattern_override_metadata( $content );
		}

		return $this->pattern_override_cache[ $cache_key ];
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @return array<string, mixed>
	 */
	private function build_pattern_entry( array $pattern, string $content, bool $include_content = true ): array {
		$name = (string) ( $pattern['name'] ?? '' );

		$entry = [
			'id'               => $name,
			'name'             => $name,
			'title'            => $pattern['title'] ?? '',
			'description'      => $pattern['description'] ?? '',
			'categories'       => $pattern['categories'] ?? [],
			'blockTypes'       => $pattern['blockTypes'] ?? [],
			'templateTypes'    => $pattern['templateTypes'] ?? [],
			'patternOverrides' => $this->get_cached_pattern_override_metadata( $content ),
		];

		if ( $include_content ) {
			$entry['content'] = $content;
		}

		return $entry;
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private function matches_search_filter( array $pattern, ?string $search ): bool {
		$search_term = is_string( $search ) ? strtolower( sanitize_text_field( $search ) ) : '';

		if ( '' === $search_term ) {
			return true;
		}

		$haystacks = [
			strtolower( (string) ( $pattern['name'] ?? '' ) ),
			strtolower( (string) ( $pattern['title'] ?? '' ) ),
		];

		foreach ( $haystacks as $haystack ) {
			if ( str_contains( $haystack, $search_term ) ) {
				return true;
			}
		}

		return false;
	}
}
