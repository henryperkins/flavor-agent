<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\StylePrompt;
use PHPUnit\Framework\TestCase;

final class StylePromptTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function build_context(): array {
		return [
			'scope'        => [
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
				'stylesheet'     => 'theme-slug',
			],
			'styleContext' => [
				'currentConfig'         => [
					'styles' => [],
				],
				'mergedConfig'          => [
					'styles' => [],
				],
				'themeTokenDiagnostics' => [
					'source'      => 'stable',
					'settingsKey' => 'features',
					'reason'      => 'stable-parity',
				],
				'themeTokens'           => [
					'colors'          => [ 'accent: #ff5500' ],
					'fontSizes'       => [ 'body: 1rem' ],
					'fontFamilies'    => [ 'display: Georgia, serif' ],
					'spacing'         => [ 's: 0.5rem' ],
					'shadows'         => [ 'soft: 0 10px 30px rgba(0,0,0,0.1)' ],
					'enabledFeatures' => [
						'customColors' => true,
					],
					'elementStyles'   => [],
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
						'styles'      => [],
					],
				],
			],
		];
	}

	public function test_build_user_includes_scope_diagnostics_and_variations(): void {
		$prompt = StylePrompt::build_user(
			$this->build_context(),
			'Make the site feel warmer.'
		);

		$this->assertStringContainsString( 'global_styles:17', $prompt );
		$this->assertStringContainsString( 'stable-parity', $prompt );
		$this->assertStringContainsString( 'color.background', $prompt );
		$this->assertStringContainsString( '#1 Midnight - Dark editorial palette', $prompt );
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
		$this->assertCount( 1, $result['suggestions'][0]['operations'] );
		$this->assertSame( 'advisory', $result['suggestions'][1]['tone'] );
		$this->assertSame( [], $result['suggestions'][1]['operations'] );
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
							'label'       => 'Adopt Midnight with accent background',
							'description' => 'Start from the Midnight variation, then warm the canvas slightly.',
							'category'    => 'variation',
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
									'type'           => 'set_theme_variation',
									'variationIndex' => 1,
									'variationTitle' => 'Midnight',
								],
								[
									'type'           => 'set_theme_variation',
									'variationIndex' => 0,
									'variationTitle' => 'Default',
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
}
