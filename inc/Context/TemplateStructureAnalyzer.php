<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class TemplateStructureAnalyzer {

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, string>            $part_area_lookup
	 * @return array{assignedParts: array<int, array<string, string>>, emptyAreas: string[], allowedAreas: string[]}
	 */
	public function collect_template_part_slots( array $blocks, array $part_area_lookup ): array {
		$assigned_parts = [];
		$empty_areas    = [];
		$allowed_areas  = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( ( $block['blockName'] ?? '' ) === 'core/template-part' ) {
				$slug = (string) ( $block['attrs']['slug'] ?? '' );
				$area = (string) (
					$block['attrs']['area'] ?? (
						'' !== $slug ? ( $part_area_lookup[ $slug ] ?? '' ) : ''
					)
				);

				if ( '' !== $slug ) {
					$assigned_parts[] = [
						'slug' => $slug,
						'area' => $area,
					];
					if ( '' !== $area ) {
						$allowed_areas[] = $area;
					}
				} elseif ( $this->is_explicit_empty_template_part_area( $area ) ) {
					$empty_areas[]   = $area;
					$allowed_areas[] = $area;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$child_slots = $this->collect_template_part_slots(
					$block['innerBlocks'],
					$part_area_lookup
				);

				$assigned_parts = array_merge( $assigned_parts, $child_slots['assignedParts'] );
				$empty_areas    = array_merge( $empty_areas, $child_slots['emptyAreas'] );
				$allowed_areas  = array_merge( $allowed_areas, $child_slots['allowedAreas'] );
			}
		}

		return [
			'assignedParts' => $assigned_parts,
			'emptyAreas'    => array_values( array_unique( $empty_areas ) ),
			'allowedAreas'  => array_values(
				array_unique(
					array_filter(
						$allowed_areas,
						static fn( string $area ): bool => '' !== $area
					)
				)
			),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @return array<int, array<string, mixed>>
	 */
	public function summarize_template_part_block_tree( array $blocks, array $path = [], int $depth = 0, int $max_depth = 3 ): array {
		$tree = [];

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$next_path    = array_merge( $path, [ (int) $index ] );
			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];
			$children     = [];

			if ( $depth + 1 < $max_depth && count( $inner_blocks ) > 0 ) {
				$children = $this->summarize_template_part_block_tree(
					$inner_blocks,
					$next_path,
					$depth + 1,
					$max_depth
				);
			}

			$tree[] = [
				'path'       => $next_path,
				'name'       => $name,
				'attributes' => $this->summarize_template_part_block_attributes(
					is_array( $block['attrs'] ?? null ) ? $block['attrs'] : []
				),
				'childCount' => count( $inner_blocks ),
				'children'   => $children,
			];
		}

		return $tree;
	}

	/**
	 * @param array<string, mixed> $attributes
	 * @return array<string, scalar>
	 */
	public function summarize_template_part_block_attributes( array $attributes ): array {
		$summary = [];
		$fields  = [ 'tagName', 'align', 'overlayMenu', 'maxNestingLevel', 'showSubmenuIcon', 'placeholder', 'slug', 'area', 'ref', 'templateLock' ];

		foreach ( $fields as $field ) {
			$value = $attributes[ $field ] ?? null;

			if ( is_string( $value ) && '' !== $value ) {
				$summary[ $field ] = $value;
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$summary[ $field ] = $value;
			}
		}

		$layout = is_array( $attributes['layout'] ?? null ) ? $attributes['layout'] : [];

		if ( isset( $layout['type'] ) && is_string( $layout['type'] ) && '' !== $layout['type'] ) {
			$summary['layoutType'] = $layout['type'];
		}

		if ( isset( $layout['justifyContent'] ) && is_string( $layout['justifyContent'] ) && '' !== $layout['justifyContent'] ) {
			$summary['layoutJustifyContent'] = $layout['justifyContent'];
		}

		if ( isset( $layout['orientation'] ) && is_string( $layout['orientation'] ) && '' !== $layout['orientation'] ) {
			$summary['layoutOrientation'] = $layout['orientation'];
		}

		return $summary;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, string>            $part_area_lookup
	 * @return array<int, array<string, mixed>>
	 */
	public function summarize_template_block_tree( array $blocks, array $part_area_lookup ): array {
		$tree = [];

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$attributes = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$slug       = sanitize_key( (string) ( $attributes['slug'] ?? '' ) );
			$area       = sanitize_key(
				(string) (
					$attributes['area'] ?? (
						'' !== $slug ? ( $part_area_lookup[ $slug ] ?? '' ) : ''
					)
				)
			);
			$entry      = [
				'path'       => [ (int) $index ],
				'name'       => $name,
				'label'      => $this->describe_template_block_label( $name, $slug, $area ),
				'attributes' => $this->summarize_template_part_block_attributes( $attributes ),
				'childCount' => count( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [] ),
			];

			if ( 'core/template-part' === $name ) {
				$entry['slot'] = [
					'slug'    => $slug,
					'area'    => $area,
					'isEmpty' => '' === $slug,
				];
			}

			$tree[] = $entry;
		}

		return $tree;
	}

	/**
	 * @param array<int, array<string, mixed>> $top_level_block_tree
	 * @return array<int, array<string, mixed>>
	 */
	public function collect_template_insertion_anchors( array $top_level_block_tree ): array {
		$anchors = [
			[
				'placement' => 'start',
				'label'     => 'Start of template',
			],
		];

		foreach ( $top_level_block_tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path       = is_array( $node['path'] ?? null ) ? array_map( 'intval', $node['path'] ) : [];
			$block_name = sanitize_text_field( (string) ( $node['name'] ?? '' ) );
			$label      = sanitize_text_field( (string) ( $node['label'] ?? $block_name ) );

			if ( 1 !== count( $path ) || '' === $block_name || '' === $label ) {
				continue;
			}

			$anchors[] = [
				'placement'  => 'before_block_path',
				'targetPath' => $path,
				'blockName'  => $block_name,
				'label'      => 'Before ' . $label,
			];
			$anchors[] = [
				'placement'  => 'after_block_path',
				'targetPath' => $path,
				'blockName'  => $block_name,
				'label'      => 'After ' . $label,
			];
		}

		$anchors[] = [
			'placement' => 'end',
			'label'     => 'End of template',
		];

		return $anchors;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, array<string, mixed>> $top_level_block_tree
	 * @return array<string, mixed>
	 */
	public function collect_template_structure_stats( array $blocks, array $top_level_block_tree ): array {
		$stats        = $this->collect_template_part_block_stats( $blocks );
		$block_counts = $stats['blockCounts'];

		return [
			'blockCount'         => $stats['blockCount'],
			'maxDepth'           => $stats['maxDepth'],
			'topLevelBlockCount' => count( $top_level_block_tree ),
			'hasNavigation'      => ! empty( $block_counts['core/navigation'] ),
			'hasQuery'           => ! empty( $block_counts['core/query'] ),
			'hasTemplateParts'   => ! empty( $block_counts['core/template-part'] ),
			'firstTopLevelBlock' => (string) ( $top_level_block_tree[0]['name'] ?? '' ),
			'lastTopLevelBlock'  => count( $top_level_block_tree ) > 0
				? (string) ( $top_level_block_tree[ count( $top_level_block_tree ) - 1 ]['name'] ?? '' )
				: '',
		];
	}

	public function describe_template_block_label( string $block_name, string $slug = '', string $area = '' ): string {
		if ( 'core/template-part' === $block_name ) {
			if ( '' !== $slug && '' !== $area ) {
				return sprintf( '%s template part (%s)', $slug, $area );
			}

			if ( '' !== $slug ) {
				return sprintf( '%s template part', $slug );
			}

			if ( '' !== $area ) {
				return sprintf( 'Empty %s template-part slot', $area );
			}

			return 'Template-part slot';
		}

		if ( str_starts_with( $block_name, 'core/' ) ) {
			$block_name = substr( $block_name, 5 );
		}

		return ucwords( str_replace( '-', ' ', $block_name ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	public function collect_template_part_operation_targets( array $blocks ): array {
		$targets = [];

		$this->walk_template_part_operation_targets( $blocks, [], false, $targets );

		return $targets;
	}

	/**
	 * @param array<int, array<string, mixed>> $operation_targets
	 * @return array<int, array<string, mixed>>
	 */
	public function collect_template_part_insertion_anchors( array $operation_targets ): array {
		$anchors = [
			[
				'placement' => 'start',
				'label'     => 'Start of template part',
			],
			[
				'placement' => 'end',
				'label'     => 'End of template part',
			],
		];

		foreach ( $operation_targets as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$path       = is_array( $target['path'] ?? null ) ? array_map( 'intval', $target['path'] ) : [];
			$block_name = sanitize_text_field( (string) ( $target['name'] ?? '' ) );
			$label      = sanitize_text_field( (string) ( $target['label'] ?? $block_name ) );

			if ( [] === $path || '' === $block_name || '' === $label ) {
				continue;
			}

			$anchors[] = [
				'placement'  => 'before_block_path',
				'targetPath' => $path,
				'blockName'  => $block_name,
				'label'      => 'Before ' . $label,
			];
			$anchors[] = [
				'placement'  => 'after_block_path',
				'targetPath' => $path,
				'blockName'  => $block_name,
				'label'      => 'After ' . $label,
			];
		}

		return $anchors;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<string, mixed>
	 */
	public function collect_template_part_structural_constraints( array $blocks ): array {
		$constraints = [
			'contentOnlyPaths' => [],
			'lockedPaths'      => [],
		];

		$this->walk_template_part_structural_constraints( $blocks, [], $constraints );

		return [
			'contentOnlyPaths' => $constraints['contentOnlyPaths'],
			'lockedPaths'      => $constraints['lockedPaths'],
			'hasContentOnly'   => count( $constraints['contentOnlyPaths'] ) > 0,
			'hasLockedBlocks'  => count( $constraints['lockedPaths'] ) > 0,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array{blockCount: int, maxDepth: int, blockCounts: array<string, int>}
	 */
	public function collect_template_part_block_stats( array $blocks ): array {
		$stats = [
			'blockCount'  => 0,
			'maxDepth'    => 0,
			'blockCounts' => [],
		];

		$this->walk_template_part_blocks( $blocks, 1, $stats );

		return $stats;
	}

	private function is_explicit_empty_template_part_area( string $area ): bool {
		return '' !== $area && 'uncategorized' !== $area;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @param array<int, array<string, mixed>> $targets
	 */
	private function walk_template_part_operation_targets( array $blocks, array $path, bool $inside_content_only, array &$targets ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$attributes                = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$template_lock             = (string) ( $attributes['templateLock'] ?? '' );
			$is_content_only_container = 'contentonly' === strtolower( trim( $template_lock ) );
			$lock                      = is_array( $attributes['lock'] ?? null ) ? $attributes['lock'] : [];
			$next_path                 = array_merge( $path, [ (int) $index ] );
			$can_target                = ! $inside_content_only && ! $is_content_only_container;
			$allowed_operations        = [];

			if ( $can_target ) {
				if ( '' === $template_lock && empty( $lock ) ) {
					$allowed_operations[] = 'replace_block_with_pattern';
					$allowed_operations[] = 'remove_block';
				}

				$targets[] = [
					'path'              => $next_path,
					'name'              => $name,
					'label'             => $this->describe_template_block_label( $name ),
					'allowedOperations' => $allowed_operations,
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				];
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( count( $inner_blocks ) > 0 ) {
				$this->walk_template_part_operation_targets(
					$inner_blocks,
					$next_path,
					$inside_content_only || $is_content_only_container,
					$targets
				);
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>>                  $blocks
	 * @param array<int, int>                                   $path
	 * @param array{contentOnlyPaths: array<int, array<int, int>>, lockedPaths: array<int, array<int, int>>} $constraints
	 */
	private function walk_template_part_structural_constraints( array $blocks, array $path, array &$constraints ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$attributes    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$template_lock = (string) ( $attributes['templateLock'] ?? '' );
			$lock          = is_array( $attributes['lock'] ?? null ) ? $attributes['lock'] : [];
			$next_path     = array_merge( $path, [ (int) $index ] );

			if ( 'contentonly' === strtolower( trim( $template_lock ) ) ) {
				$constraints['contentOnlyPaths'][] = $next_path;
			}

			if ( '' !== $template_lock || ! empty( $lock ) ) {
				$constraints['lockedPaths'][] = $next_path;
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( count( $inner_blocks ) > 0 ) {
				$this->walk_template_part_structural_constraints( $inner_blocks, $next_path, $constraints );
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>>                                  $blocks
	 * @param array{blockCount: int, maxDepth: int, blockCounts: array<string, int>} $stats
	 */
	private function walk_template_part_blocks( array $blocks, int $depth, array &$stats ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			++$stats['blockCount'];
			$stats['maxDepth']             = max( $stats['maxDepth'], $depth );
			$stats['blockCounts'][ $name ] = ( $stats['blockCounts'][ $name ] ?? 0 ) + 1;

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( count( $inner_blocks ) > 0 ) {
				$this->walk_template_part_blocks( $inner_blocks, $depth + 1, $stats );
			}
		}
	}
}
