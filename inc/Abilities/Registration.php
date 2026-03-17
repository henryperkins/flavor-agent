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
                        'properties' => [
                            'blockName'           => [ 'type' => 'string', 'description' => 'Block type name (e.g. core/group)' ],
                            'attributes'          => [ 'type' => 'object', 'description' => 'Current block attributes. Canonical visibility state lives in attributes.metadata.blockVisibility.' ],
                            'innerBlocks'         => [ 'type' => 'array', 'description' => 'Nested blocks (optional)' ],
                            'isInsideContentOnly' => [ 'type' => 'boolean', 'description' => 'Whether the block is inside a contentOnly editing container' ],
                            'blockVisibility'     => [ 'description' => 'Deprecated legacy alias for attributes.metadata.blockVisibility. Accepted for backward compatibility.' ],
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
                    'visiblePatternNames' => [
                        'type'  => 'array',
                        'items' => [ 'type' => 'string' ],
                    ],
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
                    'templateRef'  => [ 'type' => 'string', 'description' => 'Template identifier from the Site Editor.' ],
                    'templateType' => [ 'type' => 'string', 'description' => 'Normalized template type (single, page, 404, etc.). Derived from templateRef if absent.' ],
                    'prompt'       => [ 'type' => 'string' ],
                    'visiblePatternNames' => [
                        'type'        => 'array',
                        'description' => 'Pattern names currently available in the editor inserter for this template context.',
                        'items'       => [ 'type' => 'string' ],
                    ],
                ],
                'required' => [ 'templateRef' ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'suggestions' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'label'              => [ 'type' => 'string' ],
                                'description'        => [ 'type' => 'string' ],
                                'templateParts'      => [
                                    'type'  => 'array',
                                    'items' => [
                                        'type'       => 'object',
                                        'properties' => [
                                            'slug'   => [ 'type' => 'string' ],
                                            'area'   => [ 'type' => 'string' ],
                                            'reason' => [ 'type' => 'string' ],
                                        ],
                                    ],
                                ],
                                'patternSuggestions' => [
                                    'type'  => 'array',
                                    'items' => [ 'type' => 'string' ],
                                ],
                            ],
                        ],
                    ],
                    'explanation' => [ 'type' => 'string' ],
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
                    'blockPseudoStyles' => [ 'type' => 'object' ],
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
                    'model'              => [ 'type' => [ 'string', 'null' ] ],
                    'availableAbilities' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'backends'           => [
                        'type'       => 'object',
                        'properties' => [
                            'anthropic'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'configured' => [ 'type' => 'boolean' ],
                                    'model'      => [ 'type' => [ 'string', 'null' ] ],
                                ],
                            ],
                            'azure_openai' => [
                                'type'       => 'object',
                                'properties' => [
                                    'configured'          => [ 'type' => 'boolean' ],
                                    'chatDeployment'      => [ 'type' => [ 'string', 'null' ] ],
                                    'embeddingDeployment' => [ 'type' => [ 'string', 'null' ] ],
                                ],
                            ],
                            'qdrant'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'configured' => [ 'type' => 'boolean' ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'meta'                => [ 'show_in_rest' => true, 'readonly' => true ],
        ] );
    }

    private static function suggestion_output_schema(): array {
        $suggestion_schema = [
            'type'       => 'object',
            'properties' => [
                'label'            => [ 'type' => 'string' ],
                'description'      => [ 'type' => 'string' ],
                'panel'            => [ 'type' => 'string' ],
                'type'             => [ 'type' => [ 'string', 'null' ] ],
                'attributeUpdates' => [ 'type' => 'object' ],
                'currentValue'     => [ 'type' => [ 'string', 'number', 'boolean', 'object', 'array', 'null' ] ],
                'suggestedValue'   => [ 'type' => [ 'string', 'number', 'boolean', 'object', 'array', 'null' ] ],
                'isCurrentStyle'   => [ 'type' => [ 'boolean', 'null' ] ],
                'isRecommended'    => [ 'type' => [ 'boolean', 'null' ] ],
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
