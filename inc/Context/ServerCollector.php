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

    public static function introspect_block_type( string $block_name ): ?array {
        $registry   = \WP_Block_Type_Registry::get_instance();
        $block_type = $registry->get_registered( $block_name );

        if ( ! $block_type ) {
            return null;
        }

        $supports   = $block_type->supports ?? [];
        $attributes = $block_type->attributes ?? [];
        $styles     = $block_type->styles ?? [];
        $variations = $block_type->variations ?? [];

        $content_attrs = [];
        $config_attrs  = [];
        foreach ( $attributes as $name => $def ) {
            $entry = [
                'type'    => $def['type'] ?? null,
                'default' => $def['default'] ?? null,
                'role'    => $def['role'] ?? null,
            ];
            if ( isset( $def['enum'] ) ) {
                $entry['enum'] = $def['enum'];
            }
            if ( isset( $def['source'] ) ) {
                $entry['source'] = $def['source'];
            }

            if ( ( $def['role'] ?? '' ) === 'content' ) {
                $content_attrs[ $name ] = $entry;
            } else {
                $config_attrs[ $name ] = $entry;
            }
        }

        return [
            'name'              => $block_name,
            'title'             => $block_type->title ?? '',
            'category'          => $block_type->category ?? '',
            'description'       => $block_type->description ?? '',
            'supports'          => $supports,
            'inspectorPanels'   => self::resolve_inspector_panels( $supports ),
            'contentAttributes' => $content_attrs,
            'configAttributes'  => $config_attrs,
            'styles'            => array_map( fn( $s ) => [
                'name'      => $s['name'] ?? '',
                'label'     => $s['label'] ?? '',
                'isDefault' => $s['isDefault'] ?? false,
            ], $styles ),
            'variations'        => array_map( fn( $v ) => [
                'name'        => $v['name'] ?? '',
                'title'       => $v['title'] ?? '',
                'description' => $v['description'] ?? '',
                'scope'       => $v['scope'] ?? null,
            ], array_slice( $variations, 0, 10 ) ),
            'parent'            => $block_type->parent ?? null,
            'allowedBlocks'     => $block_type->allowed_blocks ?? null,
            'apiVersion'        => $block_type->api_version ?? 1,
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
        $settings       = wp_get_global_settings();
        $global_styles  = wp_get_global_styles();

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

        return [
            'colors'       => $colors,
            'gradients'    => $gradients,
            'fontSizes'    => $font_sizes,
            'fontFamilies' => $font_families,
            'spacing'      => $spacing,
            'shadows'      => $shadows,
            'layout'       => [
                'content' => $settings['layout']['contentSize'] ?? '',
                'wide'    => $settings['layout']['wideSize'] ?? '',
            ],
            'enabledFeatures' => [
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
            'block' => [
                'name'                => $block_name,
                'title'               => $type_info['title'] ?? '',
                'currentAttributes'   => $attributes,
                'inspectorPanels'     => $type_info['inspectorPanels'] ?? [],
                'styles'              => $type_info['styles'] ?? [],
                'activeStyle'         => self::extract_active_style( $attributes['className'] ?? '', $type_info['styles'] ?? [] ),
                'variations'          => $type_info['variations'] ?? [],
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

    public static function for_template_parts( ?string $area = null ): array {
        $parts  = get_block_templates( [], 'wp_template_part' );
        $result = [];

        foreach ( $parts as $part ) {
            if ( $area !== null && ( $part->area ?? '' ) !== $area ) {
                continue;
            }
            $result[] = [
                'slug'    => $part->slug ?? '',
                'title'   => $part->title ?? '',
                'area'    => $part->area ?? '',
                'content' => $part->content ?? '',
            ];
        }

        return $result;
    }

    private static function flatten_supports( array $obj, string $prefix = '' ): array {
        $entries = [];
        foreach ( $obj as $key => $val ) {
            $path = $prefix !== '' ? "{$prefix}.{$key}" : $key;

            if ( is_bool( $val ) || is_string( $val ) || ( is_array( $val ) && array_is_list( $val ) ) ) {
                $entries[] = [ $path, $val ];
            } elseif ( is_array( $val ) ) {
                $entries = array_merge( $entries, self::flatten_supports( $val, $path ) );
            }
        }
        return $entries;
    }

    private static function is_truthy( mixed $val ): bool {
        if ( $val === true ) return true;
        if ( $val === false || $val === null ) return false;
        if ( is_array( $val ) ) return count( $val ) > 0;
        return (bool) $val;
    }

    private static function extract_active_style( string $class_name, array $styles ): ?string {
        if ( $class_name === '' ) return null;
        foreach ( $styles as $style ) {
            if ( str_contains( $class_name, 'is-style-' . ( $style['name'] ?? '' ) ) ) {
                return $style['name'];
            }
        }
        return null;
    }

    private static function merge_presets( array|string $feature ): array {
        if ( ! is_array( $feature ) ) return [];
        if ( array_is_list( $feature ) ) return $feature;

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
}
