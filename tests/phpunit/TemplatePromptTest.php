<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\TemplatePrompt;
use PHPUnit\Framework\TestCase;

final class TemplatePromptTest extends TestCase {

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

	public function test_parse_response_derives_operations_from_legacy_template_fields(): void {
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
				[
					'type'        => 'insert_pattern',
					'patternName' => 'theme/hero',
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

	public function test_parse_response_rejects_multiple_implicit_pattern_inserts(): void {
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

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_recommendations', $result->get_error_code() );
	}
} 
