<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class PatternOverrideAnalyzer {

	public function __construct(
		private BlockTypeIntrospector $block_type_introspector,
		private TemplateStructureAnalyzer $template_structure_analyzer
	) {
	}

	/**
	 * Summarize whether a pattern contains explicit Pattern Overrides bindings.
	 *
	 * This stays recommendation-oriented: we surface presence and affected block
	 * attributes so ranking/explanations can prefer override-friendly patterns
	 * without widening mutation behavior.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_pattern_override_metadata( string $content ): array {
		if ( '' === $content ) {
			return $this->empty_pattern_override_metadata();
		}

		$blocks = parse_blocks( $content );

		if ( [] === $blocks ) {
			return $this->empty_pattern_override_metadata();
		}

		$summary = [
			'hasOverrides'          => false,
			'blockCount'            => 0,
			'blockNames'            => [],
			'bindableAttributes'    => [],
			'overrideAttributes'    => [],
			'usesDefaultBinding'    => false,
			'unsupportedAttributes' => [],
		];

		$this->walk_pattern_override_metadata( $blocks, $summary );

		$summary['blockNames'] = array_values( array_keys( $summary['blockNames'] ) );
		ksort( $summary['bindableAttributes'] );
		ksort( $summary['overrideAttributes'] );
		ksort( $summary['unsupportedAttributes'] );

		foreach ( [ 'bindableAttributes', 'overrideAttributes', 'unsupportedAttributes' ] as $map_key ) {
			$summary[ $map_key ] = array_map(
				static function ( array $attributes ): array {
					sort( $attributes );
					return array_values( array_unique( $attributes ) );
				},
				$summary[ $map_key ]
			);
		}

		return $summary;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, string>            $part_area_lookup
	 * @return array<string, mixed>
	 */
	public function collect_current_pattern_override_summary( array $blocks, array $part_area_lookup = [] ): array {
		$summary = [
			'hasOverrides' => false,
			'blockCount'   => 0,
			'blockNames'   => [],
			'blocks'       => [],
		];

		$this->walk_current_pattern_override_summary( $blocks, [], $part_area_lookup, $summary );

		$summary['blockNames'] = array_values( array_keys( $summary['blockNames'] ) );
		sort( $summary['blockNames'] );

		return $summary;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, mixed>             $summary
	 */
	private function walk_pattern_override_metadata( array $blocks, array &$summary ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );
			$attrs      = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$metadata   = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : [];
			$bindings   = is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : [];

			if ( '' !== $block_name && [] !== $bindings ) {
				$summary['bindableAttributes'][ $block_name ] = $this->block_type_introspector->resolve_bindable_attributes( $block_name );
				$override_attributes                          = [];
				$unsupported                                  = [];
				$bindable_lookup                              = array_fill_keys(
					$summary['bindableAttributes'][ $block_name ] ?? [],
					true
				);

				foreach ( $bindings as $attribute_name => $binding ) {
					if ( ! is_string( $attribute_name ) || ! is_array( $binding ) ) {
						continue;
					}

					$source = sanitize_text_field( (string) ( $binding['source'] ?? '' ) );

					if ( 'core/pattern-overrides' !== $source ) {
						continue;
					}

					$summary['hasOverrides'] = true;

					if ( '__default' === $attribute_name ) {
						$summary['usesDefaultBinding'] = true;
						$override_attributes           = array_merge(
							$override_attributes,
							$summary['bindableAttributes'][ $block_name ] ?? []
						);
						continue;
					}

					if ( isset( $bindable_lookup[ $attribute_name ] ) ) {
						$override_attributes[] = $attribute_name;
					} else {
						$unsupported[] = $attribute_name;
					}
				}

				if ( [] !== $override_attributes || [] !== $unsupported || $summary['usesDefaultBinding'] ) {
					++$summary['blockCount'];
					$summary['blockNames'][ $block_name ] = true;
				}

				if ( [] !== $override_attributes ) {
					$summary['overrideAttributes'][ $block_name ] = array_merge(
						$summary['overrideAttributes'][ $block_name ] ?? [],
						$override_attributes
					);
				}

				if ( [] !== $unsupported ) {
					$summary['unsupportedAttributes'][ $block_name ] = array_merge(
						$summary['unsupportedAttributes'][ $block_name ] ?? [],
						$unsupported
					);
				}
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( [] !== $inner_blocks ) {
				$this->walk_pattern_override_metadata( $inner_blocks, $summary );
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function empty_pattern_override_metadata(): array {
		return [
			'hasOverrides'          => false,
			'blockCount'            => 0,
			'blockNames'            => [],
			'bindableAttributes'    => [],
			'overrideAttributes'    => [],
			'usesDefaultBinding'    => false,
			'unsupportedAttributes' => [],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @param array<string, string>            $part_area_lookup
	 * @param array<string, mixed>             $summary
	 */
	private function walk_current_pattern_override_summary( array $blocks, array $path, array $part_area_lookup, array &$summary ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$attributes           = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$metadata             = is_array( $attributes['metadata'] ?? null ) ? $attributes['metadata'] : [];
			$bindings             = is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : [];
			$next_path            = array_merge( $path, [ (int) $index ] );
			$bindable_attributes  = $this->block_type_introspector->resolve_bindable_attributes( $name );
			$bindable_lookup      = array_fill_keys( $bindable_attributes, true );
			$override_attributes  = [];
			$unsupported          = [];
			$uses_default_binding = false;

			foreach ( $bindings as $attribute_name => $binding ) {
				if ( ! is_string( $attribute_name ) || ! is_array( $binding ) ) {
					continue;
				}

				$source = sanitize_text_field( (string) ( $binding['source'] ?? '' ) );

				if ( 'core/pattern-overrides' !== $source ) {
					continue;
				}

				if ( '__default' === $attribute_name ) {
					$uses_default_binding = true;
					$override_attributes  = array_merge( $override_attributes, $bindable_attributes );
					continue;
				}

				if ( isset( $bindable_lookup[ $attribute_name ] ) ) {
					$override_attributes[] = $attribute_name;
				} else {
					$unsupported[] = $attribute_name;
				}
			}

			if ( [] !== $override_attributes || [] !== $unsupported || $uses_default_binding ) {
				$slug                    = sanitize_key( (string) ( $attributes['slug'] ?? '' ) );
				$area                    = sanitize_key(
					(string) (
						$attributes['area'] ?? (
							'' !== $slug ? ( $part_area_lookup[ $slug ] ?? '' ) : ''
						)
					)
				);
				$summary['hasOverrides'] = true;
				++$summary['blockCount'];
				$summary['blockNames'][ $name ] = true;

				$entry = [
					'path'               => $next_path,
					'name'               => $name,
					'label'              => $this->template_structure_analyzer->describe_template_block_label( $name, $slug, $area ),
					'overrideAttributes' => array_values( array_unique( $override_attributes ) ),
					'usesDefaultBinding' => $uses_default_binding,
				];

				if ( [] !== $bindable_attributes ) {
					$entry['bindableAttributes'] = array_values( array_unique( $bindable_attributes ) );
				}

				if ( [] !== $unsupported ) {
					$entry['unsupportedAttributes'] = array_values( array_unique( $unsupported ) );
				}

				$summary['blocks'][] = $entry;
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( [] !== $inner_blocks ) {
				$this->walk_current_pattern_override_summary( $inner_blocks, $next_path, $part_area_lookup, $summary );
			}
		}
	}
}
