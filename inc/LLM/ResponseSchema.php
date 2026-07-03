<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class ResponseSchema {

	private const BLOCK_PANELS = [
		'general',
		'layout',
		'position',
		'advanced',
		'bindings',
		'list',
		'color',
		'filter',
		'typography',
		'dimensions',
		'border',
		'shadow',
		'background',
	];

	public static function get( string $surface ): ?array {
		return match ( $surface ) {
			'block' => self::block_schema(),
			'template' => self::template_schema(),
			'template_part' => self::template_part_schema(),
			// Post-blocks shares the template-part suggestion shape: the same
			// three structural operation types over a path-addressed tree.
			'post_blocks' => self::template_part_schema(),
			'style' => self::style_schema(),
			'navigation' => self::navigation_schema(),
			'content' => self::content_schema(),
			'pattern' => self::pattern_schema(),
			default => null,
		};
	}

	private static function block_schema(): array {
		return self::strict_object(
			[
				'settings'        => [
					'type'  => 'array',
					'items' => self::block_setting_style_item_schema(),
				],
				'styles'          => [
					'type'  => 'array',
					'items' => self::block_setting_style_item_schema(),
				],
				'block'           => [
					'type'  => 'array',
					'items' => self::block_block_item_schema(),
				],
				'recommendedSets' => [
					'type'  => 'array',
					'items' => self::strict_object(
						[
							'id'     => [ 'type' => 'string' ],
							'label'  => [ 'type' => 'string' ],
							'reason' => [ 'type' => 'string' ],
						]
					),
				],
				'explanation'     => [ 'type' => 'string' ],
			]
		);
	}

	private static function template_schema(): array {
		return self::strict_object(
			[
				'suggestions' => [
					'type'  => 'array',
					'items' => self::strict_object(
						[
							'label'              => [ 'type' => 'string' ],
							'description'        => [ 'type' => 'string' ],
							'operations'         => [
								'type'  => 'array',
								'items' => self::strict_object(
									[
										'type'        => [
											'type' => 'string',
											'enum' => [ 'assign_template_part', 'replace_template_part', 'insert_pattern' ],
										],
										'slug'        => self::nullable_string(),
										'area'        => self::nullable_string(),
										'currentSlug' => self::nullable_string(),
										'patternName' => self::nullable_string(),
										'placement'   => self::nullable_string(),
										'targetPath'  => self::nullable_integer_array(),
									]
								),
							],
							'templateParts'      => [
								'type'  => 'array',
								'items' => self::strict_object(
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
							'confidence'         => self::nullable_confidence(),
							'ranking'            => self::nullable_ranking_schema(),
						]
					),
				],
				'explanation' => [ 'type' => 'string' ],
			]
		);
	}

	private static function template_part_schema(): array {
		return self::strict_object(
			[
				'suggestions' => [
					'type'  => 'array',
					'items' => self::strict_object(
						[
							'label'              => [ 'type' => 'string' ],
							'description'        => [ 'type' => 'string' ],
							'blockHints'         => [
								'type'  => 'array',
								'items' => self::strict_object(
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
								'items' => self::strict_object(
									[
										'type'        => [
											'type' => 'string',
											'enum' => [ 'insert_pattern', 'replace_block_with_pattern', 'remove_block' ],
										],
										'patternName' => self::nullable_string(),
										'placement'   => self::nullable_string(),
										'targetPath'  => self::nullable_integer_array(),
										'expectedBlockName' => self::nullable_string(),
									]
								),
							],
							'confidence'         => self::nullable_confidence(),
							'ranking'            => self::nullable_ranking_schema(),
						]
					),
				],
				'explanation' => [ 'type' => 'string' ],
			]
		);
	}

	private static function style_schema(): array {
		return self::strict_object(
			[
				'suggestions' => [
					'type'  => 'array',
					'items' => self::strict_object(
						[
							'label'       => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
							'category'    => [
								'type' => 'string',
								'enum' => [ 'variation', 'color', 'typography', 'spacing', 'border', 'shadow', 'advisory' ],
							],
							'tone'        => [
								'type' => 'string',
								'enum' => [ 'executable', 'advisory' ],
							],
							'operations'  => [
								'type'  => 'array',
								'items' => self::strict_object(
									[
										'type'           => [
											'type' => 'string',
											'enum' => [ 'set_styles', 'set_block_styles', 'set_theme_variation' ],
										],
										'blockName'      => self::nullable_string(),
										'path'           => [
											'type'  => [ 'array', 'null' ],
											'items' => [ 'type' => 'string' ],
										],
										'value'          => self::any_value(),
										'valueType'      => self::nullable_string(),
										'presetType'     => self::nullable_string(),
										'presetSlug'     => self::nullable_string(),
										'cssVar'         => self::nullable_string(),
										'variationIndex' => self::nullable_integer(),
										'variationTitle' => self::nullable_string(),
									]
								),
							],
							'confidence'  => self::nullable_confidence(),
							'ranking'     => self::nullable_ranking_schema(),
						]
					),
				],
				'explanation' => [ 'type' => 'string' ],
			]
		);
	}

	private static function navigation_schema(): array {
		return self::strict_object(
			[
				'suggestions' => [
					'type'  => 'array',
					'items' => self::strict_object(
						[
							'label'       => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
							'category'    => [
								'type' => 'string',
								'enum' => [ 'structure', 'overlay', 'accessibility' ],
							],
							'changes'     => [
								'type'  => 'array',
								'items' => self::strict_object(
									[
										'type'       => [
											'type' => 'string',
											'enum' => [ 'reorder', 'group', 'ungroup', 'add-submenu', 'flatten', 'set-attribute' ],
										],
										'targetPath' => self::nullable_integer_array(),
										'target'     => [ 'type' => 'string' ],
										'detail'     => [ 'type' => 'string' ],
									]
								),
							],
							'confidence'  => self::nullable_confidence(),
							'ranking'     => self::nullable_ranking_schema(),
						]
					),
				],
				'explanation' => [ 'type' => 'string' ],
			]
		);
	}

	private static function content_schema(): array {
		return self::strict_object(
			[
				'mode'    => [
					'type' => 'string',
					'enum' => [ 'draft', 'edit', 'critique' ],
				],
				'title'   => [ 'type' => 'string' ],
				'summary' => [ 'type' => 'string' ],
				'content' => [ 'type' => 'string' ],
				'notes'   => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'issues'  => [
					'type'  => 'array',
					'items' => self::strict_object(
						[
							'original' => [ 'type' => 'string' ],
							'problem'  => [ 'type' => 'string' ],
							'revision' => [ 'type' => 'string' ],
						]
					),
				],
			]
		);
	}

	private static function block_setting_style_item_schema(): array {
		return self::strict_object(
			array_merge(
				[
					'label'            => [ 'type' => 'string' ],
					'description'      => [ 'type' => 'string' ],
					'panel'            => [
						'type' => 'string',
						'enum' => self::BLOCK_PANELS,
					],
					'type'             => [
						'type' => 'string',
						'enum' => [ 'attribute_change', 'style_variation', '' ],
					],
					'attributeUpdates' => [ 'type' => 'string' ],
				],
				self::block_display_metadata_schema()
			)
		);
	}

	private static function block_block_item_schema(): array {
		return self::strict_object(
			array_merge(
				[
					'label'            => [ 'type' => 'string' ],
					'description'      => [ 'type' => 'string' ],
					'type'             => [
						'type' => 'string',
						'enum' => [ 'attribute_change', 'style_variation', 'structural_recommendation', 'pattern_replacement', '' ],
					],
					'attributeUpdates' => [ 'type' => 'string' ],
					'panel'            => [
						'type' => 'string',
						'enum' => array_merge( self::BLOCK_PANELS, [ '' ] ),
					],
					'operations'       => [
						'type'  => 'array',
						'items' => self::block_operation_schema(),
					],
				],
				self::block_display_metadata_schema()
			)
		);
	}

	private static function block_display_metadata_schema(): array {
		return [
			'currentValue'   => [ 'type' => 'string' ],
			'suggestedValue' => [ 'type' => 'string' ],
			'isCurrentStyle' => [ 'type' => 'boolean' ],
			'isRecommended'  => [ 'type' => 'boolean' ],
			'confidence'     => [ 'type' => 'number' ],
			'ranking'        => self::nullable_ranking_schema(),
			'preview'        => [ 'type' => 'string' ],
			'presetSlug'     => [ 'type' => 'string' ],
			'cssVar'         => [ 'type' => 'string' ],
			'groupId'        => [ 'type' => 'string' ],
		];
	}

	private static function block_operation_schema(): array {
		return self::strict_object(
			[
				'type'           => [
					'type' => 'string',
					'enum' => [ 'insert_pattern', 'replace_block_with_pattern' ],
				],
				'patternName'    => [ 'type' => 'string' ],
				'targetClientId' => [ 'type' => 'string' ],
				'position'       => [
					'type' => 'string',
					'enum' => [ 'insert_before', 'insert_after', '' ],
				],
			]
		);
	}

	/**
	 * Pattern ranking constrains the model to the exact shape PatternAbilities
	 * parses ({recommendations:[{name, score, reason}]}), giving the pattern
	 * surface the same structured-output guard the other model-backed surfaces
	 * already use instead of relying on free-form JSON + manual fence stripping.
	 */
	private static function pattern_schema(): array {
		return self::strict_object(
			[
				'recommendations' => [
					'type'  => 'array',
					'items' => self::strict_object(
						[
							'name'   => [ 'type' => 'string' ],
							'score'  => [
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 1,
							],
							'reason' => [ 'type' => 'string' ],
						]
					),
				],
			]
		);
	}

	private static function strict_object( array $properties ): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
		];
	}

	private static function nullable_string(): array {
		return [ 'type' => [ 'string', 'null' ] ];
	}

	private static function nullable_integer(): array {
		return [ 'type' => [ 'integer', 'null' ] ];
	}

	private static function nullable_confidence(): array {
		return [
			'type'        => [ 'number', 'null' ],
			'minimum'     => 0,
			'maximum'     => 1,
			'description' => 'Optional 0..1 ranking confidence; return null to defer to deterministic ranking.',
		];
	}

	private static function nullable_ranking_schema(): array {
		$schema = self::strict_object(
			[
				'score'           => [
					'type'    => [ 'number', 'null' ],
					'minimum' => 0,
					'maximum' => 1,
				],
				'reason'          => self::nullable_string(),
				'sourceSignals'   => [
					'type'  => [ 'array', 'null' ],
					'items' => [ 'type' => 'string' ],
				],
				'designPrinciple' => self::nullable_string(),
				'risk'            => self::nullable_string(),
			]
		);

		$schema['type'] = [ 'object', 'null' ];

		return $schema;
	}

	private static function nullable_integer_array(): array {
		return [
			'type'  => [ 'array', 'null' ],
			'items' => [ 'type' => 'integer' ],
		];
	}

	private static function any_value(): array {
		return [
			'type' => [ 'string', 'number', 'boolean', 'null' ],
		];
	}
}
