<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\ThemeTokenFormatter;
use PHPUnit\Framework\TestCase;

final class ThemeTokenFormatterTest extends TestCase {

	public function test_format_returns_empty_string_for_empty_input(): void {
		$this->assertSame( '', ThemeTokenFormatter::format( [] ) );
		$this->assertSame( '', ThemeTokenFormatter::format( [ 'colors' => [] ] ) );
	}

	public function test_format_emits_primary_preset_and_metadata_lines(): void {
		$formatted = ThemeTokenFormatter::format(
			[
				'colors'            => [ 'primary: #0073aa' ],
				'gradients'         => [ 'hero: linear-gradient(180deg, #fff, #ddd)' ],
				'fontSizes'         => [ 'small: 0.875rem' ],
				'fontFamilies'      => [ 'inter: Inter, sans-serif' ],
				'spacing'           => [ '20: 0.5rem' ],
				'shadows'           => [ 'natural: 6px 6px 9px rgba(0,0,0,0.2)' ],
				'duotone'           => [ 'blue-orange: #0af / #fa0' ],
				'colorPresets'      => [
					[
						'slug'   => 'primary',
						'cssVar' => 'var(--wp--preset--color--primary)',
					],
				],
				'fontSizePresets'   => [
					[
						'slug'   => 'small',
						'cssVar' => 'var(--wp--preset--font-size--small)',
					],
				],
				'fontFamilyPresets' => [
					[
						'slug'   => 'inter',
						'cssVar' => 'var(--wp--preset--font-family--inter)',
					],
				],
				'spacingPresets'    => [
					[
						'slug'   => '20',
						'cssVar' => 'var(--wp--preset--spacing--20)',
					],
				],
				'layout'            => [
					'content'                       => '650px',
					'wide'                          => '1200px',
					'allowEditing'                  => true,
					'allowCustomContentAndWideSize' => true,
				],
				'enabledFeatures'   => [
					'backgroundColor' => true,
					'textColor'       => true,
				],
				'elementStyles'     => [
					'link'    => [ 'color' => [] ],
					'button'  => [ 'color' => [] ],
					'heading' => [ 'color' => [] ],
				],
			]
		);

		$this->assertStringContainsString( 'Colors: primary: #0073aa', $formatted );
		$this->assertStringContainsString( 'Color preset refs: primary (var(--wp--preset--color--primary))', $formatted );
		$this->assertStringContainsString( 'Gradients: hero: linear-gradient', $formatted );
		$this->assertStringContainsString( 'Font size preset refs: small (var(--wp--preset--font-size--small))', $formatted );
		$this->assertStringContainsString( 'Font family preset refs: inter (var(--wp--preset--font-family--inter))', $formatted );
		$this->assertStringContainsString( 'Spacing preset refs: 20 (var(--wp--preset--spacing--20))', $formatted );
		$this->assertStringContainsString( 'Layout: {"content":"650px","wide":"1200px","allowEditing":true,"allowCustomContentAndWideSize":true}', $formatted );
		$this->assertStringContainsString( 'Enabled features: {"backgroundColor":true,"textColor":true}', $formatted );
		$this->assertStringContainsString( 'Element style keys: link, button, heading', $formatted );
	}

	public function test_format_emits_preset_refs_without_primary_values(): void {
		$formatted = ThemeTokenFormatter::format(
			[
				'colorPresets' => [
					[
						'slug'   => 'primary',
						'cssVar' => 'var(--wp--preset--color--primary)',
					],
				],
			]
		);

		$this->assertStringContainsString( 'Color preset refs: primary (var(--wp--preset--color--primary))', $formatted );
		$this->assertStringNotContainsString( 'Colors:', $formatted );
	}

	public function test_format_skips_malformed_preset_entries(): void {
		$formatted = ThemeTokenFormatter::format(
			[
				'colorPresets' => [
					[
						'slug'   => 'primary',
						'cssVar' => 'var(--wp--preset--color--primary)',
					],
					[
						'slug' => 'missing-css-var',
					],
					[
						'cssVar' => 'var(--wp--preset--color--missing-slug)',
					],
				],
			]
		);

		$this->assertStringContainsString( 'Color preset refs: primary (var(--wp--preset--color--primary))', $formatted );
		$this->assertStringNotContainsString( 'missing-css-var', $formatted );
		$this->assertStringNotContainsString( 'missing-slug', $formatted );
	}

	public function test_format_uses_layout_shape_and_element_style_key_summary(): void {
		$formatted = ThemeTokenFormatter::format(
			[
				'layout'        => [
					'content'                       => '650px',
					'wide'                          => '1200px',
					'allowEditing'                  => true,
					'allowCustomContentAndWideSize' => true,
				],
				'elementStyles' => [
					'link'   => [ 'typography' => [ 'fontSize' => '1rem' ] ],
					'button' => [ 'color' => [ 'text' => '#fff' ] ],
				],
			]
		);

		$this->assertStringContainsString( 'Layout: {"content":"650px","wide":"1200px","allowEditing":true,"allowCustomContentAndWideSize":true}', $formatted );
		$this->assertStringContainsString( 'Element style keys: link, button', $formatted );
		$this->assertStringNotContainsString( 'fontSize', $formatted );
	}

	public function test_format_enforces_per_line_item_caps(): void {
		$colors = [];
		$keys   = [];
		for ( $index = 0; $index < 25; $index++ ) {
			$colors[]                    = sprintf( 'color-%d: #%06d', $index, $index );
			$keys[ 'element-' . $index ] = [];
		}

		$formatted = ThemeTokenFormatter::format(
			[
				'colors'        => $colors,
				'elementStyles' => $keys,
			]
		);

		$this->assertStringContainsString( 'color-19', $formatted );
		$this->assertStringNotContainsString( 'color-20', $formatted );
		$this->assertStringContainsString( 'element-7', $formatted );
		$this->assertStringNotContainsString( 'element-8', $formatted );
	}

	public function test_format_trims_lines_by_priority_to_stay_within_budget(): void {
		$long_values = [];
		for ( $index = 0; $index < 20; $index++ ) {
			$long_values[] = sprintf( 'token-%02d: %s', $index, str_repeat( 'x', 130 ) );
		}

		$formatted = ThemeTokenFormatter::format(
			[
				'colors'          => $long_values,
				'fontSizes'       => $long_values,
				'fontFamilies'    => $long_values,
				'spacing'         => $long_values,
				'gradients'       => $long_values,
				'shadows'         => $long_values,
				'duotone'         => $long_values,
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
			]
		);

		$this->assertLessThanOrEqual( 2000, strlen( $formatted ) );
		$this->assertStringContainsString( 'Layout:', $formatted );
		$this->assertStringContainsString( 'Enabled features:', $formatted );
		$this->assertStringNotContainsString( 'Color preset refs:', $formatted );
	}

	public function test_format_drops_oversized_json_lines_without_emitting_partial_lines(): void {
		$formatted = ThemeTokenFormatter::format(
			[
				'layout'          => [
					'content' => str_repeat( 'x', 2100 ),
				],
				'enabledFeatures' => [
					'backgroundColor' => true,
				],
			]
		);

		$this->assertLessThanOrEqual( 2000, strlen( $formatted ) );
		$this->assertSame( 'Enabled features: {"backgroundColor":true}', $formatted );
		$this->assertStringNotContainsString( 'Layout:', $formatted );
	}
}
