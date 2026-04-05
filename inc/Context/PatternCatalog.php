<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class PatternCatalog {

	public function __construct(
		private PatternOverrideAnalyzer $pattern_override_analyzer
	) {
	}

	public function for_patterns( ?array $categories = null, ?array $block_types = null, ?array $template_types = null ): array {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		$all      = $registry->get_all_registered();
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

			$result[] = [
				'name'             => $pattern['name'] ?? '',
				'title'            => $pattern['title'] ?? '',
				'description'      => $pattern['description'] ?? '',
				'categories'       => $pattern['categories'] ?? [],
				'blockTypes'       => $pattern['blockTypes'] ?? [],
				'templateTypes'    => $pattern['templateTypes'] ?? [],
				'patternOverrides' => $this->pattern_override_analyzer->collect_pattern_override_metadata(
					(string) ( $pattern['content'] ?? '' )
				),
				'content'          => $pattern['content'] ?? '',
			];
		}

		return $result;
	}
}
