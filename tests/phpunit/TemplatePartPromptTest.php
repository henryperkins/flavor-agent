<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Guidelines;
use FlavorAgent\LLM\TemplatePartPrompt;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class TemplatePartPromptTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_build_user_keeps_user_instruction_under_extreme_budget_pressure(): void {
		$filter = static fn (): int => 2000;
		add_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );

		try {
			$prompt = TemplatePartPrompt::build_user(
				[
					'templatePartRef' => 'twentytwentyfive//header',
					'slug'            => 'header',
					'title'           => str_repeat( 'H', 12000 ),
					'area'            => 'header',
				],
				'FLAVOR_AGENT_TEMPLATE_PART_INSTRUCTION_MARKER'
			);
		} finally {
			remove_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );
		}

		$this->assertStringContainsString( 'FLAVOR_AGENT_TEMPLATE_PART_INSTRUCTION_MARKER', $prompt );
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<int, array<string, mixed>>
	 */
	private function strip_ranking_from_entries( array $entries ): array {
		return array_map(
			static function ( array $entry ): array {
				unset( $entry['ranking'] );
				return $entry;
			},
			$entries
		);
	}

	public function test_build_user_does_not_inject_site_guidelines(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_SITE   => 'Header should support product discovery.',
			Guidelines::OPTION_IMAGES => 'Prefer real interface screenshots.',
		];

		$prompt = TemplatePartPrompt::build_user(
			[
				'templatePartRef' => 'theme//header',
				'slug'            => 'header',
				'title'           => 'Header',
				'area'            => 'header',
				'blockTree'       => [],
				'patterns'        => [],
				'themeTokens'     => [],
			]
		);

		$this->assertStringNotContainsString( '## Site Guidelines', $prompt );
		$this->assertStringNotContainsString( 'Header should support product discovery.', $prompt );
		$this->assertStringNotContainsString( 'Prefer real interface screenshots.', $prompt );
	}

	public function test_build_user_includes_structural_presence_flags(): void {
		$prompt = TemplatePartPrompt::build_user(
			[
				'templatePartRef' => 'theme//header',
				'slug'            => 'header',
				'title'           => 'Header',
				'area'            => 'header',
				'blockTree'       => [],
				'blockCounts'     => [],
				'structureStats'  => [
					'blockCount'          => 4,
					'maxDepth'            => 2,
					'hasNavigation'       => true,
					'containsLogo'        => true,
					'containsSiteTitle'   => false,
					'containsSearch'      => true,
					'containsSocialLinks' => false,
					'containsQuery'       => false,
					'containsColumns'     => true,
					'containsButtons'     => false,
					'containsSpacer'      => true,
					'containsSeparator'   => false,
				],
				'patterns'        => [],
				'themeTokens'     => [],
			]
		);

		$this->assertStringContainsString( '## Structure Summary', $prompt );
		$this->assertStringContainsString( 'hasNavigation: yes', $prompt );
		$this->assertStringContainsString( 'containsLogo: yes', $prompt );
		$this->assertStringContainsString( 'containsSiteTitle: no', $prompt );
		$this->assertStringContainsString( 'containsSearch: yes', $prompt );
		$this->assertStringContainsString( 'containsSocialLinks: no', $prompt );
		$this->assertStringContainsString( 'containsColumns: yes', $prompt );
		$this->assertStringContainsString( 'containsSpacer: yes', $prompt );
	}

	public function test_build_user_includes_design_semantic_context(): void {
		$prompt = TemplatePartPrompt::build_user(
			[
				'templatePartRef' => 'twentytwentyfive//footer',
				'slug'            => 'footer',
				'title'           => 'Footer',
				'area'            => 'footer',
				'blockTree'       => [],
				'patterns'        => [],
				'themeTokens'     => [],
				'designSemantics' => [
					'surface'         => 'template-part',
					'sectionRole'     => 'footer',
					'visualDensity'   => 'balanced',
					'contrastContext' => 'dark-parent',
					'layoutRhythm'    => 'constrained',
					'typographyRole'  => 'body',
					'mainDesignIssue' => 'contrast',
					'templatePart'    => [
						'area' => 'footer',
					],
				],
			],
			''
		);

		$this->assertStringContainsString( '## Design semantic context', $prompt );
		$this->assertStringContainsString( 'Role: footer', $prompt );
		$this->assertStringContainsString( 'Template part: area=footer', $prompt );
	}

	public function test_build_user_includes_pattern_override_context(): void {
		$prompt = TemplatePartPrompt::build_user(
			[
				'templatePartRef'         => 'theme//header',
				'slug'                    => 'header',
				'title'                   => 'Header',
				'area'                    => 'header',
				'blockTree'               => [],
				'patterns'                => [],
				'currentPatternOverrides' => [
					'hasOverrides' => true,
					'blockCount'   => 1,
					'blockNames'   => [ 'core/navigation' ],
					'blocks'       => [
						[
							'path'               => [ 0, 1 ],
							'name'               => 'core/navigation',
							'label'              => 'Navigation',
							'overrideAttributes' => [ 'overlayMenu' ],
							'usesDefaultBinding' => true,
						],
					],
				],
				'themeTokens'             => [],
			]
		);

		$this->assertStringContainsString( '## Current Pattern Override Blocks', $prompt );
		$this->assertStringContainsString( 'Path 1 > 2 - `Navigation`', $prompt );
		$this->assertStringContainsString( 'overridable attributes: `overlayMenu`', $prompt );
		$this->assertStringContainsString( 'uses default binding expansion', $prompt );
	}

	public function test_build_user_includes_up_to_thirty_available_patterns(): void {
		$patterns = [];

		for ( $i = 1; $i <= 31; $i++ ) {
			$patterns[] = [
				'name' => sprintf( 'theme/pattern-%02d', $i ),
			];
		}

		$prompt = TemplatePartPrompt::build_user(
			[
				'templatePartRef' => 'theme//header',
				'slug'            => 'header',
				'title'           => 'Header',
				'area'            => 'header',
				'blockTree'       => [],
				'patterns'        => $patterns,
				'themeTokens'     => [],
			]
		);

		$this->assertStringContainsString( 'Showing 30 of 31 patterns.', $prompt );
		$this->assertStringContainsString( '- `theme/pattern-30`', $prompt );
		$this->assertStringNotContainsString( '- `theme/pattern-31`', $prompt );
	}

	public function test_build_user_includes_enriched_theme_tokens(): void {
		$prompt = TemplatePartPrompt::build_user(
			[
				'templatePartRef' => 'theme//header',
				'slug'            => 'header',
				'title'           => 'Header',
				'area'            => 'header',
				'blockTree'       => [],
				'patterns'        => [],
				'themeTokens'     => [
					'colors'            => [ 'primary: #0073aa' ],
					'gradients'         => [ 'hero: linear-gradient(180deg, #fff, #ddd)' ],
					'fontSizes'         => [ 'small: 0.875rem' ],
					'fontFamilies'      => [ 'inter: Inter, sans-serif' ],
					'spacing'           => [ '20: 0.5rem' ],
					'shadows'           => [ 'natural: 6px 6px 9px rgba(0,0,0,0.2)' ],
					'fontFamilyPresets' => [
						[
							'slug'   => 'inter',
							'cssVar' => 'var(--wp--preset--font-family--inter)',
						],
					],
					'layout'            => [
						'content'                       => '650px',
						'wide'                          => '1200px',
						'allowEditing'                  => true,
						'allowCustomContentAndWideSize' => true,
					],
					'enabledFeatures'   => [
						'backgroundColor' => true,
						'textColor'       => true,
					],
				],
			]
		);

		$this->assertStringContainsString( '## Theme Tokens', $prompt );
		$this->assertStringContainsString( 'Colors: primary: #0073aa', $prompt );
		$this->assertStringContainsString( 'Gradients: hero: linear-gradient', $prompt );
		$this->assertStringContainsString( 'Font family preset refs: inter (var(--wp--preset--font-family--inter))', $prompt );
		$this->assertStringContainsString( 'Layout: {"content":"650px","wide":"1200px","allowEditing":true,"allowCustomContentAndWideSize":true}', $prompt );
		$this->assertStringContainsString( 'Enabled features: {"backgroundColor":true,"textColor":true}', $prompt );
	}

	public function test_build_system_includes_theme_capability_constraints(): void {
		$system = TemplatePartPrompt::build_system();

		$this->assertStringContainsString( 'Treat enabledFeatures and layout in Theme Tokens as hard capability constraints.', $system );
		$this->assertStringContainsString( 'do not recommend patterns, operations, or attribute changes that rely on disabled features or unsupported layout capabilities.', $system );
	}

	public function test_template_part_prompt_shows_copy_safe_operation_examples_for_executable_targets(): void {
		$prompt = TemplatePartPrompt::build_user(
			[
				'templatePartRef'  => 'theme//header',
				'slug'             => 'header',
				'title'            => 'Header',
				'area'             => 'header',
				'blockTree'        => [
					[
						'path'  => [ 0 ],
						'name'  => 'core/group',
						'label' => 'Group',
					],
				],
				'patterns'         => [
					[
						'name'  => 'theme/header-utility',
						'title' => 'Header Utility',
					],
				],
				'operationTargets' => [
					[
						'path'              => [ 0 ],
						'name'              => 'core/group',
						'label'             => 'Header group',
						'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
						'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
					],
				],
				'insertionAnchors' => [
					[
						'placement'  => 'before_block_path',
						'targetPath' => [ 0 ],
						'blockName'  => 'core/group',
						'label'      => 'Before Header group',
					],
				],
			],
			'Add a utility row'
		);

		$this->assertStringContainsString( '## Executable Operation Examples', $prompt );
		$this->assertStringContainsString( '"type":"insert_pattern"', $prompt );
		$this->assertStringContainsString( '"patternName":"theme/header-utility"', $prompt );
		$this->assertStringContainsString( '"placement":"before_block_path"', $prompt );
		$this->assertStringContainsString( '"targetPath":[0]', $prompt );
		$this->assertStringContainsString( '"type":"replace_block_with_pattern"', $prompt );
	}

	public function test_parse_response_keeps_only_valid_block_hints_and_patterns(): void {
		$context = [
			'blockTree'        => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'attributes' => [
						'tagName' => 'header',
					],
					'childCount' => 2,
					'children'   => [
						[
							'path'       => [ 0, 0 ],
							'name'       => 'core/site-logo',
							'attributes' => [],
							'childCount' => 0,
							'children'   => [],
						],
						[
							'path'       => [ 0, 1 ],
							'name'       => 'core/navigation',
							'attributes' => [
								'overlayMenu' => 'mobile',
							],
							'childCount' => 0,
							'children'   => [],
						],
					],
				],
			],
			'patterns'         => [
				[
					'name' => 'theme/header-utility',
				],
				[
					'name' => 'theme/header-minimal',
				],
			],
			'insertionAnchors' => [
				[
					'placement' => 'start',
					'label'     => 'Start of template part',
				],
				[
					'placement'  => 'before_block_path',
					'targetPath' => [ 0, 1 ],
					'label'      => 'Before Navigation block',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Tighten header hierarchy',
						'description'        => 'Focus on the navigation cluster and browse a utility-row pattern.',
						'blockHints'         => [
							[
								'path'   => [ 0, 1 ],
								'label'  => 'Navigation block',
								'reason' => 'This is where the header feels busiest.',
							],
							[
								'path'   => [ 9 ],
								'label'  => 'Missing block',
								'reason' => 'This should be ignored.',
							],
						],
						'patternSuggestions' => [
							'theme/header-utility',
							'theme/missing',
							'theme/header-utility',
						],
					],
				],
				'explanation' => 'The header already has a clear wrapper, so focus on the menu cluster.',
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			'The header already has a clear wrapper, so focus on the menu cluster.',
			$result['explanation']
		);
		$this->assertSame(
			[
				[
					'label'              => 'Tighten header hierarchy',
					'description'        => 'Focus on the navigation cluster and browse a utility-row pattern.',
					'blockHints'         => [
						[
							'path'      => [ 0, 1 ],
							'label'     => 'Navigation block',
							'blockName' => 'core/navigation',
							'reason'    => 'This is where the header feels busiest.',
						],
					],
					'patternSuggestions' => [ 'theme/header-utility' ],
					'operations'         => [],
					'validationReasons'  => [],
				],
			],
			$this->strip_ranking_from_entries( $result['suggestions'] )
		);
	}

	public function test_parse_response_keeps_only_valid_template_part_operations(): void {
		$context = [
			'blockTree'             => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'attributes' => [],
					'childCount' => 2,
					'children'   => [
						[
							'path'       => [ 0, 0 ],
							'name'       => 'core/site-logo',
							'attributes' => [],
							'childCount' => 0,
							'children'   => [],
						],
						[
							'path'       => [ 0, 1 ],
							'name'       => 'core/navigation',
							'attributes' => [],
							'childCount' => 0,
							'children'   => [],
						],
					],
				],
			],
			'patterns'              => [
				[
					'name' => 'theme/header-utility',
				],
				[
					'name' => 'theme/header-minimal',
				],
			],
			'insertionAnchors'      => [
				[
					'placement' => 'start',
					'label'     => 'Start of template part',
				],
				[
					'placement'  => 'before_block_path',
					'targetPath' => [ 0, 1 ],
					'label'      => 'Before Navigation block',
				],
			],
			'operationTargets'      => [
				[
					'path'              => [ 0 ],
					'name'              => 'core/group',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
				[
					'path'              => [ 0, 0 ],
					'name'              => 'core/site-logo',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
				[
					'path'              => [ 0, 1 ],
					'name'              => 'core/navigation',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
			],
			'structuralConstraints' => [
				'contentOnlyPaths' => [],
				'lockedPaths'      => [],
				'hasContentOnly'   => false,
				'hasLockedBlocks'  => false,
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Add a utility row',
						'description'        => 'Insert a compact row at the top of the header.',
						'blockHints'         => [],
						'patternSuggestions' => [],
						'operations'         => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/header-utility',
								'placement'   => 'before_block_path',
								'targetPath'  => [ 0, 1 ],
							],
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/header-minimal',
								'placement'   => 'middle',
							],
						],
					],
				],
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'label'              => 'Add a utility row',
					'description'        => 'Insert a compact row at the top of the header.',
					'blockHints'         => [],
					'patternSuggestions' => [ 'theme/header-utility' ],
					'operations'         => [
						[
							'type'           => 'insert_pattern',
							'patternName'    => 'theme/header-utility',
							'placement'      => 'before_block_path',
							'targetPath'     => [ 0, 1 ],
							'expectedTarget' => [
								'name'       => 'core/navigation',
								'label'      => '',
								'attributes' => [],
								'childCount' => 0,
							],
						],
					],
					'validationReasons'  => [
						[
							'code'     => 'invalid_placement',
							'severity' => 'rejected',
						],
					],
				],
			],
			$this->strip_ranking_from_entries( $result['suggestions'] )
		);
	}

	public function test_template_part_parser_keeps_valid_operations_when_pattern_suggestions_are_mixed(): void {
		$context = [
			'patterns'         => [
				[
					'name' => 'theme/header-utility',
				],
			],
			'blockTree'        => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'label'      => 'Header group',
					'attributes' => [],
					'childCount' => 0,
				],
			],
			'insertionAnchors' => [
				[
					'placement'  => 'after_block_path',
					'targetPath' => [ 0 ],
					'label'      => 'After Header group',
				],
			],
			'operationTargets' => [
				[
					'path'              => [ 0 ],
					'name'              => 'core/group',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Add utility row',
						'description'        => 'Add a compact utility row after the header group.',
						'patternSuggestions' => [ 'theme/header-utility', 'theme/missing' ],
						'operations'         => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/header-utility',
								'placement'   => 'after_block_path',
								'targetPath'  => [ 0 ],
							],
						],
					],
				],
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'theme/header-utility' ], $result['suggestions'][0]['patternSuggestions'] );
		$this->assertSame( 'insert_pattern', $result['suggestions'][0]['operations'][0]['type'] );
		$this->assertSame( 'theme/header-utility', $result['suggestions'][0]['operations'][0]['patternName'] );
	}

	public function test_parse_response_accepts_replace_and_remove_operations_when_paths_match(): void {
		$context = [
			'blockTree'             => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'attributes' => [],
					'childCount' => 2,
					'children'   => [
						[
							'path'       => [ 0, 0 ],
							'name'       => 'core/site-logo',
							'attributes' => [],
							'childCount' => 0,
							'children'   => [],
						],
						[
							'path'       => [ 0, 1 ],
							'name'       => 'core/navigation',
							'attributes' => [],
							'childCount' => 0,
							'children'   => [],
						],
					],
				],
			],
			'patterns'              => [
				[
					'name' => 'theme/header-utility',
				],
			],
			'operationTargets'      => [
				[
					'path'              => [ 0 ],
					'name'              => 'core/group',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
				[
					'path'              => [ 0, 0 ],
					'name'              => 'core/site-logo',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
				[
					'path'              => [ 0, 1 ],
					'name'              => 'core/navigation',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
			],
			'structuralConstraints' => [
				'contentOnlyPaths' => [],
				'lockedPaths'      => [],
				'hasContentOnly'   => false,
				'hasLockedBlocks'  => false,
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Reshape the header cluster',
						'description'        => 'Replace the navigation and remove the redundant logo.',
						'patternSuggestions' => [],
						'operations'         => [
							[
								'type'              => 'replace_block_with_pattern',
								'patternName'       => 'theme/header-utility',
								'expectedBlockName' => 'core/navigation',
								'targetPath'        => [ 0, 1 ],
							],
							[
								'type'              => 'remove_block',
								'expectedBlockName' => 'core/site-logo',
								'targetPath'        => [ 0, 0 ],
							],
						],
					],
				],
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'label'              => 'Reshape the header cluster',
					'description'        => 'Replace the navigation and remove the redundant logo.',
					'blockHints'         => [],
					'patternSuggestions' => [ 'theme/header-utility' ],
					'operations'         => [
						[
							'type'              => 'replace_block_with_pattern',
							'patternName'       => 'theme/header-utility',
							'expectedBlockName' => 'core/navigation',
							'expectedTarget'    => [
								'name'       => 'core/navigation',
								'label'      => '',
								'attributes' => [],
								'childCount' => 0,
							],
							'targetPath'        => [ 0, 1 ],
						],
						[
							'type'              => 'remove_block',
							'expectedBlockName' => 'core/site-logo',
							'expectedTarget'    => [
								'name'       => 'core/site-logo',
								'label'      => '',
								'attributes' => [],
								'childCount' => 0,
							],
							'targetPath'        => [ 0, 0 ],
						],
					],
					'validationReasons'  => [],
				],
			],
			$this->strip_ranking_from_entries( $result['suggestions'] )
		);
	}

	public function test_parse_response_drops_overlapping_template_part_operations(): void {
		$context = [
			'blockTree'             => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'attributes' => [],
					'childCount' => 1,
					'children'   => [
						[
							'path'       => [ 0, 0 ],
							'name'       => 'core/site-logo',
							'attributes' => [],
							'childCount' => 0,
							'children'   => [],
						],
					],
				],
			],
			'patterns'              => [
				[
					'name' => 'theme/header-utility',
				],
			],
			'operationTargets'      => [
				[
					'path'              => [ 0 ],
					'name'              => 'core/group',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
				[
					'path'              => [ 0, 0 ],
					'name'              => 'core/site-logo',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
			],
			'structuralConstraints' => [
				'contentOnlyPaths' => [],
				'lockedPaths'      => [],
				'hasContentOnly'   => false,
				'hasLockedBlocks'  => false,
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Reshape the header cluster',
						'description'        => 'Replace the wrapper and remove the logo.',
						'patternSuggestions' => [ 'theme/header-utility' ],
						'operations'         => [
							[
								'type'              => 'replace_block_with_pattern',
								'patternName'       => 'theme/header-utility',
								'expectedBlockName' => 'core/group',
								'targetPath'        => [ 0 ],
							],
							[
								'type'              => 'remove_block',
								'expectedBlockName' => 'core/site-logo',
								'targetPath'        => [ 0, 0 ],
							],
						],
					],
				],
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'label'              => 'Reshape the header cluster',
					'description'        => 'Replace the wrapper and remove the logo.',
					'blockHints'         => [],
					'patternSuggestions' => [ 'theme/header-utility' ],
					'operations'         => [],
					'validationReasons'  => [
						[
							'code'     => 'overlapping_block_paths',
							'severity' => 'rejected',
						],
					],
				],
			],
			$this->strip_ranking_from_entries( $result['suggestions'] )
		);
	}

	public function test_parse_response_accepts_deep_live_paths_from_the_full_path_index(): void {
		$context = [
			'blockTree'             => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'attributes' => [],
					'childCount' => 1,
					'children'   => [
						[
							'path'       => [ 0, 0 ],
							'name'       => 'core/group',
							'attributes' => [],
							'childCount' => 1,
							'children'   => [
								[
									'path'       => [ 0, 0, 0 ],
									'name'       => 'core/group',
									'attributes' => [],
									'childCount' => 1,
									'children'   => [],
								],
							],
						],
					],
				],
			],
			'allBlockPaths'         => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'label'      => 'Outer Group',
					'attributes' => [],
					'childCount' => 1,
				],
				[
					'path'       => [ 0, 0 ],
					'name'       => 'core/group',
					'label'      => 'Middle Group',
					'attributes' => [],
					'childCount' => 1,
				],
				[
					'path'       => [ 0, 0, 0 ],
					'name'       => 'core/group',
					'label'      => 'Inner Group',
					'attributes' => [],
					'childCount' => 1,
				],
				[
					'path'       => [ 0, 0, 0, 0 ],
					'name'       => 'core/navigation',
					'label'      => 'Navigation',
					'attributes' => [
						'overlayMenu' => 'mobile',
					],
					'childCount' => 0,
				],
			],
			'patterns'              => [
				[
					'name' => 'theme/header-utility',
				],
			],
			'operationTargets'      => [
				[
					'path'              => [ 0, 0, 0, 0 ],
					'name'              => 'core/navigation',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
			],
			'insertionAnchors'      => [
				[
					'placement' => 'start',
					'label'     => 'Start of template part',
				],
				[
					'placement' => 'end',
					'label'     => 'End of template part',
				],
				[
					'placement'  => 'before_block_path',
					'targetPath' => [ 0, 0, 0, 0 ],
					'label'      => 'Before Navigation',
				],
			],
			'structuralConstraints' => [
				'contentOnlyPaths' => [],
				'lockedPaths'      => [],
				'hasContentOnly'   => false,
				'hasLockedBlocks'  => false,
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Reshape the deep navigation cluster',
						'description'        => 'Replace the deeply nested navigation block with a utility pattern.',
						'blockHints'         => [
							[
								'path'   => [ 0, 0, 0, 0 ],
								'label'  => 'Navigation block',
								'reason' => 'This is the deepest executable target in the live editor tree.',
							],
						],
						'patternSuggestions' => [],
						'operations'         => [
							[
								'type'              => 'replace_block_with_pattern',
								'patternName'       => 'theme/header-utility',
								'expectedBlockName' => 'core/navigation',
								'targetPath'        => [ 0, 0, 0, 0 ],
							],
						],
					],
				],
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[ 0, 0, 0, 0 ],
			$result['suggestions'][0]['blockHints'][0]['path'] ?? []
		);
		$this->assertSame(
			[ 0, 0, 0, 0 ],
			$result['suggestions'][0]['operations'][0]['targetPath'] ?? []
		);
		$this->assertSame(
			'Navigation',
			$result['suggestions'][0]['operations'][0]['expectedTarget']['label'] ?? ''
		);
	}

	public function test_parse_response_records_design_validator_complexity_signals_for_template_part_patterns(): void {
		$context = [
			'templatePartArea'      => 'header',
			'blockTree'             => [],
			'patterns'              => [
				[
					'name' => 'theme/mega-header',
				],
			],
			'insertionAnchors'      => [
				[
					'placement' => 'start',
					'label'     => 'Start of template part',
				],
			],
			'operationTargets'      => [],
			'structuralConstraints' => [
				'contentOnlyPaths' => [],
				'lockedPaths'      => [],
				'hasContentOnly'   => false,
				'hasLockedBlocks'  => false,
			],
		];

		$result = TemplatePartPrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'              => 'Add dense header pattern',
							'description'        => 'Insert a larger header pattern into the part.',
							'contentBlockCount'  => 12,
							'patternSuggestions' => [ 'theme/mega-header' ],
							'operations'         => [
								[
									'type'        => 'insert_pattern',
									'patternName' => 'theme/mega-header',
									'placement'   => 'start',
								],
							],
							'ranking'            => [
								'score' => 0.84,
							],
						],
					],
				]
			),
			$context,
			[
				'surface' => 'template-part',
				'prompt'  => 'Keep the header lightweight.',
				'context' => $context,
			]
		);

		$this->assertIsArray( $result );

		$suggestion = $result['suggestions'][0];
		$codes      = array_column( $suggestion['validationReasons'] ?? [], 'code' );

		$this->assertFalse( $suggestion['qualitySignals']['complexityFit'] ?? true );
		$this->assertContains( 'excessive_visual_complexity', $codes );
		$this->assertContains( 'design_validator_v1', $suggestion['ranking']['sourceSignals'] ?? [] );
		$this->assertArrayHasKey( 'excessive_visual_complexity', $suggestion['ranking']['contextPenalties'] ?? [] );
	}

	public function test_parse_response_rejects_start_insertions_without_a_start_anchor(): void {
		$context = [
			'blockTree'        => [],
			'patterns'         => [
				[
					'name' => 'theme/header-utility',
				],
			],
			'insertionAnchors' => [
				[
					'placement' => 'end',
					'label'     => 'End of template part',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Insert at start',
						'description' => 'This should fail without a start anchor.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/header-utility',
								'placement'   => 'start',
							],
						],
					],
				],
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_rejects_end_insertions_without_an_end_anchor(): void {
		$context = [
			'blockTree'        => [],
			'patterns'         => [
				[
					'name' => 'theme/header-utility',
				],
			],
			'insertionAnchors' => [
				[
					'placement' => 'start',
					'label'     => 'Start of template part',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Insert at end',
						'description' => 'This should fail without an end anchor.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/header-utility',
								'placement'   => 'end',
							],
						],
					],
				],
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_rejects_responses_without_actionable_suggestions(): void {
		$context = [
			'blockTree' => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'attributes' => [],
					'childCount' => 0,
					'children'   => [],
				],
			],
			'patterns'  => [
				[
					'name' => 'theme/header-utility',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Loose advice only',
						'description'        => 'This keeps prose but loses every actionable reference.',
						'blockHints'         => [
							[
								'path'  => [ 9 ],
								'label' => 'Missing block',
							],
						],
						'patternSuggestions' => [ 'theme/missing' ],
					],
				],
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_blends_model_score_with_deterministic_quality_for_sorting(): void {
		$context = [
			'blockTree'        => [],
			'patterns'         => [
				[
					'name' => 'theme/header-utility',
				],
			],
			'insertionAnchors' => [
				[
					'placement' => 'start',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'High model advisory template part idea',
						'description'        => 'This should sort second after deterministic validation.',
						'patternSuggestions' => [ 'theme/header-utility' ],
						'ranking'            => [
							'score' => 0.9,
						],
					],
					[
						'label'              => 'Lower model executable template part idea',
						'description'        => 'This has stronger deterministic evidence.',
						'operations'         => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/header-utility',
								'placement'   => 'start',
							],
						],
						'patternSuggestions' => [ 'theme/header-utility' ],
						'score'              => 0.55,
					],
				],
				'explanation' => 'Deterministic quality should blend with model scores.',
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame( 'Lower model executable template part idea', $result['suggestions'][0]['label'] );
		$this->assertGreaterThan( $result['suggestions'][1]['ranking']['score'], $result['suggestions'][0]['ranking']['score'] );
	}

	public function test_parse_response_falls_back_when_nested_ranking_score_is_malformed(): void {
		$context = [
			'blockTree'        => [],
			'patterns'         => [
				[
					'name' => 'theme/header-utility',
				],
			],
			'insertionAnchors' => [],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Fallback confidence template part idea',
						'description'        => 'A malformed nested score should not zero this out.',
						'patternSuggestions' => [ 'theme/header-utility' ],
						'ranking'            => [
							'score' => [],
						],
						'confidence'         => 0.85,
					],
					[
						'label'              => 'Lower confidence template part idea',
						'description'        => 'This should sort second.',
						'patternSuggestions' => [ 'theme/header-utility' ],
						'confidence'         => 0.83,
					],
				],
				'explanation' => 'Malformed nested scores should not suppress valid fallback confidence.',
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame( 'Fallback confidence template part idea', $result['suggestions'][0]['label'] );
		$this->assertSame( 0.7, $result['suggestions'][0]['ranking']['score'] );
	}

	public function test_parse_response_merges_model_source_signals_with_plugin_ranking_signals(): void {
		$context = [
			'blockTree'        => [],
			'patterns'         => [
				[
					'name' => 'theme/header-utility',
				],
			],
			'insertionAnchors' => [
				[
					'placement' => 'start',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Concrete template part update with model signal',
						'description' => 'Keep model template-part context without losing plugin diagnostics.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/header-utility',
								'placement'   => 'start',
							],
						],
						'ranking'     => [
							'score'         => 0.61,
							'sourceSignals' => [ 'model_template_part_balance' ],
						],
					],
				],
				'explanation' => 'Source signals should merge.',
			]
		);

		$result = TemplatePartPrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[ 'llm_response', 'template_part_surface', 'has_operations', 'has_pattern_suggestions', 'model_template_part_balance' ],
			$result['suggestions'][0]['ranking']['sourceSignals']
		);
	}

	public function test_build_system_includes_required_nullable_ranking_shape_for_strict_schema(): void {
		$system = TemplatePartPrompt::build_system();

		$this->assertStringContainsString( '"ranking": null', $system );
		$this->assertStringContainsString( 'return ranking as null', strtolower( $system ) );
		$this->assertStringContainsString( 'use null for unknown ranking object values', strtolower( $system ) );
	}

	/**
	 * Shared validator lookups for the per-branch reason-code suite.
	 *
	 * The `start` / `end` insertion anchors are deliberately omitted so that a
	 * start-placement insert with an otherwise-valid pattern still falls through
	 * to the start/end anchor-missing branch. A `before_block_path|0.1` anchor
	 * and the `[ 0, 1 ]` block node are present so anchored inserts can resolve
	 * when a targetPath is supplied, while replace/remove targets at `[ 0 ]` and
	 * `[ 0, 1 ]` let the overlap branch be exercised deterministically.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function reason_branch_lookups(): array {
		return [
			'block'   => [
				'0'   => [ 'name' => 'core/group' ],
				'0.1' => [ 'name' => 'core/navigation' ],
			],
			'pattern' => [
				'a/b' => true,
			],
			'target'  => [
				'0'   => [
					'name'              => 'core/group',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
				'0.1' => [
					'name'              => 'core/navigation',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
				],
			],
			'anchor'  => [
				'before_block_path|0.1' => [
					'placement'  => 'before_block_path',
					'targetPath' => [ 0, 1 ],
				],
			],
		];
	}

	/**
	 * @dataProvider templatePartRejectionBranches
	 *
	 * @param array<int, mixed> $operations
	 */
	public function test_each_template_part_branch_maps_to_a_specific_code( array $operations, string $expected_code ): void {
		$result = TemplatePartPrompt::validate_operations_for_tests( $operations, $this->reason_branch_lookups() );

		$codes = array_column( $result['reasons'], 'code' );

		$this->assertContains( $expected_code, $codes );
		$this->assertNotContains( 'operation_validation_failed', $codes );
	}

	/**
	 * @return array<string, array{0: array<int, mixed>, 1: string}>
	 */
	public function templatePartRejectionBranches(): array {
		return [
			'too many operations'           => [
				array_fill(
					0,
					4,
					[
						'type'        => 'insert_pattern',
						'patternName' => 'a/b',
						'placement'   => 'start',
					]
				),
				'too_many_operations',
			],
			'insert empty pattern name'     => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => '',
						'placement'   => 'start',
					],
				],
				'unknown_pattern',
			],
			'insert unknown pattern name'   => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'nope',
						'placement'   => 'start',
					],
				],
				'unknown_pattern',
			],
			'insert invalid placement'      => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'a/b',
						'placement'   => 'sideways',
					],
				],
				'invalid_placement',
			],
			'insert anchored missing path'  => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'a/b',
						'placement'   => 'before_block_path',
					],
				],
				'invalid_anchor',
			],
			'insert start anchor missing'   => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'a/b',
						'placement'   => 'start',
					],
				],
				'invalid_anchor',
			],
			'insert overlapping paths'      => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'a/b',
						'placement'   => 'before_block_path',
						'targetPath'  => [ 0, 1 ],
					],
					[
						'type'        => 'insert_pattern',
						'patternName' => 'a/b',
						'placement'   => 'before_block_path',
						'targetPath'  => [ 0, 1 ],
					],
				],
				'overlapping_block_paths',
			],
			'replace missing pattern name'  => [
				[
					[
						'type'              => 'replace_block_with_pattern',
						'patternName'       => '',
						'expectedBlockName' => 'core/navigation',
						'targetPath'        => [ 0, 1 ],
					],
				],
				'unknown_pattern',
			],
			'replace unknown pattern name'  => [
				[
					[
						'type'              => 'replace_block_with_pattern',
						'patternName'       => 'nope',
						'expectedBlockName' => 'core/navigation',
						'targetPath'        => [ 0, 1 ],
					],
				],
				'unknown_pattern',
			],
			'replace missing target path'   => [
				[
					[
						'type'              => 'replace_block_with_pattern',
						'patternName'       => 'a/b',
						'expectedBlockName' => 'core/navigation',
					],
				],
				'invalid_anchor',
			],
			'replace target node mismatch'  => [
				[
					[
						'type'              => 'replace_block_with_pattern',
						'patternName'       => 'a/b',
						'expectedBlockName' => 'core/paragraph',
						'targetPath'        => [ 0, 1 ],
					],
				],
				'invalid_anchor',
			],
			'replace overlapping paths'     => [
				[
					[
						'type'              => 'replace_block_with_pattern',
						'patternName'       => 'a/b',
						'expectedBlockName' => 'core/group',
						'targetPath'        => [ 0 ],
					],
					[
						'type'              => 'replace_block_with_pattern',
						'patternName'       => 'a/b',
						'expectedBlockName' => 'core/navigation',
						'targetPath'        => [ 0, 1 ],
					],
				],
				'overlapping_block_paths',
			],
			'remove missing target path'    => [
				[
					[
						'type'              => 'remove_block',
						'expectedBlockName' => 'core/navigation',
					],
				],
				'invalid_anchor',
			],
			'remove target node mismatch'   => [
				[
					[
						'type'              => 'remove_block',
						'expectedBlockName' => 'core/paragraph',
						'targetPath'        => [ 0, 1 ],
					],
				],
				'invalid_anchor',
			],
			'remove overlapping paths'      => [
				[
					[
						'type'              => 'replace_block_with_pattern',
						'patternName'       => 'a/b',
						'expectedBlockName' => 'core/group',
						'targetPath'        => [ 0 ],
					],
					[
						'type'              => 'remove_block',
						'expectedBlockName' => 'core/navigation',
						'targetPath'        => [ 0, 1 ],
					],
				],
				'overlapping_block_paths',
			],
			'malformed non-array operation' => [
				[ 'not-an-array' ],
				'malformed_operation',
			],
			'unknown operation type'        => [
				[ [ 'type' => 'frobnicate' ] ],
				'unknown_operation_type',
			],
		];
	}
}
