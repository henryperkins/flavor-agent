<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\TemplatePartPrompt;
use PHPUnit\Framework\TestCase;

final class TemplatePartPromptTest extends TestCase {

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
				],
			],
			$result['suggestions']
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
							'type'        => 'insert_pattern',
							'patternName' => 'theme/header-utility',
							'placement'   => 'before_block_path',
							'targetPath'  => [ 0, 1 ],
						],
					],
				],
			],
			$result['suggestions']
		);
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
						'description'        => 'Replace the wrapper and remove the redundant logo.',
						'patternSuggestions' => [],
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
					'description'        => 'Replace the wrapper and remove the redundant logo.',
					'blockHints'         => [],
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
			$result['suggestions']
		);
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
}
