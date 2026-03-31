<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

final class Registration {

	public static function register_category(): void {
		wp_register_ability_category(
			'flavor-agent',
			[
				'label'       => __( 'Flavor Agent', 'flavor-agent' ),
				'description' => __( 'LLM-assisted editing, pattern, template, and diagnostic abilities for the WordPress editor.', 'flavor-agent' ),
			]
		);
	}

	public static function register_abilities(): void {
		self::register_block_abilities();
		self::register_pattern_abilities();
		self::register_template_abilities();
		self::register_navigation_abilities();
		self::register_style_abilities();
		self::register_wordpress_docs_abilities();
		self::register_infra_abilities();
	}

	private static function register_block_abilities(): void {
		wp_register_ability(
			'flavor-agent/recommend-block',
			[
				'label'               => __( 'Get block recommendations', 'flavor-agent' ),
				'description'         => __( 'Suggest attribute and style changes for a block using theme design tokens.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ BlockAbilities::class, 'recommend_block' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'selectedBlock' => self::selected_block_input_schema(),
						'prompt'        => [
							'type'        => 'string',
							'description' => 'Optional user instruction',
						],
					],
					'required'   => [ 'selectedBlock' ],
				],
				'output_schema'       => self::suggestion_output_schema(),
				'meta'                => [ 'show_in_rest' => true ],
			]
		);

		wp_register_ability(
			'flavor-agent/introspect-block',
			[
				'label'               => __( 'Introspect block type', 'flavor-agent' ),
				'description'         => __( 'Return a block type\'s capabilities: supports, Inspector panels, attributes, styles, and variations.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ BlockAbilities::class, 'introspect_block' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'blockName' => [
							'type'        => 'string',
							'description' => 'Block type name (e.g. core/group)',
						],
					],
					'required'   => [ 'blockName' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'name'                => [ 'type' => 'string' ],
						'title'               => [ 'type' => 'string' ],
						'category'            => [ 'type' => 'string' ],
						'supports'            => [ 'type' => 'object' ],
						'inspectorPanels'     => [ 'type' => 'object' ],
						'contentAttributes'   => [ 'type' => 'object' ],
						'configAttributes'    => [ 'type' => 'object' ],
						'styles'              => [ 'type' => 'array' ],
						'variations'          => [ 'type' => 'array' ],
						'supportsContentRole' => [ 'type' => 'boolean' ],
						'parent'              => [ 'type' => [ 'array', 'null' ] ],
						'allowedBlocks'       => [ 'type' => [ 'array', 'null' ] ],
					],
				],
				'meta'                => [
					'show_in_rest' => true,
					'readonly'     => true,
				],
			]
		);
	}

	private static function register_pattern_abilities(): void {
		wp_register_ability(
			'flavor-agent/recommend-patterns',
			[
				'label'               => __( 'Recommend patterns', 'flavor-agent' ),
				'description'         => __( 'Rank existing block patterns for the current editing context using LLM.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ PatternAbilities::class, 'recommend_patterns' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'postType'            => [
							'type'        => 'string',
							'description' => 'Current post type',
						],
						'blockContext'        => [
							'type'       => 'object',
							'properties' => [
								'blockName'  => [ 'type' => 'string' ],
								'attributes' => [ 'type' => 'object' ],
							],
						],
						'templateType'        => [ 'type' => 'string' ],
						'prompt'              => [ 'type' => 'string' ],
						'visiblePatternNames' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
					'required'   => [ 'postType' ],
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
			]
		);

		wp_register_ability(
			'flavor-agent/list-patterns',
			[
				'label'               => __( 'List block patterns', 'flavor-agent' ),
				'description'         => __( 'Return registered block patterns, optionally filtered by category, block type, or template type.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ PatternAbilities::class, 'list_patterns' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'categories'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'blockTypes'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'templateTypes' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
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
				'meta'                => [
					'show_in_rest' => true,
					'readonly'     => true,
				],
			]
		);
	}

	private static function register_template_abilities(): void {
		wp_register_ability(
			'flavor-agent/recommend-template',
			[
				'label'               => __( 'Recommend template structure', 'flavor-agent' ),
				'description'         => __( 'Suggest template-part arrangements and patterns for a template type.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ TemplateAbilities::class, 'recommend_template' ],
				'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'templateRef'         => [
							'type'        => 'string',
							'description' => 'Template identifier from the Site Editor.',
						],
						'templateType'        => [
							'type'        => 'string',
							'description' => 'Normalized template type (single, page, 404, etc.). Derived from templateRef if absent.',
						],
						'prompt'              => [ 'type' => 'string' ],
						'visiblePatternNames' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'editorSlots'         => [
							'type'       => 'object',
							'properties' => [
								'assignedParts' => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'slug' => [ 'type' => 'string' ],
											'area' => [ 'type' => 'string' ],
										],
									],
								],
								'emptyAreas'    => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
								'allowedAreas'  => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
							],
						],
					],
					'required'   => [ 'templateRef' ],
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
									'operations'         => [
										'type'  => 'array',
										'items' => [
											'type'       => 'object',
											'properties' => [
												'type' => [ 'type' => 'string' ],
												'slug' => [ 'type' => 'string' ],
												'area' => [ 'type' => 'string' ],
												'currentSlug' => [ 'type' => 'string' ],
												'patternName' => [ 'type' => 'string' ],
											],
										],
									],
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
			]
		);

		wp_register_ability(
			'flavor-agent/recommend-template-part',
			[
				'label'               => __( 'Recommend template-part structure', 'flavor-agent' ),
				'description'         => __( 'Suggest focused structural improvements and patterns for a single template part.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ TemplateAbilities::class, 'recommend_template_part' ],
				'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'templatePartRef'     => [
							'type'        => 'string',
							'description' => 'Template-part identifier from the Site Editor.',
						],
						'prompt'              => [ 'type' => 'string' ],
						'visiblePatternNames' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
					'required'   => [ 'templatePartRef' ],
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
									'blockHints'         => [
										'type'  => 'array',
										'items' => [
											'type'       => 'object',
											'properties' => [
												'path'   => [
													'type' => 'array',
													'items' => [ 'type' => 'integer' ],
												],
												'label'  => [ 'type' => 'string' ],
												'blockName' => [ 'type' => 'string' ],
												'reason' => [ 'type' => 'string' ],
											],
										],
									],
									'patternSuggestions' => [
										'type'  => 'array',
										'items' => [ 'type' => 'string' ],
									],
									'operations'         => [
										'type'  => 'array',
										'items' => [
											'type'       => 'object',
											'properties' => [
												'type' => [ 'type' => 'string' ],
												'patternName' => [ 'type' => 'string' ],
												'placement' => [ 'type' => 'string' ],
												'targetPath' => [
													'type' => 'array',
													'items' => [ 'type' => 'integer' ],
												],
												'expectedBlockName' => [ 'type' => 'string' ],
											],
										],
									],
								],
							],
						],
						'explanation' => [ 'type' => 'string' ],
					],
				],
				'meta'                => [ 'show_in_rest' => true ],
			]
		);

		wp_register_ability(
			'flavor-agent/list-template-parts',
			[
				'label'               => __( 'List template parts', 'flavor-agent' ),
				'description'         => __( 'Return registered template parts, optionally filtered by area.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ TemplateAbilities::class, 'list_template_parts' ],
				'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'area' => [
							'type'        => 'string',
							'description' => 'Filter by area: header, footer, sidebar, navigation-overlay',
						],
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
				'meta'                => [
					'show_in_rest' => true,
					'readonly'     => true,
				],
			]
		);
	}

	private static function register_navigation_abilities(): void {
		wp_register_ability(
			'flavor-agent/recommend-navigation',
			[
				'label'               => __( 'Recommend navigation structure', 'flavor-agent' ),
				'description'         => __( 'Suggest navigation menu structure, overlay behavior, and organization.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ NavigationAbilities::class, 'recommend_navigation' ],
				'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'menuId'           => [
							'type'        => 'integer',
							'description' => 'Nav menu ID',
						],
						'navigationMarkup' => [
							'type'        => 'string',
							'description' => 'Serialized navigation block markup',
						],
						'prompt'           => [ 'type' => 'string' ],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'suggestions' => [ 'type' => 'array' ],
						'explanation' => [ 'type' => 'string' ],
					],
				],
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}

	private static function register_style_abilities(): void {
		wp_register_ability(
			'flavor-agent/recommend-style',
			[
				'label'               => __( 'Recommend site styles', 'flavor-agent' ),
				'description'         => __( 'Suggest theme-safe Global Styles changes and theme style variations for the Site Editor.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ StyleAbilities::class, 'recommend_style' ],
				'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'scope'        => self::open_object_schema(
							[
								'surface'        => [ 'type' => 'string' ],
								'scopeKey'       => [ 'type' => 'string' ],
								'globalStylesId' => [ 'type' => 'string' ],
								'postType'       => [ 'type' => 'string' ],
								'entityId'       => [ 'type' => 'string' ],
								'entityKind'     => [ 'type' => 'string' ],
								'entityName'     => [ 'type' => 'string' ],
								'stylesheet'     => [ 'type' => 'string' ],
							],
							'Resolved Global Styles scope descriptor from the Site Editor.'
						),
						'styleContext' => self::open_object_schema(
							[
								'currentConfig'         => self::open_object_schema(),
								'mergedConfig'          => self::open_object_schema(),
								'availableVariations'   => [
									'type'  => 'array',
									'items' => self::open_object_schema(),
								],
								'themeTokenDiagnostics' => self::open_object_schema(
									[
										'source'      => [ 'type' => 'string' ],
										'settingsKey' => [ 'type' => 'string' ],
										'reason'      => [ 'type' => 'string' ],
									]
								),
							],
							'Current Global Styles editor context needed for style recommendations.'
						),
						'prompt'       => [
							'type'        => 'string',
							'description' => 'Optional user instruction',
						],
					],
					'required'   => [ 'scope', 'styleContext' ],
				],
				'output_schema'       => self::style_recommendation_output_schema(),
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}

	private static function register_wordpress_docs_abilities(): void {
		wp_register_ability(
			'flavor-agent/search-wordpress-docs',
			[
				'label'               => __( 'Search WordPress developer docs', 'flavor-agent' ),
				'description'         => __( 'Query the configured Cloudflare AI Search index for official WordPress developer documentation.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ WordPressDocsAbilities::class, 'search_wordpress_docs' ],
				'permission_callback' => [ WordPressDocsAbilities::class, 'can_search_wordpress_docs' ],
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'query'      => [
							'type'        => 'string',
							'description' => 'Search query for WordPress developer documentation.',
						],
						'entityKey'  => [
							'type'        => 'string',
							'description' => 'Optional normalized entity cache key to warm alongside the exact query cache. Use namespace/block-name for blocks or template:single, template:404, etc. for templates.',
						],
						'maxResults' => [
							'type'        => 'integer',
							'description' => 'Optional result cap between 1 and 8.',
						],
					],
					'required'   => [ 'query' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'query'    => [ 'type' => 'string' ],
						'guidance' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'        => [ 'type' => 'string' ],
									'title'     => [ 'type' => 'string' ],
									'sourceKey' => [ 'type' => 'string' ],
									'url'       => [ 'type' => 'string' ],
									'excerpt'   => [ 'type' => 'string' ],
									'score'     => [ 'type' => 'number' ],
								],
							],
						],
					],
				],
				'meta'                => [
					'show_in_rest' => true,
					'readonly'     => true,
				],
			]
		);
	}

	private static function register_infra_abilities(): void {
		wp_register_ability(
			'flavor-agent/get-theme-tokens',
			[
				'label'               => __( 'Get theme design tokens', 'flavor-agent' ),
				'description'         => __( 'Return the current theme\'s color palette, font sizes, font families, spacing, shadows, and layout constraints.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ InfraAbilities::class, 'get_theme_tokens' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'colors'            => [ 'type' => 'array' ],
						'gradients'         => [ 'type' => 'array' ],
						'fontSizes'         => [ 'type' => 'array' ],
						'fontFamilies'      => [ 'type' => 'array' ],
						'spacing'           => [ 'type' => 'array' ],
						'shadows'           => [ 'type' => 'array' ],
						'diagnostics'       => [ 'type' => 'object' ],
						'layout'            => [ 'type' => 'object' ],
						'enabledFeatures'   => [ 'type' => 'object' ],
						'blockPseudoStyles' => [ 'type' => 'object' ],
					],
				],
				'meta'                => [
					'show_in_rest' => true,
					'readonly'     => true,
				],
			]
		);

		wp_register_ability(
			'flavor-agent/check-status',
			[
				'label'               => __( 'Check Flavor Agent status', 'flavor-agent' ),
				'description'         => __( 'Report configured Flavor Agent backends, active models, and abilities currently available to the current user.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ InfraAbilities::class, 'check_status' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'configured'         => [
							'type'        => 'boolean',
							'description' => 'Whether at least one recommendation or docs backend is configured.',
						],
						'model'              => [
							'type'        => [ 'string', 'null' ],
							'description' => 'Legacy model indicator. Returns provider-managed when block recommendations are backed by the WordPress AI Client, otherwise the active OpenAI provider model or null.',
						],
						'availableAbilities' => [
							'type'        => 'array',
							'description' => 'Abilities currently available to the requesting user under the active backend configuration.',
							'items'       => [ 'type' => 'string' ],
						],
						'surfaces'           => SurfaceCapabilities::surfaces_output_schema(),
						'backends'           => [
							'type'       => 'object',
							'properties' => [
								'wordpress_ai_client'  => [
									'type'       => 'object',
									'properties' => [
										'configured' => [ 'type' => 'boolean' ],
									],
								],
								'azure_openai'         => [
									'type'       => 'object',
									'properties' => [
										'configured'     => [ 'type' => 'boolean' ],
										'chatDeployment' => [ 'type' => [ 'string', 'null' ] ],
										'embeddingDeployment' => [ 'type' => [ 'string', 'null' ] ],
									],
								],
								'openai_native'        => [
									'type'       => 'object',
									'properties' => [
										'configured'     => [ 'type' => 'boolean' ],
										'chatModel'      => [ 'type' => [ 'string', 'null' ] ],
										'embeddingModel' => [ 'type' => [ 'string', 'null' ] ],
									],
								],
								'qdrant'               => [
									'type'       => 'object',
									'properties' => [
										'configured' => [ 'type' => 'boolean' ],
									],
								],
								'cloudflare_ai_search' => [
									'type'       => 'object',
									'properties' => [
										'configured' => [ 'type' => 'boolean' ],
										'instanceId' => [ 'type' => [ 'string', 'null' ] ],
									],
								],
							],
						],
					],
				],
				'meta'                => [
					'show_in_rest' => true,
					'readonly'     => true,
				],
			]
		);
	}

	private static function selected_block_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'blockName'           => [
					'type'        => 'string',
					'description' => 'Block type name (e.g. core/group).',
				],
				'attributes'          => self::open_object_schema(
					[],
					'Current block attributes. Canonical visibility state lives in attributes.metadata.blockVisibility.'
				),
				'innerBlocks'         => [
					'type'        => 'array',
					'description' => 'Nested blocks (optional).',
					'items'       => self::open_object_schema(),
				],
				'isInsideContentOnly' => [
					'type'        => 'boolean',
					'description' => 'Whether the block is inside a contentOnly editing container.',
				],
				'supportsContentRole' => [
					'type'        => 'boolean',
					'description' => 'Whether the block declares supports.contentRole and is content-editable through inner blocks or content attributes.',
				],
				'editingMode'         => [
					'type'        => 'string',
					'description' => 'Current editing mode for the selected block.',
				],
				'childCount'          => [
					'type'        => 'integer',
					'description' => 'Number of direct child blocks nested inside the selected block.',
				],
				'structuralIdentity'  => self::structural_identity_schema(),
				'structuralAncestors' => [
					'type'        => 'array',
					'description' => 'Summarized structural ancestors leading to the selected block.',
					'items'       => self::structural_summary_item_schema(),
				],
				'structuralBranch'    => [
					'type'        => 'array',
					'description' => 'Summarized structural branch rooted at the nearest structural ancestor.',
					'items'       => self::structural_summary_item_schema( true ),
				],
				'blockVisibility'     => self::open_object_schema(
					[],
					'Deprecated legacy alias for attributes.metadata.blockVisibility. Accepted for backward compatibility.'
				),
			],
			'required'             => [ 'blockName' ],
			'additionalProperties' => false,
		];
	}

	private static function structural_identity_schema(): array {
		return self::open_object_schema(
			[
				'role'             => [ 'type' => 'string' ],
				'job'              => [ 'type' => 'string' ],
				'location'         => [ 'type' => 'string' ],
				'templateArea'     => [ 'type' => 'string' ],
				'templatePartSlug' => [ 'type' => 'string' ],
				'position'         => self::open_object_schema(
					[
						'depth'               => [ 'type' => 'integer' ],
						'siblingIndex'        => [ 'type' => 'integer' ],
						'siblingCount'        => [ 'type' => 'integer' ],
						'sameTypeIndex'       => [ 'type' => 'integer' ],
						'sameTypeCount'       => [ 'type' => 'integer' ],
						'typeOrderInLocation' => [ 'type' => 'integer' ],
					]
				),
				'evidence'         => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
			'Resolved structural identity for the selected block.'
		);
	}

	private static function structural_summary_item_schema( bool $include_children = false ): array {
		$properties = [
			'block'            => [ 'type' => 'string' ],
			'role'             => [ 'type' => 'string' ],
			'job'              => [ 'type' => 'string' ],
			'location'         => [ 'type' => 'string' ],
			'templateArea'     => [ 'type' => 'string' ],
			'templatePartSlug' => [ 'type' => 'string' ],
		];

		if ( $include_children ) {
			$properties['isSelected'] = [ 'type' => 'boolean' ];
			$properties['children']   = [
				'type'  => 'array',
				'items' => self::open_object_schema(),
			];
		}

		return self::open_object_schema( $properties );
	}

	private static function open_object_schema( array $properties = [], string $description = '' ): array {
		$schema = [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => true,
		];

		if ( $description !== '' ) {
			$schema['description'] = $description;
		}

		return $schema;
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
				'settings'    => [
					'type'  => 'array',
					'items' => $suggestion_schema,
				],
				'styles'      => [
					'type'  => 'array',
					'items' => $suggestion_schema,
				],
				'block'       => [
					'type'  => 'array',
					'items' => $suggestion_schema,
				],
				'explanation' => [ 'type' => 'string' ],
			],
		];
	}

	private static function style_recommendation_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'suggestions' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'label'       => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
							'category'    => [ 'type' => 'string' ],
							'tone'        => [ 'type' => 'string' ],
							'operations'  => [
								'type'  => 'array',
								'items' => [
									'type'       => 'object',
									'properties' => [
										'type'           => [ 'type' => 'string' ],
										'path'           => [
											'type'  => 'array',
											'items' => [ 'type' => 'string' ],
										],
										'value'          => [ 'type' => [ 'string', 'number', 'boolean', 'object', 'array', 'null' ] ],
										'valueType'      => [ 'type' => 'string' ],
										'presetType'     => [ 'type' => 'string' ],
										'presetSlug'     => [ 'type' => 'string' ],
										'cssVar'         => [ 'type' => 'string' ],
										'variationIndex' => [ 'type' => 'integer' ],
										'variationTitle' => [ 'type' => 'string' ],
									],
								],
							],
						],
					],
				],
				'explanation' => [ 'type' => 'string' ],
			],
		];
	}
}
