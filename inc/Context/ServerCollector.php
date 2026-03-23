<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class ServerCollector {

	private const SUPPORT_TO_PANEL = [
		'color.background'           => 'color',
		'color.text'                 => 'color',
		'color.link'                 => 'color',
		'color.heading'              => 'color',
		'color.button'               => 'color',
		'color.gradients'            => 'color',
		'typography.fontSize'        => 'typography',
		'typography.lineHeight'      => 'typography',
		'typography.textAlign'       => 'typography',
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
			'inspectorPanels'     => self::resolve_inspector_panels( $supports ),
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

	public static function for_tokens(): array {
		$settings      = wp_get_global_settings();
		$global_styles = wp_get_global_styles();

		$colors = [];
		foreach ( self::merge_presets( $settings['color']['palette'] ?? [] ) as $c ) {
			$colors[] = ( $c['slug'] ?? '' ) . ': ' . ( $c['color'] ?? '' );
		}

		$gradients = [];
		foreach ( self::merge_presets( $settings['color']['gradients'] ?? [] ) as $g ) {
			$gradients[] = $g['slug'] ?? '';
		}

		$font_sizes = [];
		foreach ( self::merge_presets( $settings['typography']['fontSizes'] ?? [] ) as $fs ) {
			$font_sizes[] = ( $fs['slug'] ?? '' ) . ': ' . ( $fs['size'] ?? '' );
		}

		$font_families = [];
		foreach ( self::merge_presets( $settings['typography']['fontFamilies'] ?? [] ) as $ff ) {
			$font_families[] = ( $ff['slug'] ?? '' ) . ': ' . ( $ff['fontFamily'] ?? '' );
		}

		$spacing = [];
		foreach ( self::merge_presets( $settings['spacing']['spacingSizes'] ?? [] ) as $s ) {
			$spacing[] = ( $s['slug'] ?? '' ) . ': ' . ( $s['size'] ?? '' );
		}

		$shadows = [];
		foreach ( self::merge_presets( $settings['shadow']['presets'] ?? [] ) as $s ) {
			$shadows[] = ( $s['slug'] ?? '' ) . ': ' . ( $s['shadow'] ?? '' );
		}

		$duotone = [];
		foreach ( self::merge_presets( $settings['color']['duotone'] ?? [] ) as $preset ) {
			$slug   = (string) ( $preset['slug'] ?? '' );
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
			'gradients'         => $gradients,
			'fontSizes'         => $font_sizes,
			'fontFamilies'      => $font_families,
			'spacing'           => $spacing,
			'shadows'           => $shadows,
			'duotone'          => $duotone,
			'layout'            => [
				'content' => $settings['layout']['contentSize'] ?? '',
				'wide'    => $settings['layout']['wideSize'] ?? '',
			],
			'enabledFeatures'   => [
				'lineHeight'   => $settings['typography']['lineHeight'] ?? false,
				'dropCap'      => $settings['typography']['dropCap'] ?? true,
				'customColors' => $settings['color']['custom'] ?? true,
				'linkColor'    => $settings['color']['link'] ?? false,
				'margin'       => $settings['spacing']['margin'] ?? false,
				'padding'      => $settings['spacing']['padding'] ?? false,
				'borderColor'  => $settings['border']['color'] ?? false,
				'borderRadius' => $settings['border']['radius'] ?? false,
			],
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
				'supportsContentRole' => ! empty( $type_info['supportsContentRole'] ),
				'contentAttributes'   => $type_info['contentAttributes'] ?? [],
				'configAttributes'    => $type_info['configAttributes'] ?? [],
				'editingMode'         => 'default',
				'isInsideContentOnly' => $is_inside_content_only,
				'blockVisibility'     => $attributes['metadata']['blockVisibility'] ?? null,
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
				'content'       => $pattern['content'] ?? '',
			];
		}

		return $result;
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
	 * Assemble context for a template recommendation.
	 *
	 * Template recommendations stay template-global unless a dedicated
	 * design update explicitly introduces inserter-root narrowing.
	 *
	 * @param string      $template_ref  Template identifier from the Site Editor.
	 * @param string|null $template_type Normalized template type. Derived if null.
	 * @return array|\WP_Error Template context or error.
	 */
	public static function for_template(
		string $template_ref,
		?string $template_type = null
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

		$slots = self::collect_template_part_slots(
			parse_blocks( $template->content ?? '' ),
			$part_area_lookup
		);

		return [
			'templateRef'    => $template_ref,
			'templateType'   => $template_type,
			'title'          => $template->title ?? $template_ref,
			'assignedParts'  => $slots['assignedParts'],
			'emptyAreas'     => $slots['emptyAreas'],
			'allowedAreas'   => $slots['allowedAreas'],
			'availableParts' => $available_parts,
			'patterns'       => self::collect_template_candidate_patterns( $template_type ),
			'themeTokens'    => self::for_tokens(),
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
	 * @param string|null $template_type Normalized template type.
	 * @return array Pattern candidates.
	 */
	private static function collect_template_candidate_patterns( ?string $template_type ): array {
		$all_patterns = self::for_patterns();
		$typed        = [];
		$generic      = [];
		$unfiltered   = [];
		$seen         = [];

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

		if ( $template_type === null ) {
			return $unfiltered;
		}

		return array_merge( $typed, $generic );
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
		$role = $definition['role'] ?? $definition['__experimentalRole'] ?? null;

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
}
