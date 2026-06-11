<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AI\FeatureBootstrap;
use FlavorAgent\AI\Abilities\PreviewRecommendBlockAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendNavigationAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendStyleAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendTemplateAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendTemplatePartAbility;
use FlavorAgent\AI\Abilities\RecommendBlockAbility;
use FlavorAgent\AI\Abilities\RecommendContentAbility;
use FlavorAgent\AI\Abilities\RecommendNavigationAbility;
use FlavorAgent\AI\Abilities\RecommendPatternsAbility;
use FlavorAgent\AI\Abilities\RecommendStyleAbility;
use FlavorAgent\AI\Abilities\RecommendTemplateAbility;
use FlavorAgent\AI\Abilities\RecommendTemplatePartAbility;
use FlavorAgent\AI\Abilities\GetActivityAbility;
use FlavorAgent\AI\Abilities\ListActivityAbility;
use FlavorAgent\AI\Abilities\RequestStyleApplyAbility;
use FlavorAgent\AI\Abilities\UndoActivityAbility;
use FlavorAgent\Context\BlockOperationValidator;

final class Registration {

	private const STRUCTURAL_SUMMARY_MAX_ITEMS = 6;

	private const STRUCTURAL_SUMMARY_MAX_CHILDREN = 6;

	private const STRUCTURAL_SUMMARY_MAX_DEPTH = 2;

	public static function register_category(): void {
		wp_register_ability_category(
			'flavor-agent',
			[
				'label'       => __( 'Flavor Agent', 'flavor-agent' ),
				'description' => __( 'Governed AI abilities for WordPress: schema-bounded recommendations, read-only context and preview helpers, and review-gated external style applies with server-side attribution and drift-safe undo. AI proposes; WordPress approves.', 'flavor-agent' ),
			]
		);
	}

	public static function register_abilities(): void {
		self::register_block_abilities();
		self::register_pattern_abilities();
		self::register_template_abilities();
		self::register_wordpress_docs_abilities();
		self::register_infra_abilities();

		if ( FeatureBootstrap::canonical_contracts_available() ) {
			self::register_preview_recommendation_abilities();
		}
	}

	public static function register_recommendation_abilities(): void {
		if ( ! FeatureBootstrap::canonical_contracts_available() ) {
			return;
		}

		foreach ( self::recommendation_ability_classes() as $ability_id => $definition ) {
			wp_register_ability(
				$ability_id,
				[
					'label'         => $definition['label'],
					'description'   => $definition['description'],
					'category'      => 'flavor-agent',
					'ability_class' => $definition['ability_class'],
				]
			);
		}
	}

	public static function register_preview_recommendation_abilities(): void {
		if ( ! FeatureBootstrap::canonical_contracts_available() ) {
			return;
		}

		foreach ( self::preview_recommendation_ability_classes() as $ability_id => $definition ) {
			wp_register_ability(
				$ability_id,
				[
					'label'         => $definition['label'],
					'description'   => $definition['description'],
					'category'      => 'flavor-agent',
					'ability_class' => $definition['ability_class'],
				]
			);
		}
	}

	/**
	 * @return array<string, array{label: string, description: string, ability_class: class-string}>
	 */
	public static function preview_recommendation_ability_classes(): array {
		return [
			'flavor-agent/preview-recommend-block'         => [
				'label'         => __( 'Preview block recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the apply-context signature for a block recommendation request without invoking the AI Connector. Read-only preflight for the Abilities Explorer and external MCP clients.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendBlockAbility::class,
			],
			'flavor-agent/preview-recommend-navigation'    => [
				'label'         => __( 'Preview navigation recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the review-context signature for a navigation recommendation request without invoking the AI Connector. Read-only preflight for the Abilities Explorer and external MCP clients.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendNavigationAbility::class,
			],
			'flavor-agent/preview-recommend-style'         => [
				'label'         => __( 'Preview style recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the review and apply context signatures for a Global Styles or Style Book recommendation request without invoking the AI Connector. Read-only preflight for the Abilities Explorer and external MCP clients.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendStyleAbility::class,
			],
			'flavor-agent/preview-recommend-template'      => [
				'label'         => __( 'Preview template recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the review and apply context signatures for a template recommendation request without invoking the AI Connector. Read-only preflight for the Abilities Explorer and external MCP clients.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendTemplateAbility::class,
			],
			'flavor-agent/preview-recommend-template-part' => [
				'label'         => __( 'Preview template-part recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the review and apply context signatures for a template-part recommendation request without invoking the AI Connector. Read-only preflight for the Abilities Explorer and external MCP clients.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendTemplatePartAbility::class,
			],
		];
	}

	public static function preview_recommendation_meta(): array {
		return [
			'show_in_rest' => true,
			'readonly'     => true,
			'mcp'          => [
				'public' => true,
				'type'   => 'tool',
			],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
		];
	}

	/**
	 * @return array<string, array{label: string, description: string, ability_class: class-string}>
	 */
	public static function recommendation_ability_classes(): array {
		return [
			'flavor-agent/recommend-block'         => [
				'label'         => __( 'Get block recommendations', 'flavor-agent' ),
				'description'   => __( 'Suggest attribute and style changes for a block using theme design tokens. Responses are bounded by the block operation schema and execution contract, classified for inline or review-gated apply, and carry freshness signatures so a stale editing context cannot apply.', 'flavor-agent' ),
				'ability_class' => RecommendBlockAbility::class,
			],
			'flavor-agent/recommend-content'       => [
				'label'         => __( 'Recommend editorial content', 'flavor-agent' ),
				'description'   => __( 'Draft, edit, or critique blog posts, essays, and site copy in Henry Perkins\'s voice. Editorial-only by contract: schema-bounded text output with no apply path, so it never mutates site content.', 'flavor-agent' ),
				'ability_class' => RecommendContentAbility::class,
			],
			'flavor-agent/recommend-patterns'      => [
				'label'         => __( 'Recommend patterns', 'flavor-agent' ),
				'description'   => __( 'Rank registered and synced block patterns for the current editing context. Read-only ranking with explained reasons: pattern insertion stays native Gutenberg behavior, and Flavor Agent owns no pattern apply or undo.', 'flavor-agent' ),
				'ability_class' => RecommendPatternsAbility::class,
			],
			'flavor-agent/recommend-navigation'    => [
				'label'         => __( 'Recommend navigation structure', 'flavor-agent' ),
				'description'   => __( 'Suggest navigation menu structure, overlay behavior, and organization. Advisory-only by contract: schema-bounded guidance with review-context signatures and no navigation apply path in this release.', 'flavor-agent' ),
				'ability_class' => RecommendNavigationAbility::class,
			],
			'flavor-agent/recommend-style'         => [
				'label'         => __( 'Recommend site styles', 'flavor-agent' ),
				'description'   => __( 'Suggest theme-safe style changes and theme style variations for supported Site Editor style surfaces. Operations are bounded to validated theme.json paths with WCAG AA contrast checks, reviewed before apply, and recorded server-side with drift-checked undo.', 'flavor-agent' ),
				'ability_class' => RecommendStyleAbility::class,
			],
			'flavor-agent/recommend-template'      => [
				'label'         => __( 'Recommend template structure', 'flavor-agent' ),
				'description'   => __( 'Suggest template-part arrangements and patterns for a template type. Operations come from a bounded template-operation vocabulary, are previewed and reviewed before deterministic apply, and recorded server-side with drift-checked undo.', 'flavor-agent' ),
				'ability_class' => RecommendTemplateAbility::class,
			],
			'flavor-agent/recommend-template-part' => [
				'label'         => __( 'Recommend template-part structure', 'flavor-agent' ),
				'description'   => __( 'Suggest focused structural improvements and patterns for a single template part. Operations come from a bounded template-part operation vocabulary, are previewed and reviewed before deterministic apply, and recorded server-side with drift-checked undo.', 'flavor-agent' ),
				'ability_class' => RecommendTemplatePartAbility::class,
			],
		];
	}

	/**
	 * External-apply abilities: the governed apply/read/undo loop for agents.
	 * Approval itself is deliberately NOT an ability — it is an admin REST action.
	 *
	 * @return array<string, array{label: string, description: string, ability_class: class-string}>
	 */
	public static function external_apply_ability_classes(): array {
		return [
			'flavor-agent/request-style-apply' => [
				'label'         => __( 'Request a governed style apply', 'flavor-agent' ),
				'description'   => __( 'Queue a reviewed Global Styles or Style Book apply from a recommend-style result. Validates operations and freshness signatures, then creates a pending approval row a site administrator decides in Settings > AI Activity. Mutates nothing until approved.', 'flavor-agent' ),
				'ability_class' => RequestStyleApplyAbility::class,
			],
			'flavor-agent/get-activity'        => [
				'label'         => __( 'Get one AI activity entry', 'flavor-agent' ),
				'description'   => __( 'Return a single Flavor Agent activity entry by id, including external-apply lifecycle status, decision provenance, and undo state. The polling read for agents awaiting an approval decision.', 'flavor-agent' ),
				'ability_class' => GetActivityAbility::class,
			],
			'flavor-agent/list-activity'       => [
				'label'         => __( 'List scoped AI activity', 'flavor-agent' ),
				'description'   => __( 'Return Flavor Agent activity entries for one scope key with optional surface and status filters. Admin-global reads stay on the REST activity route.', 'flavor-agent' ),
				'ability_class' => ListActivityAbility::class,
			],
			'flavor-agent/undo-activity'       => [
				'label'         => __( 'Undo an applied AI activity entry', 'flavor-agent' ),
				'description'   => __( 'Server-side undo of an executed Global Styles or Style Book activity row: enforces ordered undo, verifies the recorded after-state still matches the live entity, restores the before snapshot, and persists the one-way undone/failed transition.', 'flavor-agent' ),
				'ability_class' => UndoActivityAbility::class,
			],
		];
	}

	public static function register_external_apply_abilities(): void {
		if ( ! FeatureBootstrap::canonical_contracts_available() ) {
			return;
		}

		foreach ( self::external_apply_ability_classes() as $ability_id => $definition ) {
			wp_register_ability(
				$ability_id,
				[
					'label'         => $definition['label'],
					'description'   => $definition['description'],
					'category'      => 'flavor-agent',
					'ability_class' => $definition['ability_class'],
				]
			);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function external_apply_meta( string $ability_id ): array {
		$annotations = match ( $ability_id ) {
			'flavor-agent/request-style-apply' => [
				'destructive' => false,
				'idempotent'  => false,
			],
			'flavor-agent/undo-activity' => [
				'destructive' => true,
				'idempotent'  => false,
			],
			default => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
		};

		$meta = [
			'show_in_rest' => true,
			'annotations'  => $annotations,
		];

		if ( ! empty( $annotations['readonly'] ) ) {
			$meta['readonly'] = true;
		}

		return $meta;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function style_operation_schema(): array {
		return [
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
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function external_apply_input_schema( string $ability_id ): array {
		return match ( $ability_id ) {
			'flavor-agent/request-style-apply' => [
				'type'       => 'object',
				'properties' => [
					'scope'            => self::open_object_schema(
						[
							'surface'        => [ 'type' => 'string' ],
							'globalStylesId' => [ 'type' => 'string' ],
							'blockName'      => [ 'type' => 'string' ],
							'blockTitle'     => [ 'type' => 'string' ],
						],
						'Style surface scope: the same scope shape sent to recommend-style.'
					),
					'styleContext'     => self::open_object_schema(
						[
							'currentConfig'       => self::open_object_schema(),
							'mergedConfig'        => self::open_object_schema(),
							'availableVariations' => [
								'type'  => 'array',
								'items' => self::open_object_schema(),
							],
							'styleBookTarget'     => self::open_object_schema(),
						],
						'The same styleContext sent to recommend-style; used to recompute the freshness signatures.'
					),
					'prompt'           => [
						'type'        => 'string',
						'description' => 'The prompt sent to recommend-style, byte-identical, so the resolved signature recomputes.',
					],
					'operations'       => [
						'type'  => 'array',
						'items' => self::style_operation_schema(),
					],
					'signatures'       => [
						'type'       => 'object',
						'properties' => [
							'resolvedContextSignature' => [ 'type' => 'string' ],
							'reviewContextSignature'   => [ 'type' => 'string' ],
						],
						'required'   => [ 'resolvedContextSignature', 'reviewContextSignature' ],
					],
					'suggestion'       => self::open_object_schema(
						[
							'label'       => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
						],
						'Human-readable label shown to the approver.'
					),
					'requestReference' => [
						'type'        => 'string',
						'description' => 'Optional opaque agent-side reference echoed back on reads.',
					],
				],
				'required'   => [ 'scope', 'styleContext', 'operations', 'signatures' ],
			],
			'flavor-agent/get-activity' => [
				'type'       => 'object',
				'properties' => [
					'activityId' => [ 'type' => 'string' ],
				],
				'required'   => [ 'activityId' ],
			],
			'flavor-agent/list-activity' => [
				'type'       => 'object',
				'properties' => [
					'scopeKey' => [
						'type'        => 'string',
						'description' => 'Required activity scope key, e.g. global_styles:17.',
					],
					'surface'  => [ 'type' => 'string' ],
					'status'   => [
						'type' => 'string',
						'enum' => [ 'pending', 'applied', 'rejected', 'expired', 'failed', 'undone' ],
					],
					'limit'    => [ 'type' => 'integer' ],
				],
				'required'   => [ 'scopeKey' ],
			],
			'flavor-agent/undo-activity' => [
				'type'       => 'object',
				'properties' => [
					'activityId' => [ 'type' => 'string' ],
				],
				'required'   => [ 'activityId' ],
			],
			default => self::open_object_schema(),
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function external_apply_output_schema( string $ability_id ): array {
		return match ( $ability_id ) {
			'flavor-agent/request-style-apply' => [
				'type'       => 'object',
				'properties' => [
					'activityId'       => [ 'type' => 'string' ],
					'status'           => [ 'type' => 'string' ],
					'expiresAt'        => [ 'type' => 'string' ],
					'requestReference' => [ 'type' => 'string' ],
				],
			],
			'flavor-agent/get-activity' => [
				'type'       => 'object',
				'properties' => [
					'entry' => self::open_object_schema(),
				],
			],
			'flavor-agent/list-activity' => [
				'type'       => 'object',
				'properties' => [
					'entries' => [
						'type'  => 'array',
						'items' => self::open_object_schema(),
					],
				],
			],
			'flavor-agent/undo-activity' => [
				'type'       => 'object',
				'properties' => [
					'entry'  => self::open_object_schema(),
					'result' => [ 'type' => 'string' ],
					'error'  => [ 'type' => [ 'string', 'null' ] ],
				],
			],
			default => self::open_object_schema(),
		};
	}

	private static function register_block_abilities(): void {
		wp_register_ability(
			'flavor-agent/introspect-block',
			[
				'label'               => __( 'Introspect block type', 'flavor-agent' ),
				'description'         => __( 'Return a block type\'s capabilities: supports, Inspector panels, attributes, styles, and variations. Read-only.', 'flavor-agent' ),
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
				'meta'                => self::mcp_public_readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/list-allowed-blocks',
			[
				'label'               => __( 'List allowed blocks', 'flavor-agent' ),
				'description'         => __( 'Return block types registered on the current site, with optional search, pagination, and variation payload controls. Read-only.', 'flavor-agent' ),
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
					'default'    => [],
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
				'meta'                => self::mcp_public_readonly_rest_meta(),
			]
		);
	}

	private static function register_pattern_abilities(): void {
		wp_register_ability(
			'flavor-agent/list-patterns',
			[
				'label'               => __( 'List block patterns', 'flavor-agent' ),
				'description'         => __( 'Return registered block patterns, optionally filtered by category, block type, template type, search, and payload size controls. Read-only.', 'flavor-agent' ),
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
					'default'    => [],
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
				'meta'                => self::mcp_public_readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/get-pattern',
			[
				'label'               => __( 'Get block pattern', 'flavor-agent' ),
				'description'         => __( 'Return a single registered block pattern by name. Read-only.', 'flavor-agent' ),
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
				'meta'                => self::mcp_public_readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/list-synced-patterns',
			[
				'label'               => __( 'List synced patterns', 'flavor-agent' ),
				'description'         => __( 'Return wp_block pattern entities available on the site, optionally filtered by sync status, search, and payload size controls. Read-only.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ PatternAbilities::class, 'list_synced_patterns' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'syncStatus'     => [
							'type'        => 'string',
							'enum'        => [ 'synced', 'partial', 'unsynced', 'all' ],
							'default'     => 'synced',
							'description' => 'Optional sync status filter: synced, partial, unsynced, or all. Defaults to synced when omitted.',
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
					'default'    => [],
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
				'description'         => __( 'Return a single wp_block pattern entity by numeric post ID. Read-only.', 'flavor-agent' ),
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
			'flavor-agent/list-template-parts',
			[
				'label'               => __( 'List template parts', 'flavor-agent' ),
				'description'         => __( 'Return registered template-part metadata for editors, with optional content only for users who can edit themes. Read-only.', 'flavor-agent' ),
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
					'default'    => [],
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
				'meta'                => self::mcp_public_readonly_rest_meta(),
			]
		);
	}

	private static function register_wordpress_docs_abilities(): void {
		wp_register_ability(
			'flavor-agent/search-wordpress-docs',
			[
				'label'               => __( 'Search WordPress developer docs', 'flavor-agent' ),
				'description'         => __( 'Query Flavor Agent\'s trusted WordPress developer docs search backend. Read-only; results follow the plugin\'s trusted-source and currentness policy.', 'flavor-agent' ),
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
						'query'                    => [ 'type' => 'string' ],
						'guidance'                 => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'          => [ 'type' => 'string' ],
									'title'       => [ 'type' => 'string' ],
									'sourceKey'   => [ 'type' => 'string' ],
									'sourceType'  => [ 'type' => 'string' ],
									'url'         => [ 'type' => 'string' ],
									'excerpt'     => [ 'type' => 'string' ],
									'score'       => [ 'type' => 'number' ],
									'retrievedAt' => [ 'type' => 'string' ],
									'publishedAt' => [ 'type' => 'string' ],
									'contentHash' => [ 'type' => 'string' ],
									'freshness'   => [ 'type' => 'string' ],
								],
							],
						],
						'docsGrounding'            => self::docs_grounding_output_schema(),
						'docsGroundingFingerprint' => [ 'type' => 'string' ],
					],
				],
				'meta'                => self::docs_search_meta(),
			]
		);
	}

	private static function docs_search_meta(): array {
		return [
			'show_in_rest' => true,
			'mcp'          => [
				'public' => true,
				'type'   => 'tool',
			],
			'annotations'  => [
				'destructive' => false,
				'idempotent'  => false,
				'openWorld'   => true,
			],
		];
	}

	private static function docs_grounding_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'available'   => [ 'type' => 'boolean' ],
				'sourceTypes' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'count'       => [ 'type' => 'integer' ],
			],
		];
	}

	private static function with_docs_grounding_output_schema( array $schema ): array {
		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			$schema['properties'] = [];
		}

		$schema['properties']['docsGrounding']            = self::docs_grounding_output_schema();
		$schema['properties']['docsGroundingFingerprint'] = [ 'type' => 'string' ];

		return $schema;
	}

	private static function register_infra_abilities(): void {
		wp_register_ability(
			'flavor-agent/get-active-theme',
			[
				'label'               => __( 'Get active theme', 'flavor-agent' ),
				'description'         => __( 'Return the active theme name, stylesheet, template, and version. Read-only.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ InfraAbilities::class, 'get_active_theme' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'    => 'object',
					'default' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => self::active_theme_schema_properties(),
				],
				'meta'                => self::mcp_public_readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/get-theme-presets',
			[
				'label'               => __( 'Get theme presets', 'flavor-agent' ),
				'description'         => __( 'Return the active theme design presets from global settings, including colors, typography, spacing, shadows, gradients, and duotone presets. Read-only.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ InfraAbilities::class, 'get_theme_presets' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'    => 'object',
					'default' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => self::theme_presets_schema_properties(),
				],
				'meta'                => self::mcp_public_readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/get-theme-styles',
			[
				'label'               => __( 'Get theme styles', 'flavor-agent' ),
				'description'         => __( 'Return the applied global theme styles plus extracted element and block pseudo-state styles. Read-only.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ InfraAbilities::class, 'get_theme_styles' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'    => 'object',
					'default' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => self::theme_styles_schema_properties(),
				],
				'meta'                => self::mcp_public_readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/get-theme-tokens',
			[
				'label'               => __( 'Get theme design tokens', 'flavor-agent' ),
				'description'         => __( 'Return the current theme\'s color palette, font sizes, font families, spacing, shadows, and layout constraints. Read-only.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ InfraAbilities::class, 'get_theme_tokens' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'    => 'object',
					'default' => [],
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
				'meta'                => self::mcp_public_readonly_rest_meta(),
			]
		);

		wp_register_ability(
			'flavor-agent/check-status',
			[
				'label'               => __( 'Check Flavor Agent status', 'flavor-agent' ),
				'description'         => __( 'Report configured Flavor Agent backends, active models, and abilities currently available to the current user. Read-only diagnostic.', 'flavor-agent' ),
				'category'            => 'flavor-agent',
				'execute_callback'    => [ InfraAbilities::class, 'check_status' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'input_schema'        => [
					'type'    => 'object',
					'default' => [],
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
								'wordpress_ai_client'   => [
									'type'       => 'object',
									'properties' => [
										'configured' => [ 'type' => 'boolean' ],
									],
								],
								'cloudflare_workers_ai' => [
									'type'       => 'object',
									'properties' => [
										'configured'     => [ 'type' => 'boolean' ],
										'embeddingModel' => [ 'type' => [ 'string', 'null' ] ],
									],
								],
								'qdrant'                => [
									'type'       => 'object',
									'properties' => [
										'configured' => [ 'type' => 'boolean' ],
									],
								],
								'cloudflare_ai_search'  => [
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
				'meta'                => self::readonly_rest_meta(),
			]
		);
	}

	public static function can_list_template_parts( mixed $_input = null ): bool {
		return current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' );
	}

	public static function recommendation_input_schema( string $ability_id ): array {
		$document       = self::document_input_schema();
		$client_request = self::client_request_input_schema();

		return match ( $ability_id ) {
			'flavor-agent/recommend-block' => [
				'type'       => 'object',
				'properties' => [
					'editorContext'        => self::open_object_schema(
						[],
						'Block context snapshot from the editor.'
					),
					'clientId'             => [
						'type'        => 'string',
						'description' => 'Editor client ID for the selected block.',
					],
					'selectedBlock'        => self::selected_block_input_schema(),
					'prompt'               => [
						'type'        => 'string',
						'description' => 'Optional user instruction. For example: "Improve the contrast and spacing for accessibility."',
					],
					'document'             => $document,
					'clientRequest'        => $client_request,
					'resolveSignatureOnly' => [
						'type'        => 'boolean',
						'description' => 'When true, only resolve the server-issued apply-context signature without calling the model. Useful for dry-running this ability from the Ability Explorer or other tooling without invoking the AI Connector.',
					],
				],
			],
			'flavor-agent/recommend-content' => [
				'type'       => 'object',
				'properties' => [
					'mode'          => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'edit', 'critique' ],
						'default'     => 'draft',
						'description' => 'Writing mode: draft, edit, or critique. Defaults to draft when omitted.',
					],
					'prompt'        => [
						'type'        => 'string',
						'description' => 'Optional user instruction for the content lane. For example: "Draft a post about how reflective coaching shaped my leadership practice."',
					],
					'voiceProfile'  => [
						'type'        => 'string',
						'description' => 'Optional extra voice guidance layered on top of the default Henry profile. For example: "Calm, measured, slightly self-deprecating."',
					],
					'postContext'   => self::open_object_schema(
						[
							'postId'          => [ 'type' => 'integer' ],
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
					'document'      => $document,
					'clientRequest' => $client_request,
				],
			],
			'flavor-agent/recommend-patterns' => [
				'type'                 => 'object',
				'properties'           => [
					'postType'             => [
						'type'        => 'string',
						'description' => 'Current post type. For example: "post".',
					],
					'blockContext'         => self::open_object_schema(),
					'insertionContext'     => self::pattern_insertion_context_schema(),
					'templateType'         => [
						'type'        => 'string',
						'description' => 'Template type slug. For example: "home".',
					],
					'prompt'               => [
						'type'        => 'string',
						'description' => 'Optional user instruction. For example: "Suggest a hero pattern for the homepage that introduces the team."',
					],
					'visiblePatternNames'  => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'document'             => $document,
					'clientRequest'        => $client_request,
					'resolveSignatureOnly' => [
						'type'        => 'boolean',
						'description' => 'When true, only resolve the server-issued apply-context signature and review freshness signature without calling retrieval or the model. Useful for revalidating a pattern recommendation before inserting it.',
					],
					'requestPurpose'       => [
						'type'        => 'string',
						'description' => 'Optional client request purpose. The editor sends "inserter_ranking" only for real inserter-triggered pattern ranking requests.',
					],
				],
				'additionalProperties' => true,
				'required'             => [ 'postType' ],
			],
			'flavor-agent/recommend-navigation' => [
				'type'       => 'object',
				'properties' => [
					'menuId'               => [ 'type' => 'integer' ],
					'navigationMarkup'     => [ 'type' => 'string' ],
					'editorContext'        => self::open_object_schema(
						[],
						'Selected navigation block context snapshot from the editor.'
					),
					'blockClientId'        => [ 'type' => 'string' ],
					'prompt'               => [
						'type'        => 'string',
						'description' => 'Optional user instruction. For example: "Simplify the menu and add an overlay on mobile."',
					],
					'document'             => $document,
					'clientRequest'        => $client_request,
					'resolveSignatureOnly' => [
						'type'        => 'boolean',
						'description' => 'When true, only resolve the server-backed review freshness signature without calling the model. Useful for dry-running this ability from the Ability Explorer or other tooling without invoking the AI Connector.',
					],
				],
			],
			'flavor-agent/recommend-style' => [
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
							'themeTokenDiagnostics' => self::open_object_schema(),
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
						'description' => 'Optional user instruction. For example: "Modernize the typography hierarchy and tighten the headings."',
					],
					'document'             => $document,
					'clientRequest'        => $client_request,
					'resolveSignatureOnly' => [
						'type'        => 'boolean',
						'description' => 'When true, only resolve the server-issued review/apply context signatures without calling the model. Useful for dry-running this ability from the Ability Explorer or other tooling without invoking the AI Connector.',
					],
				],
				'required'   => [ 'scope', 'styleContext' ],
			],
			'flavor-agent/recommend-template' => [
				'type'       => 'object',
				'properties' => [
					'templateRef'          => [
						'type'        => 'string',
						'description' => 'Template identifier from the Site Editor.',
					],
					'templateType'         => [
						'type'        => 'string',
						'description' => 'Template type slug. For example: "home".',
					],
					'prompt'               => [
						'type'        => 'string',
						'description' => 'Optional user instruction. For example: "Add a sidebar with recent posts and tighten the hero spacing."',
					],
					'visiblePatternNames'  => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'designSemantics'      => self::open_object_schema(),
					'editorSlots'          => self::open_object_schema(
						[
							'assignedParts' => [
								'type'  => 'array',
								'items' => self::open_object_schema(
									[
										'slug' => [ 'type' => 'string' ],
										'area' => [ 'type' => 'string' ],
									]
								),
							],
							'emptyAreas'    => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
							'allowedAreas'  => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
						]
					),
					'editorStructure'      => self::open_object_schema(
						[
							'blockTree'         => [
								'type'  => 'array',
								'items' => self::open_object_schema(
									[
										'path'       => [
											'type'  => 'array',
											'items' => [ 'type' => 'integer' ],
										],
										'name'       => [ 'type' => 'string' ],
										'label'      => [ 'type' => 'string' ],
										'attributes' => [ 'type' => 'object' ],
										'childCount' => [ 'type' => 'integer' ],
									]
								),
							],
							'topLevelBlockTree' => [
								'type'  => 'array',
								'items' => self::open_object_schema(
									[
										'path'       => [
											'type'  => 'array',
											'items' => [ 'type' => 'integer' ],
										],
										'name'       => [ 'type' => 'string' ],
										'label'      => [ 'type' => 'string' ],
										'attributes' => [ 'type' => 'object' ],
										'childCount' => [ 'type' => 'integer' ],
									]
								),
							],
							'allBlockPaths'     => [
								'type'  => 'array',
								'items' => self::open_object_schema(
									[
										'path'       => [
											'type'  => 'array',
											'items' => [ 'type' => 'integer' ],
										],
										'name'       => [ 'type' => 'string' ],
										'label'      => [ 'type' => 'string' ],
										'attributes' => [ 'type' => 'object' ],
										'childCount' => [ 'type' => 'integer' ],
									]
								),
							],
							'topLevelBlocks'    => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
							'structureStats'    => self::open_object_schema(
								[
									'blockCount'         => [ 'type' => 'integer' ],
									'maxDepth'           => [ 'type' => 'integer' ],
									'topLevelBlockCount' => [ 'type' => 'integer' ],
									'hasNavigation'      => [ 'type' => 'boolean' ],
								]
							),
							'operationTargets'  => [
								'type'  => 'array',
								'items' => self::open_object_schema(),
							],
							'insertionAnchors'  => [
								'type'  => 'array',
								'items' => self::open_object_schema(),
							],
						]
					),
					'document'             => $document,
					'clientRequest'        => $client_request,
					'resolveSignatureOnly' => [
						'type'        => 'boolean',
						'description' => 'When true, only resolve the server-issued review/apply context signatures without calling the model. Useful for dry-running this ability from the Ability Explorer or other tooling without invoking the AI Connector.',
					],
				],
				'required'   => [ 'templateRef' ],
			],
			'flavor-agent/recommend-template-part' => [
				'type'       => 'object',
				'properties' => [
					'templatePartRef'      => [
						'type'        => 'string',
						'description' => 'Template-part identifier from the Site Editor.',
					],
					'prompt'               => [
						'type'        => 'string',
						'description' => 'Optional user instruction. For example: "Tighten the header layout and add a sticky behavior on scroll."',
					],
					'visiblePatternNames'  => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'designSemantics'      => self::open_object_schema(),
					'editorStructure'      => self::template_part_editor_structure_schema(),
					'document'             => $document,
					'clientRequest'        => $client_request,
					'resolveSignatureOnly' => [
						'type'        => 'boolean',
						'description' => 'When true, only resolve the server-issued review/apply context signatures without calling the model. Useful for dry-running this ability from the Ability Explorer or other tooling without invoking the AI Connector.',
					],
				],
				'required'   => [ 'templatePartRef' ],
			],
			default => self::open_object_schema(),
		};
	}

	public static function recommendation_output_schema( string $ability_id ): array {
		return match ( $ability_id ) {
			'flavor-agent/recommend-block' => self::suggestion_output_schema(),
			'flavor-agent/recommend-content' => self::content_recommendation_output_schema(),
			'flavor-agent/recommend-style' => self::style_recommendation_output_schema(),
			'flavor-agent/recommend-patterns' => self::patterns_recommendation_output_schema(),
			'flavor-agent/recommend-navigation' => self::navigation_recommendation_output_schema(),
			'flavor-agent/recommend-template' => self::template_recommendation_output_schema(),
			'flavor-agent/recommend-template-part' => self::template_part_recommendation_output_schema(),
			default => self::open_object_schema(
				[
					'recommendations'          => [
						'type'  => 'array',
						'items' => self::open_object_schema(),
					],
					'suggestions'              => [
						'type'  => 'array',
						'items' => self::open_object_schema(),
					],
					'explanation'              => [ 'type' => 'string' ],
					'summary'                  => [ 'type' => 'string' ],
					'content'                  => [ 'type' => 'string' ],
					'diagnostics'              => self::open_object_schema(),
					'reviewContextSignature'   => [ 'type' => 'string' ],
					'resolvedContextSignature' => [ 'type' => 'string' ],
					'requestMeta'              => self::open_object_schema(),
				]
			),
		};
	}

	private static function content_recommendation_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'mode'        => [ 'type' => 'string' ],
				'title'       => [ 'type' => 'string' ],
				'summary'     => [ 'type' => 'string' ],
				'content'     => [ 'type' => 'string' ],
				'notes'       => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'issues'      => [
					'type'  => 'array',
					'items' => self::open_object_schema(
						[
							'original' => [ 'type' => 'string' ],
							'problem'  => [ 'type' => 'string' ],
							'revision' => [ 'type' => 'string' ],
						]
					),
				],
				'requestMeta' => self::open_object_schema(),
			],
		];
	}

	private static function patterns_recommendation_output_schema(): array {
		return self::with_docs_grounding_output_schema(
			[
				'type'       => 'object',
				'properties' => [
					'recommendations'          => [
						'type'  => 'array',
						'items' => self::open_object_schema(
							[
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
								'ranking'              => self::ranking_contract_schema(),
								'content'              => [ 'type' => 'string' ],
							]
						),
					],
					'diagnostics'              => self::open_object_schema(
						[
							'filteredCandidates' => self::open_object_schema(
								[
									'unreadableSyncedPatterns' => [ 'type' => 'integer' ],
								]
							),
							'pipelineTrace'      => self::open_object_schema(
								[
									'backendRetrieved'    => [ 'type' => 'integer' ],
									'visibleScopeDropped' => [ 'type' => 'integer' ],
									'rehydrationDropped'  => [ 'type' => 'integer' ],
									'candidatePool'       => [ 'type' => 'integer' ],
									'diversityDropped'    => [ 'type' => 'integer' ],
									'llmReturned'         => [ 'type' => 'integer' ],
									'llmNameMismatchDropped' => [ 'type' => 'integer' ],
									'llmMalformedDropped' => [ 'type' => 'integer' ],
									'belowThresholdDropped' => [ 'type' => 'integer' ],
									'duplicateRowsCollapsed' => [ 'type' => 'integer' ],
									'returnedRecommendations' => [ 'type' => 'integer' ],
								]
							),
							'dropReasons'        => self::open_object_schema(),
							'modelRequest'       => self::open_object_schema(
								[
									'attempted' => [ 'type' => 'boolean' ],
									'reason'    => [ 'type' => 'string' ],
								]
							),
						]
					),
					'reviewContextSignature'   => [ 'type' => 'string' ],
					'resolvedContextSignature' => [ 'type' => 'string' ],
					'patternRuntimeSignature'  => [ 'type' => 'string' ],
					'requestMeta'              => self::open_object_schema(),
				],
			]
		);
	}

	private static function navigation_recommendation_output_schema(): array {
		return self::with_docs_grounding_output_schema(
			[
				'type'       => 'object',
				'properties' => [
					'suggestions'            => [
						'type'  => 'array',
						'items' => self::open_object_schema(
							[
								'label'       => [ 'type' => 'string' ],
								'description' => [ 'type' => 'string' ],
								'category'    => [ 'type' => 'string' ],
								'changes'     => [
									'type'  => 'array',
									'items' => self::open_object_schema(
										[
											'type'       => [ 'type' => 'string' ],
											'target'     => [ 'type' => 'string' ],
											'detail'     => [ 'type' => 'string' ],
											'targetPath' => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
										]
									),
								],
								'ranking'     => self::ranking_contract_schema(),
							]
						),
					],
					'explanation'            => [ 'type' => 'string' ],
					'reviewContextSignature' => [ 'type' => 'string' ],
					'requestMeta'            => self::open_object_schema(),
				],
			]
		);
	}

	private static function template_recommendation_output_schema(): array {
		$expected_target = self::open_object_schema(
			[
				'name'       => [ 'type' => 'string' ],
				'label'      => [ 'type' => 'string' ],
				'attributes' => [ 'type' => 'object' ],
				'childCount' => [ 'type' => 'integer' ],
				'slot'       => self::open_object_schema(
					[
						'slug'    => [ 'type' => 'string' ],
						'area'    => [ 'type' => 'string' ],
						'isEmpty' => [ 'type' => 'boolean' ],
					]
				),
			]
		);

		return self::with_docs_grounding_output_schema(
			[
				'type'       => 'object',
				'properties' => [
					'suggestions'              => [
						'type'  => 'array',
						'items' => self::open_object_schema(
							[
								'label'              => [ 'type' => 'string' ],
								'description'        => [ 'type' => 'string' ],
								'operations'         => [
									'type'  => 'array',
									'items' => self::open_object_schema(
										[
											'type'        => [ 'type' => 'string' ],
											'slug'        => [ 'type' => 'string' ],
											'area'        => [ 'type' => 'string' ],
											'currentSlug' => [ 'type' => 'string' ],
											'patternName' => [ 'type' => 'string' ],
											'placement'   => [ 'type' => 'string' ],
											'targetPath'  => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
											'expectedTarget' => $expected_target,
										]
									),
								],
								'templateParts'      => [
									'type'  => 'array',
									'items' => self::open_object_schema(
										[
											'slug'   => [ 'type' => 'string' ],
											'area'   => [ 'type' => 'string' ],
											'reason' => [ 'type' => 'string' ],
										]
									),
								],
								'patternSuggestions' => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
								'ranking'            => self::ranking_contract_schema(),
								'validationReasons'  => self::validation_reasons_schema(),
							]
						),
					],
					'explanation'              => [ 'type' => 'string' ],
					'reviewContextSignature'   => [ 'type' => 'string' ],
					'resolvedContextSignature' => [ 'type' => 'string' ],
					'requestMeta'              => self::open_object_schema(),
				],
			]
		);
	}

	private static function template_part_recommendation_output_schema(): array {
		return self::with_docs_grounding_output_schema(
			[
				'type'       => 'object',
				'properties' => [
					'suggestions'              => [
						'type'  => 'array',
						'items' => self::open_object_schema(
							[
								'label'              => [ 'type' => 'string' ],
								'description'        => [ 'type' => 'string' ],
								'blockHints'         => [
									'type'  => 'array',
									'items' => self::open_object_schema(
										[
											'path'      => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
											'label'     => [ 'type' => 'string' ],
											'blockName' => [ 'type' => 'string' ],
											'reason'    => [ 'type' => 'string' ],
										]
									),
								],
								'patternSuggestions' => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
								'operations'         => [
									'type'  => 'array',
									'items' => self::open_object_schema(
										[
											'type'        => [ 'type' => 'string' ],
											'patternName' => [ 'type' => 'string' ],
											'placement'   => [ 'type' => 'string' ],
											'targetPath'  => [
												'type'  => 'array',
												'items' => [ 'type' => 'integer' ],
											],
											'expectedBlockName' => [ 'type' => 'string' ],
											'expectedTarget' => [ 'type' => 'object' ],
										]
									),
								],
								'ranking'            => self::ranking_contract_schema(),
								'validationReasons'  => self::validation_reasons_schema(),
							]
						),
					],
					'explanation'              => [ 'type' => 'string' ],
					'reviewContextSignature'   => [ 'type' => 'string' ],
					'resolvedContextSignature' => [ 'type' => 'string' ],
					'requestMeta'              => self::open_object_schema(),
				],
			]
		);
	}

	private static function template_part_editor_structure_schema(): array {
		return self::open_object_schema(
			[
				'blockTree'             => [
					'type'  => 'array',
					'items' => self::open_object_schema(
						[
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
						]
					),
				],
				'allBlockPaths'         => [
					'type'  => 'array',
					'items' => self::open_object_schema(
						[
							'path'       => [
								'type'  => 'array',
								'items' => [ 'type' => 'integer' ],
							],
							'name'       => [ 'type' => 'string' ],
							'label'      => [ 'type' => 'string' ],
							'attributes' => [ 'type' => 'object' ],
							'childCount' => [ 'type' => 'integer' ],
						]
					),
				],
				'topLevelBlocks'        => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'blockCounts'           => [ 'type' => 'object' ],
				'structureStats'        => self::open_object_schema(
					[
						'blockCount'    => [ 'type' => 'integer' ],
						'maxDepth'      => [ 'type' => 'integer' ],
						'hasNavigation' => [ 'type' => 'boolean' ],
					]
				),
				'operationTargets'      => [
					'type'  => 'array',
					'items' => self::open_object_schema(
						[
							'path'              => [
								'type'  => 'array',
								'items' => [ 'type' => 'integer' ],
							],
							'name'              => [ 'type' => 'string' ],
							'label'             => [ 'type' => 'string' ],
							'allowedOperations' => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
						]
					),
				],
				'insertionAnchors'      => [
					'type'  => 'array',
					'items' => self::open_object_schema(
						[
							'placement'  => [ 'type' => 'string' ],
							'label'      => [ 'type' => 'string' ],
							'blockName'  => [ 'type' => 'string' ],
							'targetPath' => [
								'type'  => 'array',
								'items' => [ 'type' => 'integer' ],
							],
						]
					),
				],
				'structuralConstraints' => self::open_object_schema(
					[
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
					]
				),
			]
		);
	}

	public static function recommendation_meta(): array {
		return self::public_recommendation_meta();
	}

	private static function public_recommendation_meta(): array {
		return [
			'show_in_rest' => true,
			'mcp'          => [
				'public' => true,
				'type'   => 'tool',
			],
			'annotations'  => [
				'destructive' => false,
				'idempotent'  => false,
				'openWorld'   => true,
			],
		];
	}

	private static function document_input_schema(): array {
		return self::open_object_schema(
			[
				'scopeKey'   => [
					'type'        => 'string',
					'description' => 'Stable editor activity scope key.',
				],
				'postType'   => [ 'type' => 'string' ],
				'entityId'   => [ 'type' => 'string' ],
				'entityKind' => [ 'type' => 'string' ],
				'entityName' => [ 'type' => 'string' ],
				'stylesheet' => [ 'type' => 'string' ],
			],
			'Optional document scope metadata used for request diagnostic persistence.'
		);
	}

	private static function client_request_input_schema(): array {
		return self::open_object_schema(
			[
				'sessionId'    => [ 'type' => 'string' ],
				'requestToken' => [ 'type' => 'integer' ],
				'abortId'      => [ 'type' => 'string' ],
				'aborted'      => [ 'type' => 'boolean' ],
				'scopeKey'     => [ 'type' => 'string' ],
			],
			'Optional per-page request identity used to ignore stale or aborted diagnostic persistence.'
		);
	}

	private static function readonly_rest_meta(): array {
		return [
			'show_in_rest' => true,
			'readonly'     => true,
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
		];
	}

	private static function mcp_public_readonly_rest_meta(): array {
		$meta        = self::readonly_rest_meta();
		$meta['mcp'] = [
			'public' => true,
			'type'   => 'tool',
		];

		return $meta;
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
				'name'                   => [
					'type'        => 'string',
					'description' => 'Deprecated alias for blockName accepted from Gutenberg-shaped selected block payloads.',
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
			'anyOf'                => [
				[ 'required' => [ 'blockName' ] ],
				[ 'required' => [ 'name' ] ],
			],
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

	private static function ranking_contract_schema(): array {
		return self::open_object_schema(
			[
				'score'              => [
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
				],
				'reason'             => [ 'type' => 'string' ],
				'sourceSignals'      => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'safetyMode'         => [ 'type' => 'string' ],
				'freshnessMeta'      => self::open_object_schema(),
				'designPrinciple'    => [ 'type' => 'string' ],
				'risk'               => [ 'type' => 'string' ],
				'operations'         => [
					'type'  => 'array',
					'items' => self::open_object_schema(),
				],
				'rankingHint'        => self::open_object_schema(),
				'advisoryType'       => [ 'type' => 'string' ],
				'modelScore'         => [
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
				],
				'deterministicScore' => [
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
				],
				'contextScore'       => [
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
				],
				'blendedScore'       => [
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
				],
				'contextEvidence'    => self::open_object_schema(),
				'contextPenalties'   => self::open_object_schema(),
				'rankingVersion'     => [ 'type' => 'string' ],
			],
			'Normalized ranking contract for recommendation items: score (0–1), reason, sourceSignals, safetyMode, and freshnessMeta are always emitted; operations, rankingHint, and advisoryType are surface-specific and omitted when empty.'
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
			'additionalProperties' => true,
		];

		if ( ! empty( $properties ) ) {
			$schema['properties'] = $properties;
		}

		if ( $description !== '' ) {
			$schema['description'] = $description;
		}

		return $schema;
	}

	/**
	 * Output-schema fragment for the per-suggestion `validationReasons` array.
	 *
	 * `code` is a BOUNDED STRING (maxLength + pattern), NOT an enum (design
	 * decision OD-1): the versioned reason vocabulary grows over releases, and
	 * the Gutenberg ajv-draft-04 strict client validator would reject the WHOLE
	 * recommendation payload on a single out-of-enum value. `severity` is a
	 * fixed 3-value set and is safely enumerated.
	 *
	 * @return array<string, mixed>
	 */
	private static function validation_reasons_schema(): array {
		return [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'code'     => [
						'type'      => 'string',
						'maxLength' => 64,
						'pattern'   => '^[a-z0-9_-]+$',
					],
					'severity' => [
						'type' => 'string',
						'enum' => [ 'rejected', 'downgraded', 'no_op' ],
					],
					'message'  => [ 'type' => 'string' ],
				],
			],
		];
	}

	private static function suggestion_output_schema(): array {
		$suggestion_schema       = [
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
				'groupId'          => [ 'type' => [ 'string', 'null' ] ],
				'ranking'          => self::ranking_contract_schema(),
			],
		];
		$block_suggestion_schema = $suggestion_schema;
		$block_operation_schema  = [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'catalogVersion'  => [ 'type' => [ 'integer', 'null' ] ],
					'type'            => [
						'type' => [ 'string', 'null' ],
						'enum' => [
							BlockOperationValidator::INSERT_PATTERN,
							BlockOperationValidator::REPLACE_BLOCK_WITH_PATTERN,
							null,
						],
					],
					'patternName'     => [ 'type' => [ 'string', 'null' ] ],
					'targetClientId'  => [ 'type' => [ 'string', 'null' ] ],
					'position'        => [ 'type' => [ 'string', 'null' ] ],
					'action'          => [ 'type' => [ 'string', 'null' ] ],
					'targetSignature' => [ 'type' => [ 'string', 'null' ] ],
					'targetSurface'   => [ 'type' => [ 'string', 'null' ] ],
					'targetType'      => [ 'type' => [ 'string', 'null' ] ],
					'expectedTarget'  => [
						'type'       => [ 'object', 'null' ],
						'properties' => [
							'clientId'   => [ 'type' => [ 'string', 'null' ] ],
							'name'       => [ 'type' => [ 'string', 'null' ] ],
							'label'      => [ 'type' => [ 'string', 'null' ] ],
							'attributes' => [ 'type' => 'object' ],
							'childCount' => [ 'type' => [ 'integer', 'null' ] ],
						],
					],
				],
			],
		];
		$block_rejection_codes   = [
			BlockOperationValidator::ERROR_STRUCTURAL_ACTIONS_DISABLED,
			BlockOperationValidator::ERROR_MULTI_OPERATION_UNSUPPORTED,
			BlockOperationValidator::ERROR_INVALID_OPERATION_PAYLOAD,
			BlockOperationValidator::ERROR_UNKNOWN_OPERATION_TYPE,
			BlockOperationValidator::ERROR_MISSING_PATTERN_NAME,
			BlockOperationValidator::ERROR_PATTERN_NOT_AVAILABLE,
			BlockOperationValidator::ERROR_MISSING_TARGET_CLIENT_ID,
			BlockOperationValidator::ERROR_STALE_TARGET,
			BlockOperationValidator::ERROR_CROSS_SURFACE_TARGET,
			BlockOperationValidator::ERROR_INVALID_TARGET_TYPE,
			BlockOperationValidator::ERROR_LOCKED_TARGET,
			BlockOperationValidator::ERROR_CONTENT_ONLY_TARGET,
			BlockOperationValidator::ERROR_INVALID_INSERTION_POSITION,
			BlockOperationValidator::ERROR_ACTION_NOT_ALLOWED,
			BlockOperationValidator::ERROR_CLIENT_SERVER_OPERATION_MISMATCH,
		];

		$block_suggestion_schema['properties']['operations']         = $block_operation_schema;
		$block_suggestion_schema['properties']['proposedOperations'] = $block_operation_schema;
		$block_suggestion_schema['properties']['rejectedOperations'] = [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'code'      => [
						'type' => 'string',
						'enum' => $block_rejection_codes,
					],
					'message'   => [ 'type' => 'string' ],
					'operation' => [
						'type'       => [ 'object', 'null' ],
						'properties' => $block_operation_schema['items']['properties'],
					],
				],
			],
		];

		$block_suggestion_schema['properties']['validationReasons'] = self::validation_reasons_schema();

		return self::with_docs_grounding_output_schema(
			[
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
					'recommendedSets'          => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'     => [ 'type' => 'string' ],
								'label'  => [ 'type' => 'string' ],
								'reason' => [ 'type' => 'string' ],
							],
						],
					],
					'explanation'              => [ 'type' => 'string' ],
					'preFilteringCounts'       => [
						'type'       => 'object',
						'properties' => [
							'settings' => [ 'type' => 'integer' ],
							'styles'   => [ 'type' => 'integer' ],
							'block'    => [ 'type' => 'integer' ],
						],
					],
					'executionContract'        => [ 'type' => 'object' ],
					'resolvedContextSignature' => [ 'type' => 'string' ],
				],
			]
		);
	}

	private static function style_recommendation_output_schema(): array {
		return self::with_docs_grounding_output_schema(
			[
				'type'       => 'object',
				'properties' => [
					'suggestions'              => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'label'             => [ 'type' => 'string' ],
								'description'       => [ 'type' => 'string' ],
								'category'          => [ 'type' => 'string' ],
								'tone'              => [ 'type' => 'string' ],
								'operations'        => [
									'type'  => 'array',
									'items' => self::style_operation_schema(),
								],
								'ranking'           => self::ranking_contract_schema(),
								'validationReasons' => self::validation_reasons_schema(),
							],
						],
					],
					'explanation'              => [ 'type' => 'string' ],
					'reviewContextSignature'   => [ 'type' => 'string' ],
					'resolvedContextSignature' => [ 'type' => 'string' ],
				],
			]
		);
	}
}
