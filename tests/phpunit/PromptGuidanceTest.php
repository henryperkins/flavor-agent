<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\Prompt;
use FlavorAgent\LLM\TemplatePrompt;
use PHPUnit\Framework\TestCase;

final class PromptGuidanceTest extends TestCase {

	public function test_block_prompt_includes_wordpress_guidance_section_when_available(): void {
			$prompt = Prompt::build_user(
				[
					'block'       => [
						'name' => 'core/group',
					],
					'themeTokens' => [],
				],
				'Tighten layout spacing.',
				[
					[
						'sourceKey' => 'developer.wordpress.org/block-supports',
						'excerpt'   => 'Use block supports to expose design tools instead of ad hoc attributes.',
					],
				]
			);

		$this->assertStringContainsString( '## WordPress Developer Guidance', $prompt );
		$this->assertStringContainsString( 'Use block supports to expose design tools instead of ad hoc attributes.', $prompt );
	}

	public function test_template_prompt_includes_wordpress_guidance_section_when_available(): void {
		$prompt = TemplatePrompt::build_user(
			[
				'templateType'   => 'single',
				'title'          => 'Single',
				'assignedParts'  => [],
				'emptyAreas'     => [ 'footer' ],
				'availableParts' => [],
				'patterns'       => [],
				'themeTokens'    => [],
			],
			'Improve footer composition.',
			[
				[
					'title'   => 'Template parts REST reference',
					'excerpt' => 'Template parts expose an area field that indicates where they are intended for use.',
				],
			]
		);

		$this->assertStringContainsString( '## WordPress Developer Guidance', $prompt );
		$this->assertStringContainsString( 'Template parts REST reference: Template parts expose an area field', $prompt );
	}

	public function test_block_prompt_labels_core_roadmap_guidance(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name' => 'core/group',
				],
				'themeTokens' => [],
			],
			'Align with current core work.',
			[
				[
					'title'      => 'WordPress AI roadmap status',
					'sourceType' => 'roadmap',
					'excerpt'    => 'Open roadmap milestones: 0.9.0, 1.0.0.',
				],
			]
		);

		$this->assertStringContainsString( 'Core roadmap - WordPress AI roadmap status: Open roadmap milestones', $prompt );
	}

	public function test_block_prompt_includes_structural_identity_sections_when_available(): void {
		$prompt = Prompt::build_user(
			[
				'block'               => [
					'name'               => 'core/navigation',
					'structuralIdentity' => [
						'role'             => 'footer-navigation',
						'job'              => 'Navigation block in the "footer" template part.',
						'location'         => 'footer',
						'templateArea'     => 'footer',
						'templatePartSlug' => 'footer',
					],
				],
				'structuralAncestors' => [
					[
						'block' => 'core/template-part',
						'role'  => 'footer-slot',
					],
				],
				'structuralBranch'    => [
					[
						'block' => 'core/template-part',
						'role'  => 'footer-slot',
					],
				],
				'themeTokens'         => [],
			]
		);

		$this->assertStringContainsString( '## Structural identity', $prompt );
		$this->assertStringContainsString( 'Resolved role: footer-navigation', $prompt );
		$this->assertStringContainsString( '## Structural ancestors', $prompt );
		$this->assertStringContainsString( '## Structural branch', $prompt );
	}

	public function test_block_prompt_includes_design_semantic_context(): void {
		$prompt = Prompt::build_user(
			[
				'block'           => [
					'name'            => 'core/paragraph',
					'title'           => 'Paragraph',
					'inspectorPanels' => [
						'styles' => [ 'color' ],
					],
				],
				'designSemantics' => [
					'surface'         => 'block',
					'sectionRole'     => 'footer',
					'visualDensity'   => 'balanced',
					'contrastContext' => 'dark-parent',
					'layoutRhythm'    => 'constrained',
					'typographyRole'  => 'body',
					'mainDesignIssue' => 'contrast',
					'negativeSignals' => [ 'parent-already-supplies-contrast' ],
					'block'           => [
						'name' => 'core/paragraph',
					],
				],
				'themeTokens'     => [],
			],
			''
		);

		$this->assertStringContainsString( '## Design semantic context', $prompt );
		$this->assertStringContainsString( 'Role: footer', $prompt );
		$this->assertStringContainsString( 'Negative signals: parent-already-supplies-contrast', $prompt );
	}

	public function test_block_prompt_describes_block_level_content_only_restrictions(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name'        => 'core/paragraph',
					'editingMode' => 'contentOnly',
					'title'       => 'Paragraph',
				],
				'themeTokens' => [],
			]
		);

		$this->assertStringContainsString(
			'## Content-only restrictions',
			$prompt
		);
		$this->assertStringContainsString(
			'This block is in contentOnly editing mode.',
			$prompt
		);
		$this->assertStringContainsString(
			'Only content attributes (role=content) can be edited.',
			$prompt
		);
	}

	public function test_block_prompt_warns_when_content_only_block_uses_inner_blocks_as_content(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name'                => 'core/navigation',
					'editingMode'         => 'contentOnly',
					'title'               => 'Navigation',
					'supportsContentRole' => true,
					'contentAttributes'   => [],
				],
				'themeTokens' => [],
			]
		);

		$this->assertStringContainsString(
			'supports.contentRole',
			$prompt
		);
		$this->assertStringContainsString(
			'Do not suggest direct wrapper attribute changes.',
			$prompt
		);
	}

	public function test_block_system_prompt_allows_filter_panel_in_appearance_guidance(): void {
		$prompt = Prompt::build_system();

		$this->assertStringContainsString(
			'general|layout|position|advanced|bindings|list|color|filter|typography|dimensions|border|shadow|background',
			$prompt
		);
		$this->assertStringContainsString(
			'Appearance tab (color, filter, typography, dimensions, border, shadow, background, style variations)',
			$prompt
		);
		$this->assertStringContainsString(
			'Settings tab (layout, alignment, position, advanced, bindings, list, general config)',
			$prompt
		);
		$this->assertStringContainsString(
			'Do not say the panels were not provided.',
			$prompt
		);
		$this->assertStringContainsString(
			'structural_recommendation|pattern_replacement',
			$prompt
		);
		$this->assertStringContainsString(
			'parent containers, structural composition, or replacing the block with a more suitable pattern',
			$prompt
		);
		$this->assertStringContainsString(
			'Only include attributeUpdates when the selected block\'s own local attributes can be changed safely.',
			$prompt
		);
		$this->assertStringContainsString(
			'Inspector panel for executable block items; use empty string for advisory structural/pattern ideas',
			$prompt
		);
		$this->assertStringContainsString(
			'attributeUpdates must be a JSON object string, not a nested object.',
			$prompt
		);
		$this->assertStringContainsString(
			'emit two separate suggestions instead of combining them into one item',
			$prompt
		);
	}

	public function test_block_prompt_reports_explicitly_empty_available_panels(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name'            => 'plugin/plain-block',
					'inspectorPanels' => [],
				],
				'themeTokens' => [],
			]
		);

		$this->assertStringContainsString( 'Available panels: []', $prompt );
	}

	public function test_block_prompt_includes_duotone_token_summary_when_available(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name'               => 'core/image',
					'variations'         => [
						[
							'name'        => 'rounded',
							'title'       => 'Rounded',
							'description' => 'Rounded image treatment.',
						],
					],
					'configAttributes'   => [
						'sizeSlug' => [
							'type'    => 'string',
							'default' => 'large',
							'role'    => null,
						],
					],
					'currentAttributes'  => [
						'metadata' => [
							'bindings' => [
								'url' => [
									'source' => 'core/post-meta',
								],
							],
						],
					],
					'bindableAttributes' => [ 'content', 'url', 'linkTarget' ],
				],
				'themeTokens' => [
					'colors'          => [ 'accent: #ff5500' ],
					'colorPresets'    => [
						[
							'slug'   => 'accent',
							'color'  => '#ff5500',
							'cssVar' => 'var(--wp--preset--color--accent)',
						],
					],
					'gradients'       => [ 'sunset: linear-gradient(135deg,#f60,#fc0)' ],
					'gradientPresets' => [
						[
							'slug'     => 'sunset',
							'gradient' => 'linear-gradient(135deg,#f60,#fc0)',
							'cssVar'   => 'var(--wp--preset--gradient--sunset)',
						],
					],
					'duotone'         => [ 'midnight: #111111 / #f5f5f5' ],
					'duotonePresets'  => [
						[
							'slug'   => 'midnight',
							'colors' => [ '#111111', '#f5f5f5' ],
						],
					],
					'diagnostics'     => [
						'source' => 'server',
						'reason' => 'server-global-settings',
					],
					'enabledFeatures' => [
						'backgroundImage' => true,
					],
					'elementStyles'   => [
						'heading' => [
							'base' => [
								'text' => 'var(--wp--preset--color--contrast)',
							],
						],
					],
				],
			]
		);

		$this->assertStringContainsString(
			'Colors: accent: #ff5500',
			$prompt
		);
		$this->assertStringContainsString(
			'Gradients: sunset: linear-gradient(135deg,#f60,#fc0)',
			$prompt
		);
		$this->assertStringContainsString(
			'Duotone presets: midnight: #111111 / #f5f5f5',
			$prompt
		);
		// The per-preset JSON blobs are deliberately gone. They duplicated every
		// family, carrying only a cosmetic name and a cssVar the model derives
		// from the slug, and together cost roughly half the block prompt.
		$this->assertStringNotContainsString( 'preset details:', $prompt );
		$this->assertStringNotContainsString( 'cssVar', $prompt );
			$this->assertStringContainsString(
				'Block bindings:',
				$prompt
			);
			$this->assertStringContainsString(
				'Bindable attributes: ["content","url","linkTarget"]',
				$prompt
			);
		$this->assertStringContainsString(
			'Block variations:',
			$prompt
		);
		$this->assertStringContainsString(
			'Config attribute schema:',
			$prompt
		);
		$this->assertStringContainsString(
			'Theme feature flags:',
			$prompt
		);
		$this->assertStringContainsString(
			'Theme token diagnostics:',
			$prompt
		);
		$this->assertStringContainsString(
			'Global element styles:',
			$prompt
		);
	}

	public function test_block_system_prompt_mentions_aspect_ratio_and_height_exclusivity(): void {
		$prompt = Prompt::build_system();

		$this->assertStringContainsString(
			'Choose aspectRatio or height/minHeight, not both.',
			$prompt
		);
		$this->assertStringContainsString(
			'For duotone, use style.color.duotone.',
			$prompt
		);
	}

	public function test_block_prompt_limits_large_theme_token_collections_to_sixty_items(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name' => 'core/group',
				],
				'themeTokens' => [
					'colors'    => $this->buildTokenSequence( 'color', 65 ),
					'fontSizes' => $this->buildTokenSequence( 'font-size', 65 ),
					'spacing'   => $this->buildTokenSequence( 'spacing', 65 ),
					'shadows'   => $this->buildTokenSequence( 'shadow', 65 ),
				],
			]
		);

		$this->assertStringContainsString( 'color-59', $prompt );
		$this->assertStringNotContainsString( 'color-60', $prompt );
		$this->assertStringContainsString( 'font-size-59', $prompt );
		$this->assertStringNotContainsString( 'font-size-60', $prompt );
		$this->assertStringContainsString( 'spacing-59', $prompt );
		$this->assertStringNotContainsString( 'spacing-60', $prompt );
		$this->assertStringContainsString( 'shadow-59', $prompt );
		$this->assertStringNotContainsString( 'shadow-60', $prompt );
	}

	public function test_block_prompt_uses_budget_filter_and_drops_low_priority_context_first(): void {
		$captured       = [];
		$budget_filter  = static fn(): int => 2000;
		$capture_filter = static function ( int $value, string $surface ) use ( &$captured ): int {
			$captured[] = $surface;

			return $value;
		};
		add_filter( 'flavor_agent_prompt_budget_max_tokens', $capture_filter, 9, 2 );
		add_filter( 'flavor_agent_prompt_budget_max_tokens', $budget_filter, 10 );

		try {
			$prompt = Prompt::build_user(
				[
					'block'            => [
						'name'              => 'core/paragraph',
						'title'             => 'Paragraph',
						'currentAttributes' => [
							'content' => 'Intro paragraph.',
						],
					],
					'themeTokens'      => [
						'colors' => $this->buildTokenSequence( 'color', 24 ),
					],
					'structuralBranch' => [
						[
							'block'    => 'core/group',
							'children' => [
								[
									'block' => 'core/paragraph',
								],
							],
						],
					],
				],
				'Tighten the copy.',
				[
					[
						'title'   => 'Long docs',
						'excerpt' => str_repeat( 'Documentation guidance. ', 600 ),
					],
				]
			);
		} finally {
			remove_filter( 'flavor_agent_prompt_budget_max_tokens', $capture_filter, 9 );
			remove_filter( 'flavor_agent_prompt_budget_max_tokens', $budget_filter, 10 );
		}

		$this->assertContains( 'block', $captured );
		$this->assertStringContainsString( '## Block', $prompt );
		$this->assertStringContainsString( '## User instruction', $prompt );
		$this->assertStringContainsString( 'Tighten the copy.', $prompt );
		$this->assertStringNotContainsString( '## WordPress Developer Guidance', $prompt );
	}

	public function test_block_prompt_keeps_up_to_twenty_allowed_patterns_without_budget_pressure(): void {
		$prompt = Prompt::build_user(
			[
				'block'                 => [
					'name' => 'core/paragraph',
				],
				'blockOperationContext' => [
					'targetClientId'  => 'paragraph-1',
					'targetBlockName' => 'core/paragraph',
					'targetSignature' => 'signature-123',
					'allowedPatterns' => $this->buildAllowedPatterns( 21 ),
				],
			]
		);

		$this->assertStringContainsString( 'theme/pattern-19', $prompt );
		$this->assertStringNotContainsString( 'theme/pattern-20', $prompt );
	}

	public function test_block_prompt_renders_the_selected_block_interior(): void {
		$prompt = Prompt::build_user(
			[
				'block'         => [
					'name'       => 'core/group',
					'childCount' => 12,
				],
				'blockInterior' => [
					[
						'block'       => 'core/heading',
						'title'       => 'Heading',
						'role'        => 'section-title',
						'visualHints' => [ 'textColor' => 'contrast' ],
					],
					[
						'block'        => 'core/columns',
						'title'        => 'Columns',
						'childCount'   => 3,
						'children'     => [
							[
								'block' => 'core/column',
								'title' => 'Column',
							],
						],
						'moreChildren' => 2,
					],
				],
			]
		);

		$this->assertStringContainsString( '## Inside this block', $prompt );
		$this->assertStringContainsString( 'Showing 2 of 12 direct children.', $prompt );
		$this->assertStringContainsString( 'text:contrast', $prompt );
		$this->assertStringContainsString( '(3 children)', $prompt );
		$this->assertStringContainsString( '+2 more children not shown', $prompt );
	}

	public function test_block_prompt_omits_interior_section_for_leaf_blocks(): void {
		$prompt = Prompt::build_user(
			[
				'block'         => [ 'name' => 'core/paragraph' ],
				'blockInterior' => [],
			]
		);

		$this->assertStringNotContainsString( '## Inside this block', $prompt );
	}

	public function test_block_prompt_omits_shortfall_notice_when_all_children_are_shown(): void {
		$prompt = Prompt::build_user(
			[
				'block'         => [
					'name'       => 'core/group',
					'childCount' => 1,
				],
				'blockInterior' => [
					[
						'block' => 'core/paragraph',
						'title' => 'Paragraph',
					],
				],
			]
		);

		$this->assertStringContainsString( '## Inside this block', $prompt );
		$this->assertStringNotContainsString( 'Showing 1 of 1', $prompt );
	}

	public function test_block_prompt_does_not_add_visual_hints_to_the_structural_branch(): void {
		$prompt = Prompt::build_user(
			[
				'block'            => [ 'name' => 'core/paragraph' ],
				'structuralBranch' => [
					[
						'block'       => 'core/group',
						'title'       => 'Group',
						'visualHints' => [ 'textColor' => 'contrast' ],
					],
				],
			]
		);

		// Visual hints are an interior-only affordance; the branch rendering must
		// stay byte-identical to its previous output.
		$this->assertStringContainsString( '## Structural branch', $prompt );
		$this->assertStringNotContainsString( 'text:contrast', $prompt );
	}

	public function test_block_prompt_inlines_fluid_font_sizing_from_structured_presets(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [ 'name' => 'core/heading' ],
				'themeTokens' => [
					// Server-collected shape: the flat list drops fluid entirely,
					// so it must be read off the structured preset instead.
					'fontSizes'       => [ 'large: 2rem' ],
					'fontSizePresets' => [
						[
							'slug'  => 'large',
							'size'  => '2rem',
							'fluid' => [
								'min' => '1.5rem',
								'max' => '2.5rem',
							],
						],
					],
				],
			]
		);

		$this->assertStringContainsString( 'Font sizes: large: 2rem (fluid:', $prompt );
		$this->assertStringContainsString( '"min":"1.5rem"', $prompt );
	}

	public function test_block_prompt_renders_all_duotone_stops_not_just_the_first_two(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [ 'name' => 'core/image' ],
				'themeTokens' => [
					'duotonePresets' => [
						[
							'slug'   => 'triple',
							'colors' => [ '#000000', '#888888', '#ffffff' ],
						],
					],
				],
			]
		);

		$this->assertStringContainsString(
			'Duotone presets: triple: #000000 / #888888 / #ffffff',
			$prompt
		);
	}

	public function test_block_prompt_falls_back_to_flat_lists_when_structured_presets_are_malformed(): void {
		// External/MCP callers may send a preset family as slug-only scalars.
		// A malformed structured payload must not blank the family, which would
		// also read to the model as "family empty" and license raw hex values.
		$prompt = Prompt::build_user(
			[
				'block'       => [ 'name' => 'core/group' ],
				'themeTokens' => [
					'colors'       => [ 'accent: #ffffff', 'base: #000000' ],
					'colorPresets' => [ 'accent', 'base' ],
				],
			]
		);

		$this->assertStringContainsString(
			'Colors: accent: #ffffff, base: #000000',
			$prompt
		);
	}

	public function test_block_prompt_falls_back_to_flat_lists_when_structured_presets_are_absent(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [ 'name' => 'core/group' ],
				'themeTokens' => [ 'colors' => [ 'accent: #ff5500' ] ],
			]
		);

		$this->assertStringContainsString( 'Colors: accent: #ff5500', $prompt );
	}

	public function test_block_prompt_shortens_theme_token_families_instead_of_dropping_them(): void {
		$budget_filter = static fn(): int => 2000;
		add_filter( 'flavor_agent_prompt_budget_max_tokens', $budget_filter, 10 );

		try {
			$prompt = Prompt::build_user(
				[
					'block'       => [ 'name' => 'core/paragraph' ],
					'themeTokens' => [ 'colors' => $this->buildTokenSequence( 'color', 400 ) ],
				],
				'Tighten the copy.'
			);
		} finally {
			remove_filter( 'flavor_agent_prompt_budget_max_tokens', $budget_filter, 10 );
		}

		// The family is shortened and says so, rather than vanishing wholesale and
		// leaving the model to infer that no colour presets exist.
		$this->assertStringContainsString( '## Theme token values', $prompt );
		$this->assertStringContainsString( 'of 400): ', $prompt );
		$this->assertStringContainsString( 'color-00', $prompt );
		$this->assertStringNotContainsString( 'color-399', $prompt );
	}

	public function test_block_prompt_keeps_theme_capabilities_above_the_value_inventory(): void {
		$budget_filter = static fn(): int => 2000;
		add_filter( 'flavor_agent_prompt_budget_max_tokens', $budget_filter, 10 );

		// Values long enough that even the smallest ladder step overflows the whole
		// budget, so the inventory genuinely cannot be rendered at any size.
		$bulky = [];
		for ( $index = 0; $index < 40; $index++ ) {
			$bulky[] = sprintf( 'color-%02d: %s', $index, str_repeat( 'x', 4000 ) );
		}

		try {
			$prompt = Prompt::build_user(
				[
					'block'       => [ 'name' => 'core/paragraph' ],
					'themeTokens' => [
						'colors'          => $bulky,
						'enabledFeatures' => [ 'padding' => false ],
						'layout'          => [ 'contentSize' => '640px' ],
					],
				],
				'Tighten the copy.'
			);
		} finally {
			remove_filter( 'flavor_agent_prompt_budget_max_tokens', $budget_filter, 10 );
		}

		// Capability constraints are correctness-bearing; the value inventory is
		// only grounding. Under extreme pressure the former must outlive the latter.
		$this->assertStringContainsString( 'Theme feature flags: {"padding":false}', $prompt );
		$this->assertStringContainsString( 'Layout:', $prompt );
		$this->assertStringNotContainsString( '## Theme token values', $prompt );
	}

	public function test_block_prompt_renders_content_attribute_schema(): void {
		$prompt = Prompt::build_user(
			[
				'block' => [
					'name'              => 'core/paragraph',
					'contentAttributes' => [
						'content' => [
							'type' => 'string',
							'role' => 'content',
						],
					],
					'configAttributes'  => [
						'align' => [ 'type' => 'string' ],
					],
				],
			]
		);

		$this->assertStringContainsString( 'Content attribute schema (role=content):', $prompt );
		$this->assertStringContainsString( 'Config attribute schema:', $prompt );
	}

	public function test_block_prompt_names_editable_content_attributes_in_content_only_mode(): void {
		$prompt = Prompt::build_user(
			[
				'block' => [
					'name'              => 'core/paragraph',
					'editingMode'       => 'contentOnly',
					'contentAttributes' => [
						'content' => [
							'type' => 'string',
							'role' => 'content',
						],
					],
				],
			]
		);

		$this->assertStringContainsString( 'Editable content attributes: ["content"]', $prompt );
	}

	public function test_block_prompt_keeps_inner_block_content_notice_when_no_content_attributes(): void {
		$prompt = Prompt::build_user(
			[
				'block' => [
					'name'                => 'core/buttons',
					'editingMode'         => 'contentOnly',
					'supportsContentRole' => true,
					'contentAttributes'   => [],
				],
			]
		);

		// The pre-existing arm must stay reachable: uses_inner_blocks_as_content()
		// keys off the same condition to drive a different post-generation filter.
		$this->assertStringContainsString( 'supports.contentRole', $prompt );
		$this->assertStringNotContainsString( 'Editable content attributes:', $prompt );
	}

	public function test_block_prompt_discloses_preset_policy_in_the_required_section(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [ 'name' => 'core/group' ],
				'themeTokens' => [ 'colors' => [ 'accent: #ff5500' ] ],
			],
			'',
			[],
			[
				'presetSlugs' => [
					'color'    => [ 'accent' ],
					'gradient' => [],
					'shadow'   => [],
				],
			]
		);

		$this->assertStringContainsString( '"presetPolicy"', $prompt );
		$this->assertStringContainsString( '"closedFamilies":["color"]', $prompt );
		$this->assertStringContainsString( '"freeformFamilies":["gradient","shadow"]', $prompt );
	}

	public function test_block_prompt_omits_preset_policy_when_contract_has_no_preset_slugs(): void {
		$prompt = Prompt::build_user(
			[ 'block' => [ 'name' => 'core/group' ] ],
			'',
			[],
			[ 'allowedPanels' => [ 'color' ] ]
		);

		$this->assertStringNotContainsString( 'presetPolicy', $prompt );
	}

	public function test_block_system_prompt_states_the_preset_css_variable_convention(): void {
		$prompt = Prompt::build_system();

		$this->assertStringContainsString( 'var(--wp--preset--{family}--{slug})', $prompt );
		$this->assertStringContainsString( 'font-size, not fontSize', $prompt );
		$this->assertStringContainsString( 'presetPolicy.freeformFamilies', $prompt );
		$this->assertStringContainsString(
			'is not an empty family',
			$prompt
		);
	}

	private function buildTokenSequence( string $prefix, int $count ): array {
		$tokens = [];

		for ( $index = 0; $index < $count; $index++ ) {
			$tokens[] = sprintf( '%s-%02d', $prefix, $index );
		}

		return $tokens;
	}

	private function buildAllowedPatterns( int $count ): array {
		$patterns = [];

		for ( $index = 0; $index < $count; $index++ ) {
			$patterns[] = [
				'name'           => sprintf( 'theme/pattern-%02d', $index ),
				'title'          => sprintf( 'Pattern %02d', $index ),
				'source'         => 'theme',
				'categories'     => [ 'featured' ],
				'blockTypes'     => [ 'core/paragraph' ],
				'allowedActions' => [ 'insert_after' ],
			];
		}

		return $patterns;
	}
}
