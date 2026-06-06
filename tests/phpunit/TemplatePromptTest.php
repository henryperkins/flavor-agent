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

		$this->assertStringNotContainsString( '## Site Guidelines', $prompt );
		$this->assertStringNotContainsString( 'Homepage for enterprise buyers.', $prompt );
		$this->assertStringNotContainsString( 'Keep accessibility requirements visible.', $prompt );
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

	public function test_build_user_includes_design_semantic_context(): void {
		$prompt = TemplatePrompt::build_user(
			[
				'templateRef'     => 'twentytwentyfive//archive',
				'templateType'    => 'archive',
				'title'           => 'Archive',
				'assignedParts'   => [],
				'emptyAreas'      => [],
				'availableParts'  => [],
				'patterns'        => [],
				'themeTokens'     => [],
				'designSemantics' => [
					'surface'         => 'template',
					'sectionRole'     => 'archive-list',
					'visualDensity'   => 'dense',
					'contrastContext' => 'unknown',
					'layoutRhythm'    => 'grid',
					'typographyRole'  => 'body',
					'mainDesignIssue' => 'rhythm',
					'template'        => [
						'emptyAreaCount' => 1,
					],
				],
			],
			''
		);

		$this->assertStringContainsString( '## Design semantic context', $prompt );
		$this->assertStringContainsString( 'Role: archive-list', $prompt );
		$this->assertStringContainsString( 'Template: emptyAreaCount=1', $prompt );
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
					'validationReasons'  => [],
				],
			],
			$this->strip_ranking_from_entries( $result['suggestions'] )
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
					'validationReasons'  => [],
				],
			],
			$this->strip_ranking_from_entries( $result['suggestions'] )
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
					'validationReasons'  => [],
				],
			],
			$this->strip_ranking_from_entries( $result['suggestions'] )
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
					'validationReasons'  => [],
				],
			],
			$this->strip_ranking_from_entries( $result['suggestions'] )
		);
	}

	public function test_parse_response_records_design_validator_complexity_signals_for_template_patterns(): void {
		$context = [
			'assignedParts'            => [],
			'availableParts'           => [],
			'allowedAreas'             => [],
			'emptyAreas'               => [],
			'designSemantics'          => [
				'sectionRole' => 'header',
			],
			'patterns'                 => [
				[
					'name' => 'theme/mega-header',
				],
			],
			'topLevelBlockTree'        => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/template-part',
					'label'      => 'Header template part',
					'attributes' => [
						'slug' => 'header',
						'area' => 'header',
					],
					'childCount' => 0,
				],
			],
			'topLevelInsertionAnchors' => [
				[
					'placement' => 'end',
					'label'     => 'End of template',
				],
			],
		];

		$result = TemplatePrompt::parse_response(
			wp_json_encode(
				[
					'suggestions' => [
						[
							'label'             => 'Add a dense header pattern',
							'description'       => 'Insert the larger header pattern at the end.',
							'contentBlockCount' => 12,
							'operations'        => [
								[
									'type'        => 'insert_pattern',
									'patternName' => 'theme/mega-header',
									'placement'   => 'end',
								],
							],
							'ranking'           => [
								'score' => 0.83,
							],
						],
					],
				]
			),
			$context,
			[
				'surface' => 'template',
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

	public function test_parse_response_blends_model_score_with_deterministic_quality_for_sorting(): void {
		$context = [
			'assignedParts'            => [],
			'availableParts'           => [],
			'allowedAreas'             => [ 'header', 'footer' ],
			'emptyAreas'               => [ 'header', 'footer' ],
			'patterns'                 => [
				[
					'name' => 'theme/hero',
				],
			],
			'topLevelBlockTree'        => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'label'      => 'Main group',
					'attributes' => [],
					'childCount' => 0,
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
						'label'              => 'High model advisory template idea',
						'description'        => 'This should sort second after deterministic validation.',
						'patternSuggestions' => [ 'theme/hero' ],
						'ranking'            => [
							'score' => 0.9,
						],
					],
					[
						'label'              => 'Lower model executable template idea',
						'description'        => 'This has stronger deterministic evidence.',
						'operations'         => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'placement'   => 'start',
							],
						],
						'patternSuggestions' => [ 'theme/hero' ],
						'score'              => 0.55,
					],
				],
				'explanation' => 'Deterministic quality should blend with model scores.',
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame( 'Lower model executable template idea', $result['suggestions'][0]['label'] );
		$this->assertGreaterThan( $result['suggestions'][1]['ranking']['score'], $result['suggestions'][0]['ranking']['score'] );
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
		$this->assertSame( 0.704, $result['suggestions'][0]['ranking']['score'] );
	}

	public function test_parse_response_merges_model_source_signals_with_plugin_ranking_signals(): void {
		$context = [
			'assignedParts'            => [],
			'availableParts'           => [],
			'allowedAreas'             => [ 'header', 'footer' ],
			'emptyAreas'               => [ 'header', 'footer' ],
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
						'label'       => 'Concrete template update with model signal',
						'description' => 'Keep model template context without losing plugin diagnostics.',
						'operations'  => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'placement'   => 'start',
							],
						],
						'ranking'     => [
							'score'         => 0.61,
							'sourceSignals' => [ 'model_template_balance' ],
						],
					],
				],
				'explanation' => 'Source signals should merge.',
			]
		);

		$result = TemplatePrompt::parse_response( $raw, $context );

		$this->assertIsArray( $result );
		$this->assertSame(
			[ 'llm_response', 'template_surface', 'has_operations', 'has_pattern_suggestions', 'model_template_balance' ],
			$result['suggestions'][0]['ranking']['sourceSignals']
		);
	}

	public function test_build_system_includes_required_nullable_ranking_shape_for_strict_schema(): void {
		$system = TemplatePrompt::build_system();

		$this->assertStringContainsString( '"ranking": null', $system );
		$this->assertStringContainsString( 'return ranking as null', strtolower( $system ) );
		$this->assertStringContainsString( 'use null for unknown ranking object values', strtolower( $system ) );
	}

	/**
	 * Base lookup set that makes a "happy-path" assign/replace/insert validate.
	 *
	 * Individual data-provider rows override slices of this set so each row can
	 * deterministically reach exactly one rejection branch while every earlier
	 * guard in the validator passes.
	 *
	 * @return array{
	 *     unused: array<string, array{area: string}>,
	 *     assigned: array{bySlug: array<string, array{slug: string, area: string}>, byArea: array<string, array{slug: string, area: string}>},
	 *     allowed: array<string, true>,
	 *     empty: array<string, true>,
	 *     pattern: array<string, mixed>,
	 *     block: array<string, array<string, mixed>>,
	 *     anchor: array<string, array<string, mixed>>
	 * }
	 */
	private function template_operation_lookups(): array {
		return [
			'unused'   => [
				'header-a' => [ 'area' => 'header' ],
				'header-b' => [ 'area' => 'header' ],
				'footer-a' => [ 'area' => 'footer' ],
			],
			'assigned' => [
				'bySlug' => [
					'old-footer' => [
						'slug' => 'old-footer',
						'area' => 'footer',
					],
				],
				'byArea' => [
					'footer' => [
						'slug' => 'old-footer',
						'area' => 'footer',
					],
				],
			],
			'allowed'  => [
				'header' => true,
				'footer' => true,
			],
			'empty'    => [
				'header' => true,
			],
			'pattern'  => [
				'my/pattern'    => true,
				'other/pattern' => true,
			],
			'block'    => [
				'0' => [
					'name' => 'core/group',
					'path' => [ 0 ],
				],
			],
			'anchor'   => [
				'start' => [ 'placement' => 'start' ],
				'end'   => [ 'placement' => 'end' ],
			],
		];
	}

	/**
	 * Merge per-row lookup overrides over the happy-path base set.
	 *
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function lookups_with( array $overrides ): array {
		return array_merge( $this->template_operation_lookups(), $overrides );
	}

	/**
	 * Full keyed-lookups base set for the suggestion-level seam.
	 *
	 * @return array<string, mixed>
	 */
	private function lookups(): array {
		return $this->template_operation_lookups();
	}

	/**
	 * Assigned-part lookup for the derive seam.
	 *
	 * @return array<string, mixed>
	 */
	private function assignedLookup(): array {
		return $this->template_operation_lookups()['assigned'];
	}

	/**
	 * Explicitly-empty area lookup for the derive seam.
	 *
	 * @return array<string, true>
	 */
	private function emptyLookup(): array {
		return $this->template_operation_lookups()['empty'];
	}

	public function test_invalid_operations_keep_advisory_remnant_with_reasons(): void {
		// A suggestion with an invalid operation but valid templateParts must be KEPT
		// as an advisory remnant carrying validationReasons (not discarded).
		$suggestions = [
			[
				'label'         => 'Footer composition',
				'operations'    => [
					[
						'type' => 'assign_template_part',
						'slug' => 'unknown',
						'area' => 'footer',
					],
				],
				'templateParts' => [
					[
						'slug'   => 'footer-a',
						'area'   => 'footer',
						'reason' => 'fills footer',
					],
				],
			],
		];

		$out = TemplatePrompt::validate_template_suggestions_for_tests( $suggestions, $this->lookups() );

		$this->assertCount( 1, $out ); // NOT discarded.
		$this->assertSame( [], $out[0]['operations'] ); // operations emptied.
		$this->assertNotEmpty( $out[0]['templateParts'] ); // advisory remnant kept.
		$this->assertNotEmpty( $out[0]['validationReasons'] ); // reason recorded.
	}

	public function test_derive_duplicate_area_returns_code(): void {
		$result = TemplatePrompt::derive_template_operations_for_tests(
			[
				[
					'slug' => 'a',
					'area' => 'header',
				],
				[
					'slug' => 'b',
					'area' => 'header',
				],
			],
			$this->assignedLookup(),
			$this->emptyLookup()
		);
		$this->assertTrue( $result['invalid'] );
		$this->assertSame( 'duplicate_area_mutation', $result['code'] );
	}

	/**
	 * One row per deterministic rejection branch in
	 * validate_template_operations(), mapping operations -> the single specific
	 * reason code that branch must now emit.
	 *
	 * @return array<string, array{0: array<int, mixed>, 1: array<string, mixed>, 2: string}>
	 */
	public function templateRejectionBranches(): array {
		return [
			// :1142-1144 — operation is not an array.
			'malformed: non-array operation'            => [
				[ 'not-an-array' ],
				$this->template_operation_lookups(),
				'malformed_operation',
			],

			// :1153-1155 — assign targets an area already mutated this call.
			'assign: area already mutated'              => [
				[
					[
						'type' => 'assign_template_part',
						'slug' => 'header-a',
						'area' => 'header',
					],
					[
						'type' => 'assign_template_part',
						'slug' => 'header-b',
						'area' => 'header',
					],
				],
				$this->template_operation_lookups(),
				'duplicate_area_mutation',
			],

			// :1157-1163 (split) — assign slug/area is not a valid unused part.
			'assign: not a valid unused part'           => [
				[
					[
						'type' => 'assign_template_part',
						'slug' => 'does-not-exist',
						'area' => 'header',
					],
				],
				$this->template_operation_lookups(),
				'invalid_template_area',
			],

			// :1157-1163 (split) — assign area is not empty (already assigned).
			'assign: area not empty / already assigned' => [
				[
					[
						'type' => 'assign_template_part',
						'slug' => 'footer-a',
						'area' => 'footer',
					],
				],
				$this->template_operation_lookups(),
				'duplicate_area_mutation',
			],

			// :1193-1195 — replace has no assigned part / empty current slug.
			'replace: no assigned part'                 => [
				[
					[
						'type' => 'replace_template_part',
						'slug' => 'header-a',
						'area' => 'sidebar',
					],
				],
				$this->template_operation_lookups(),
				'no_assigned_part',
			],

			// :1201-1203 — replace derives an empty area.
			'replace: area empty after derivation'      => [
				[
					[
						'type'        => 'replace_template_part',
						'currentSlug' => 'broken',
						'slug'        => 'header-a',
					],
				],
				$this->lookups_with(
					[
						'assigned' => [
							'bySlug' => [
								'broken' => [
									'slug' => 'broken',
									'area' => '',
								],
							],
							'byArea' => [],
						],
					]
				),
				'invalid_template_area',
			],

			// :1205-1207 — replace targets an area already mutated this call.
			'replace: area already mutated'             => [
				[
					[
						'type'        => 'replace_template_part',
						'currentSlug' => 'old-footer',
						'slug'        => 'footer-a',
						'area'        => 'footer',
					],
					[
						'type'        => 'replace_template_part',
						'currentSlug' => 'footer-a',
						'slug'        => 'header-a',
						'area'        => 'footer',
					],
				],
				$this->template_operation_lookups(),
				'duplicate_area_mutation',
			],

			// :1209-1211 — replace slug/area is not a valid unused part.
			'replace: not a valid unused part'          => [
				[
					[
						'type'        => 'replace_template_part',
						'currentSlug' => 'old-footer',
						'slug'        => 'does-not-exist',
						'area'        => 'footer',
					],
				],
				$this->template_operation_lookups(),
				'invalid_template_area',
			],

			// :1213-1218 — replace target area differs from the assigned area.
			'replace: assigned area mismatch'           => [
				[
					[
						'type'        => 'replace_template_part',
						'currentSlug' => 'mismatch-part',
						'slug'        => 'header-a',
						'area'        => 'header',
					],
				],
				$this->lookups_with(
					[
						'assigned' => [
							'bySlug' => [
								'mismatch-part' => [
									'slug' => 'mismatch-part',
									'area' => 'sidebar',
								],
							],
							'byArea' => [],
						],
					]
				),
				'area_mismatch',
			],

			// :1220-1222 — replace currentSlug === slug (no-op).
			'replace: same slug no-op'                  => [
				[
					[
						'type'        => 'replace_template_part',
						'currentSlug' => 'header-a',
						'slug'        => 'header-a',
						'area'        => 'header',
					],
				],
				$this->lookups_with(
					[
						'assigned' => [
							'bySlug' => [
								'header-a' => [
									'slug' => 'header-a',
									'area' => 'header',
								],
							],
							'byArea' => [],
						],
					]
				),
				'same_slug_no_op',
			],

			// :1248-1254 (split) — insert with an empty pattern name.
			'insert: empty pattern name'                => [
				[
					[
						'type'      => 'insert_pattern',
						'placement' => 'start',
					],
				],
				$this->template_operation_lookups(),
				'unknown_pattern',
			],

			// :1248-1254 (split) — insert with an empty placement.
			'insert: empty placement'                   => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'my/pattern',
					],
				],
				$this->template_operation_lookups(),
				'invalid_placement',
			],

			// :1248-1254 (split) — insert pattern not in the candidate lookup.
			'insert: pattern not in lookup'             => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'ghost/pattern',
						'placement'   => 'start',
					],
				],
				$this->template_operation_lookups(),
				'unknown_pattern',
			],

			// :1263-1265 — insert placement value is not in the allow-list.
			'insert: placement not allowed'             => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'my/pattern',
						'placement'   => 'middle',
					],
				],
				$this->template_operation_lookups(),
				'invalid_placement',
			],

			// :1267-1269 — insert targetPath key present but malformed.
			'insert: malformed targetPath'              => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'my/pattern',
						'placement'   => 'before_block_path',
						'targetPath'  => [ 'not-an-index' ],
					],
				],
				$this->template_operation_lookups(),
				'invalid_anchor',
			],

			// :1271-1279 — insert anchored path missing/unknown in block lookup.
			'insert: anchored path unknown'             => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'my/pattern',
						'placement'   => 'after_block_path',
						'targetPath'  => [ 5 ],
					],
				],
				$this->template_operation_lookups(),
				'invalid_anchor',
			],

			// :1281-1286 — insert start/end anchor missing from anchor lookup.
			'insert: start/end anchor missing'          => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'my/pattern',
						'placement'   => 'start',
					],
				],
				$this->lookups_with(
					[
						'anchor' => [
							'end' => [ 'placement' => 'end' ],
						],
					]
				),
				'invalid_anchor',
			],

			// :1288-1290 — insert is the second pattern insert this call.
			'insert: repeated pattern insert'           => [
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'my/pattern',
						'placement'   => 'start',
					],
					[
						'type'        => 'insert_pattern',
						'patternName' => 'other/pattern',
						'placement'   => 'end',
					],
				],
				$this->template_operation_lookups(),
				'repeated_pattern_insert',
			],

			// :1315-1316 — unknown operation type (default branch).
			'default: unknown operation type'           => [
				[
					[ 'type' => 'totally_bogus_operation' ],
				],
				$this->template_operation_lookups(),
				'unknown_operation_type',
			],
		];
	}

	/**
	 * @dataProvider templateRejectionBranches
	 *
	 * @param array<int, mixed>    $operations Raw operations from a suggestion.
	 * @param array<string, mixed> $lookups    Per-row validator lookups.
	 * @param string               $expected   Expected specific reason code.
	 */
	public function test_each_template_operation_branch_maps_to_a_specific_code(
		array $operations,
		array $lookups,
		string $expected
	): void {
		$result = TemplatePrompt::validate_template_operations_for_tests( $operations, $lookups );

		$this->assertTrue( $result['invalid'], 'Branch should be rejected.' );
		$this->assertSame( $expected, $result['code'] );
		$this->assertNotSame( 'operation_validation_failed', $result['code'] );
		$this->assertSame( [], $result['operations'] );
	}

	public function test_validate_template_operations_returns_empty_code_on_success(): void {
		$lookups = $this->template_operation_lookups();

		$result = TemplatePrompt::validate_template_operations_for_tests(
			[
				[
					'type' => 'assign_template_part',
					'slug' => 'header-a',
					'area' => 'header',
				],
			],
			$lookups
		);

		$this->assertFalse( $result['invalid'] );
		$this->assertSame( '', $result['code'] );
		$this->assertCount( 1, $result['operations'] );
	}
}
