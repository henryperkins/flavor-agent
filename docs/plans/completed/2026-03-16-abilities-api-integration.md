# Abilities API Integration — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register 9 WordPress Abilities (4 read-only, 1 LLM-powered, 3 stubbed) with server-side context assembly so WordPress core AI orchestration can discover and invoke Flavor Agent's recommendation engine.

**Architecture:** New `inc/Abilities/` directory with a registration class and per-domain callback classes. A new `inc/Context/ServerCollector.php` provides PHP equivalents of the JS introspection modules using `WP_Block_Type_Registry`, `wp_get_global_settings()`, and `WP_Block_Patterns_Registry`. Action abilities reuse the existing `Prompt` + `Client` LLM layer. Hooks only fire on WP 6.9+ for backward compatibility.

**Tech Stack:** PHP 8.0+, WordPress Abilities API (WP 6.9+), `WP_Block_Type_Registry`, `wp_get_global_settings()`, `WP_Block_Patterns_Registry`, `get_block_templates()`.

**Spec:** `docs/specs/2026-03-16-abilities-api-integration-design.md`

---

## File Map

```
inc/
├── Abilities/
│   ├── Registration.php        # NEW — category + 9 ability registrations
│   ├── BlockAbilities.php      # NEW — recommend-block, introspect-block
│   ├── PatternAbilities.php    # NEW — recommend-patterns (stub), list-patterns
│   ├── TemplateAbilities.php   # NEW — recommend-template (stub), list-template-parts
│   ├── NavigationAbilities.php # NEW — recommend-navigation (stub)
│   └── InfraAbilities.php      # NEW — get-theme-tokens, check-status
├── Context/
│   └── ServerCollector.php     # NEW — server-side context assembly
├── LLM/
│   ├── Client.php              # EXISTING — unchanged
│   └── Prompt.php              # EXISTING — unchanged
├── REST/
│   └── Agent_Controller.php    # EXISTING — unchanged
└── Settings.php                # EXISTING — unchanged

flavor-agent.php                # MODIFY — add 2 hook lines
```

---

## Chunk 1: Server-Side Context Assembly

### Task 1: Create ServerCollector with block introspection

**Files:**
- Create: `inc/Context/ServerCollector.php`

This is the PHP equivalent of the JS `block-inspector.js` + `theme-tokens.js`. It provides the data layer all abilities depend on.

- [ ] **Step 1: Create the ServerCollector class with SUPPORT_TO_PANEL and introspect_block_type()**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class ServerCollector {

    /**
     * Same mapping as src/context/block-inspector.js SUPPORT_TO_PANEL.
     */
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

    /**
     * Introspect a block type by name — PHP equivalent of JS introspectBlockType().
     *
     * @param string $block_name e.g. 'core/group'
     * @return array|null Capability manifest, or null if block not registered.
     */
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

    /**
     * Resolve which Inspector panels a block exposes based on its supports.
     */
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
     * Collect theme design tokens — PHP equivalent of JS collectThemeTokens() + summarizeTokens().
     *
     * @return array Token summary in the same shape as the JS summarizeTokens() output.
     */
    public static function for_tokens(): array {
        $settings = wp_get_global_settings();

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
        ];
    }

    /**
     * Build full context for a block recommendation (server-side assembly).
     *
     * @param string $block_name  Block type name.
     * @param array  $attributes  Current block attributes.
     * @param array  $inner_blocks Serialized inner blocks (optional).
     * @return array Context in the shape Prompt::build_user() expects.
     */
    public static function for_block( string $block_name, array $attributes = [], array $inner_blocks = [] ): array {
        $type_info = self::introspect_block_type( $block_name );

        return [
            'block' => [
                'name'              => $block_name,
                'title'             => $type_info['title'] ?? '',
                'currentAttributes' => $attributes,
                'inspectorPanels'   => $type_info['inspectorPanels'] ?? [],
                'styles'            => $type_info['styles'] ?? [],
                'activeStyle'       => self::extract_active_style( $attributes['className'] ?? '', $type_info['styles'] ?? [] ),
                'variations'        => $type_info['variations'] ?? [],
                'contentAttributes' => $type_info['contentAttributes'] ?? [],
                'configAttributes'  => $type_info['configAttributes'] ?? [],
                'editingMode'       => 'default',
            ],
            'siblingsBefore' => [],
            'siblingsAfter'  => [],
            'themeTokens'    => self::for_tokens(),
        ];
    }

    /**
     * Collect filtered pattern inventory.
     *
     * @param array|null $categories    Filter by category slugs.
     * @param array|null $block_types   Filter by blockTypes.
     * @param array|null $template_types Filter by templateTypes.
     * @return array Pattern list.
     */
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

    /**
     * Collect template parts, optionally filtered by area.
     *
     * @param string|null $area Filter by area (header, footer, sidebar, navigation-overlay).
     * @return array Template part list.
     */
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

    // ── Private helpers ─────────────────────────────────────────

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

    /**
     * Merge origin-separated presets (default/theme/custom) into a flat array.
     * If the input is already a flat array, return it as-is.
     */
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
}
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php -l /home/azureuser/wordpress/flavor-agent/inc/Context/ServerCollector.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /home/azureuser/wordpress/flavor-agent
git add inc/Context/ServerCollector.php
git commit -m "feat: add ServerCollector for server-side block/token/pattern introspection"
```

---

## Chunk 2: Ability Callbacks

### Task 2: Create InfraAbilities (get-theme-tokens, check-status)

**Files:**
- Create: `inc/Abilities/InfraAbilities.php`

- [ ] **Step 1: Write InfraAbilities.php**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\ServerCollector;

final class InfraAbilities {

    public static function get_theme_tokens( array $input ): array {
        return ServerCollector::for_tokens();
    }

    public static function check_status( array $input ): array {
        $api_key = get_option( 'flavor_agent_api_key', '' );
        $model   = get_option( 'flavor_agent_model', 'claude-sonnet-4-20250514' );

        return [
            'configured'        => ! empty( $api_key ),
            'model'             => $model,
            'availableAbilities' => [
                'flavor-agent/recommend-block',
                'flavor-agent/introspect-block',
                'flavor-agent/list-patterns',
                'flavor-agent/list-template-parts',
                'flavor-agent/get-theme-tokens',
                'flavor-agent/check-status',
            ],
        ];
    }
}
```

- [ ] **Step 2: Verify syntax and commit**

```bash
php -l inc/Abilities/InfraAbilities.php
git add inc/Abilities/InfraAbilities.php
git commit -m "feat: add InfraAbilities callbacks for theme tokens and status"
```

---

### Task 3: Create BlockAbilities (recommend-block, introspect-block)

**Files:**
- Create: `inc/Abilities/BlockAbilities.php`

- [ ] **Step 1: Write BlockAbilities.php**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\Client;
use FlavorAgent\LLM\Prompt;

final class BlockAbilities {

    /**
     * Recommend block attribute/style changes via LLM.
     * Accepts minimal input; assembles full context server-side.
     */
    public static function recommend_block( array $input ): array|\WP_Error {
        $selected = $input['selectedBlock'] ?? [];
        $block_name   = $selected['blockName'] ?? '';
        $attributes   = $selected['attributes'] ?? [];
        $inner_blocks = $selected['innerBlocks'] ?? [];
        $prompt       = $input['prompt'] ?? '';

        if ( empty( $block_name ) ) {
            return new \WP_Error( 'missing_block_name', 'selectedBlock.blockName is required.', [ 'status' => 400 ] );
        }

        $api_key = get_option( 'flavor_agent_api_key', '' );
        if ( empty( $api_key ) ) {
            return new \WP_Error( 'missing_api_key', 'Configure your API key in Settings > Flavor Agent.', [ 'status' => 400 ] );
        }

        $context = ServerCollector::for_block( $block_name, $attributes, $inner_blocks );

        $system_prompt = Prompt::build_system();
        $user_prompt   = Prompt::build_user( $context, $prompt );

        $result = Client::chat( $system_prompt, $user_prompt, $api_key );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return Prompt::parse_response( $result );
    }

    /**
     * Return a block type's capability manifest (read-only, no LLM).
     */
    public static function introspect_block( array $input ): array|\WP_Error {
        $block_name = $input['blockName'] ?? '';

        if ( empty( $block_name ) ) {
            return new \WP_Error( 'missing_block_name', 'blockName is required.', [ 'status' => 400 ] );
        }

        $manifest = ServerCollector::introspect_block_type( $block_name );

        if ( $manifest === null ) {
            return new \WP_Error( 'block_not_found', "Block type '{$block_name}' is not registered.", [ 'status' => 404 ] );
        }

        return $manifest;
    }
}
```

- [ ] **Step 2: Verify syntax and commit**

```bash
php -l inc/Abilities/BlockAbilities.php
git add inc/Abilities/BlockAbilities.php
git commit -m "feat: add BlockAbilities callbacks for recommend-block and introspect-block"
```

---

### Task 4: Create PatternAbilities (list-patterns, recommend-patterns stub)

**Files:**
- Create: `inc/Abilities/PatternAbilities.php`

- [ ] **Step 1: Write PatternAbilities.php**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\ServerCollector;

final class PatternAbilities {

    /**
     * Return filtered pattern inventory (read-only, no LLM).
     */
    public static function list_patterns( array $input ): array {
        $categories     = $input['categories'] ?? null;
        $block_types    = $input['blockTypes'] ?? null;
        $template_types = $input['templateTypes'] ?? null;

        return [
            'patterns' => ServerCollector::for_patterns( $categories, $block_types, $template_types ),
        ];
    }

    /**
     * Stub: recommend patterns via LLM (not yet implemented).
     */
    public static function recommend_patterns( array $input ): \WP_Error {
        return new \WP_Error(
            'not_implemented',
            'Pattern recommendation is planned but not yet available.',
            [ 'status' => 501 ]
        );
    }
}
```

- [ ] **Step 2: Verify syntax and commit**

```bash
php -l inc/Abilities/PatternAbilities.php
git add inc/Abilities/PatternAbilities.php
git commit -m "feat: add PatternAbilities with list-patterns and recommend-patterns stub"
```

---

### Task 5: Create TemplateAbilities and NavigationAbilities

**Files:**
- Create: `inc/Abilities/TemplateAbilities.php`
- Create: `inc/Abilities/NavigationAbilities.php`

- [ ] **Step 1: Write TemplateAbilities.php**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\ServerCollector;

final class TemplateAbilities {

    /**
     * Return template parts, optionally filtered by area (read-only).
     */
    public static function list_template_parts( array $input ): array {
        $area = $input['area'] ?? null;

        return [
            'templateParts' => ServerCollector::for_template_parts( $area ),
        ];
    }

    /**
     * Stub: recommend template structure via LLM (not yet implemented).
     */
    public static function recommend_template( array $input ): \WP_Error {
        return new \WP_Error(
            'not_implemented',
            'Template recommendation is planned but not yet available.',
            [ 'status' => 501 ]
        );
    }
}
```

- [ ] **Step 2: Write NavigationAbilities.php**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

final class NavigationAbilities {

    /**
     * Stub: recommend navigation structure via LLM (not yet implemented).
     */
    public static function recommend_navigation( array $input ): \WP_Error {
        return new \WP_Error(
            'not_implemented',
            'Navigation recommendation is planned but not yet available.',
            [ 'status' => 501 ]
        );
    }
}
```

- [ ] **Step 3: Verify syntax and commit both**

```bash
php -l inc/Abilities/TemplateAbilities.php && php -l inc/Abilities/NavigationAbilities.php
git add inc/Abilities/TemplateAbilities.php inc/Abilities/NavigationAbilities.php
git commit -m "feat: add TemplateAbilities and NavigationAbilities (list + stubs)"
```

---

## Chunk 3: Registration and Bootstrap

### Task 6: Create Registration.php with all 9 abilities

**Files:**
- Create: `inc/Abilities/Registration.php`

This is the central file that registers the category and all 9 abilities with full `input_schema` and `output_schema`.

- [ ] **Step 1: Write Registration.php**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

final class Registration {

    public static function register_category(): void {
        wp_register_ability_category( 'flavor-agent', [
            'label' => __( 'Flavor Agent', 'flavor-agent' ),
        ] );
    }

    public static function register_abilities(): void {
        self::register_block_abilities();
        self::register_pattern_abilities();
        self::register_template_abilities();
        self::register_navigation_abilities();
        self::register_infra_abilities();
    }

    private static function register_block_abilities(): void {
        wp_register_ability( 'flavor-agent/recommend-block', [
            'label'               => __( 'Get block recommendations', 'flavor-agent' ),
            'description'         => __( 'Suggest attribute and style changes for a block using theme design tokens.', 'flavor-agent' ),
            'category'            => 'flavor-agent',
            'callback'            => [ BlockAbilities::class, 'recommend_block' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'selectedBlock' => [
                        'type'       => 'object',
                        'required'   => true,
                        'properties' => [
                            'blockName'   => [ 'type' => 'string', 'description' => 'Block type name (e.g. core/group)' ],
                            'attributes'  => [ 'type' => 'object', 'description' => 'Current block attributes' ],
                            'innerBlocks' => [ 'type' => 'array', 'description' => 'Nested blocks (optional)' ],
                        ],
                        'required' => [ 'blockName' ],
                    ],
                    'prompt' => [ 'type' => 'string', 'description' => 'Optional user instruction' ],
                ],
                'required' => [ 'selectedBlock' ],
            ],
            'output_schema'       => self::suggestion_output_schema(),
            'meta'                => [ 'show_in_rest' => true ],
        ] );

        wp_register_ability( 'flavor-agent/introspect-block', [
            'label'               => __( 'Introspect block type', 'flavor-agent' ),
            'description'         => __( 'Return a block type\'s capabilities: supports, Inspector panels, attributes, styles, and variations.', 'flavor-agent' ),
            'category'            => 'flavor-agent',
            'callback'            => [ BlockAbilities::class, 'introspect_block' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'blockName' => [ 'type' => 'string', 'description' => 'Block type name (e.g. core/group)' ],
                ],
                'required' => [ 'blockName' ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'name'              => [ 'type' => 'string' ],
                    'title'             => [ 'type' => 'string' ],
                    'category'          => [ 'type' => 'string' ],
                    'supports'          => [ 'type' => 'object' ],
                    'inspectorPanels'   => [ 'type' => 'object' ],
                    'contentAttributes' => [ 'type' => 'object' ],
                    'configAttributes'  => [ 'type' => 'object' ],
                    'styles'            => [ 'type' => 'array' ],
                    'variations'        => [ 'type' => 'array' ],
                    'parent'            => [ 'type' => [ 'array', 'null' ] ],
                    'allowedBlocks'     => [ 'type' => [ 'array', 'null' ] ],
                ],
            ],
            'meta'                => [ 'show_in_rest' => true, 'readonly' => true ],
        ] );
    }

    private static function register_pattern_abilities(): void {
        wp_register_ability( 'flavor-agent/recommend-patterns', [
            'label'               => __( 'Recommend patterns', 'flavor-agent' ),
            'description'         => __( 'Rank existing block patterns for the current editing context using LLM.', 'flavor-agent' ),
            'category'            => 'flavor-agent',
            'callback'            => [ PatternAbilities::class, 'recommend_patterns' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'postType'     => [ 'type' => 'string', 'description' => 'Current post type' ],
                    'blockContext' => [
                        'type'       => 'object',
                        'properties' => [
                            'blockName'  => [ 'type' => 'string' ],
                            'attributes' => [ 'type' => 'object' ],
                        ],
                    ],
                    'templateType' => [ 'type' => 'string' ],
                    'prompt'       => [ 'type' => 'string' ],
                ],
                'required' => [ 'postType' ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'recommendations' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'name'       => [ 'type' => 'string' ],
                                'title'      => [ 'type' => 'string' ],
                                'score'      => [ 'type' => 'number' ],
                                'reason'     => [ 'type' => 'string' ],
                                'categories' => [ 'type' => 'array' ],
                                'content'    => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ],
            'meta'                => [ 'show_in_rest' => true ],
        ] );

        wp_register_ability( 'flavor-agent/list-patterns', [
            'label'               => __( 'List block patterns', 'flavor-agent' ),
            'description'         => __( 'Return registered block patterns, optionally filtered by category, block type, or template type.', 'flavor-agent' ),
            'category'            => 'flavor-agent',
            'callback'            => [ PatternAbilities::class, 'list_patterns' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'categories'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'blockTypes'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'templateTypes' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'patterns' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'name'          => [ 'type' => 'string' ],
                                'title'         => [ 'type' => 'string' ],
                                'description'   => [ 'type' => 'string' ],
                                'categories'    => [ 'type' => 'array' ],
                                'blockTypes'    => [ 'type' => 'array' ],
                                'templateTypes' => [ 'type' => 'array' ],
                                'content'       => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ],
            'meta'                => [ 'show_in_rest' => true, 'readonly' => true ],
        ] );
    }

    private static function register_template_abilities(): void {
        wp_register_ability( 'flavor-agent/recommend-template', [
            'label'               => __( 'Recommend template structure', 'flavor-agent' ),
            'description'         => __( 'Suggest template-part arrangements and patterns for a template type.', 'flavor-agent' ),
            'category'            => 'flavor-agent',
            'callback'            => [ TemplateAbilities::class, 'recommend_template' ],
            'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'templateType' => [ 'type' => 'string', 'description' => 'Template type: page, 404, archive, single, index, etc.' ],
                    'prompt'       => [ 'type' => 'string' ],
                ],
                'required' => [ 'templateType' ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'suggestions'  => [ 'type' => 'array' ],
                    'explanation'  => [ 'type' => 'string' ],
                ],
            ],
            'meta'                => [ 'show_in_rest' => true ],
        ] );

        wp_register_ability( 'flavor-agent/list-template-parts', [
            'label'               => __( 'List template parts', 'flavor-agent' ),
            'description'         => __( 'Return registered template parts, optionally filtered by area.', 'flavor-agent' ),
            'category'            => 'flavor-agent',
            'callback'            => [ TemplateAbilities::class, 'list_template_parts' ],
            'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'area' => [ 'type' => 'string', 'description' => 'Filter by area: header, footer, sidebar, navigation-overlay' ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'templateParts' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'slug'    => [ 'type' => 'string' ],
                                'title'   => [ 'type' => 'string' ],
                                'area'    => [ 'type' => 'string' ],
                                'content' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ],
            'meta'                => [ 'show_in_rest' => true, 'readonly' => true ],
        ] );
    }

    private static function register_navigation_abilities(): void {
        wp_register_ability( 'flavor-agent/recommend-navigation', [
            'label'               => __( 'Recommend navigation structure', 'flavor-agent' ),
            'description'         => __( 'Suggest navigation menu structure, overlay behavior, and organization.', 'flavor-agent' ),
            'category'            => 'flavor-agent',
            'callback'            => [ NavigationAbilities::class, 'recommend_navigation' ],
            'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'menuId'             => [ 'type' => 'integer', 'description' => 'Nav menu ID' ],
                    'navigationMarkup'   => [ 'type' => 'string', 'description' => 'Serialized navigation block markup' ],
                    'prompt'             => [ 'type' => 'string' ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'suggestions'  => [ 'type' => 'array' ],
                    'explanation'  => [ 'type' => 'string' ],
                ],
            ],
            'meta'                => [ 'show_in_rest' => true ],
        ] );
    }

    private static function register_infra_abilities(): void {
        wp_register_ability( 'flavor-agent/get-theme-tokens', [
            'label'               => __( 'Get theme design tokens', 'flavor-agent' ),
            'description'         => __( 'Return the current theme\'s color palette, font sizes, font families, spacing, shadows, and layout constraints.', 'flavor-agent' ),
            'category'            => 'flavor-agent',
            'callback'            => [ InfraAbilities::class, 'get_theme_tokens' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'input_schema'        => [ 'type' => 'object', 'properties' => [] ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'colors'          => [ 'type' => 'array' ],
                    'gradients'       => [ 'type' => 'array' ],
                    'fontSizes'       => [ 'type' => 'array' ],
                    'fontFamilies'    => [ 'type' => 'array' ],
                    'spacing'         => [ 'type' => 'array' ],
                    'shadows'         => [ 'type' => 'array' ],
                    'layout'          => [ 'type' => 'object' ],
                    'enabledFeatures' => [ 'type' => 'object' ],
                ],
            ],
            'meta'                => [ 'show_in_rest' => true, 'readonly' => true ],
        ] );

        wp_register_ability( 'flavor-agent/check-status', [
            'label'               => __( 'Check Flavor Agent status', 'flavor-agent' ),
            'description'         => __( 'Report whether the LLM API key is configured and which model is active.', 'flavor-agent' ),
            'category'            => 'flavor-agent',
            'callback'            => [ InfraAbilities::class, 'check_status' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'input_schema'        => [ 'type' => 'object', 'properties' => [] ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'configured'         => [ 'type' => 'boolean' ],
                    'model'              => [ 'type' => 'string' ],
                    'availableAbilities' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                ],
            ],
            'meta'                => [ 'show_in_rest' => true, 'readonly' => true ],
        ] );
    }

    /**
     * Shared output schema for suggestion-producing abilities.
     */
    private static function suggestion_output_schema(): array {
        $suggestion_schema = [
            'type'       => 'object',
            'properties' => [
                'label'            => [ 'type' => 'string' ],
                'description'      => [ 'type' => 'string' ],
                'panel'            => [ 'type' => 'string' ],
                'attributeUpdates' => [ 'type' => 'object' ],
                'confidence'       => [ 'type' => [ 'number', 'null' ] ],
                'preview'          => [ 'type' => [ 'string', 'null' ] ],
                'presetSlug'       => [ 'type' => [ 'string', 'null' ] ],
                'cssVar'           => [ 'type' => [ 'string', 'null' ] ],
            ],
        ];

        return [
            'type'       => 'object',
            'properties' => [
                'settings'    => [ 'type' => 'array', 'items' => $suggestion_schema ],
                'styles'      => [ 'type' => 'array', 'items' => $suggestion_schema ],
                'block'       => [ 'type' => 'array', 'items' => $suggestion_schema ],
                'explanation' => [ 'type' => 'string' ],
            ],
        ];
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php -l /home/azureuser/wordpress/flavor-agent/inc/Abilities/Registration.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add inc/Abilities/Registration.php
git commit -m "feat: add Registration with all 9 ability definitions and full schemas"
```

---

### Task 7: Update bootstrap and verify

**Files:**
- Modify: `flavor-agent.php` — add 2 hook lines

- [ ] **Step 1: Add Abilities API hooks to bootstrap**

Add these two lines after the existing `add_action` calls (after line 28):

```php
add_action( 'wp_abilities_api_categories_init', [ FlavorAgent\Abilities\Registration::class, 'register_category' ] );
add_action( 'wp_abilities_api_init', [ FlavorAgent\Abilities\Registration::class, 'register_abilities' ] );
```

- [ ] **Step 2: Regenerate Composer autoloader**

```bash
cd /home/azureuser/wordpress/flavor-agent && composer dump-autoload
```

- [ ] **Step 3: Verify PHP syntax on all new files**

```bash
php -l inc/Context/ServerCollector.php && \
php -l inc/Abilities/Registration.php && \
php -l inc/Abilities/BlockAbilities.php && \
php -l inc/Abilities/PatternAbilities.php && \
php -l inc/Abilities/TemplateAbilities.php && \
php -l inc/Abilities/NavigationAbilities.php && \
php -l inc/Abilities/InfraAbilities.php && \
php -l flavor-agent.php
```

Expected: All pass with `No syntax errors detected`

- [ ] **Step 4: Restart WordPress and verify plugin loads**

```bash
cd /home/azureuser/wordpress && docker compose restart wordpress
```

Visit `http://localhost:8888/wp-admin/plugins.php` — Flavor Agent should be active with no errors.

- [ ] **Step 5: Verify Abilities API registration (WP 6.9+ only)**

If the WordPress installation is 6.9+:

```bash
curl -s "http://localhost:8888/?rest_route=/wp-abilities/v1/abilities" | python3 -c "
import sys, json
data = json.load(sys.stdin)
abilities = [a.get('id','') for a in data] if isinstance(data, list) else []
expected = ['flavor-agent/recommend-block', 'flavor-agent/introspect-block', 'flavor-agent/get-theme-tokens', 'flavor-agent/check-status', 'flavor-agent/list-patterns', 'flavor-agent/list-template-parts', 'flavor-agent/recommend-patterns', 'flavor-agent/recommend-template', 'flavor-agent/recommend-navigation']
found = [a for a in expected if a in abilities]
print(f'Found {len(found)}/{len(expected)} abilities: {found}')
"
```

If WP < 6.9, the Abilities API endpoints won't exist — that's expected and correct (backward compatibility).

- [ ] **Step 6: Verify existing REST endpoint still works**

```bash
curl -s "http://localhost:8888/?rest_route=/" | python3 -c "import sys,json; d=json.load(sys.stdin); print('flavor-agent/v1 still registered:', 'flavor-agent/v1' in d.get('namespaces',[]))"
```

Expected: `True`

- [ ] **Step 7: Commit bootstrap change**

```bash
git add flavor-agent.php
git commit -m "feat: register Abilities API hooks in bootstrap (WP 6.9+ only)"
```

---

## Post-Implementation Notes

### What this builds
- 9 WordPress abilities registered under `flavor-agent` category with full JSON Schema
- Server-side block introspection (PHP equivalent of JS `block-inspector.js`)
- Server-side theme token collection (PHP equivalent of JS `theme-tokens.js`)
- Server-side pattern and template part listing
- `recommend-block` ability works end-to-end via LLM (reuses existing `Prompt` + `Client`)
- 3 LLM-powered abilities stubbed with proper 501 responses and full schemas
- Backward compatible: WP 6.5-6.8 unaffected, WP 6.9+ gets abilities

### What this does NOT build
- `recommend-patterns` LLM prompt and implementation
- `recommend-template` LLM prompt and implementation
- `recommend-navigation` LLM prompt and implementation
- JS-side ability consumption via `@wordpress/abilities`
- Tests (no WP test harness is set up — verification is manual via REST)

### Files created (8 new, 1 modified)
- `inc/Context/ServerCollector.php` — 260 lines
- `inc/Abilities/Registration.php` — 280 lines
- `inc/Abilities/BlockAbilities.php` — 60 lines
- `inc/Abilities/PatternAbilities.php` — 35 lines
- `inc/Abilities/TemplateAbilities.php` — 35 lines
- `inc/Abilities/NavigationAbilities.php` — 20 lines
- `inc/Abilities/InfraAbilities.php` — 30 lines
- `flavor-agent.php` — 2 lines added
