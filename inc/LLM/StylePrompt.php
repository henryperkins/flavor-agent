<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\Support\FormatsDocsGuidance;
use FlavorAgent\Support\RankingContract;

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
	- When Design semantic context is provided, use it to fit the recommendation to the surface's job and nearby structure (for example, supporting footer text vs primary header or content surfaces).
	- Treat mixed or low-confidence design semantic context as a hint, not a fact. Do not over-commit to a single role when the provided occurrences vary.
	- Do not invent section roles, density, emphasis, or tone requirements when they are not evidenced by the provided design semantic context, viewport visibility constraints, or the user instruction.
	- When Viewport Visibility Constraints are provided, treat hidden-on-mobile or hidden-on-desktop blocks as conditional surfaces. Do not recommend styling them as the primary experience for viewports where they are hidden, and mention the constraint when it materially changes the advice.
	- Do not invent viewport visibility constraints when none are listed.
	- Prefer 1-4 suggestions.
	- Keep labels under 60 characters and descriptions under 180 characters.
SYSTEM;
	}

	public static function build_user( array $context, string $prompt = '', array $docs_guidance = [] ): string {
		$max_tokens          = (int) apply_filters( 'flavor_agent_prompt_budget_max_tokens', 0, 'style' );
		$budget              = new PromptBudget( $max_tokens );
		$scope               = is_array( $context['scope'] ?? null ) ? $context['scope'] : [];
		$style_context       = is_array( $context['styleContext'] ?? null ) ? $context['styleContext'] : [];
		$theme_tokens        = is_array( $style_context['themeTokens'] ?? null ) ? $style_context['themeTokens'] : [];
		$supported_paths     = is_array( $style_context['supportedStylePaths'] ?? null ) ? $style_context['supportedStylePaths'] : [];
		$style_book_target   = is_array( $style_context['styleBookTarget'] ?? null ) ? $style_context['styleBookTarget'] : [];
		$block_manifest      = is_array( $style_context['blockManifest'] ?? null ) ? $style_context['blockManifest'] : [];
		$template_structure  = is_array( $style_context['templateStructure'] ?? null ) ? $style_context['templateStructure'] : [];
		$design_semantics    = is_array( $style_context['designSemantics'] ?? null ) ? $style_context['designSemantics'] : [];
		$template_visibility = is_array( $style_context['templateVisibility'] ?? null ) ? $style_context['templateVisibility'] : [];
		$surface             = sanitize_key( (string) ( $scope['surface'] ?? 'global-styles' ) );

		$scope_lines = [
			'## Scope',
			'Surface: ' . ( $surface !== '' ? $surface : 'global-styles' ),
			'Scope key: ' . (string) ( $scope['scopeKey'] ?? '' ),
			'Global styles id: ' . (string) ( $scope['globalStylesId'] ?? '' ),
			'Post type: ' . (string) ( $scope['postType'] ?? '' ),
			'Entity id: ' . (string) ( $scope['entityId'] ?? '' ),
			'Entity kind: ' . (string) ( $scope['entityKind'] ?? '' ),
			'Entity name: ' . (string) ( $scope['entityName'] ?? '' ),
		];

		if ( 'style-book' === $surface ) {
			if ( ! empty( $scope['blockName'] ) ) {
				$scope_lines[] = 'Block name: ' . (string) $scope['blockName'];
			}

			if ( ! empty( $scope['blockTitle'] ) ) {
				$scope_lines[] = 'Block title: ' . (string) $scope['blockTitle'];
			}
		}

		if ( ! empty( $scope['stylesheet'] ) ) {
			$scope_lines[] = 'Stylesheet: ' . (string) $scope['stylesheet'];
		}

		if ( ! empty( $scope['templateSlug'] ) ) {
			$scope_lines[] = 'Template slug: ' . (string) $scope['templateSlug'];
		}

		if ( ! empty( $scope['templateType'] ) ) {
			$scope_lines[] = 'Template type: ' . (string) $scope['templateType'];
		}

		// Scope is the surface identity. Without it the model cannot disambiguate global-styles vs style-book.
		$budget->add_section( 'scope', implode( "\n", $scope_lines ), 100, true );

		$guidelines_context = \FlavorAgent\Guidelines::format_prompt_context(
			'style-book' === $surface ? (string) ( $scope['blockName'] ?? '' ) : ''
		);
		if ( '' !== $guidelines_context ) {
			$budget->add_section( 'site_guidelines', $guidelines_context, 88 );
		}

		// Current and merged config carry the live state the model must reason about; required so the response is grounded.
		$budget->add_section( 'current_config', "## Current Global Styles user config\n" . wp_json_encode( $style_context['currentConfig'] ?? [] ), 90, true );
		$budget->add_section( 'merged_config', "## Current merged style config\n" . wp_json_encode( $style_context['mergedConfig'] ?? [] ), 85 );

		if ( 'style-book' === $surface && [] !== $style_book_target ) {
			$target_lines = [ '## Style Book target' ];

			if ( ! empty( $style_book_target['blockName'] ) ) {
				$target_lines[] = 'Target block name: ' . (string) $style_book_target['blockName'];
			}

			if ( ! empty( $style_book_target['blockTitle'] ) ) {
				$target_lines[] = 'Target block title: ' . (string) $style_book_target['blockTitle'];
			}

			if ( ! empty( $style_book_target['description'] ) ) {
				$target_lines[] = 'Target description: ' . (string) $style_book_target['description'];
			}

			if ( ! empty( $style_book_target['currentStyles'] ) ) {
				$target_lines[] = 'Current target styles: ' . wp_json_encode( $style_book_target['currentStyles'] );
			}

			if ( ! empty( $style_book_target['mergedStyles'] ) ) {
				$target_lines[] = 'Merged target styles: ' . wp_json_encode( $style_book_target['mergedStyles'] );
			}

			$budget->add_section( 'style_book_target', implode( "\n", $target_lines ), 80 );
		}

		if ( 'style-book' === $surface && [] !== $block_manifest ) {
			$budget->add_section(
				'block_supports',
				"## Target block supports\nSupports: " . wp_json_encode( $block_manifest['supports'] ?? [] )
				. "\nInspector panels: " . wp_json_encode( $block_manifest['inspectorPanels'] ?? [] ),
				75
			);
		}

		if ( ! empty( $style_context['themeTokenDiagnostics'] ) ) {
			$budget->add_section( 'theme_token_diagnostics', "## Theme token diagnostics\n" . wp_json_encode( $style_context['themeTokenDiagnostics'] ), 60 );
		}

		if ( ! empty( $theme_tokens['diagnostics'] ) ) {
			$budget->add_section( 'server_theme_token_diagnostics', "## Server theme token diagnostics\n" . wp_json_encode( $theme_tokens['diagnostics'] ), 58 );
		}

		$theme_token_lines = [
			'## Theme tokens',
			'Colors: ' . implode( ', ', (array) ( $theme_tokens['colors'] ?? [] ) ),
			'Gradients: ' . implode( ', ', (array) ( $theme_tokens['gradients'] ?? [] ) ),
			'Font sizes: ' . implode( ', ', (array) ( $theme_tokens['fontSizes'] ?? [] ) ),
			'Font families: ' . implode( ', ', (array) ( $theme_tokens['fontFamilies'] ?? [] ) ),
			'Spacing: ' . implode( ', ', (array) ( $theme_tokens['spacing'] ?? [] ) ),
			'Shadows: ' . implode( ', ', (array) ( $theme_tokens['shadows'] ?? [] ) ),
			'Duotone presets: ' . implode( ', ', (array) ( $theme_tokens['duotone'] ?? [] ) ),
			'Duotone preset details: ' . wp_json_encode( $theme_tokens['duotonePresets'] ?? [] ),
			'Layout: ' . wp_json_encode( $theme_tokens['layout'] ?? [] ),
			'Enabled features: ' . wp_json_encode( $theme_tokens['enabledFeatures'] ?? [] ),
			'Element styles: ' . wp_json_encode( $theme_tokens['elementStyles'] ?? [] ),
			'Block pseudo-class styles: ' . wp_json_encode( $theme_tokens['blockPseudoStyles'] ?? [] ),
		];
		$budget->add_section( 'theme_tokens', implode( "\n", $theme_token_lines ), 30 );

		$supported_path_lines = [ '## Supported style paths' ];
		if ( 'style-book' === $surface ) {
			$supported_path_lines[] = 'These paths apply relative to styles.blocks.<target-block>.';
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

			$supported_path_lines[] = sprintf( '- %s (%s)', $path, $value_source );
		}
		// Supported style paths are the validator contract; without them the model has no enumerated allow-list and the server drops every operation.
		$budget->add_section( 'supported_style_paths', implode( "\n", $supported_path_lines ), 88, true );

		$variations = is_array( $style_context['availableVariations'] ?? null ) ? $style_context['availableVariations'] : [];
		if ( 'style-book' !== $surface && [] !== $variations ) {
			$variation_lines = [ '## Available theme style variations' ];

			if ( '' !== (string) ( $style_context['activeVariationTitle'] ?? '' ) ) {
				$variation_lines[] = sprintf(
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

				$variation_lines[] = $variation_summary;

				if ( ! empty( $variation['settings'] ) ) {
					$variation_lines[] = '  settings: ' . wp_json_encode( $variation['settings'] );
				}

				if ( ! empty( $variation['styles'] ) ) {
					$variation_lines[] = '  styles: ' . wp_json_encode( $variation['styles'] );
				}
			}

			$budget->add_section( 'available_variations', implode( "\n", $variation_lines ), 70 );
		}

		if ( [] !== $template_structure ) {
			$budget->add_section( 'template_structure', "## Current template structure\n" . wp_json_encode( $template_structure ), 55 );
		}

		if ( [] !== $design_semantics ) {
			$design_semantic_lines = self::format_design_semantics_summary( $design_semantics );

			if ( [] !== $design_semantic_lines ) {
				$budget->add_section( 'design_semantics', "## Design semantic context\n" . implode( "\n", $design_semantic_lines ), 50 );
			}
		}

		$budget->add_section( 'viewport_visibility', "## Viewport visibility constraints\n" . self::format_template_visibility_summary( $template_visibility ), 45 );

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
				$budget->add_section( 'docs_guidance', "## WordPress Developer Guidance\n" . implode( "\n", $guidance_lines ), 20 );
			}
		}

		// User instruction is the operator's intent; required so the model never recommends in the absence of a directive.
		$budget->add_section(
			'user_instruction',
			"## User instruction\n" . (
				'' !== trim( $prompt )
					? trim( $prompt )
					: (
						'style-book' === $surface
							? 'Recommend one or two safe Style Book improvements.'
							: 'Recommend one or two safe Global Styles improvements.'
					)
			),
			95,
			true
		);

		foreach ( self::get_few_shot_examples() as $index => $example ) {
			$budget->add_section( 'few_shot_' . $index, $example, 10 );
		}

		return $budget->assemble();
	}

	public static function get_few_shot_examples(): array {
		return [
			<<<'EXAMPLE'
## Example - global styles palette adjustment

Input context:
- Surface: global-styles
- Supported paths include `color.background`
- Theme variation `Midnight` is available

Expected response:
{"suggestions":[{"label":"Use the Midnight variation","description":"Switch to the darker preset variation before adding custom overrides.","category":"variation","tone":"executable","operations":[{"type":"set_theme_variation","variationIndex":1,"variationTitle":"Midnight"}]}],"explanation":"Prefer a theme-provided variation when it already matches the requested mood."}
EXAMPLE
			,
			<<<'EXAMPLE'
## Example - style book text emphasis

Input context:
- Surface: style-book
- Target block: `core/paragraph`
- Supported paths include `color.text`
- Theme palette includes `accent`

Expected response:
{"suggestions":[{"label":"Use the accent text preset","description":"Apply the theme accent color to the paragraph text for a stronger emphasis cue.","category":"color","tone":"executable","operations":[{"type":"set_block_styles","blockName":"core/paragraph","path":["color","text"],"value":"var:preset|color|accent","valueType":"preset","presetType":"color","presetSlug":"accent","cssVar":"var(--wp--preset--color--accent)"}]}],"explanation":"Keep the change inside supported block style paths and theme presets."}
EXAMPLE
			,
		];
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	private static function format_template_visibility_summary( array $summary ): string {
		$blocks = is_array( $summary['blocks'] ?? null ) ? $summary['blocks'] : [];

		if ( [] === $blocks ) {
			return 'None detected.';
		}

		$lines = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$label             = sanitize_text_field( (string) ( $block['label'] ?? ( $block['name'] ?? 'Block' ) ) );
			$name              = sanitize_text_field( (string) ( $block['name'] ?? '' ) );
			$path              = self::format_visibility_path( $block['path'] ?? null );
			$hidden_viewports  = array_values(
				array_filter(
					array_map(
						'sanitize_key',
						is_array( $block['hiddenViewports'] ?? null ) ? $block['hiddenViewports'] : []
					),
					static fn( string $viewport ): bool => '' !== $viewport
				)
			);
			$visible_viewports = array_values(
				array_filter(
					array_map(
						'sanitize_key',
						is_array( $block['visibleViewports'] ?? null ) ? $block['visibleViewports'] : []
					),
					static fn( string $viewport ): bool => '' !== $viewport
				)
			);
			$details           = [];

			if ( [] !== $hidden_viewports ) {
				$details[] = 'hidden on `' . implode( '`, `', $hidden_viewports ) . '`';
			}

			if ( [] !== $visible_viewports ) {
				$details[] = 'explicitly visible on `' . implode( '`, `', $visible_viewports ) . '`';
			}

			$line = '- ';

			if ( '' !== $path ) {
				$line .= "{$path} - ";
			}

			$line .= "`{$label}`";

			if ( '' !== $name && $name !== $label ) {
				$line .= " ({$name})";
			}

			if ( [] !== $details ) {
				$line .= ': ' . implode( '; ', $details );
			}

			$lines[] = $line;
		}

		return [] !== $lines ? implode( "\n", $lines ) : 'None detected.';
	}

	private static function format_visibility_path( mixed $path ): string {
		if ( ! is_array( $path ) || [] === $path ) {
			return '';
		}

		$segments = array_map(
			static fn( mixed $segment ): int => (int) $segment + 1,
			$path
		);

		return 'Path ' . implode( ' > ', $segments );
	}

	/**
	 * @param array<string, mixed> $design_semantics
	 * @return string[]
	 */
	private static function format_design_semantics_summary( array $design_semantics ): array {
		$surface = sanitize_key( (string) ( $design_semantics['surface'] ?? '' ) );

		if ( 'style-book' === $surface ) {
			return self::format_style_book_design_semantics( $design_semantics );
		}

		return self::format_global_style_design_semantics( $design_semantics );
	}

	/**
	 * @param array<string, mixed> $design_semantics
	 * @return string[]
	 */
	private static function format_global_style_design_semantics( array $design_semantics ): array {
		$lines = [];

		if ( ! empty( $design_semantics['overallDensityHint'] ) ) {
			$lines[] = 'Overall density hint: ' . sanitize_text_field( (string) $design_semantics['overallDensityHint'] );
		}

		if ( ! empty( $design_semantics['locationSummary'] ) ) {
			$lines[] = 'Location summary: ' . self::format_semantic_count_summary( $design_semantics['locationSummary'] );
		}

		if ( ! empty( $design_semantics['roleSummary'] ) ) {
			$lines[] = 'Role summary: ' . self::format_semantic_count_summary( $design_semantics['roleSummary'] );
		}

		$sections = is_array( $design_semantics['sections'] ?? null ) ? $design_semantics['sections'] : [];

		foreach ( array_slice( $sections, 0, 4 ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$details = [];

			if ( ! empty( $section['role'] ) ) {
				$details[] = 'role `' . sanitize_text_field( (string) $section['role'] ) . '`';
			}

			if ( ! empty( $section['location'] ) ) {
				$details[] = 'location `' . sanitize_text_field( (string) $section['location'] ) . '`';
			}

			if ( ! empty( $section['templatePartSlug'] ) ) {
				$details[] = 'template part `' . sanitize_text_field( (string) $section['templatePartSlug'] ) . '`';
			}

			if ( ! empty( $section['emphasisHint'] ) ) {
				$details[] = 'emphasis `' . sanitize_text_field( (string) $section['emphasisHint'] ) . '`';
			}

			if ( ! empty( $section['densityHint'] ) ) {
				$details[] = 'density `' . sanitize_text_field( (string) $section['densityHint'] ) . '`';
			}

			if ( ! empty( $section['childRoles'] ) && is_array( $section['childRoles'] ) ) {
				$details[] = 'child roles `' . implode(
					'`, `',
					array_map( 'sanitize_text_field', array_slice( $section['childRoles'], 0, 4 ) )
				) . '`';
			}

			$visibility = self::format_semantic_visibility_details( $section );

			if ( $visibility !== '' ) {
				$details[] = $visibility;
			}

			$lines[] = sprintf(
				'- %s - `%s`%s',
				self::format_visibility_path( $section['path'] ?? null ),
				sanitize_text_field( (string) ( $section['label'] ?? ( $section['block'] ?? 'Section' ) ) ),
				[] !== $details ? ': ' . implode( '; ', $details ) : ''
			);
		}

		return $lines;
	}

	/**
	 * @param array<string, mixed> $design_semantics
	 * @return string[]
	 */
	private static function format_style_book_design_semantics( array $design_semantics ): array {
		$lines = [];

		if ( isset( $design_semantics['occurrenceCount'] ) ) {
			$lines[] = 'Matching template occurrences: ' . max( 0, (int) $design_semantics['occurrenceCount'] );
		}

		if ( ! empty( $design_semantics['confidence'] ) ) {
			$lines[] = 'Semantic confidence: ' . sanitize_text_field( (string) $design_semantics['confidence'] );
		}

		if ( ! empty( $design_semantics['dominantRole'] ) ) {
			$lines[] = 'Dominant role hint: `' . sanitize_text_field( (string) $design_semantics['dominantRole'] ) . '`';
		}

		if ( ! empty( $design_semantics['dominantLocation'] ) ) {
			$lines[] = 'Dominant location hint: `' . sanitize_text_field( (string) $design_semantics['dominantLocation'] ) . '`';
		}

		if ( ! empty( $design_semantics['densitySummary'] ) ) {
			$lines[] = 'Density summary: ' . self::format_semantic_count_summary( $design_semantics['densitySummary'] );
		}

		if ( ! empty( $design_semantics['emphasisSummary'] ) ) {
			$lines[] = 'Emphasis summary: ' . self::format_semantic_count_summary( $design_semantics['emphasisSummary'] );
		}

		$occurrences = is_array( $design_semantics['occurrences'] ?? null ) ? $design_semantics['occurrences'] : [];

		foreach ( array_slice( $occurrences, 0, 3 ) as $occurrence ) {
			if ( ! is_array( $occurrence ) ) {
				continue;
			}

			$details = [];

			if ( ! empty( $occurrence['role'] ) ) {
				$details[] = 'role `' . sanitize_text_field( (string) $occurrence['role'] ) . '`';
			}

			if ( ! empty( $occurrence['location'] ) ) {
				$details[] = 'location `' . sanitize_text_field( (string) $occurrence['location'] ) . '`';
			}

			if ( ! empty( $occurrence['templatePartSlug'] ) ) {
				$details[] = 'template part `' . sanitize_text_field( (string) $occurrence['templatePartSlug'] ) . '`';
			}

			if ( ! empty( $occurrence['emphasisHint'] ) ) {
				$details[] = 'emphasis `' . sanitize_text_field( (string) $occurrence['emphasisHint'] ) . '`';
			}

			if ( ! empty( $occurrence['densityHint'] ) ) {
				$details[] = 'density `' . sanitize_text_field( (string) $occurrence['densityHint'] ) . '`';
			}

			$visibility = self::format_semantic_visibility_details( $occurrence );

			if ( $visibility !== '' ) {
				$details[] = $visibility;
			}

			$nearby = self::format_semantic_nearby_blocks( $occurrence['nearbyBlocks'] ?? [] );

			if ( $nearby !== '' ) {
				$details[] = $nearby;
			}

			$lines[] = sprintf(
				'- %s - `%s`%s',
				self::format_visibility_path( $occurrence['path'] ?? null ),
				sanitize_text_field( (string) ( $occurrence['label'] ?? ( $occurrence['block'] ?? 'Occurrence' ) ) ),
				[] !== $details ? ': ' . implode( '; ', $details ) : ''
			);
		}

		if ( ! empty( $design_semantics['omittedOccurrenceCount'] ) ) {
			$lines[] = sprintf(
				'- %d additional matching occurrences omitted from the summary.',
				max( 0, (int) $design_semantics['omittedOccurrenceCount'] )
			);
		}

		return $lines;
	}

	/**
	 * @param mixed $summary
	 */
	private static function format_semantic_count_summary( mixed $summary ): string {
		$parts = [];

		foreach ( is_array( $summary ) ? array_slice( $summary, 0, 4 ) : [] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) ( $entry['value'] ?? '' ) );
			$count = max( 0, (int) ( $entry['count'] ?? 0 ) );

			if ( $value === '' || $count === 0 ) {
				continue;
			}

			$parts[] = sprintf( '`%s` (%d)', $value, $count );
		}

		return [] !== $parts ? implode( ', ', $parts ) : 'None detected.';
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function format_semantic_visibility_details( array $entry ): string {
		$details = [];
		$hidden  = is_array( $entry['hiddenViewports'] ?? null ) ? $entry['hiddenViewports'] : [];
		$visible = is_array( $entry['visibleViewports'] ?? null ) ? $entry['visibleViewports'] : [];

		if ( [] !== $hidden ) {
			$details[] = 'hidden on `' . implode(
				'`, `',
				array_map( 'sanitize_text_field', $hidden )
			) . '`';
		}

		if ( [] !== $visible ) {
			$details[] = 'visible on `' . implode(
				'`, `',
				array_map( 'sanitize_text_field', $visible )
			) . '`';
		}

		return implode( '; ', $details );
	}

	/**
	 * @param mixed $nearby_blocks
	 */
	private static function format_semantic_nearby_blocks( mixed $nearby_blocks ): string {
		$nearby_blocks = is_array( $nearby_blocks ) ? $nearby_blocks : [];
		$parts         = [];

		foreach ( [ 'before', 'after' ] as $direction ) {
			$labels = [];

			foreach ( is_array( $nearby_blocks[ $direction ] ?? null ) ? $nearby_blocks[ $direction ] : [] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$labels[] = sanitize_text_field(
					(string) ( $entry['label'] ?? ( $entry['block'] ?? '' ) )
				);
			}

			$labels = array_values( array_filter( $labels ) );

			if ( [] === $labels ) {
				continue;
			}

			$parts[] = $direction . ' `' . implode( '`, `', array_slice( $labels, 0, 2 ) ) . '`';
		}

		return implode( '; ', $parts );
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
		$order     = 0;

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

			$entry = [
				'label'       => sanitize_text_field( (string) ( $suggestion['label'] ?? '' ) ),
				'description' => sanitize_text_field( (string) ( $suggestion['description'] ?? '' ) ),
				'category'    => sanitize_key( (string) ( $suggestion['category'] ?? 'advisory' ) ),
				'tone'        => $tone,
				'operations'  => 'executable' === $tone ? $operations : [],
			];

			$ranking_input  = is_array( $suggestion['ranking'] ?? null ) ? $suggestion['ranking'] : [];
			$computed_score = RankingContract::resolve_score_candidate(
				$ranking_input['score'] ?? null,
				$suggestion['score'] ?? null,
				$ranking_input['confidence'] ?? null,
				$suggestion['confidence'] ?? null
			);
			if ( null === $computed_score ) {
				$computed_score = RankingContract::derive_score(
					0.45,
					[
						'is_executable'   => 'executable' === $tone ? 0.25 : 0.0,
						'has_operations'  => [] !== $operations ? 0.15 : 0.0,
						'has_description' => '' !== $entry['description'] ? 0.1 : 0.0,
						'has_category'    => '' !== $entry['category'] ? 0.05 : 0.0,
					]
				);
			}
			$source_signals = [ 'llm_response', 'style_surface', 'tone_' . $tone ];

			if ( [] !== $operations ) {
				$source_signals[] = 'has_operations';
			}

			if ( array_key_exists( 'ranking', $suggestion ) || isset( $suggestion['confidence'] ) || isset( $suggestion['score'] ) ) {
				$entry['ranking'] = RankingContract::normalize(
					$ranking_input,
					[
						'score'         => $computed_score,
						'reason'        => (string) ( $suggestion['description'] ?? '' ),
						'sourceSignals' => $source_signals,
						'safetyMode'    => 'validated',
						'freshnessMeta' => [
							'source'  => 'llm',
							'surface' => 'style',
						],
						'operations'    => 'executable' === $tone ? $operations : [],
					]
				);
			}

			$entry['_rankScore'] = $computed_score;
			$entry['_rankOrder'] = $order++;
			$validated[]         = $entry;
		}

		$filtered = array_values(
			array_filter(
				$validated,
				static fn( array $suggestion ): bool => '' !== $suggestion['label']
			)
		);

		usort(
			$filtered,
			static function ( array $left, array $right ): int {
				$score_compare = (float) ( $right['_rankScore'] ?? 0.0 ) <=> (float) ( $left['_rankScore'] ?? 0.0 );

				if ( 0 !== $score_compare ) {
					return $score_compare;
				}

				return (int) ( $left['_rankOrder'] ?? 0 ) <=> (int) ( $right['_rankOrder'] ?? 0 );
			}
		);

		return array_map(
			static function ( array $suggestion ): array {
				unset( $suggestion['_rankOrder'] );
				unset( $suggestion['_rankScore'] );
				return $suggestion;
			},
			$filtered
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
