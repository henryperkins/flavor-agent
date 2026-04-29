<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

final class Registration {

	private const STRUCTURAL_SUMMARY_MAX_ITEMS = 6;

	private const STRUCTURAL_SUMMARY_MAX_CHILDREN = 6;

	private const STRUCTURAL_SUMMARY_MAX_DEPTH = 2;

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
		self::register_content_abilities();
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
						'selectedBlock'        => self::selected_block_input_schema(),
						'prompt'               => [
							'type'        => 'string',
							'description' => 'Optional user instruction',
						],
						'resolveSignatureOnly' => [
							'type'        => 'boolean',
							'description' => 'When true, only resolve the server-issued apply-context signature without calling the model.',
						],
					],
					'required'   => [ 'selectedBlock' ],
				],
				'output_schema'       => self::suggestion_output_schema(),
				'meta'                => self::public_recommendation_meta(),
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
					'properties' => self::block_manifest_schema_properties(),
				],
				'meta'                => self::readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/list-allowed-blocks',
			[
				'label'               => __( 'List allowed blocks', 'flavor-agent' ),
				'description'         => __( 'Return block types registered on the current site, with optional search, pagination, and variation payload controls.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ BlockAbilities::class, 'list_allowed_blocks' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search'            => [
							'type'        => 'string',
							'description' => 'Optional case-insensitive search filter for block name or title.',
						],
						'category'          => [
							'type'        => 'string',
							'description' => 'Optional block category filter, for example design or text.',
						],
						'limit'             => [
							'type'        => 'integer',
							'description' => 'Optional maximum number of block manifests to return.',
						],
						'offset'            => [
							'type'        => 'integer',
							'description' => 'Optional offset for paginated block results.',
						],
						'includeVariations' => [
							'type'        => 'boolean',
							'description' => 'When true, include block variations in list results. Defaults to false for lighter payloads.',
						],
						'maxVariations'     => [
							'type'        => 'integer',
							'description' => 'Maximum number of variations to include per block when includeVariations is true. Defaults to 10.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'blocks' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => self::block_manifest_schema_properties(),
							],
						],
						'total'  => [ 'type' => 'integer' ],
					],
				],
				'meta'                => self::readonly_rest_meta(),
			]
		);
	}

	private static function register_content_abilities(): void {
		wp_register_ability(
			'flavor-agent/recommend-content',
			[
				'label'               => __( 'Recommend editorial content', 'flavor-agent' ),
				'description'         => __( 'Draft, edit, or critique blog posts, essays, and site copy in Henry Perkins\'s voice.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ ContentAbilities::class, 'recommend_content' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'mode'         => [
							'type'        => 'string',
							'description' => 'Writing mode: draft, edit, or critique.',
						],
						'prompt'       => [
							'type'        => 'string',
							'description' => 'Optional user instruction for the content lane.',
						],
						'voiceProfile' => [
							'type'        => 'string',
							'description' => 'Optional extra voice guidance layered on top of the default Henry profile.',
						],
						'postContext'  => self::open_object_schema(
							[
								'postType'        => [ 'type' => 'string' ],
								'title'           => [ 'type' => 'string' ],
								'excerpt'         => [ 'type' => 'string' ],
								'content'         => [ 'type' => 'string' ],
								'slug'            => [ 'type' => 'string' ],
								'status'          => [ 'type' => 'string' ],
								'audience'        => [ 'type' => 'string' ],
								'siteTitle'       => [ 'type' => 'string' ],
								'siteDescription' => [ 'type' => 'string' ],
								'categories'      => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
								'tags'            => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
							],
							'Optional post-editor context for drafting, editing, or critique.'
						),
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'mode'    => [ 'type' => 'string' ],
						'title'   => [ 'type' => 'string' ],
						'summary' => [ 'type' => 'string' ],
						'content' => [ 'type' => 'string' ],
						'notes'   => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'issues'  => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'original' => [ 'type' => 'string' ],
									'problem'  => [ 'type' => 'string' ],
									'revision' => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'meta'                => self::public_recommendation_meta(),
			]
		);
	}

	private static function register_pattern_abilities(): void {
		wp_register_ability(
			'flavor-agent/recommend-patterns',
			[
				'label'               => __( 'Recommend patterns', 'flavor-agent' ),
				'description'         => __( 'Rank registered and synced block patterns for the current editing context using LLM.', 'flavor-agent' ),
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
						'insertionContext'    => self::pattern_insertion_context_schema(),
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
									'name'                 => [ 'type' => 'string' ],
									'title'                => [ 'type' => 'string' ],
									'type'                 => [ 'type' => 'string' ],
									'source'               => [ 'type' => 'string' ],
									'syncedPatternId'      => [ 'type' => 'integer' ],
									'syncStatus'           => [ 'type' => 'string' ],
									'wpPatternSyncStatus'  => [ 'type' => 'string' ],
									'score'                => [ 'type' => 'number' ],
									'reason'               => [ 'type' => 'string' ],
									'categories'           => [ 'type' => 'array' ],
									'patternOverrides'     => [ 'type' => 'object' ],
									'overrideCapabilities' => self::pattern_override_capabilities_schema(),
									'content'              => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'meta'                => self::public_recommendation_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/list-patterns',
			[
				'label'               => __( 'List block patterns', 'flavor-agent' ),
				'description'         => __( 'Return registered block patterns, optionally filtered by category, block type, template type, search, and payload size controls.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ PatternAbilities::class, 'list_patterns' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'categories'     => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'blockTypes'     => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'templateTypes'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'search'         => [
							'type'        => 'string',
							'description' => 'Optional case-insensitive search filter for pattern name or title.',
						],
						'includeContent' => [
							'type'        => 'boolean',
							'description' => 'When true, include full pattern markup in list results. Defaults to false for lighter payloads.',
						],
						'limit'          => [
							'type'        => 'integer',
							'description' => 'Optional maximum number of patterns to return.',
						],
						'offset'         => [
							'type'        => 'integer',
							'description' => 'Optional offset for paginated pattern results.',
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
								'properties' => self::pattern_schema_properties(),
							],
						],
						'total'    => [ 'type' => 'integer' ],
					],
				],
				'meta'                => self::readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/get-pattern',
			[
				'label'               => __( 'Get block pattern', 'flavor-agent' ),
				'description'         => __( 'Return a single registered block pattern by name.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ PatternAbilities::class, 'get_pattern' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'patternId' => [
							'type'        => 'string',
							'description' => 'Registered pattern name, for example theme/hero.',
						],
					],
					'required'   => [ 'patternId' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => self::pattern_schema_properties(),
				],
				'meta'                => self::readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/list-synced-patterns',
			[
				'label'               => __( 'List synced patterns', 'flavor-agent' ),
				'description'         => __( 'Return wp_block pattern entities available on the site, optionally filtered by sync status, search, and payload size controls.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ PatternAbilities::class, 'list_synced_patterns' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'syncStatus'     => [
							'type'        => 'string',
							'description' => 'Optional sync status filter: synced, partial, unsynced, or all.',
						],
						'search'         => [
							'type'        => 'string',
							'description' => 'Optional case-insensitive search filter for synced pattern title or slug.',
						],
						'includeContent' => [
							'type'        => 'boolean',
							'description' => 'When true, include full block markup in list results. Defaults to false for lighter payloads.',
						],
						'limit'          => [
							'type'        => 'integer',
							'description' => 'Optional maximum number of synced patterns to return.',
						],
						'offset'         => [
							'type'        => 'integer',
							'description' => 'Optional offset for paginated synced-pattern results.',
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
								'properties' => self::synced_pattern_schema_properties(),
							],
						],
						'total'    => [ 'type' => 'integer' ],
					],
				],
				'meta'                => self::readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/get-synced-pattern',
			[
				'label'               => __( 'Get synced pattern', 'flavor-agent' ),
				'description'         => __( 'Return a single wp_block pattern entity by numeric post ID.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ PatternAbilities::class, 'get_synced_pattern' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'patternId' => [
							'type'        => 'integer',
							'description' => 'wp_block post ID for the synced pattern.',
						],
					],
					'required'   => [ 'patternId' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => self::synced_pattern_schema_properties(),
				],
				'meta'                => self::readonly_rest_meta(),
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
						'templateRef'          => [
							'type'        => 'string',
							'description' => 'Template identifier from the Site Editor.',
						],
						'templateType'         => [
							'type'        => 'string',
							'description' => 'Normalized template type (single, page, 404, etc.). Derived from templateRef if absent.',
						],
						'prompt'               => [ 'type' => 'string' ],
						'resolveSignatureOnly' => [
							'type'        => 'boolean',
							'description' => 'When true, only resolve the server-issued review/apply context signatures without calling the model.',
						],
						'visiblePatternNames'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'editorSlots'          => [
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
						'editorStructure'      => [
							'type'       => 'object',
							'properties' => [
								'topLevelBlockTree'       => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'path'       => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
											'name'       => [ 'type' => 'string' ],
											'label'      => [ 'type' => 'string' ],
											'attributes' => [ 'type' => 'object' ],
											'childCount' => [ 'type' => 'integer' ],
											'slot'       => [
												'type' => 'object',
												'properties' => [
													'slug' => [ 'type' => 'string' ],
													'area' => [ 'type' => 'string' ],
													'isEmpty' => [ 'type' => 'boolean' ],
												],
											],
										],
									],
								],
								'structureStats'          => [
									'type'       => 'object',
									'properties' => [
										'blockCount'       => [ 'type' => 'integer' ],
										'maxDepth'         => [ 'type' => 'integer' ],
										'topLevelBlockCount' => [ 'type' => 'integer' ],
										'hasNavigation'    => [ 'type' => 'boolean' ],
										'hasQuery'         => [ 'type' => 'boolean' ],
										'hasTemplateParts' => [ 'type' => 'boolean' ],
										'firstTopLevelBlock' => [ 'type' => 'string' ],
										'lastTopLevelBlock' => [ 'type' => 'string' ],
									],
								],
								'currentPatternOverrides' => [ 'type' => 'object' ],
								'currentViewportVisibility' => [ 'type' => 'object' ],
							],
						],
					],
					'required'   => [ 'templateRef' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'suggestions'              => [
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
												'placement' => [ 'type' => 'string' ],
												'targetPath' => [
													'type' => 'array',
													'items' => [ 'type' => 'integer' ],
												],
												'expectedTarget' => [
													'type' => 'object',
													'properties' => [
														'name'       => [ 'type' => 'string' ],
														'label'      => [ 'type' => 'string' ],
														'attributes' => [ 'type' => 'object' ],
														'childCount' => [ 'type' => 'integer' ],
														'slot'       => [
															'type'       => 'object',
															'properties' => [
																'slug'    => [ 'type' => 'string' ],
																'area'    => [ 'type' => 'string' ],
																'isEmpty' => [ 'type' => 'boolean' ],
															],
														],
													],
												],
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
						'explanation'              => [ 'type' => 'string' ],
						'reviewContextSignature'   => [ 'type' => 'string' ],
						'resolvedContextSignature' => [ 'type' => 'string' ],
					],
				],
				'meta'                => self::public_recommendation_meta(),
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
						'templatePartRef'      => [
							'type'        => 'string',
							'description' => 'Template-part identifier from the Site Editor.',
						],
						'prompt'               => [ 'type' => 'string' ],
						'resolveSignatureOnly' => [
							'type'        => 'boolean',
							'description' => 'When true, only resolve the server-issued review/apply context signatures without calling the model.',
						],
						'visiblePatternNames'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'editorStructure'      => [
							'type'       => 'object',
							'properties' => [
								'blockTree'               => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'path'       => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
											'name'       => [ 'type' => 'string' ],
											'label'      => [ 'type' => 'string' ],
											'attributes' => [ 'type' => 'object' ],
											'childCount' => [ 'type' => 'integer' ],
											'children'   => [
												'type'  => 'array',
												'items' => [ 'type' => 'object' ],
											],
										],
									],
								],
								'allBlockPaths'           => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'path'       => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
											'name'       => [ 'type' => 'string' ],
											'label'      => [ 'type' => 'string' ],
											'attributes' => [ 'type' => 'object' ],
											'childCount' => [ 'type' => 'integer' ],
										],
									],
								],
								'topLevelBlocks'          => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
								'blockCounts'             => [
									'type' => 'object',
								],
								'structureStats'          => [
									'type'       => 'object',
									'properties' => [
										'blockCount'      => [ 'type' => 'integer' ],
										'maxDepth'        => [ 'type' => 'integer' ],
										'hasNavigation'   => [ 'type' => 'boolean' ],
										'containsLogo'    => [ 'type' => 'boolean' ],
										'containsSiteTitle' => [ 'type' => 'boolean' ],
										'containsSearch'  => [ 'type' => 'boolean' ],
										'containsSocialLinks' => [ 'type' => 'boolean' ],
										'containsQuery'   => [ 'type' => 'boolean' ],
										'containsColumns' => [ 'type' => 'boolean' ],
										'containsButtons' => [ 'type' => 'boolean' ],
										'containsSpacer'  => [ 'type' => 'boolean' ],
										'containsSeparator' => [ 'type' => 'boolean' ],
										'firstTopLevelBlock' => [ 'type' => 'string' ],
										'lastTopLevelBlock' => [ 'type' => 'string' ],
										'hasSingleWrapperGroup' => [ 'type' => 'boolean' ],
										'isNearlyEmpty'   => [ 'type' => 'boolean' ],
									],
								],
								'currentPatternOverrides' => [ 'type' => 'object' ],
								'operationTargets'        => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'path'  => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
											'name'  => [ 'type' => 'string' ],
											'label' => [ 'type' => 'string' ],
											'allowedOperations' => [
												'type'  => 'array',
												'items' => [ 'type' => 'string' ],
											],
											'allowedInsertions' => [
												'type'  => 'array',
												'items' => [ 'type' => 'string' ],
											],
										],
									],
								],
								'insertionAnchors'        => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'placement'  => [ 'type' => 'string' ],
											'label'      => [ 'type' => 'string' ],
											'blockName'  => [ 'type' => 'string' ],
											'targetPath' => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
										],
									],
								],
								'structuralConstraints'   => [
									'type'       => 'object',
									'properties' => [
										'contentOnlyPaths' => [
											'type'  => 'array',
											'items' => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
										],
										'lockedPaths'      => [
											'type'  => 'array',
											'items' => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
										],
										'hasContentOnly'   => [ 'type' => 'boolean' ],
										'hasLockedBlocks'  => [ 'type' => 'boolean' ],
									],
								],
							],
						],
					],
					'required'   => [ 'templatePartRef' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'suggestions'              => [
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
												'expectedTarget' => [ 'type' => 'object' ],
											],
										],
									],
								],
							],
						],
						'explanation'              => [ 'type' => 'string' ],
						'reviewContextSignature'   => [ 'type' => 'string' ],
						'resolvedContextSignature' => [ 'type' => 'string' ],
					],
				],
				'meta'                => self::public_recommendation_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/list-template-parts',
			[
				'label'               => __( 'List template parts', 'flavor-agent' ),
				'description'         => __( 'Return registered template-part metadata for editors, with optional content only for users who can edit themes.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ TemplateAbilities::class, 'list_template_parts' ],
				'permission_callback' => [ self::class, 'can_list_template_parts' ],
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'area'           => [
							'type'        => 'string',
							'description' => 'Filter by area: header, footer, sidebar, navigation-overlay',
						],
						'includeContent' => [
							'type'        => 'boolean',
							'description' => 'When true, request template-part markup. Callers without the edit_theme_options capability receive metadata-only results.',
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
				'meta'                => self::readonly_rest_meta(),
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
						'menuId'               => [
							'type'        => 'integer',
							'description' => 'Nav menu ID',
						],
						'navigationMarkup'     => [
							'type'        => 'string',
							'description' => 'Serialized navigation block markup',
						],
						'editorContext'        => [
							'type'        => 'object',
							'description' => 'Selected navigation block context snapshot from the editor.',
						],
						'resolveSignatureOnly' => [
							'type'        => 'boolean',
							'description' => 'When true, only resolve the server-backed review freshness signature without calling the model.',
						],
						'prompt'               => [ 'type' => 'string' ],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'suggestions'            => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'label'       => [ 'type' => 'string' ],
									'description' => [ 'type' => 'string' ],
									'category'    => [ 'type' => 'string' ],
									'changes'     => [
										'type'  => 'array',
										'items' => [
											'type'       => 'object',
											'properties' => [
												'type'   => [ 'type' => 'string' ],
												'target' => [ 'type' => 'string' ],
												'detail' => [ 'type' => 'string' ],
												'targetPath' => [
													'type' => 'array',
													'items' => [ 'type' => 'integer' ],
												],
											],
										],
									],
								],
							],
						],
						'explanation'            => [ 'type' => 'string' ],
						'reviewContextSignature' => [ 'type' => 'string' ],
					],
				],
				'meta'                => self::public_recommendation_meta(),
			]
		);
	}

	private static function register_style_abilities(): void {
		wp_register_ability(
			'flavor-agent/recommend-style',
			[
				'label'               => __( 'Recommend site styles', 'flavor-agent' ),
				'description'         => __( 'Suggest theme-safe style changes and theme style variations for supported Site Editor style surfaces.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ StyleAbilities::class, 'recommend_style' ],
				'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'scope'                => self::open_object_schema(
							[
								'surface'        => [ 'type' => 'string' ],
								'scopeKey'       => [ 'type' => 'string' ],
								'globalStylesId' => [ 'type' => 'string' ],
								'postType'       => [ 'type' => 'string' ],
								'entityId'       => [ 'type' => 'string' ],
								'entityKind'     => [ 'type' => 'string' ],
								'entityName'     => [ 'type' => 'string' ],
								'stylesheet'     => [ 'type' => 'string' ],
								'blockName'      => [ 'type' => 'string' ],
								'blockTitle'     => [ 'type' => 'string' ],
							],
							'Resolved style surface scope descriptor from the Site Editor.'
						),
						'styleContext'         => self::open_object_schema(
							[
								'currentConfig'         => self::open_object_schema(),
								'mergedConfig'          => self::open_object_schema(),
								'availableVariations'   => [
									'type'  => 'array',
									'items' => self::open_object_schema(),
								],
								'templateStructure'     => self::template_structure_schema(),
								'templateVisibility'    => self::open_object_schema(),
								'themeTokenDiagnostics' => self::open_object_schema(
									[
										'source'      => [ 'type' => 'string' ],
										'settingsKey' => [ 'type' => 'string' ],
										'reason'      => [ 'type' => 'string' ],
									]
								),
								'designSemantics'       => self::open_object_schema(),
								'styleBookTarget'       => self::open_object_schema(
									[
										'blockName'     => [ 'type' => 'string' ],
										'blockTitle'    => [ 'type' => 'string' ],
										'description'   => [ 'type' => 'string' ],
										'currentStyles' => self::open_object_schema(),
										'mergedStyles'  => self::open_object_schema(),
									]
								),
							],
							'Current style surface editor context needed for style recommendations.'
						),
						'prompt'               => [
							'type'        => 'string',
							'description' => 'Optional user instruction',
						],
						'resolveSignatureOnly' => [
							'type'        => 'boolean',
							'description' => 'When true, only resolve the server-issued review/apply context signatures without calling the model.',
						],
					],
					'required'   => [ 'scope', 'styleContext' ],
				],
				'output_schema'       => self::style_recommendation_output_schema(),
				'meta'                => self::public_recommendation_meta(),
			]
		);
	}

	private static function register_wordpress_docs_abilities(): void {
		wp_register_ability(
			'flavor-agent/search-wordpress-docs',
			[
				'label'               => __( 'Search WordPress developer docs', 'flavor-agent' ),
				'description'         => __( 'Query Flavor Agent\'s trusted WordPress developer docs search backend.', 'flavor-agent' ),
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
									'id'         => [ 'type' => 'string' ],
									'title'      => [ 'type' => 'string' ],
									'sourceKey'  => [ 'type' => 'string' ],
									'sourceType' => [ 'type' => 'string' ],
									'url'        => [ 'type' => 'string' ],
									'excerpt'    => [ 'type' => 'string' ],
									'score'      => [ 'type' => 'number' ],
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
			'flavor-agent/get-active-theme',
			[
				'label'               => __( 'Get active theme', 'flavor-agent' ),
				'description'         => __( 'Return the active theme name, stylesheet, template, and version.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ InfraAbilities::class, 'get_active_theme' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => self::active_theme_schema_properties(),
				],
				'meta'                => self::readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/get-theme-presets',
			[
				'label'               => __( 'Get theme presets', 'flavor-agent' ),
				'description'         => __( 'Return the active theme design presets from global settings, including colors, typography, spacing, shadows, gradients, and duotone presets.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ InfraAbilities::class, 'get_theme_presets' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => self::theme_presets_schema_properties(),
				],
				'meta'                => self::readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/get-theme-styles',
			[
				'label'               => __( 'Get theme styles', 'flavor-agent' ),
				'description'         => __( 'Return the applied global theme styles plus extracted element and block pseudo-state styles.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ InfraAbilities::class, 'get_theme_styles' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => self::theme_styles_schema_properties(),
				],
				'meta'                => self::readonly_rest_meta(),
			]
		);

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
							'description' => 'Legacy model indicator. Returns provider-managed when recommendations are backed by the WordPress AI Client, otherwise null.',
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

	public static function can_list_template_parts( mixed $_input = null ): bool {
		return current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' );
	}

	private static function public_recommendation_meta(): array {
		return [
			'show_in_rest' => true,
			'mcp'          => [
				'public' => true,
			],
		];
	}

	private static function readonly_rest_meta(): array {
		return [
			'show_in_rest' => true,
			'readonly'     => true,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function block_manifest_schema_properties(): array {
		return [
			'name'                => [ 'type' => 'string' ],
			'title'               => [ 'type' => 'string' ],
			'category'            => [ 'type' => 'string' ],
			'description'         => [ 'type' => 'string' ],
			'supports'            => [ 'type' => 'object' ],
			'inspectorPanels'     => [ 'type' => 'object' ],
			'bindableAttributes'  => [
				'type'  => 'array',
				'items' => [ 'type' => 'string' ],
			],
			'contentAttributes'   => [ 'type' => 'object' ],
			'configAttributes'    => [ 'type' => 'object' ],
			'styles'              => [ 'type' => 'array' ],
			'variations'          => [ 'type' => 'array' ],
			'supportsContentRole' => [ 'type' => 'boolean' ],
			'parent'              => [ 'type' => [ 'array', 'null' ] ],
			'allowedBlocks'       => [ 'type' => [ 'array', 'null' ] ],
			'apiVersion'          => [ 'type' => 'integer' ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function pattern_schema_properties(): array {
		return [
			'id'               => [ 'type' => 'string' ],
			'name'             => [ 'type' => 'string' ],
			'title'            => [ 'type' => 'string' ],
			'description'      => [ 'type' => 'string' ],
			'categories'       => [ 'type' => 'array' ],
			'blockTypes'       => [ 'type' => 'array' ],
			'templateTypes'    => [ 'type' => 'array' ],
			'patternOverrides' => [ 'type' => 'object' ],
			'content'          => [ 'type' => 'string' ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function synced_pattern_schema_properties(): array {
		return [
			'id'                  => [ 'type' => 'integer' ],
			'title'               => [ 'type' => 'string' ],
			'slug'                => [ 'type' => 'string' ],
			'content'             => [ 'type' => 'string' ],
			'status'              => [ 'type' => 'string' ],
			'authorId'            => [ 'type' => 'integer' ],
			'dateGmt'             => [ 'type' => 'string' ],
			'modifiedGmt'         => [ 'type' => 'string' ],
			'syncStatus'          => [ 'type' => 'string' ],
			'wpPatternSyncStatus' => [ 'type' => 'string' ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function active_theme_schema_properties(): array {
		return [
			'name'       => [ 'type' => 'string' ],
			'version'    => [ 'type' => 'string' ],
			'stylesheet' => [ 'type' => 'string' ],
			'template'   => [ 'type' => 'string' ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function theme_presets_schema_properties(): array {
		return [
			'colorPresets'      => [ 'type' => 'array' ],
			'gradientPresets'   => [ 'type' => 'array' ],
			'fontSizePresets'   => [ 'type' => 'array' ],
			'fontFamilyPresets' => [ 'type' => 'array' ],
			'spacingPresets'    => [ 'type' => 'array' ],
			'shadowPresets'     => [ 'type' => 'array' ],
			'duotonePresets'    => [ 'type' => 'array' ],
			'diagnostics'       => [ 'type' => 'object' ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function theme_styles_schema_properties(): array {
		return [
			'styles'            => [ 'type' => 'object' ],
			'elementStyles'     => [ 'type' => 'object' ],
			'blockPseudoStyles' => [ 'type' => 'object' ],
			'diagnostics'       => [ 'type' => 'object' ],
		];
	}

	private static function selected_block_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'blockName'              => [
					'type'        => 'string',
					'description' => 'Block type name (e.g. core/group).',
				],
				'attributes'             => self::open_object_schema(
					[],
					'Current block attributes. Canonical visibility state lives in attributes.metadata.blockVisibility.'
				),
				'innerBlocks'            => [
					'type'        => 'array',
					'description' => 'Nested blocks (optional).',
					'items'       => self::open_object_schema(),
				],
				'isInsideContentOnly'    => [
					'type'        => 'boolean',
					'description' => 'Whether the block is inside a contentOnly editing container.',
				],
				'supportsContentRole'    => [
					'type'        => 'boolean',
					'description' => 'Whether the block declares supports.contentRole and is content-editable through inner blocks or content attributes.',
				],
				'editingMode'            => [
					'type'        => 'string',
					'description' => 'Current editing mode for the selected block.',
				],
				'childCount'             => [
					'type'        => 'integer',
					'description' => 'Number of direct child blocks nested inside the selected block.',
				],
				'parentContext'          => self::parent_context_schema(),
				'siblingSummariesBefore' => [
					'type'        => 'array',
					'description' => 'Sibling block summaries before the selected block.',
					'items'       => self::sibling_summary_schema(),
					'maxItems'    => 3,
					'default'     => [],
				],
				'siblingSummariesAfter'  => [
					'type'        => 'array',
					'description' => 'Sibling block summaries after the selected block.',
					'items'       => self::sibling_summary_schema(),
					'maxItems'    => 3,
					'default'     => [],
				],
				'structuralIdentity'     => self::structural_identity_schema(),
				'structuralAncestors'    => [
					'type'        => 'array',
					'description' => 'Summarized structural ancestors leading to the selected block.',
					'items'       => self::structural_summary_item_schema(),
					'maxItems'    => self::STRUCTURAL_SUMMARY_MAX_ITEMS,
				],
				'structuralBranch'       => [
					'type'        => 'array',
					'description' => 'Summarized structural branch rooted at the nearest structural ancestor.',
					'items'       => self::structural_summary_item_schema( true ),
					'maxItems'    => self::STRUCTURAL_SUMMARY_MAX_ITEMS,
				],
				'blockVisibility'        => self::open_object_schema(
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

	private static function structural_summary_item_schema( bool $include_children = false, int $depth = 0 ): array {
		$properties = [
			'block'            => [ 'type' => 'string' ],
			'title'            => [ 'type' => 'string' ],
			'role'             => [ 'type' => 'string' ],
			'job'              => [ 'type' => 'string' ],
			'location'         => [ 'type' => 'string' ],
			'templateArea'     => [ 'type' => 'string' ],
			'templatePartSlug' => [ 'type' => 'string' ],
			'childCount'       => [ 'type' => 'integer' ],
			'moreChildren'     => [ 'type' => 'integer' ],
		];

		if ( $include_children || $depth > 0 ) {
			$properties['isSelected'] = [ 'type' => 'boolean' ];
		}

		if ( $include_children ) {
			$properties['children'] = [
				'type'     => 'array',
				'items'    => self::structural_summary_item_schema(
					$depth + 1 < self::STRUCTURAL_SUMMARY_MAX_DEPTH,
					$depth + 1
				),
				'maxItems' => self::STRUCTURAL_SUMMARY_MAX_CHILDREN,
			];
		}

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		];
	}

	private static function parent_context_schema(): array {
		return [
			'type'                 => 'object',
			'description'          => 'Optional parent container summary for the selected block.',
			'properties'           => [
				'block'       => [ 'type' => 'string' ],
				'title'       => [ 'type' => 'string' ],
				'role'        => [ 'type' => 'string' ],
				'job'         => [ 'type' => 'string' ],
				'childCount'  => [ 'type' => 'integer' ],
				'visualHints' => self::visual_hints_schema( true ),
			],
			'additionalProperties' => false,
		];
	}

	private static function sibling_summary_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'block'       => [ 'type' => 'string' ],
				'role'        => [ 'type' => 'string' ],
				'visualHints' => self::visual_hints_schema(),
			],
			'additionalProperties' => false,
		];
	}

	private static function visual_hints_schema( bool $include_parent_extensions = false ): array {
		$schema = [
			'type'                 => 'object',
			'properties'           => [
				'backgroundColor' => [ 'type' => [ 'string', 'number', 'boolean' ] ],
				'textColor'       => [ 'type' => [ 'string', 'number', 'boolean' ] ],
				'gradient'        => [ 'type' => [ 'string', 'number', 'boolean' ] ],
				'align'           => [ 'type' => [ 'string', 'number', 'boolean' ] ],
				'textAlign'       => [ 'type' => [ 'string', 'number', 'boolean' ] ],
				'style'           => [
					'type'                 => 'object',
					'properties'           => [
						'color' => [
							'type'                 => 'object',
							'properties'           => [
								'background' => [ 'type' => [ 'string', 'number', 'boolean' ] ],
								'text'       => [ 'type' => [ 'string', 'number', 'boolean' ] ],
							],
							'additionalProperties' => false,
						],
					],
					'additionalProperties' => false,
				],
				'layout'          => [
					'type'                 => 'object',
					'properties'           => [
						'type'           => [ 'type' => [ 'string', 'number', 'boolean' ] ],
						'justifyContent' => [ 'type' => [ 'string', 'number', 'boolean' ] ],
					],
					'additionalProperties' => false,
				],
			],
			'additionalProperties' => false,
		];

		if ( $include_parent_extensions ) {
			$schema['properties']['dimRatio']      = [ 'type' => [ 'string', 'number', 'boolean' ] ];
			$schema['properties']['minHeight']     = [ 'type' => [ 'string', 'number', 'boolean' ] ];
			$schema['properties']['minHeightUnit'] = [ 'type' => [ 'string', 'number', 'boolean' ] ];
			$schema['properties']['tagName']       = [ 'type' => [ 'string', 'number', 'boolean' ] ];
		}

		return $schema;
	}

	private static function pattern_insertion_context_schema(): array {
		return self::open_object_schema(
			[
				'rootBlock'        => [ 'type' => 'string' ],
				'ancestors'        => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'nearbySiblings'   => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'templatePartArea' => [ 'type' => 'string' ],
				'templatePartSlug' => [ 'type' => 'string' ],
				'containerLayout'  => [ 'type' => 'string' ],
			],
			'Inserter-side structural context for pattern recommendations.'
		);
	}

	private static function pattern_override_capabilities_schema(): array {
		return self::open_object_schema(
			[
				'hasPatternOverrides'      => [ 'type' => 'boolean' ],
				'overrideBlockCount'       => [ 'type' => 'integer' ],
				'usesDefaultBinding'       => [ 'type' => 'boolean' ],
				'hasBindableOverrides'     => [ 'type' => 'boolean' ],
				'hasUnsupportedOverrides'  => [ 'type' => 'boolean' ],
				'matchesNearbyBlock'       => [ 'type' => 'boolean' ],
				'nearbyBlockOverlapAttrs'  => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'siblingOverrideCount'     => [ 'type' => 'integer' ],
				'matchesNearbyCustomBlock' => [ 'type' => 'boolean' ],
				'supportsCustomBlocks'     => [ 'type' => 'boolean' ],
			],
			'Resolved Pattern Overrides capabilities for the current recommendation context.'
		);
	}

	private static function template_structure_schema(): array {
		return [
			'type'        => 'array',
			'description' => 'Top-level template block summary with one level of child block names.',
			'items'       => self::open_object_schema(
				[
					'name'        => [ 'type' => 'string' ],
					'innerBlocks' => [
						'type'  => 'array',
						'items' => self::open_object_schema(
							[
								'name' => [ 'type' => 'string' ],
							]
						),
					],
				]
			),
		];
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
		$suggestion_schema                                   = [
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
		$block_suggestion_schema                             = $suggestion_schema;
		$block_operation_schema                              = [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'type'            => [
						'type' => [ 'string', 'null' ],
						'enum' => [ 'insert_pattern', 'replace_block_with_pattern', null ],
					],
					'patternName'     => [ 'type' => [ 'string', 'null' ] ],
					'targetClientId'  => [ 'type' => [ 'string', 'null' ] ],
					'position'        => [ 'type' => [ 'string', 'null' ] ],
					'targetSignature' => [ 'type' => [ 'string', 'null' ] ],
					'targetSurface'   => [ 'type' => [ 'string', 'null' ] ],
					'targetType'      => [ 'type' => [ 'string', 'null' ] ],
				],
			],
		];
		$block_suggestion_schema['properties']['operations'] = $block_operation_schema;
		$block_suggestion_schema['properties']['proposedOperations'] = $block_operation_schema;
		$block_suggestion_schema['properties']['rejectedOperations'] = [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'code'      => [ 'type' => 'string' ],
					'message'   => [ 'type' => 'string' ],
					'operation' => [
						'type'       => [ 'object', 'null' ],
						'properties' => $block_operation_schema['items']['properties'],
					],
				],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'settings'                 => [
					'type'  => 'array',
					'items' => $suggestion_schema,
				],
				'styles'                   => [
					'type'  => 'array',
					'items' => $suggestion_schema,
				],
				'block'                    => [
					'type'  => 'array',
					'items' => $block_suggestion_schema,
				],
				'explanation'              => [ 'type' => 'string' ],
				'executionContract'        => [ 'type' => 'object' ],
				'resolvedContextSignature' => [ 'type' => 'string' ],
			],
		];
	}

	private static function style_recommendation_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'suggestions'              => [
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
										'blockName'      => [ 'type' => 'string' ],
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
				'explanation'              => [ 'type' => 'string' ],
				'reviewContextSignature'   => [ 'type' => 'string' ],
				'resolvedContextSignature' => [ 'type' => 'string' ],
			],
		];
	}
}
