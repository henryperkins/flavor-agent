<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class ThemeTokenCollector {

	private static ?string $cached_hash = null;

	private static ?array $cached_tokens = null;

	public function for_tokens(): array {
		$settings      = wp_get_global_settings();
		$global_styles = wp_get_global_styles();
		$cache_hash    = md5( serialize( [ $settings, $global_styles ] ) );

		if ( self::$cached_hash === $cache_hash && is_array( self::$cached_tokens ) ) {
			return self::$cached_tokens;
		}

		$color_presets = array_map(
			static fn( array $preset ): array => [
				'name'   => (string) ( $preset['name'] ?? '' ),
				'slug'   => (string) ( $preset['slug'] ?? '' ),
				'color'  => (string) ( $preset['color'] ?? '' ),
				'cssVar' => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--color--' . (string) $preset['slug'] . ')'
					: '',
			],
			$this->merge_presets( $settings['color']['palette'] ?? [] )
		);
		$colors        = [];

		foreach ( $color_presets as $preset ) {
			$colors[] = ( $preset['slug'] ?? '' ) . ': ' . ( $preset['color'] ?? '' );
		}

		$gradient_presets = array_map(
			static fn( array $preset ): array => [
				'name'     => (string) ( $preset['name'] ?? '' ),
				'slug'     => (string) ( $preset['slug'] ?? '' ),
				'gradient' => (string) ( $preset['gradient'] ?? '' ),
				'cssVar'   => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--gradient--' . (string) $preset['slug'] . ')'
					: '',
			],
			$this->merge_presets( $settings['color']['gradients'] ?? [] )
		);
		$gradients        = [];

		foreach ( $gradient_presets as $preset ) {
			$slug        = (string) ( $preset['slug'] ?? '' );
			$gradient    = (string) ( $preset['gradient'] ?? '' );
			$gradients[] = $gradient !== ''
				? "{$slug}: {$gradient}"
				: $slug;
		}

		$font_size_presets = array_map(
			static fn( array $preset ): array => [
				'name'   => (string) ( $preset['name'] ?? '' ),
				'slug'   => (string) ( $preset['slug'] ?? '' ),
				'size'   => (string) ( $preset['size'] ?? '' ),
				'fluid'  => $preset['fluid'] ?? null,
				'cssVar' => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--font-size--' . (string) $preset['slug'] . ')'
					: '',
			],
			$this->merge_presets( $settings['typography']['fontSizes'] ?? [] )
		);
		$font_sizes        = [];

		foreach ( $font_size_presets as $preset ) {
			$font_sizes[] = ( $preset['slug'] ?? '' ) . ': ' . ( $preset['size'] ?? '' );
		}

		$font_family_presets = array_map(
			static fn( array $preset ): array => [
				'name'       => (string) ( $preset['name'] ?? '' ),
				'slug'       => (string) ( $preset['slug'] ?? '' ),
				'fontFamily' => (string) ( $preset['fontFamily'] ?? '' ),
				'cssVar'     => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--font-family--' . (string) $preset['slug'] . ')'
					: '',
			],
			$this->merge_presets( $settings['typography']['fontFamilies'] ?? [] )
		);
		$font_families       = [];

		foreach ( $font_family_presets as $preset ) {
			$font_families[] = ( $preset['slug'] ?? '' ) . ': ' . ( $preset['fontFamily'] ?? '' );
		}

		$spacing_presets = array_map(
			static fn( array $preset ): array => [
				'name'   => (string) ( $preset['name'] ?? '' ),
				'slug'   => (string) ( $preset['slug'] ?? '' ),
				'size'   => (string) ( $preset['size'] ?? '' ),
				'cssVar' => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--spacing--' . (string) $preset['slug'] . ')'
					: '',
			],
			$this->merge_presets( $settings['spacing']['spacingSizes'] ?? [] )
		);
		$spacing         = [];

		foreach ( $spacing_presets as $preset ) {
			$spacing[] = ( $preset['slug'] ?? '' ) . ': ' . ( $preset['size'] ?? '' );
		}

		$shadow_presets = array_map(
			static fn( array $preset ): array => [
				'name'   => (string) ( $preset['name'] ?? '' ),
				'slug'   => (string) ( $preset['slug'] ?? '' ),
				'shadow' => (string) ( $preset['shadow'] ?? '' ),
				'cssVar' => isset( $preset['slug'] ) && (string) $preset['slug'] !== ''
					? 'var(--wp--preset--shadow--' . (string) $preset['slug'] . ')'
					: '',
			],
			$this->merge_presets( $settings['shadow']['presets'] ?? [] )
		);
		$shadows        = [];

		foreach ( $shadow_presets as $preset ) {
			$shadows[] = ( $preset['slug'] ?? '' ) . ': ' . ( $preset['shadow'] ?? '' );
		}

		$duotone = [];

		foreach ( $this->merge_presets( $settings['color']['duotone'] ?? [] ) as $preset ) {
			$slug          = (string) ( $preset['slug'] ?? '' );
			$preset_colors = is_array( $preset['colors'] ?? null ) ? $preset['colors'] : [];

			if ( '' === $slug ) {
				continue;
			}

			$color_summary = implode( ' / ', array_map( 'strval', array_slice( $preset_colors, 0, 2 ) ) );
			$duotone[]     = $color_summary !== ''
				? sprintf( '%s: %s', $slug, $color_summary )
				: $slug;
		}

		self::$cached_hash   = $cache_hash;
		self::$cached_tokens = [
			'colors'            => $colors,
			'colorPresets'      => $color_presets,
			'gradients'         => $gradients,
			'gradientPresets'   => $gradient_presets,
			'fontSizes'         => $font_sizes,
			'fontSizePresets'   => $font_size_presets,
			'fontFamilies'      => $font_families,
			'fontFamilyPresets' => $font_family_presets,
			'spacing'           => $spacing,
			'spacingPresets'    => $spacing_presets,
			'shadows'           => $shadows,
			'shadowPresets'     => $shadow_presets,
			'duotone'           => $duotone,
			'diagnostics'       => [
				'source'      => 'server',
				'settingsKey' => 'wp_get_global_settings',
				'reason'      => 'server-global-settings',
			],
			'duotonePresets'    => array_map(
				static fn( array $preset ): array => [
					'slug'   => (string) ( $preset['slug'] ?? '' ),
					'colors' => is_array( $preset['colors'] ?? null ) ? array_values( $preset['colors'] ) : [],
				],
				$this->merge_presets( $settings['color']['duotone'] ?? [] )
			),
			'layout'            => [
				'content'                       => $settings['layout']['contentSize'] ?? '',
				'wide'                          => $settings['layout']['wideSize'] ?? '',
				'allowEditing'                  => $settings['layout']['allowEditing'] ?? true,
				'allowCustomContentAndWideSize' => $settings['layout']['allowCustomContentAndWideSize'] ?? true,
			],
			'enabledFeatures'   => [
				'lineHeight'      => $settings['typography']['lineHeight'] ?? false,
				'dropCap'         => $settings['typography']['dropCap'] ?? true,
				'fontStyle'       => $settings['typography']['fontStyle'] ?? false,
				'fontWeight'      => $settings['typography']['fontWeight'] ?? false,
				'letterSpacing'   => $settings['typography']['letterSpacing'] ?? false,
				'textDecoration'  => $settings['typography']['textDecoration'] ?? false,
				'textTransform'   => $settings['typography']['textTransform'] ?? false,
				'customColors'    => $settings['color']['custom'] ?? true,
				'backgroundColor' => array_key_exists( 'background', $settings['color'] ?? [] )
						? (bool) $settings['color']['background']
						: true,
				'textColor'       => array_key_exists( 'text', $settings['color'] ?? [] )
					? (bool) $settings['color']['text']
					: true,
				'linkColor'       => $settings['color']['link'] ?? false,
				'buttonColor'     => $settings['color']['button'] ?? false,
				'headingColor'    => $settings['color']['heading'] ?? false,
				'margin'          => $settings['spacing']['margin'] ?? false,
				'padding'         => $settings['spacing']['padding'] ?? false,
				'blockGap'        => $settings['spacing']['blockGap'] ?? null,
				'borderColor'     => $settings['border']['color'] ?? false,
				'borderRadius'    => $settings['border']['radius'] ?? false,
				'borderStyle'     => $settings['border']['style'] ?? false,
				'borderWidth'     => $settings['border']['width'] ?? false,
				'backgroundImage' => $settings['background']['backgroundImage'] ?? false,
				'backgroundSize'  => $settings['background']['backgroundSize'] ?? false,
			],
			'elementStyles'     => $this->collect_element_styles( $global_styles ),
			'blockPseudoStyles' => $this->collect_block_pseudo_styles( $global_styles ),
		];

		return self::$cached_tokens;
	}

	private function merge_presets( array|string $feature ): array {
		if ( ! is_array( $feature ) ) {
			return [];
		}

		if ( $this->is_list_array( $feature ) ) {
			return $feature;
		}

		$by_slug = [];

		foreach ( [ 'default', 'theme', 'custom' ] as $origin ) {
			foreach ( $feature[ $origin ] ?? [] as $item ) {
				$slug = $item['slug'] ?? '';

				if ( '' !== $slug ) {
					$by_slug[ $slug ] = $item;
				}
			}
		}

		return array_values( $by_slug );
	}

	private function collect_block_pseudo_styles( array $styles ): array {
		$block_styles   = $styles['blocks'] ?? [];
		$pseudo_classes = [ ':hover', ':focus', ':focus-visible', ':active' ];
		$result         = [];

		foreach ( $block_styles as $block_name => $style_definition ) {
			if ( ! is_array( $style_definition ) ) {
				continue;
			}

			$pseudos = [];

			foreach ( $pseudo_classes as $pseudo ) {
				if ( ! empty( $style_definition[ $pseudo ] ) ) {
					$pseudos[ $pseudo ] = $style_definition[ $pseudo ];
				}
			}

			if ( ! empty( $pseudos ) ) {
				$result[ $block_name ] = $pseudos;
			}
		}

		return $result;
	}

	private function collect_element_styles( array $styles ): array {
		$elements = $styles['elements'] ?? [];
		$result   = [];

		foreach ( $elements as $element => $style_definition ) {
			if ( ! is_array( $style_definition ) ) {
				continue;
			}

			$result[ $element ] = [
				'base'         => is_array( $style_definition['color'] ?? null ) ? $style_definition['color'] : [],
				'hover'        => is_array( $style_definition[':hover']['color'] ?? null ) ? $style_definition[':hover']['color'] : [],
				'focus'        => is_array( $style_definition[':focus']['color'] ?? null ) ? $style_definition[':focus']['color'] : [],
				'focusVisible' => is_array( $style_definition[':focus-visible'] ?? null ) ? $style_definition[':focus-visible'] : [],
			];
		}

		return $result;
	}

	private function is_list_array( array $values ): bool {
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
