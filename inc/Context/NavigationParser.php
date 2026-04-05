<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class NavigationParser {

	/**
	 * @param array<int, mixed> $blocks
	 * @return array<string, mixed>|null
	 */
	public function find_navigation_block( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === 'core/navigation' ) {
				return $block;
			}
		}

		return null;
	}

	/**
	 * @return array{attrs: array<string, mixed>, inner: array<int, mixed>, hasNavigationBlock: bool, hasStructure: bool}
	 */
	public function parse_navigation_source( string $content ): array {
		if ( '' === $content ) {
			return [
				'attrs'              => [],
				'inner'              => [],
				'hasNavigationBlock' => false,
				'hasStructure'       => false,
			];
		}

		$blocks    = parse_blocks( $content );
		$nav_block = $this->find_navigation_block( $blocks );

		if ( null !== $nav_block ) {
			$inner                = is_array( $nav_block['innerBlocks'] ?? null )
				? $nav_block['innerBlocks']
				: [];
			$has_explicit_wrapper = str_contains( $content, '<!-- /wp:navigation -->' );

			return [
				'attrs'              => is_array( $nav_block['attrs'] ?? null ) ? $nav_block['attrs'] : [],
				'inner'              => $inner,
				'hasNavigationBlock' => true,
				'hasStructure'       => $has_explicit_wrapper || [] !== $inner,
			];
		}

		return [
			'attrs'              => [],
			'inner'              => $blocks,
			'hasNavigationBlock' => false,
			'hasStructure'       => [] !== $blocks,
		];
	}

	/**
	 * Extract a flat/nested menu item structure from parsed inner blocks.
	 *
	 * @param array<int, mixed> $blocks Parsed inner blocks of a navigation.
	 * @return array<int, array<string, mixed>>
	 */
	public function extract_menu_items( array $blocks ): array {
		$items = [];

		foreach ( $blocks as $block ) {
			$name  = $block['blockName'] ?? '';
			$attrs = $block['attrs'] ?? [];
			$inner = $block['innerBlocks'] ?? [];

			if ( null === $name || '' === $name ) {
				continue;
			}

			$item = [
				'type' => $this->simplify_block_name( $name ),
			];

			if ( 'core/navigation-link' === $name || 'core/navigation-submenu' === $name ) {
				$item['label'] = $this->extract_string_attr( $attrs, 'label', '' );
				$item['url']   = $this->extract_string_attr( $attrs, 'url', '' );
			} elseif ( 'core/page-list' === $name ) {
				$item['label'] = 'Page List (auto-generated)';
			} elseif ( 'core/home-link' === $name ) {
				$item['label'] = $this->extract_string_attr( $attrs, 'label', 'Home' );
			} else {
				$item['label'] = '';
			}

			if ( is_array( $inner ) && count( $inner ) > 0 ) {
				$item['children'] = $this->extract_menu_items( $inner );
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * @param array<string, mixed> $nav_attrs
	 * @return array<string, mixed>
	 */
	public function collect_navigation_attributes( array $nav_attrs ): array {
		return [
			'overlayMenu'         => $this->extract_string_attr( $nav_attrs, 'overlayMenu', 'mobile' ),
			'hasIcon'             => ! empty( $nav_attrs['hasIcon'] ),
			'icon'                => $this->extract_string_attr( $nav_attrs, 'icon', 'handle' ),
			'openSubmenusOnClick' => ! empty( $nav_attrs['openSubmenusOnClick'] ),
			'showSubmenuIcon'     => $nav_attrs['showSubmenuIcon'] ?? true,
			'maxNestingLevel'     => isset( $nav_attrs['maxNestingLevel'] ) ? (int) $nav_attrs['maxNestingLevel'] : 0,
		];
	}

	public function count_menu_items_recursive( array $items ): int {
		$count = count( $items );

		foreach ( $items as $item ) {
			if ( isset( $item['children'] ) && is_array( $item['children'] ) ) {
				$count += $this->count_menu_items_recursive( $item['children'] );
			}
		}

		return $count;
	}

	public function measure_menu_depth( array $items, int $current = 1 ): int {
		if ( 0 === count( $items ) ) {
			return 0;
		}

		$max = $current;

		foreach ( $items as $item ) {
			if ( isset( $item['children'] ) && is_array( $item['children'] ) && count( $item['children'] ) > 0 ) {
				$child_depth = $this->measure_menu_depth( $item['children'], $current + 1 );
				$max         = max( $max, $child_depth );
			}
		}

		return $max;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	public function collect_navigation_structure_summary( array $items ): array {
		$summary = [
			'topLevelCount'  => count( $items ),
			'submenuCount'   => 0,
			'hasPageList'    => false,
			'nonLinkTypes'   => [],
			'topLevelLabels' => [],
		];

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( $index < 6 ) {
				$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );

				if ( '' !== $label ) {
					$summary['topLevelLabels'][] = $label;
				}
			}

			$this->walk_navigation_structure_summary( $item, $summary );
		}

		$summary['nonLinkTypes'] = array_values( array_unique( $summary['nonLinkTypes'] ) );

		return $summary;
	}

	private function simplify_block_name( string $name ): string {
		if ( str_starts_with( $name, 'core/' ) ) {
			return substr( $name, 5 );
		}

		return $name;
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private function extract_string_attr( array $attrs, string $key, string $default_value ): string {
		return isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ? $attrs[ $key ] : $default_value;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $summary
	 */
	private function walk_navigation_structure_summary( array $item, array &$summary ): void {
		$type = sanitize_key( (string) ( $item['type'] ?? '' ) );

		if ( 'page-list' === $type ) {
			$summary['hasPageList'] = true;
		}

		if ( '' !== $type && ! in_array( $type, [ 'navigation-link', 'navigation-submenu', 'home-link', 'page-list' ], true ) ) {
			$summary['nonLinkTypes'][] = $type;
		}

		$children = is_array( $item['children'] ?? null ) ? $item['children'] : [];

		if ( count( $children ) > 0 ) {
			++$summary['submenuCount'];

			foreach ( $children as $child ) {
				if ( is_array( $child ) ) {
					$this->walk_navigation_structure_summary( $child, $summary );
				}
			}
		}
	}
}
