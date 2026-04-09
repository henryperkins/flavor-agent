<?php
/**
 * Shared theme token formatter for prompt builders.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class ThemeTokenFormatter {

	private const MAX_FORMATTED_LENGTH = 2000;

	/**
	 * @var array<string, int>
	 */
	private const ITEM_LIMITS = [
		'colors'                   => 20,
		'color_preset_refs'        => 20,
		'gradients'                => 12,
		'font_sizes'               => 20,
		'font_size_preset_refs'    => 20,
		'font_families'            => 12,
		'font_family_preset_refs'  => 12,
		'spacing'                  => 12,
		'spacing_preset_refs'      => 12,
		'shadows'                  => 8,
		'duotone'                  => 8,
		'element_style_keys'       => 8,
	];

	/**
	 * Format collected theme tokens into bounded prompt lines.
	 *
	 * @param array<string, mixed> $tokens Theme token payload.
	 */
	public static function format( array $tokens ): string {
		if ( [] === $tokens ) {
			return '';
		}

		$lines = [];

		$lines['colors'] = self::build_value_line(
			'Colors',
			$tokens['colors'] ?? null,
			self::ITEM_LIMITS['colors']
		);
		$lines['color_preset_refs'] = self::build_preset_ref_line(
			'Color preset refs',
			$tokens['colorPresets'] ?? null,
			self::ITEM_LIMITS['color_preset_refs']
		);
		$lines['gradients'] = self::build_value_line(
			'Gradients',
			$tokens['gradients'] ?? null,
			self::ITEM_LIMITS['gradients']
		);
		$lines['font_sizes'] = self::build_value_line(
			'Font sizes',
			$tokens['fontSizes'] ?? null,
			self::ITEM_LIMITS['font_sizes']
		);
		$lines['font_size_preset_refs'] = self::build_preset_ref_line(
			'Font size preset refs',
			$tokens['fontSizePresets'] ?? null,
			self::ITEM_LIMITS['font_size_preset_refs']
		);
		$lines['font_families'] = self::build_value_line(
			'Font families',
			$tokens['fontFamilies'] ?? null,
			self::ITEM_LIMITS['font_families']
		);
		$lines['font_family_preset_refs'] = self::build_preset_ref_line(
			'Font family preset refs',
			$tokens['fontFamilyPresets'] ?? null,
			self::ITEM_LIMITS['font_family_preset_refs']
		);
		$lines['spacing'] = self::build_value_line(
			'Spacing',
			$tokens['spacing'] ?? null,
			self::ITEM_LIMITS['spacing']
		);
		$lines['spacing_preset_refs'] = self::build_preset_ref_line(
			'Spacing preset refs',
			$tokens['spacingPresets'] ?? null,
			self::ITEM_LIMITS['spacing_preset_refs']
		);
		$lines['shadows'] = self::build_value_line(
			'Shadows',
			$tokens['shadows'] ?? null,
			self::ITEM_LIMITS['shadows']
		);
		$lines['duotone'] = self::build_duotone_line(
			$tokens['duotone'] ?? null,
			self::ITEM_LIMITS['duotone']
		);
		$lines['layout'] = self::build_json_line( 'Layout', $tokens['layout'] ?? null );
		$lines['enabled_features'] = self::build_json_line( 'Enabled features', $tokens['enabledFeatures'] ?? null );
		$lines['element_style_keys'] = self::build_element_style_keys_line(
			$tokens['elementStyles'] ?? null,
			self::ITEM_LIMITS['element_style_keys']
		);

		$lines = array_filter(
			$lines,
			static fn( $line ): bool => is_string( $line ) && '' !== $line
		);

		if ( [] === $lines ) {
			return '';
		}

		$trim_order = [
			'spacing_preset_refs',
			'font_family_preset_refs',
			'font_size_preset_refs',
			'color_preset_refs',
			'element_style_keys',
			'shadows',
			'duotone',
			'gradients',
			'font_family_preset_refs',
			'font_families',
			'spacing',
			'font_sizes',
			'colors',
		];

		foreach ( $trim_order as $line_key ) {
			if ( self::joined_length( $lines ) <= self::MAX_FORMATTED_LENGTH ) {
				break;
			}

			if ( ! isset( $lines[ $line_key ] ) ) {
				continue;
			}

			unset( $lines[ $line_key ] );
		}

		return implode( "\n", array_values( $lines ) );
	}

	private static function joined_length( array $lines ): int {
		return strlen( implode( "\n", array_values( $lines ) ) );
	}

	private static function build_value_line( string $label, mixed $values, int $limit ): string {
		$items = self::normalize_scalar_items( $values, $limit );

		if ( [] === $items ) {
			return '';
		}

		return $label . ': ' . implode( ', ', $items );
	}

	private static function build_duotone_line( mixed $values, int $limit ): string {
		if ( ! is_array( $values ) || [] === $values ) {
			return '';
		}

		$items = [];

		foreach ( $values as $value ) {
			if ( count( $items ) >= $limit ) {
				break;
			}

			if ( is_string( $value ) || is_numeric( $value ) ) {
				$text = sanitize_text_field( (string) $value );
				if ( '' !== $text ) {
					$items[] = $text;
				}
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $value['slug'] ?? '' ) );
			$pair = is_array( $value['colors'] ?? null ) ? $value['colors'] : [];

			if ( '' === $slug || count( $pair ) < 2 ) {
				continue;
			}

			$first  = sanitize_text_field( (string) $pair[0] );
			$second = sanitize_text_field( (string) $pair[1] );
			if ( '' === $first || '' === $second ) {
				continue;
			}

			$items[] = $slug . ': ' . $first . ' / ' . $second;
		}

		if ( [] === $items ) {
			return '';
		}

		return 'Duotone: ' . implode( ', ', $items );
	}

	private static function build_preset_ref_line( string $label, mixed $presets, int $limit ): string {
		if ( ! is_array( $presets ) || [] === $presets ) {
			return '';
		}

		$items = [];

		foreach ( $presets as $preset ) {
			if ( count( $items ) >= $limit ) {
				break;
			}

			if ( ! is_array( $preset ) ) {
				continue;
			}

			$slug    = sanitize_key( (string) ( $preset['slug'] ?? '' ) );
			$css_var = sanitize_text_field( (string) ( $preset['cssVar'] ?? '' ) );

			if ( '' === $slug || '' === $css_var ) {
				continue;
			}

			$items[] = sprintf( '%s (%s)', $slug, $css_var );
		}

		if ( [] === $items ) {
			return '';
		}

		return $label . ': ' . implode( ', ', $items );
	}

	private static function build_json_line( string $label, mixed $value ): string {
		if ( ! is_array( $value ) || [] === $value ) {
			return '';
		}

		$json = wp_json_encode( $value );
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		return $label . ': ' . $json;
	}

	private static function build_element_style_keys_line( mixed $value, int $limit ): string {
		if ( ! is_array( $value ) || [] === $value ) {
			return '';
		}

		$keys = [];
		foreach ( array_keys( $value ) as $key ) {
			$clean_key = sanitize_key( (string) $key );
			if ( '' === $clean_key ) {
				continue;
			}
			$keys[] = $clean_key;
		}

		$keys = array_values( array_unique( $keys ) );
		if ( [] === $keys ) {
			return '';
		}

		$keys = array_slice( $keys, 0, $limit );

		return 'Element style keys: ' . implode( ', ', $keys );
	}

	/**
	 * @return array<int, string>
	 */
	private static function normalize_scalar_items( mixed $values, int $limit ): array {
		if ( ! is_array( $values ) || [] === $values ) {
			return [];
		}

		$items = [];

		foreach ( $values as $value ) {
			if ( count( $items ) >= $limit ) {
				break;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$text = sanitize_text_field( (string) $value );
			if ( '' === $text ) {
				continue;
			}

			$items[] = $text;
		}

		return $items;
	}
}
