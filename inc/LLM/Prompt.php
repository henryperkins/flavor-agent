<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class Prompt {

	/**
	 * Build the system prompt that instructs the LLM how to respond.
	 */
	public static function build_system(): string {
		return <<<'SYSTEM'
You are a WordPress Gutenberg block styling and configuration assistant.

You receive a block's current state, its available Inspector panels (what it supports), its resolved structural identity and surrounding branch context, and the active theme's design tokens (colors, fonts, spacing, shadows).

Your job: suggest specific, actionable attribute changes that improve the block's appearance and configuration. Every suggestion must use the theme's actual preset slugs and CSS custom properties — never raw hex codes or pixel values unless the theme has no presets.

Respond with a JSON object (no markdown fences, no explanation outside the JSON):

{
  "settings": [],
  "styles": [],
  "block": [],
  "explanation": "One sentence summary of your recommendations."
}

Each item in settings/styles/block is an object:
{
  "label": "Human-readable name (e.g. 'Use theme accent background')",
  "description": "Why this helps (one sentence)",
  "panel": "Which Inspector panel: general|layout|position|advanced|color|typography|dimensions|border|shadow|background",
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

Rules:
- "settings" array: suggestions for the Settings tab (layout, alignment, position, advanced, general config).
- "styles" array: suggestions for the Appearance tab (color, typography, dimensions, border, shadow, background, style variations).
- "block" array: block-level suggestions (style variation changes, structural recommendations).
- Only suggest changes for panels listed in the block's inspectorPanels.
- Only suggest preset values that exist in the provided themeTokens.
- When WordPress Developer Guidance is provided, prefer recommendations that align with that guidance and avoid contradicting documented Gutenberg capabilities or theme.json standards.
- When structural identity is provided, treat it as the block's job on this page. Distinguish role and location from raw block name alone (for example, header navigation vs footer navigation, main query vs sidebar query).
- If the block already has good values, return fewer or no suggestions.
- Return 2-6 suggestions total. Prioritize high-impact visual improvements.
- If the block is inside a contentOnly container, only suggest changes to content attributes (role=content). Do not suggest style or settings changes — those panels are locked.
- You may suggest viewport visibility rules: { "metadata": { "blockVisibility": { "viewport": { "mobile": false } } } } to show/hide the block on specific devices.
- If theme pseudo-class styles (:hover, :focus, :active, :focus-visible) are provided for a block, use them when suggesting interactive state styles.
- For style objects in attributeUpdates, use the nested style format:
  { "style": { "color": { "background": "var(--wp--preset--color--accent)" } } }
  or preset attributes like { "backgroundColor": "accent" }.
- When a block supports both aspect ratio and explicit height, never suggest setting both in the same recommendation. Choose aspectRatio or height, not both.
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

		if ( ! empty( $block['inspectorPanels'] ) ) {
			$parts[] = 'Available panels: ' . wp_json_encode( $block['inspectorPanels'] );
		}

		if ( ! empty( $block['currentAttributes'] ) ) {
			$parts[] = 'Current attributes: ' . wp_json_encode( $block['currentAttributes'] );
		}

		if ( ! empty( $block['supportsContentRole'] ) ) {
			$parts[] = 'Block declares supports.contentRole: true';
		}

		if ( ! empty( $block['styles'] ) ) {
			$parts[] = 'Style variations: ' . wp_json_encode( $block['styles'] );
		}

		if ( ! empty( $block['activeStyle'] ) ) {
			$parts[] = 'Active style: ' . $block['activeStyle'];
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
		}

		if ( array_key_exists( 'blockVisibility', $block ) && null !== $block['blockVisibility'] ) {
			$parts[] = 'Block visibility: ' . wp_json_encode( $block['blockVisibility'] );
		}

		$parts[] = '';
		$parts[] = '## Theme Tokens';

		if ( ! empty( $tokens['colors'] ) ) {
			$parts[] = 'Colors: ' . implode( ', ', array_slice( (array) $tokens['colors'], 0, 20 ) );
		}

		if ( ! empty( $tokens['fontSizes'] ) ) {
			$parts[] = 'Font sizes: ' . implode( ', ', (array) $tokens['fontSizes'] );
		}

		if ( ! empty( $tokens['fontFamilies'] ) ) {
			$parts[] = 'Font families: ' . implode( ', ', (array) $tokens['fontFamilies'] );
		}

		if ( ! empty( $tokens['spacing'] ) ) {
			$parts[] = 'Spacing: ' . implode( ', ', (array) $tokens['spacing'] );
		}

		if ( ! empty( $tokens['shadows'] ) ) {
			$parts[] = 'Shadows: ' . implode( ', ', (array) $tokens['shadows'] );
		}

		if ( ! empty( $tokens['layout'] ) ) {
			$parts[] = 'Layout: ' . wp_json_encode( $tokens['layout'] );
		}

		if ( ! empty( $tokens['blockPseudoStyles'] ) ) {
			$parts[] = 'Block pseudo-class styles (hover/focus/active): ' . wp_json_encode( $tokens['blockPseudoStyles'] );
		}

		if ( ! empty( $context['siblingsBefore'] ) || ! empty( $context['siblingsAfter'] ) ) {
			$parts[] = '';
			$parts[] = '## Surrounding blocks';
			if ( ! empty( $context['siblingsBefore'] ) ) {
				$parts[] = 'Before: ' . implode( ', ', (array) $context['siblingsBefore'] );
			}
			if ( ! empty( $context['siblingsAfter'] ) ) {
				$parts[] = 'After: ' . implode( ', ', (array) $context['siblingsAfter'] );
			}
		}

		if ( ! empty( $context['structuralAncestors'] ) ) {
			$parts[] = '';
			$parts[] = '## Structural ancestors';
			$parts[] = wp_json_encode( $context['structuralAncestors'] );
		}

		if ( ! empty( $context['structuralBranch'] ) ) {
			$parts[] = '';
			$parts[] = '## Structural branch';
			$parts[] = wp_json_encode( $context['structuralBranch'] );
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

	/**
	 * @param array<string, mixed> $guidance
	 */
	private static function format_guidance_line( array $guidance ): string {
		$prefix = sanitize_text_field( (string) ( $guidance['title'] ?? '' ) );

		if ( $prefix === '' ) {
			$prefix = sanitize_text_field( (string) ( $guidance['sourceKey'] ?? '' ) );
		}

		$excerpt = sanitize_textarea_field( (string) ( $guidance['excerpt'] ?? '' ) );

		if ( $excerpt === '' ) {
			return '';
		}

		return $prefix !== '' ? "{$prefix}: {$excerpt}" : $excerpt;
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
			'settings'    => self::validate_suggestions( $data['settings'] ?? [] ),
			'styles'      => self::validate_suggestions( $data['styles'] ?? [] ),
			'block'       => self::validate_suggestions( $data['block'] ?? [] ),
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

		if ( ! $restrictions['contentOnly'] ) {
			return $payload;
		}

		$content_attribute_keys = array_keys( $block['contentAttributes'] ?? [] );

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

	private static function validate_suggestions( array $suggestions ): array {
		$valid = [];
		foreach ( $suggestions as $s ) {
			if ( ! is_array( $s ) || empty( $s['label'] ) ) {
				continue;
			}
			$valid[] = [
				'label'            => sanitize_text_field( $s['label'] ),
				'description'      => sanitize_text_field( $s['description'] ?? '' ),
				'panel'            => sanitize_key( $s['panel'] ?? 'general' ),
				'type'             => isset( $s['type'] ) ? sanitize_key( $s['type'] ) : null,
				'attributeUpdates' => self::sanitize_attribute_updates( $s['attributeUpdates'] ?? [] ),
				'currentValue'     => self::sanitize_display_value( $s['currentValue'] ?? null ),
				'suggestedValue'   => self::sanitize_display_value( $s['suggestedValue'] ?? null ),
				'isCurrentStyle'   => isset( $s['isCurrentStyle'] ) ? (bool) $s['isCurrentStyle'] : null,
				'isRecommended'    => isset( $s['isRecommended'] ) ? (bool) $s['isRecommended'] : null,
				'confidence'       => isset( $s['confidence'] ) ? (float) $s['confidence'] : null,
				'preview'          => isset( $s['preview'] ) ? sanitize_text_field( $s['preview'] ) : null,
				'presetSlug'       => isset( $s['presetSlug'] ) ? sanitize_key( $s['presetSlug'] ) : null,
				'cssVar'           => isset( $s['cssVar'] ) ? sanitize_text_field( $s['cssVar'] ) : null,
			];
		}
		return $valid;
	}

	private static function filter_suggestion_for_content_only( array $suggestion, array $content_attribute_keys ): ?array {
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

	private static function get_block_restrictions( array $block ): array {
		$editing_mode = self::normalize_editing_mode( $block['editingMode'] ?? 'default' );

		return [
			'disabled'    => $editing_mode === 'disabled',
			'contentOnly' => ! empty( $block['isInsideContentOnly'] ) || $editing_mode === 'contentOnly',
		];
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

	private static function sanitize_display_value( mixed $data ): mixed {
		return self::sanitize_attribute_updates( $data );
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
