<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\Prompt;
use PHPUnit\Framework\TestCase;

final class PromptRulesTest extends TestCase {

	public function test_enforce_block_context_rules_returns_empty_payload_for_disabled_blocks(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[ 'label' => 'Toggle setting' ],
				],
				'styles'      => [
					[ 'label' => 'Accent background' ],
				],
				'block'       => [
					[ 'label' => 'Edit content' ],
				],
				'explanation' => 'Should not survive disabled mode.',
			],
			[
				'editingMode' => 'disabled',
			]
		);

		$this->assertSame(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [],
				'explanation' => '',
			],
			$result
		);
	}

	public function test_enforce_block_context_rules_filters_non_content_updates_for_block_level_content_only(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Toggle setting',
						'attributeUpdates' => [ 'dropCap' => true ],
					],
				],
				'styles'      => [
					[
						'label'            => 'Accent background',
						'attributeUpdates' => [ 'backgroundColor' => 'accent' ],
					],
				],
				'block'       => [
					[
						'label'            => 'Update copy',
						'attributeUpdates' => [
							'content'         => 'Shorter copy',
							'backgroundColor' => 'accent',
						],
					],
				],
				'explanation' => 'Content-only output.',
			],
			[
				'editingMode'       => 'contentOnly',
				'contentAttributes' => [
					'content' => [
						'role' => 'content',
					],
				],
			]
		);

		$this->assertSame( [], $result['settings'] );
		$this->assertSame( [], $result['styles'] );
		$this->assertSame(
			[
				[
					'label'            => 'Update copy',
					'attributeUpdates' => [
						'content' => 'Shorter copy',
					],
				],
			],
			$result['block']
		);
		$this->assertSame( 'Content-only output.', $result['explanation'] );
	}

	public function test_enforce_block_context_rules_keeps_container_level_content_only_behavior(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'            => 'Update copy',
						'attributeUpdates' => [
							'content'  => 'Updated',
							'metadata' => [ 'foo' => 'bar' ],
						],
					],
				],
				'explanation' => 'Container lock.',
			],
			[
				'isInsideContentOnly' => true,
				'contentAttributes'   => [
					'content' => [
						'role' => 'content',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Update copy',
					'attributeUpdates' => [
						'content' => 'Updated',
					],
				],
			],
			$result['block']
		);
		$this->assertSame( 'Container lock.', $result['explanation'] );
	}

	public function test_parse_response_normalizes_list_view_panel_aliases(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'settings'    => [
						[
							'label'            => 'Open list items by default',
							'panel'            => 'list-view',
							'attributeUpdates' => [ 'ordered' => true ],
						],
					],
					'styles'      => [],
					'block'       => [],
					'explanation' => 'List view routing should be normalized.',
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'list', $result['settings'][0]['panel'] );
	}

	public function test_parse_response_keeps_advisory_block_suggestions_without_panel_and_strips_attribute_updates(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'block'       => [
						[
							'label'            => 'Wrap this block in a Group',
							'description'      => 'Use a Group parent to unlock spacing and background controls.',
							'type'             => 'structural_recommendation',
							'attributeUpdates' => [
								'customCSS' => '.wp-block-group { color: red; }',
							],
						],
					],
					'explanation' => 'Structural advice should stay advisory.',
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'label'            => 'Wrap this block in a Group',
					'description'      => 'Use a Group parent to unlock spacing and background controls.',
					'type'             => 'structural_recommendation',
					'attributeUpdates' => [],
					'currentValue'     => null,
					'suggestedValue'   => null,
					'isCurrentStyle'   => null,
					'isRecommended'    => null,
					'confidence'       => null,
					'preview'          => null,
					'presetSlug'       => null,
					'cssVar'           => null,
				],
			],
			$result['block']
		);
	}

	public function test_enforce_block_context_rules_strips_unsupported_bindings_for_unlocked_blocks(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Connect CTA fields',
						'attributeUpdates' => [
							'metadata' => [
								'name'     => 'Hero CTA',
								'bindings' => [
									'url'  => [
										'source' => 'core/post-meta',
										'args'   => [ 'key' => 'cta_url' ],
									],
									'text' => [
										'source' => 'core/post-meta',
										'args'   => [ 'key' => 'cta_label' ],
									],
								],
							],
						],
					],
				],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Only supported binding targets should survive.',
			],
			[
				'bindableAttributes' => [ 'url' ],
			],
			[
				'bindableAttributes' => [ 'url' ],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Connect CTA fields',
					'attributeUpdates' => [
						'metadata' => [
							'bindings' => [
								'url' => [
									'source' => 'core/post-meta',
									'args'   => [ 'key' => 'cta_url' ],
								],
							],
						],
					],
				],
			],
			$result['settings']
		);
	}

	public function test_enforce_block_context_rules_keeps_advisory_block_suggestions_when_bindable_attributes_are_present(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'            => 'Wrap this block in a Group',
						'type'             => 'structural_recommendation',
						'attributeUpdates' => [],
					],
				],
				'explanation' => 'Advisory structure guidance should survive binding filters.',
			],
			[
				'bindableAttributes' => [ 'url' ],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Wrap this block in a Group',
					'type'             => 'structural_recommendation',
					'attributeUpdates' => [],
				],
			],
			$result['block']
		);
	}

	public function test_enforce_block_context_rules_preserves_advisory_block_suggestions_in_content_only_mode(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'            => 'Wrap this block in a Group',
						'type'             => 'structural_recommendation',
						'attributeUpdates' => [
							'backgroundColor' => 'accent',
						],
					],
					[
						'label'            => 'Use accent background',
						'type'             => 'attribute_change',
						'attributeUpdates' => [
							'backgroundColor' => 'accent',
						],
					],
				],
				'explanation' => 'Structural advice should survive locked wrapper edits.',
			],
			[
				'editingMode'       => 'contentOnly',
				'contentAttributes' => [
					'content' => [
						'role' => 'content',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Wrap this block in a Group',
					'type'             => 'structural_recommendation',
					'attributeUpdates' => [],
				],
			],
			$result['block']
		);
	}

	public function test_enforce_block_context_rules_preserves_advisory_block_suggestions_for_supports_content_role_blocks_without_direct_content_attributes(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'            => 'Replace with a richer pattern',
						'type'             => 'pattern_replacement',
						'attributeUpdates' => [
							'className' => 'is-style-outline',
						],
					],
					[
						'label'            => 'Change wrapper spacing',
						'type'             => 'attribute_change',
						'attributeUpdates' => [
							'style' => [
								'spacing' => [
									'padding' => 'var(--wp--preset--spacing--40)',
								],
							],
						],
					],
				],
				'explanation' => 'Only advisory structure guidance should survive.',
			],
			[
				'editingMode'         => 'contentOnly',
				'supportsContentRole' => true,
				'contentAttributes'   => [],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Replace with a richer pattern',
					'type'             => 'pattern_replacement',
					'attributeUpdates' => [],
				],
			],
			$result['block']
		);
	}

	public function test_enforce_block_context_rules_drops_binding_only_suggestions_when_no_bindable_attributes_are_allowed(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Connect CTA fields',
						'attributeUpdates' => [
							'metadata' => [
								'name'     => 'Hero CTA',
								'bindings' => [
									'url' => [
										'source' => 'core/post-meta',
										'args'   => [ 'key' => 'cta_url' ],
									],
								],
							],
						],
					],
				],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Unsupported bindings should not degrade into renames.',
			],
			[
				'bindableAttributes' => [],
			]
		);

		$this->assertSame( [], $result['settings'] );
		$this->assertSame( 'Unsupported bindings should not degrade into renames.', $result['explanation'] );
	}

	public function test_parse_response_drops_suggestions_that_only_set_custom_css(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'styles'      => [
						[
							'label'            => 'Inject custom CSS',
							'attributeUpdates' => [
								'customCSS' => '.wp-block-paragraph { color: red; }',
							],
						],
					],
					'explanation' => 'Unsafe CSS should not survive parsing.',
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['styles'] );
		$this->assertSame( 'Unsafe CSS should not survive parsing.', $result['explanation'] );
	}

	public function test_parse_response_strips_nested_style_css_but_keeps_safe_theme_json_values(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'styles'      => [
						[
							'label'            => 'Use the accent preset instead',
							'attributeUpdates' => [
								'style' => [
									'css'   => '.wp-block-group { color: red; }',
									'color' => [
										'background' => 'var:preset|color|accent',
									],
								],
							],
						],
					],
					'explanation' => 'Safe theme-backed changes should remain.',
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'label'            => 'Use the accent preset instead',
					'description'      => '',
					'panel'            => 'general',
					'type'             => null,
					'attributeUpdates' => [
						'style' => [
							'color' => [
								'background' => 'var:preset|color|accent',
							],
						],
					],
					'currentValue'     => null,
					'suggestedValue'   => null,
					'isCurrentStyle'   => null,
					'isRecommended'    => null,
					'confidence'       => null,
					'preview'          => null,
					'presetSlug'       => null,
					'cssVar'           => null,
				],
			],
			$result['styles']
		);
	}

	public function test_parse_response_drops_raw_css_strings_from_style_updates(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'styles'      => [
						[
							'label'            => 'Replace the text color',
							'attributeUpdates' => [
								'style' => [
									'color' => [
										'text' => 'color: red;',
									],
								],
							],
						],
					],
					'explanation' => 'Raw CSS declarations should not survive parsing.',
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['styles'] );
	}

	public function test_parse_response_drops_non_block_suggestions_without_attribute_updates(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'settings'    => [
						[
							'label' => 'No-op setting',
							'panel' => 'general',
						],
					],
					'styles'      => [
						[
							'label' => 'No-op style',
							'panel' => 'color',
						],
					],
					'block'       => [
						[
							'label'       => 'Wrap this block in a Group',
							'type'        => 'structural_recommendation',
							'description' => 'Manual follow-through only.',
						],
					],
					'explanation' => 'Only advisory block items should survive.',
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['settings'] );
		$this->assertSame( [], $result['styles'] );
		$this->assertCount( 1, $result['block'] );
		$this->assertSame( 'structural_recommendation', $result['block'][0]['type'] );
	}

	public function test_enforce_block_context_rules_drops_settings_and_styles_when_execution_contract_declares_explicitly_empty_panels(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Use accent background',
						'panel'            => 'color',
						'attributeUpdates' => [
							'backgroundColor' => 'accent',
						],
					],
				],
				'styles'      => [
					[
						'label'            => 'Add padding',
						'panel'            => 'dimensions',
						'attributeUpdates' => [
							'style' => [
								'spacing' => [
									'padding' => 'var:preset|spacing|40',
								],
							],
						],
					],
				],
				'block'       => [
					[
						'label'            => 'Use outline style',
						'type'             => 'style_variation',
						'attributeUpdates' => [
							'className' => 'is-style-outline',
						],
					],
				],
				'explanation' => 'Only the registered style variation should survive.',
			],
			[],
			[
				'allowedPanels'            => [],
				'hasExplicitlyEmptyPanels' => true,
				'registeredStyles'         => [ 'outline' ],
				'presetSlugs'              => [
					'color'   => [ 'accent' ],
					'spacing' => [ '40' ],
				],
			]
		);

		$this->assertSame( [], $result['settings'] );
		$this->assertSame( [], $result['styles'] );
		$this->assertSame(
			[
				[
					'label'            => 'Use outline style',
					'type'             => 'style_variation',
					'attributeUpdates' => [
						'className' => 'is-style-outline',
					],
				],
			],
			$result['block']
		);
	}

	public function test_enforce_block_context_rules_drops_suggestions_that_target_unsupported_panels(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Use accent background',
						'panel'            => 'color',
						'attributeUpdates' => [
							'backgroundColor' => 'accent',
						],
					],
				],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Unsupported panel suggestions should be removed.',
			],
			[],
			[
				'allowedPanels'     => [ 'general' ],
				'styleSupportPaths' => [ 'color.background' ],
				'presetSlugs'       => [
					'color' => [ 'accent' ],
				],
			]
		);

		$this->assertSame( [], $result['settings'] );
	}

	public function test_enforce_block_context_rules_preserves_best_effort_settings_and_styles_when_panel_mapping_is_unknown(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Turn on drop cap',
						'panel'            => 'general',
						'attributeUpdates' => [
							'dropCap' => true,
						],
					],
				],
				'styles'      => [
					[
						'label'            => 'Use accent background',
						'panel'            => 'color',
						'attributeUpdates' => [
							'backgroundColor' => 'accent',
						],
					],
				],
				'block'       => [],
				'explanation' => 'Unknown panel mappings should stay best-effort.',
			],
			[],
			[
				'panelMappingKnown'   => false,
				'allowedPanels'       => [],
				'configAttributeKeys' => [ 'dropCap' ],
				'styleSupportPaths'   => [],
				'presetSlugs'         => [
					'color' => [ 'accent' ],
				],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Turn on drop cap',
					'panel'            => 'general',
					'attributeUpdates' => [
						'dropCap' => true,
					],
				],
			],
			$result['settings']
		);
		$this->assertSame(
			[
				[
					'label'            => 'Use accent background',
					'panel'            => 'color',
					'attributeUpdates' => [
						'backgroundColor' => 'accent',
					],
				],
			],
			$result['styles']
		);
	}

	public function test_enforce_block_context_rules_keeps_block_suggestions_when_panel_mapping_is_unknown(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'            => 'Set custom alignment',
						'panel'            => 'layout',
						'attributeUpdates' => [
							'align' => 'wide',
						],
					],
				],
				'explanation' => 'Block suggestions should survive when the execution contract is not authoritative.',
			],
			[],
			[
				'panelMappingKnown'        => false,
				'allowedPanels'            => [],
				'hasExplicitlyEmptyPanels' => false,
				'configAttributeKeys'      => [ 'align' ],
				'styleSupportPaths'        => [],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Set custom alignment',
					'panel'            => 'layout',
					'attributeUpdates' => [
						'align' => 'wide',
					],
				],
			],
			$result['block']
		);
	}

	public function test_enforce_block_context_rules_drops_block_suggestions_with_unknown_panel_when_mapping_is_known(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'            => 'Set custom alignment',
						'panel'            => 'layout',
						'attributeUpdates' => [
							'align' => 'wide',
						],
					],
				],
				'explanation' => 'Block suggestions targeting an unknown panel should drop when the contract is authoritative.',
			],
			[],
			[
				'panelMappingKnown'        => true,
				'allowedPanels'            => [ 'color' ],
				'hasExplicitlyEmptyPanels' => false,
				'styleSupportPaths'        => [],
			]
		);

		$this->assertSame( [], $result['block'] );
	}

	public function test_enforce_block_context_rules_filters_executable_updates_to_declared_attribute_contract(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Update declared config',
						'panel'            => 'general',
						'attributeUpdates' => [
							'dropCap'           => true,
							'lock'              => [ 'move' => true ],
							'unregisteredThing' => 'surprise',
						],
					],
				],
				'styles'      => [],
				'block'       => [
					[
						'label'            => 'Connect supported metadata',
						'attributeUpdates' => [
							'metadata' => [
								'name'            => 'Hero CTA',
								'blockVisibility' => [
									'viewport' => [
										'mobile' => false,
									],
								],
								'bindings'        => [
									'content' => [
										'source' => 'core/post-meta',
										'args'   => [ 'key' => 'cta_label' ],
									],
									'url'     => [
										'source' => 'core/post-meta',
										'args'   => [ 'key' => 'cta_url' ],
									],
								],
							],
						],
					],
				],
				'explanation' => 'Only declared local attributes and supported metadata should survive.',
			],
			[
				'bindableAttributes' => [ 'content' ],
			],
			[
				'allowedPanels'       => [ 'general' ],
				'configAttributeKeys' => [ 'dropCap' ],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Update declared config',
					'panel'            => 'general',
					'attributeUpdates' => [
						'dropCap' => true,
					],
				],
			],
			$result['settings']
		);
		$this->assertSame(
			[
				[
					'label'            => 'Connect supported metadata',
					'attributeUpdates' => [
						'metadata' => [
							'blockVisibility' => [
								'viewport' => [
									'mobile' => false,
								],
							],
							'bindings'        => [
								'content' => [
									'source' => 'core/post-meta',
									'args'   => [ 'key' => 'cta_label' ],
								],
							],
						],
					],
				],
			],
			$result['block']
		);
	}

	public function test_enforce_block_context_rules_merges_partial_execution_contract_with_block_local_attributes(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Update local attributes',
						'panel'            => 'general',
						'attributeUpdates' => [
							'content'          => 'Updated copy',
							'dropCap'          => true,
							'unknownAttribute' => 'remove me',
						],
					],
				],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Partial contracts should still use block-local keys.',
			],
			[
				'contentAttributes' => [
					'content' => [ 'role' => 'content' ],
				],
				'configAttributes'  => [
					'dropCap' => [ 'type' => 'boolean' ],
				],
			],
			[
				'allowedPanels'     => [ 'general' ],
				'panelMappingKnown' => true,
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Update local attributes',
					'panel'            => 'general',
					'attributeUpdates' => [
						'content' => 'Updated copy',
						'dropCap' => true,
					],
				],
			],
			$result['settings']
		);
	}

	public function test_enforce_block_context_rules_filters_bindings_declared_by_execution_contract(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Scope CTA visibility and bindings',
						'attributeUpdates' => [
							'metadata' => [
								'blockVisibility' => [
									'viewport' => [
										'mobile' => false,
									],
								],
								'bindings'        => [
									'content' => [
										'source' => 'core/post-meta',
										'args'   => [ 'key' => 'cta_label' ],
									],
									'url'     => [
										'source' => 'core/post-meta',
										'args'   => [ 'key' => 'cta_url' ],
									],
								],
							],
						],
					],
				],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Execution contracts can carry binding support.',
			],
			[],
			[
				'bindableAttributes' => [ 'content' ],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Scope CTA visibility and bindings',
					'attributeUpdates' => [
						'metadata' => [
							'blockVisibility' => [
								'viewport' => [
									'mobile' => false,
								],
							],
							'bindings'        => [
								'content' => [
									'source' => 'core/post-meta',
									'args'   => [ 'key' => 'cta_label' ],
								],
							],
						],
					],
				],
			],
			$result['settings']
		);
	}

	public function test_enforce_block_context_rules_drops_unsupported_presets_and_unregistered_style_variations(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [
					[
						'label'            => 'Use accent background',
						'panel'            => 'color',
						'attributeUpdates' => [
							'backgroundColor' => 'accent',
						],
					],
				],
				'block'       => [
					[
						'label'            => 'Use fancy style',
						'type'             => 'style_variation',
						'attributeUpdates' => [
							'className' => 'is-style-fancy',
						],
					],
				],
				'explanation' => 'Unsupported presets and styles should not survive.',
			],
			[],
			[
				'allowedPanels'     => [ 'color' ],
				'styleSupportPaths' => [ 'color.background' ],
				'registeredStyles'  => [ 'outline' ],
				'presetSlugs'       => [
					'color' => [ 'base' ],
				],
			]
		);

		$this->assertSame( [], $result['styles'] );
		$this->assertSame( [], $result['block'] );
	}

	public function test_enforce_block_context_rules_preserves_safe_custom_property_style_values_under_an_authoritative_contract(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [
					[
						'label'            => 'Use the custom brand accent',
						'panel'            => 'color',
						'attributeUpdates' => [
							'style' => [
								'color' => [
									'text' => 'var(--wp--custom--brand-accent)',
								],
							],
						],
					],
				],
				'block'       => [],
				'explanation' => 'Custom theme variables should survive contract enforcement.',
			],
			[],
			[
				'allowedPanels'     => [ 'color' ],
				'styleSupportPaths' => [ 'color.text' ],
				'presetSlugs'       => [
					'color' => [ 'base' ],
				],
				'enabledFeatures'   => [
					'textColor' => true,
				],
			]
		);

		$this->assertSame(
			'var(--wp--custom--brand-accent)',
			$result['styles'][0]['attributeUpdates']['style']['color']['text']
		);
	}

	public function test_enforce_block_context_rules_allows_raw_fallback_values_when_relevant_preset_families_are_empty(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [
					[
						'label'            => 'Use raw values when no presets exist',
						'panel'            => 'typography',
						'attributeUpdates' => [
							'style' => [
								'color'      => [
									'background' => '#222',
								],
								'typography' => [
									'fontSize'   => '18px',
									'fontFamily' => '"Literata", serif',
								],
								'shadow'     => '0 12px 32px rgba(0, 0, 0, 0.18)',
							],
						],
					],
				],
				'block'       => [],
				'explanation' => 'Empty preset families should allow safe raw fallbacks.',
			],
			[],
			[
				'allowedPanels'     => [ 'color', 'shadow', 'typography' ],
				'styleSupportPaths' => [
					'color.background',
					'typography.fontSize',
					'typography.__experimentalFontFamily',
					'shadow',
				],
				'presetSlugs'       => [
					'color'      => [],
					'fontsize'   => [],
					'fontfamily' => [],
					'shadow'     => [],
				],
				'enabledFeatures'   => [
					'backgroundColor' => true,
				],
			]
		);

		$this->assertSame(
			[
				'color'      => [
					'background' => '#222',
				],
				'typography' => [
					'fontSize'   => '18px',
					'fontFamily' => '"Literata", serif',
				],
				'shadow'     => '0 12px 32px rgba(0, 0, 0, 0.18)',
			],
			$result['styles'][0]['attributeUpdates']['style']
		);
	}

	public function test_enforce_block_context_rules_preserves_supported_typography_controls_under_an_authoritative_contract(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [
					[
						'label'            => 'Strengthen the heading treatment',
						'panel'            => 'typography',
						'attributeUpdates' => [
							'style' => [
								'typography' => [
									'fontWeight'     => '700',
									'fontStyle'      => 'italic',
									'letterSpacing'  => '-0.02em',
									'textDecoration' => 'underline',
									'textTransform'  => 'uppercase',
								],
							],
						],
					],
				],
				'block'       => [],
				'explanation' => 'Supported typography controls should remain executable.',
			],
			[],
			[
				'allowedPanels'     => [ 'typography' ],
				'styleSupportPaths' => [
					'typography.fontWeight',
					'typography.fontStyle',
					'typography.letterSpacing',
					'typography.textDecoration',
					'typography.textTransform',
				],
				'enabledFeatures'   => [
					'fontWeight'     => true,
					'fontStyle'      => true,
					'letterSpacing'  => true,
					'textDecoration' => true,
					'textTransform'  => true,
				],
			]
		);

		$this->assertSame(
			[
				'fontWeight'     => '700',
				'fontStyle'      => 'italic',
				'letterSpacing'  => '-0.02em',
				'textDecoration' => 'underline',
				'textTransform'  => 'uppercase',
			],
			$result['styles'][0]['attributeUpdates']['style']['typography']
		);
	}

	public function test_parse_response_normalizes_ranking_contract_when_confidence_present(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'block' => [
						[
							'label'       => 'Refine heading hierarchy',
							'description' => 'Use a more structured heading level.',
							'type'        => 'structural_recommendation',
							'confidence'  => 0.71,
						],
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0.71, $result['block'][0]['ranking']['score'] );
		$this->assertSame( 'validated', $result['block'][0]['ranking']['safetyMode'] );
		$this->assertSame( 'structural_recommendation', $result['block'][0]['ranking']['advisoryType'] );
		$this->assertSame( [ 'llm_response', 'block_surface', 'has_description' ], $result['block'][0]['ranking']['sourceSignals'] );
	}

	public function test_parse_response_prefers_explicit_score_over_confidence_when_ranking_blocks(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'block' => [
						[
							'label'       => 'Explicit score wins',
							'description' => 'The score should drive ordering.',
							'type'        => 'structural_recommendation',
							'score'       => 0.91,
							'confidence'  => 0.14,
						],
						[
							'label'       => 'Confidence only',
							'description' => 'Still valid, but lower-ranked.',
							'type'        => 'structural_recommendation',
							'confidence'  => 0.82,
						],
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Explicit score wins', $result['block'][0]['label'] );
		$this->assertSame( 0.91, $result['block'][0]['ranking']['score'] );
	}

	public function test_parse_response_falls_back_when_nested_ranking_score_is_malformed(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'block' => [
						[
							'label'       => 'Fallback confidence wins',
							'description' => 'A malformed nested score should not zero this out.',
							'type'        => 'structural_recommendation',
							'ranking'     => [
								'score' => [],
							],
							'confidence'  => 0.88,
						],
						[
							'label'       => 'Lower confidence',
							'description' => 'Still valid, but should sort second.',
							'type'        => 'structural_recommendation',
							'confidence'  => 0.82,
						],
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Fallback confidence wins', $result['block'][0]['label'] );
		$this->assertSame( 0.88, $result['block'][0]['ranking']['score'] );
	}

	public function test_parse_response_ranks_block_suggestions_by_computed_quality_signals(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'block' => [
						[
							'label'       => 'Advisory only',
							'description' => 'General suggestion.',
							'type'        => 'structural_recommendation',
						],
						[
							'label'            => 'Executable update',
							'description'      => 'Apply a concrete attribute update.',
							'type'             => 'attribute_change',
							'attributeUpdates' => [
								'level' => 2,
							],
						],
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Executable update', $result['block'][0]['label'] );
	}
}
