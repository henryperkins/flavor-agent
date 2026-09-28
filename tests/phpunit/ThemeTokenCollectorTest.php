<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\ThemeTokenCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ThemeTokenCollectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->reset_static_cache();
	}

	protected function tearDown(): void {
		$this->reset_static_cache();

		parent::tearDown();
	}

	private function reset_static_cache(): void {
		$reflection = new ReflectionClass( ThemeTokenCollector::class );

		$hash_property = $reflection->getProperty( 'cached_hash' );
		$hash_property->setAccessible( true );
		$hash_property->setValue( null, null );

		$tokens_property = $reflection->getProperty( 'cached_tokens' );
		$tokens_property->setAccessible( true );
		$tokens_property->setValue( null, null );
	}

	public function test_for_active_theme_returns_sanitized_metadata_from_wp_theme_object(): void {
		WordPressTestState::$active_theme = [
			'name'       => "Twenty Twenty-Five\n",
			'version'    => '1.2.3',
			'stylesheet' => 'Twenty-TwentyFive',
			'template'   => 'Parent Theme',
		];

		$result = ( new ThemeTokenCollector() )->for_active_theme();

		// sanitize_text_field strips trailing whitespace/newlines.
		$this->assertSame( 'Twenty Twenty-Five', $result['name'] );
		$this->assertSame( '1.2.3', $result['version'] );
		// sanitize_key lowercases and removes uppercase characters / disallowed chars.
		$this->assertSame( 'twenty-twentyfive', $result['stylesheet'] );
		$this->assertSame( 'parenttheme', $result['template'] );
	}

	public function test_for_active_theme_returns_empty_strings_when_theme_data_missing(): void {
		WordPressTestState::$active_theme = [];

		$result = ( new ThemeTokenCollector() )->for_active_theme();

		$this->assertSame(
			[
				'name'       => '',
				'version'    => '',
				'stylesheet' => '',
				'template'   => '',
			],
			$result
		);
	}

	public function test_for_tokens_returns_documented_diagnostics_block(): void {
		$tokens = ( new ThemeTokenCollector() )->for_tokens();

		$this->assertSame(
			[
				'source'      => 'server',
				'settingsKey' => 'wp_get_global_settings',
				'reason'      => 'server-global-settings',
			],
			$tokens['diagnostics']
		);
	}

	public function test_for_tokens_maps_color_palette_presets_to_css_vars_and_summary_strings(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'palette' => [
					[
						'name'  => 'Brand',
						'slug'  => 'brand',
						'color' => '#ff00aa',
					],
					[
						'name'  => 'No Slug',
						'slug'  => '',
						'color' => '#000000',
					],
				],
			],
		];

		$tokens = ( new ThemeTokenCollector() )->for_tokens();

		$this->assertCount( 2, $tokens['colorPresets'] );
		$this->assertSame( 'Brand', $tokens['colorPresets'][0]['name'] );
		$this->assertSame( '#ff00aa', $tokens['colorPresets'][0]['color'] );
		$this->assertSame(
			'var(--wp--preset--color--brand)',
			$tokens['colorPresets'][0]['cssVar']
		);
		// Empty slug -> empty cssVar.
		$this->assertSame( '', $tokens['colorPresets'][1]['cssVar'] );
		$this->assertSame( [ 'brand: #ff00aa', ': #000000' ], $tokens['colors'] );
	}

	public function test_for_tokens_merges_origin_keyed_presets_by_slug_with_documented_priority(): void {
		// origin-keyed shape: default/theme/custom; later origins override earlier ones for matching slugs.
		WordPressTestState::$global_settings = [
			'color' => [
				'palette' => [
					'default' => [
						[
							'slug'  => 'shared',
							'name'  => 'From Default',
							'color' => '#111',
						],
						[
							'slug'  => 'only-default',
							'name'  => 'Only Default',
							'color' => '#222',
						],
					],
					'theme'   => [
						[
							'slug'  => 'shared',
							'name'  => 'From Theme',
							'color' => '#333',
						],
					],
					'custom'  => [
						[
							'slug'  => 'shared',
							'name'  => 'From Custom',
							'color' => '#444',
						],
					],
				],
			],
		];

		$tokens  = ( new ThemeTokenCollector() )->for_tokens();
		$by_slug = [];
		foreach ( $tokens['colorPresets'] as $preset ) {
			$by_slug[ $preset['slug'] ] = $preset;
		}

		$this->assertArrayHasKey( 'shared', $by_slug );
		$this->assertArrayHasKey( 'only-default', $by_slug );
		// Custom is the last origin in the documented priority list,
		// so its value wins when the same slug exists.
		$this->assertSame( 'From Custom', $by_slug['shared']['name'] );
		$this->assertSame( '#444', $by_slug['shared']['color'] );
	}

	public function test_for_tokens_returns_cached_result_when_inputs_are_unchanged(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'palette' => [
					[
						'slug'  => 'one',
						'name'  => 'One',
						'color' => '#111',
					],
				],
			],
		];

		$collector = new ThemeTokenCollector();
		$first     = $collector->for_tokens();
		$second    = $collector->for_tokens();

		// Identical input hashes should reuse the cached payload.
		$this->assertSame( $first, $second );
		$this->assertSame( 'one', $second['colorPresets'][0]['slug'] );
	}

	public function test_block_tokens_override_controls_and_inherit_unspecified_settings(): void {
		WordPressTestState::$global_settings = [
			'color'      => [
				'text'       => true,
				'background' => false,
				'custom'     => false,
			],
			'typography' => [ 'lineHeight' => false ],
			'border'     => [ 'radius' => true ],
			'blocks'     => [
				'core/paragraph' => [
					'color'      => [
						'text'       => false,
						'background' => true,
					],
					'typography' => [ 'lineHeight' => true ],
				],
			],
		];

		$tokens = ( new ThemeTokenCollector() )->for_tokens( 'core/paragraph' );

		$this->assertFalse( $tokens['enabledFeatures']['textColor'] );
		$this->assertTrue( $tokens['enabledFeatures']['backgroundColor'] );
		$this->assertFalse( $tokens['enabledFeatures']['customColors'] );
		$this->assertTrue( $tokens['enabledFeatures']['lineHeight'] );
		$this->assertTrue( $tokens['enabledFeatures']['borderRadius'] );
	}

	/**
	 * @dataProvider block_preset_families
	 */
	public function test_block_presets_override_global_slugs_after_resolving_origins_and_keep_inherited_presets(
		string $group,
		string $setting_key,
		string $token_key,
		string $value_key,
		mixed $global_value,
		mixed $block_value,
		mixed $custom_value
	): void {
		WordPressTestState::$global_settings = [
			$group   => [
				$setting_key => [
					'default' => [
						[
							'slug'     => 'shared',
							$value_key => $global_value,
						],
						[
							'slug'     => 'global-only',
							$value_key => $global_value,
						],
					],
					'custom'  => [
						[
							'slug'     => 'shared',
							$value_key => $custom_value,
						],
					],
				],
			],
			'blocks' => [
				'core/paragraph' => [
					$group => [
						$setting_key => [
							'theme'  => [
								[
									'slug'     => 'shared',
									$value_key => $block_value,
								],
								[
									'slug'     => 'block-only',
									$value_key => $block_value,
								],
							],
							'custom' => [
								[
									'slug'     => 'block-only',
									$value_key => $custom_value,
								],
							],
						],
					],
				],
			],
		];

		$tokens  = ( new ThemeTokenCollector() )->for_tokens( 'core/paragraph' );
		$presets = array_column( $tokens[ $token_key ], null, 'slug' );

		$this->assertSame( [ 'shared', 'global-only', 'block-only' ], array_keys( $presets ) );
		$this->assertSame( $block_value, $presets['shared'][ $value_key ] );
		$this->assertSame( $global_value, $presets['global-only'][ $value_key ] );
		$this->assertSame( $custom_value, $presets['block-only'][ $value_key ] );
	}

	public static function block_preset_families(): array {
		return [
			'colors'        => [ 'color', 'palette', 'colorPresets', 'color', '#111111', '#222222', '#333333' ],
			'gradients'     => [ 'color', 'gradients', 'gradientPresets', 'gradient', 'linear-gradient(#111, #fff)', 'linear-gradient(#222, #fff)', 'linear-gradient(#333, #fff)' ],
			'duotone'       => [ 'color', 'duotone', 'duotonePresets', 'colors', [ '#111111', '#ffffff' ], [ '#222222', '#ffffff' ], [ '#333333', '#ffffff' ] ],
			'font sizes'    => [ 'typography', 'fontSizes', 'fontSizePresets', 'size', '1rem', '2rem', '3rem' ],
			'font families' => [ 'typography', 'fontFamilies', 'fontFamilyPresets', 'fontFamily', 'serif', 'sans-serif', 'monospace' ],
			'spacing'       => [ 'spacing', 'spacingSizes', 'spacingPresets', 'size', '1rem', '2rem', '3rem' ],
			'shadows'       => [ 'shadow', 'presets', 'shadowPresets', 'shadow', '0 1px #111', '0 2px #222', '0 3px #333' ],
		];
	}

	public function test_block_token_cache_is_isolated_from_siblings_and_global_collection(): void {
		WordPressTestState::$global_settings = [
			'color'  => [
				'text'    => true,
				'palette' => [
					[
						'slug'  => 'global',
						'color' => '#111111',
					],
				],
			],
			'blocks' => [
				'core/paragraph' => [
					'color' => [
						'text'    => false,
						'palette' => [
							[
								'slug'  => 'paragraph',
								'color' => '#222222',
							],
						],
					],
				],
				'core/heading'   => [
					'color' => [
						'palette' => [
							[
								'slug'  => 'heading',
								'color' => '#333333',
							],
						],
					],
				],
			],
		];

		$collector = new ThemeTokenCollector();
		$paragraph = $collector->for_tokens( 'core/paragraph' );
		$heading   = $collector->for_tokens( 'core/heading' );
		$global    = $collector->for_tokens();

		$this->assertSame( [ 'global', 'paragraph' ], array_column( $paragraph['colorPresets'], 'slug' ) );
		$this->assertFalse( $paragraph['enabledFeatures']['textColor'] );
		$this->assertSame( [ 'global', 'heading' ], array_column( $heading['colorPresets'], 'slug' ) );
		$this->assertTrue( $heading['enabledFeatures']['textColor'] );
		$this->assertSame( [ 'global' ], array_column( $global['colorPresets'], 'slug' ) );
		$this->assertTrue( $global['enabledFeatures']['textColor'] );
		$this->assertSame( $paragraph, $collector->for_tokens( 'core/paragraph' ) );
		$this->assertSame( $global, $collector->for_tokens( 'core/missing' ) );

		WordPressTestState::$global_settings['blocks']['core/paragraph']['color']['text'] = true;
		$this->assertTrue( $collector->for_tokens( 'core/paragraph' )['enabledFeatures']['textColor'] );
	}

	public function test_for_tokens_recomputes_when_settings_hash_changes(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'palette' => [
					[
						'slug'  => 'one',
						'name'  => 'One',
						'color' => '#111',
					],
				],
			],
		];

		$collector = new ThemeTokenCollector();
		$first     = $collector->for_tokens();
		$this->assertSame( 'one', $first['colorPresets'][0]['slug'] );

		// Mutating the settings changes the hash, so the next call recomputes
		// instead of returning the cached payload.
		WordPressTestState::$global_settings = [
			'color' => [
				'palette' => [
					[
						'slug'  => 'two',
						'name'  => 'Two',
						'color' => '#222',
					],
				],
			],
		];

		$second = $collector->for_tokens();

		$this->assertSame( 'two', $second['colorPresets'][0]['slug'] );
	}

	public function test_for_tokens_emits_gradient_summary_with_slug_only_fallback(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'gradients' => [
					[
						'slug'     => 'sunrise',
						'gradient' => 'linear-gradient(#fff, #000)',
					],
					[ 'slug' => 'no-gradient' ],
				],
			],
		];

		$tokens = ( new ThemeTokenCollector() )->for_tokens();

		$this->assertSame(
			[ 'sunrise: linear-gradient(#fff, #000)', 'no-gradient' ],
			$tokens['gradients']
		);
	}

	public function test_for_tokens_collects_duotone_summary_and_skips_slugless_entries(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'duotone' => [
					[
						'slug'   => 'shadow-light',
						'colors' => [ '#000000', '#ffffff', '#cccccc' ],
					],
					[
						'slug'   => 'no-colors',
						'colors' => [],
					],
					[
						// Missing slug -> skipped entirely.
						'colors' => [ '#aa0000', '#00aa00' ],
					],
				],
			],
		];

		$tokens = ( new ThemeTokenCollector() )->for_tokens();

		// Only first two colors are summarized; slugless preset is omitted.
		$this->assertSame(
			[ 'shadow-light: #000000 / #ffffff', 'no-colors' ],
			$tokens['duotone']
		);
		// duotonePresets keeps full list for entries that survive merging.
		$slugs = array_map(
			static fn( array $preset ): string => $preset['slug'],
			$tokens['duotonePresets']
		);
		$this->assertContains( 'shadow-light', $slugs );
		$this->assertContains( 'no-colors', $slugs );
	}

	public function test_for_tokens_layout_section_falls_back_to_documented_defaults(): void {
		$tokens = ( new ThemeTokenCollector() )->for_tokens();

		$this->assertSame(
			[
				'content'                       => '',
				'wide'                          => '',
				'allowEditing'                  => true,
				'allowCustomContentAndWideSize' => true,
			],
			$tokens['layout']
		);
	}

	public function test_for_tokens_enabled_features_reflect_explicit_color_overrides(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				// Explicit `false` should produce a literal `false`, not the
				// implicit `true` default for the background/text keys.
				'background' => false,
				'text'       => false,
				'link'       => true,
			],
		];

		$features = ( new ThemeTokenCollector() )->for_tokens()['enabledFeatures'];

		$this->assertFalse( $features['backgroundColor'] );
		$this->assertFalse( $features['textColor'] );
		$this->assertTrue( $features['linkColor'] );
		// Defaults remain for keys we did not touch.
		$this->assertTrue( $features['customColors'] );
		$this->assertTrue( $features['dropCap'] );
	}

	public function test_for_tokens_collects_element_styles_from_global_styles(): void {
		WordPressTestState::$global_styles = [
			'elements' => [
				'link'   => [
					'color'          => [ 'text' => 'var(--wp--preset--color--brand)' ],
					':hover'         => [ 'color' => [ 'text' => '#000' ] ],
					':focus-visible' => [ 'outline' => '2px solid' ],
				],
				'button' => 'not-an-array',
			],
		];

		$tokens = ( new ThemeTokenCollector() )->for_tokens();

		$this->assertArrayHasKey( 'link', $tokens['elementStyles'] );
		$this->assertSame(
			[ 'text' => 'var(--wp--preset--color--brand)' ],
			$tokens['elementStyles']['link']['base']
		);
		$this->assertSame(
			[ 'text' => '#000' ],
			$tokens['elementStyles']['link']['hover']
		);
		$this->assertSame( [], $tokens['elementStyles']['link']['focus'] );
		$this->assertSame(
			[ 'outline' => '2px solid' ],
			$tokens['elementStyles']['link']['focusVisible']
		);
		// Non-array element definitions are skipped.
		$this->assertArrayNotHasKey( 'button', $tokens['elementStyles'] );
	}

	public function test_for_tokens_collects_block_pseudo_styles_only_when_present(): void {
		WordPressTestState::$global_styles = [
			'blocks' => [
				'core/button'    => [
					':hover'  => [ 'color' => [ 'background' => '#111' ] ],
					':focus'  => [ 'color' => [ 'background' => '#222' ] ],
					'spacing' => [ 'padding' => '1rem' ],
				],
				'core/quote'     => [
					'spacing' => [ 'padding' => '0.5rem' ],
				],
				'core/paragraph' => 'not-an-array',
			],
		];

		$pseudo = ( new ThemeTokenCollector() )->for_tokens()['blockPseudoStyles'];

		$this->assertArrayHasKey( 'core/button', $pseudo );
		$this->assertSame(
			[ ':hover', ':focus' ],
			array_keys( $pseudo['core/button'] )
		);
		// Block with only non-pseudo styles is omitted.
		$this->assertArrayNotHasKey( 'core/quote', $pseudo );
		$this->assertArrayNotHasKey( 'core/paragraph', $pseudo );
	}

	public function test_for_presets_projects_preset_buckets_from_for_tokens(): void {
		WordPressTestState::$global_settings = [
			'color'      => [
				'palette' => [
					[
						'slug'  => 'brand',
						'name'  => 'Brand',
						'color' => '#abc',
					],
				],
			],
			'typography' => [
				'fontSizes' => [
					[
						'slug' => 'base',
						'name' => 'Base',
						'size' => '16px',
					],
				],
			],
		];

		$presets = ( new ThemeTokenCollector() )->for_presets();

		$this->assertSame( [ 'brand' ], array_column( $presets['colorPresets'], 'slug' ) );
		$this->assertSame( [ 'base' ], array_column( $presets['fontSizePresets'], 'slug' ) );
		$this->assertSame( [], $presets['gradientPresets'] );
		$this->assertSame( [], $presets['duotonePresets'] );
		$this->assertSame( 'server', $presets['diagnostics']['source'] );
	}

	public function test_for_styles_returns_global_styles_with_element_and_pseudo_subsets(): void {
		WordPressTestState::$global_styles = [
			'elements' => [
				'link' => [ 'color' => [ 'text' => '#123' ] ],
			],
			'blocks'   => [
				'core/button' => [
					':hover' => [ 'color' => [ 'background' => '#111' ] ],
				],
			],
		];

		$styles = ( new ThemeTokenCollector() )->for_styles();

		$this->assertSame( WordPressTestState::$global_styles, $styles['styles'] );
		$this->assertArrayHasKey( 'link', $styles['elementStyles'] );
		$this->assertArrayHasKey( 'core/button', $styles['blockPseudoStyles'] );
		$this->assertSame( 'server-global-settings', $styles['diagnostics']['reason'] );
	}

	public function test_for_styles_includes_global_styles_scope_and_request_context(): void {
		WordPressTestState::$active_theme    = [
			'stylesheet' => 'pattern-theme',
		];
		WordPressTestState::$global_settings = [
			'color' => [
				'palette' => [],
			],
		];
		WordPressTestState::$global_styles   = [
			'color' => [
				'background' => '#fefefe',
			],
		];
		WordPressTestState::$posts[17]       = new \WP_Post(
			[
				'ID'           => 17,
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_content' => (string) wp_json_encode(
					[
						'version'                     => 3,
						'isGlobalStylesUserThemeJSON' => true,
						'settings'                    => [
							'color' => [
								'palette' => [],
							],
						],
						'styles'                      => [
							'color' => [
								'text' => '#111111',
							],
						],
					]
				),
			]
		);

		$styles = ( new ThemeTokenCollector() )->for_styles();

		$this->assertSame(
			[
				'surface'        => 'global-styles',
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
				'postType'       => 'global_styles',
				'entityId'       => '17',
				'entityKind'     => 'root',
				'entityName'     => 'globalStyles',
				'stylesheet'     => 'pattern-theme',
			],
			$styles['scope']
		);
		$this->assertSame(
			[
				'settings' => [
					'color' => [
						'palette' => [],
					],
				],
				'styles'   => [
					'color' => [
						'text' => '#111111',
					],
				],
			],
			$styles['styleContext']['currentConfig']
		);
		$this->assertSame(
			[
				'settings' => WordPressTestState::$global_settings,
				'styles'   => WordPressTestState::$global_styles,
			],
			$styles['styleContext']['mergedConfig']
		);
		$this->assertSame( [], $styles['styleContext']['availableVariations'] );
		$this->assertSame(
			$styles['diagnostics'],
			$styles['styleContext']['themeTokenDiagnostics']
		);
	}

	public function test_for_styles_omits_unpublished_draft_global_styles_from_request_context(): void {
		WordPressTestState::$active_theme = [
			'stylesheet' => 'pattern-theme',
		];
		// A draft wp_global_styles row holds unpublished design work. get-theme-styles
		// is an edit_posts helper, so it must not surface draft config or its post id.
		WordPressTestState::$posts[21] = new \WP_Post(
			[
				'ID'           => 21,
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'draft',
				'post_content' => (string) wp_json_encode(
					[
						'settings' => [ 'color' => [ 'palette' => [] ] ],
						'styles'   => [ 'color' => [ 'text' => '#abcabc' ] ],
					]
				),
			]
		);

		$styles = ( new ThemeTokenCollector() )->for_styles();

		$this->assertSame(
			[
				'settings' => [],
				'styles'   => [],
			],
			$styles['styleContext']['currentConfig']
		);
		$this->assertSame( '', $styles['scope']['globalStylesId'] );
		$this->assertSame( '', $styles['scope']['scopeKey'] );
		$this->assertSame( '', $styles['scope']['entityId'] );
	}

	public function test_for_styles_available_variations_honor_the_apply_contract_filter(): void {
		// get-theme-styles feeds recommend-style / request-style-apply, so its
		// availableVariations must match the apply pipeline's filtered universe
		// (StyleApplyExecutor::theme_style_variations()).
		$variation = [
			'title'    => 'Midnight',
			'settings' => [ 'custom' => [ 'mood' => 'dark' ] ],
			'styles'   => [ 'color' => [ 'background' => '#101010' ] ],
		];
		add_filter(
			'flavor_agent_external_apply_theme_variations',
			static fn(): array => [ $variation ]
		);

		$styles = ( new ThemeTokenCollector() )->for_styles();

		$this->assertSame( [ $variation ], $styles['styleContext']['availableVariations'] );
	}
}
