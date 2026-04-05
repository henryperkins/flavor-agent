<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

use FlavorAgent\Support\StringArray;

final class ServerCollector {

	public const TEMPLATE_PATTERN_CANDIDATE_CAP = 30;

	// Keep this map in sync with src/context/block-inspector.js.
	private const SUPPORT_TO_PANEL = [
		'color.background'           => 'color',
		'color.text'                 => 'color',
		'color.link'                 => 'color',
		'color.heading'              => 'color',
		'color.button'               => 'color',
		'color.gradients'            => 'color',
		'typography.fontSize'        => 'typography',
		'typography.fitText'         => 'typography',
		'typography.lineHeight'      => 'typography',
		'typography.textAlign'       => 'typography',
		'typography.textIndent'      => 'typography',
		'spacing.margin'             => 'dimensions',
		'spacing.padding'            => 'dimensions',
		'spacing.blockGap'           => 'dimensions',
		'dimensions.aspectRatio'     => 'dimensions',
		'dimensions.minHeight'       => 'dimensions',
		'dimensions.height'          => 'dimensions',
		'dimensions.width'           => 'dimensions',
		'border.color'               => 'border',
		'border.radius'              => 'border',
		'border.style'               => 'border',
		'border.width'               => 'border',
		'shadow'                     => 'shadow',
		'filter.duotone'             => 'filter',
		'background.backgroundImage' => 'background',
		'background.backgroundSize'  => 'background',
		'position.sticky'            => 'position',
		'position.fixed'             => 'position',
		'layout'                     => 'layout',
		'anchor'                     => 'advanced',
		'customCSS'                  => 'advanced',
		'listView'                   => 'list',
	];

	private const GENERAL_PANEL_EXCLUDED_ATTRIBUTES = [
		'className' => true,
		'metadata'  => true,
		'style'     => true,
		'lock'      => true,
	];

	private const DEFAULT_BINDABLE_ATTRIBUTES = [
		'core/paragraph' => [ 'content' ],
		'core/heading'   => [ 'content' ],
		'core/image'     => [ 'id', 'url', 'title', 'alt' ],
		'core/button'    => [ 'url', 'text', 'linkTarget', 'rel' ],
	];

	private const KNOWN_TEMPLATE_TYPES = [
		'index',
		'home',
		'front-page',
		'singular',
		'single',
		'page',
		'archive',
		'author',
		'category',
		'tag',
		'taxonomy',
		'date',
		'search',
		'404',
	];

	public static function introspect_block_type( string $block_name ): ?array {
		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( $block_name );

		if ( ! $block_type ) {
			return null;
		}

		$supports              = $block_type->supports ?? [];
		$supports_content_role = ! empty( $supports['contentRole'] );
		$attributes            = $block_type->attributes ?? [];
		$styles                = $block_type->styles ?? [];
		$variations            = $block_type->variations ?? [];
		$bindable_attributes   = self::resolve_bindable_attributes( $block_name );

		$content_attrs = [];
		$config_attrs  = [];
		foreach ( $attributes as $name => $def ) {
			$role  = self::resolve_attribute_role( $def );
			$entry = [
				'type'    => $def['type'] ?? null,
				'default' => $def['default'] ?? null,
				'role'    => $role,
			];
			if ( isset( $def['enum'] ) ) {
				$entry['enum'] = $def['enum'];
			}
			if ( isset( $def['source'] ) ) {
				$entry['source'] = $def['source'];
			}

			if ( 'content' === $role ) {
				$content_attrs[ $name ] = $entry;
			} else {
				$config_attrs[ $name ] = $entry;
			}
		}

		return [
			'name'                => $block_name,
			'title'               => $block_type->title ?? '',
			'category'            => $block_type->category ?? '',
			'description'         => $block_type->description ?? '',
			'supports'            => $supports,
			'supportsContentRole' => $supports_content_role,
			'inspectorPanels'     => self::merge_bindings_inspector_panel(
				self::merge_general_inspector_panel(
					self::resolve_inspector_panels( $supports ),
					$config_attrs
				),
				$bindable_attributes
			),
			'bindableAttributes'  => $bindable_attributes,
			'contentAttributes'   => $content_attrs,
			'configAttributes'    => $config_attrs,
			'styles'              => array_map(
				fn( $s ) => [
					'name'      => $s['name'] ?? '',
					'label'     => $s['label'] ?? '',
					'isDefault' => $s['isDefault'] ?? false,
				],
				$styles
			),
			'variations'          => array_map(
				fn( $v ) => [
					'name'        => $v['name'] ?? '',
					'title'       => $v['title'] ?? '',
					'description' => $v['description'] ?? '',
					'scope'       => $v['scope'] ?? null,
				],
				array_slice( $variations, 0, 10 )
			),
			'parent'              => $block_type->parent ?? null,
			'allowedBlocks'       => $block_type->allowed_blocks ?? null,
			'apiVersion'          => $block_type->api_version ?? 1,
		];
	}

	public static function resolve_inspector_panels( array $supports ): array {
		$panels = [];
		$flat   = self::flatten_supports( $supports );

		foreach ( $flat as [ $path, $value ] ) {
			$panel_key = self::SUPPORT_TO_PANEL[ $path ] ?? null;
			if ( $panel_key && self::is_truthy( $value ) ) {
				$panels[ $panel_key ]   = $panels[ $panel_key ] ?? [];
				$panels[ $panel_key ][] = $path;
			}
		}

		return $panels;
	}

	/**
	 * @return string[]
	 */
	private static function resolve_bindable_attributes( string $block_name ): array {
		if ( function_exists( 'get_block_bindings_supported_attributes' ) ) {
			return StringArray::sanitize( \get_block_bindings_supported_attributes( $block_name ) );
		}

		return StringArray::sanitize( self::DEFAULT_BINDABLE_ATTRIBUTES[ $block_name ] ?? [] );
	}

	/**
	 * @param string[] $bindable_attributes
	 */
	private static function merge_bindings_inspector_panel( array $inspector_panels, array $bindable_attributes ): array {
		if ( [] === $bindable_attributes ) {
			return $inspector_panels;
		}

		$inspector_panels['bindings'] = $bindable_attributes;

		return $inspector_panels;
	}

	private static function merge_general_inspector_panel( array $inspector_panels, array $config_attributes ): array {
		$general_attributes = array_values(
			array_filter(
				array_keys( $config_attributes ),
				static fn( $attribute_name ): bool =>
					is_string( $attribute_name )
					&& $attribute_name !== ''
					&& ! isset( self::GENERAL_PANEL_EXCLUDED_ATTRIBUTES[ $attribute_name ] )
			)
		);

		if ( [] === $general_attributes ) {
			return $inspector_panels;
		}

		$existing_general = StringArray::sanitize( $inspector_panels['general'] ?? [] );

		$inspector_panels['general'] = array_values(
			array_unique(
				array_merge( $existing_general, $general_attributes )
			)
		);

		return $inspector_panels;
	}

	public static function for_tokens(): array {
		$settings      = wp_get_global_settings();
		$global_styles = wp_get_global_styles();

		$color_presets = array_map(
			static fn( array $preset ): array => [
				'name'   => (string) ( $preset['name'] ?? '' ),
				'slug'   => (string) ( $preset['slug'] ?? '' ),
				'color'  => (string) ( $preset['color'] ?? '' ),
				'cssVar' => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--color--' . (string) $preset['slug'] . ')'
					: '',
			],
			self::merge_presets( $settings['color']['palette'] ?? [] )
		);
		$colors        = [];
		foreach ( $color_presets as $c ) {
			$colors[] = ( $c['slug'] ?? '' ) . ': ' . ( $c['color'] ?? '' );
		}

		$gradient_presets = array_map(
			static fn( array $preset ): array => [
				'name'     => (string) ( $preset['name'] ?? '' ),
				'slug'     => (string) ( $preset['slug'] ?? '' ),
				'gradient' => (string) ( $preset['gradient'] ?? '' ),
				'cssVar'   => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--gradient--' . (string) $preset['slug'] . ')'
					: '',
			],
			self::merge_presets( $settings['color']['gradients'] ?? [] )
		);
		$gradients        = [];
		foreach ( $gradient_presets as $g ) {
			$slug        = (string) ( $g['slug'] ?? '' );
			$gradient    = (string) ( $g['gradient'] ?? '' );
			$gradients[] = $gradient !== ''
				? "{$slug}: {$gradient}"
				: $slug;
		}

		$font_size_presets = array_map(
			static fn( array $preset ): array => [
				'name'   => (string) ( $preset['name'] ?? '' ),
				'slug'   => (string) ( $preset['slug'] ?? '' ),
				'size'   => (string) ( $preset['size'] ?? '' ),
				'fluid'  => isset( $preset['fluid'] ) ? $preset['fluid'] : null,
				'cssVar' => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--font-size--' . (string) $preset['slug'] . ')'
					: '',
			],
			self::merge_presets( $settings['typography']['fontSizes'] ?? [] )
		);
		$font_sizes        = [];
		foreach ( $font_size_presets as $fs ) {
			$font_sizes[] = ( $fs['slug'] ?? '' ) . ': ' . ( $fs['size'] ?? '' );
		}

		$font_family_presets = array_map(
			static fn( array $preset ): array => [
				'name'       => (string) ( $preset['name'] ?? '' ),
				'slug'       => (string) ( $preset['slug'] ?? '' ),
				'fontFamily' => (string) ( $preset['fontFamily'] ?? '' ),
				'cssVar'     => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--font-family--' . (string) $preset['slug'] . ')'
					: '',
			],
			self::merge_presets( $settings['typography']['fontFamilies'] ?? [] )
		);
		$font_families       = [];
		foreach ( $font_family_presets as $ff ) {
			$font_families[] = ( $ff['slug'] ?? '' ) . ': ' . ( $ff['fontFamily'] ?? '' );
		}

		$spacing_presets = array_map(
			static fn( array $preset ): array => [
				'name'   => (string) ( $preset['name'] ?? '' ),
				'slug'   => (string) ( $preset['slug'] ?? '' ),
				'size'   => (string) ( $preset['size'] ?? '' ),
				'cssVar' => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--spacing--' . (string) $preset['slug'] . ')'
					: '',
			],
			self::merge_presets( $settings['spacing']['spacingSizes'] ?? [] )
		);
		$spacing         = [];
		foreach ( $spacing_presets as $s ) {
			$spacing[] = ( $s['slug'] ?? '' ) . ': ' . ( $s['size'] ?? '' );
		}

		$shadow_presets = array_map(
			static fn( array $preset ): array => [
				'name'   => (string) ( $preset['name'] ?? '' ),
				'slug'   => (string) ( $preset['slug'] ?? '' ),
				'shadow' => (string) ( $preset['shadow'] ?? '' ),
				'cssVar' => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--shadow--' . (string) $preset['slug'] . ')'
					: '',
			],
			self::merge_presets( $settings['shadow']['presets'] ?? [] )
		);
		$shadows        = [];
		foreach ( $shadow_presets as $s ) {
			$shadows[] = ( $s['slug'] ?? '' ) . ': ' . ( $s['shadow'] ?? '' );
		}

		$duotone = [];
		foreach ( self::merge_presets( $settings['color']['duotone'] ?? [] ) as $preset ) {
			$slug          = (string) ( $preset['slug'] ?? '' );
			$preset_colors = is_array( $preset['colors'] ?? null ) ? $preset['colors'] : [];

			if ( $slug === '' ) {
				continue;
			}

			$color_summary = implode( ' / ', array_map( 'strval', array_slice( $preset_colors, 0, 2 ) ) );
			$duotone[]     = $color_summary !== ''
				? sprintf( '%s: %s', $slug, $color_summary )
				: $slug;
		}

		return [
			'colors'            => $colors,
			'colorPresets'      => $color_presets,
			'gradients'         => $gradients,
			'gradientPresets'   => $gradient_presets,
			'fontSizes'         => $font_sizes,
			'fontSizePresets'   => $font_size_presets,
			'fontFamilies'      => $font_families,
			'fontFamilyPresets' => $font_family_presets,
			'spacing'           => $spacing,
			'spacingPresets'    => $spacing_presets,
			'shadows'           => $shadows,
			'shadowPresets'     => $shadow_presets,
			'duotone'           => $duotone,
			'diagnostics'       => [
				'source'      => 'server',
				'settingsKey' => 'wp_get_global_settings',
				'reason'      => 'server-global-settings',
			],
			'duotonePresets'    => array_map(
				static fn( array $preset ): array => [
					'slug'   => (string) ( $preset['slug'] ?? '' ),
					'colors' => is_array( $preset['colors'] ?? null ) ? array_values( $preset['colors'] ) : [],
				],
				self::merge_presets( $settings['color']['duotone'] ?? [] )
			),
			'layout'            => [
				'content'                       => $settings['layout']['contentSize'] ?? '',
				'wide'                          => $settings['layout']['wideSize'] ?? '',
				'allowEditing'                  => $settings['layout']['allowEditing'] ?? true,
				'allowCustomContentAndWideSize' => $settings['layout']['allowCustomContentAndWideSize'] ?? true,
			],
			'enabledFeatures'   => [
				'lineHeight'      => $settings['typography']['lineHeight'] ?? false,
				'dropCap'         => $settings['typography']['dropCap'] ?? true,
				'customColors'    => $settings['color']['custom'] ?? true,
				'backgroundColor' => array_key_exists( 'background', $settings['color'] ?? [] )
					? (bool) $settings['color']['background']
					: true,
				'textColor'       => array_key_exists( 'text', $settings['color'] ?? [] )
					? (bool) $settings['color']['text']
					: true,
				'linkColor'       => $settings['color']['link'] ?? false,
				'buttonColor'     => $settings['color']['button'] ?? false,
				'headingColor'    => $settings['color']['heading'] ?? false,
				'margin'          => $settings['spacing']['margin'] ?? false,
				'padding'         => $settings['spacing']['padding'] ?? false,
				'blockGap'        => $settings['spacing']['blockGap'] ?? null,
				'borderColor'     => $settings['border']['color'] ?? false,
				'borderRadius'    => $settings['border']['radius'] ?? false,
				'borderStyle'     => $settings['border']['style'] ?? false,
				'borderWidth'     => $settings['border']['width'] ?? false,
				'backgroundImage' => $settings['background']['backgroundImage'] ?? false,
				'backgroundSize'  => $settings['background']['backgroundSize'] ?? false,
			],
			'elementStyles'     => self::collect_element_styles( $global_styles ),
			'blockPseudoStyles' => self::collect_block_pseudo_styles( $global_styles ),
		];
	}

	public static function for_block( string $block_name, array $attributes = [], array $inner_blocks = [], bool $is_inside_content_only = false ): array {
		$type_info = self::introspect_block_type( $block_name );

		return [
			'block'          => [
				'name'                => $block_name,
				'title'               => $type_info['title'] ?? '',
				'currentAttributes'   => $attributes,
				'inspectorPanels'     => $type_info['inspectorPanels'] ?? [],
				'styles'              => $type_info['styles'] ?? [],
				'activeStyle'         => self::extract_active_style( $attributes['className'] ?? '', $type_info['styles'] ?? [] ),
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
			'themeTokens'    => self::for_tokens(),
		];
	}

	public static function for_patterns( ?array $categories = null, ?array $block_types = null, ?array $template_types = null ): array {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		$all      = $registry->get_all_registered();
		$result   = [];

		foreach ( $all as $pattern ) {
			if ( $categories !== null ) {
				$pattern_cats = $pattern['categories'] ?? [];
				if ( empty( array_intersect( $categories, $pattern_cats ) ) ) {
					continue;
				}
			}
			if ( $block_types !== null ) {
				$pattern_bt = $pattern['blockTypes'] ?? [];
				if ( empty( array_intersect( $block_types, $pattern_bt ) ) ) {
					continue;
				}
			}
			if ( $template_types !== null ) {
				$pattern_tt = $pattern['templateTypes'] ?? [];
				if ( empty( array_intersect( $template_types, $pattern_tt ) ) ) {
					continue;
				}
			}

			$result[] = [
				'name'          => $pattern['name'] ?? '',
				'title'         => $pattern['title'] ?? '',
				'description'   => $pattern['description'] ?? '',
				'categories'    => $pattern['categories'] ?? [],
				'blockTypes'    => $pattern['blockTypes'] ?? [],
				'templateTypes' => $pattern['templateTypes'] ?? [],
				'patternOverrides' => self::collect_pattern_override_metadata(
					(string) ( $pattern['content'] ?? '' )
				),
				'content'       => $pattern['content'] ?? '',
			];
		}

		return $result;
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
	private static function collect_pattern_override_metadata( string $content ): array {
		if ( $content === '' ) {
			return self::empty_pattern_override_metadata();
		}

		$blocks = parse_blocks( $content );

		if ( [] === $blocks ) {
			return self::empty_pattern_override_metadata();
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

		self::walk_pattern_override_metadata( $blocks, $summary );

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
	 * @param array<string, mixed>             $summary
	 */
	private static function walk_pattern_override_metadata( array $blocks, array &$summary ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );
			$attrs      = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$metadata   = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : [];
			$bindings   = is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : [];

			if ( $block_name !== '' && [] !== $bindings ) {
				$summary['bindableAttributes'][ $block_name ] = self::resolve_bindable_attributes( $block_name );
				$override_attributes = [];
				$unsupported         = [];
				$bindable_lookup     = array_fill_keys(
					$summary['bindableAttributes'][ $block_name ] ?? [],
					true
				);

				foreach ( $bindings as $attribute_name => $binding ) {
					if ( ! is_string( $attribute_name ) || ! is_array( $binding ) ) {
						continue;
					}

					$source = sanitize_text_field( (string) ( $binding['source'] ?? '' ) );
					if ( $source !== 'core/pattern-overrides' ) {
						continue;
					}

					$summary['hasOverrides'] = true;

					if ( $attribute_name === '__default' ) {
						$summary['usesDefaultBinding'] = true;
						$override_attributes          = array_merge(
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
					$summary['blockCount'] += 1;
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
				self::walk_pattern_override_metadata( $inner_blocks, $summary );
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function empty_pattern_override_metadata(): array {
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
	 * @param array<string, string>            $part_area_lookup
	 * @return array<string, mixed>
	 */
	private static function collect_current_pattern_override_summary( array $blocks, array $part_area_lookup = [] ): array {
		$summary = [
			'hasOverrides' => false,
			'blockCount'   => 0,
			'blockNames'   => [],
			'blocks'       => [],
		];

		self::walk_current_pattern_override_summary( $blocks, [], $part_area_lookup, $summary );

		$summary['blockNames'] = array_values( array_keys( $summary['blockNames'] ) );
		sort( $summary['blockNames'] );

		return $summary;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @param array<string, string>            $part_area_lookup
	 * @param array<string, mixed>             $summary
	 */
	private static function walk_current_pattern_override_summary( array $blocks, array $path, array $part_area_lookup, array &$summary ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$attributes          = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$metadata            = is_array( $attributes['metadata'] ?? null ) ? $attributes['metadata'] : [];
			$bindings            = is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : [];
			$next_path           = array_merge( $path, [ (int) $index ] );
			$bindable_attributes = self::resolve_bindable_attributes( $name );
			$bindable_lookup     = array_fill_keys( $bindable_attributes, true );
			$override_attributes = [];
			$unsupported         = [];
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
				$slug                      = sanitize_key( (string) ( $attributes['slug'] ?? '' ) );
				$area                      = sanitize_key(
					(string) (
						$attributes['area'] ?? (
							$slug !== '' ? ( $part_area_lookup[ $slug ] ?? '' ) : ''
						)
					)
				);
				$summary['hasOverrides']   = true;
				++$summary['blockCount'];
				$summary['blockNames'][ $name ] = true;

				$entry = [
					'path'               => $next_path,
					'name'               => $name,
					'label'              => self::describe_template_block_label( $name, $slug, $area ),
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
				self::walk_current_pattern_override_summary( $inner_blocks, $next_path, $part_area_lookup, $summary );
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, string>            $part_area_lookup
	 * @return array<string, mixed>
	 */
	private static function collect_current_viewport_visibility_summary( array $blocks, array $part_area_lookup = [] ): array {
		$summary = [
			'hasVisibilityRules' => false,
			'blockCount'         => 0,
			'blocks'             => [],
		];

		self::walk_current_viewport_visibility_summary( $blocks, [], $part_area_lookup, $summary );

		return $summary;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @param array<string, string>            $part_area_lookup
	 * @param array<string, mixed>             $summary
	 */
	private static function walk_current_viewport_visibility_summary( array $blocks, array $path, array $part_area_lookup, array &$summary ): void {
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
			$visibility_summary = self::summarize_viewport_visibility_rules( $metadata['blockVisibility'] ?? null );
			$next_path          = array_merge( $path, [ (int) $index ] );

			if ( null !== $visibility_summary ) {
				$slug  = sanitize_key( (string) ( $attributes['slug'] ?? '' ) );
				$area  = sanitize_key(
					(string) (
						$attributes['area'] ?? (
							$slug !== '' ? ( $part_area_lookup[ $slug ] ?? '' ) : ''
						)
					)
				);
				$summary['hasVisibilityRules'] = true;
				++$summary['blockCount'];
				$summary['blocks'][] = [
					'path'             => $next_path,
					'name'             => $name,
					'label'            => self::describe_template_block_label( $name, $slug, $area ),
					'hiddenViewports'  => $visibility_summary['hiddenViewports'],
					'visibleViewports' => $visibility_summary['visibleViewports'],
				];
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( [] !== $inner_blocks ) {
				self::walk_current_viewport_visibility_summary( $inner_blocks, $next_path, $part_area_lookup, $summary );
			}
		}
	}

	/**
	 * @return array{hiddenViewports: string[], visibleViewports: string[]}|null
	 */
	private static function summarize_viewport_visibility_rules( mixed $block_visibility ): ?array {
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

	public static function for_template_parts( ?string $area = null, bool $include_content = true ): array {
		$query = [];

		if ( $area !== null && $area !== '' ) {
			$query['area'] = sanitize_key( $area );
		}

		$parts  = get_block_templates( $query, 'wp_template_part' );
		$result = [];

		foreach ( $parts as $part ) {
			$entry = [
				'slug'  => $part->slug ?? '',
				'title' => $part->title ?? '',
				'area'  => $part->area ?? '',
			];

			if ( $include_content ) {
				$entry['content'] = $part->content ?? '';
			}

			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * Assemble context for a single template-part document.
	 *
	 * @param string $template_part_ref Template-part identifier from the Site Editor.
	 * @return array|\WP_Error Template-part context or error.
	 */
	public static function for_template_part( string $template_part_ref, ?array $visible_pattern_names = null ): array|\WP_Error {
		$template_part = self::resolve_template_part( $template_part_ref );

		if ( ! $template_part ) {
			return new \WP_Error(
				'template_part_not_found',
				'Could not resolve the current template part from the Site Editor context.',
				[ 'status' => 404 ]
			);
		}

		$content                = (string) ( $template_part->content ?? '' );
		$slug                   = sanitize_key( (string) ( $template_part->slug ?? '' ) );
		$area                   = sanitize_key( (string) ( $template_part->area ?? '' ) );
		$title_source           = (string) ( $template_part->title ?? '' );
		$title                  = sanitize_text_field(
			$title_source !== ''
				? $title_source
				: ( $slug !== '' ? $slug : $template_part_ref )
		);
		$blocks                 = parse_blocks( $content );
		$block_tree             = self::summarize_template_part_block_tree( $blocks );
		$operation_targets      = self::collect_template_part_operation_targets( $blocks );
		$insertion_anchors      = self::collect_template_part_insertion_anchors( $operation_targets );
		$structural_constraints = self::collect_template_part_structural_constraints( $blocks );
		$top_level_blocks       = array_values(
			array_filter(
				array_map(
					static fn( array $block ): string => (string) ( $block['blockName'] ?? '' ),
					array_filter( $blocks, 'is_array' )
				),
				static fn( string $name ): bool => $name !== ''
			)
		);
		$summary_stats          = self::collect_template_part_block_stats( $blocks );
		$block_counts           = $summary_stats['blockCounts'];

		return [
			'templatePartRef'       => self::resolve_template_part_ref( $template_part_ref, $template_part ),
			'slug'                  => $slug,
			'title'                 => $title !== '' ? $title : $template_part_ref,
			'area'                  => $area,
			'blockTree'             => $block_tree,
			'topLevelBlocks'        => $top_level_blocks,
			'currentPatternOverrides' => self::collect_current_pattern_override_summary( $blocks ),
			'blockCounts'           => $block_counts,
			'structureStats'        => [
				'blockCount'            => $summary_stats['blockCount'],
				'maxDepth'              => $summary_stats['maxDepth'],
				'hasNavigation'         => ! empty( $block_counts['core/navigation'] ),
				'containsLogo'          => ! empty( $block_counts['core/site-logo'] ),
				'containsSiteTitle'     => ! empty( $block_counts['core/site-title'] ),
				'containsSearch'        => ! empty( $block_counts['core/search'] ),
				'containsSocialLinks'   => ! empty( $block_counts['core/social-links'] ),
				'containsQuery'         => ! empty( $block_counts['core/query'] ),
				'containsColumns'       => ! empty( $block_counts['core/columns'] ),
				'containsButtons'       => ! empty( $block_counts['core/buttons'] ),
				'containsSpacer'        => ! empty( $block_counts['core/spacer'] ),
				'containsSeparator'     => ! empty( $block_counts['core/separator'] ),
				'firstTopLevelBlock'    => $top_level_blocks[0] ?? '',
				'lastTopLevelBlock'     => count( $top_level_blocks ) > 0 ? $top_level_blocks[ count( $top_level_blocks ) - 1 ] : '',
				'hasSingleWrapperGroup' => count( $top_level_blocks ) === 1 && $top_level_blocks[0] === 'core/group',
				'isNearlyEmpty'         => $summary_stats['blockCount'] <= 1,
			],
			'operationTargets'      => $operation_targets,
			'insertionAnchors'      => $insertion_anchors,
			'structuralConstraints' => $structural_constraints,
			'patterns'              => self::collect_template_part_candidate_patterns( $area, $visible_pattern_names ),
			'themeTokens'           => self::for_tokens(),
		];
	}

	/**
	 * Return a normalized slug => area map for registered template parts.
	 *
	 * @return array<string, string>
	 */
	public static function for_template_part_areas(): array {
		$lookup = [];

		foreach ( self::for_template_parts( null, false ) as $part ) {
			$slug = isset( $part['slug'] )
				? sanitize_key( (string) $part['slug'] )
				: '';
			$area = isset( $part['area'] )
				? sanitize_key( (string) $part['area'] )
				: '';

			if ( $slug === '' || $area === '' ) {
				continue;
			}

			$lookup[ $slug ] = $area;
		}

		return $lookup;
	}

	/**
	 * Resolve a template-part object from a canonical ref or slug.
	 *
	 * @param string $template_part_ref Template-part identifier from the Site Editor.
	 * @return object|null
	 */
	private static function resolve_template_part( string $template_part_ref ): ?object {
		$template_part = null;

		if ( str_contains( $template_part_ref, '//' ) ) {
			$template_part = get_block_template( $template_part_ref, 'wp_template_part' );
		}

		if ( ! $template_part ) {
			$slug           = self::extract_template_part_slug( $template_part_ref );
			$template_parts = $slug !== ''
				? get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template_part' )
				: [];
			$template_part  = $template_parts[0] ?? null;
		}

		return is_object( $template_part ) ? $template_part : null;
	}

	private static function extract_template_part_slug( string $template_part_ref ): string {
		return str_contains( $template_part_ref, '//' )
			? substr( $template_part_ref, strpos( $template_part_ref, '//' ) + 2 )
			: $template_part_ref;
	}

	private static function resolve_template_part_ref( string $requested_ref, object $template_part ): string {
		$resolved_id = (string) ( $template_part->id ?? '' );

		if ( $resolved_id !== '' ) {
			return $resolved_id;
		}

		return $requested_ref !== ''
			? $requested_ref
			: (string) ( $template_part->slug ?? '' );
	}

	/**
	 * Assemble context for a template recommendation.
	 *
	 * Template recommendations stay template-global unless a dedicated
	 * design update explicitly introduces inserter-root narrowing.
	 *
	 * @param string      $template_ref          Template identifier from the Site Editor.
	 * @param string|null $template_type          Normalized template type. Derived if null.
	 * @param string[]|null $visible_pattern_names Optional client-side visible pattern filter.
	 *                                             Applied before the candidate cap so that all
	 *                                             visible patterns are considered, not just the
	 *                                             first N unfiltered candidates.
	 * @return array|\WP_Error Template context or error.
	 */
	public static function for_template(
		string $template_ref,
		?string $template_type = null,
		?array $visible_pattern_names = null
	): array|\WP_Error {
		$template = null;

		if ( str_contains( $template_ref, '//' ) ) {
			$template = get_block_template( $template_ref, 'wp_template' );
		}

		if ( ! $template ) {
			$slug = str_contains( $template_ref, '//' )
				? substr( $template_ref, strpos( $template_ref, '//' ) + 2 )
				: $template_ref;

			$templates = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template' );
			$template  = $templates[0] ?? null;
		}

		if ( ! $template ) {
			return new \WP_Error(
				'template_not_found',
				'Could not resolve the current template from the Site Editor context.',
				[ 'status' => 404 ]
			);
		}

		if ( $template_type === null ) {
			$template_type = self::derive_template_type( $template_ref );
		}

		$available_parts  = self::for_template_parts( null, false );
		$part_area_lookup = [];
		foreach ( $available_parts as $part ) {
			$slug = (string) ( $part['slug'] ?? '' );

			if ( $slug === '' ) {
				continue;
			}

			$part_area_lookup[ $slug ] = (string) ( $part['area'] ?? '' );
		}

		$template_blocks      = parse_blocks( $template->content ?? '' );
		$slots                = self::collect_template_part_slots(
			$template_blocks,
			$part_area_lookup
		);
		$top_level_block_tree = self::summarize_template_block_tree(
			$template_blocks,
			$part_area_lookup
		);

		return [
			'templateRef'              => $template_ref,
			'templateType'             => $template_type,
			'title'                    => $template->title ?? $template_ref,
			'assignedParts'            => $slots['assignedParts'],
			'emptyAreas'               => $slots['emptyAreas'],
			'allowedAreas'             => $slots['allowedAreas'],
			'topLevelBlockTree'        => $top_level_block_tree,
			'currentPatternOverrides'  => self::collect_current_pattern_override_summary( $template_blocks, $part_area_lookup ),
			'currentViewportVisibility' => self::collect_current_viewport_visibility_summary( $template_blocks, $part_area_lookup ),
			'topLevelInsertionAnchors' => self::collect_template_insertion_anchors( $top_level_block_tree ),
			'structureStats'           => self::collect_template_structure_stats( $template_blocks, $top_level_block_tree ),
			'availableParts'           => $available_parts,
			'patterns'                 => self::collect_template_candidate_patterns( $template_type, $visible_pattern_names ),
			'themeTokens'              => self::for_tokens(),
		];
	}

	/**
	 * Walk parsed blocks recursively and extract template-part slots.
	 *
	 * @param array $blocks           Parsed block array from parse_blocks().
	 * @param array $part_area_lookup Map of slug => area from available parts.
	 * @return array{assignedParts: array, emptyAreas: string[], allowedAreas: string[]}
	 */
	private static function collect_template_part_slots( array $blocks, array $part_area_lookup ): array {
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
						$slug !== '' ? ( $part_area_lookup[ $slug ] ?? '' ) : ''
					)
				);

				if ( $slug !== '' ) {
					$assigned_parts[] = [
						'slug' => $slug,
						'area' => $area,
					];
					if ( $area !== '' ) {
						$allowed_areas[] = $area;
					}
				} elseif ( self::is_explicit_empty_template_part_area( $area ) ) {
					$empty_areas[]   = $area;
					$allowed_areas[] = $area;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$child_slots = self::collect_template_part_slots(
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
						static fn( string $area ): bool => $area !== ''
					)
				)
			),
		];
	}

	/**
	 * Normalize a template ref to a known template type.
	 *
	 * @param string $ref Template slug or canonical template ref.
	 * @return string|null Normalized type or null.
	 */
	private static function derive_template_type( string $ref ): ?string {
		$slug = str_contains( $ref, '//' )
			? substr( $ref, strpos( $ref, '//' ) + 2 )
			: $ref;

		if ( $slug === '' ) {
			return null;
		}

		if ( in_array( $slug, self::KNOWN_TEMPLATE_TYPES, true ) ) {
			return $slug;
		}

		$base = explode( '-', $slug )[0];

		if ( in_array( $base, self::KNOWN_TEMPLATE_TYPES, true ) ) {
			return $base;
		}

		return null;
	}

	/**
	 * Return whether an area represents an explicit empty placeholder worth surfacing.
	 *
	 * @param string $area Template-part area from block attributes.
	 * @return bool Whether the area should be treated as explicitly empty.
	 */
	private static function is_explicit_empty_template_part_area( string $area ): bool {
		return $area !== '' && $area !== 'uncategorized';
	}

	/**
	 * Collect candidate patterns for a template type.
	 *
	 * Typed matches sort first, followed by generic patterns with no template
	 * types. Pattern content is removed to avoid prompt bloat.
	 *
	 * When $visible_pattern_names is provided, the visibility filter is applied
	 * before the candidate cap so that all visible patterns are considered.
	 *
	 * @param string|null   $template_type          Normalized template type.
	 * @param string[]|null $visible_pattern_names   Optional visibility filter.
	 * @return array Pattern candidates.
	 */
	private static function collect_template_candidate_patterns( ?string $template_type, ?array $visible_pattern_names = null ): array {
		$max_candidates = self::TEMPLATE_PATTERN_CANDIDATE_CAP;
		$all_patterns   = self::for_patterns();
		$typed          = [];
		$generic        = [];
		$unfiltered     = [];
		$seen           = [];

		foreach ( $all_patterns as $pattern ) {
			$name = (string) ( $pattern['name'] ?? '' );

			if ( $name === '' || isset( $seen[ $name ] ) ) {
				continue;
			}

			unset( $pattern['content'] );

			$types = $pattern['templateTypes'] ?? [];
			if ( ! is_array( $types ) ) {
				$types = [];
			}

			if ( $template_type === null ) {
				$unfiltered[]  = $pattern;
				$seen[ $name ] = true;
				continue;
			}

			if ( in_array( $template_type, $types, true ) ) {
				$pattern['matchType'] = 'typed';
				$typed[]              = $pattern;
				$seen[ $name ]        = true;
				continue;
			}

			if ( empty( $types ) ) {
				$pattern['matchType'] = 'generic';
				$generic[]            = $pattern;
				$seen[ $name ]        = true;
			}
		}

		$candidates = $template_type === null
			? $unfiltered
			: array_merge( $typed, $generic );

		if ( is_array( $visible_pattern_names ) ) {
			$visible_lookup = array_fill_keys( $visible_pattern_names, true );
			$candidates     = array_values(
				array_filter(
					$candidates,
					static function ( array $pattern ) use ( $visible_lookup ): bool {
						$name = (string) ( $pattern['name'] ?? '' );

						return $name !== '' && isset( $visible_lookup[ $name ] );
					}
				)
			);
		}

		return array_slice( $candidates, 0, $max_candidates );
	}

	/**
	 * Collect candidate patterns for a template-part area.
	 *
	 * Strong area matches sort first, followed by generic fallback patterns.
	 * Pattern content is removed to avoid prompt bloat.
	 *
	 * @param string|null $area Template-part area slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_template_part_candidate_patterns( ?string $area, ?array $visible_pattern_names = null ): array {
		$max_candidates = self::TEMPLATE_PATTERN_CANDIDATE_CAP;
		$all_patterns   = self::for_patterns();
		$area_key       = sanitize_key( (string) $area );
		$area_terms     = self::template_part_area_terms( $area_key );
		$matched        = [];
		$generic        = [];
		$unfiltered     = [];
		$seen           = [];
		$index          = 0;

		foreach ( $all_patterns as $pattern ) {
			$name = (string) ( $pattern['name'] ?? '' );

			if ( $name === '' || isset( $seen[ $name ] ) ) {
				continue;
			}

			unset( $pattern['content'] );
			$pattern['_sortIndex'] = $index++;

			if ( $area_key === '' ) {
				$unfiltered[]  = $pattern;
				$seen[ $name ] = true;
				continue;
			}

			$score = self::score_template_part_pattern_candidate(
				$pattern,
				$area_key,
				$area_terms
			);

			if ( $score > 0 ) {
				$pattern['matchType']  = 'area';
				$pattern['_sortScore'] = $score;
				$matched[]             = $pattern;
				$seen[ $name ]         = true;
				continue;
			}

			$block_types    = $pattern['blockTypes'] ?? [];
			$template_types = $pattern['templateTypes'] ?? [];
			if (
				( ! is_array( $block_types ) || count( $block_types ) === 0 ) &&
				( ! is_array( $template_types ) || count( $template_types ) === 0 )
			) {
				$pattern['matchType'] = 'generic';
				$generic[]            = $pattern;
				$seen[ $name ]        = true;
			}
		}

		$sort_candidates = static function ( array &$candidates ): void {
			usort(
				$candidates,
				static function ( array $left, array $right ): int {
					$score_compare = (int) ( $right['_sortScore'] ?? 0 ) <=> (int) ( $left['_sortScore'] ?? 0 );

					if ( 0 !== $score_compare ) {
						return $score_compare;
					}

					return (int) ( $left['_sortIndex'] ?? 0 ) <=> (int) ( $right['_sortIndex'] ?? 0 );
				}
			);
		};

		$strip_sort_fields = static function ( array $pattern ): array {
			unset( $pattern['_sortIndex'], $pattern['_sortScore'] );
			return $pattern;
		};

		if ( $area_key === '' ) {
			$candidates = $unfiltered;
		} else {
			$sort_candidates( $matched );
			$candidates = array_merge( $matched, $generic );
		}

		if ( is_array( $visible_pattern_names ) ) {
			$visible_lookup = array_fill_keys( $visible_pattern_names, true );
			$candidates     = array_values(
				array_filter(
					$candidates,
					static function ( array $pattern ) use ( $visible_lookup ): bool {
						$name = (string) ( $pattern['name'] ?? '' );

						return $name !== '' && isset( $visible_lookup[ $name ] );
					}
				)
			);
		}

		return array_map(
			$strip_sort_fields,
			array_slice( $candidates, 0, $max_candidates )
		);
	}

	/**
	 * @return string[]
	 */
	private static function template_part_area_terms( string $area ): array {
		$map = [
			'header'             => [ 'header', 'masthead' ],
			'footer'             => [ 'footer' ],
			'sidebar'            => [ 'sidebar', 'aside' ],
			'navigation-overlay' => [ 'navigation overlay', 'overlay', 'navigation' ],
		];

		if ( isset( $map[ $area ] ) ) {
			return $map[ $area ];
		}

		if ( $area === '' ) {
			return [];
		}

		$spaced = str_replace( '-', ' ', $area );

		return array_values(
			array_unique(
				array_filter(
					[ $area, $spaced ],
					static fn( string $term ): bool => $term !== ''
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private static function score_template_part_pattern_candidate( array $pattern, string $area, array $terms ): int {
		$score       = 0;
		$block_types = array_map(
			static fn( string $value ): string => strtolower( trim( $value ) ),
			array_filter(
				array_map(
					'strval',
					is_array( $pattern['blockTypes'] ?? null ) ? $pattern['blockTypes'] : []
				)
			)
		);

		if ( in_array( 'core/template-part/' . $area, $block_types, true ) ) {
			$score += 8;
		}

		if ( in_array( 'core/template-part', $block_types, true ) ) {
			$score += 2;
		}

		$categories = is_array( $pattern['categories'] ?? null )
			? array_map( 'strval', $pattern['categories'] )
			: [];
		$haystack   = strtolower(
			implode(
				' ',
				array_filter(
					[
						(string) ( $pattern['name'] ?? '' ),
						(string) ( $pattern['title'] ?? '' ),
						(string) ( $pattern['description'] ?? '' ),
						implode( ' ', $categories ),
					]
				)
			)
		);

		foreach ( $terms as $term ) {
			$normalized_term = strtolower( $term );

			if ( $normalized_term === '' ) {
				continue;
			}

			$matches = str_contains( $normalized_term, ' ' )
				? str_contains( $haystack, $normalized_term )
				: (bool) preg_match( '/\b' . preg_quote( $normalized_term, '/' ) . '\b/u', $haystack );

			if ( $matches ) {
				$score += 4;
			}
		}

		if (
			$area === 'navigation-overlay' &&
			str_contains( $haystack, 'navigation' ) &&
			str_contains( $haystack, 'overlay' )
		) {
			$score += 2;
		}

		return $score;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed block array from parse_blocks().
	 * @param array<int, int>                  $path   Path from the template-part root.
	 * @return array<int, array<string, mixed>>
	 */
	private static function summarize_template_part_block_tree( array $blocks, array $path = [], int $depth = 0, int $max_depth = 3 ): array {
		$tree = [];

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( $name === '' ) {
				continue;
			}

			$next_path    = array_merge( $path, [ (int) $index ] );
			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];
			$children     = [];

			if ( $depth + 1 < $max_depth && count( $inner_blocks ) > 0 ) {
				$children = self::summarize_template_part_block_tree(
					$inner_blocks,
					$next_path,
					$depth + 1,
					$max_depth
				);
			}

			$tree[] = [
				'path'       => $next_path,
				'name'       => $name,
				'attributes' => self::summarize_template_part_block_attributes(
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
	private static function summarize_template_part_block_attributes( array $attributes ): array {
		$summary = [];
		$fields  = [ 'tagName', 'align', 'overlayMenu', 'maxNestingLevel', 'showSubmenuIcon', 'placeholder', 'slug', 'area', 'ref', 'templateLock' ];

		foreach ( $fields as $field ) {
			$value = $attributes[ $field ] ?? null;

			if ( is_string( $value ) && $value !== '' ) {
				$summary[ $field ] = $value;
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$summary[ $field ] = $value;
			}
		}

		$layout = is_array( $attributes['layout'] ?? null ) ? $attributes['layout'] : [];

		if ( isset( $layout['type'] ) && is_string( $layout['type'] ) && $layout['type'] !== '' ) {
			$summary['layoutType'] = $layout['type'];
		}

		if ( isset( $layout['justifyContent'] ) && is_string( $layout['justifyContent'] ) && $layout['justifyContent'] !== '' ) {
			$summary['layoutJustifyContent'] = $layout['justifyContent'];
		}

		if ( isset( $layout['orientation'] ) && is_string( $layout['orientation'] ) && $layout['orientation'] !== '' ) {
			$summary['layoutOrientation'] = $layout['orientation'];
		}

		return $summary;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed block array from parse_blocks().
	 * @param array<string, string>            $part_area_lookup Map of slug => area from available parts.
	 * @return array<int, array<string, mixed>>
	 */
	private static function summarize_template_block_tree( array $blocks, array $part_area_lookup ): array {
		$tree = [];

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( $name === '' ) {
				continue;
			}

			$attributes = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$slug       = sanitize_key( (string) ( $attributes['slug'] ?? '' ) );
			$area       = sanitize_key(
				(string) (
					$attributes['area'] ?? (
						$slug !== '' ? ( $part_area_lookup[ $slug ] ?? '' ) : ''
					)
				)
			);
			$entry      = [
				'path'       => [ (int) $index ],
				'name'       => $name,
				'label'      => self::describe_template_block_label( $name, $slug, $area ),
				'attributes' => self::summarize_template_part_block_attributes( $attributes ),
				'childCount' => count( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [] ),
			];

			if ( $name === 'core/template-part' ) {
				$entry['slot'] = [
					'slug'    => $slug,
					'area'    => $area,
					'isEmpty' => $slug === '',
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
	private static function collect_template_insertion_anchors( array $top_level_block_tree ): array {
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

			if ( count( $path ) !== 1 || $block_name === '' || $label === '' ) {
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
	 * @param array<int, array<string, mixed>> $blocks Parsed block array from parse_blocks().
	 * @param array<int, array<string, mixed>> $top_level_block_tree Summarized top-level block tree.
	 * @return array<string, mixed>
	 */
	private static function collect_template_structure_stats( array $blocks, array $top_level_block_tree ): array {
		$stats        = self::collect_template_part_block_stats( $blocks );
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

	private static function describe_template_block_label( string $block_name, string $slug = '', string $area = '' ): string {
		if ( $block_name === 'core/template-part' ) {
			if ( $slug !== '' && $area !== '' ) {
				return sprintf( '%s template part (%s)', $slug, $area );
			}

			if ( $slug !== '' ) {
				return sprintf( '%s template part', $slug );
			}

			if ( $area !== '' ) {
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
	 * @param array<int, array<string, mixed>> $blocks Parsed block array from parse_blocks().
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_template_part_operation_targets( array $blocks ): array {
		$targets = [];

		self::walk_template_part_operation_targets( $blocks, [], false, $targets );

		return $targets;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed block array from parse_blocks().
	 * @param array<int, int>                  $path   Path from the template-part root.
	 * @param array<int, array<string, mixed>> $targets
	 */
	private static function walk_template_part_operation_targets( array $blocks, array $path, bool $inside_content_only, array &$targets ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( $name === '' ) {
				continue;
			}

			$attributes                = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$template_lock             = (string) ( $attributes['templateLock'] ?? '' );
			$is_content_only_container = strtolower( trim( $template_lock ) ) === 'contentonly';
			$lock                      = is_array( $attributes['lock'] ?? null ) ? $attributes['lock'] : [];
			$next_path                 = array_merge( $path, [ (int) $index ] );
			$can_target                = ! $inside_content_only && ! $is_content_only_container;
			$allowed_operations        = [];

			if ( $can_target ) {
				if ( $template_lock === '' && empty( $lock ) ) {
					$allowed_operations[] = 'replace_block_with_pattern';
					$allowed_operations[] = 'remove_block';
				}

				$targets[] = [
					'path'              => $next_path,
					'name'              => $name,
					'label'             => self::describe_template_block_label( $name ),
					'allowedOperations' => $allowed_operations,
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				];
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( count( $inner_blocks ) > 0 ) {
				self::walk_template_part_operation_targets(
					$inner_blocks,
					$next_path,
					$inside_content_only || $is_content_only_container,
					$targets
				);
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $operation_targets
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_template_part_insertion_anchors( array $operation_targets ): array {
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

			if ( $path === [] || $block_name === '' || $label === '' ) {
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
	 * @param array<int, array<string, mixed>> $blocks Parsed block array from parse_blocks().
	 * @return array<string, mixed>
	 */
	private static function collect_template_part_structural_constraints( array $blocks ): array {
		$constraints = [
			'contentOnlyPaths' => [],
			'lockedPaths'      => [],
		];

		self::walk_template_part_structural_constraints( $blocks, [], $constraints );

		return [
			'contentOnlyPaths' => $constraints['contentOnlyPaths'],
			'lockedPaths'      => $constraints['lockedPaths'],
			'hasContentOnly'   => count( $constraints['contentOnlyPaths'] ) > 0,
			'hasLockedBlocks'  => count( $constraints['lockedPaths'] ) > 0,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed block array from parse_blocks().
	 * @param array<int, int>                  $path   Path from the template-part root.
	 * @param array<string, array<int, array<int, int>>> $constraints
	 */
	private static function walk_template_part_structural_constraints( array $blocks, array $path, array &$constraints ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( $name === '' ) {
				continue;
			}

			$attributes    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$template_lock = (string) ( $attributes['templateLock'] ?? '' );
			$lock          = is_array( $attributes['lock'] ?? null ) ? $attributes['lock'] : [];
			$next_path     = array_merge( $path, [ (int) $index ] );

			if ( strtolower( trim( $template_lock ) ) === 'contentonly' ) {
				$constraints['contentOnlyPaths'][] = $next_path;
			}

			if ( $template_lock !== '' || ! empty( $lock ) ) {
				$constraints['lockedPaths'][] = $next_path;
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( count( $inner_blocks ) > 0 ) {
				self::walk_template_part_structural_constraints( $inner_blocks, $next_path, $constraints );
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed block array from parse_blocks().
	 * @return array{blockCount: int, maxDepth: int, blockCounts: array<string, int>}
	 */
	private static function collect_template_part_block_stats( array $blocks ): array {
		$stats = [
			'blockCount'  => 0,
			'maxDepth'    => 0,
			'blockCounts' => [],
		];

		self::walk_template_part_blocks( $blocks, 1, $stats );

		return $stats;
	}

	/**
	 * @param array<int, array<string, mixed>>          $blocks Parsed block array from parse_blocks().
	 * @param array{blockCount: int, maxDepth: int, blockCounts: array<string, int>} $stats
	 */
	private static function walk_template_part_blocks( array $blocks, int $depth, array &$stats ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( $name === '' ) {
				continue;
			}

			++$stats['blockCount'];
			$stats['maxDepth']             = max( $stats['maxDepth'], $depth );
			$stats['blockCounts'][ $name ] = ( $stats['blockCounts'][ $name ] ?? 0 ) + 1;

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( count( $inner_blocks ) > 0 ) {
				self::walk_template_part_blocks( $inner_blocks, $depth + 1, $stats );
			}
		}
	}

	private static function flatten_supports( array $obj, string $prefix = '' ): array {
		$entries = [];
		foreach ( $obj as $key => $val ) {
			$path = $prefix !== '' ? "{$prefix}.{$key}" : $key;

			if ( is_bool( $val ) || is_string( $val ) || ( is_array( $val ) && self::is_list_array( $val ) ) ) {
				$entries[] = [ $path, $val ];
			} elseif ( is_array( $val ) ) {
				$entries = array_merge( $entries, self::flatten_supports( $val, $path ) );
			}
		}
		return $entries;
	}

	private static function is_truthy( mixed $val ): bool {
		if ( $val === true ) {
			return true;
		}
		if ( $val === false || $val === null ) {
			return false;
		}
		if ( is_array( $val ) ) {
			return count( $val ) > 0;
		}
		return (bool) $val;
	}

	private static function extract_active_style( string $class_name, array $styles ): ?string {
		if ( $class_name === '' ) {
			return null;
		}
		foreach ( $styles as $style ) {
			if ( str_contains( $class_name, 'is-style-' . ( $style['name'] ?? '' ) ) ) {
				return $style['name'];
			}
		}
		return null;
	}

	private static function resolve_attribute_role( array $definition ): ?string {
		$role = $definition['role'] ?? null;

		return is_string( $role ) && '' !== $role ? $role : null;
	}

	private static function merge_presets( array|string $feature ): array {
		if ( ! is_array( $feature ) ) {
			return [];
		}
		if ( self::is_list_array( $feature ) ) {
			return $feature;
		}

		$by_slug = [];
		foreach ( [ 'default', 'theme', 'custom' ] as $origin ) {
			foreach ( $feature[ $origin ] ?? [] as $item ) {
				$slug = $item['slug'] ?? '';
				if ( $slug !== '' ) {
					$by_slug[ $slug ] = $item;
				}
			}
		}
		return array_values( $by_slug );
	}

	private static function collect_block_pseudo_styles( array $styles ): array {
		$block_styles   = $styles['blocks'] ?? [];
		$pseudo_classes = [ ':hover', ':focus', ':focus-visible', ':active' ];
		$result         = [];

		foreach ( $block_styles as $block_name => $style_def ) {
			if ( ! is_array( $style_def ) ) {
				continue;
			}
			$pseudos = [];
			foreach ( $pseudo_classes as $pseudo ) {
				if ( ! empty( $style_def[ $pseudo ] ) ) {
					$pseudos[ $pseudo ] = $style_def[ $pseudo ];
				}
			}
			if ( ! empty( $pseudos ) ) {
				$result[ $block_name ] = $pseudos;
			}
		}

		return $result;
	}

	private static function collect_element_styles( array $styles ): array {
		$elements = $styles['elements'] ?? [];
		$result   = [];

		foreach ( $elements as $element => $style_def ) {
			if ( ! is_array( $style_def ) ) {
				continue;
			}

			$result[ $element ] = [
				'base'         => is_array( $style_def['color'] ?? null ) ? $style_def['color'] : [],
				'hover'        => is_array( $style_def[':hover']['color'] ?? null ) ? $style_def[':hover']['color'] : [],
				'focus'        => is_array( $style_def[':focus']['color'] ?? null ) ? $style_def[':focus']['color'] : [],
				'focusVisible' => is_array( $style_def[':focus-visible'] ?? null ) ? $style_def[':focus-visible'] : [],
			];
		}

		return $result;
	}

	private static function is_list_array( array $values ): bool {
		$expected_index = 0;

		foreach ( $values as $key => $_value ) {
			if ( $key !== $expected_index ) {
				return false;
			}

			++$expected_index;
		}

		return true;
	}

	/**
	 * Assemble context for a navigation recommendation.
	 *
	 * Accepts either a wp_navigation post ID or raw navigation block markup.
	 * Extracts menu item structure, overlay configuration, and overlay
	 * template parts (WP 7.0+ navigation-overlay area).
	 *
	 * @param int    $menu_id  wp_navigation post ID. 0 to skip.
	 * @param string $markup   Serialized navigation block markup. Empty to skip.
	 * @return array|\WP_Error Navigation context or error.
	 */
	public static function for_navigation( int $menu_id = 0, string $markup = '' ): array|\WP_Error {
		$saved_source  = self::parse_navigation_source( '' );
		$markup_source = self::parse_navigation_source( '' );

		// Resolve content from wp_navigation post if menu ID is provided.
		if ( $menu_id > 0 ) {
			$post = get_post( $menu_id );

			if ( ! $post || $post->post_type !== 'wp_navigation' ) {
				return new \WP_Error(
					'navigation_not_found',
					"No wp_navigation post found with ID {$menu_id}.",
					[ 'status' => 404 ]
				);
			}

			$saved_source = self::parse_navigation_source(
				(string) ( $post->post_content ?? '' )
			);
		}

		// If raw markup is provided, use its live block attributes and any
		// unsaved inner structure, while falling back to the saved menu items
		// when the editor only stores a `ref` to the wp_navigation post.
		if ( $markup !== '' ) {
			$markup_source = self::parse_navigation_source( $markup );
		}

		$nav_attrs = $markup_source['hasNavigationBlock']
			? $markup_source['attrs']
			: $saved_source['attrs'];
		$inner     = $markup_source['hasStructure']
			? $markup_source['inner']
			: $saved_source['inner'];

		$menu_items = self::extract_menu_items( $inner );

		// Extract key navigation attributes.
		$attributes = [
			'overlayMenu'         => self::extract_string_attr( $nav_attrs, 'overlayMenu', 'mobile' ),
			'hasIcon'             => ! empty( $nav_attrs['hasIcon'] ),
			'icon'                => self::extract_string_attr( $nav_attrs, 'icon', 'handle' ),
			'openSubmenusOnClick' => ! empty( $nav_attrs['openSubmenusOnClick'] ),
			'showSubmenuIcon'     => $nav_attrs['showSubmenuIcon'] ?? true,
			'maxNestingLevel'     => isset( $nav_attrs['maxNestingLevel'] ) ? (int) $nav_attrs['maxNestingLevel'] : 0,
		];

		// Detect location from menu ID reference (if the navigation block
		// references this menu from a template part, we can infer location).
		$location = 'unknown';
		if ( $menu_id > 0 ) {
			$location = self::infer_navigation_location( $menu_id );
		}

		// Collect navigation-overlay template parts (WP 7.0+).
		$overlay_parts     = self::for_template_parts( 'navigation-overlay', false );
		$structure_summary = self::collect_navigation_structure_summary( $menu_items );
		$uses_overlay      = count( $overlay_parts ) > 0 || ( $attributes['overlayMenu'] ?? 'never' ) !== 'never';

		return [
			'menuId'               => $menu_id > 0 ? $menu_id : null,
			'location'             => $location,
			'locationDetails'      => [
				'area'   => $location,
				'source' => $location !== 'unknown'
					? 'template-part-scan'
					: ( $menu_id > 0 ? 'navigation-post' : 'live-markup' ),
			],
			'attributes'           => $attributes,
			'menuItems'            => $menu_items,
			'menuItemCount'        => self::count_menu_items_recursive( $menu_items ),
			'maxDepth'             => self::measure_menu_depth( $menu_items ),
			'structureSummary'     => $structure_summary,
			'overlayContext'       => [
				'usesOverlay'              => $uses_overlay,
				'overlayMode'              => (string) ( $attributes['overlayMenu'] ?? 'never' ),
				'hasDedicatedOverlayParts' => count( $overlay_parts ) > 0,
				'overlayTemplatePartCount' => count( $overlay_parts ),
				'overlayTemplatePartSlugs' => array_values(
					array_filter(
						array_map(
							static fn( array $part ): string => sanitize_key( (string) ( $part['slug'] ?? '' ) ),
							$overlay_parts
						)
					)
				),
			],
			'overlayTemplateParts' => $overlay_parts,
			'themeTokens'          => self::for_tokens(),
		];
	}

	/**
	 * Find a core/navigation block in parsed blocks (top-level only).
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array|null The navigation block or null.
	 */
	private static function find_navigation_block( array $blocks ): ?array {
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
	private static function parse_navigation_source( string $content ): array {
		if ( '' === $content ) {
			return [
				'attrs'              => [],
				'inner'              => [],
				'hasNavigationBlock' => false,
				'hasStructure'       => false,
			];
		}

		$blocks    = parse_blocks( $content );
		$nav_block = self::find_navigation_block( $blocks );

		if ( null !== $nav_block ) {
			$inner                = is_array( $nav_block['innerBlocks'] ?? null )
				? $nav_block['innerBlocks']
				: [];
			$has_explicit_wrapper = str_contains(
				$content,
				'<!-- /wp:navigation -->'
			);

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
	 * @param array $blocks Parsed inner blocks of a navigation.
	 * @return array Menu item tree.
	 */
	private static function extract_menu_items( array $blocks ): array {
		$items = [];

		foreach ( $blocks as $block ) {
			$name  = $block['blockName'] ?? '';
			$attrs = $block['attrs'] ?? [];
			$inner = $block['innerBlocks'] ?? [];

			if ( $name === null || $name === '' ) {
				continue;
			}

			$item = [
				'type' => self::simplify_block_name( $name ),
			];

			// Extract label and URL for navigation links/submenus.
			if ( $name === 'core/navigation-link' || $name === 'core/navigation-submenu' ) {
				$item['label'] = self::extract_string_attr( $attrs, 'label', '' );
				$item['url']   = self::extract_string_attr( $attrs, 'url', '' );
			} elseif ( $name === 'core/page-list' ) {
				$item['label'] = 'Page List (auto-generated)';
			} elseif ( $name === 'core/home-link' ) {
				$item['label'] = self::extract_string_attr( $attrs, 'label', 'Home' );
			} else {
				// Other allowed inner blocks (spacer, search, social-links, etc.).
				$item['label'] = '';
			}

			// Recurse for submenus.
			if ( is_array( $inner ) && count( $inner ) > 0 ) {
				$item['children'] = self::extract_menu_items( $inner );
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Strip the core/ prefix for readability in prompts.
	 */
	private static function simplify_block_name( string $name ): string {
		if ( str_starts_with( $name, 'core/' ) ) {
			return substr( $name, 5 );
		}

		return $name;
	}

	/**
	 * Safely extract a string attribute with a default.
	 */
	private static function extract_string_attr( array $attrs, string $key, string $default_value ): string {
		return isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ? $attrs[ $key ] : $default_value;
	}

	/**
	 * Count all menu items recursively.
	 */
	private static function count_menu_items_recursive( array $items ): int {
		$count = count( $items );

		foreach ( $items as $item ) {
			if ( isset( $item['children'] ) && is_array( $item['children'] ) ) {
				$count += self::count_menu_items_recursive( $item['children'] );
			}
		}

		return $count;
	}

	/**
	 * Measure the maximum nesting depth of a menu item tree.
	 */
	private static function measure_menu_depth( array $items, int $current = 1 ): int {
		if ( count( $items ) === 0 ) {
			return 0;
		}

		$max = $current;

		foreach ( $items as $item ) {
			if ( isset( $item['children'] ) && is_array( $item['children'] ) && count( $item['children'] ) > 0 ) {
				$child_depth = self::measure_menu_depth( $item['children'], $current + 1 );
				$max         = max( $max, $child_depth );
			}
		}

		return $max;
	}

	/**
	 * @param array<int, array<string, mixed>> $items Navigation menu item tree.
	 * @return array<string, mixed>
	 */
	private static function collect_navigation_structure_summary( array $items ): array {
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

				if ( $label !== '' ) {
					$summary['topLevelLabels'][] = $label;
				}
			}

			self::walk_navigation_structure_summary( $item, $summary );
		}

		$summary['nonLinkTypes'] = array_values( array_unique( $summary['nonLinkTypes'] ) );

		return $summary;
	}

	/**
	 * @param array<string, mixed>       $item
	 * @param array<string, mixed> $summary
	 */
	private static function walk_navigation_structure_summary( array $item, array &$summary ): void {
		$type = sanitize_key( (string) ( $item['type'] ?? '' ) );

		if ( $type === 'page-list' ) {
			$summary['hasPageList'] = true;
		}

		if ( $type !== '' && ! in_array( $type, [ 'navigation-link', 'navigation-submenu', 'home-link', 'page-list' ], true ) ) {
			$summary['nonLinkTypes'][] = $type;
		}

		$children = is_array( $item['children'] ?? null ) ? $item['children'] : [];

		if ( count( $children ) > 0 ) {
			++$summary['submenuCount'];

			foreach ( $children as $child ) {
				if ( is_array( $child ) ) {
					self::walk_navigation_structure_summary( $child, $summary );
				}
			}
		}
	}

	/**
	 * Infer where a navigation menu is used by scanning template parts.
	 *
	 * Checks header and footer template parts for references to the given
	 * wp_navigation post ID. Falls back to 'unknown'.
	 *
	 * @param int $menu_id wp_navigation post ID.
	 * @return string Location identifier (header, footer, or unknown).
	 */
	private static function infer_navigation_location( int $menu_id ): string {
		$parts = self::for_template_parts( null, true );

		foreach ( $parts as $part ) {
			$content = $part['content'] ?? '';
			$area    = $part['area'] ?? '';

			if ( $content === '' || $area === '' ) {
				continue;
			}

			// Look for a navigation block referencing this menu ID.
			if ( str_contains( $content, '"ref":' . $menu_id ) || str_contains( $content, '"ref": ' . $menu_id ) ) {
				return $area;
			}
		}

		return 'unknown';
	}
}
