<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

final class Utils {

	public static function sanitize_url_value( mixed $value ): string {
		return (string) sanitize_url( (string) $value );
	}

	public static function encode_json_payload( mixed $value, string $fallback = '[]', int $flags = 0 ): string {
		$encoded = wp_json_encode( $value, $flags );

		return is_string( $encoded ) ? $encoded : $fallback;
	}

	/**
	 * @param array<string, string> $defaults
	 * @param array<string, string> $attributes
	 * @return array<string, string>
	 */
	public static function merge_html_attributes( array $defaults, array $attributes ): array {
		$merged = $defaults;

		foreach ( $attributes as $attribute_name => $attribute_value ) {
			if ( ! is_string( $attribute_value ) ) {
				continue;
			}

			if ( 'class' === $attribute_name && isset( $merged['class'] ) && '' !== $attribute_value ) {
				$merged['class'] .= ' ' . $attribute_value;
				continue;
			}

			$merged[ $attribute_name ] = $attribute_value;
		}

		return $merged;
	}

	/**
	 * @param array<string, string> $attributes
	 */
	public static function render_html_attributes( array $attributes ): void {
		foreach ( $attributes as $attribute_name => $attribute_value ) {
			printf(
				' %s="%s"',
				esc_attr( $attribute_name ),
				esc_attr( $attribute_value )
			);
		}
	}

	private function __construct() {
	}
}
