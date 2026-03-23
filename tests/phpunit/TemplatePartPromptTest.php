<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\TemplatePartPrompt;
use PHPUnit\Framework\TestCase;

final class TemplatePartPromptTest extends TestCase {

	public function test_parse_response_keeps_only_valid_block_hints_and_patterns(): void {
		$context = [
			'blockTree' => [
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
			'patterns'  => [
				[
					'name' => 'theme/header-utility',
				],
				[
					'name' => 'theme/header-minimal',
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
