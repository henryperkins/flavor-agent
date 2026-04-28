<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Guidelines;
use FlavorAgent\LLM\TemplatePrompt;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class TemplatePromptTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_build_user_includes_site_guidelines(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_SITE       => 'Homepage for enterprise buyers.',
			Guidelines::OPTION_ADDITIONAL => 'Keep accessibility requirements visible.',
		];

		$prompt = TemplatePrompt::build_user(
			[
				'templateType'   => 'home',
				'title'          => 'Home',
				'assignedParts'  => [],
				'emptyAreas'     => [],
				'availableParts' => [],
				'patterns'       => [],
				'themeTokens'    => [],
			]
		);

		$this->assertStringContainsString( '## Site Guidelines', $prompt );
		$this->assertStringContainsString( 'Site: Homepage for enterprise buyers.', $prompt );
		$this->assertStringContainsString( 'Additional: Keep accessibility requirements visible.', $prompt );
	}

	public function test_build_user_includes_structure_summary(): void {
		$prompt = TemplatePrompt::build_user(
			[
				'templateType'   => 'home',
				'title'          => 'Home',
				'assignedParts'  => [],
				'emptyAreas'     => [],
				'availableParts' => [],
				'patterns'       => [],
				'structureStats' => [
					'blockCount'         => 6,
					'maxDepth'           => 3,
					'topLevelBlockCount' => 2,
					'hasNavigation'      => true,
					'hasQuery'           => true,
					'hasTemplateParts'   => true,
					'firstTopLevelBlock' => 'core/template-part',
					'lastTopLevelBlock'  => 'core/query',
				],
				'themeTokens'    => [],
			]
		);

		$this->assertStringContainsString( '## Structure Summary', $prompt );
		$this->assertStringContainsString( 'Block count: 6', $prompt );
		$this->assertStringContainsString( 'Max depth: 3', $prompt );
		$this->assertStringContainsString( 'Top-level block count: 2', $prompt );
		$this->assertStringContainsString( 'hasNavigation: yes', $prompt );
		$this->assertStringContainsString( 'hasQuery: yes', $prompt );
		$this->assertStringContainsString( 'hasTemplateParts: yes', $prompt );
		$this->assertStringContainsString( 'First top-level block: core/template-part', $prompt );
		$this->assertStringContainsString( 'Last top-level block: core/query', $prompt );
	}

	public function test_build_user_includes_pattern_override_and_visibility_context(): void {
		$prompt = TemplatePrompt::build_user(
			[
				'templateType'              => 'home',
				'title'                     => 'Home',
				'assignedParts'             => [],
				'emptyAreas'                => [],
				'availableParts'            => [],
				'patterns'                  => [],
				'topLevelBlockTree'         => [],
				'topLevelInsertionAnchors'  => [],
				'currentPatternOverrides'   => [
					'hasOverrides' => true,
					'blockCount'   => 1,
					'blockNames'   => [ 'core/heading' ],
					'blocks'       => [
						[
							'path'               => [ 0, 1 ],
							'name'               => 'core/heading',
							'label'              => 'Heading',
							'overrideAttributes' => [ 'content' ],
							'usesDefaultBinding' => false,
						],
					],
				],
				'currentViewportVisibility' => [
					'hasVisibilityRules' => true,
					'blockCount'         => 1,
					'blocks'             => [
						[
							'path'             => [ 1 ],
							'name'             => 'core/group',
							'label'            => 'Group',
							'hiddenViewports'  => [ 'mobile' ],
							'visibleViewports' => [ 'desktop' ],
						],
					],
				],
				'themeTokens'               => [],
			]
		);

		$this->assertStringContainsString( '## Current Pattern Override Blocks', $prompt );
		$this->assertStringContainsString( 'Path 1 > 2 - `Heading`', $prompt );
		$this->assertStringContainsString( 'overridable attributes: `content`', $prompt );
		$this->assertStringContainsString( '## Current Viewport Visibility Constraints', $prompt );
		$this->assertStringContainsString( 'hidden on `mobile`', $prompt );
		$this->assertStringContainsString( 'explicitly visible on `desktop`', $prompt );
	}

	public function test_build_user_includes_enriched_theme_tokens(): void {
		$prompt = TemplatePrompt::build_user(
			[
				'templateType'   => 'single',
				'title'          => 'Single Post',
				'assignedParts'  => [],
				'emptyAreas'     => [],
				'availableParts' => [],
				'patterns'       => [],
				'themeTokens'    => [
					'colors'          => [ 'primary: #0073aa' ],
					'gradients'       => [ 'vivid: linear-gradient(135deg, #00f, #f0f)' ],
					'fontSizes'       => [ 'small: 0.875rem' ],
					'fontFamilies'    => [ 'inter: Inter, sans-serif' ],
					'spacing'         => [ '20: 0.5rem' ],
					'shadows'         => [ 'natural: 6px 6px 9px rgba(0,0,0,0.2)' ],
					'colorPresets'    => [
						[
							'slug'   => 'primary',
							'cssVar' => 'var(--wp--preset--color--primary)',
						],
					],
					'layout'          => [
						'content'                       => '650px',
						'wide'                          => '1200px',
						'allowEditing'                  => true,
						'allowCustomContentAndWideSize' => true,
					],
					'enabledFeatures' => [
						'backgroundColor' => true,
						'textColor'       => true,
					],
				],
			]
		);

		$this->assertStringContainsString( '## Theme Tokens', $prompt );
		$this->assertStringContainsString( 'Colors: primary: #0073aa', $prompt );
		$this->assertStringContainsString( 'Gradients: vivid: linear-gradient', $prompt );
		$this->assertStringContainsString( 'Shadows: natural: 6px 6px 9px rgba(0,0,0,0.2)', $prompt );
		$this->assertStringContainsString( 'Color preset refs: primary (var(--wp--preset--color--primary))', $prompt );
		$this->assertStringContainsString( 'Layout: {"content":"650px","wide":"1200px","allowEditing":true,"allowCustomContentAndWideSize":true}', $prompt );
		$this->assertStringContainsString( 'Enabled features: {"backgroundColor":true,"textColor":true}', $prompt );
	}

	public function test_build_system_includes_theme_capability_constraints(): void {
		$system = TemplatePrompt::build_system();

		$this->assertStringContainsString( 'Treat enabledFeatures and layout in Theme Tokens as hard capability constraints.', $system );
		$this->assertStringContainsString( 'do not recommend patterns, operations, or attribute changes that rely on disabled features or unsupported layout capabilities.', $system );
	}

	public function test_parse_response_keeps_valid_structured_template_operations(): void {
		$context = [
			'assignedParts'            => [
				[
					'slug' => 'header',
					'area' => 'header',
				],
			],
			'availableParts'           => [
				[
					'slug'  => 'header-minimal',
					'area'  => 'header',
					'title' => 'Header Minimal',
				],
				[
					'slug'  => 'footer-main',
					'area'  => 'footer',
					'title' => 'Footer Main',
				],
			],
			'allowedAreas'             => [ 'header', 'footer' ],
			'emptyAreas'               => [ 'footer' ],
			'patterns'                 => [
				[
					'name' => 'theme/hero',
				],
			],
			'topLevelInsertionAnchors' => [
				[
					'placement' => 'start',
					'label'     => 'Start of template',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Tighten template structure',
						'description' => 'Replace the current header and add a hero pattern.',
						'operations'  => [
							[
								'type'        => 'replace_template_part',
								'currentSlug' => 'header',
								'slug'        => 'header-minimal',
								'area'        => 'header',
							],
							[
								'type' => 'assign_template_part',
								'slug' => 'footer-main',
								'area' => 'footer',
							],
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'placement'   => 'start',
							],
						],
					],
				],
				'explanation' => 'Keep structure predictable.',
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame( 'Keep structure predictable.', $result['explanation'] );
		$this->assertSame(
			[
				[
					'label'              => 'Tighten template structure',
					'description'        => 'Replace the current header and add a hero pattern.',
					'operations'         => [
						[
							'type'        => 'replace_template_part',
							'currentSlug' => 'header',
							'slug'        => 'header-minimal',
							'area'        => 'header',
						],
						[
							'type' => 'assign_template_part',
							'slug' => 'footer-main',
							'area' => 'footer',
						],
						[
							'type'        => 'insert_pattern',
							'patternName' => 'theme/hero',
							'placement'   => 'start',
						],
					],
					'templateParts'      => [
						[
							'slug'   => 'header-minimal',
							'area'   => 'header',
							'reason' => '',
						],
						[
							'slug'   => 'footer-main',
							'area'   => 'footer',
							'reason' => '',
						],
					],
					'patternSuggestions' => [ 'theme/hero' ],
				],
			],
			$result['suggestions']
		);
	}

	public function test_parse_response_rejects_structured_template_suggestions_with_any_invalid_operation(): void {
		$context = [
			'assignedParts'            => [
				[
					'slug' => 'header',
					'area' => 'header',
				],
			],
			'availableParts'           => [
				[
					'slug'  => 'header-minimal',
					'area'  => 'header',
					'title' => 'Header Minimal',
				],
			],
			'allowedAreas'             => [ 'header' ],
			'emptyAreas'               => [],
			'patterns'                 => [
				[
					'name' => 'theme/hero',
				],
			],
			'topLevelInsertionAnchors' => [
				[
					'placement' => 'start',
					'label'     => 'Start of template',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Do not truncate executable plans',
						'description' => 'A malformed operation should not leave a partial executable plan.',
						'operations'  => [
							[
								'type'        => 'replace_template_part',
								'currentSlug' => 'header',
								'slug'        => 'header-minimal',
								'area'        => 'header',
							],
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/missing',
								'placement'   => 'start',
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_keeps_all_valid_explicit_template_operations(): void {
		$context = [
			'availableParts' => [
				[
					'slug' => 'header-compact',
					'area' => 'header',
				],
				[
					'slug' => 'sidebar-main',
					'area' => 'sidebar',
				],
				[
					'slug' => 'footer-main',
					'area' => 'footer',
				],
				[
					'slug' => 'aside-main',
					'area' => 'aside',
				],
				[
					'slug' => 'promo-main',
					'area' => 'promo',
				],
			],
			'allowedAreas'   => [ 'header', 'sidebar', 'footer', 'aside', 'promo' ],
			'emptyAreas'     => [ 'header', 'sidebar', 'footer', 'aside', 'promo' ],
			'patterns'       => [],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Fill the open structural slots',
						'description' => 'Every explicit operation is part of the executable plan.',
						'operations'  => [
							[
								'type' => 'assign_template_part',
								'slug' => 'header-compact',
								'area' => 'header',
							],
							[
								'type' => 'assign_template_part',
								'slug' => 'sidebar-main',
								'area' => 'sidebar',
							],
							[
								'type' => 'assign_template_part',
								'slug' => 'footer-main',
								'area' => 'footer',
							],
							[
								'type' => 'assign_template_part',
								'slug' => 'aside-main',
								'area' => 'aside',
							],
							[
								'type' => 'assign_template_part',
								'slug' => 'promo-main',
								'area' => 'promo',
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertCount( 5, $result['suggestions'][0]['operations'] ?? [] );
		$this->assertSame( 'promo', $result['suggestions'][0]['operations'][4]['area'] ?? null );
	}

	public function test_parse_response_derives_template_part_operations_from_legacy_template_fields_without_keeping_advisory_pattern_summaries(): void {
		$context = [
			'assignedParts'  => [
				[
					'slug' => 'header',
					'area' => 'header',
				],
			],
			'availableParts' => [
				[
					'slug'  => 'header-minimal',
					'area'  => 'header',
					'title' => 'Header Minimal',
				],
				[
					'slug'  => 'footer-main',
					'area'  => 'footer',
					'title' => 'Footer Main',
				],
			],
			'allowedAreas'   => [ 'header', 'footer' ],
			'emptyAreas'     => [ 'footer' ],
			'patterns'       => [
				[
					'name' => 'theme/hero',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Legacy suggestion',
						'description'        => 'Use the compact header and add a hero pattern.',
						'templateParts'      => [
							[
								'slug'   => 'header-minimal',
								'area'   => 'header',
								'reason' => 'A smaller header matches the tighter layout.',
							],
							[
								'slug'   => 'footer-main',
								'area'   => 'footer',
								'reason' => 'Populate the empty footer slot.',
							],
						],
						'patternSuggestions' => [ 'theme/hero' ],
					],
				],
				'explanation' => 'Legacy payloads still derive executable steps.',
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'type'        => 'replace_template_part',
					'currentSlug' => 'header',
					'slug'        => 'header-minimal',
					'area'        => 'header',
				],
				[
					'type' => 'assign_template_part',
					'slug' => 'footer-main',
					'area' => 'footer',
				],
			],
			$result['suggestions'][0]['operations']
		);
		$this->assertSame(
			[
				[
					'slug'   => 'header-minimal',
					'area'   => 'header',
					'reason' => 'A smaller header matches the tighter layout.',
				],
				[
					'slug'   => 'footer-main',
					'area'   => 'footer',
					'reason' => 'Populate the empty footer slot.',
				],
			],
			$result['suggestions'][0]['templateParts']
		);
		$this->assertSame(
			[],
			$result['suggestions'][0]['patternSuggestions']
		);
	}

	public function test_parse_response_keeps_advisory_pattern_summaries_without_operations(): void {
		$context = [
			'assignedParts'  => [
				[
					'slug' => 'header',
					'area' => 'header',
				],
			],
			'availableParts' => [
				[
					'slug'  => 'footer-main',
					'area'  => 'footer',
					'title' => 'Footer Main',
				],
			],
			'allowedAreas'   => [ 'header', 'footer' ],
			'emptyAreas'     => [ 'footer' ],
			'patterns'       => [
				[
					'name' => 'theme/hero',
				],
				[
					'name' => 'theme/cta',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Consider a stronger hero pattern',
						'description'        => 'A hero pattern could make the opening feel more intentional without forcing a structural mutation yet.',
						'operations'         => [],
						'patternSuggestions' => [ 'theme/hero', 'theme/cta', 'missing/pattern' ],
					],
				],
				'explanation' => 'Pattern ideas stay advisory when there is no safe deterministic insertion anchor.',
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'label'              => 'Consider a stronger hero pattern',
					'description'        => 'A hero pattern could make the opening feel more intentional without forcing a structural mutation yet.',
					'operations'         => [],
					'templateParts'      => [],
					'patternSuggestions' => [ 'theme/hero', 'theme/cta' ],
				],
			],
			$result['suggestions']
		);
	}

	public function test_parse_response_rejects_assign_operations_for_occupied_areas(): void {
		$context = [
			'assignedParts'  => [
				[
					'slug' => 'header',
					'area' => 'header',
				],
			],
			'availableParts' => [
				[
					'slug'  => 'header-minimal',
					'area'  => 'header',
					'title' => 'Header Minimal',
				],
				[
					'slug'  => 'footer-main',
					'area'  => 'footer',
					'title' => 'Footer Main',
				],
			],
			'allowedAreas'   => [ 'header', 'footer' ],
			'emptyAreas'     => [ 'footer' ],
			'patterns'       => [],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Avoid destructive assigns',
						'description' => 'Only assign into truly empty areas.',
						'operations'  => [
							[
								'type' => 'assign_template_part',
								'slug' => 'header-minimal',
								'area' => 'header',
							],
							[
								'type' => 'assign_template_part',
								'slug' => 'footer-main',
								'area' => 'footer',
							],
						],
					],
				],
				'explanation' => 'Assignments should stay non-destructive.',
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_rejects_double_assigns_for_the_same_area(): void {
		$context = [
			'availableParts' => [
				[
					'slug'  => 'footer-main',
					'area'  => 'footer',
					'title' => 'Footer Main',
				],
				[
					'slug'  => 'footer-alt',
					'area'  => 'footer',
					'title' => 'Footer Alt',
				],
			],
			'allowedAreas'   => [ 'footer' ],
			'emptyAreas'     => [ 'footer' ],
			'patterns'       => [],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Conflicting footer assignments',
						'description' => 'Do not allow two assignments into one area.',
						'operations'  => [
							[
								'type' => 'assign_template_part',
								'slug' => 'footer-main',
								'area' => 'footer',
							],
							[
								'type' => 'assign_template_part',
								'slug' => 'footer-alt',
								'area' => 'footer',
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_rejects_double_replacements_for_the_same_area(): void {
		$context = [
			'assignedParts'  => [
				[
					'slug' => 'header',
					'area' => 'header',
				],
			],
			'availableParts' => [
				[
					'slug'  => 'header-minimal',
					'area'  => 'header',
					'title' => 'Header Minimal',
				],
				[
					'slug'  => 'header-large',
					'area'  => 'header',
					'title' => 'Header Large',
				],
			],
			'allowedAreas'   => [ 'header' ],
			'emptyAreas'     => [],
			'patterns'       => [],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Conflicting header replacements',
						'description' => 'Do not apply multiple replacements to the same area.',
						'operations'  => [
							[
								'type'        => 'replace_template_part',
								'currentSlug' => 'header',
								'slug'        => 'header-minimal',
								'area'        => 'header',
							],
							[
								'type'        => 'replace_template_part',
								'currentSlug' => 'header-minimal',
								'slug'        => 'header-large',
								'area'        => 'header',
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_rejects_assign_then_replace_for_the_same_area(): void {
		$context = [
			'availableParts' => [
				[
					'slug'  => 'footer-main',
					'area'  => 'footer',
					'title' => 'Footer Main',
				],
				[
					'slug'  => 'footer-alt',
					'area'  => 'footer',
					'title' => 'Footer Alt',
				],
			],
			'allowedAreas'   => [ 'footer' ],
			'emptyAreas'     => [ 'footer' ],
			'patterns'       => [],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Conflicting footer sequence',
						'description' => 'Do not allow an assign and replace in one area.',
						'operations'  => [
							[
								'type' => 'assign_template_part',
								'slug' => 'footer-main',
								'area' => 'footer',
							],
							[
								'type'        => 'replace_template_part',
								'currentSlug' => 'footer-main',
								'slug'        => 'footer-alt',
								'area'        => 'footer',
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_keeps_legacy_pattern_summaries_as_advisory_without_executable_operations(): void {
		$context = [
			'assignedParts'  => [],
			'availableParts' => [],
			'allowedAreas'   => [],
			'emptyAreas'     => [],
			'patterns'       => [
				[
					'name' => 'theme/hero',
				],
				[
					'name' => 'theme/cta',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Too many pattern inserts',
						'description'        => 'Legacy summaries should not imply multiple inserts.',
						'templateParts'      => [],
						'patternSuggestions' => [ 'theme/hero', 'theme/cta' ],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'label'              => 'Too many pattern inserts',
					'description'        => 'Legacy summaries should not imply multiple inserts.',
					'operations'         => [],
					'templateParts'      => [],
					'patternSuggestions' => [ 'theme/hero', 'theme/cta' ],
				],
			],
			$result['suggestions']
		);
	}

	public function test_parse_response_rejects_multiple_insert_pattern_operations(): void {
		$context = [
			'assignedParts'  => [],
			'availableParts' => [],
			'allowedAreas'   => [],
			'emptyAreas'     => [],
			'patterns'       => [
				[
					'name' => 'theme/hero',
				],
				[
					'name' => 'theme/cta',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Too many pattern inserts',
						'description' => 'Only one insert should be allowed.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'placement'   => 'start',
							],
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/cta',
								'placement'   => 'end',
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_rejects_template_start_pattern_insertions_without_a_live_start_anchor(): void {
		$context = [
			'assignedParts'            => [],
			'availableParts'           => [],
			'allowedAreas'             => [],
			'emptyAreas'               => [],
			'patterns'                 => [
				[
					'name' => 'theme/hero',
				],
			],
			'topLevelInsertionAnchors' => [
				[
					'placement' => 'end',
					'label'     => 'End of template',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Insert a hero first',
						'description' => 'Place a hero at the top of the template.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'placement'   => 'start',
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_accepts_template_start_pattern_insertions_when_a_live_start_anchor_exists(): void {
		$context = [
			'assignedParts'            => [],
			'availableParts'           => [],
			'allowedAreas'             => [],
			'emptyAreas'               => [],
			'patterns'                 => [
				[
					'name' => 'theme/hero',
				],
			],
			'topLevelInsertionAnchors' => [
				[
					'placement' => 'start',
					'label'     => 'Start of template',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Insert a hero first',
						'description' => 'Place a hero at the top of the template.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'placement'   => 'start',
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'theme/hero',
					'placement'   => 'start',
				],
			],
			$result['suggestions'][0]['operations'] ?? []
		);
	}

	public function test_parse_response_rejects_template_end_pattern_insertions_without_a_live_end_anchor(): void {
		$context = [
			'assignedParts'            => [],
			'availableParts'           => [],
			'allowedAreas'             => [],
			'emptyAreas'               => [],
			'patterns'                 => [
				[
					'name' => 'theme/hero',
				],
			],
			'topLevelInsertionAnchors' => [
				[
					'placement' => 'start',
					'label'     => 'Start of template',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Insert a hero last',
						'description' => 'Place a hero at the end of the template.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'placement'   => 'end',
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_build_system_documents_the_single_insert_pattern_limit(): void {
		$system = TemplatePrompt::build_system();

		$this->assertStringContainsString(
			'Each suggestion may contain at most one insert_pattern operation.',
			$system
		);
	}

	public function test_parse_response_accepts_anchored_pattern_insertions_against_top_level_template_paths_and_records_expected_targets(): void {
		$context = [
			'assignedParts'            => [],
			'availableParts'           => [],
			'allowedAreas'             => [],
			'emptyAreas'               => [],
			'patterns'                 => [
				[
					'name' => 'theme/hero',
				],
			],
			'topLevelBlockTree'        => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'label'      => 'Group',
					'attributes' => [
						'tagName' => 'main',
					],
					'childCount' => 0,
				],
				[
					'path'       => [ 1 ],
					'name'       => 'core/template-part',
					'label'      => 'header template part (header)',
					'attributes' => [
						'slug' => 'header',
						'area' => 'header',
					],
					'childCount' => 0,
					'slot'       => [
						'slug'    => 'header',
						'area'    => 'header',
						'isEmpty' => false,
					],
				],
			],
			'topLevelInsertionAnchors' => [
				[
					'placement' => 'start',
					'label'     => 'Start of template',
				],
				[
					'placement'  => 'before_block_path',
					'targetPath' => [ 1 ],
					'label'      => 'Before Header',
				],
				[
					'placement' => 'end',
					'label'     => 'End of template',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Anchor the hero ahead of the header',
						'description' => 'Insert the hero before the header slot.',
						'operations'  => [
							[
								'type'           => 'insert_pattern',
								'patternName'    => 'theme/hero',
								'placement'      => 'before_block_path',
								'targetPath'     => [ 1 ],
								'expectedTarget' => [
									'name'       => 'core/template-part',
									'label'      => 'header template part (header)',
									'attributes' => [
										'slug' => 'header',
										'area' => 'header',
									],
									'childCount' => 0,
									'slot'       => [
										'slug'    => 'header',
										'area'    => 'header',
										'isEmpty' => false,
									],
								],
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'label'              => 'Anchor the hero ahead of the header',
					'description'        => 'Insert the hero before the header slot.',
					'operations'         => [
						[
							'type'           => 'insert_pattern',
							'patternName'    => 'theme/hero',
							'placement'      => 'before_block_path',
							'targetPath'     => [ 1 ],
							'expectedTarget' => [
								'name'       => 'core/template-part',
								'label'      => 'header template part (header)',
								'attributes' => [
									'slug' => 'header',
									'area' => 'header',
								],
								'childCount' => 0,
								'slot'       => [
									'slug'    => 'header',
									'area'    => 'header',
									'isEmpty' => false,
								],
							],
						],
					],
					'templateParts'      => [],
					'patternSuggestions' => [ 'theme/hero' ],
				],
			],
			$result['suggestions']
		);
	}

	public function test_parse_response_rejects_anchored_pattern_insertions_for_unknown_top_level_paths(): void {
		$context = [
			'assignedParts'     => [],
			'availableParts'    => [],
			'allowedAreas'      => [],
			'emptyAreas'        => [],
			'patterns'          => [
				[
					'name' => 'theme/hero',
				],
			],
			'topLevelBlockTree' => [
				[
					'path' => [ 0 ],
					'name' => 'core/group',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Bad anchor',
						'description' => 'This should fail validation.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'placement'   => 'before_block_path',
								'targetPath'  => [ 9 ],
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_rejects_template_pattern_insertions_without_explicit_placement(): void {
		$context = [
			'assignedParts'  => [],
			'availableParts' => [],
			'allowedAreas'   => [],
			'emptyAreas'     => [],
			'patterns'       => [
				[
					'name' => 'theme/hero',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Legacy insert',
						'description' => 'This should fail validation now.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_rejects_legacy_template_insertions_that_include_target_paths_without_placement(): void {
		$context = [
			'assignedParts'     => [],
			'availableParts'    => [],
			'allowedAreas'      => [],
			'emptyAreas'        => [],
			'patterns'          => [
				[
					'name' => 'theme/hero',
				],
			],
			'topLevelBlockTree' => [
				[
					'path' => [ 0 ],
					'name' => 'core/group',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Invalid legacy insert',
						'description' => 'This path-targeted insert should be rejected.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'targetPath'  => [ 0 ],
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_rejects_legacy_template_insertions_with_malformed_target_paths(): void {
		$context = [
			'assignedParts'     => [],
			'availableParts'    => [],
			'allowedAreas'      => [],
			'emptyAreas'        => [],
			'patterns'          => [
				[
					'name' => 'theme/hero',
				],
			],
			'topLevelBlockTree' => [
				[
					'path' => [ 0 ],
					'name' => 'core/group',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Malformed legacy insert',
						'description' => 'This empty path should be rejected instead of falling back to the insertion point.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'targetPath'  => [],
							],
						],
					],
				],
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}

	public function test_parse_response_prefers_explicit_score_over_confidence_for_sorting(): void {
		$context = [
			'assignedParts'  => [],
			'availableParts' => [],
			'allowedAreas'   => [ 'header', 'footer' ],
			'emptyAreas'     => [ 'header', 'footer' ],
			'patterns'       => [
				[
					'name' => 'theme/hero',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Explicit score template idea',
						'description'        => 'This should sort first.',
						'patternSuggestions' => [ 'theme/hero' ],
						'score'              => 0.92,
						'confidence'         => 0.18,
					],
					[
						'label'              => 'Confidence template idea',
						'description'        => 'This should sort second.',
						'patternSuggestions' => [ 'theme/hero' ],
						'confidence'         => 0.84,
					],
				],
				'explanation' => 'Explicit scores should drive ordering.',
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame( 'Explicit score template idea', $result['suggestions'][0]['label'] );
		$this->assertSame( 0.92, $result['suggestions'][0]['ranking']['score'] );
	}

	public function test_parse_response_falls_back_when_nested_ranking_score_is_malformed(): void {
		$context = [
			'assignedParts'  => [],
			'availableParts' => [],
			'allowedAreas'   => [ 'header', 'footer' ],
			'emptyAreas'     => [ 'header', 'footer' ],
			'patterns'       => [
				[
					'name' => 'theme/hero',
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Fallback confidence template idea',
						'description'        => 'A malformed nested score should not zero this out.',
						'patternSuggestions' => [ 'theme/hero' ],
						'ranking'            => [
							'score' => [],
						],
						'confidence'         => 0.86,
					],
					[
						'label'              => 'Lower confidence template idea',
						'description'        => 'This should sort second.',
						'patternSuggestions' => [ 'theme/hero' ],
						'confidence'         => 0.84,
					],
				],
				'explanation' => 'Malformed nested scores should not suppress valid fallback confidence.',
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame( 'Fallback confidence template idea', $result['suggestions'][0]['label'] );
		$this->assertSame( 0.86, $result['suggestions'][0]['ranking']['score'] );
	}
}
