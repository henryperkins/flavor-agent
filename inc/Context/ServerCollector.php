<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class ServerCollector {

	public const TEMPLATE_PATTERN_CANDIDATE_CAP = 30;

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
			$slug        = (string) ( $g['slug'] ?? '' );
			$gradient    = (string) ( $g['gradient'] ?? '' );
			$gradients[] = $gradient !== ''
				? "{$slug}: {$gradient}"
				: $slug;
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
			'gradients'         => $gradients,
			'fontSizes'         => $font_sizes,
			'fontFamilies'      => $font_families,
			'spacing'           => $spacing,
			'shadows'           => $shadows,
			'duotone'           => $duotone,
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
				'linkColor'       => $settings['color']['link'] ?? false,
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
	 * Assemble context for a single template-part document.
	 *
	 * @param string $template_part_ref Template-part identifier from the Site Editor.
	 * @return array|\WP_Error Template-part context or error.
	 */
	public static function for_template_part( string $template_part_ref ): array|\WP_Error {
		$template_part = self::resolve_template_part( $template_part_ref );

		if ( ! $template_part ) {
			return new \WP_Error(
				'template_part_not_found',
				'Could not resolve the current template part from the Site Editor context.',
				[ 'status' => 404 ]
			);
		}

		$content          = (string) ( $template_part->content ?? '' );
		$slug             = sanitize_key( (string) ( $template_part->slug ?? '' ) );
		$area             = sanitize_key( (string) ( $template_part->area ?? '' ) );
		$title_source     = (string) ( $template_part->title ?? '' );
		$title            = sanitize_text_field(
			$title_source !== ''
				? $title_source
				: ( $slug !== '' ? $slug : $template_part_ref )
		);
		$blocks           = parse_blocks( $content );
		$block_tree       = self::summarize_template_part_block_tree( $blocks );
		$top_level_blocks = array_values(
			array_filter(
				array_map(
					static fn( array $block ): string => (string) ( $block['blockName'] ?? '' ),
					array_filter( $blocks, 'is_array' )
				),
				static fn( string $name ): bool => $name !== ''
			)
		);
		$summary_stats    = self::collect_template_part_block_stats( $blocks );
		$block_counts     = $summary_stats['blockCounts'];

		return [
			'templatePartRef' => self::resolve_template_part_ref( $template_part_ref, $template_part ),
			'slug'            => $slug,
			'title'           => $title !== '' ? $title : $template_part_ref,
			'area'            => $area,
			'blockTree'       => $block_tree,
			'topLevelBlocks'  => $top_level_blocks,
			'blockCounts'     => $block_counts,
			'structureStats'  => [
				'blockCount'           => $summary_stats['blockCount'],
				'maxDepth'             => $summary_stats['maxDepth'],
				'hasNavigation'        => ! empty( $block_counts['core/navigation'] ),
				'containsLogo'         => ! empty( $block_counts['core/site-logo'] ),
				'containsSiteTitle'    => ! empty( $block_counts['core/site-title'] ),
				'containsSearch'       => ! empty( $block_counts['core/search'] ),
				'containsSocialLinks'  => ! empty( $block_counts['core/social-links'] ),
				'containsQuery'        => ! empty( $block_counts['core/query'] ),
				'containsColumns'      => ! empty( $block_counts['core/columns'] ),
				'containsButtons'      => ! empty( $block_counts['core/buttons'] ),
				'containsSpacer'       => ! empty( $block_counts['core/spacer'] ),
				'containsSeparator'    => ! empty( $block_counts['core/separator'] ),
				'firstTopLevelBlock'   => $top_level_blocks[0] ?? '',
				'lastTopLevelBlock'    => count( $top_level_blocks ) > 0 ? $top_level_blocks[ count( $top_level_blocks ) - 1 ] : '',
				'hasSingleWrapperGroup' => count( $top_level_blocks ) === 1 && $top_level_blocks[0] === 'core/group',
				'isNearlyEmpty'        => $summary_stats['blockCount'] <= 1,
			],
			'patterns'        => self::collect_template_part_candidate_patterns( $area ),
			'themeTokens'     => self::for_tokens(),
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
			'patterns'       => self::collect_template_candidate_patterns( $template_type, $visible_pattern_names ),
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

		if ( is_array( $visible_pattern_names ) && [] !== $visible_pattern_names ) {
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
	private static function collect_template_part_candidate_patterns( ?string $area ): array {
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

			$block_types   = $pattern['blockTypes'] ?? [];
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
			return array_map(
				$strip_sort_fields,
				array_slice( $unfiltered, 0, $max_candidates )
			);
		}

		$sort_candidates( $matched );

		return array_map(
			$strip_sort_fields,
			array_slice( array_merge( $matched, $generic ), 0, $max_candidates )
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
		$fields  = [ 'tagName', 'align', 'overlayMenu', 'maxNestingLevel', 'showSubmenuIcon', 'placeholder' ];

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
	 * @return array{blockCount: int, maxDepth: int, blockCounts: array<string, int>}
	 */
	private static function collect_template_part_block_stats( array $blocks ): array {
		$stats = [
			'blockCount' => 0,
			'maxDepth'   => 0,
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
			$stats['maxDepth'] = max( $stats['maxDepth'], $depth );
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
		$nav_content = '';
		$nav_attrs   = [];

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

			$nav_content = $post->post_content ?? '';
		}

		// If raw markup is provided, use it (overrides or supplements menu ID).
		if ( $markup !== '' ) {
			$nav_content = $markup;
		}

		// Parse the navigation block(s) to extract structure.
		$blocks = parse_blocks( $nav_content );

		// Find the core/navigation block (may be top-level or the markup may
		// be just the inner blocks of a navigation).
		$nav_block = self::find_navigation_block( $blocks );

		if ( $nav_block !== null ) {
			$nav_attrs = $nav_block['attrs'] ?? [];
			$inner     = $nav_block['innerBlocks'] ?? [];
		} else {
			// Treat all parsed blocks as inner blocks of an implicit navigation.
			$inner = $blocks;
		}

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
		$overlay_parts = self::for_template_parts( 'navigation-overlay', false );

		return [
			'menuId'               => $menu_id > 0 ? $menu_id : null,
			'location'             => $location,
			'attributes'           => $attributes,
			'menuItems'            => $menu_items,
			'menuItemCount'        => self::count_menu_items_recursive( $menu_items ),
			'maxDepth'             => self::measure_menu_depth( $menu_items ),
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
