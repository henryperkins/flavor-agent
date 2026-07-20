<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class BlockContextCollector {

	/**
	 * Node levels emitted for the selected block's interior. Matches
	 * BlockAbilities::BLOCK_INTERIOR_MAX_DEPTH, which caps the same tree after
	 * normalization; item and per-node child counts are capped there too.
	 */
	private const INTERIOR_MAX_DEPTH = 3;

	public function __construct(
		private BlockTypeIntrospector $block_type_introspector,
		private ThemeTokenCollector $theme_token_collector
	) {
	}

	public function for_block(
		string $block_name,
		array $attributes = [],
		array $inner_blocks = [],
		bool $is_inside_content_only = false,
		array $parent_context = [],
		array $sibling_summaries_before = [],
		array $sibling_summaries_after = []
	): array {
		$type_info = $this->block_type_introspector->introspect_block_type( $block_name );

		$result = [
			'block'          => [
				'name'                => $block_name,
				'title'               => $type_info['title'] ?? '',
				'currentAttributes'   => $attributes,
				'inspectorPanels'     => $type_info['inspectorPanels'] ?? [],
				'styles'              => $type_info['styles'] ?? [],
				'activeStyle'         => $this->block_type_introspector->extract_active_style(
					$attributes['className'] ?? '',
					$type_info['styles'] ?? []
				),
				'variations'          => $type_info['variations'] ?? [],
				'bindableAttributes'  => $type_info['bindableAttributes'] ?? [],
				'supportsContentRole' => ! empty( $type_info['supportsContentRole'] ),
				'contentAttributes'   => $type_info['contentAttributes'] ?? [],
				'configAttributes'    => $type_info['configAttributes'] ?? [],
				'editingMode'         => 'default',
				'isInsideContentOnly' => $is_inside_content_only,
				'blockVisibility'     => $attributes['metadata']['blockVisibility'] ?? null,
				'childCount'          => count( $inner_blocks ),
			],
			'siblingsBefore' => [],
			'siblingsAfter'  => [],
			'themeTokens'    => $this->theme_token_collector->for_tokens(),
		];

		if ( ! empty( $parent_context ) ) {
			$result['parentContext'] = $parent_context;
		}

		if ( ! empty( $sibling_summaries_before ) ) {
			$result['siblingSummariesBefore'] = $sibling_summaries_before;
		}

		if ( ! empty( $sibling_summaries_after ) ) {
			$result['siblingSummariesAfter'] = $sibling_summaries_after;
		}

		$block_interior = $this->summarize_inner_blocks( $inner_blocks );

		if ( ! empty( $block_interior ) ) {
			$result['blockInterior'] = $block_interior;
		}

		return $result;
	}

	/**
	 * Summarize the selected block's own subtree for external clients.
	 *
	 * Degrades relative to the editor payload by design: no title (that would
	 * cost one block-type introspection per node) and no role/job (no structural
	 * annotation exists server-side). Visual hints are emitted as raw attributes
	 * and narrowed downstream by BlockAbilities::normalize_visual_hints(), so the
	 * allowlist has exactly one implementation.
	 *
	 * @param array<int, mixed> $inner_blocks
	 * @return array<int, array<string, mixed>>
	 */
	private function summarize_inner_blocks( array $inner_blocks, int $depth = 0 ): array {
		$summaries = [];

		foreach ( $inner_blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			// Accept both the parse_blocks shape and the normalized editor shape.
			$name = $block['blockName'] ?? $block['name'] ?? '';

			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			$attributes = $block['attrs'] ?? $block['attributes'] ?? [];
			$children   = $block['innerBlocks'] ?? [];
			$children   = is_array( $children ) ? $children : [];

			$summary = [
				'block'      => $name,
				'childCount' => count( $children ),
			];

			if ( is_array( $attributes ) && [] !== $attributes ) {
				$summary['visualHints'] = $attributes;
			}

			if ( $depth + 1 < self::INTERIOR_MAX_DEPTH && [] !== $children ) {
				$summary['children'] = $this->summarize_inner_blocks( $children, $depth + 1 );
			}

			$summaries[] = $summary;
		}

		return $summaries;
	}
}
