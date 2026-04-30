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
			'style' => self::style_schema(),
			'navigation' => self::navigation_schema(),
			'content' => self::content_schema(),
			default => null,
		};
	}

	private static function block_schema(): array {
		return self::strict_object(
			[
				'settings'    => [
					'type'  => 'array',
					'items' => self::block_setting_style_item_schema(),
				],
				'styles'      => [
					'type'  => 'array',
					'items' => self::block_setting_style_item_schema(),
				],
				'block'       => [
					'type'  => 'array',
					'items' => self::block_block_item_schema(),
				],
				'explanation' => [ 'type' => 'string' ],
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
										'type'           => [
											'type' => 'string',
											'enum' => [ 'assign_template_part', 'replace_template_part', 'insert_pattern' ],
										],
										'slug'           => self::nullable_string(),
										'area'           => self::nullable_string(),
										'currentSlug'    => self::nullable_string(),
										'patternName'    => self::nullable_string(),
										'placement'      => self::nullable_string(),
										'targetPath'     => self::nullable_integer_array(),
										'expectedTarget' => self::nullable_expected_target_schema(),
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
										'type'           => [
											'type' => 'string',
											'enum' => [ 'insert_pattern', 'replace_block_with_pattern', 'remove_block' ],
										],
										'patternName'    => self::nullable_string(),
										'placement'      => self::nullable_string(),
										'targetPath'     => self::nullable_integer_array(),
										'expectedBlockName' => self::nullable_string(),
										'expectedTarget' => self::nullable_expected_target_schema(),
									]
								),
							],
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
			[
				'label'            => [ 'type' => 'string' ],
				'description'      => [ 'type' => 'string' ],
				'panel'            => [
					'type' => 'string',
					'enum' => self::BLOCK_PANELS,
				],
				'type'             => [
					'type' => [ 'string', 'null' ],
					'enum' => [ 'attribute_change', 'style_variation', null ],
				],
				'attributeUpdates' => [
					'type' => [ 'object', 'null' ],
				],
				'currentValue'     => self::any_value(),
				'suggestedValue'   => self::any_value(),
				'isCurrentStyle'   => self::nullable_boolean(),
				'isRecommended'    => self::nullable_boolean(),
				'confidence'       => self::nullable_number(),
				'preview'          => self::nullable_string(),
				'presetSlug'       => self::nullable_string(),
				'cssVar'           => self::nullable_string(),
			]
		);
	}

	private static function block_block_item_schema(): array {
		return self::strict_object(
			[
				'label'              => [ 'type' => 'string' ],
				'description'        => [ 'type' => 'string' ],
				'type'               => [
					'type' => [ 'string', 'null' ],
					'enum' => [ 'attribute_change', 'style_variation', 'structural_recommendation', 'pattern_replacement', null ],
				],
				'attributeUpdates'   => [
					'type' => [ 'object', 'null' ],
				],
				'panel'              => [
					'type' => [ 'string', 'null' ],
					'enum' => array_merge( self::BLOCK_PANELS, [ null ] ),
				],
				'operations'         => [
					'type'  => 'array',
					'items' => self::block_operation_schema(),
				],
				'proposedOperations' => [
					'type'  => 'array',
					'items' => self::block_operation_schema(),
				],
				'rejectedOperations' => [
					'type'  => 'array',
					'items' => self::block_operation_rejection_schema(),
				],
				'currentValue'       => self::any_value(),
				'suggestedValue'     => self::any_value(),
				'isCurrentStyle'     => self::nullable_boolean(),
				'isRecommended'      => self::nullable_boolean(),
				'confidence'         => self::nullable_number(),
				'preview'            => self::nullable_string(),
				'presetSlug'         => self::nullable_string(),
				'cssVar'             => self::nullable_string(),
			]
		);
	}

	private static function block_operation_schema(): array {
		return self::strict_object(
			[
				'catalogVersion'  => self::nullable_integer(),
				'type'            => [
					'type' => [ 'string', 'null' ],
					'enum' => [ 'insert_pattern', 'replace_block_with_pattern', null ],
				],
				'patternName'     => self::nullable_string(),
				'targetClientId'  => self::nullable_string(),
				'position'        => self::nullable_string(),
				'action'          => self::nullable_string(),
				'targetSignature' => self::nullable_string(),
				'targetSurface'   => self::nullable_string(),
				'targetType'      => self::nullable_string(),
				'expectedTarget'  => self::nullable_block_operation_expected_target_schema(),
			]
		);
	}

	private static function block_operation_rejection_schema(): array {
		return self::strict_object(
			[
				'code'      => self::nullable_string(),
				'message'   => self::nullable_string(),
				'operation' => [
					'type'                 => [ 'object', 'null' ],
					'additionalProperties' => true,
				],
			]
		);
	}

	private static function nullable_block_operation_expected_target_schema(): array {
		return self::nullable_strict_object(
			[
				'clientId'   => self::nullable_string(),
				'name'       => self::nullable_string(),
				'label'      => self::nullable_string(),
				'attributes' => [ 'type' => [ 'object', 'null' ] ],
				'childCount' => self::nullable_integer(),
			]
		);
	}

	private static function nullable_expected_target_schema(): array {
		return self::nullable_strict_object(
			[
				'name'       => self::nullable_string(),
				'label'      => self::nullable_string(),
				'attributes' => [ 'type' => 'object' ],
				'childCount' => self::nullable_integer(),
				'slot'       => self::nullable_strict_object(
					[
						'slug'    => self::nullable_string(),
						'area'    => self::nullable_string(),
						'isEmpty' => self::nullable_boolean(),
					]
				),
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

	private static function nullable_strict_object( array $properties ): array {
		$schema         = self::strict_object( $properties );
		$schema['type'] = [ 'object', 'null' ];

		return $schema;
	}

	private static function nullable_string(): array {
		return [ 'type' => [ 'string', 'null' ] ];
	}

	private static function nullable_boolean(): array {
		return [ 'type' => [ 'boolean', 'null' ] ];
	}

	private static function nullable_number(): array {
		return [ 'type' => [ 'number', 'null' ] ];
	}

	private static function nullable_integer(): array {
		return [ 'type' => [ 'integer', 'null' ] ];
	}

	private static function nullable_integer_array(): array {
		return [
			'type'  => [ 'array', 'null' ],
			'items' => [ 'type' => 'integer' ],
		];
	}

	private static function any_value(): array {
		return [
			'type' => [ 'string', 'number', 'boolean', 'object', 'array', 'null' ],
		];
	}
}
