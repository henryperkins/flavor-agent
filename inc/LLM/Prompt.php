<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\Support\FormatsDocsGuidance;
use FlavorAgent\Support\RankingContract;

final class Prompt {

	use FormatsDocsGuidance;

	/**
	 * Build the system prompt that instructs the LLM how to respond.
	 */
	public static function build_system(): string {
		return <<<'SYSTEM'
You are a WordPress Gutenberg block styling and configuration assistant.

You receive a block's current state, its available Inspector panels (what it supports), its resolved structural identity and surrounding branch context, and the active theme's design tokens (colors, duotone presets, fonts, spacing, shadows, feature flags, and global element styles).

Your job: suggest specific, actionable attribute changes that improve the block's appearance and configuration. Every suggestion must use the theme's actual preset slugs and CSS custom properties — never raw hex codes or pixel values unless the theme has no presets.

Respond with a JSON object (no markdown fences, no explanation outside the JSON):

{
  "settings": [],
  "styles": [],
  "block": [],
  "explanation": "One sentence summary of your recommendations."
}

Each item in settings/styles is an object:
{
  "label": "Human-readable name (e.g. 'Use theme accent background')",
  "description": "Why this helps (one sentence)",
	"panel": "Which Inspector panel: general|layout|position|advanced|bindings|list|color|filter|typography|dimensions|border|shadow|background",
	"type": "Optional: attribute_change|style_variation",
	"attributeUpdates": { "attributeName": "value" },
  "currentValue": "Optional: current value for before/after display",
  "suggestedValue": "Optional: suggested value for before/after display",
  "isCurrentStyle": "Optional boolean for style variation items",
  "isRecommended": "Optional boolean for style variation items",
  "confidence": 0.0-1.0,
  "preview": "Optional: hex color for visual preview swatch",
  "presetSlug": "Optional: theme preset slug being used",
  "cssVar": "Optional: var(--wp--preset--color--accent)"
}

Each item in block is an object:
{
  "label": "Human-readable name",
  "description": "Why this helps (one sentence)",
	"type": "Optional: attribute_change|style_variation|structural_recommendation|pattern_replacement",
	"attributeUpdates": { "attributeName": "value" },
	"panel": "Optional for executable block items when helpful; omit it for advisory structural/pattern ideas",
  "currentValue": "Optional: current value for before/after display",
  "suggestedValue": "Optional: suggested value for before/after display",
  "isCurrentStyle": "Optional boolean for style variation items",
  "isRecommended": "Optional boolean for style variation items",
  "confidence": 0.0-1.0,
  "preview": "Optional: hex color for visual preview swatch",
  "presetSlug": "Optional: theme preset slug being used",
  "cssVar": "Optional: var(--wp--preset--color--accent)"
}

Rules:
- "settings" array: suggestions for the Settings tab (layout, alignment, position, advanced, bindings, list, general config).
- "styles" array: suggestions for the Appearance tab (color, filter, typography, dimensions, border, shadow, background, style variations).
- "block" array: block-level suggestions (style variation changes, structural recommendations).
- Only suggest changes for panels listed in the block's inspectorPanels.
- If inspectorPanels is an empty object or array, treat that as an explicit signal that no mapped Inspector panels are available — not that the field was omitted. Do not say the panels were not provided.
- You may still suggest a registered style variation as a block-level "style_variation" item when styles are provided and one is clearly beneficial, even if inspectorPanels is empty.
- When a block has little or no direct Inspector surface, you may use the block array for advisory suggestions about parent containers, structural composition, or replacing the block with a more suitable pattern.
- Structural_recommendation and pattern_replacement block items are advisory-only. Omit panel for those items unless it materially clarifies the idea, and do not include attributeUpdates.
- Advisory block suggestions must not invent executable attributeUpdates for ancestor blocks, wrappers, or replacement patterns. Only include attributeUpdates when the selected block's own local attributes can be changed safely.
- If you need to suggest both a local selected-block mutation and a broader structural idea, emit two separate suggestions instead of combining them into one item.
- Use "list" for List View tab suggestions.
- When bindableAttributes are provided, only suggest metadata.bindings changes for those attribute names.
- Only suggest preset values that exist in the provided themeTokens.
- When WordPress Developer Guidance is provided, prefer recommendations that align with that guidance and avoid contradicting documented Gutenberg capabilities or theme.json standards.
- When structural identity is provided, treat it as the block's job on this page. Distinguish role and location from raw block name alone (for example, header navigation vs footer navigation, main query vs sidebar query).
- When parent container context shows a dark or high-overlay container (for example, high dimRatio or a dark/contrast background preset), prefer light/contrast text colors and ensure sufficient contrast.
- When the parent container uses a constrained layout, respect its width constraints in dimension suggestions.
- Use sibling context to maintain visual consistency - if surrounding blocks use a particular alignment or color scheme, prefer suggestions that harmonize rather than clash.
- Use structural ancestors and the structural branch to infer section role and composition (header, footer, hero, article body, sidebar) when deciding whether a suggestion fits the selected block's neighborhood.
- If the block already has good values, return fewer or no suggestions.
- Return 2-6 suggestions total. Prioritize high-impact visual improvements.
- If the block is inside a contentOnly container, only suggest changes to content attributes (role=content). Do not suggest style or settings changes — those panels are locked.
- If a block only exposes content through supports.contentRole inner blocks and has no direct content attributes, do not suggest direct wrapper attribute changes.
- You may suggest viewport visibility rules: { "metadata": { "blockVisibility": { "viewport": { "mobile": false } } } } to show/hide the block on specific devices.
- If theme pseudo-class styles (:hover, :focus, :active, :focus-visible) are provided for a block, use them when suggesting interactive state styles.
- Treat themeTokens.enabledFeatures and themeTokens.layout as hard capability constraints. Avoid suggesting controls the theme has disabled or locked.
- For style objects in attributeUpdates, use the nested style format:
  { "style": { "color": { "background": "var(--wp--preset--color--accent)" } } }
  or preset attributes like { "backgroundColor": "accent" }.
- For duotone, use style.color.duotone. Preset references must use the canonical format "var:preset|duotone|{slug}".
- When a block supports both aspect ratio and explicit height, never suggest setting both in the same recommendation. Choose aspectRatio or height/minHeight, not both.
- Preserve Gutenberg attribute key casing exactly in attributeUpdates (for example, backgroundColor and metadata.blockVisibility).
- If suggesting a registered style variation, use "type": "style_variation" and include the exact attributeUpdates needed to activate it.
SYSTEM;
	}

	/**
	 * Build the user prompt from editor context.
	 */
	public static function build_user( array $context, string $prompt = '', array $docs_guidance = [] ): string {
		$block  = $context['block'] ?? [];
		$tokens = $context['themeTokens'] ?? [];

			$parts = [];

			$parts[] = '## Block';
			$parts[] = 'Name: ' . ( $block['name'] ?? 'unknown' );
			$parts[] = 'Title: ' . ( $block['title'] ?? '' );

		if ( array_key_exists( 'inspectorPanels', $block ) ) {
			$parts[] = 'Available panels: ' . wp_json_encode(
				is_array( $block['inspectorPanels'] ) ? $block['inspectorPanels'] : []
			);
		}

		if ( ! empty( $block['currentAttributes'] ) ) {
			$parts[] = 'Current attributes: ' . wp_json_encode( $block['currentAttributes'] );
		}

		if ( ! empty( $block['currentAttributes']['metadata']['bindings'] ) ) {
			$parts[] = 'Block bindings: ' . wp_json_encode( $block['currentAttributes']['metadata']['bindings'] );
		}

		$bindable_attributes = array_values(
			array_filter(
				array_map(
					static fn( mixed $attribute ): string => is_string( $attribute )
						? ( self::sanitize_attribute_update_key( $attribute ) ?? '' )
						: '',
					(array) ( $block['bindableAttributes'] ?? [] )
				),
				static fn( string $attribute ): bool => $attribute !== ''
			)
		);

		if ( ! empty( $bindable_attributes ) ) {
			$parts[] = 'Bindable attributes: ' . wp_json_encode( $bindable_attributes );
		}

		if ( ! empty( $block['supportsContentRole'] ) ) {
			$parts[] = 'Block declares supports.contentRole: true';
		}

		if ( ! empty( $block['styles'] ) ) {
			$parts[] = 'Style variations: ' . wp_json_encode( $block['styles'] );
		}

		if ( ! empty( $block['variations'] ) ) {
			$parts[] = 'Block variations: ' . wp_json_encode( $block['variations'] );
		}

		if ( ! empty( $block['activeStyle'] ) ) {
			$parts[] = 'Active style: ' . $block['activeStyle'];
		}

		if ( ! empty( $block['configAttributes'] ) ) {
			$parts[] = 'Config attribute schema: ' . wp_json_encode( $block['configAttributes'] );
		}

		if ( ! empty( $block['childCount'] ) ) {
			$parts[] = 'Child blocks: ' . (int) $block['childCount'];
		}

			$structural_identity = is_array( $block['structuralIdentity'] ?? null ) ? $block['structuralIdentity'] : [];
		if ( ! empty( $structural_identity ) ) {
			$parts[] = '';
			$parts[] = '## Structural identity';

			if ( ! empty( $structural_identity['role'] ) ) {
				$parts[] = 'Resolved role: ' . $structural_identity['role'];
			}

			if ( ! empty( $structural_identity['job'] ) ) {
				$parts[] = 'Resolved job: ' . $structural_identity['job'];
			}

			if ( ! empty( $structural_identity['location'] ) ) {
				$parts[] = 'Page location: ' . $structural_identity['location'];
			}

			if ( ! empty( $structural_identity['templateArea'] ) ) {
				$parts[] = 'Template area: ' . $structural_identity['templateArea'];
			}

			if ( ! empty( $structural_identity['templatePartSlug'] ) ) {
				$parts[] = 'Template part slug: ' . $structural_identity['templatePartSlug'];
			}

			if ( ! empty( $structural_identity['position'] ) ) {
				$parts[] = 'Position: ' . wp_json_encode( $structural_identity['position'] );
			}

			if ( ! empty( $structural_identity['evidence'] ) ) {
				$parts[] = 'Evidence: ' . wp_json_encode( $structural_identity['evidence'] );
			}
		}

		if ( ! empty( $block['editingMode'] ) && $block['editingMode'] !== 'default' ) {
			$parts[] = 'Editing mode: ' . $block['editingMode'];
		}

		$restrictions = self::get_block_restrictions( $block );

		if ( $restrictions['contentOnly'] ) {
			$parts[] = '';
			$parts[] = '## Content-only restrictions';
			$parts[] = ! empty( $block['isInsideContentOnly'] )
				? 'This block is inside a contentOnly container.'
				: 'This block is in contentOnly editing mode.';
			$parts[] = 'Only content attributes (role=content) can be edited. Do not suggest style or settings panel changes.';

			if (
				! empty( $block['supportsContentRole'] )
				&& empty( $block['contentAttributes'] )
			) {
				$parts[] = 'This block exposes editable content through inner blocks via supports.contentRole and has no direct content attributes on its wrapper. Do not suggest direct wrapper attribute changes.';
			}
		}

		if ( array_key_exists( 'blockVisibility', $block ) && null !== $block['blockVisibility'] ) {
			$parts[] = 'Block visibility: ' . wp_json_encode( $block['blockVisibility'] );
		}

		$parts[] = '';
		$parts[] = '## Theme Tokens';

		if ( ! empty( $tokens['colors'] ) ) {
			$parts[] = 'Colors: ' . implode( ', ', array_slice( (array) $tokens['colors'], 0, 60 ) );
		}

		if ( ! empty( $tokens['colorPresets'] ) ) {
			$parts[] = 'Color preset details: ' . wp_json_encode( array_slice( (array) $tokens['colorPresets'], 0, 60 ) );
		}

		if ( ! empty( $tokens['gradients'] ) ) {
			$parts[] = 'Gradients: ' . implode( ', ', array_slice( (array) $tokens['gradients'], 0, 60 ) );
		}

		if ( ! empty( $tokens['gradientPresets'] ) ) {
			$parts[] = 'Gradient preset details: ' . wp_json_encode( array_slice( (array) $tokens['gradientPresets'], 0, 60 ) );
		}

		if ( ! empty( $tokens['fontSizes'] ) ) {
			$parts[] = 'Font sizes: ' . implode( ', ', array_slice( (array) $tokens['fontSizes'], 0, 60 ) );
		}

		if ( ! empty( $tokens['fontSizePresets'] ) ) {
			$parts[] = 'Font size preset details: ' . wp_json_encode( array_slice( (array) $tokens['fontSizePresets'], 0, 60 ) );
		}

		if ( ! empty( $tokens['fontFamilies'] ) ) {
			$parts[] = 'Font families: ' . implode( ', ', array_slice( (array) $tokens['fontFamilies'], 0, 60 ) );
		}

		if ( ! empty( $tokens['fontFamilyPresets'] ) ) {
			$parts[] = 'Font family preset details: ' . wp_json_encode( array_slice( (array) $tokens['fontFamilyPresets'], 0, 60 ) );
		}

		if ( ! empty( $tokens['spacing'] ) ) {
			$parts[] = 'Spacing: ' . implode( ', ', array_slice( (array) $tokens['spacing'], 0, 60 ) );
		}

		if ( ! empty( $tokens['spacingPresets'] ) ) {
			$parts[] = 'Spacing preset details: ' . wp_json_encode( array_slice( (array) $tokens['spacingPresets'], 0, 60 ) );
		}

		if ( ! empty( $tokens['shadows'] ) ) {
			$parts[] = 'Shadows: ' . implode( ', ', array_slice( (array) $tokens['shadows'], 0, 60 ) );
		}

		if ( ! empty( $tokens['shadowPresets'] ) ) {
			$parts[] = 'Shadow preset details: ' . wp_json_encode( array_slice( (array) $tokens['shadowPresets'], 0, 60 ) );
		}

		if ( ! empty( $tokens['duotone'] ) ) {
			$parts[] = 'Duotone presets: ' . implode( ', ', (array) $tokens['duotone'] );
		}

		if ( ! empty( $tokens['duotonePresets'] ) ) {
			$parts[] = 'Duotone preset details: ' . wp_json_encode( $tokens['duotonePresets'] );
		}

		if ( ! empty( $tokens['diagnostics'] ) ) {
			$parts[] = 'Theme token diagnostics: ' . wp_json_encode( $tokens['diagnostics'] );
		}

		if ( ! empty( $tokens['layout'] ) ) {
			$parts[] = 'Layout: ' . wp_json_encode( $tokens['layout'] );
		}

		if ( ! empty( $tokens['enabledFeatures'] ) ) {
			$parts[] = 'Theme feature flags: ' . wp_json_encode( $tokens['enabledFeatures'] );
		}

		if ( ! empty( $tokens['elementStyles'] ) ) {
			$parts[] = 'Global element styles: ' . wp_json_encode( $tokens['elementStyles'] );
		}

		if ( ! empty( $tokens['blockPseudoStyles'] ) ) {
			$parts[] = 'Block pseudo-class styles (hover/focus/active): ' . wp_json_encode( $tokens['blockPseudoStyles'] );
		}

		$has_sibling_summaries = ! empty( $context['siblingSummariesBefore'] ) || ! empty( $context['siblingSummariesAfter'] );
		if ( $has_sibling_summaries || ! empty( $context['siblingsBefore'] ) || ! empty( $context['siblingsAfter'] ) ) {
			$parts[] = '';
			$parts[] = '## Surrounding blocks';

			$before_summaries = self::format_sibling_summaries(
				is_array( $context['siblingSummariesBefore'] ?? null ) ? $context['siblingSummariesBefore'] : [],
				'before'
			);
			$after_summaries  = self::format_sibling_summaries(
				is_array( $context['siblingSummariesAfter'] ?? null ) ? $context['siblingSummariesAfter'] : [],
				'after'
			);

			if ( '' !== $before_summaries ) {
				$parts[] = $before_summaries;
			} elseif ( ! empty( $context['siblingsBefore'] ) ) {
				$parts[] = 'Before: ' . implode( ', ', (array) $context['siblingsBefore'] );
			}

			if ( '' !== $after_summaries ) {
				$parts[] = $after_summaries;
			} elseif ( ! empty( $context['siblingsAfter'] ) ) {
				$parts[] = 'After: ' . implode( ', ', (array) $context['siblingsAfter'] );
			}
		}

		$parent_context = self::format_parent_context(
			is_array( $context['parentContext'] ?? null ) ? $context['parentContext'] : null
		);
		if ( '' !== $parent_context ) {
			$parts[] = '';
			$parts[] = '## Parent container';
			$parts[] = $parent_context;
		}

		$structural_ancestors = self::format_structural_ancestors(
			is_array( $context['structuralAncestors'] ?? null ) ? $context['structuralAncestors'] : [],
			is_array( $block['structuralIdentity'] ?? null ) ? $block['structuralIdentity'] : []
		);
		if ( '' !== $structural_ancestors ) {
			$parts[] = '';
			$parts[] = '## Structural ancestors';
			$parts[] = $structural_ancestors;
		}

		$structural_branch = self::format_structural_branch(
			is_array( $context['structuralBranch'] ?? null ) ? $context['structuralBranch'] : []
		);
		if ( '' !== $structural_branch ) {
			$parts[] = '';
			$parts[] = '## Structural branch';
			$parts[] = $structural_branch;
		}

		if ( ! empty( $docs_guidance ) ) {
			$parts[] = '';
			$parts[] = '## WordPress Developer Guidance';

			foreach ( array_slice( $docs_guidance, 0, 3 ) as $guidance ) {
				if ( ! is_array( $guidance ) ) {
					continue;
				}

				$summary = self::format_guidance_line( $guidance );

				if ( $summary !== '' ) {
					$parts[] = '- ' . $summary;
				}
			}
		}

		if ( ! empty( $prompt ) ) {
			$parts[] = '';
			$parts[] = '## User instruction';
			$parts[] = $prompt;
		}

		return implode( "\n", $parts );
	}

	private static function format_structural_ancestors( array $ancestors, array $selected_identity = [] ): string {
		if ( empty( $ancestors ) ) {
			return '';
		}

		$chain = array_values(
			array_filter(
				array_map(
					static fn( array $ancestor ): string => self::format_structural_label( $ancestor ),
					$ancestors
				),
				static fn( string $label ): bool => $label !== ''
			)
		);

		if ( empty( $chain ) ) {
			return '';
		}

		$parts = [ implode( ' > ', $chain ) ];

		if ( isset( $selected_identity['position']['depth'] ) ) {
			$depth_sentence = 'Selected block is ' . (int) $selected_identity['position']['depth'] . ' levels deep';

			if ( ! empty( $selected_identity['templateArea'] ) ) {
				$depth_sentence .= ' in the ' . sanitize_text_field( (string) $selected_identity['templateArea'] ) . ' template area';
			} elseif ( ! empty( $selected_identity['location'] ) ) {
				$depth_sentence .= ' in the ' . sanitize_text_field( (string) $selected_identity['location'] ) . ' area';
			}

			$parts[] = rtrim( $depth_sentence, '.' ) . '.';
		}

		return implode( "\n\n", array_filter( $parts ) );
	}

	private static function format_structural_branch( array $branch ): string {
		if ( empty( $branch ) ) {
			return '';
		}

		$lines = [];
		foreach ( $branch as $node ) {
			self::render_structural_branch_node( $node, 0, $lines );
		}

		return implode( "\n", $lines );
	}

	private static function render_structural_branch_node( array $node, int $depth, array &$lines ): void {
		$indent = str_repeat( '  ', $depth );
		$label  = self::format_structural_label( $node );

		if ( $label === '' ) {
			$label = '(unknown block)';
		}

		if ( ! empty( $node['childCount'] ) ) {
			$label .= ' (' . (int) $node['childCount'] . ' children)';
		}

		if ( ! empty( $node['isSelected'] ) ) {
			$label .= ' <- selected';
		}

		$lines[] = $indent . $label;

		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				self::render_structural_branch_node( $child, $depth + 1, $lines );
			}
		}

		if ( ! empty( $node['moreChildren'] ) ) {
			$lines[] = $indent . '  ... +' . (int) $node['moreChildren'] . ' more children not shown';
		}
	}

	private static function format_parent_context( ?array $parent_context ): string {
		if ( empty( $parent_context ) ) {
			return '';
		}

		$label = self::format_structural_label( $parent_context );
		if ( '' === $label ) {
			return '';
		}

		$parts = [ $label ];

		$visual = self::format_visual_hints( $parent_context['visualHints'] ?? [], true );
		if ( $visual !== '' ) {
			$parts[] = '[' . $visual . ']';
		}

		if ( ! empty( $parent_context['childCount'] ) ) {
			$parts[] = '(' . (int) $parent_context['childCount'] . ' children)';
		}

		return implode( ' ', array_filter( $parts ) );
	}

	private static function format_visual_hints( array $visual_hints, bool $include_parent_extensions = false ): string {
		if ( empty( $visual_hints ) ) {
			return '';
		}

		$parts = [];

		$bg = $visual_hints['style']['color']['background'] ?? $visual_hints['backgroundColor'] ?? null;
		if ( $bg ) {
			$parts[] = 'bg:' . sanitize_text_field( (string) $bg );
		}

		$text = $visual_hints['style']['color']['text'] ?? $visual_hints['textColor'] ?? null;
		if ( $text ) {
			$parts[] = 'text:' . sanitize_text_field( (string) $text );
		}

		if ( ! empty( $visual_hints['gradient'] ) ) {
			$parts[] = 'gradient:' . sanitize_text_field( (string) $visual_hints['gradient'] );
		}

		if ( ! empty( $visual_hints['align'] ) ) {
			$parts[] = 'align:' . sanitize_text_field( (string) $visual_hints['align'] );
		}

		if ( ! empty( $visual_hints['textAlign'] ) ) {
			$parts[] = 'textAlign:' . sanitize_text_field( (string) $visual_hints['textAlign'] );
		}

		$layout = $visual_hints['layout'] ?? [];
		if ( ! empty( $layout['type'] ) ) {
			$parts[] = 'layout:' . sanitize_text_field( (string) $layout['type'] );
		}

		if ( ! empty( $layout['justifyContent'] ) ) {
			$parts[] = 'justify:' . sanitize_text_field( (string) $layout['justifyContent'] );
		}

		if ( $include_parent_extensions ) {
			if ( isset( $visual_hints['dimRatio'] ) ) {
				$dim     = $visual_hints['dimRatio'];
				$parts[] = 'overlay:' . ( is_numeric( $dim ) ? ( (float) $dim ) . '%' : sanitize_text_field( (string) $dim ) );
			}

			if ( isset( $visual_hints['minHeight'] ) ) {
				$height  = (string) $visual_hints['minHeight'];
				$unit    = isset( $visual_hints['minHeightUnit'] ) ? (string) $visual_hints['minHeightUnit'] : '';
				$parts[] = 'minHeight:' . sanitize_text_field( $height . $unit );
			}

			if ( ! empty( $visual_hints['tagName'] ) ) {
				$parts[] = 'tag:' . sanitize_text_field( (string) $visual_hints['tagName'] );
			}
		}

		return implode( ', ', array_filter( $parts ) );
	}

	private static function format_sibling_summaries( array $summaries, string $direction ): string {
		if ( empty( $summaries ) ) {
			return '';
		}

		$lines = [ ucfirst( $direction ) . ':' ];

		foreach ( $summaries as $summary ) {
			if ( empty( $summary['block'] ) ) {
				continue;
			}

			$label = self::get_block_key( $summary['block'] );
			$meta  = [];

			if ( ! empty( $summary['role'] ) ) {
				$meta[] = sanitize_text_field( (string) $summary['role'] );
			}

			$visual = self::format_visual_hints( $summary['visualHints'] ?? [] );
			if ( $visual !== '' ) {
				$meta[] = $visual;
			}

			if ( ! empty( $meta ) ) {
				$label .= ' (' . implode( '; ', $meta ) . ')';
			}

			$lines[] = '  - ' . $label;
		}

		return implode( "\n", array_filter( $lines ) );
	}

	private static function format_structural_label( array $node ): string {
		if ( empty( $node['block'] ) ) {
			return '';
		}

		$label = self::get_block_key( $node['block'] );

		if ( ! empty( $node['templateArea'] ) ) {
			$label .= '(' . sanitize_text_field( (string) $node['templateArea'] ) . ')';
		} elseif ( ! empty( $node['templatePartSlug'] ) ) {
			$label .= '(' . sanitize_text_field( (string) $node['templatePartSlug'] ) . ')';
		}

		if ( ! empty( $node['role'] ) ) {
			$label .= ' (' . sanitize_text_field( (string) $node['role'] ) . ')';
		}

		if ( ! empty( $node['job'] ) ) {
			$label .= ' "' . sanitize_text_field( (string) $node['job'] ) . '"';
		}

		return $label;
	}

	private static function get_block_key( mixed $block_name ): string {
		if ( ! is_string( $block_name ) || '' === $block_name ) {
			return '';
		}

		$normalized = sanitize_text_field( $block_name );

		if ( str_contains( $normalized, '/' ) ) {
			$parts = explode( '/', $normalized );
			$short = end( $parts );

			return is_string( $short ) && '' !== $short ? $short : $normalized;
		}

		return $normalized;
	}

	/**
	 * Parse the LLM's JSON response into the expected payload shape.
	 */
	public static function parse_response( string $raw ): array|\WP_Error {
		$cleaned = preg_replace( '/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim( $raw ) );

		$data = json_decode( $cleaned, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'parse_error',
				'Failed to parse LLM response as JSON: ' . json_last_error_msg(),
				[
					'status' => 500,
					'raw'    => substr( $raw, 0, 500 ),
				]
			);
		}

		return [
			'settings'    => self::validate_suggestions( $data['settings'] ?? [], 'settings' ),
			'styles'      => self::validate_suggestions( $data['styles'] ?? [], 'styles' ),
			'block'       => self::validate_suggestions( $data['block'] ?? [], 'block' ),
			'explanation' => sanitize_text_field( $data['explanation'] ?? '' ),
		];
	}

	public static function enforce_block_context_rules( array $payload, array $block ): array {
		$restrictions = self::get_block_restrictions( $block );

		if ( $restrictions['disabled'] ) {
			return [
				'settings'    => [],
				'styles'      => [],
				'block'       => [],
				'explanation' => '',
			];
		}

		$payload = self::normalize_block_payload_for_execution( $payload );
		$payload = self::filter_payload_for_bindable_attributes( $payload, $block );

		if ( ! $restrictions['contentOnly'] ) {
			return $payload;
		}

		$content_attribute_keys = array_keys( $block['contentAttributes'] ?? [] );

		if ( self::uses_inner_blocks_as_content( $block ) ) {
			return [
				'settings'    => [],
				'styles'      => [],
				'block'       => array_values(
					array_filter(
						$payload['block'] ?? [],
						fn( array $suggestion ): bool => self::is_advisory_only_block_type( $suggestion['type'] ?? null )
					)
				),
				'explanation' => $payload['explanation'] ?? '',
			];
		}

		return [
			'settings'    => [],
			'styles'      => [],
			'block'       => array_values(
				array_filter(
					array_map(
						fn( array $suggestion ) => self::filter_suggestion_for_content_only( $suggestion, $content_attribute_keys ),
						$payload['block'] ?? []
					)
				)
			),
			'explanation' => $payload['explanation'] ?? '',
		];
	}

	private static function filter_payload_for_bindable_attributes( array $payload, array $block ): array {
		$bindable_attribute_keys = self::get_bindable_attribute_keys( $block );

		if ( null === $bindable_attribute_keys ) {
			return $payload;
		}

		return [
			'settings'    => self::filter_suggestion_group_for_bindable_attributes(
				$payload['settings'] ?? [],
				$bindable_attribute_keys
			),
			'styles'      => self::filter_suggestion_group_for_bindable_attributes(
				$payload['styles'] ?? [],
				$bindable_attribute_keys
			),
			'block'       => self::filter_suggestion_group_for_bindable_attributes(
				$payload['block'] ?? [],
				$bindable_attribute_keys
			),
			'explanation' => $payload['explanation'] ?? '',
		];
	}

	/**
	 * @param string[] $bindable_attribute_keys
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_suggestion_group_for_bindable_attributes( array $suggestions, array $bindable_attribute_keys ): array {
		return array_values(
			array_filter(
				array_map(
					fn( array $suggestion ): ?array => self::filter_suggestion_for_bindable_attributes(
						$suggestion,
						$bindable_attribute_keys
					),
					$suggestions
				)
			)
		);
	}

	private static function validate_suggestions( array $suggestions, string $group ): array {
		$valid = [];
		$order = 0;
		foreach ( $suggestions as $s ) {
			if ( ! is_array( $s ) || empty( $s['label'] ) ) {
				continue;
			}

			$type                   = isset( $s['type'] ) ? sanitize_key( $s['type'] ) : null;
			$has_executable_updates = is_array( $s['attributeUpdates'] ?? null ) && [] !== $s['attributeUpdates'];
			$attribute_updates      = self::sanitize_attribute_updates( $s['attributeUpdates'] ?? [] );
			$is_advisory_block_type = 'block' === $group && self::is_advisory_only_block_type( $type );

			if ( $is_advisory_block_type ) {
				$has_executable_updates = false;
				$attribute_updates      = [];
			} else {
				$attribute_updates = self::filter_unsafe_attribute_updates( $attribute_updates )['value'];
			}

			if ( $has_executable_updates && ( ! is_array( $attribute_updates ) || [] === $attribute_updates ) ) {
				continue;
			}

			$normalized = [
				'label'       => sanitize_text_field( $s['label'] ),
				'description' => sanitize_text_field( $s['description'] ?? '' ),
			];

			if ( 'block' !== $group || array_key_exists( 'panel', $s ) ) {
				$normalized['panel'] = self::normalize_panel_key( $s['panel'] ?? 'general' );
			}

			$normalized += [
				'type'             => $type,
				'attributeUpdates' => is_array( $attribute_updates ) ? $attribute_updates : [],
				'currentValue'     => self::sanitize_display_value( $s['currentValue'] ?? null ),
				'suggestedValue'   => self::sanitize_display_value( $s['suggestedValue'] ?? null ),
				'isCurrentStyle'   => isset( $s['isCurrentStyle'] ) ? (bool) $s['isCurrentStyle'] : null,
				'isRecommended'    => isset( $s['isRecommended'] ) ? (bool) $s['isRecommended'] : null,
				'confidence'       => isset( $s['confidence'] ) ? (float) $s['confidence'] : null,
				'preview'          => isset( $s['preview'] ) ? sanitize_text_field( $s['preview'] ) : null,
				'presetSlug'       => isset( $s['presetSlug'] ) ? sanitize_key( $s['presetSlug'] ) : null,
				'cssVar'           => isset( $s['cssVar'] ) ? sanitize_text_field( $s['cssVar'] ) : null,
			];

			$has_executable_updates = ! empty( $normalized['attributeUpdates'] );
			$ranking_input          = is_array( $s['ranking'] ?? null ) ? $s['ranking'] : [];
			$computed_score         = RankingContract::resolve_score_candidate(
				$ranking_input['score'] ?? null,
				$s['score'] ?? null,
				$ranking_input['confidence'] ?? null,
				$s['confidence'] ?? null
			);
			if ( null === $computed_score ) {
				$computed_score = RankingContract::derive_score(
					0.45,
					[
						'has_executable_updates' => $has_executable_updates ? 0.25 : 0.0,
						'has_description'        => '' !== $normalized['description'] ? 0.15 : 0.0,
						'has_type'               => null !== $type && '' !== $type ? 0.05 : 0.0,
						'has_preview'            => null !== $normalized['preview'] && '' !== $normalized['preview'] ? 0.05 : 0.0,
					]
				);
			}
			$source_signals = [ 'llm_response', $group . '_surface' ];

			if ( $has_executable_updates ) {
				$source_signals[] = 'has_executable_updates';
			}

			if ( '' !== $normalized['description'] ) {
				$source_signals[] = 'has_description';
			}

			if ( array_key_exists( 'ranking', $s ) || isset( $s['confidence'] ) || isset( $s['score'] ) ) {
				$normalized['ranking'] = RankingContract::normalize(
					$ranking_input,
					[
						'score'         => $computed_score,
						'reason'        => (string) ( $s['description'] ?? '' ),
						'sourceSignals' => $source_signals,
						'safetyMode'    => 'validated',
						'freshnessMeta' => [
							'source' => 'llm',
							'group'  => $group,
						],
						'advisoryType'  => 'block' === $group ? (string) ( $s['type'] ?? '' ) : '',
					]
				);
			}

			$normalized['_rankScore'] = $computed_score;
			$normalized['_rankOrder'] = $order++;
			$valid[]                  = $normalized;
		}

		usort(
			$valid,
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
			$valid
		);
	}

	private static function filter_suggestion_for_content_only( array $suggestion, array $content_attribute_keys ): ?array {
		$suggestion = self::normalize_block_suggestion_for_execution( $suggestion );

		if ( self::is_advisory_only_block_type( $suggestion['type'] ?? null ) ) {
			return $suggestion;
		}

		if ( ! is_array( $suggestion['attributeUpdates'] ?? null ) ) {
			return null;
		}

		$filtered_updates = [];

		foreach ( $suggestion['attributeUpdates'] as $key => $value ) {
			if ( in_array( $key, $content_attribute_keys, true ) ) {
				$filtered_updates[ $key ] = $value;
			}
		}

		if ( empty( $filtered_updates ) ) {
			return null;
		}

		$suggestion['attributeUpdates'] = $filtered_updates;

		return $suggestion;
	}

	private static function normalize_block_payload_for_execution( array $payload ): array {
		$payload['block'] = array_values(
			array_map(
				fn( array $suggestion ): array => self::normalize_block_suggestion_for_execution( $suggestion ),
				array_filter(
					$payload['block'] ?? [],
					'is_array'
				)
			)
		);

		return $payload;
	}

	private static function normalize_block_suggestion_for_execution( array $suggestion ): array {
		if ( ! self::is_advisory_only_block_type( $suggestion['type'] ?? null ) ) {
			return $suggestion;
		}

		$suggestion['attributeUpdates'] = [];

		return $suggestion;
	}

	/**
	 * @param string[] $bindable_attribute_keys
	 */
	private static function filter_suggestion_for_bindable_attributes( array $suggestion, array $bindable_attribute_keys ): ?array {
		if (
			! is_array( $suggestion['attributeUpdates'] ?? null ) ||
			[] === $suggestion['attributeUpdates']
		) {
			return $suggestion;
		}

		$filtered_updates = self::filter_attribute_updates_for_bindable_attributes(
			$suggestion['attributeUpdates'],
			$bindable_attribute_keys
		);

		if ( empty( $filtered_updates ) ) {
			return null;
		}

		$suggestion['attributeUpdates'] = $filtered_updates;

		return $suggestion;
	}

	/**
	 * @param string[] $bindable_attribute_keys
	 */
	private static function filter_attribute_updates_for_bindable_attributes( array $attribute_updates, array $bindable_attribute_keys ): array {
		$metadata = $attribute_updates['metadata'] ?? null;
		$bindings = is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : null;

		if ( ! is_array( $metadata ) || ! is_array( $bindings ) ) {
			return $attribute_updates;
		}

		$allowed_bindings  = array_fill_keys( $bindable_attribute_keys, true );
		$filtered_bindings = [];

		foreach ( $bindings as $attribute_name => $binding ) {
			if ( is_string( $attribute_name ) && isset( $allowed_bindings[ $attribute_name ] ) ) {
				$filtered_bindings[ $attribute_name ] = $binding;
			}
		}

		if ( [] === $filtered_bindings ) {
			unset( $attribute_updates['metadata'] );
			return $attribute_updates;
		} else {
			$metadata['bindings'] = $filtered_bindings;
		}

		if ( [] === $metadata ) {
			unset( $attribute_updates['metadata'] );
		} else {
			$attribute_updates['metadata'] = $metadata;
		}

		return $attribute_updates;
	}

	/**
	 * @return string[]|null
	 */
	private static function get_bindable_attribute_keys( array $block ): ?array {
		if ( ! array_key_exists( 'bindableAttributes', $block ) ) {
			return null;
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( mixed $attribute ): string => is_string( $attribute )
							? ( self::sanitize_attribute_update_key( $attribute ) ?? '' )
							: '',
						(array) $block['bindableAttributes']
					),
					static fn( string $attribute ): bool => $attribute !== ''
				)
			)
		);
	}

	private static function get_block_restrictions( array $block ): array {
		$editing_mode = self::normalize_editing_mode( $block['editingMode'] ?? 'default' );

		return [
			'disabled'    => $editing_mode === 'disabled',
			'contentOnly' => ! empty( $block['isInsideContentOnly'] ) || $editing_mode === 'contentOnly',
		];
	}

	private static function uses_inner_blocks_as_content( array $block ): bool {
		return ! empty( $block['supportsContentRole'] ) && empty( $block['contentAttributes'] );
	}

	private static function is_advisory_only_block_type( mixed $type ): bool {
		return in_array( $type, [ 'structural_recommendation', 'pattern_replacement' ], true );
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

	/**
	 * Recursively sanitize attribute update values from LLM output.
	 */
	private static function sanitize_attribute_updates( mixed $data, int $depth = 0 ): mixed {
		if ( $depth > 5 ) {
			return null;
		}
		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}
		if ( is_array( $data ) ) {
			if ( self::is_list_array( $data ) ) {
				return array_values(
					array_map(
						fn( mixed $value ) => self::sanitize_attribute_updates( $value, $depth + 1 ),
						$data
					)
				);
			}

			$out = [];
			foreach ( $data as $key => $value ) {
				$sanitized_key = self::sanitize_attribute_update_key( $key );

				if ( null === $sanitized_key ) {
					continue;
				}

				$out[ $sanitized_key ] = self::sanitize_attribute_updates( $value, $depth + 1 );
			}
			return $out;
		}
		if ( is_bool( $data ) || is_int( $data ) || is_float( $data ) || is_null( $data ) ) {
			return $data;
		}
		return null;
	}

	/**
	 * Remove unsafe CSS channels from attribute updates after shape sanitization.
	 *
	 * @param string[] $path
	 * @return array{kept: bool, value: mixed}
	 */
	private static function filter_unsafe_attribute_updates( mixed $data, array $path = [] ): array {
		if ( is_string( $data ) ) {
			if ( self::is_style_adjacent_attribute_path( $path ) && self::looks_like_css_payload( $data ) ) {
				return [
					'kept'  => false,
					'value' => null,
				];
			}

			return [
				'kept'  => true,
				'value' => $data,
			];
		}

		if ( ! is_array( $data ) ) {
			return [
				'kept'  => true,
				'value' => $data,
			];
		}

		if ( self::is_list_array( $data ) ) {
			$values = [];

			foreach ( $data as $index => $value ) {
				$filtered = self::filter_unsafe_attribute_updates(
					$value,
					array_merge( $path, [ (string) $index ] )
				);

				if ( $filtered['kept'] ) {
					$values[] = $filtered['value'];
				}
			}

			return [
				'kept'  => [] !== $values || [] === $path,
				'value' => $values,
			];
		}

		$values = [];

		foreach ( $data as $key => $value ) {
			$next_path = array_merge( $path, [ (string) $key ] );

			if ( self::is_banned_attribute_update_path( $next_path ) ) {
				continue;
			}

			$filtered = self::filter_unsafe_attribute_updates( $value, $next_path );

			if ( ! $filtered['kept'] ) {
				continue;
			}

			$values[ $key ] = $filtered['value'];
		}

		return [
			'kept'  => [] !== $values || [] === $path,
			'value' => $values,
		];
	}

	/**
	 * @param string[] $path
	 */
	private static function is_banned_attribute_update_path( array $path ): bool {
		if ( [] === $path ) {
			return false;
		}

		if ( 'customCSS' === $path[0] ) {
			return true;
		}

		for ( $index = 0; $index < count( $path ) - 1; $index++ ) {
			if ( 'style' === $path[ $index ] && 'css' === $path[ $index + 1 ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string[] $path
	 */
	private static function is_style_adjacent_attribute_path( array $path ): bool {
		if ( [] === $path ) {
			return false;
		}

		if ( 'customCSS' === $path[0] ) {
			return true;
		}

		return in_array( 'style', $path, true ) || 'css' === end( $path );
	}

	private static function looks_like_css_payload( string $value ): bool {
		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return false;
		}

		if ( preg_match( '/[{};]/', $trimmed ) ) {
			return true;
		}

		if ( preg_match( '/!\s*important\b/i', $trimmed ) ) {
			return true;
		}

		if ( preg_match( '/^@(?:container|font-face|import|keyframes|layer|media|supports)\b/i', $trimmed ) ) {
			return true;
		}

		if (
			preg_match( '/^[a-z-]+\s*:\s*.+$/i', $trimmed ) &&
			! preg_match( '/^var:preset\|/i', $trimmed ) &&
			! preg_match( '/^var\(--/i', $trimmed )
		) {
			return true;
		}

		return false;
	}

	private static function sanitize_display_value( mixed $data ): mixed {
		return self::sanitize_attribute_updates( $data );
	}

	private static function normalize_panel_key( mixed $panel ): string {
		$normalized = sanitize_key( is_string( $panel ) ? $panel : 'general' );

		return match ( $normalized ) {
			'listview', 'list-view' => 'list',
			default => $normalized !== '' ? $normalized : 'general',
		};
	}

	private static function sanitize_attribute_update_key( mixed $key ): ?string {
		if ( is_int( $key ) ) {
			return (string) $key;
		}

		if ( ! is_string( $key ) ) {
			return null;
		}

		$sanitized_key = trim( wp_strip_all_tags( $key ) );

		if ( '' === $sanitized_key ) {
			return null;
		}

		if ( preg_match( '/[\x00-\x1F\x7F]/', $sanitized_key ) ) {
			return null;
		}

		return $sanitized_key;
	}

	private static function is_list_array( array $data ): bool {
		$expected_index = 0;

		foreach ( $data as $key => $_value ) {
			if ( $key !== $expected_index ) {
				return false;
			}

			++$expected_index;
		}

		return true;
	}
}
