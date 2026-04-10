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

	public function for_patterns( ?array $categories = null, ?array $block_types = null, ?array $template_types = null ): array {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		$all      = $registry->get_all_registered();
		$fingerprint = $this->build_registry_fingerprint( $all );

		if ( $this->registry_fingerprint !== $fingerprint ) {
			$this->pattern_query_cache    = [];
			$this->registry_fingerprint = $fingerprint;
		}

		$filters_key = wp_json_encode(
			[
				'categories'    => array_values( $categories ?? [] ),
				'blockTypes'    => array_values( $block_types ?? [] ),
				'templateTypes' => array_values( $template_types ?? [] ),
			]
		);
		$cache_key   = false !== $filters_key
			? $fingerprint . '|' . $filters_key
			: false;

		if ( false !== $cache_key && isset( $this->pattern_query_cache[ $cache_key ] ) ) {
			return $this->pattern_query_cache[ $cache_key ];
		}
		$result   = [];

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

			$content = (string) ( $pattern['content'] ?? '' );

			$result[] = [
				'name'             => $pattern['name'] ?? '',
				'title'            => $pattern['title'] ?? '',
				'description'      => $pattern['description'] ?? '',
				'categories'       => $pattern['categories'] ?? [],
				'blockTypes'       => $pattern['blockTypes'] ?? [],
				'templateTypes'    => $pattern['templateTypes'] ?? [],
				'patternOverrides' => $this->get_cached_pattern_override_metadata( $content ),
				'content'          => $content,
			];
		}

		if ( false !== $cache_key ) {
			$this->pattern_query_cache[ $cache_key ] = $result;
		}

		return $result;
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
}
