<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

use FlavorAgent\Support\StringArray;

final class BlockRecommendationExecutionContract {

	private const STYLE_PANELS = [
		'color'      => true,
		'filter'     => true,
		'typography' => true,
		'dimensions' => true,
		'border'     => true,
		'shadow'     => true,
		'background' => true,
	];

	public static function from_context( array $context ): array {
		$block                  = self::normalize_map( $context['block'] ?? [] );
		$theme_tokens           = self::normalize_map( $context['themeTokens'] ?? [] );
		$inspector_panels       = self::normalize_inspector_panels( $block['inspectorPanels'] ?? [] );
		$panel_mapping_explicit = ! empty( $block['inspectorPanelsExplicit'] );
		$content_keys           = array_values(
			array_filter(
				array_keys( self::normalize_map( $block['contentAttributes'] ?? [] ) ),
				'is_string'
			)
		);

		return [
			'inspectorPanels'          => $inspector_panels,
			'allowedPanels'            => array_values( array_keys( $inspector_panels ) ),
			'panelMappingKnown'        => [] !== $inspector_panels || $panel_mapping_explicit,
			'hasExplicitlyEmptyPanels' => $panel_mapping_explicit && [] === $inspector_panels,
			'styleSupportPaths'        => self::collect_style_support_paths( $inspector_panels ),
			'bindableAttributes'       => StringArray::sanitize( $block['bindableAttributes'] ?? [] ),
			'contentAttributeKeys'     => $content_keys,
			'configAttributeKeys'      => array_values(
				array_filter(
					array_keys( self::normalize_map( $block['configAttributes'] ?? [] ) ),
					'is_string'
				)
			),
			'supportsContentRole'      => ! empty( $block['supportsContentRole'] ),
			'editingMode'              => self::normalize_editing_mode( $block['editingMode'] ?? 'default' ),
			'isInsideContentOnly'      => ! empty( $block['isInsideContentOnly'] ),
			'usesInnerBlocksAsContent' => ! empty( $block['supportsContentRole'] ) && [] === $content_keys,
			'registeredStyles'         => self::collect_registered_style_names( $block['styles'] ?? [] ),
			'presetSlugs'              => [
				'color'      => self::collect_preset_slugs( $theme_tokens['colorPresets'] ?? [] ),
				'gradient'   => self::collect_preset_slugs( $theme_tokens['gradientPresets'] ?? [] ),
				'duotone'    => self::collect_preset_slugs( $theme_tokens['duotonePresets'] ?? [] ),
				'fontsize'   => self::collect_preset_slugs( $theme_tokens['fontSizePresets'] ?? [] ),
				'fontfamily' => self::collect_preset_slugs( $theme_tokens['fontFamilyPresets'] ?? [] ),
				'spacing'    => self::collect_preset_slugs( $theme_tokens['spacingPresets'] ?? [] ),
				'shadow'     => self::collect_preset_slugs( $theme_tokens['shadowPresets'] ?? [] ),
			],
			'enabledFeatures'          => self::normalize_map( $theme_tokens['enabledFeatures'] ?? [] ),
			'layout'                   => self::normalize_map( $theme_tokens['layout'] ?? [] ),
		];
	}

	private static function collect_style_support_paths( array $inspector_panels ): array {
		$paths = [];

		foreach ( $inspector_panels as $panel => $entries ) {
			if ( ! isset( self::STYLE_PANELS[ $panel ] ) || ! is_array( $entries ) ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( ! is_string( $entry ) ) {
					continue;
				}

				$path = trim( $entry );

				if ( '' === $path || in_array( $path, $paths, true ) ) {
					continue;
				}

				$paths[] = $path;
			}
		}

		sort( $paths );

		return $paths;
	}

	private static function collect_registered_style_names( mixed $raw_styles ): array {
		$styles = [];

		foreach ( self::normalize_list( $raw_styles ) as $style ) {
			if ( ! is_array( $style ) ) {
				continue;
			}

			$name = is_string( $style['name'] ?? null ) ? sanitize_key( $style['name'] ) : '';

			if ( '' === $name || in_array( $name, $styles, true ) ) {
				continue;
			}

			$styles[] = $name;
		}

		sort( $styles );

		return $styles;
	}

	private static function collect_preset_slugs( mixed $raw_presets ): array {
		$slugs = [];

		foreach ( self::normalize_list( $raw_presets ) as $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}

			$slug = is_string( $preset['slug'] ?? null ) ? sanitize_key( $preset['slug'] ) : '';

			if ( '' === $slug || in_array( $slug, $slugs, true ) ) {
				continue;
			}

			$slugs[] = $slug;
		}

		sort( $slugs );

		return $slugs;
	}

	private static function normalize_inspector_panels( mixed $value ): array {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		if ( self::is_list_array( $value ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $value as $panel => $entries ) {
			if ( ! is_string( $panel ) ) {
				continue;
			}

			$panel_key = sanitize_key( $panel );

			if ( '' === $panel_key ) {
				continue;
			}

			$normalized[ $panel_key ] = StringArray::sanitize( $entries );
		}

		ksort( $normalized );

		return $normalized;
	}

	private static function normalize_editing_mode( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return 'default';
		}

		$value = strtolower( preg_replace( '/[^a-z]/i', '', $value ) ?? '' );

		return match ( $value ) {
			'contentonly' => 'contentOnly',
			'disabled' => 'disabled',
			default => 'default',
		};
	}

	private static function normalize_map( mixed $value ): array {
		$normalized = self::normalize_value( $value );

		return is_array( $normalized ) ? $normalized : [];
	}

	private static function normalize_list( mixed $value ): array {
		$normalized = self::normalize_map( $value );

		return array_values( $normalized );
	}

	private static function normalize_value( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = [];

			foreach ( $value as $key => $entry ) {
				$normalized[ $key ] = self::normalize_value( $entry );
			}

			return $normalized;
		}

		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}

		return null;
	}

	private static function is_list_array( array $values ): bool {
		$expected_index = 0;

		foreach ( $values as $key => $_value ) {
			if ( $key !== $expected_index ) {
				return false;
			}

			++$expected_index;
		}

		return true;
	}
}
