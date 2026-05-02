<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class StyleContrastValidator {

	private const AA_THRESHOLD       = 4.5;
	private const SUPPORTED_ELEMENTS = [ 'button', 'link', 'heading' ];
	private const ENUM_SCOPE_ORDER   = [ 'root', 'elements.button', 'elements.link', 'elements.heading' ];

	/**
	 * @param array<int, array<string, mixed>> $operations
	 * @param array<string, mixed>             $context
	 *
	 * @return array{passed: bool, kind: string|null, reason: string|null, ratio: float|null}
	 */
	public static function evaluate( array $operations, array $context ): array {
		$style_context = is_array( $context['styleContext'] ?? null ) ? $context['styleContext'] : [];
		$theme_tokens  = is_array( $style_context['themeTokens'] ?? null ) ? $style_context['themeTokens'] : [];
		$groups        = [];
		$unsupported   = false;

		if ( self::has_theme_variation_operation( $operations ) && self::has_readable_color_operation( $operations ) ) {
			return [
				'passed' => false,
				'kind'   => 'unavailable',
				'reason' => __( 'Contrast check unavailable: theme variation and color overrides must be reviewed separately.', 'flavor-agent' ),
				'ratio'  => null,
			];
		}

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$scope_key = self::scope_key_for_operation( $operation );

			if ( null === $scope_key ) {
				continue;
			}

			if ( 'unsupported' === $scope_key ) {
				$unsupported = true;
				continue;
			}

			$path = is_array( $operation['path'] ?? null ) ? array_values( $operation['path'] ) : [];
			$side = (string) end( $path );

			if ( ! in_array( $side, [ 'text', 'background' ], true ) ) {
				continue;
			}

			$groups[ $scope_key ][ $side ][] = $operation;
		}

		if ( $unsupported ) {
			return [
				'passed' => false,
				'kind'   => 'unavailable',
				'reason' => __( 'Contrast check unavailable: unsupported readable color path.', 'flavor-agent' ),
				'ratio'  => null,
			];
		}

		foreach ( self::scope_keys_in_enum_order( array_keys( $groups ) ) as $scope_key ) {
			$sides         = $groups[ $scope_key ];
			$background_op = self::last_operation( $sides['background'] ?? [] );
			$text_op       = self::last_operation( $sides['text'] ?? [] );

			$background_lookup = self::resolve_side_for_evaluation(
				$background_op,
				'background',
				$scope_key,
				$context,
				$theme_tokens
			);

			if ( null !== $background_lookup['fail'] ) {
				return $background_lookup['fail'];
			}

			$text_lookup = self::resolve_side_for_evaluation(
				$text_op,
				'text',
				$scope_key,
				$context,
				$theme_tokens
			);

			if ( null !== $text_lookup['fail'] ) {
				return $text_lookup['fail'];
			}

			$ratio = self::contrast_ratio(
				(string) $background_lookup['hex'],
				(string) $text_lookup['hex']
			);

			if ( $ratio < self::AA_THRESHOLD ) {
				return [
					'passed' => false,
					'kind'   => 'low_ratio',
					'reason' => sprintf(
						/* translators: 1: contrast ratio, 2: foreground label, 3: background label, 4: style scope */
						__( 'Contrast check: %1$s:1 between "%2$s" and "%3$s" at %4$s, below the 4.5:1 minimum.', 'flavor-agent' ),
						number_format( $ratio, 1 ),
						self::label_for( $text_op, (string) $text_lookup['hex'] ),
						self::label_for( $background_op, (string) $background_lookup['hex'] ),
						$scope_key
					),
					'ratio'  => round( $ratio, 2 ),
				];
			}
		}

		return [
			'passed' => true,
			'kind'   => null,
			'reason' => null,
			'ratio'  => null,
		];
	}

	/**
	 * @param array<string, mixed> $theme_tokens
	 *
	 * @return array{resolved: bool, hex: string|null, reason: string|null}
	 */
	public static function resolve_color_value( mixed $value, array $theme_tokens ): array {
		if ( null === $value || '' === $value ) {
			return [
				'resolved' => false,
				'hex'      => null,
				'reason'   => 'missing',
			];
		}

		if ( ! is_string( $value ) ) {
			return [
				'resolved' => false,
				'hex'      => null,
				'reason'   => 'unknown-form',
			];
		}

		if ( preg_match( '/^#[0-9a-f]{6}([0-9a-f]{2})?$/i', $value ) === 1 ) {
			return [
				'resolved' => true,
				'hex'      => strtolower( substr( $value, 0, 7 ) ),
				'reason'   => null,
			];
		}

		if ( preg_match( '/^var:preset\|color\|([a-z0-9_-]+)$/i', $value, $matches ) === 1 ) {
			return self::resolve_preset_slug( sanitize_key( (string) $matches[1] ), $theme_tokens );
		}

		if ( preg_match( '/^var\(--wp--preset--color--([a-z0-9_-]+)\)$/i', $value, $matches ) === 1 ) {
			return self::resolve_preset_slug( sanitize_key( (string) $matches[1] ), $theme_tokens );
		}

		return [
			'resolved' => false,
			'hex'      => null,
			'reason'   => 'unknown-form',
		];
	}

	public static function contrast_ratio( string $hex_a, string $hex_b ): float {
		$luminance_a = self::relative_luminance( $hex_a );
		$luminance_b = self::relative_luminance( $hex_b );
		$lighter     = max( $luminance_a, $luminance_b );
		$darker      = min( $luminance_a, $luminance_b );

		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * @param array<string, mixed> $operation
	 */
	public static function scope_key_for_operation( array $operation ): ?string {
		$type = sanitize_key( (string) ( $operation['type'] ?? '' ) );
		$path = is_array( $operation['path'] ?? null ) ? array_values( $operation['path'] ) : [];

		if ( 'set_block_styles' === $type ) {
			if ( ! self::path_ends_in_color_pair_leaf( $path ) ) {
				return null;
			}

			$block_name = sanitize_text_field( (string) ( $operation['blockName'] ?? '' ) );

			return '' !== $block_name ? 'blocks.' . $block_name : null;
		}

		if ( 'set_styles' !== $type ) {
			return null;
		}

		if ( ! self::path_ends_in_color_pair_leaf( $path ) ) {
			return null;
		}

		if ( count( $path ) === 2 ) {
			return 'root';
		}

		if ( count( $path ) === 4 && 'elements' === $path[0] ) {
			$element = sanitize_key( (string) $path[1] );

			return in_array( $element, self::SUPPORTED_ELEMENTS, true )
				? 'elements.' . $element
				: 'unsupported';
		}

		return 'unsupported';
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 */
	private static function has_theme_variation_operation( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			if ( 'set_theme_variation' === sanitize_key( (string) ( $operation['type'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 */
	private static function has_readable_color_operation( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $operation['type'] ?? '' ) );

			if ( ! in_array( $type, [ 'set_styles', 'set_block_styles' ], true ) ) {
				continue;
			}

			$path = is_array( $operation['path'] ?? null ) ? array_values( $operation['path'] ) : [];

			if ( self::path_ends_in_color_pair_leaf( $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function merged_complement_hex( string $scope_key, string $side, array $context ): ?string {
		if ( ! in_array( $side, [ 'text', 'background' ], true ) ) {
			return null;
		}

		$style_context = is_array( $context['styleContext'] ?? null ) ? $context['styleContext'] : [];
		$theme_tokens  = is_array( $style_context['themeTokens'] ?? null ) ? $style_context['themeTokens'] : [];
		$merged        = is_array( $style_context['mergedConfig'] ?? null ) ? $style_context['mergedConfig'] : [];
		$merged_styles = is_array( $merged['styles'] ?? null ) ? $merged['styles'] : [];
		$candidate     = self::scope_specific_complement_value( $scope_key, $side, $merged_styles, $theme_tokens );

		if ( null !== $candidate ) {
			$resolved = self::resolve_color_value( $candidate, $theme_tokens );

			if ( $resolved['resolved'] ) {
				return $resolved['hex'];
			}
		}

		$root_color = is_array( $merged_styles['color'] ?? null ) ? $merged_styles['color'] : [];
		$root_value = $root_color[ $side ] ?? null;

		if ( null !== $root_value ) {
			$resolved = self::resolve_color_value( $root_value, $theme_tokens );

			if ( $resolved['resolved'] ) {
				return $resolved['hex'];
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $theme_tokens
	 *
	 * @return array{resolved: bool, hex: string|null, reason: string|null}
	 */
	private static function resolve_preset_slug( string $slug, array $theme_tokens ): array {
		$index = self::build_preset_index( $theme_tokens );
		$hex   = $index[ $slug ] ?? '';

		if ( '' === $hex || preg_match( '/^#[0-9a-f]{6}([0-9a-f]{2})?$/i', $hex ) !== 1 ) {
			return [
				'resolved' => false,
				'hex'      => null,
				'reason'   => 'unknown-preset',
			];
		}

		return [
			'resolved' => true,
			'hex'      => strtolower( substr( $hex, 0, 7 ) ),
			'reason'   => null,
		];
	}

	/**
	 * @param array<string, mixed> $theme_tokens
	 *
	 * @return array<string, string>
	 */
	private static function build_preset_index( array $theme_tokens ): array {
		$presets = is_array( $theme_tokens['colorPresets'] ?? null ) ? $theme_tokens['colorPresets'] : [];
		$index   = [];

		foreach ( $presets as $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $preset['slug'] ?? '' ) );

			if ( '' === $slug ) {
				continue;
			}

			$index[ $slug ] = (string) ( $preset['color'] ?? '' );
		}

		return $index;
	}

	private static function relative_luminance( string $hex ): float {
		[ $red, $green, $blue ] = self::hex_to_srgb_channels( $hex );

		return 0.2126 * self::linearize_channel( $red )
			+ 0.7152 * self::linearize_channel( $green )
			+ 0.0722 * self::linearize_channel( $blue );
	}

	/**
	 * @return array{0: float, 1: float, 2: float}
	 */
	private static function hex_to_srgb_channels( string $hex ): array {
		$hex = ltrim( $hex, '#' );

		return [
			(float) hexdec( substr( $hex, 0, 2 ) ) / 255.0,
			(float) hexdec( substr( $hex, 2, 2 ) ) / 255.0,
			(float) hexdec( substr( $hex, 4, 2 ) ) / 255.0,
		];
	}

	private static function linearize_channel( float $channel ): float {
		return $channel <= 0.03928
			? $channel / 12.92
			: pow( ( $channel + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * @param array<int, mixed> $path
	 */
	private static function path_ends_in_color_pair_leaf( array $path ): bool {
		if ( count( $path ) < 2 ) {
			return false;
		}

		$tail = array_slice( $path, -2 );

		return 'color' === $tail[0] && in_array( $tail[1], [ 'text', 'background' ], true );
	}

	/**
	 * @param array<string, mixed> $merged_styles
	 * @param array<string, mixed> $theme_tokens
	 */
	private static function scope_specific_complement_value(
		string $scope_key,
		string $side,
		array $merged_styles,
		array $theme_tokens
	): mixed {
		if ( 'root' === $scope_key ) {
			return null;
		}

		if ( str_starts_with( $scope_key, 'elements.' ) ) {
			$element        = substr( $scope_key, strlen( 'elements.' ) );
			$merged_element = is_array( $merged_styles['elements'][ $element ]['color'] ?? null )
				? $merged_styles['elements'][ $element ]['color']
				: [];

			if ( array_key_exists( $side, $merged_element ) ) {
				return $merged_element[ $side ];
			}

			$element_styles = is_array( $theme_tokens['elementStyles'][ $element ]['base'] ?? null )
				? $theme_tokens['elementStyles'][ $element ]['base']
				: [];

			return $element_styles[ $side ] ?? null;
		}

		if ( str_starts_with( $scope_key, 'blocks.' ) ) {
			$block_name = substr( $scope_key, strlen( 'blocks.' ) );
			$block      = is_array( $merged_styles['blocks'][ $block_name ]['color'] ?? null )
				? $merged_styles['blocks'][ $block_name ]['color']
				: [];

			return $block[ $side ] ?? null;
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 *
	 * @return array<string, mixed>|null
	 */
	private static function last_operation( array $operations ): ?array {
		return [] !== $operations ? $operations[ count( $operations ) - 1 ] : null;
	}

	/**
	 * @param array<string, mixed>|null $operation
	 * @param array<string, mixed>      $context
	 * @param array<string, mixed>      $theme_tokens
	 *
	 * @return array{hex: string|null, fail: array{passed: bool, kind: string, reason: string, ratio: null}|null}
	 */
	private static function resolve_side_for_evaluation(
		?array $operation,
		string $side,
		string $scope_key,
		array $context,
		array $theme_tokens
	): array {
		if ( null !== $operation ) {
			$resolved = self::resolve_color_value( $operation['value'] ?? null, $theme_tokens );

			if ( ! $resolved['resolved'] ) {
				return [
					'hex'  => null,
					'fail' => self::unavailable_result( $side, $scope_key ),
				];
			}

			return [
				'hex'  => $resolved['hex'],
				'fail' => null,
			];
		}

		$hex = self::merged_complement_hex( $scope_key, $side, $context );

		if ( null === $hex ) {
			return [
				'hex'  => null,
				'fail' => self::unavailable_result( $side, $scope_key ),
			];
		}

		return [
			'hex'  => $hex,
			'fail' => null,
		];
	}

	/**
	 * @return array{passed: bool, kind: string, reason: string, ratio: null}
	 */
	private static function unavailable_result( string $side, string $scope_key ): array {
		return [
			'passed' => false,
			'kind'   => 'unavailable',
			'reason' => sprintf(
				/* translators: 1: color side, either background or text; 2: style scope */
				__( 'Contrast check unavailable: unresolved %1$s at %2$s.', 'flavor-agent' ),
				$side,
				$scope_key
			),
			'ratio'  => null,
		];
	}

	/**
	 * @param array<int, string> $scope_keys
	 *
	 * @return array<int, string>
	 */
	private static function scope_keys_in_enum_order( array $scope_keys ): array {
		$known  = [];
		$blocks = [];

		foreach ( $scope_keys as $key ) {
			if ( in_array( $key, self::ENUM_SCOPE_ORDER, true ) ) {
				$known[ (int) array_search( $key, self::ENUM_SCOPE_ORDER, true ) ] = $key;
				continue;
			}

			if ( str_starts_with( $key, 'blocks.' ) ) {
				$blocks[] = $key;
			}
		}

		ksort( $known );
		sort( $blocks );

		return array_merge( array_values( $known ), $blocks );
	}

	/**
	 * @param array<string, mixed>|null $operation
	 */
	private static function label_for( ?array $operation, string $hex_fallback ): string {
		if ( is_array( $operation ) && is_string( $operation['value'] ?? null ) ) {
			$value = $operation['value'];

			if ( preg_match( '/^var:preset\|color\|([a-z0-9_-]+)$/i', $value, $matches ) === 1 ) {
				return sanitize_text_field( (string) $matches[1] );
			}

			if ( preg_match( '/^var\(--wp--preset--color--([a-z0-9_-]+)\)$/i', $value, $matches ) === 1 ) {
				return sanitize_text_field( (string) $matches[1] );
			}
		}

		return $hex_fallback;
	}
}
