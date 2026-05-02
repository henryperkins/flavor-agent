<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\StyleContrastValidator;
use PHPUnit\Framework\TestCase;

final class StyleContrastValidatorTest extends TestCase {

	public function test_resolver_form_3_accepts_hex_value(): void {
		$this->assertSame(
			[
				'resolved' => true,
				'hex'      => '#112233',
				'reason'   => null,
			],
			StyleContrastValidator::resolve_color_value( '#112233', [ 'colorPresets' => [] ] )
		);
	}

	public function test_resolver_form_3_truncates_alpha_channel(): void {
		$this->assertSame(
			[
				'resolved' => true,
				'hex'      => '#112233',
				'reason'   => null,
			],
			StyleContrastValidator::resolve_color_value( '#11223344', [ 'colorPresets' => [] ] )
		);
	}

	public function test_resolver_form_1_resolves_flavor_agent_preset_reference(): void {
		$this->assertSame(
			[
				'resolved' => true,
				'hex'      => '#aabbcc',
				'reason'   => null,
			],
			StyleContrastValidator::resolve_color_value(
				'var:preset|color|accent',
				[
					'colorPresets' => [
						[
							'slug'  => 'base',
							'color' => '#ffffff',
						],
						[
							'slug'  => 'accent',
							'color' => '#aabbcc',
						],
					],
				]
			)
		);
	}

	public function test_resolver_form_2_resolves_wp_css_var_preset_reference(): void {
		$this->assertSame(
			[
				'resolved' => true,
				'hex'      => '#aabbcc',
				'reason'   => null,
			],
			StyleContrastValidator::resolve_color_value(
				'var(--wp--preset--color--accent)',
				[
					'colorPresets' => [
						[
							'slug'  => 'accent',
							'color' => '#aabbcc',
						],
					],
				]
			)
		);
	}

	public function test_resolver_form_1_unknown_slug_returns_unknown_preset(): void {
		$this->assertSame(
			[
				'resolved' => false,
				'hex'      => null,
				'reason'   => 'unknown-preset',
			],
			StyleContrastValidator::resolve_color_value(
				'var:preset|color|nope',
				[
					'colorPresets' => [
						[
							'slug'  => 'accent',
							'color' => '#aabbcc',
						],
					],
				]
			)
		);
	}

	public function test_resolver_form_2_unknown_slug_returns_unknown_preset(): void {
		$this->assertSame(
			[
				'resolved' => false,
				'hex'      => null,
				'reason'   => 'unknown-preset',
			],
			StyleContrastValidator::resolve_color_value(
				'var(--wp--preset--color--nope)',
				[
					'colorPresets' => [
						[
							'slug'  => 'accent',
							'color' => '#aabbcc',
						],
					],
				]
			)
		);
	}

	public function test_resolver_form_1_empty_preset_color_returns_unknown_preset(): void {
		$this->assertSame(
			[
				'resolved' => false,
				'hex'      => null,
				'reason'   => 'unknown-preset',
			],
			StyleContrastValidator::resolve_color_value(
				'var:preset|color|accent',
				[
					'colorPresets' => [
						[
							'slug'  => 'accent',
							'color' => '',
						],
					],
				]
			)
		);
	}

	public function test_resolver_form_4_null_returns_missing(): void {
		$this->assertSame(
			[
				'resolved' => false,
				'hex'      => null,
				'reason'   => 'missing',
			],
			StyleContrastValidator::resolve_color_value( null, [ 'colorPresets' => [] ] )
		);
	}

	public function test_resolver_form_4_empty_string_returns_missing(): void {
		$this->assertSame(
			[
				'resolved' => false,
				'hex'      => null,
				'reason'   => 'missing',
			],
			StyleContrastValidator::resolve_color_value( '', [ 'colorPresets' => [] ] )
		);
	}

	/**
	 * @dataProvider provide_form_5_values
	 */
	public function test_resolver_form_5_returns_unknown_form( mixed $value ): void {
		$this->assertSame(
			[
				'resolved' => false,
				'hex'      => null,
				'reason'   => 'unknown-form',
			],
			StyleContrastValidator::resolve_color_value( $value, [ 'colorPresets' => [] ] )
		);
	}

	public static function provide_form_5_values(): array {
		return [
			'named color'   => [ 'red' ],
			'rgb function'  => [ 'rgb(0, 0, 0)' ],
			'hsl function'  => [ 'hsl(0, 0%, 0%)' ],
			'rgba function' => [ 'rgba(0, 0, 0, 0.5)' ],
			'currentColor'  => [ 'currentColor' ],
			'inherit'       => [ 'inherit' ],
			'transparent'   => [ 'transparent' ],
			'gradient'      => [ 'linear-gradient(to right, #000, #fff)' ],
			'numeric'       => [ 0 ],
			'array'         => [ [ '#000000' ] ],
		];
	}

	public function test_contrast_ratio_black_on_white_is_21(): void {
		$this->assertEqualsWithDelta(
			21.0,
			StyleContrastValidator::contrast_ratio( '#000000', '#ffffff' ),
			0.01
		);
	}

	public function test_contrast_ratio_same_color_is_1(): void {
		$this->assertEqualsWithDelta(
			1.0,
			StyleContrastValidator::contrast_ratio( '#777777', '#777777' ),
			0.01
		);
	}

	public function test_contrast_ratio_is_symmetric(): void {
		$forward  = StyleContrastValidator::contrast_ratio( '#112233', '#ddeeff' );
		$backward = StyleContrastValidator::contrast_ratio( '#ddeeff', '#112233' );

		$this->assertEqualsWithDelta( $forward, $backward, 0.0001 );
	}

	public function test_contrast_ratio_low_contrast_pair(): void {
		$this->assertLessThan(
			4.5,
			StyleContrastValidator::contrast_ratio( '#888888', '#aaaaaa' )
		);
	}

	public function test_contrast_ratio_at_threshold(): void {
		$this->assertGreaterThanOrEqual(
			4.5,
			StyleContrastValidator::contrast_ratio( '#767676', '#ffffff' )
		);
	}

	public function test_scope_key_root_color_text(): void {
		$this->assertSame(
			'root',
			StyleContrastValidator::scope_key_for_operation(
				[
					'type' => 'set_styles',
					'path' => [ 'color', 'text' ],
				]
			)
		);
	}

	public function test_scope_key_root_color_background(): void {
		$this->assertSame(
			'root',
			StyleContrastValidator::scope_key_for_operation(
				[
					'type' => 'set_styles',
					'path' => [ 'color', 'background' ],
				]
			)
		);
	}

	public function test_scope_key_elements_button(): void {
		$this->assertSame(
			'elements.button',
			StyleContrastValidator::scope_key_for_operation(
				[
					'type' => 'set_styles',
					'path' => [ 'elements', 'button', 'color', 'text' ],
				]
			)
		);
	}

	public function test_scope_key_elements_link(): void {
		$this->assertSame(
			'elements.link',
			StyleContrastValidator::scope_key_for_operation(
				[
					'type' => 'set_styles',
					'path' => [ 'elements', 'link', 'color', 'text' ],
				]
			)
		);
	}

	public function test_scope_key_elements_heading(): void {
		$this->assertSame(
			'elements.heading',
			StyleContrastValidator::scope_key_for_operation(
				[
					'type' => 'set_styles',
					'path' => [ 'elements', 'heading', 'color', 'text' ],
				]
			)
		);
	}

	public function test_scope_key_block_styles(): void {
		$this->assertSame(
			'blocks.core/paragraph',
			StyleContrastValidator::scope_key_for_operation(
				[
					'type'      => 'set_block_styles',
					'blockName' => 'core/paragraph',
					'path'      => [ 'color', 'background' ],
				]
			)
		);
	}

	public function test_scope_key_border_color_returns_null(): void {
		$this->assertNull(
			StyleContrastValidator::scope_key_for_operation(
				[
					'type' => 'set_styles',
					'path' => [ 'border', 'color' ],
				]
			)
		);
	}

	public function test_scope_key_unknown_readable_path_returns_unsupported_marker(): void {
		$this->assertSame(
			'unsupported',
			StyleContrastValidator::scope_key_for_operation(
				[
					'type' => 'set_styles',
					'path' => [ 'elements', 'caption', 'color', 'text' ],
				]
			)
		);
	}

	public function test_scope_key_typography_op_returns_null(): void {
		$this->assertNull(
			StyleContrastValidator::scope_key_for_operation(
				[
					'type' => 'set_styles',
					'path' => [ 'typography', 'fontSize' ],
				]
			)
		);
	}

	public function test_scope_key_set_theme_variation_returns_null(): void {
		$this->assertNull(
			StyleContrastValidator::scope_key_for_operation(
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 1,
				]
			)
		);
	}

	public function test_complement_root_uses_merged_styles_color_branch(): void {
		$this->assertSame(
			'#ffffff',
			StyleContrastValidator::merged_complement_hex(
				'root',
				'background',
				[
					'styleContext' => [
						'mergedConfig' => [
							'styles' => [
								'color' => [
									'background' => '#ffffff',
								],
							],
						],
					],
				]
			)
		);
	}

	public function test_complement_element_button_uses_element_styles_then_root(): void {
		$this->assertSame(
			'#aabbcc',
			StyleContrastValidator::merged_complement_hex(
				'elements.button',
				'background',
				[
					'styleContext' => [
						'themeTokens'  => [
							'colorPresets'  => [
								[
									'slug'  => 'accent',
									'color' => '#aabbcc',
								],
							],
							'elementStyles' => [
								'button' => [
									'base' => [
										'background' => 'var(--wp--preset--color--accent)',
									],
								],
							],
						],
						'mergedConfig' => [
							'styles' => [
								'color' => [
									'background' => '#ffffff',
								],
							],
						],
					],
				]
			)
		);
	}

	public function test_complement_element_prefers_request_merged_styles_over_server_tokens(): void {
		$this->assertSame(
			'#111111',
			StyleContrastValidator::merged_complement_hex(
				'elements.button',
				'background',
				[
					'styleContext' => [
						'themeTokens'  => [
							'colorPresets'  => [],
							'elementStyles' => [
								'button' => [
									'base' => [
										'background' => '#ffffff',
									],
								],
							],
						],
						'mergedConfig' => [
							'styles' => [
								'color'    => [
									'background' => '#eeeeee',
								],
								'elements' => [
									'button' => [
										'color' => [
											'background' => '#111111',
										],
									],
								],
							],
						],
					],
				]
			)
		);
	}

	public function test_complement_element_falls_back_to_root_when_missing(): void {
		$this->assertSame(
			'#ffffff',
			StyleContrastValidator::merged_complement_hex(
				'elements.link',
				'background',
				[
					'styleContext' => [
						'themeTokens'  => [
							'colorPresets'  => [],
							'elementStyles' => [
								'link' => [
									'base' => [
										'text' => '#0000ff',
									],
								],
							],
						],
						'mergedConfig' => [
							'styles' => [
								'color' => [
									'background' => '#ffffff',
								],
							],
						],
					],
				]
			)
		);
	}

	public function test_complement_block_uses_block_branch_then_root(): void {
		$this->assertSame(
			'#eeeeee',
			StyleContrastValidator::merged_complement_hex(
				'blocks.core/quote',
				'background',
				[
					'styleContext' => [
						'themeTokens'  => [ 'colorPresets' => [] ],
						'mergedConfig' => [
							'styles' => [
								'color'  => [
									'background' => '#ffffff',
								],
								'blocks' => [
									'core/quote' => [
										'color' => [
											'background' => '#eeeeee',
										],
									],
								],
							],
						],
					],
				]
			)
		);
	}

	public function test_complement_block_falls_back_to_root(): void {
		$this->assertSame(
			'#ffffff',
			StyleContrastValidator::merged_complement_hex(
				'blocks.core/paragraph',
				'background',
				[
					'styleContext' => [
						'themeTokens'  => [ 'colorPresets' => [] ],
						'mergedConfig' => [
							'styles' => [
								'color' => [
									'background' => '#ffffff',
								],
							],
						],
					],
				]
			)
		);
	}

	public function test_complement_returns_null_when_all_sources_empty(): void {
		$this->assertNull(
			StyleContrastValidator::merged_complement_hex(
				'root',
				'text',
				[
					'styleContext' => [
						'themeTokens'  => [ 'colorPresets' => [] ],
						'mergedConfig' => [ 'styles' => [] ],
					],
				]
			)
		);
	}

	public function test_evaluate_passes_high_contrast_pair(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'background' ],
					'value' => 'var:preset|color|base',
				],
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'text' ],
					'value' => 'var:preset|color|accent',
				],
			],
			$this->context_with_palette()
		);

		$this->assertTrue( $result['passed'] );
		$this->assertNull( $result['kind'] );
		$this->assertNull( $result['reason'] );
		$this->assertNull( $result['ratio'] );
	}

	public function test_evaluate_fails_low_contrast_pair(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'background' ],
					'value' => 'var:preset|color|wash',
				],
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'text' ],
					'value' => 'var:preset|color|base',
				],
			],
			$this->context_with_palette()
		);

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'low_ratio', $result['kind'] );
		$this->assertNotNull( $result['ratio'] );
		$this->assertLessThan( 4.5, $result['ratio'] );
		$this->assertStringContainsString( 'root', (string) $result['reason'] );
	}

	public function test_evaluate_solo_op_evaluated_against_merged_complement(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'background' ],
					'value' => 'var:preset|color|base',
				],
			],
			$this->context_with_palette()
		);

		$this->assertTrue( $result['passed'] );
	}

	public function test_evaluate_solo_op_with_unresolved_complement_fails_unavailable(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'background' ],
					'value' => 'var:preset|color|base',
				],
			],
			[
				'styleContext' => [
					'themeTokens'  => [
						'colorPresets' => [
							[
								'slug'  => 'base',
								'color' => '#ffffff',
							],
						],
					],
					'mergedConfig' => [ 'styles' => [] ],
				],
			]
		);

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'unavailable', $result['kind'] );
		$this->assertNull( $result['ratio'] );
		$this->assertStringContainsString( 'root', (string) $result['reason'] );
	}

	public function test_evaluate_uses_last_write_when_same_scope_side_is_written_twice(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'background' ],
					'value' => 'var:preset|color|base',
				],
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'background' ],
					'value' => 'var:preset|color|wash',
				],
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'text' ],
					'value' => 'var:preset|color|base',
				],
			],
			$this->context_with_palette()
		);

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'low_ratio', $result['kind'] );
		$this->assertStringContainsString( 'wash', (string) $result['reason'] );
	}

	public function test_evaluate_proposed_op_with_unresolved_value_fails_unavailable(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'background' ],
					'value' => 'var:preset|color|nope',
				],
			],
			[
				'styleContext' => [
					'themeTokens'  => [
						'colorPresets'  => [
							[
								'slug'  => 'base',
								'color' => '#ffffff',
							],
						],
						'elementStyles' => [],
					],
					'mergedConfig' => [
						'styles' => [
							'color' => [
								'background' => '#ffffff',
								'text'       => '#000000',
							],
						],
					],
				],
			]
		);

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'unavailable', $result['kind'] );
		$this->assertStringContainsString( 'background', (string) $result['reason'] );
		$this->assertStringContainsString( 'root', (string) $result['reason'] );
	}

	public function test_evaluate_unsupported_readable_path_fails_unavailable(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'elements', 'caption', 'color', 'text' ],
					'value' => 'var:preset|color|base',
				],
			],
			$this->context_with_palette()
		);

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'unavailable', $result['kind'] );
	}

	public function test_evaluate_border_color_op_is_skipped(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'border', 'color' ],
					'value' => 'var:preset|color|base',
				],
			],
			$this->context_with_palette()
		);

		$this->assertTrue( $result['passed'] );
	}

	public function test_evaluate_first_failure_by_enum_order(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'background' ],
					'value' => 'var:preset|color|wash',
				],
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'text' ],
					'value' => 'var:preset|color|wash',
				],
				[
					'type'  => 'set_styles',
					'path'  => [ 'elements', 'button', 'color', 'background' ],
					'value' => 'var:preset|color|wash',
				],
			],
			[
				'styleContext' => [
					'themeTokens'  => [
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
					'mergedConfig' => [
						'styles' => [
							'color' => [
								'background' => '#ffffff',
								'text'       => '#dddddd',
							],
						],
					],
				],
			]
		);

		$this->assertFalse( $result['passed'] );
		$this->assertStringContainsString( 'root', (string) $result['reason'] );
	}

	public function test_evaluate_blocks_per_block_distinct(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'      => 'set_block_styles',
					'blockName' => 'core/button',
					'path'      => [ 'color', 'background' ],
					'value'     => 'var:preset|color|dark',
				],
				[
					'type'      => 'set_block_styles',
					'blockName' => 'core/button',
					'path'      => [ 'color', 'text' ],
					'value'     => 'var:preset|color|base',
				],
				[
					'type'      => 'set_block_styles',
					'blockName' => 'core/paragraph',
					'path'      => [ 'color', 'background' ],
					'value'     => 'var:preset|color|wash',
				],
				[
					'type'      => 'set_block_styles',
					'blockName' => 'core/paragraph',
					'path'      => [ 'color', 'text' ],
					'value'     => 'var:preset|color|base',
				],
			],
			[
				'styleContext' => [
					'themeTokens'  => [
						'colorPresets'  => [
							[
								'slug'  => 'base',
								'color' => '#ffffff',
							],
							[
								'slug'  => 'wash',
								'color' => '#dddddd',
							],
							[
								'slug'  => 'dark',
								'color' => '#111111',
							],
						],
						'elementStyles' => [],
					],
					'mergedConfig' => [
						'styles' => [
							'color' => [
								'background' => '#ffffff',
								'text'       => '#000000',
							],
						],
					],
				],
			]
		);

		$this->assertFalse( $result['passed'] );
		$this->assertStringContainsString( 'core/paragraph', (string) $result['reason'] );
	}

	public function test_evaluate_solo_element_color_uses_request_merged_complement(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'elements', 'button', 'color', 'text' ],
					'value' => 'var:preset|color|base',
				],
			],
			[
				'styleContext' => [
					'themeTokens'  => [
						'colorPresets'  => [
							[
								'slug'  => 'base',
								'color' => '#ffffff',
							],
						],
						'elementStyles' => [
							'button' => [
								'base' => [
									'background' => '#ffffff',
								],
							],
						],
					],
					'mergedConfig' => [
						'styles' => [
							'color'    => [
								'background' => '#ffffff',
								'text'       => '#000000',
							],
							'elements' => [
								'button' => [
									'color' => [
										'background' => '#111111',
									],
								],
							],
						],
					],
				],
			]
		);

		$this->assertTrue( $result['passed'] );
	}

	public function test_evaluate_set_theme_variation_does_not_affect_passed(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 1,
					'variationTitle' => 'Midnight',
				],
			],
			$this->context_with_palette()
		);

		$this->assertTrue( $result['passed'] );
	}

	public function test_evaluate_rejects_theme_variation_mixed_with_readable_color_operations(): void {
		$result = StyleContrastValidator::evaluate(
			[
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 1,
					'variationTitle' => 'Midnight',
				],
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'text' ],
					'value' => 'var:preset|color|accent',
				],
			],
			$this->context_with_palette()
		);

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'unavailable', $result['kind'] );
		$this->assertStringContainsString( 'theme variation', (string) $result['reason'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function context_with_palette(): array {
		return [
			'styleContext' => [
				'themeTokens'  => [
					'colorPresets'  => [
						[
							'slug'  => 'base',
							'color' => '#ffffff',
						],
						[
							'slug'  => 'accent',
							'color' => '#222222',
						],
						[
							'slug'  => 'wash',
							'color' => '#dddddd',
						],
					],
					'elementStyles' => [],
				],
				'mergedConfig' => [
					'styles' => [
						'color' => [
							'background' => '#ffffff',
							'text'       => '#000000',
						],
					],
				],
			],
		];
	}
}
