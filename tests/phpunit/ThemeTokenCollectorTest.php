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
}
