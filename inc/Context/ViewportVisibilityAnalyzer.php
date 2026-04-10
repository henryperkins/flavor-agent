<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class ViewportVisibilityAnalyzer {

	public function __construct(
		private TemplateStructureAnalyzer $template_structure_analyzer
	) {
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, string>            $part_area_lookup
	 * @return array<string, mixed>
	 */
	public function collect_current_viewport_visibility_summary( array $blocks, array $part_area_lookup = [] ): array {
		$summary = [
			'hasVisibilityRules' => false,
			'blockCount'         => 0,
			'blocks'             => [],
		];

		$this->walk_current_viewport_visibility_summary( $blocks, [], $part_area_lookup, $summary );

		return $summary;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @param array<string, string>            $part_area_lookup
	 * @param array<string, mixed>             $summary
	 */
	private function walk_current_viewport_visibility_summary( array $blocks, array $path, array $part_area_lookup, array &$summary ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$attributes         = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$metadata           = is_array( $attributes['metadata'] ?? null ) ? $attributes['metadata'] : [];
			$visibility_summary = $this->summarize_viewport_visibility_rules( $metadata['blockVisibility'] ?? null );
			$next_path          = array_merge( $path, [ (int) $index ] );

			if ( null !== $visibility_summary ) {
				$slug                          = sanitize_key( (string) ( $attributes['slug'] ?? '' ) );
				$area                          = sanitize_key(
					(string) (
						$attributes['area'] ?? (
							'' !== $slug ? ( $part_area_lookup[ $slug ] ?? '' ) : ''
						)
					)
				);
				$summary['hasVisibilityRules'] = true;
				++$summary['blockCount'];
				$summary['blocks'][] = [
					'path'             => $next_path,
					'name'             => $name,
					'label'            => $this->template_structure_analyzer->describe_template_block_label( $name, $slug, $area ),
					'hiddenViewports'  => $visibility_summary['hiddenViewports'],
					'visibleViewports' => $visibility_summary['visibleViewports'],
				];
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( [] !== $inner_blocks ) {
				$this->walk_current_viewport_visibility_summary( $inner_blocks, $next_path, $part_area_lookup, $summary );
			}
		}
	}

	/**
	 * @return array{hiddenViewports: string[], visibleViewports: string[]}|null
	 */
	private function summarize_viewport_visibility_rules( mixed $block_visibility ): ?array {
		if ( false === $block_visibility ) {
			return [
				'hiddenViewports'  => [ 'all' ],
				'visibleViewports' => [],
			];
		}

		if ( ! is_array( $block_visibility ) ) {
			return null;
		}

		$viewport_rules = is_array( $block_visibility['viewport'] ?? null ) ? $block_visibility['viewport'] : [];
		$hidden         = [];
		$visible        = [];

		foreach ( $viewport_rules as $viewport => $value ) {
			if ( ! is_string( $viewport ) || ! is_bool( $value ) ) {
				continue;
			}

			$normalized_viewport = sanitize_key( $viewport );

			if ( '' === $normalized_viewport ) {
				continue;
			}

			if ( $value ) {
				$visible[] = $normalized_viewport;
			} else {
				$hidden[] = $normalized_viewport;
			}
		}

		if ( [] === $hidden && [] === $visible ) {
			return null;
		}

		sort( $hidden );
		sort( $visible );

		return [
			'hiddenViewports'  => array_values( array_unique( $hidden ) ),
			'visibleViewports' => array_values( array_unique( $visible ) ),
		];
	}
}
