<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Guidelines;
use FlavorAgent\LLM\StylePrompt;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class StylePromptTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_context(): array {
		return [
			'scope'        => [
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
				'postType'       => 'wp_global_styles',
				'entityId'       => '17',
				'entityKind'     => 'root',
				'entityName'     => 'globalStyles',
				'stylesheet'     => 'theme-slug',
				'templateSlug'   => 'theme-slug//home',
				'templateType'   => 'home',
			],
			'styleContext' => [
				'currentConfig'         => [
					'styles' => [],
				],
				'mergedConfig'          => [
					'styles' => [
						'color' => [
							'background' => '#000000',
							'text'       => '#000000',
						],
					],
				],
				'themeTokenDiagnostics' => [
					'source'      => 'stable',
					'settingsKey' => 'features',
					'reason'      => 'stable-parity',
				],
				'themeTokens'           => [
					'colors'            => [ 'accent: #ff5500' ],
					'colorPresets'      => [
						[
							'slug'  => 'accent',
							'color' => '#ff5500',
						],
					],
					'gradients'         => [ 'sunset: linear-gradient(135deg,#f60,#fc0)' ],
					'fontSizes'         => [ 'body: 1rem' ],
					'fontFamilies'      => [ 'display: Georgia, serif' ],
					'spacing'           => [ 's: 0.5rem' ],
					'shadows'           => [ 'soft: 0 10px 30px rgba(0,0,0,0.1)' ],
					'duotone'           => [ 'midnight: #111111 / #f5f5f5' ],
					'duotonePresets'    => [
						[
							'slug'   => 'midnight',
							'colors' => [ '#111111', '#f5f5f5' ],
						],
					],
					'layout'            => [
						'content'      => '680px',
						'wide'         => '1200px',
						'allowEditing' => false,
					],
					'diagnostics'       => [
						'source' => 'server',
						'reason' => 'server-global-settings',
					],
					'enabledFeatures'   => [
						'customColors' => true,
					],
					'elementStyles'     => [],
					'blockPseudoStyles' => [
						'core/button' => [
							':hover' => [
								'color' => [
									'text' => 'var(--wp--preset--color--accent)',
								],
							],
						],
					],
				],
				'supportedStylePaths'   => [
					[
						'path'        => [ 'color', 'background' ],
						'valueSource' => 'color',
					],
					[
						'path'        => [ 'typography', 'lineHeight' ],
						'valueSource' => 'freeform',
					],
					[
						'path'        => [ 'border', 'radius' ],
						'valueSource' => 'freeform',
					],
					[
						'path'        => [ 'border', 'style' ],
						'valueSource' => 'freeform',
					],
					[
						'path'        => [ 'border', 'width' ],
						'valueSource' => 'freeform',
					],
				],
				'availableVariations'   => [
					[
						'title'    => 'Default',
						'settings' => [],
						'styles'   => [],
					],
					[
						'title'       => 'Midnight',
						'description' => 'Dark editorial palette',
						'settings'    => [],
						'styles'      => [
							'color' => [
								'background' => 'var:preset|color|accent',
							],
						],
					],
				],
				'activeVariationIndex'  => 0,
				'activeVariationTitle'  => 'Default',
				'templateStructure'     => [
					[
						'name'        => 'core/template-part',
						'innerBlocks' => [
							[
								'name' => 'core/site-title',
							],
						],
					],
				],
				'designSemantics'       => [
					'surface'            => 'global-styles',
					'templateType'       => 'home',
					'sectionCount'       => 2,
					'overallDensityHint' => 'balanced',
					'locationSummary'    => [
						[
							'value' => 'header',
							'count' => 1,
						],
						[
							'value' => 'content',
							'count' => 1,
						],
					],
					'roleSummary'        => [
						[
							'value' => 'header-slot',
							'count' => 1,
						],
						[
							'value' => 'main-query',
							'count' => 1,
						],
					],
					'sections'           => [
						[
							'path'             => [ 0 ],
							'block'            => 'core/template-part',
							'label'            => 'Header slot',
							'role'             => 'header-slot',
							'location'         => 'header',
							'templateArea'     => 'header',
							'templatePartSlug' => 'header',
							'childRoles'       => [ 'header-site-title' ],
							'emphasisHint'     => 'primary',
							'densityHint'      => 'airy',
							'hiddenViewports'  => [],
							'visibleViewports' => [],
						],
					],
				],
			],
		];
	}

	public function test_build_user_does_not_inject_site_guidelines(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_SITE   => 'Use a restrained operational tone.',
			Guidelines::OPTION_BLOCKS => [
				'core/paragraph' => 'Keep body text compact.',
			],
		];

		$context                       = $this->build_context();
		$context['scope']['surface']   = 'style-book';
		$context['scope']['blockName'] = 'core/paragraph';

		$prompt = StylePrompt::build_user( $context );

		$this->assertStringNotContainsString( '## Site Guidelines', $prompt );
		$this->assertStringNotContainsString( 'Use a restrained operational tone.', $prompt );
		$this->assertStringNotContainsString( 'Keep body text compact.', $prompt );
	}

	public function test_build_user_includes_scope_diagnostics_and_variations(): void {
		$prompt = StylePrompt::build_user(
			$this->build_context(),
			'Make the site feel warmer.'
		);

		$this->assertStringContainsString( 'global_styles:17', $prompt );
		$this->assertStringContainsString( 'Post type: wp_global_styles', $prompt );
		$this->assertStringContainsString( 'Entity kind: root', $prompt );
		$this->assertStringContainsString( 'Entity name: globalStyles', $prompt );
		$this->assertStringContainsString( 'Template slug: theme-slug//home', $prompt );
		$this->assertStringContainsString( 'Template type: home', $prompt );
		$this->assertStringContainsString( 'stable-parity', $prompt );
		$this->assertStringContainsString( 'server-global-settings', $prompt );
		$this->assertStringContainsString( 'color.background', $prompt );
		$this->assertStringContainsString( 'Gradients: sunset: linear-gradient(135deg,#f60,#fc0)', $prompt );
		$this->assertStringContainsString( 'Duotone presets: midnight: #111111 / #f5f5f5', $prompt );
		$this->assertStringContainsString( 'Block pseudo-class styles:', $prompt );
		$this->assertStringContainsString( 'Active variation: #0 Default', $prompt );
		$this->assertStringContainsString( '#1 Midnight - Dark editorial palette', $prompt );
		$this->assertStringContainsString( 'styles: {"color":{"background":"var:preset|color|accent"}}', $prompt );
		$this->assertStringContainsString( '## Current template structure', $prompt );
		$this->assertStringContainsString( '## Design semantic context', $prompt );
		$this->assertStringContainsString( 'Overall density hint: balanced', $prompt );
		$this->assertStringContainsString( 'template part `header`', $prompt );
	}

	public function test_build_user_trims_global_style_configs_to_model_relevant_branches(): void {
		$context = $this->build_context();

		$context['styleContext']['currentConfig'] = [
			'settings' => [
				'color'      => [
					'palette' => [
						'custom' => false,
					],
				],
				'typography' => [
					'fontSizes' => [],
				],
				'spacing'    => [
					'units' => [ 'px' ],
				],
				'layout'     => [
					'contentSize' => 'should-not-include-layout',
				],
			],
			'styles'   => [
				'color' => [
					'background' => '#ffffff',
				],
			],
			'_links'   => [
				'self' => [
					[
						'href' => 'should-not-include-link',
					],
				],
			],
		];

		$context['styleContext']['mergedConfig'] = [
			'settings' => [
				'color'   => [
					'background' => true,
				],
				'custom'  => [
					'flag' => 'should-not-include-custom',
				],
				'spacing' => [
					'blockGap' => true,
				],
			],
			'styles'   => [
				'typography' => [
					'lineHeight' => '1.5',
				],
			],
		];

		$prompt = StylePrompt::build_user( $context );

		$this->assertStringContainsString( '"palette":{"custom":false}', $prompt );
		$this->assertStringContainsString( '"units":["px"]', $prompt );
		$this->assertStringContainsString( '"styles":{"color":{"background":"#ffffff"}}', $prompt );
		$this->assertStringContainsString( '"blockGap":true', $prompt );
		$this->assertStringContainsString( '"styles":{"typography":{"lineHeight":"1.5"}}', $prompt );
		$this->assertStringNotContainsString( 'should-not-include-layout', $prompt );
		$this->assertStringNotContainsString( 'should-not-include-link', $prompt );
		$this->assertStringNotContainsString( 'should-not-include-custom', $prompt );
	}

	public function test_build_user_includes_style_book_target_context(): void {
		$context                                    = $this->build_context();
		$context['scope']['surface']                = 'style-book';
		$context['scope']['scopeKey']               = 'style_book:17:core/paragraph';
		$context['scope']['blockName']              = 'core/paragraph';
		$context['scope']['blockTitle']             = 'Paragraph';
		$context['styleContext']['blockManifest']   = [
			'supports'        => [
				'color' => [
					'text' => true,
				],
			],
			'inspectorPanels' => [
				'color' => true,
			],
		];
		$context['styleContext']['styleBookTarget'] = [
			'blockName'     => 'core/paragraph',
			'blockTitle'    => 'Paragraph',
			'description'   => 'Primary intro copy block.',
			'currentStyles' => [
				'color' => [
					'text' => 'var:preset|color|accent',
				],
			],
			'mergedStyles'  => [
				'typography' => [
					'fontSize' => 'var:preset|font-size|body',
				],
			],
		];
		$context['styleContext']['designSemantics'] = [
			'surface'                => 'style-book',
			'templateType'           => 'home',
			'targetBlockName'        => 'core/paragraph',
			'targetBlockTitle'       => 'Paragraph',
			'occurrenceCount'        => 1,
			'sampledOccurrenceCount' => 1,
			'omittedOccurrenceCount' => 0,
			'confidence'             => 'high',
			'dominantRole'           => 'footer-paragraph',
			'dominantLocation'       => 'footer',
			'densitySummary'         => [
				[
					'value' => 'balanced',
					'count' => 1,
				],
			],
			'emphasisSummary'        => [
				[
					'value' => 'supporting',
					'count' => 1,
				],
			],
			'occurrences'            => [
				[
					'path'             => [ 0, 1 ],
					'block'            => 'core/paragraph',
					'label'            => 'Footer paragraph',
					'role'             => 'footer-paragraph',
					'location'         => 'footer',
					'templateArea'     => 'footer',
					'templatePartSlug' => 'footer',
					'emphasisHint'     => 'supporting',
					'densityHint'      => 'balanced',
					'nearbyBlocks'     => [
						'before' => [
							[
								'block' => 'core/heading',
								'label' => 'Heading',
							],
						],
						'after'  => [
							[
								'block' => 'core/buttons',
								'label' => 'Buttons',
							],
						],
					],
				],
			],
		];

		$prompt = StylePrompt::build_user(
			$context,
			'Tune this intro block.'
		);

		$this->assertStringContainsString( 'Surface: style-book', $prompt );
		$this->assertStringContainsString( 'Block name: core/paragraph', $prompt );
		$this->assertStringContainsString( 'Primary intro copy block.', $prompt );
		$this->assertStringContainsString( '## Target block supports', $prompt );
		$this->assertStringContainsString( 'Inspector panels: {"color":true}', $prompt );
		$this->assertStringContainsString( '"fontSize":"var:preset|font-size|body"', $prompt );
		$this->assertStringContainsString( 'Matching template occurrences: 1', $prompt );
		$this->assertStringContainsString( 'Dominant role hint: `footer-paragraph`', $prompt );
		$this->assertStringContainsString( 'before `Heading`; after `Buttons`', $prompt );
		$this->assertStringNotContainsString( '## Available theme style variations', $prompt );
		$this->assertStringNotContainsString( 'Active variation:', $prompt );
	}

	public function test_build_user_includes_docs_guidance_when_available(): void {
		$prompt = StylePrompt::build_user(
			$this->build_context(),
			'Make the site feel warmer.',
			[
				[
					'title'   => 'Global styles reference',
					'excerpt' => 'Use theme.json preset families for color and typography changes.',
				],
			]
		);

		$this->assertStringContainsString( '## WordPress Developer Guidance', $prompt );
		$this->assertStringContainsString( 'Global styles reference: Use theme.json preset families for color and typography changes.', $prompt );
	}

	public function test_build_user_includes_template_visibility_constraints(): void {
		$context                                       = $this->build_context();
		$context['styleContext']['templateVisibility'] = [
			'hasVisibilityRules' => true,
			'blockCount'         => 1,
			'blocks'             => [
				[
					'path'             => [ 1, 0 ],
					'name'             => 'core/query-title',
					'label'            => 'Query Title',
					'hiddenViewports'  => [ 'mobile' ],
					'visibleViewports' => [ 'desktop' ],
				],
			],
		];

		$prompt = StylePrompt::build_user(
			$context,
			'Keep the archive title feeling deliberate.'
		);

		$this->assertStringContainsString( '## Viewport visibility constraints', $prompt );
		$this->assertStringContainsString( 'Path 2 > 1 - `Query Title`', $prompt );
		$this->assertStringContainsString( 'hidden on `mobile`', $prompt );
		$this->assertStringContainsString( 'explicitly visible on `desktop`', $prompt );
	}

	public function test_build_system_includes_pair_guidance_for_color_ops(): void {
		$system = StylePrompt::build_system();

		$this->assertStringContainsString( 'pairing foreground and background operations', $system );
		$this->assertStringContainsString( 'downgraded to advisory', $system );
	}

	public function test_parse_response_filters_unsafe_style_operations(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Use accent canvas',
							'description' => 'Apply the accent preset to the background.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|accent',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'accent',
									'cssVar'     => 'var(--wp--preset--color--accent)',
								],
								[
									'type'      => 'set_styles',
									'path'      => [ 'customCSS' ],
									'value'     => 'body{color:red}',
									'valueType' => 'freeform',
								],
							],
						],
						[
							'label'       => 'Unsafe color',
							'description' => 'Uses an unknown preset.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|unknown',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'unknown',
								],
							],
						],
					],
					'explanation' => 'Prefer preset-backed color changes.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['suggestions'] );
		$this->assertSame( 'advisory', $result['suggestions'][0]['tone'] );
		$this->assertSame( [], $result['suggestions'][0]['operations'] );
		$this->assertSame( 'advisory', $result['suggestions'][1]['tone'] );
		$this->assertSame( [], $result['suggestions'][1]['operations'] );
	}

	public function test_parse_response_downgrades_partial_style_operation_sequences_to_advisory(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Pair foreground and canvas',
							'description' => 'A paired color change should not become a partial executable update.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|accent',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'accent',
								],
								[
									'type'      => 'set_styles',
									'path'      => [ 'customCSS' ],
									'value'     => 'body{color:red}',
									'valueType' => 'freeform',
								],
							],
						],
					],
					'explanation' => 'Partial operation groups are not review-safe.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'advisory', $result['suggestions'][0]['tone'] );
		$this->assertSame( [], $result['suggestions'][0]['operations'] );
	}

	public function test_parse_response_preserves_camel_case_style_paths(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Scale body type',
							'description' => 'Use the preset-backed body size.',
							'category'    => 'typography',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'typography', 'fontSize' ],
									'value'      => 'var:preset|font-size|body',
									'valueType'  => 'preset',
									'presetType' => 'font-size',
									'presetSlug' => 'body',
								],
							],
						],
					],
					'explanation' => 'Prefer preset-backed typography changes.',
				]
			),
			[
				'scope'        => [
					'scopeKey'       => 'global_styles:17',
					'globalStylesId' => '17',
					'stylesheet'     => 'theme-slug',
				],
				'styleContext' => [
					'themeTokens'         => [
						'fontSizes' => [ 'body: 1rem' ],
					],
					'supportedStylePaths' => [
						[
							'path'        => [ 'typography', 'fontSize' ],
							'valueSource' => 'font-size',
						],
					],
					'availableVariations' => [],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'executable', $result['suggestions'][0]['tone'] );
		$this->assertSame(
			[ 'typography', 'fontSize' ],
			$result['suggestions'][0]['operations'][0]['path'] ?? null
		);
	}

	public function test_parse_response_rejects_mismatched_preset_value_and_slug(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Use accent canvas',
							'description' => 'Apply the accent preset to the background.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|contrast',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'accent',
								],
							],
						],
					],
					'explanation' => 'Prefer preset-backed color changes.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'advisory', $result['suggestions'][0]['tone'] );
		$this->assertSame( [], $result['suggestions'][0]['operations'] );
	}

	public function test_parse_response_moves_theme_variations_before_style_overrides(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Adopt Midnight with roomier text',
							'description' => 'Start from the Midnight variation, then loosen the line height.',
							'category'    => 'variation',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'typography', 'lineHeight' ],
									'value'      => '1.6',
									'valueType'  => 'freeform',
									'presetType' => '',
									'presetSlug' => '',
								],
								[
									'type'           => 'set_theme_variation',
									'variationIndex' => 1,
									'variationTitle' => 'Midnight',
								],
							],
						],
					],
					'explanation' => 'Use the preset-backed Midnight variation as the base.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[ 'set_theme_variation', 'set_styles' ],
			array_column( $result['suggestions'][0]['operations'] ?? [], 'type' )
		);
		$this->assertSame(
			'Midnight',
			$result['suggestions'][0]['operations'][0]['variationTitle'] ?? null
		);
	}

	public function test_parse_response_accepts_title_only_theme_variation_operations(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Switch to Midnight',
							'description' => 'Use the Midnight variation as the new base.',
							'category'    => 'variation',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'           => 'set_theme_variation',
									'variationTitle' => 'Midnight',
								],
							],
						],
					],
					'explanation' => 'Use the preset-backed Midnight variation.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'executable', $result['suggestions'][0]['tone'] );
		$this->assertSame(
			[
				'type'           => 'set_theme_variation',
				'variationIndex' => 1,
				'variationTitle' => 'Midnight',
			],
			$result['suggestions'][0]['operations'][0]
		);
	}

	public function test_parse_response_downgrades_theme_variation_mixed_with_color_override(): void {
		$parsed = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Adopt Midnight and recolor canvas',
							'description' => 'Start from the Midnight variation, then recolor the canvas.',
							'category'    => 'variation',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'           => 'set_theme_variation',
									'variationIndex' => 1,
									'variationTitle' => 'Midnight',
								],
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|accent',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'accent',
								],
							],
						],
					],
					'explanation' => 'Use the preset-backed Midnight variation as the base.',
				]
			),
			$this->build_context()
		);

		$this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
		$this->assertSame( [], $parsed['suggestions'][0]['operations'] );
		$this->assertStringContainsString(
			'theme variation',
			$parsed['suggestions'][0]['description']
		);
	}

	public function test_parse_response_accepts_valid_block_style_operations_for_style_book_scope(): void {
		$context                                        = $this->build_context();
		$context['scope']['surface']                    = 'style-book';
		$context['scope']['blockName']                  = 'core/paragraph';
		$context['styleContext']['styleBookTarget']     = [
			'blockName' => 'core/paragraph',
		];
		$context['styleContext']['supportedStylePaths'] = [
			[
				'path'        => [ 'color', 'text' ],
				'valueSource' => 'color',
			],
		];

		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Warm the intro copy',
							'description' => 'Use the accent preset on the target block text.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_block_styles',
									'blockName'  => 'core/paragraph',
									'path'       => [ 'color', 'text' ],
									'value'      => 'var:preset|color|accent',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'accent',
								],
							],
						],
					],
					'explanation' => 'Use a preset-backed block text color.',
				]
			),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'executable', $result['suggestions'][0]['tone'] );
		$this->assertSame(
			'set_block_styles',
			$result['suggestions'][0]['operations'][0]['type'] ?? null
		);
		$this->assertSame(
			'core/paragraph',
			$result['suggestions'][0]['operations'][0]['blockName'] ?? null
		);
	}

	public function test_parse_response_rejects_site_level_style_operations_inside_style_book_scope(): void {
		$context                                        = $this->build_context();
		$context['scope']['surface']                    = 'style-book';
		$context['scope']['blockName']                  = 'core/paragraph';
		$context['styleContext']['styleBookTarget']     = [
			'blockName' => 'core/paragraph',
		];
		$context['styleContext']['supportedStylePaths'] = [
			[
				'path'        => [ 'color', 'text' ],
				'valueSource' => 'color',
			],
		];

		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Unsafe scope mismatch',
							'description' => 'This should be downgraded because it targets site styles.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'text' ],
									'value'      => 'var:preset|color|accent',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'accent',
								],
							],
						],
					],
					'explanation' => 'Style Book scope must stay block-relative.',
				]
			),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'advisory', $result['suggestions'][0]['tone'] );
		$this->assertSame( [], $result['suggestions'][0]['operations'] );
	}

	public function test_parse_response_rejects_theme_variation_operations_inside_style_book_scope(): void {
		$context                                    = $this->build_context();
		$context['scope']['surface']                = 'style-book';
		$context['scope']['blockName']              = 'core/paragraph';
		$context['styleContext']['styleBookTarget'] = [
			'blockName' => 'core/paragraph',
		];

		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Switch to Midnight',
							'description' => 'This should stay advisory inside Style Book.',
							'category'    => 'variation',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'           => 'set_theme_variation',
									'variationIndex' => 1,
									'variationTitle' => 'Midnight',
								],
							],
						],
					],
					'explanation' => 'Theme variations are site-wide changes.',
				]
			),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'advisory', $result['suggestions'][0]['tone'] );
		$this->assertSame( [], $result['suggestions'][0]['operations'] );
	}

	public function test_parse_response_rejects_invalid_freeform_style_values(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Set an unsafe line height',
							'description' => 'This should be downgraded to advisory.',
							'category'    => 'typography',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'      => 'set_styles',
									'path'      => [ 'typography', 'lineHeight' ],
									'value'     => [ 'amount' => 1.6 ],
									'valueType' => 'freeform',
								],
								[
									'type'      => 'set_styles',
									'path'      => [ 'border', 'style' ],
									'value'     => 'glow',
									'valueType' => 'freeform',
								],
							],
						],
					],
					'explanation' => 'Invalid freeform values should not be executable.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'advisory', $result['suggestions'][0]['tone'] );
		$this->assertSame( [], $result['suggestions'][0]['operations'] );
	}

	public function test_parse_response_accepts_valid_freeform_style_values(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Refine the frame',
							'description' => 'Tighten line height and border rhythm.',
							'category'    => 'border',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'      => 'set_styles',
									'path'      => [ 'typography', 'lineHeight' ],
									'value'     => 1.6,
									'valueType' => 'freeform',
								],
								[
									'type'      => 'set_styles',
									'path'      => [ 'border', 'radius' ],
									'value'     => '12%',
									'valueType' => 'freeform',
								],
								[
									'type'      => 'set_styles',
									'path'      => [ 'border', 'style' ],
									'value'     => ' Dashed ',
									'valueType' => 'freeform',
								],
								[
									'type'      => 'set_styles',
									'path'      => [ 'border', 'width' ],
									'value'     => '2px',
									'valueType' => 'freeform',
								],
							],
						],
					],
					'explanation' => 'Validated freeform values should remain executable.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'executable', $result['suggestions'][0]['tone'] );
		$this->assertCount( 4, $result['suggestions'][0]['operations'] );
		$this->assertSame( 1.6, $result['suggestions'][0]['operations'][0]['value'] ?? null );
		$this->assertSame( '12%', $result['suggestions'][0]['operations'][1]['value'] ?? null );
		$this->assertSame( 'dashed', $result['suggestions'][0]['operations'][2]['value'] ?? null );
		$this->assertSame( '2px', $result['suggestions'][0]['operations'][3]['value'] ?? null );
	}

	public function test_parse_response_accepts_css_custom_properties_for_freeform_style_values(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Use the theme spacing token',
							'description' => 'Theme variables should remain executable on freeform style paths.',
							'category'    => 'border',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'      => 'set_styles',
									'path'      => [ 'border', 'radius' ],
									'value'     => '  var(--wp--preset--spacing--20)  ',
									'valueType' => 'freeform',
								],
							],
						],
					],
					'explanation' => 'Client and server validation must both accept theme custom properties.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'executable', $result['suggestions'][0]['tone'] );
		$this->assertSame(
			'var(--wp--preset--spacing--20)',
			$result['suggestions'][0]['operations'][0]['value'] ?? null
		);
	}

	public function test_parse_response_rejects_numeric_border_lengths_that_the_client_cannot_apply(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Round the frame',
							'description' => 'Numeric border lengths should be downgraded.',
							'category'    => 'border',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'      => 'set_styles',
									'path'      => [ 'border', 'radius' ],
									'value'     => 12,
									'valueType' => 'freeform',
								],
								[
									'type'      => 'set_styles',
									'path'      => [ 'border', 'width' ],
									'value'     => 2,
									'valueType' => 'freeform',
								],
							],
						],
					],
					'explanation' => 'Client and server validation must stay aligned.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'advisory', $result['suggestions'][0]['tone'] );
		$this->assertSame( [], $result['suggestions'][0]['operations'] );
	}

	public function test_parse_response_blends_model_score_with_deterministic_quality_for_sorting(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'High model advisory',
							'description' => 'This should sort second after deterministic validation.',
							'category'    => 'color',
							'ranking'     => [
								'score' => 0.9,
							],
						],
						[
							'label'       => 'Lower model executable preset',
							'description' => 'This has stronger deterministic evidence.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|accent',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'accent',
								],
							],
							'score'       => 0.55,
						],
					],
					'explanation' => 'Deterministic quality should blend with model scores.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Lower model executable preset', $result['suggestions'][0]['label'] );
		$this->assertGreaterThan( $result['suggestions'][1]['ranking']['score'], $result['suggestions'][0]['ranking']['score'] );
	}

	public function test_parse_response_falls_back_when_nested_ranking_score_is_malformed(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Fallback confidence suggestion',
							'description' => 'A malformed nested score should not zero this out.',
							'category'    => 'color',
							'ranking'     => [
								'score' => [],
							],
							'confidence'  => 0.87,
						],
						[
							'label'       => 'Lower confidence suggestion',
							'description' => 'This should sort second.',
							'category'    => 'color',
							'confidence'  => 0.81,
						],
					],
					'explanation' => 'Malformed nested scores should not suppress valid fallback confidence.',
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Fallback confidence suggestion', $result['suggestions'][0]['label'] );
		$this->assertSame( 0.708, $result['suggestions'][0]['ranking']['score'] );
	}

	public function test_parse_response_merges_model_source_signals_with_plugin_ranking_signals(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Concrete style update with model signal',
							'description' => 'Keep model style context without losing plugin diagnostics.',
							'category'    => 'border',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'      => 'set_styles',
									'path'      => [ 'border', 'width' ],
									'value'     => '2px',
									'valueType' => 'freeform',
								],
							],
							'ranking'     => [
								'score'         => 0.61,
								'sourceSignals' => [ 'model_style_balance' ],
							],
						],
					],
				]
			),
			$this->build_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[ 'llm_response', 'style_surface', 'tone_executable', 'has_operations', 'model_style_balance' ],
			$result['suggestions'][0]['ranking']['sourceSignals']
		);
	}

	public function test_parse_response_records_design_validator_typography_and_preset_signals(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Raw typography value',
							'description' => 'Use a one-off line-height value for the section.',
							'category'    => 'typography',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'      => 'set_styles',
									'path'      => [ 'typography', 'lineHeight' ],
									'value'     => '1.2',
									'valueType' => 'freeform',
								],
							],
							'ranking'     => [
								'score' => 0.81,
							],
						],
					],
				]
			),
			[
				'scope'        => [
					'surface' => 'global-styles',
				],
				'styleContext' => [
					'themeTokens'         => [
						'colors'        => [],
						'colorPresets'  => [],
						'elementStyles' => [],
					],
					'mergedConfig'        => [ 'styles' => [] ],
					'currentConfig'       => [
						'styles'   => [],
						'settings' => [],
					],
					'supportedStylePaths' => [
						[
							'path'        => [ 'typography', 'lineHeight' ],
							'valueSource' => 'freeform',
						],
					],
				],
			],
			[
				'surface' => 'style',
				'prompt'  => 'Keep typography aligned to the theme scale.',
			]
		);

		$this->assertIsArray( $result );

		$suggestion = $result['suggestions'][0];
		$codes      = array_column( $suggestion['validationReasons'] ?? [], 'code' );

		$this->assertFalse( $suggestion['qualitySignals']['presetBacked'] ?? true );
		$this->assertContains( 'raw_value_when_preset_available', $codes );
		$this->assertContains( 'design_validator_v1', $suggestion['ranking']['sourceSignals'] ?? [] );
		$this->assertArrayHasKey( 'raw_value_when_preset_available', $suggestion['ranking']['contextPenalties'] ?? [] );
	}

	public function test_parse_response_records_positive_contrast_signal_when_paired_color_ops_pass(): void {
		$result = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Readable color pair',
							'description' => 'Use the high contrast pair.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|base',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'base',
								],
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'text' ],
									'value'      => 'var:preset|color|contrast',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'contrast',
								],
							],
							'ranking'     => [
								'score' => 0.77,
							],
						],
					],
				]
			),
			[
				'scope'        => [
					'surface' => 'global-styles',
				],
				'styleContext' => [
					'themeTokens'         => [
						'colors'        => [ 'base: #ffffff', 'contrast: #111111' ],
						'colorPresets'  => [
							[
								'slug'  => 'base',
								'color' => '#ffffff',
							],
							[
								'slug'  => 'contrast',
								'color' => '#111111',
							],
						],
						'elementStyles' => [],
					],
					'mergedConfig'        => [
						'styles' => [
							'color' => [
								'background' => '#ffffff',
								'text'       => '#111111',
							],
						],
					],
					'currentConfig'       => [
						'styles'   => [],
						'settings' => [],
					],
					'supportedStylePaths' => [
						[
							'path'        => [ 'color', 'background' ],
							'valueSource' => 'color',
						],
						[
							'path'        => [ 'color', 'text' ],
							'valueSource' => 'color',
						],
					],
				],
			],
			[
				'surface' => 'style',
				'prompt'  => 'Improve contrast.',
			]
		);

		$this->assertIsArray( $result );

		$suggestion = $result['suggestions'][0];

		$this->assertTrue( $suggestion['qualitySignals']['contrastPreserved'] ?? false );
		$this->assertContains( 'design_validator_v1', $suggestion['ranking']['sourceSignals'] ?? [] );
		$this->assertGreaterThan( 0.55, $suggestion['ranking']['contextEvidence']['contrast_preserved'] ?? 0.0 );
	}

	public function test_parse_response_downgrades_low_contrast_executable_suggestion_to_advisory(): void {
		$parsed = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Soft on soft',
							'description' => 'Use the wash on base.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|wash',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'wash',
								],
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'text' ],
									'value'      => 'var:preset|color|base',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'base',
								],
							],
						],
					],
					'explanation' => 'demo',
				]
			),
			$this->build_global_styles_context_with_low_contrast_palette()
		);

		$this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
		$this->assertSame( [], $parsed['suggestions'][0]['operations'] );
		$this->assertStringContainsString( 'Contrast check:', $parsed['suggestions'][0]['description'] );
	}

	public function test_parse_response_records_failed_contrast_reason_when_paired_color_ops_fail_contrast(): void {
		$parsed = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Soft on soft',
							'description' => 'Use the wash on base.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|wash',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'wash',
								],
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'text' ],
									'value'      => 'var:preset|color|base',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'base',
								],
							],
						],
					],
					'explanation' => 'demo',
				]
			),
			$this->build_global_styles_context_with_low_contrast_palette()
		);

		$this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
		$codes = array_column( $parsed['suggestions'][0]['validationReasons'] ?? [], 'code' );
		$this->assertContains(
			'failed_contrast',
			$codes,
			sprintf( 'Expected failed_contrast reason; got: %s', implode( ', ', $codes ) )
		);
		$this->assertSame(
			'downgraded',
			$parsed['suggestions'][0]['validationReasons'][ array_search( 'failed_contrast', $codes, true ) ]['severity'] ?? null
		);
	}

	public function test_parse_response_records_unknown_operation_reason_when_ops_are_emptied(): void {
		$parsed = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Inert change',
							'description' => 'An op of an unrecognized type leaves nothing executable.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'  => 'unknown_operation',
									'path'  => [ 'color', 'background' ],
									'value' => 'var:preset|color|accent',
								],
							],
						],
					],
					'explanation' => 'demo',
				]
			),
			$this->build_context()
		);

		$this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
		$this->assertSame( [], $parsed['suggestions'][0]['operations'] );
			$codes = array_column( $parsed['suggestions'][0]['validationReasons'] ?? [], 'code' );
			$this->assertContains(
				'unknown_operation_type',
				$codes,
				sprintf( 'Expected unknown_operation_type reason; got: %s', implode( ', ', $codes ) )
			);
	}

	public function test_parse_response_uses_validation_prefix_when_drop_and_contrast_both_fire(): void {
		$parsed = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Mixed bag',
							'description' => 'Two ops, one bad.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|wash',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'wash',
								],
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'text' ],
									'value'      => 'var:preset|color|nonexistent',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'nonexistent',
								],
							],
						],
					],
					'explanation' => 'demo',
				]
			),
			$this->build_global_styles_context_with_low_contrast_palette()
		);

		$this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
		$this->assertStringStartsWith( 'Two ops, one bad. Validation:', $parsed['suggestions'][0]['description'] );
		$this->assertStringNotContainsString( 'Contrast check', $parsed['suggestions'][0]['description'] );
	}

	public function test_parse_response_dedups_canonical_prefix_already_present(): void {
		$parsed = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Pre-annotated',
							'description' => 'Soft on soft. Contrast check: already noted.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|wash',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'wash',
								],
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'text' ],
									'value'      => 'var:preset|color|base',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'base',
								],
							],
						],
					],
					'explanation' => 'demo',
				]
			),
			$this->build_global_styles_context_with_low_contrast_palette()
		);

		$this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
		$this->assertSame(
			1,
			substr_count( $parsed['suggestions'][0]['description'], 'Contrast check:' )
		);
	}

	public function test_parse_response_downgraded_suggestion_has_no_has_operations_signal(): void {
		$parsed  = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Soft on soft',
							'description' => 'Use the wash on base.',
							'category'    => 'color',
							'tone'        => 'executable',
							'ranking'     => [ 'score' => 0.9 ],
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|wash',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'wash',
								],
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'text' ],
									'value'      => 'var:preset|color|base',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'base',
								],
							],
						],
					],
					'explanation' => 'demo',
				]
			),
			$this->build_global_styles_context_with_low_contrast_palette()
		);
		$signals = $parsed['suggestions'][0]['ranking']['sourceSignals'] ?? [];

		$this->assertContains( 'tone_advisory', $signals );
		$this->assertNotContains( 'has_operations', $signals );
		$this->assertNotContains( 'tone_executable', $signals );
	}

	public function test_parse_response_uses_unavailable_prefix_when_contrast_inputs_unresolved(): void {
		$context = [
			'scope'        => [
				'surface'        => 'global-styles',
				'globalStylesId' => 'gs-1',
			],
			'styleContext' => [
				'themeTokens'         => [
					'colors'        => [ 'base: #ffffff' ],
					'colorPresets'  => [
						[
							'slug'  => 'base',
							'color' => '#ffffff',
						],
					],
					'elementStyles' => [],
				],
				'mergedConfig'        => [ 'styles' => [] ],
				'currentConfig'       => [
					'styles'   => [],
					'settings' => [],
				],
				'supportedStylePaths' => [
					[
						'path'        => [ 'color', 'background' ],
						'valueSource' => 'color',
					],
					[
						'path'        => [ 'color', 'text' ],
						'valueSource' => 'color',
					],
				],
			],
		];
		$parsed  = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Solo background',
							'description' => 'Just background.',
							'category'    => 'color',
							'tone'        => 'executable',
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|base',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'base',
								],
							],
						],
					],
					'explanation' => 'demo',
				]
			),
			$context
		);

		$this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
		$this->assertSame( [], $parsed['suggestions'][0]['operations'] );
		$this->assertStringContainsString(
			'Contrast check unavailable:',
			$parsed['suggestions'][0]['description']
		);
	}

	public function test_parse_response_downgraded_suggestion_score_excludes_operations_boost(): void {
		$parsed = StylePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'       => 'Soft on soft',
							'description' => 'Use the wash on base.',
							'category'    => 'color',
							'tone'        => 'executable',
							'ranking'     => [],
							'operations'  => [
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'background' ],
									'value'      => 'var:preset|color|wash',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'wash',
								],
								[
									'type'       => 'set_styles',
									'path'       => [ 'color', 'text' ],
									'value'      => 'var:preset|color|base',
									'valueType'  => 'preset',
									'presetType' => 'color',
									'presetSlug' => 'base',
								],
							],
						],
					],
					'explanation' => 'demo',
				]
			),
			$this->build_global_styles_context_with_low_contrast_palette()
		);

		$this->assertEqualsWithDelta(
			0.60,
			(float) ( $parsed['suggestions'][0]['ranking']['score'] ?? 0.0 ),
			0.01
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_global_styles_context_with_low_contrast_palette(): array {
		return [
			'scope'        => [
				'surface'        => 'global-styles',
				'globalStylesId' => 'gs-1',
			],
			'styleContext' => [
				'themeTokens'         => [
					'colors'        => [ 'base: #ffffff', 'wash: #dddddd' ],
					'colorPresets'  => [
						[
							'slug'  => 'base',
							'color' => '#ffffff',
						],
						[
							'slug'  => 'wash',
							'color' => '#dddddd',
						],
					],
					'elementStyles' => [],
				],
				'mergedConfig'        => [
					'styles' => [
						'color' => [
							'background' => '#ffffff',
							'text'       => '#000000',
						],
					],
				],
				'currentConfig'       => [
					'styles'   => [],
					'settings' => [],
				],
				'supportedStylePaths' => [
					[
						'path'        => [ 'color', 'background' ],
						'valueSource' => 'color',
					],
					[
						'path'        => [ 'color', 'text' ],
						'valueSource' => 'color',
					],
				],
			],
		];
	}

	public function test_build_system_includes_required_nullable_ranking_shape_for_strict_schema(): void {
		$system = StylePrompt::build_system();

		$this->assertStringContainsString( '"ranking": null', $system );
		$this->assertStringContainsString( 'return ranking as null', strtolower( $system ) );
		$this->assertStringContainsString( 'use null for unknown ranking object values', strtolower( $system ) );
	}

	/**
	 * Shared style context for branch coverage. `surface` is injected per row so
	 * a single helper can drive both global-styles and style-book rejections.
	 *
	 * @param string $surface Scope surface (`global-styles` or `style-book`).
	 * @return array<string, mixed>
	 */
	private function branch_context( string $surface = 'global-styles' ): array {
		return [
			'scope'        => [
				'surface'   => $surface,
				'blockName' => 'style-book' === $surface ? 'core/group' : '',
			],
			'styleContext' => [
				'themeTokens'         => [
					// preset_exists() reads the string `colors` list, not colorPresets.
					'colors' => [ 'accent: #ff5500' ],
				],
				'supportedStylePaths' => [
					[
						'path'        => [ 'color', 'background' ],
						'valueSource' => 'color',
					],
					[
						'path'        => [ 'typography', 'lineHeight' ],
						'valueSource' => 'freeform',
					],
				],
				'availableVariations' => [
					[
						'title'    => 'Default',
						'settings' => [],
						'styles'   => [],
					],
					[
						'title'    => 'Midnight',
						'settings' => [],
						'styles'   => [],
					],
				],
			],
		];
	}

	/**
	 * One row per deterministic rejection branch in validate_operations().
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>, 2: string}>
	 */
	public function styleRejectionBranches(): array {
		return [
			'malformed operation entry'                  => [
				'not-an-array',
				$this->branch_context(),
				'malformed_operation',
			],
			'set_styles on style-book scope'             => [
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'background' ],
					'value' => '#abcdef',
				],
				$this->branch_context( 'style-book' ),
				'unsupported_scope',
			],
			'set_block_styles on global-styles scope'    => [
				[
					'type'      => 'set_block_styles',
					'blockName' => 'core/group',
					'path'      => [ 'color', 'background' ],
					'value'     => '#abcdef',
				],
				$this->branch_context(),
				'unsupported_scope',
			],
			'set_block_styles missing style-book target' => [
				[
					'type'      => 'set_block_styles',
					'blockName' => 'core/paragraph',
					'path'      => [ 'color', 'background' ],
					'value'     => 'var:preset|color|accent',
				],
				$this->branch_context( 'style-book' ),
				'missing_style_book_target',
			],
			'unsupported path'                           => [
				[
					'type'  => 'set_styles',
					'path'  => [ 'spacing', 'blockGap' ],
					'value' => '1rem',
				],
				$this->branch_context(),
				'unsupported_path',
			],
			'invalid freeform value'                     => [
				[
					'type'  => 'set_styles',
					'path'  => [ 'typography', 'lineHeight' ],
					'value' => 'not-a-line-height',
				],
				$this->branch_context(),
				'invalid_freeform_value',
			],
			'preset required but freeform given'         => [
				[
					'type'      => 'set_styles',
					'path'      => [ 'color', 'background' ],
					'value'     => 'var:preset|color|accent',
					'valueType' => 'freeform',
				],
				$this->branch_context(),
				'preset_required',
			],
			'preset required but slug missing'           => [
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|accent',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => '',
				],
				$this->branch_context(),
				'preset_required',
			],
			'preset metadata type mismatch'              => [
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|accent',
					'valueType'  => 'preset',
					'presetType' => 'fontSize',
					'presetSlug' => 'accent',
				],
				$this->branch_context(),
				'preset_metadata_mismatch',
			],
			'preset value unparseable'                   => [
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'background' ],
					'value'      => '#abcdef',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'accent',
				],
				$this->branch_context(),
				'preset_reference_mismatch',
			],
			'preset parsed type mismatch'                => [
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|font-size|accent',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'accent',
				],
				$this->branch_context(),
				'preset_reference_mismatch',
			],
			'preset parsed slug mismatch'                => [
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|other',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'accent',
				],
				$this->branch_context(),
				'preset_reference_mismatch',
			],
			'preset not present in theme'                => [
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|missing',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'missing',
				],
				$this->branch_context(),
				'preset_unavailable',
			],
			'set_theme_variation on style-book scope'    => [
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 1,
					'variationTitle' => 'Midnight',
				],
				$this->branch_context( 'style-book' ),
				'unsupported_scope',
			],
			'unresolvable theme variation'               => [
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 99,
					'variationTitle' => 'Nonexistent',
				],
				$this->branch_context(),
				'unavailable_variation',
			],
			'unknown operation type'                     => [
				[
					'type' => 'rotate_styles',
				],
				$this->branch_context(),
				'unknown_operation_type',
			],
		];
	}

	/**
	 * @dataProvider styleRejectionBranches
	 *
	 * @param mixed                $operation    Single operation under test.
	 * @param array<string, mixed> $context      Style context.
	 * @param string               $expected_code Expected per-branch reason code.
	 */
	public function test_each_style_validation_branch_maps_to_a_specific_code(
		mixed $operation,
		array $context,
		string $expected_code
	): void {
		$result = StylePrompt::validate_operations_for_tests( [ $operation ], $context );
		$codes  = array_column( $result['reasons'], 'code' );

		$this->assertContains(
			$expected_code,
			$codes,
			sprintf( 'Expected reason code "%s"; got: %s', $expected_code, implode( ', ', $codes ) )
		);
		$this->assertNotContains(
			'operation_validation_failed',
			$codes,
			'Deterministic style branches must map to a specific code, never the generic fallback.'
		);
		$this->assertSame(
			[],
			$result['operations'],
			'A rejected operation must not survive into the operations list.'
		);
	}

	public function test_duplicate_theme_variation_emits_reason_and_keeps_first_variation(): void {
		$result = StylePrompt::validate_operations_for_tests(
			[
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 0,
					'variationTitle' => 'Default',
				],
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 1,
					'variationTitle' => 'Midnight',
				],
			],
			$this->branch_context()
		);
		$codes  = array_column( $result['reasons'], 'code' );

		$this->assertContains( 'multi_operation_unsupported', $codes );
		$this->assertNotContains( 'operation_validation_failed', $codes );
		$this->assertCount( 1, $result['operations'] );
		$this->assertSame( 'set_theme_variation', $result['operations'][0]['type'] );
		$this->assertSame( 0, $result['operations'][0]['variationIndex'] );
	}
}
