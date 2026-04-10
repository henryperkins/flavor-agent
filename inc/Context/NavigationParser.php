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
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( ( $block['blockName'] ?? '' ) === 'core/navigation' ) {
				return $block;
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];
			$match        = $this->find_navigation_block( $inner_blocks );

			if ( is_array( $match ) ) {
				return $match;
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
	public function extract_menu_items( array $blocks, array $parent_path = [], int $depth = 0 ): array {
		$items = [];

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name  = $block['blockName'] ?? '';
			$attrs = $block['attrs'] ?? [];
			$inner = $block['innerBlocks'] ?? [];

			if ( null === $name || '' === $name ) {
				continue;
			}

			$path = array_merge( $parent_path, [ (int) $index ] );
			$item = [
				'type'  => $this->simplify_block_name( $name ),
				'path'  => $path,
				'depth' => $depth,
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
				$item['children'] = $this->extract_menu_items( $inner, $path, $depth + 1 );
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array{path: array<int, int>, label: string, type: string, depth: int}>
	 */
	public function build_target_inventory( array $items ): array {
		$inventory = [];

		$this->walk_target_inventory( $items, $inventory );

		return $inventory;
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

	/**
	 * @param array<int, mixed> $blocks
	 */
	public function blocks_reference_navigation( array $blocks, int $menu_id ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if (
				( $block['blockName'] ?? '' ) === 'core/navigation'
				&& isset( $block['attrs']['ref'] )
				&& (int) $block['attrs']['ref'] === $menu_id
			) {
				return true;
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( [] !== $inner_blocks && $this->blocks_reference_navigation( $inner_blocks, $menu_id ) ) {
				return true;
			}
		}

		return false;
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
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, array{path: array<int, int>, label: string, type: string, depth: int}> $inventory
	 */
	private function walk_target_inventory( array $items, array &$inventory ): void {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$path = $this->sanitize_target_path( $item['path'] ?? null );

			if ( null === $path ) {
				continue;
			}

			$inventory[] = [
				'path'  => $path,
				'label' => sanitize_text_field( (string) ( $item['label'] ?? '' ) ),
				'type'  => sanitize_key( (string) ( $item['type'] ?? '' ) ),
				'depth' => isset( $item['depth'] ) ? max( 0, (int) $item['depth'] ) : count( $path ) - 1,
			];

			$children = is_array( $item['children'] ?? null ) ? $item['children'] : [];

			if ( [] !== $children ) {
				$this->walk_target_inventory( $children, $inventory );
			}
		}
	}

	/**
	 * @return array<int, int>|null
	 */
	private function sanitize_target_path( mixed $path ): ?array {
		if ( ! is_array( $path ) || [] === $path ) {
			return null;
		}

		$normalized = [];

		foreach ( $path as $segment ) {
			if ( ! is_numeric( $segment ) ) {
				return null;
			}

			$normalized[] = max( 0, (int) $segment );
		}

		return $normalized;
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
