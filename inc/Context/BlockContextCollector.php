<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class BlockContextCollector {

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

		return $result;
	}
}
