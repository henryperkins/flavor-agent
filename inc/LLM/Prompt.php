<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\Context\BlockOperationValidator;
use FlavorAgent\Support\FormatsDocsGuidance;
use FlavorAgent\Support\RankingContract;

final class Prompt {

	use FormatsDocsGuidance;

	private const CSS_LENGTH_UNITS = [
		'px',
		'em',
		'rem',
		'vh',
		'vw',
		'vmin',
		'vmax',
		'svh',
		'lvh',
		'dvh',
		'svw',
		'lvw',
		'dvw',
		'ch',
		'ex',
		'cap',
		'ic',
		'lh',
		'rlh',
		'cm',
		'mm',
		'q',
		'in',
		'pt',
		'pc',
	];

	private const BLOCK_OPERATION_PROMPT_MAX_PATTERNS = 20;

	private const BLOCK_OPERATION_PROMPT_ALLOWED_ACTIONS = [
		'insert_before',
		'insert_after',
		'replace',
	];

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
	"type": "attribute_change|style_variation or empty string",
	"attributeUpdates": "{\"attributeName\":\"value\"}",
  "currentValue": "Current value display label or empty string",
  "suggestedValue": "Suggested value display label or empty string",
  "isCurrentStyle": false,
  "isRecommended": false,
  "confidence": 0.0,
  "preview": "Hex color for visual preview swatch or empty string",
  "presetSlug": "Theme preset slug or empty string",
  "cssVar": "CSS custom property reference or empty string"
}

Each item in block is an object:
{
  "label": "Human-readable name",
  "description": "Why this helps (one sentence)",
	"type": "attribute_change|style_variation|structural_recommendation|pattern_replacement or empty string",
	"attributeUpdates": "{\"attributeName\":\"value\"}",
	"panel": "Inspector panel for executable block items; use empty string for advisory structural/pattern ideas and style_variation items when no mapped panel applies",
	"operations": [],
  "currentValue": "Current value display label or empty string",
  "suggestedValue": "Suggested value display label or empty string",
  "isCurrentStyle": false,
  "isRecommended": false,
  "confidence": 0.0,
  "preview": "Hex color for visual preview swatch or empty string",
  "presetSlug": "Theme preset slug or empty string",
  "cssVar": "CSS custom property reference or empty string"
}

Rules:
- "settings" array: suggestions for the Settings tab (layout, alignment, position, advanced, bindings, list, general config).
- "styles" array: suggestions for the Appearance tab (color, filter, typography, dimensions, border, shadow, background, style variations).
- "block" array: block-level suggestions (style variation changes, structural recommendations).
- Only suggest executable settings/styles suggestions and executable block attribute_change suggestions for panels listed in the block's inspectorPanels.
- Executable block attribute_change items must include a non-empty panel listed in inspectorPanels when panel mapping is known.
- If inspectorPanels is an empty object or array, treat that as an explicit signal that no mapped Inspector panels are available — not that the field was omitted. Do not say the panels were not provided.
- You may still suggest a registered style variation as a block-level "style_variation" item when styles are provided and one is clearly beneficial, even if inspectorPanels is empty; set panel to an empty string when no specific mapped panel applies.
- When a block has little or no direct Inspector surface, you may use the block array for advisory suggestions about parent containers, structural composition, or replacing the block with a more suitable pattern.
- Structural_recommendation and pattern_replacement block items are advisory-only unless they include exactly one operation from the allowed catalog shown in the prompt. Even then, the operation is only a proposal; the plugin validator decides whether it becomes review-safe metadata. Omit panel for those items unless it materially clarifies the idea.
- For structural_recommendation or pattern_replacement items, set attributeUpdates to "{}". If a local selected-block attribute update is also useful, emit it as a separate attribute_change item.
- Advisory block suggestions must not invent executable attributeUpdates for ancestor blocks, wrappers, or replacement patterns. Only include attributeUpdates when the selected block's own local attributes can be changed safely.
- If you need to suggest both a local selected-block mutation and a broader structural idea, emit two separate suggestions instead of combining them into one item.
- attributeUpdates must be a JSON object string, not a nested object. Use "{}" when there are no selected-block attribute changes.
- For display metadata, use empty string, false, or 0 when the metadata is not applicable. Use confidence 0 only when you cannot estimate confidence.
- Every block-lane item must include operations. Use [] for ordinary attribute-only recommendations.
- Do not invent proposedOperations or rejectedOperations. The plugin derives them after validation.
- When allowed block pattern actions are provided, you may propose at most one operation from the catalog.
- Use insert_pattern only with position insert_before or insert_after.
- Use replace_block_with_pattern only when the allowed pattern lists replace and set position to an empty string.
- Never treat operations as authorization; the plugin validator may reject them.
- When structural actions are not described in the prompt, return operations: [].
- Use "list" for List View tab suggestions.
- When bindableAttributes are provided, only suggest metadata.bindings changes for those attribute names.
- Only suggest preset values that exist in the provided themeTokens.
- CSS custom property references like var(--wp--custom--brand-accent) are allowed when they match the theme's live variables.
- When the relevant preset family is empty in themeTokens, you may fall back to safe raw values for that property (for example hex colors, font sizes, font families, or shadow strings).
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
- You may suggest viewport visibility rules in attributeUpdates: "{\"metadata\":{\"blockVisibility\":{\"viewport\":{\"mobile\":false}}}}" to show/hide the block on specific devices.
- If theme pseudo-class styles (:hover, :focus, :active, :focus-visible) are provided for a block, use them when suggesting interactive state styles.
- Treat themeTokens.enabledFeatures and themeTokens.layout as hard capability constraints. Avoid suggesting controls the theme has disabled or locked.
- For style objects in attributeUpdates, use the nested style format:
  "{\"style\":{\"color\":{\"background\":\"var(--wp--preset--color--accent)\"}}}"
  or preset attributes like "{\"backgroundColor\":\"accent\"}".
- For duotone, use style.color.duotone. Preset references must use the canonical format "var:preset|duotone|{slug}".
- When a block supports both aspect ratio and explicit height, never suggest setting both in the same recommendation. Choose aspectRatio or height/minHeight, not both.
- Preserve Gutenberg attribute key casing exactly in attributeUpdates (for example, backgroundColor and metadata.blockVisibility).
- If suggesting a registered style variation, use "type": "style_variation" and include the exact attributeUpdates needed to activate it.
SYSTEM;
	}

	/**
	 * Build the user prompt from editor context.
	 */
	public static function build_user(
		array $context,
		string $prompt = '',
		array $docs_guidance = [],
		array $execution_contract = []
	): string {
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

		if ( ! empty( $execution_contract ) ) {
			$parts[] = 'Execution contract: ' . wp_json_encode(
				array_filter(
					[
						'allowedPanels'            => $execution_contract['allowedPanels'] ?? [],
						'styleSupportPaths'        => $execution_contract['styleSupportPaths'] ?? [],
						'registeredStyles'         => $execution_contract['registeredStyles'] ?? [],
						'hasExplicitlyEmptyPanels' => ! empty( $execution_contract['hasExplicitlyEmptyPanels'] ),
					],
					static fn( mixed $value ): bool => [] !== $value && null !== $value && false !== $value
				)
			);
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

		$guidelines_context = \FlavorAgent\Guidelines::format_prompt_context( (string) ( $block['name'] ?? '' ) );
		if ( '' !== $guidelines_context ) {
			$parts[] = '';
			$parts[] = $guidelines_context;
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

		$block_operation_context = self::format_block_operation_context(
			is_array( $context['blockOperationContext'] ?? null ) ? $context['blockOperationContext'] : []
		);
		if ( '' !== $block_operation_context ) {
			$parts[] = '';
			$parts[] = '## Allowed block pattern actions';
			$parts[] = $block_operation_context;
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

	private static function format_block_operation_context( array $operation_context ): string {
		$target_client_id  = is_string( $operation_context['targetClientId'] ?? null ) ? sanitize_text_field( $operation_context['targetClientId'] ) : '';
		$target_block_name = is_string( $operation_context['targetBlockName'] ?? null ) ? sanitize_text_field( $operation_context['targetBlockName'] ) : '';
		$target_signature  = is_string( $operation_context['targetSignature'] ?? null ) ? sanitize_text_field( $operation_context['targetSignature'] ) : '';

		if ( '' === $target_client_id || '' === $target_block_name || '' === $target_signature ) {
			return '';
		}

		$patterns = [];

		foreach (
			array_slice(
				is_array( $operation_context['allowedPatterns'] ?? null ) ? $operation_context['allowedPatterns'] : [],
				0,
				self::BLOCK_OPERATION_PROMPT_MAX_PATTERNS
			) as $pattern
		) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$name = is_string( $pattern['name'] ?? null ) ? sanitize_text_field( $pattern['name'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$allowed_actions = array_values(
				array_intersect(
					self::sanitize_string_list( $pattern['allowedActions'] ?? [] ),
					self::BLOCK_OPERATION_PROMPT_ALLOWED_ACTIONS
				)
			);

			if ( empty( $allowed_actions ) ) {
				continue;
			}

			$patterns[] = [
				'name'           => $name,
				'title'          => is_string( $pattern['title'] ?? null ) ? sanitize_text_field( $pattern['title'] ) : '',
				'source'         => is_string( $pattern['source'] ?? null ) ? sanitize_key( $pattern['source'] ) : '',
				'categories'     => self::sanitize_string_list( $pattern['categories'] ?? [] ),
				'blockTypes'     => self::sanitize_string_list( $pattern['blockTypes'] ?? [] ),
				'allowedActions' => $allowed_actions,
			];
		}

		$lines = [
			'Target: ' . wp_json_encode(
				[
					'targetClientId'  => $target_client_id,
					'targetBlockName' => $target_block_name,
					'targetSignature' => $target_signature,
				]
			),
		];

		if ( empty( $patterns ) ) {
			$lines[] = 'Allowed patterns: none for this target. Return operations: [].';
		} else {
			$lines[] = 'Allowed patterns: ' . wp_json_encode( $patterns );
			$lines[] = 'Catalog:';
			$lines[] = '- insert_pattern: patternName, targetClientId, position insert_before|insert_after.';
			$lines[] = '- replace_block_with_pattern: patternName, targetClientId, position empty string.';
			$lines[] = 'Return at most one operation per block suggestion.';
			$lines[] = 'Use only targetClientId and patternName values shown above.';
		}

		return implode( "\n", $lines );
	}

	private static function sanitize_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( mixed $entry ): string => is_string( $entry )
							? sanitize_text_field( $entry )
							: '',
						$value
					),
					static fn( string $entry ): bool => '' !== $entry
				)
			)
		);
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
		$cleaned = self::clean_response_text( $raw );
		$data    = self::decode_response_json( $cleaned, $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'parse_error',
				'Block recommendation response must be a JSON object.',
				[
					'status' => 502,
					'raw'    => substr( $raw, 0, 500 ),
				]
			);
		}

		$block_suggestions = self::normalize_response_suggestion_list( $data['block'] ?? null, 'block' );

		if ( [] === $block_suggestions ) {
			$block_suggestions = self::normalize_response_suggestion_list( $data['suggestions'] ?? null, 'block' );
		}

		$explanation = $data['explanation'] ?? '';
		$explanation = is_scalar( $explanation ) ? sanitize_text_field( (string) $explanation ) : '';

		return [
			'settings'    => self::validate_suggestions(
				self::normalize_response_suggestion_list( $data['settings'] ?? null, 'settings' ),
				'settings'
			),
			'styles'      => self::validate_suggestions(
				self::normalize_response_suggestion_list( $data['styles'] ?? null, 'styles' ),
				'styles'
			),
			'block'       => self::validate_suggestions( $block_suggestions, 'block' ),
			'explanation' => $explanation,
		];
	}

	private static function clean_response_text( string $raw ): string {
		$cleaned = preg_replace( '/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim( $raw ) );

		return is_string( $cleaned ) ? trim( $cleaned ) : trim( $raw );
	}

	private static function decode_response_json( string $cleaned, string $raw ): mixed {
		$data = json_decode( $cleaned, true );

		if ( json_last_error() === JSON_ERROR_NONE ) {
			return $data;
		}

		$json_object = self::extract_json_object( $cleaned );

		if ( null !== $json_object ) {
			$data = json_decode( $json_object, true );

			if ( json_last_error() === JSON_ERROR_NONE ) {
				return $data;
			}
		}

		return new \WP_Error(
			'parse_error',
			'Failed to parse LLM response as JSON: ' . json_last_error_msg(),
			[
				'status' => 502,
				'raw'    => substr( $raw, 0, 500 ),
			]
		);
	}

	/**
	 * Recover a single JSON object from a response that may include surrounding prose.
	 *
	 * Naive scan: returns the substring between the first `{` and the last `}`.
	 * This handles the common case where the model prefixes/suffixes the JSON
	 * payload with a sentence or two. It does not handle multiple top-level
	 * objects, unbalanced braces inside string literals, or code-fenced JSON
	 * containing prose. Those cases fall through to the parse_error WP_Error path.
	 */
	private static function extract_json_object( string $text ): ?string {
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		return substr( $text, $start, $end - $start + 1 );
	}

	private static function normalize_response_suggestion_list( mixed $value, string $group ): array {
		if ( is_array( $value ) ) {
			if ( self::is_list_array( $value ) ) {
				return $value;
			}

			return array_key_exists( 'label', $value ) || array_key_exists( 'description', $value )
				? [ $value ]
				: [];
		}

		if ( 'block' === $group && is_string( $value ) ) {
			$suggestion = self::build_plain_text_advisory_suggestion( $value );

			return null !== $suggestion ? [ $suggestion ] : [];
		}

		return [];
	}

	private static function build_plain_text_advisory_suggestion( string $text ): ?array {
		$description = self::normalize_plain_text_recommendation( $text );

		if ( '' === $description ) {
			return null;
		}

		return [
			'label'            => self::plain_text_recommendation_summary( $description ),
			'description'      => $description,
			'type'             => 'structural_recommendation',
			'attributeUpdates' => '{}',
			'operations'       => [],
			'confidence'       => 0.35,
		];
	}

	private static function normalize_plain_text_recommendation( string $text ): string {
		$without_tags = wp_strip_all_tags( $text );
		$collapsed    = preg_replace( '/\s+/', ' ', $without_tags );
		$normalized   = is_string( $collapsed ) ? trim( $collapsed ) : trim( $without_tags );

		if ( '' === $normalized || ! preg_match( '/[A-Za-z]/', $normalized ) ) {
			return '';
		}

		return $normalized;
	}

	private static function plain_text_recommendation_summary( string $text ): string {
		$sentences = preg_split( '/(?<=[.!?])\s+/', $text );
		$summary   = is_array( $sentences ) && is_string( $sentences[0] ?? null )
			? trim( $sentences[0] )
			: trim( $text );

		if ( strlen( $summary ) > 96 ) {
			$summary = rtrim( substr( $summary, 0, 93 ) ) . '...';
		}

		return $summary;
	}

	public static function enforce_block_context_rules( array $payload, array $block, array $execution_contract = [], array $block_operation_context = [] ): array {
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
		$payload = self::filter_payload_for_execution_contract( $payload, $execution_contract, $block );
		$payload = self::filter_payload_for_bindable_attributes( $payload, $block );
		$payload = self::enforce_block_operation_context_rules( $payload, $block_operation_context );

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

	private static function enforce_block_operation_context_rules( array $payload, array $block_operation_context ): array {
		$validator_context = BlockOperationValidator::normalize_context(
			$block_operation_context,
			function_exists( '\\flavor_agent_block_structural_actions_enabled' )
				&& \flavor_agent_block_structural_actions_enabled()
		);

		$payload['block'] = array_values(
			array_map(
				static function ( array $suggestion ) use ( $validator_context ): array {
					$validation = BlockOperationValidator::validate_sequence(
						$suggestion['proposedOperations'] ?? [],
						$validator_context
					);

					$suggestion['operations']         = $validation['operations'];
					$suggestion['rejectedOperations'] = $validation['rejectedOperations'];

					return $suggestion;
				},
				array_filter( $payload['block'] ?? [], 'is_array' )
			)
		);

		return $payload;
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

	private static function filter_payload_for_execution_contract( array $payload, array $execution_contract, array $block = [] ): array {
		if ( [] === $execution_contract ) {
			return $payload;
		}

		$execution_contract = self::normalize_execution_contract_for_payload_filtering( $block, $execution_contract );

		return [
			'settings'    => self::filter_suggestion_group_for_execution_contract(
				$payload['settings'] ?? [],
				'settings',
				$execution_contract
			),
			'styles'      => self::filter_suggestion_group_for_execution_contract(
				$payload['styles'] ?? [],
				'styles',
				$execution_contract
			),
			'block'       => self::filter_suggestion_group_for_execution_contract(
				$payload['block'] ?? [],
				'block',
				$execution_contract
			),
			'explanation' => $payload['explanation'] ?? '',
		];
	}

	private static function normalize_execution_contract_for_payload_filtering( array $block, array $execution_contract ): array {
		foreach (
			[
				'contentAttributeKeys' => 'contentAttributes',
				'configAttributeKeys'  => 'configAttributes',
			] as $contract_key => $context_key
		) {
			if ( array_key_exists( $contract_key, $execution_contract ) || ! is_array( $block[ $context_key ] ?? null ) ) {
				continue;
			}

			$execution_contract[ $contract_key ] = array_values(
				array_filter(
					array_keys( $block[ $context_key ] ),
					'is_string'
				)
			);
		}

		return $execution_contract;
	}

	private static function filter_suggestion_group_for_execution_contract( array $suggestions, string $group, array $execution_contract ): array {
		return array_values(
			array_filter(
				array_map(
					fn( array $suggestion ): ?array => self::filter_suggestion_for_execution_contract(
						$suggestion,
						$group,
						$execution_contract
					),
					array_filter( $suggestions, 'is_array' )
				)
			)
		);
	}

	private static function filter_suggestion_for_execution_contract( array $suggestion, string $group, array $execution_contract ): ?array {
		$is_advisory_only            = 'block' === $group && self::is_advisory_only_block_type( $suggestion['type'] ?? null );
		$allowed_panels              = self::get_allowed_panel_lookup( $execution_contract );
		$has_empty_panels            = ! empty( $execution_contract['hasExplicitlyEmptyPanels'] );
		$should_enforce_panels       = $has_empty_panels
			|| [] !== $allowed_panels
			|| self::execution_contract_knows_panel_mapping( $execution_contract );
		$should_enforce_block_panels = $has_empty_panels
			|| self::execution_contract_knows_panel_mapping( $execution_contract );
		$panel                       = array_key_exists( 'panel', $suggestion )
			? self::normalize_optional_panel_key( $suggestion['panel'] ?? '' )
			: '';
		$is_style_variation          = ( $suggestion['type'] ?? null ) === 'style_variation';

		if ( 'settings' === $group || 'styles' === $group ) {
			if ( $should_enforce_panels && ( '' === $panel || ! isset( $allowed_panels[ $panel ] ) ) ) {
				return null;
			}
		} elseif ( 'block' === $group && ! $is_advisory_only && $should_enforce_block_panels ) {
			if ( $is_style_variation ) {
				if ( '' !== $panel && ! isset( $allowed_panels[ $panel ] ) ) {
					return null;
				}
			} elseif ( '' === $panel || ! isset( $allowed_panels[ $panel ] ) ) {
				return null;
			}
		}

		if ( 'block' === $group && ! $is_advisory_only && $has_empty_panels && ! $is_style_variation ) {
			return null;
		}

		if ( $is_style_variation && ! self::is_valid_style_variation_suggestion( $suggestion, $execution_contract ) ) {
			return null;
		}

		if ( ! is_array( $suggestion['attributeUpdates'] ?? null ) || [] === $suggestion['attributeUpdates'] ) {
			return $is_advisory_only || 'block' === $group ? $suggestion : null;
		}

		$filtered_updates = self::filter_attribute_updates_for_execution_contract(
			$suggestion['attributeUpdates'],
			$execution_contract,
			$is_style_variation
		);

		if ( [] === $filtered_updates ) {
			return $is_advisory_only ? $suggestion : null;
		}

		$suggestion['attributeUpdates'] = $filtered_updates;

		return $suggestion;
	}

	private static function filter_attribute_updates_for_execution_contract(
		array $attribute_updates,
		array $execution_contract,
		bool $allow_class_name = false
	): array {
		$filtered_updates = [];
		$local_attributes = self::get_allowed_local_attribute_lookup( $execution_contract );

		foreach ( $attribute_updates as $key => $value ) {
			switch ( $key ) {
				case 'lock':
					$validated = null;
					break;
				case 'className':
					$validated = $allow_class_name && is_string( $value )
						? self::normalize_registered_style_variation_class_name( $value, $execution_contract )
						: null;
					break;
				case 'metadata':
					$validated = is_array( $value )
						? self::filter_metadata_attribute_updates_for_execution_contract( $value, $execution_contract )
						: [];
					break;
				case 'backgroundColor':
					$validated = self::validate_top_level_preset_attribute(
						$value,
						'color.background',
						'color',
						'backgroundColor',
						$execution_contract
					);
					break;
				case 'textColor':
					$validated = self::validate_top_level_preset_attribute(
						$value,
						'color.text',
						'color',
						'textColor',
						$execution_contract
					);
					break;
				case 'gradient':
					$validated = self::validate_top_level_preset_attribute(
						$value,
						'color.gradients',
						'gradient',
						null,
						$execution_contract
					);
					break;
				case 'fontSize':
					$validated = self::validate_top_level_preset_attribute(
						$value,
						'typography.fontSize',
						'fontsize',
						null,
						$execution_contract
					);
					break;
				case 'textAlign':
					$validated = self::validate_supported_scalar_attribute(
						$value,
						'typography.textAlign',
						$execution_contract
					);
					break;
				case 'minHeight':
					$validated = self::validate_supported_scalar_attribute(
						$value,
						'dimensions.minHeight',
						$execution_contract
					);
					break;
				case 'minHeightUnit':
					$validated = self::validate_supported_scalar_attribute(
						$value,
						'dimensions.minHeight',
						$execution_contract
					);
					break;
				case 'height':
					$validated = self::validate_supported_scalar_attribute(
						$value,
						'dimensions.height',
						$execution_contract
					);
					break;
				case 'width':
					$validated = self::validate_supported_scalar_attribute(
						$value,
						'dimensions.width',
						$execution_contract
					);
					break;
				case 'aspectRatio':
					$validated = self::validate_supported_scalar_attribute(
						$value,
						'dimensions.aspectRatio',
						$execution_contract
					);
					break;
				case 'style':
					$validated = is_array( $value )
						? self::filter_style_attribute_updates_for_execution_contract(
							$value,
							$execution_contract
						)
						: [];
					break;
				default:
					$validated = isset( $local_attributes[ $key ] )
						? $value
						: null;
					break;
			}

			if ( null === $validated || [] === $validated ) {
				continue;
			}

			$filtered_updates[ $key ] = $validated;
		}

		return $filtered_updates;
	}

	private static function get_allowed_local_attribute_lookup( array $execution_contract ): array {
		$attributes = array_merge(
			(array) ( $execution_contract['contentAttributeKeys'] ?? [] ),
			(array) ( $execution_contract['configAttributeKeys'] ?? [] )
		);
		$lookup     = [];

		foreach ( $attributes as $attribute ) {
			$attribute_key = self::sanitize_attribute_update_key( $attribute );

			if ( null !== $attribute_key ) {
				$lookup[ $attribute_key ] = true;
			}
		}

		return $lookup;
	}

	private static function filter_metadata_attribute_updates_for_execution_contract(
		array $metadata,
		array $execution_contract
	): array {
		$filtered = [];

		if (
			array_key_exists( 'blockVisibility', $metadata ) &&
			(
				false === $metadata['blockVisibility'] ||
				is_array( $metadata['blockVisibility'] )
			)
		) {
			$filtered['blockVisibility'] = $metadata['blockVisibility'];
		}

		if ( is_array( $metadata['bindings'] ?? null ) ) {
			$bindable_attribute_keys = self::get_bindable_attribute_keys_from_execution_contract( $execution_contract );

			if ( is_array( $bindable_attribute_keys ) ) {
				$allowed_bindings  = array_fill_keys( $bindable_attribute_keys, true );
				$filtered_bindings = [];

				foreach ( $metadata['bindings'] as $attribute_name => $binding ) {
					if ( is_string( $attribute_name ) && isset( $allowed_bindings[ $attribute_name ] ) ) {
						$filtered_bindings[ $attribute_name ] = $binding;
					}
				}

				if ( [] !== $filtered_bindings ) {
					$filtered['bindings'] = $filtered_bindings;
				}
			} else {
				$filtered['bindings'] = $metadata['bindings'];
			}
		}

		return $filtered;
	}

	private static function filter_style_attribute_updates_for_execution_contract(
		array $style_updates,
		array $execution_contract,
		array $path = []
	): array {
		$filtered = [];

		foreach ( $style_updates as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$next_path = array_merge( $path, [ $key ] );
			$dot_path  = implode( '.', $next_path );

			if ( in_array( $dot_path, [ 'spacing.padding', 'spacing.margin' ], true ) ) {
				$validated = self::validate_spacing_box_value( $value, $dot_path, $execution_contract );

				if ( null !== $validated && [] !== $validated ) {
					$filtered[ $key ] = $validated;
				}

				continue;
			}

			if ( is_array( $value ) ) {
				$nested = self::filter_style_attribute_updates_for_execution_contract(
					$value,
					$execution_contract,
					$next_path
				);

				if ( [] !== $nested ) {
					$filtered[ $key ] = $nested;
				}

				continue;
			}

			$validated = self::validate_style_leaf_value(
				$dot_path,
				$value,
				$execution_contract
			);

			if ( null !== $validated ) {
				$filtered[ $key ] = $validated;
			}
		}

		return $filtered;
	}

	private static function validate_style_leaf_value( string $dot_path, mixed $value, array $execution_contract ): mixed {
		$rules = [
			'color.background'           => [
				'supportPath'         => 'color.background',
				'featureKey'          => 'backgroundColor',
				'presetType'          => 'color',
				'allowCustomProperty' => true,
				'fallbackValidator'   => 'color',
			],
			'color.text'                 => [
				'supportPath'         => 'color.text',
				'featureKey'          => 'textColor',
				'presetType'          => 'color',
				'allowCustomProperty' => true,
				'fallbackValidator'   => 'color',
			],
			'color.gradient'             => [
				'supportPath'         => 'color.gradients',
				'presetType'          => 'gradient',
				'allowCustomProperty' => true,
			],
			'color.duotone'              => [
				'supportPath' => 'filter.duotone',
				'presetType'  => 'duotone',
			],
			'typography.fontSize'        => [
				'supportPath'         => 'typography.fontSize',
				'presetType'          => 'fontsize',
				'allowCustomProperty' => true,
				'fallbackValidator'   => 'length-or-percentage',
			],
			'typography.fontFamily'      => [
				'supportPath'         => [ 'typography.fontFamily', 'typography.__experimentalFontFamily' ],
				'presetType'          => 'fontfamily',
				'allowCustomProperty' => true,
				'fallbackValidator'   => 'font-family',
			],
			'typography.lineHeight'      => [
				'supportPath' => 'typography.lineHeight',
				'featureKey'  => 'lineHeight',
				'validator'   => 'line-height',
			],
			'typography.fontStyle'       => [
				'supportPath' => 'typography.fontStyle',
				'featureKey'  => 'fontStyle',
				'validator'   => 'font-style',
			],
			'typography.fontWeight'      => [
				'supportPath' => 'typography.fontWeight',
				'featureKey'  => 'fontWeight',
				'validator'   => 'font-weight',
			],
			'typography.letterSpacing'   => [
				'supportPath' => 'typography.letterSpacing',
				'featureKey'  => 'letterSpacing',
				'validator'   => 'letter-spacing',
			],
			'typography.textDecoration'  => [
				'supportPath' => 'typography.textDecoration',
				'featureKey'  => 'textDecoration',
				'validator'   => 'text-decoration',
			],
			'typography.textTransform'   => [
				'supportPath' => 'typography.textTransform',
				'featureKey'  => 'textTransform',
				'validator'   => 'text-transform',
			],
			'spacing.blockGap'           => [
				'supportPath'         => 'spacing.blockGap',
				'featureKey'          => 'blockGap',
				'presetType'          => 'spacing',
				'allowCustomProperty' => true,
			],
			'border.color'               => [
				'supportPath'         => 'border.color',
				'featureKey'          => 'borderColor',
				'presetType'          => 'color',
				'allowCustomProperty' => true,
				'fallbackValidator'   => 'color',
			],
			'border.radius'              => [
				'supportPath' => 'border.radius',
				'featureKey'  => 'borderRadius',
				'validator'   => 'length-or-percentage',
			],
			'border.style'               => [
				'supportPath' => 'border.style',
				'featureKey'  => 'borderStyle',
				'validator'   => 'border-style',
			],
			'border.width'               => [
				'supportPath' => 'border.width',
				'featureKey'  => 'borderWidth',
				'validator'   => 'length',
			],
			'shadow'                     => [
				'supportPath'         => 'shadow',
				'presetType'          => 'shadow',
				'allowCustomProperty' => true,
				'fallbackValidator'   => 'shadow',
			],
			'background.backgroundImage' => [
				'supportPath' => 'background.backgroundImage',
				'featureKey'  => 'backgroundImage',
			],
			'background.backgroundSize'  => [
				'supportPath' => 'background.backgroundSize',
				'featureKey'  => 'backgroundSize',
			],
		];
		$rule  = $rules[ $dot_path ] ?? null;

		if ( ! is_array( $rule ) ) {
			return null;
		}

		$support_paths = isset( $rule['supportPath'] ) && is_array( $rule['supportPath'] )
			? $rule['supportPath']
			: [ $rule['supportPath'] ?? '' ];
		$supports_path = false;

		foreach ( $support_paths as $support_path ) {
			if ( is_string( $support_path ) && self::execution_contract_supports_path( $execution_contract, $support_path ) ) {
				$supports_path = true;
				break;
			}
		}

		if ( ! $supports_path ) {
			return null;
		}

		if ( isset( $rule['featureKey'] ) && ! self::execution_contract_feature_enabled( $execution_contract, (string) $rule['featureKey'] ) ) {
			return null;
		}

		if ( isset( $rule['presetType'] ) ) {
			return self::validate_preset_backed_style_value(
				$value,
				$rule,
				$execution_contract
			);
		}

		if ( isset( $rule['validator'] ) ) {
			return self::validate_freeform_style_value(
				(string) $rule['validator'],
				$value
			);
		}

		return self::sanitize_scalar_value( $value );
	}

	private static function validate_preset_backed_style_value( mixed $value, array $rule, array $execution_contract ): mixed {
		$preset_type = isset( $rule['presetType'] ) && is_string( $rule['presetType'] )
			? $rule['presetType']
			: '';

		if ( '' === $preset_type ) {
			return null;
		}

		$preset_reference = self::validate_preset_reference_value(
			$value,
			$preset_type,
			$execution_contract
		);

		if ( null !== $preset_reference ) {
			return $preset_reference;
		}

		if ( ! empty( $rule['allowCustomProperty'] ) ) {
			$custom_property = self::validate_css_custom_property_reference( $value );

			if ( null !== $custom_property ) {
				return $custom_property;
			}
		}

		if (
			isset( $rule['fallbackValidator'] ) &&
			is_string( $rule['fallbackValidator'] ) &&
			self::preset_type_allows_freeform_fallback( $execution_contract, $preset_type )
		) {
			return self::validate_freeform_style_value(
				$rule['fallbackValidator'],
				$value
			);
		}

		return null;
	}

	private static function validate_spacing_box_value( mixed $value, string $dot_path, array $execution_contract ): mixed {
		$support_path = 'spacing.padding' === $dot_path ? 'spacing.padding' : 'spacing.margin';
		$feature_key  = 'spacing.padding' === $dot_path ? 'padding' : 'margin';

		if ( ! self::execution_contract_supports_path( $execution_contract, $support_path ) ) {
			return null;
		}

		if ( ! self::execution_contract_feature_enabled( $execution_contract, $feature_key ) ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$filtered = [];

			foreach ( $value as $side => $side_value ) {
				if ( ! is_string( $side ) ) {
					continue;
				}

				$validated = self::validate_spacing_scalar_value( $side_value, $execution_contract );

				if ( null !== $validated ) {
					$filtered[ $side ] = $validated;
				}
			}

			return [] !== $filtered ? $filtered : null;
		}

		return self::validate_spacing_scalar_value( $value, $execution_contract );
	}

	private static function validate_spacing_scalar_value( mixed $value, array $execution_contract ): mixed {
		$preset_reference = self::validate_preset_reference_value(
			$value,
			'spacing',
			$execution_contract
		);

		if ( null !== $preset_reference ) {
			return $preset_reference;
		}

		return self::validate_freeform_style_value( 'length-or-percentage', $value );
	}

	private static function validate_top_level_preset_attribute(
		mixed $value,
		string $support_path,
		string $preset_type,
		?string $feature_key,
		array $execution_contract
	): ?string {
		if ( ! self::execution_contract_supports_path( $execution_contract, $support_path ) ) {
			return null;
		}

		if ( null !== $feature_key && ! self::execution_contract_feature_enabled( $execution_contract, $feature_key ) ) {
			return null;
		}

		$validated = self::validate_raw_preset_slug(
			$value,
			$preset_type,
			$execution_contract
		);

		return is_string( $validated ) ? $validated : null;
	}

	private static function validate_supported_scalar_attribute( mixed $value, string $support_path, array $execution_contract ): mixed {
		if ( ! self::execution_contract_supports_path( $execution_contract, $support_path ) ) {
			return null;
		}

		return self::sanitize_scalar_value( $value );
	}

	private static function validate_preset_reference_value( mixed $value, string $preset_type, array $execution_contract ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return null;
		}

		$parsed = self::parse_preset_reference( $trimmed );

		if ( null === $parsed ) {
			return null;
		}

		if ( self::normalize_preset_type( $parsed['type'] ) !== self::normalize_preset_type( $preset_type ) ) {
			return null;
		}

		$allowed = self::get_allowed_preset_lookup( $execution_contract, $preset_type );

		if ( [] === $allowed || ! isset( $allowed[ sanitize_key( $parsed['slug'] ) ] ) ) {
			return null;
		}

		return sanitize_text_field( $trimmed );
	}

	private static function validate_raw_preset_slug( mixed $value, string $preset_type, array $execution_contract ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$slug = sanitize_key( $value );

		if ( '' === $slug ) {
			return null;
		}

		$allowed = self::get_allowed_preset_lookup( $execution_contract, $preset_type );

		return isset( $allowed[ $slug ] ) ? $slug : null;
	}

	private static function preset_type_allows_freeform_fallback( array $execution_contract, string $preset_type ): bool {
		$preset_type = self::normalize_preset_type( $preset_type );

		if ( '' === $preset_type ) {
			return false;
		}

		if ( ! array_key_exists( 'presetSlugs', $execution_contract ) || ! is_array( $execution_contract['presetSlugs'] ) ) {
			return false;
		}

		return array_key_exists( $preset_type, $execution_contract['presetSlugs'] )
			&& is_array( $execution_contract['presetSlugs'][ $preset_type ] )
			&& [] === $execution_contract['presetSlugs'][ $preset_type ];
	}

	private static function validate_css_custom_property_reference( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return null;
		}

		return 1 === preg_match( '/^var\(\s*--[a-z0-9_-]+\s*\)$/i', $trimmed )
			? sanitize_text_field( $trimmed )
			: null;
	}

	private static function parse_preset_reference( string $value ): ?array {
		if ( preg_match( '/^var:preset\|([a-z-]+)\|([a-z0-9_-]+)$/i', $value, $matches ) ) {
			return [
				'type' => sanitize_key( $matches[1] ),
				'slug' => sanitize_key( $matches[2] ),
			];
		}

		if ( preg_match( '/^var\(--wp--preset--([a-z-]+)--([a-z0-9_-]+)\)$/i', $value, $matches ) ) {
			return [
				'type' => sanitize_key( $matches[1] ),
				'slug' => sanitize_key( $matches[2] ),
			];
		}

		return null;
	}

	private static function get_allowed_panel_lookup( array $execution_contract ): array {
		$allowed = [];

		foreach ( (array) ( $execution_contract['allowedPanels'] ?? [] ) as $panel ) {
			if ( ! is_string( $panel ) ) {
				continue;
			}

			$panel_key = self::normalize_panel_key( $panel );

			if ( '' !== $panel_key ) {
				$allowed[ $panel_key ] = true;
			}
		}

		return $allowed;
	}

	private static function execution_contract_knows_panel_mapping( array $execution_contract ): bool {
		return ! empty( $execution_contract['panelMappingKnown'] );
	}

	private static function execution_contract_supports_path( array $execution_contract, string $support_path ): bool {
		if ( '' === $support_path ) {
			return false;
		}

		$support_lookup = array_fill_keys(
			array_map(
				static fn( mixed $path ): string => is_string( $path ) ? trim( $path ) : '',
				(array) ( $execution_contract['styleSupportPaths'] ?? [] )
			),
			true
		);

		if ( [] === $support_lookup && ! self::execution_contract_knows_panel_mapping( $execution_contract ) ) {
			return true;
		}

		return isset( $support_lookup[ $support_path ] );
	}

	private static function execution_contract_feature_enabled( array $execution_contract, string $feature_key ): bool {
		$enabled_features = is_array( $execution_contract['enabledFeatures'] ?? null )
			? $execution_contract['enabledFeatures']
			: [];

		return ! array_key_exists( $feature_key, $enabled_features ) || false !== $enabled_features[ $feature_key ];
	}

	private static function get_allowed_preset_lookup( array $execution_contract, string $preset_type ): array {
		$preset_type = self::normalize_preset_type( $preset_type );
		$lookup      = [];

		foreach ( (array) ( $execution_contract['presetSlugs'][ $preset_type ] ?? [] ) as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}

			$normalized_slug = sanitize_key( $slug );

			if ( '' !== $normalized_slug ) {
				$lookup[ $normalized_slug ] = true;
			}
		}

		return $lookup;
	}

	private static function normalize_preset_type( string $preset_type ): string {
		return str_replace( '-', '', sanitize_key( $preset_type ) );
	}

	private static function is_valid_style_variation_suggestion( array $suggestion, array $execution_contract ): bool {
		if ( [] === self::get_registered_style_variation_lookup( $execution_contract ) ) {
			return false;
		}

		$class_name = is_string( $suggestion['attributeUpdates']['className'] ?? null )
			? (string) $suggestion['attributeUpdates']['className']
			: '';

		return null !== self::normalize_registered_style_variation_class_name( $class_name, $execution_contract );
	}

	private static function normalize_registered_style_variation_class_name( string $class_name, array $execution_contract ): ?string {
		$registered_styles = self::get_registered_style_variation_lookup( $execution_contract );
		$tokens            = preg_split( '/\s+/', trim( $class_name ) );

		if ( ! is_array( $tokens ) ) {
			$tokens = [];
		}

		$style_classes = [];

		if ( [] === $registered_styles || [] === $tokens ) {
			return null;
		}

		foreach ( $tokens as $token ) {
			if ( ! is_string( $token ) || '' === $token ) {
				continue;
			}

			if ( ! preg_match( '/^is-style-([a-z0-9_-]+)$/i', $token, $matches ) ) {
				return null;
			}

			$style_name = sanitize_key( $matches[1] );

			if ( ! isset( $registered_styles[ $style_name ] ) ) {
				return null;
			}

			$style_classes[] = 'is-style-' . $style_name;
		}

		$style_classes = array_values( array_unique( $style_classes ) );

		if ( count( $style_classes ) !== 1 ) {
			return null;
		}

		return $style_classes[0];
	}

	private static function get_registered_style_variation_lookup( array $execution_contract ): array {
		return array_fill_keys(
			array_map(
				'sanitize_key',
				array_filter(
					(array) ( $execution_contract['registeredStyles'] ?? [] ),
					'is_string'
				)
			),
			true
		);
	}

	/**
	 * @return string[]
	 */
	private static function extract_style_variation_names( string $class_name ): array {
		if ( '' === $class_name ) {
			return [];
		}

		preg_match_all( '/\bis-style-([a-z0-9_-]+)\b/i', $class_name, $matches );

		return array_values(
			array_unique(
				array_map(
					'sanitize_key',
					$matches[1] ?? []
				)
			)
		);
	}

	private static function validate_freeform_style_value( string $validator, mixed $value ): mixed {
		$custom_property = self::validate_css_custom_property_reference( $value );

		if ( null !== $custom_property ) {
			return $custom_property;
		}

		return match ( $validator ) {
			'color' => self::validate_color_value( $value ),
			'font-family' => self::validate_safe_scalar_string_value( $value ),
			'font-style' => self::validate_font_style_value( $value ),
			'font-weight' => self::validate_font_weight_value( $value ),
			'line-height' => self::validate_line_height_value( $value ),
			'length-or-percentage' => self::validate_length_value( $value, true ),
			'length' => self::validate_length_value( $value, false ),
			'letter-spacing' => self::validate_letter_spacing_value( $value ),
			'border-style' => self::validate_border_style_value( $value ),
			'shadow' => self::validate_safe_scalar_string_value( $value ),
			'text-decoration' => self::validate_text_decoration_value( $value ),
			'text-transform' => self::validate_text_transform_value( $value ),
			default => null,
		};
	}

	private static function validate_line_height_value( mixed $value ): mixed {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		if (
			preg_match( '/^(?:\d+|\d*\.\d+)$/', $trimmed ) ||
			self::is_valid_css_length( $trimmed, true, false )
		) {
			return $trimmed;
		}

		return null;
	}

	private static function validate_length_value( mixed $value, bool $allow_percentage ): mixed {
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 === $value || 0.0 === $value ? $value : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return null;
		}

		return self::is_valid_css_length( $trimmed, $allow_percentage, true )
			? $trimmed
			: null;
	}

	private static function validate_color_value( mixed $value ): ?string {
		$safe_string = self::validate_safe_scalar_string_value( $value );

		if ( null === $safe_string ) {
			return null;
		}

		if (
			preg_match( '/^#[0-9a-f]{3,4}$/i', $safe_string ) ||
			preg_match( '/^#[0-9a-f]{6}$/i', $safe_string ) ||
			preg_match( '/^#[0-9a-f]{8}$/i', $safe_string ) ||
			preg_match( '/^(?:rgba?|hsla?)\(\s*[-\d.%\s,\/]+\)$/i', $safe_string ) ||
			preg_match( '/^[a-z-]+$/i', $safe_string )
		) {
			return $safe_string;
		}

		return null;
	}

	private static function validate_font_style_value( mixed $value ): ?string {
		$safe_string = self::validate_safe_scalar_string_value( $value );

		if ( null === $safe_string ) {
			return null;
		}

		$normalized = strtolower( $safe_string );
		$allowed    = [
			'normal',
			'italic',
			'oblique',
		];

		return in_array( $normalized, $allowed, true ) ? $normalized : null;
	}

	private static function validate_font_weight_value( mixed $value ): mixed {
		if ( is_int( $value ) ) {
			return $value >= 1 && $value <= 1000 ? $value : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed    = trim( $value );
		$normalized = strtolower( $trimmed );
		$allowed    = [
			'normal',
			'bold',
			'bolder',
			'lighter',
		];

		if ( in_array( $normalized, $allowed, true ) ) {
			return $normalized;
		}

		if ( preg_match( '/^\d{1,4}$/', $trimmed ) ) {
			$weight = (int) $trimmed;

			return $weight >= 1 && $weight <= 1000 ? $trimmed : null;
		}

		return null;
	}

	private static function validate_letter_spacing_value( mixed $value ): mixed {
		if ( is_string( $value ) && 'normal' === strtolower( trim( $value ) ) ) {
			return 'normal';
		}

		return self::validate_signed_length_value( $value );
	}

	private static function validate_border_style_value( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$normalized = strtolower( trim( $value ) );
		$allowed    = [
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

		return in_array( $normalized, $allowed, true ) ? $normalized : null;
	}

	private static function validate_text_decoration_value( mixed $value ): ?string {
		$safe_string = self::validate_safe_scalar_string_value( $value );

		if ( null === $safe_string ) {
			return null;
		}

		$normalized = strtolower( $safe_string );

		if ( 'none' === $normalized ) {
			return 'none';
		}

		$tokens  = preg_split( '/\s+/', $normalized );
		$tokens  = is_array( $tokens ) ? $tokens : [];
		$allowed = [
			'underline'    => true,
			'overline'     => true,
			'line-through' => true,
		];

		foreach ( $tokens as $token ) {
			if ( '' === $token || ! isset( $allowed[ $token ] ) ) {
				return null;
			}
		}

		if ( count( $tokens ) !== count( array_unique( $tokens ) ) ) {
			return null;
		}

		return implode( ' ', $tokens );
	}

	private static function validate_text_transform_value( mixed $value ): ?string {
		$safe_string = self::validate_safe_scalar_string_value( $value );

		if ( null === $safe_string ) {
			return null;
		}

		$normalized = strtolower( $safe_string );
		$allowed    = [
			'none',
			'capitalize',
			'uppercase',
			'lowercase',
			'full-width',
			'full-size-kana',
		];

		return in_array( $normalized, $allowed, true ) ? $normalized : null;
	}

	private static function validate_signed_length_value( mixed $value ): mixed {
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 === $value || 0.0 === $value ? $value : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return null;
		}

		$suffix  = implode( '|', self::CSS_LENGTH_UNITS );
		$pattern = '/^-?0(?:\.0+)?(?:(' . $suffix . '))?$|^-?(?:\d+|\d*\.\d+)(?:(' . $suffix . '))$/i';

		return 1 === preg_match( $pattern, $trimmed ) && 0.0 !== (float) $trimmed
			? $trimmed
			: ( 0 === (float) $trimmed && 1 === preg_match( $pattern, $trimmed ) ? $trimmed : null );
	}

	private static function is_valid_css_length( string $value, bool $allow_percentage, bool $allow_zero ): bool {
		$suffix = implode( '|', self::CSS_LENGTH_UNITS );

		if ( $allow_percentage ) {
			$suffix .= '|%';
		}

		$pattern = $allow_zero
			? '/^0(?:\.0+)?(?:(' . $suffix . '))?$|^(?:\d+|\d*\.\d+)(?:(' . $suffix . '))$/i'
			: '/^(?:\d+|\d*\.\d+)(?:(' . $suffix . '))$/i';

		return 1 === preg_match( $pattern, $value );
	}

	private static function validate_safe_scalar_string_value( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed || self::looks_like_css_payload( $trimmed ) ) {
			return null;
		}

		$sanitized = sanitize_text_field( $trimmed );

		return '' !== $sanitized ? $sanitized : null;
	}

	private static function sanitize_scalar_value( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			$trimmed = sanitize_text_field( $value );

			return '' !== $trimmed ? $trimmed : null;
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}

		return null;
	}

	private static function normalize_attribute_updates_payload( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return [];
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed || '{}' === $trimmed ) {
			return [];
		}

		$decoded = json_decode( $trimmed, true );

		if (
			JSON_ERROR_NONE !== json_last_error()
			|| ! is_array( $decoded )
			|| self::is_list_array( $decoded )
		) {
			return [];
		}

		return $decoded;
	}

	private static function sanitize_optional_display_value( mixed $value ): mixed {
		$sanitized = self::sanitize_display_value( $value );

		return '' === $sanitized ? null : $sanitized;
	}

	private static function sanitize_optional_number( mixed $value ): ?float {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return null;
		}

		$number = (float) $value;

		return $number > 0.0 ? $number : null;
	}

	private static function sanitize_optional_text_value( mixed $value, bool $sanitize_key = false ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$sanitized = $sanitize_key ? sanitize_key( $value ) : sanitize_text_field( $value );

		return '' !== $sanitized ? $sanitized : null;
	}

	private static function validate_suggestions( array $suggestions, string $group ): array {
		$valid = [];
		$order = 0;
		foreach ( $suggestions as $s ) {
			if ( ! is_array( $s ) || empty( $s['label'] ) ) {
				continue;
			}

			$type                   = isset( $s['type'] ) ? sanitize_key( $s['type'] ) : null;
			$raw_attribute_updates  = self::normalize_attribute_updates_payload( $s['attributeUpdates'] ?? [] );
			$has_executable_updates = [] !== $raw_attribute_updates;
			$attribute_updates      = self::sanitize_attribute_updates( $raw_attribute_updates );
			$is_advisory_block_type = 'block' === $group && self::is_advisory_only_block_type( $type );
			$confidence             = self::sanitize_optional_number( $s['confidence'] ?? null );
			$preview                = self::sanitize_optional_text_value( $s['preview'] ?? null );
			$preset_slug            = self::sanitize_optional_text_value( $s['presetSlug'] ?? null, true );
			$css_var                = self::sanitize_optional_text_value( $s['cssVar'] ?? null );

			if ( $is_advisory_block_type ) {
				$has_executable_updates = false;
				$attribute_updates      = [];
			} else {
				$attribute_updates = self::filter_unsafe_attribute_updates( $attribute_updates )['value'];
			}

			if ( $has_executable_updates && ( ! is_array( $attribute_updates ) || [] === $attribute_updates ) ) {
				continue;
			}

			if ( 'block' !== $group && ( ! is_array( $attribute_updates ) || [] === $attribute_updates ) ) {
				continue;
			}

			$normalized = [
				'label'       => sanitize_text_field( $s['label'] ),
				'description' => sanitize_text_field( $s['description'] ?? '' ),
			];

			if ( 'block' === $group ) {
				if ( array_key_exists( 'panel', $s ) ) {
					$normalized['panel'] = self::normalize_optional_panel_key( $s['panel'] ?? '' );
				}
			} else {
				$normalized['panel'] = self::normalize_panel_key( $s['panel'] ?? 'general' );
			}

			$normalized += [
				'type'             => $type,
				'attributeUpdates' => is_array( $attribute_updates ) ? $attribute_updates : [],
				'currentValue'     => self::sanitize_optional_display_value( $s['currentValue'] ?? null ),
				'suggestedValue'   => self::sanitize_optional_display_value( $s['suggestedValue'] ?? null ),
				'isCurrentStyle'   => isset( $s['isCurrentStyle'] ) ? (bool) $s['isCurrentStyle'] : null,
				'isRecommended'    => isset( $s['isRecommended'] ) ? (bool) $s['isRecommended'] : null,
				'confidence'       => $confidence,
				'preview'          => $preview,
				'presetSlug'       => $preset_slug,
				'cssVar'           => $css_var,
			];

			if ( 'block' === $group ) {
				$normalized['operations']         = [];
				$normalized['proposedOperations'] = self::sanitize_block_operation_proposals( $s['operations'] ?? [] );
				$normalized['rejectedOperations'] = [];
			}

			$has_executable_updates = ! empty( $normalized['attributeUpdates'] );
			$ranking_input          = is_array( $s['ranking'] ?? null ) ? $s['ranking'] : [];
			$computed_score         = RankingContract::resolve_score_candidate(
				$ranking_input['score'] ?? null,
				$s['score'] ?? null,
				$ranking_input['confidence'] ?? null,
				$confidence
			);
			if ( null === $computed_score ) {
				$computed_score = RankingContract::derive_score(
					0.45,
					[
						'has_executable_updates' => $has_executable_updates ? 0.25 : 0.0,
						'has_description'        => '' !== $normalized['description'] ? 0.15 : 0.0,
						'has_type'               => null !== $type && '' !== $type ? 0.05 : 0.0,
						'has_preview'            => null !== $normalized['preview'] ? 0.05 : 0.0,
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

			if ( array_key_exists( 'ranking', $s ) || null !== $confidence || isset( $s['score'] ) ) {
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

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function sanitize_block_operation_proposals( mixed $operations ): array {
		if ( ! is_array( $operations ) ) {
			return [];
		}

		$proposals = [];

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$proposal = [];

			foreach ( [ 'type', 'patternName', 'targetClientId', 'position', 'action', 'targetSignature', 'targetSurface', 'targetType' ] as $field ) {
				if ( array_key_exists( $field, $operation ) && is_string( $operation[ $field ] ) ) {
					$proposal[ $field ] = sanitize_text_field( $operation[ $field ] );
				}
			}

			if ( isset( $operation['catalogVersion'] ) && is_numeric( $operation['catalogVersion'] ) ) {
				$proposal['catalogVersion'] = (int) $operation['catalogVersion'];
			}

			if ( isset( $operation['expectedTarget'] ) && is_array( $operation['expectedTarget'] ) ) {
				$expected_target = self::sanitize_block_operation_expected_target( $operation['expectedTarget'] );

				if ( ! empty( $expected_target ) ) {
					$proposal['expectedTarget'] = $expected_target;
				}
			}

			if ( ! empty( $proposal ) ) {
				$proposals[] = $proposal;
			}
		}

		return $proposals;
	}

	/**
	 * @param array<string, mixed> $expected_target
	 * @return array<string, mixed>
	 */
	private static function sanitize_block_operation_expected_target( array $expected_target ): array {
		$target = [];

		foreach ( [ 'clientId', 'name', 'label' ] as $field ) {
			if ( isset( $expected_target[ $field ] ) && is_string( $expected_target[ $field ] ) ) {
				$target[ $field ] = sanitize_text_field( $expected_target[ $field ] );
			}
		}

		if ( isset( $expected_target['childCount'] ) && is_numeric( $expected_target['childCount'] ) ) {
			$target['childCount'] = (int) $expected_target['childCount'];
		}

		if ( isset( $expected_target['attributes'] ) && is_array( $expected_target['attributes'] ) ) {
			$target['attributes'] = self::sanitize_attribute_updates( $expected_target['attributes'] );
		}

		return $target;
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
		$suggestion['proposedOperations'] = is_array( $suggestion['proposedOperations'] ?? null )
			? self::sanitize_block_operation_proposals( $suggestion['proposedOperations'] )
			: self::sanitize_block_operation_proposals( $suggestion['operations'] ?? [] );
		$suggestion['operations']         = [];
		$suggestion['rejectedOperations'] = is_array( $suggestion['rejectedOperations'] ?? null )
			? array_values( array_filter( $suggestion['rejectedOperations'], 'is_array' ) )
			: [];

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
			unset( $metadata['bindings'] );
		} else {
			$metadata['bindings'] = $filtered_bindings;
		}

		foreach ( array_keys( $metadata ) as $metadata_key ) {
			if ( ! in_array( $metadata_key, [ 'blockVisibility', 'bindings' ], true ) ) {
				unset( $metadata[ $metadata_key ] );
			}
		}

		if (
			array_key_exists( 'blockVisibility', $metadata ) &&
			false !== $metadata['blockVisibility'] &&
			! is_array( $metadata['blockVisibility'] )
		) {
			unset( $metadata['blockVisibility'] );
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

		return self::sanitize_attribute_update_keys( (array) $block['bindableAttributes'] );
	}

	private static function get_bindable_attribute_keys_from_execution_contract( array $execution_contract ): ?array {
		if ( ! array_key_exists( 'bindableAttributes', $execution_contract ) ) {
			return null;
		}

		return self::sanitize_attribute_update_keys( (array) $execution_contract['bindableAttributes'] );
	}

	/**
	 * @param array<int|string, mixed> $attributes
	 * @return string[]
	 */
	private static function sanitize_attribute_update_keys( array $attributes ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( mixed $attribute ): string => is_string( $attribute )
							? ( self::sanitize_attribute_update_key( $attribute ) ?? '' )
							: '',
						$attributes
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

		if ( in_array( $path[0], [ 'customCSS', 'lock' ], true ) ) {
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

		if ( in_array( $path[0], [ 'customCSS', 'lock' ], true ) ) {
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

	private static function normalize_optional_panel_key( mixed $panel ): string {
		if ( is_string( $panel ) && '' === trim( $panel ) ) {
			return '';
		}

		return self::normalize_panel_key( $panel );
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
