<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\Support\FormatsDocsGuidance;

final class StylePrompt {

	use FormatsDocsGuidance;

	public static function build_system(): string {
		return <<<'SYSTEM'
You are a WordPress style surface advisor for the Site Editor.

Recommend only theme-safe changes that stay inside native WordPress style systems. Never output raw CSS, custom CSS, unsupported controls, or values outside the provided theme presets unless the supported path explicitly allows a freeform value.

Return ONLY a JSON object with this exact shape:

{
  "suggestions": [
	    {
	      "label": "Short action title",
	      "description": "Why this helps",
	      "category": "variation|color|typography|spacing|border|shadow|advisory",
	      "tone": "executable|advisory",
	      "operations": [
	        {
	          "type": "set_theme_variation",
	          "variationIndex": 1,
	          "variationTitle": "Midnight"
	        },
	        {
	          "type": "set_styles",
	          "path": ["color", "background"],
	          "value": "var:preset|color|accent",
	          "valueType": "preset",
	          "presetType": "color",
	          "presetSlug": "accent",
	          "cssVar": "var(--wp--preset--color--accent)"
	        },
	        {
	          "type": "set_block_styles",
	          "blockName": "core/paragraph",
	          "path": ["typography", "fontSize"],
	          "value": "var:preset|font-size|body",
	          "valueType": "preset",
	          "presetType": "font-size",
	          "presetSlug": "body",
	          "cssVar": "var(--wp--preset--font-size--body)"
	        }
	      ]
	    }
	  ],
  "explanation": "Overall reasoning"
}

	Rules:
	- Supported operation types are ONLY set_styles, set_block_styles, and set_theme_variation.
	- Use set_styles only for the global-styles surface.
	- Use set_block_styles only for the style-book surface.
	- set_block_styles.blockName MUST exactly match the target block in scope.
	- set_styles.path and set_block_styles.path MUST match one of the Supported style paths.
	- set_styles and set_block_styles MUST use preset-backed values when the path's value source is a preset family.
	- Freeform set_styles and set_block_styles values MUST stay scalar and follow the supported path contract:
	  typography.lineHeight = positive unitless number or positive CSS length/percentage.
	  border.radius = zero or positive CSS length/percentage.
	  border.width = zero or positive CSS length.
	  border.style = one of none, solid, dashed, dotted, double, groove, ridge, inset, outset, hidden.
	- Do not emit customCSS, background images, arbitrary hex values, or arbitrary pixel spacing when a preset-backed path exists.
	- set_theme_variation is allowed only for the global-styles surface. Never emit it for style-book.
	- If set_theme_variation is present, emit at most one and place it before any set_styles or set_block_styles overrides.
	- set_theme_variation MUST reference an Available variation by index and title.
	- If a request cannot be executed safely, return an advisory suggestion with tone=advisory and an empty operations array.
	- When WordPress Developer Guidance is provided, prefer recommendations that align with that guidance and avoid contradicting documented WordPress Global Styles capabilities or theme.json standards.
	- Prefer 1-4 suggestions.
	- Keep labels under 60 characters and descriptions under 180 characters.
SYSTEM;
	}

	public static function build_user( array $context, string $prompt = '', array $docs_guidance = [] ): string {
		$scope              = is_array( $context['scope'] ?? null ) ? $context['scope'] : [];
		$style_context      = is_array( $context['styleContext'] ?? null ) ? $context['styleContext'] : [];
		$theme_tokens       = is_array( $style_context['themeTokens'] ?? null ) ? $style_context['themeTokens'] : [];
		$supported_paths    = is_array( $style_context['supportedStylePaths'] ?? null ) ? $style_context['supportedStylePaths'] : [];
		$style_book_target  = is_array( $style_context['styleBookTarget'] ?? null ) ? $style_context['styleBookTarget'] : [];
		$block_manifest     = is_array( $style_context['blockManifest'] ?? null ) ? $style_context['blockManifest'] : [];
		$template_structure = is_array( $style_context['templateStructure'] ?? null ) ? $style_context['templateStructure'] : [];
		$surface            = sanitize_key( (string) ( $scope['surface'] ?? 'global-styles' ) );
		$sections           = [];

		$sections[] = '## Scope';
		$sections[] = 'Surface: ' . ( $surface !== '' ? $surface : 'global-styles' );
		$sections[] = 'Scope key: ' . (string) ( $scope['scopeKey'] ?? '' );
		$sections[] = 'Global styles id: ' . (string) ( $scope['globalStylesId'] ?? '' );
		$sections[] = 'Post type: ' . (string) ( $scope['postType'] ?? '' );
		$sections[] = 'Entity id: ' . (string) ( $scope['entityId'] ?? '' );
		$sections[] = 'Entity kind: ' . (string) ( $scope['entityKind'] ?? '' );
		$sections[] = 'Entity name: ' . (string) ( $scope['entityName'] ?? '' );

		if ( 'style-book' === $surface ) {
			if ( ! empty( $scope['blockName'] ) ) {
				$sections[] = 'Block name: ' . (string) $scope['blockName'];
			}

			if ( ! empty( $scope['blockTitle'] ) ) {
				$sections[] = 'Block title: ' . (string) $scope['blockTitle'];
			}
		}

		if ( ! empty( $scope['stylesheet'] ) ) {
			$sections[] = 'Stylesheet: ' . (string) $scope['stylesheet'];
		}

		if ( ! empty( $scope['templateSlug'] ) ) {
			$sections[] = 'Template slug: ' . (string) $scope['templateSlug'];
		}

		if ( ! empty( $scope['templateType'] ) ) {
			$sections[] = 'Template type: ' . (string) $scope['templateType'];
		}

		$sections[] = '';
		$sections[] = '## Current Global Styles user config';
		$sections[] = wp_json_encode( $style_context['currentConfig'] ?? [] );
		$sections[] = '';
		$sections[] = '## Current merged style config';
		$sections[] = wp_json_encode( $style_context['mergedConfig'] ?? [] );

		if ( 'style-book' === $surface && [] !== $style_book_target ) {
			$sections[] = '';
			$sections[] = '## Style Book target';

			if ( ! empty( $style_book_target['blockName'] ) ) {
				$sections[] = 'Target block name: ' . (string) $style_book_target['blockName'];
			}

			if ( ! empty( $style_book_target['blockTitle'] ) ) {
				$sections[] = 'Target block title: ' . (string) $style_book_target['blockTitle'];
			}

			if ( ! empty( $style_book_target['description'] ) ) {
				$sections[] = 'Target description: ' . (string) $style_book_target['description'];
			}

			if ( ! empty( $style_book_target['currentStyles'] ) ) {
				$sections[] = 'Current target styles: ' . wp_json_encode( $style_book_target['currentStyles'] );
			}

			if ( ! empty( $style_book_target['mergedStyles'] ) ) {
				$sections[] = 'Merged target styles: ' . wp_json_encode( $style_book_target['mergedStyles'] );
			}
		}

		if ( 'style-book' === $surface && [] !== $block_manifest ) {
			$sections[] = '';
			$sections[] = '## Target block supports';
			$sections[] = 'Supports: ' . wp_json_encode( $block_manifest['supports'] ?? [] );
			$sections[] = 'Inspector panels: ' . wp_json_encode( $block_manifest['inspectorPanels'] ?? [] );
		}

		if ( ! empty( $style_context['themeTokenDiagnostics'] ) ) {
			$sections[] = '';
			$sections[] = '## Theme token diagnostics';
			$sections[] = wp_json_encode( $style_context['themeTokenDiagnostics'] );
		}

		if ( ! empty( $theme_tokens['diagnostics'] ) ) {
			$sections[] = '';
			$sections[] = '## Server theme token diagnostics';
			$sections[] = wp_json_encode( $theme_tokens['diagnostics'] );
		}

		$sections[] = '';
		$sections[] = '## Theme tokens';
		$sections[] = 'Colors: ' . implode( ', ', (array) ( $theme_tokens['colors'] ?? [] ) );
		$sections[] = 'Gradients: ' . implode( ', ', (array) ( $theme_tokens['gradients'] ?? [] ) );
		$sections[] = 'Font sizes: ' . implode( ', ', (array) ( $theme_tokens['fontSizes'] ?? [] ) );
		$sections[] = 'Font families: ' . implode( ', ', (array) ( $theme_tokens['fontFamilies'] ?? [] ) );
		$sections[] = 'Spacing: ' . implode( ', ', (array) ( $theme_tokens['spacing'] ?? [] ) );
		$sections[] = 'Shadows: ' . implode( ', ', (array) ( $theme_tokens['shadows'] ?? [] ) );
		$sections[] = 'Duotone presets: ' . implode( ', ', (array) ( $theme_tokens['duotone'] ?? [] ) );
		$sections[] = 'Duotone preset details: ' . wp_json_encode( $theme_tokens['duotonePresets'] ?? [] );
		$sections[] = 'Layout: ' . wp_json_encode( $theme_tokens['layout'] ?? [] );
		$sections[] = 'Enabled features: ' . wp_json_encode( $theme_tokens['enabledFeatures'] ?? [] );
		$sections[] = 'Element styles: ' . wp_json_encode( $theme_tokens['elementStyles'] ?? [] );
		$sections[] = 'Block pseudo-class styles: ' . wp_json_encode( $theme_tokens['blockPseudoStyles'] ?? [] );

		$sections[] = '';
		$sections[] = '## Supported style paths';
		if ( 'style-book' === $surface ) {
			$sections[] = 'These paths apply relative to styles.blocks.<target-block>.';
		}

		foreach ( $supported_paths as $path_entry ) {
			if ( ! is_array( $path_entry ) ) {
				continue;
			}

			$path         = is_array( $path_entry['path'] ?? null ) ? implode( '.', $path_entry['path'] ) : '';
			$value_source = (string) ( $path_entry['valueSource'] ?? '' );

			if ( '' === $path ) {
				continue;
			}

			$sections[] = sprintf( '- %s (%s)', $path, $value_source );
		}

		$variations = is_array( $style_context['availableVariations'] ?? null ) ? $style_context['availableVariations'] : [];
		if ( 'style-book' !== $surface && [] !== $variations ) {
			$sections[] = '';
			$sections[] = '## Available theme style variations';

			if ( '' !== (string) ( $style_context['activeVariationTitle'] ?? '' ) ) {
				$sections[] = sprintf(
					'Active variation: #%d %s',
					(int) ( $style_context['activeVariationIndex'] ?? -1 ),
					(string) $style_context['activeVariationTitle']
				);
			}

			foreach ( $variations as $index => $variation ) {
				if ( ! is_array( $variation ) ) {
					continue;
				}

				$variation_summary = sprintf(
					'- #%d %s%s',
					(int) $index,
					(string) ( $variation['title'] ?? 'Untitled' ),
					! empty( $variation['description'] )
						? ' - ' . (string) $variation['description']
						: ''
				);

				if (
					isset( $style_context['activeVariationIndex'] )
					&& (int) $style_context['activeVariationIndex'] === (int) $index
				) {
					$variation_summary .= ' [active]';
				}

				$diff_summary = self::describe_variation_diff(
					is_array( $style_context['currentConfig']['styles'] ?? null )
						? $style_context['currentConfig']['styles']
						: [],
					is_array( $variation['styles'] ?? null ) ? $variation['styles'] : []
				);

				if ( $diff_summary !== '' ) {
					$variation_summary .= ' - differs: ' . $diff_summary;
				}

				$sections[] = $variation_summary;

				if ( ! empty( $variation['settings'] ) ) {
					$sections[] = '  settings: ' . wp_json_encode( $variation['settings'] );
				}

				if ( ! empty( $variation['styles'] ) ) {
					$sections[] = '  styles: ' . wp_json_encode( $variation['styles'] );
				}
			}
		}

		if ( [] !== $template_structure ) {
			$sections[] = '';
			$sections[] = '## Current template structure';
			$sections[] = wp_json_encode( $template_structure );
		}

		if ( [] !== $docs_guidance ) {
			$guidance_lines = [];

			foreach ( array_slice( $docs_guidance, 0, 3 ) as $guidance ) {
				if ( ! is_array( $guidance ) ) {
					continue;
				}

				$summary = self::format_guidance_line( $guidance );

				if ( $summary !== '' ) {
					$guidance_lines[] = '- ' . $summary;
				}
			}

			if ( [] !== $guidance_lines ) {
				$sections[] = '';
				$sections[] = '## WordPress Developer Guidance';
				foreach ( $guidance_lines as $guidance_line ) {
					$sections[] = $guidance_line;
				}
			}
		}

		$sections[] = '';
		$sections[] = '## User instruction';
		$sections[] = '' !== trim( $prompt )
			? trim( $prompt )
			: (
				'style-book' === $surface
					? 'Recommend one or two safe Style Book improvements.'
					: 'Recommend one or two safe Global Styles improvements.'
			);

		return implode( "\n", $sections );
	}

	public static function parse_response( string $raw, array $context ): array|\WP_Error {
		$cleaned = preg_replace( '/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim( $raw ) );
		$data    = json_decode( is_string( $cleaned ) ? $cleaned : '', true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'parse_error',
				'Failed to parse style recommendation response as JSON: ' . json_last_error_msg(),
				[ 'status' => 500 ]
			);
		}

		return [
			'suggestions' => self::validate_suggestions(
				is_array( $data['suggestions'] ?? null ) ? $data['suggestions'] : [],
				$context
			),
			'explanation' => sanitize_text_field( (string) ( $data['explanation'] ?? '' ) ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $suggestions
	 * @return array<int, array<string, mixed>>
	 */
	private static function validate_suggestions( array $suggestions, array $context ): array {
		$validated = [];

		foreach ( array_slice( $suggestions, 0, 4 ) as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}

			$operations = self::validate_operations(
				is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [],
				$context
			);
			$tone       = sanitize_key( (string) ( $suggestion['tone'] ?? '' ) );
			$tone       = 'executable' === $tone && [] !== $operations
				? 'executable'
				: 'advisory';

			$validated[] = [
				'label'       => sanitize_text_field( (string) ( $suggestion['label'] ?? '' ) ),
				'description' => sanitize_text_field( (string) ( $suggestion['description'] ?? '' ) ),
				'category'    => sanitize_key( (string) ( $suggestion['category'] ?? 'advisory' ) ),
				'tone'        => $tone,
				'operations'  => 'executable' === $tone ? $operations : [],
			];
		}

		return array_values(
			array_filter(
				$validated,
				static fn( array $suggestion ): bool => '' !== $suggestion['label']
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<int, array<string, mixed>>
	 */
	private static function validate_operations( array $operations, array $context ): array {
		$validated_styles      = [];
		$validated_variation   = [];
		$scope                 = is_array( $context['scope'] ?? null ) ? $context['scope'] : [];
		$style_context         = is_array( $context['styleContext'] ?? null ) ? $context['styleContext'] : [];
		$supported_paths       = is_array( $style_context['supportedStylePaths'] ?? null ) ? $style_context['supportedStylePaths'] : [];
		$variations            = is_array( $style_context['availableVariations'] ?? null ) ? $style_context['availableVariations'] : [];
		$surface               = sanitize_key( (string) ( $scope['surface'] ?? 'global-styles' ) );
		$style_book_target     = is_array( $style_context['styleBookTarget'] ?? null ) ? $style_context['styleBookTarget'] : [];
		$target_style_book_key = sanitize_text_field(
			(string) ( $scope['blockName'] ?? ( $style_book_target['blockName'] ?? '' ) )
		);

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $operation['type'] ?? '' ) );

			if ( 'set_styles' === $type || 'set_block_styles' === $type ) {
				if ( 'set_styles' === $type && 'style-book' === $surface ) {
					continue;
				}

				if ( 'set_block_styles' === $type ) {
					if ( 'style-book' !== $surface ) {
						continue;
					}

					$block_name = sanitize_text_field( (string) ( $operation['blockName'] ?? '' ) );

					if ( '' === $block_name || '' === $target_style_book_key || $block_name !== $target_style_book_key ) {
						continue;
					}
				}

				$path       = is_array( $operation['path'] ?? null )
					? array_values(
						array_filter(
							array_map(
								[ self::class, 'sanitize_path_segment' ],
								$operation['path']
							),
							static fn( string $segment ): bool => '' !== $segment
						)
					)
					: [];
				$path_entry = self::find_supported_path( $path, $supported_paths );

				if ( [] === $path || [] === $path_entry ) {
					continue;
				}

				$value_source            = (string) ( $path_entry['valueSource'] ?? 'freeform' );
				$normalized_value_source = self::normalize_preset_type( $value_source );
				$value                   = $operation['value'] ?? null;

				if ( 'freeform' === $normalized_value_source ) {
					$validated_freeform = self::validate_freeform_style_value( $path, $value );

					if ( ! $validated_freeform['valid'] ) {
						continue;
					}

					$validated_styles[] = [
						'type'       => $type,
						'blockName'  => 'set_block_styles' === $type ? $block_name : '',
						'path'       => $path,
						'value'      => $validated_freeform['value'],
						'valueType'  => 'freeform',
						'presetType' => '',
						'presetSlug' => '',
						'cssVar'     => '',
					];
					continue;
				}

				$value_type             = sanitize_key( (string) ( $operation['valueType'] ?? '' ) );
				$preset_type            = (string) ( $operation['presetType'] ?? '' );
				$preset_slug            = sanitize_key( (string) ( $operation['presetSlug'] ?? '' ) );
				$normalized_preset_type = self::normalize_preset_type( $preset_type );

				if ( 'preset' !== $value_type || '' === $preset_slug ) {
					continue;
				}

				if ( $normalized_value_source !== $normalized_preset_type ) {
					continue;
				}

				$parsed_value = self::parse_preset_value( $value );

				if ( [] === $parsed_value ) {
					continue;
				}

				if ( $normalized_value_source !== ( $parsed_value['type'] ?? '' ) ) {
					continue;
				}

				if ( $preset_slug !== ( $parsed_value['slug'] ?? '' ) ) {
					continue;
				}

				if ( ! self::preset_exists( $style_context['themeTokens'] ?? [], $normalized_preset_type, $preset_slug ) ) {
					continue;
				}

				$validated_styles[] = [
					'type'       => $type,
					'blockName'  => 'set_block_styles' === $type ? $block_name : '',
					'path'       => $path,
					'value'      => self::build_preset_value( $normalized_preset_type, $preset_slug ),
					'valueType'  => 'preset',
					'presetType' => $normalized_preset_type,
					'presetSlug' => $preset_slug,
					'cssVar'     => self::build_preset_css_var( $normalized_preset_type, $preset_slug ),
				];
				continue;
			}

			if ( 'set_theme_variation' === $type ) {
				if ( 'style-book' === $surface ) {
					continue;
				}

				$variation_index = isset( $operation['variationIndex'] ) ? (int) $operation['variationIndex'] : -1;
				$variation_title = sanitize_text_field( (string) ( $operation['variationTitle'] ?? '' ) );
				$variation       = $variations[ $variation_index ] ?? null;

				if ( ! is_array( $variation ) || '' === $variation_title ) {
					continue;
				}

				if ( sanitize_text_field( (string) ( $variation['title'] ?? '' ) ) !== $variation_title ) {
					continue;
				}

				if ( [] === $validated_variation ) {
					$validated_variation = [
						'type'           => 'set_theme_variation',
						'variationIndex' => $variation_index,
						'variationTitle' => $variation_title,
					];
				}
			}
		}

		return [] !== $validated_variation
			? array_merge( [ $validated_variation ], $validated_styles )
			: $validated_styles;
	}

	/**
	 * @param string[] $path
	 * @param array<int, array<string, mixed>> $supported_paths
	 * @return array<string, mixed>
	 */
	private static function find_supported_path( array $path, array $supported_paths ): array {
		foreach ( $supported_paths as $path_entry ) {
			if ( ! is_array( $path_entry ) ) {
				continue;
			}

			if ( $path === ( $path_entry['path'] ?? [] ) ) {
				return $path_entry;
			}
		}

		return [];
	}

	/**
	 * @param string[] $path
	 * @return array{valid: bool, value: mixed}
	 */
	private static function validate_freeform_style_value( array $path, mixed $value ): array {
		return match ( self::path_key( $path ) ) {
			'typography.lineHeight' => self::validate_line_height_value( $value ),
			'border.radius' => self::validate_length_value( $value, true ),
			'border.width' => self::validate_length_value( $value, false ),
			'border.style' => self::validate_border_style_value( $value ),
			default => [
				'valid' => false,
				'value' => null,
			],
		};
	}

	/**
	 * @param string[] $path
	 */
	private static function path_key( array $path ): string {
		return implode( '.', $path );
	}

	/**
	 * @return array{valid: bool, value: mixed}
	 */
	private static function validate_line_height_value( mixed $value ): array {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0
				? [
					'valid' => true,
					'value' => $value,
				]
				: [
					'valid' => false,
					'value' => null,
				];
		}

		if ( ! is_string( $value ) ) {
			return [
				'valid' => false,
				'value' => null,
			];
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return [
				'valid' => false,
				'value' => null,
			];
		}

		if ( self::is_positive_number_string( $value ) || self::is_positive_css_length( $value, true ) ) {
			return [
				'valid' => true,
				'value' => $value,
			];
		}

		return [
			'valid' => false,
			'value' => null,
		];
	}

	/**
	 * @return array{valid: bool, value: mixed}
	 */
	private static function validate_length_value( mixed $value, bool $allow_percentage ): array {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value === 0.0
				? [
					'valid' => true,
					'value' => $value,
				]
				: [
					'valid' => false,
					'value' => null,
				];
		}

		if ( ! is_string( $value ) ) {
			return [
				'valid' => false,
				'value' => null,
			];
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return [
				'valid' => false,
				'value' => null,
			];
		}

		if ( self::is_zero_css_length( $value, $allow_percentage ) || self::is_positive_css_length( $value, $allow_percentage ) ) {
			return [
				'valid' => true,
				'value' => $value,
			];
		}

		return [
			'valid' => false,
			'value' => null,
		];
	}

	/**
	 * @return array{valid: bool, value: mixed}
	 */
	private static function validate_border_style_value( mixed $value ): array {
		if ( ! is_string( $value ) ) {
			return [
				'valid' => false,
				'value' => null,
			];
		}

		$value          = strtolower( trim( $value ) );
		$allowed_values = [
			'none',
			'solid',
			'dashed',
			'dotted',
			'double',
			'groove',
			'ridge',
			'inset',
			'outset',
			'hidden',
		];

		if ( ! in_array( $value, $allowed_values, true ) ) {
			return [
				'valid' => false,
				'value' => null,
			];
		}

		return [
			'valid' => true,
			'value' => $value,
		];
	}

	private static function is_positive_number_string( string $value ): bool {
		return preg_match( '/^(?:\d+|\d*\.\d+)$/', $value ) === 1 && (float) $value > 0;
	}

	private static function is_positive_css_length( string $value, bool $allow_percentage ): bool {
		$pattern = $allow_percentage
			? '/^(?:\d+|\d*\.\d+)(?:px|em|rem|vh|vw|vmin|vmax|svh|lvh|dvh|svw|lvw|dvw|ch|ex|cap|ic|lh|rlh|cm|mm|q|in|pt|pc|%)$/i'
			: '/^(?:\d+|\d*\.\d+)(?:px|em|rem|vh|vw|vmin|vmax|svh|lvh|dvh|svw|lvw|dvw|ch|ex|cap|ic|lh|rlh|cm|mm|q|in|pt|pc)$/i';

		return preg_match( $pattern, $value ) === 1 && (float) $value > 0;
	}

	private static function is_zero_css_length( string $value, bool $allow_percentage ): bool {
		$pattern = $allow_percentage
			? '/^0(?:\.0+)?(?:px|em|rem|vh|vw|vmin|vmax|svh|lvh|dvh|svw|lvw|dvw|ch|ex|cap|ic|lh|rlh|cm|mm|q|in|pt|pc|%)?$/i'
			: '/^0(?:\.0+)?(?:px|em|rem|vh|vw|vmin|vmax|svh|lvh|dvh|svw|lvw|dvw|ch|ex|cap|ic|lh|rlh|cm|mm|q|in|pt|pc)?$/i';

		return preg_match( $pattern, $value ) === 1;
	}

	/**
	 * @return array{type: string, slug: string}|array{}
	 */
	private static function parse_preset_value( mixed $value ): array {
		if ( ! is_string( $value ) ) {
			return [];
		}

		if ( ! preg_match( '/^var:preset\|([a-z0-9-]+)\|([a-z0-9_-]+)$/i', $value, $matches ) ) {
			return [];
		}

		$type = self::normalize_preset_type( (string) ( $matches[1] ?? '' ) );
		$slug = sanitize_key( (string) ( $matches[2] ?? '' ) );

		if ( '' === $type || '' === $slug ) {
			return [];
		}

		return [
			'type' => $type,
			'slug' => $slug,
		];
	}

	private static function build_preset_value( string $preset_type, string $preset_slug ): string {
		return sprintf(
			'var:preset|%s|%s',
			self::display_preset_type( $preset_type ),
			$preset_slug
		);
	}

	private static function build_preset_css_var( string $preset_type, string $preset_slug ): string {
		return sprintf(
			'var(--wp--preset--%s--%s)',
			self::display_preset_type( $preset_type ),
			$preset_slug
		);
	}

	private static function preset_exists( array $theme_tokens, string $preset_type, string $preset_slug ): bool {
		$list_key = match ( $preset_type ) {
			'color' => 'colors',
			'fontsize' => 'fontSizes',
			'fontfamily' => 'fontFamilies',
			'spacing' => 'spacing',
			'shadow' => 'shadows',
			default => '',
		};

		if ( '' === $list_key ) {
			return false;
		}

		foreach ( is_array( $theme_tokens[ $list_key ] ?? null ) ? $theme_tokens[ $list_key ] : [] as $token ) {
			if ( ! is_string( $token ) ) {
				continue;
			}

			if ( str_starts_with( $token, $preset_slug . ':' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function normalize_preset_type( string $value ): string {
		return str_replace( '-', '', sanitize_key( $value ) );
	}

	private static function display_preset_type( string $preset_type ): string {
		return match ( $preset_type ) {
			'fontsize' => 'font-size',
			'fontfamily' => 'font-family',
			default => sanitize_key( $preset_type ),
		};
	}

	private static function sanitize_path_segment( mixed $segment ): string {
		$segment = is_scalar( $segment ) ? trim( (string) $segment ) : '';

		if ( '' === $segment ) {
			return '';
		}

		$sanitized = preg_replace( '/[^A-Za-z0-9_:-]/', '', $segment );

		return is_string( $sanitized ) ? $sanitized : '';
	}

	/**
	 * @param array<string, mixed> $current_styles
	 * @param array<string, mixed> $variation_styles
	 */
	private static function describe_variation_diff( array $current_styles, array $variation_styles ): string {
		$diff_keys = [];
		$top_keys  = array_values(
			array_unique(
				array_merge(
					array_keys( $current_styles ),
					array_keys( $variation_styles )
				)
			)
		);

		foreach ( $top_keys as $top_key ) {
			$current_value   = $current_styles[ $top_key ] ?? null;
			$variation_value = $variation_styles[ $top_key ] ?? null;

			if ( wp_json_encode( $current_value ) === wp_json_encode( $variation_value ) ) {
				continue;
			}

			$diff_keys[] = sanitize_key( (string) $top_key );
		}

		return implode( ', ', array_slice( array_filter( $diff_keys ), 0, 4 ) );
	}
}
