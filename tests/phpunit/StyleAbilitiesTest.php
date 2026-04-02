<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class StyleAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		WordPressTestState::$options         = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'chat-deployment',
		];
		WordPressTestState::$global_settings = [
			'color' => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
		];
	}

	public function test_recommend_style_requires_global_styles_scope(): void {
		$result = StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'template',
					'scopeKey'       => 'wp_template:theme//home',
					'globalStylesId' => '17',
				],
				'styleContext' => [],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_style_scope', $result->get_error_code() );
	}

	public function test_recommend_style_returns_validated_style_suggestions(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [
								[
									'label'       => 'Use accent canvas',
									'description' => 'Apply the accent preset to the site background.',
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
									],
								],
							],
							'explanation' => 'Prefer theme palette colors.',
						]
					),
				]
			),
		];

		$result = StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'global-styles',
					'scopeKey'       => 'global_styles:17',
					'globalStylesId' => '17',
				],
				'styleContext' => [
					'currentConfig'         => [ 'styles' => [] ],
					'mergedConfig'          => [ 'styles' => [] ],
					'availableVariations'   => [],
					'themeTokenDiagnostics' => [
						'source'      => 'stable',
						'settingsKey' => 'features',
						'reason'      => 'stable-parity',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Use accent canvas', $result['suggestions'][0]['label'] ?? null );
		$this->assertSame( 'set_styles', $result['suggestions'][0]['operations'][0]['type'] ?? null );
	}

	public function test_recommend_style_downgrades_invalid_freeform_operations_to_advisory(): void {
		WordPressTestState::$global_settings      = [
			'color'  => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
			'border' => [
				'style' => true,
			],
		];
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [
								[
									'label'       => 'Unsafe border treatment',
									'description' => 'Uses an invalid freeform border style.',
									'category'    => 'border',
									'tone'        => 'executable',
									'operations'  => [
										[
											'type'      => 'set_styles',
											'path'      => [ 'border', 'style' ],
											'value'     => [ 'style' => 'glow' ],
											'valueType' => 'freeform',
										],
									],
								],
							],
							'explanation' => 'Unsafe freeform values should be stripped.',
						]
					),
				]
			),
		];

		$result = StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'global-styles',
					'scopeKey'       => 'global_styles:17',
					'globalStylesId' => '17',
				],
				'styleContext' => [
					'currentConfig'         => [ 'styles' => [] ],
					'mergedConfig'          => [ 'styles' => [] ],
					'availableVariations'   => [],
					'themeTokenDiagnostics' => [
						'source'      => 'stable',
						'settingsKey' => 'features',
						'reason'      => 'stable-parity',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'advisory', $result['suggestions'][0]['tone'] ?? null );
		$this->assertSame( [], $result['suggestions'][0]['operations'] ?? null );
	}

	public function test_supported_style_paths_keep_palette_backed_color_controls_when_custom_colors_are_disabled(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'custom'  => false,
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
		];

		$paths = StyleAbilities::supported_style_paths();

		$this->assertContains(
			[
				'path'        => [ 'color', 'background' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertContains(
			[
				'path'        => [ 'color', 'text' ],
				'valueSource' => 'color',
			],
			$paths
		);
	}

	public function test_supported_style_paths_omit_disabled_color_controls(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'background' => false,
				'text'       => false,
				'link'       => false,
				'button'     => false,
				'heading'    => false,
				'palette'    => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
		];

		$paths = StyleAbilities::supported_style_paths();

		$this->assertNotContains(
			[
				'path'        => [ 'color', 'background' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'color', 'text' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'elements', 'link', 'color', 'text' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'elements', 'button', 'color', 'background' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'elements', 'button', 'color', 'text' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'elements', 'heading', 'color', 'text' ],
				'valueSource' => 'color',
			],
			$paths
		);
	}

	public function test_recommend_style_strips_non_semantic_config_fields_from_prompt_context(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [],
							'explanation' => 'Use semantic Global Styles context only.',
						]
					),
				]
			),
		];

		StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'global-styles',
					'scopeKey'       => 'global_styles:17',
					'globalStylesId' => '17',
				],
				'styleContext' => [
					'currentConfig'         => [
						'settings' => [
							'color' => [
								'palette' => [
									'default' => true,
								],
							],
						],
						'styles'   => [
							'color' => [
								'background' => 'var:preset|color|accent',
							],
						],
						'_links'   => [
							'self' => [
								[ 'href' => '/wp/v2/global-styles/17' ],
							],
						],
					],
					'mergedConfig'          => [
						'settings' => [],
						'styles'   => [],
						'_links'   => [
							'self' => [
								[ 'href' => '/wp/v2/global-styles/17?context=edit' ],
							],
						],
					],
					'availableVariations'   => [],
					'themeTokenDiagnostics' => [
						'source'      => 'stable',
						'settingsKey' => 'features',
						'reason'      => 'stable-parity',
					],
				],
			]
		);

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);

		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'"settings":{"color":{"palette":{"default":true}}}',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'_links',
			(string) ( $request_body['input'] ?? '' )
		);
	}
}
