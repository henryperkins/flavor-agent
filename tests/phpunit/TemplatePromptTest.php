<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\TemplatePrompt;
use PHPUnit\Framework\TestCase;

final class TemplatePromptTest extends TestCase {

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

	public function test_parse_response_keeps_only_valid_structured_template_operations(): void {
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
							[
								'type'        => 'replace_template_part',
								'currentSlug' => 'missing',
								'slug'        => 'footer-main',
								'area'        => 'footer',
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

	public function test_parse_response_derives_template_part_operations_from_legacy_template_fields_and_keeps_pattern_summaries_advisory(): void {
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
			[ 'theme/hero' ],
			$result['suggestions'][0]['patternSuggestions']
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

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'label'              => 'Avoid destructive assigns',
					'description'        => 'Only assign into truly empty areas.',
					'operations'         => [
						[
							'type' => 'assign_template_part',
							'slug' => 'footer-main',
							'area' => 'footer',
						],
					],
					'templateParts'      => [
						[
							'slug'   => 'footer-main',
							'area'   => 'footer',
							'reason' => '',
						],
					],
					'patternSuggestions' => [],
				],
			],
			$result['suggestions']
		);
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

	public function test_parse_response_keeps_legacy_pattern_summaries_as_advisory_only(): void {
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
}
