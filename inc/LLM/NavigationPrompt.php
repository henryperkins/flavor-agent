<?php
/**
 * Navigation-specific LLM prompt assembly and response parsing.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class NavigationPrompt {

	/**
	 * Build the system prompt for navigation recommendations.
	 */
	public static function build_system(): string {
		return <<<'SYSTEM'
You are a WordPress navigation structure advisor. Given a navigation block's menu items, overlay configuration, location context, and theme design tokens, suggest how to improve the navigation's structure and behavior.

Return ONLY a JSON object with this exact shape. Do not use markdown fences or add any text outside the JSON object:

{
  "suggestions": [
    {
      "label": "Short title for this suggestion",
      "description": "Why this improves the navigation",
      "category": "structure|overlay|accessibility",
      "changes": [
        {
          "type": "reorder|group|ungroup|add-submenu|flatten|set-attribute",
          "target": "Description of what to change",
          "detail": "Specific recommendation"
        }
      ]
    }
  ],
  "explanation": "Overall reasoning for these recommendations"
}

Rules:
- category MUST be one of: structure, overlay, accessibility.
- changes[].type MUST be one of: reorder, group, ungroup, add-submenu, flatten, set-attribute.
- set-attribute changes should reference real core/navigation block attributes (overlayMenu, openSubmenusOnClick, hasIcon, icon, maxNestingLevel, showSubmenuIcon).
- In WordPress 7.0+, navigation overlays are a first-class template-part area (navigation-overlay). When overlay template parts exist, prefer referencing them over suggesting inline overlay configuration.
- Do not suggest adding menu items that do not exist in the current structure. Suggest reorganization of what is already there.
- When WordPress Developer Guidance is provided, prefer suggestions that match documented navigation block practices.
- Respect the theme's design tokens when suggesting visual changes.
- Return 1-3 suggestions. Each should be distinct and actionable.
- Keep labels under 60 characters. Keep descriptions under 200 characters.
SYSTEM;
	}

	/**
	 * Build the user prompt from navigation context.
	 *
	 * @param array  $context Navigation context from ServerCollector::for_navigation().
	 * @param string $prompt  Optional user instruction.
	 * @param array  $docs_guidance WordPress docs grounding chunks.
	 */
	public static function build_user( array $context, string $prompt = '', array $docs_guidance = [] ): string {
		$sections = [];

		// Navigation identity.
		$location   = (string) ( $context['location'] ?? 'unknown' );
		$sections[] = "## Navigation\nLocation: {$location}";

		// Current attributes.
		$attrs = is_array( $context['attributes'] ?? null ) ? $context['attributes'] : [];
		if ( count( $attrs ) > 0 ) {
			$lines = [];
			foreach ( $attrs as $key => $value ) {
				$display = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
				$lines[] = "- `{$key}`: {$display}";
			}
			$sections[] = "## Current Attributes\n" . implode( "\n", $lines );
		}

		// Menu structure.
		$items = is_array( $context['menuItems'] ?? null ) ? $context['menuItems'] : [];
		if ( count( $items ) > 0 ) {
			$sections[] = "## Menu Structure\n" . self::format_menu_items( $items, 0 );
		} else {
			$sections[] = "## Menu Structure\nNo menu items found. The navigation block may use a Page List or be empty.";
		}

		// Overlay template parts (WP 7.0+).
		$overlay_parts = is_array( $context['overlayTemplateParts'] ?? null ) ? $context['overlayTemplateParts'] : [];
		if ( count( $overlay_parts ) > 0 ) {
			$lines      = array_map(
				static fn( array $part ): string => sprintf(
					'- `%s`: %s',
					(string) ( $part['slug'] ?? '' ),
					(string) ( $part['title'] ?? '' )
				),
				$overlay_parts
			);
			$sections[] = "## Navigation Overlay Template Parts (WP 7.0+)\n" . implode( "\n", $lines );
		}

		// Theme tokens.
		$tokens      = is_array( $context['themeTokens'] ?? null ) ? $context['themeTokens'] : [];
		$token_lines = self::format_theme_tokens( $tokens );
		if ( $token_lines !== '' ) {
			$sections[] = "## Theme Design Tokens\n{$token_lines}";
		}

		// WordPress docs guidance.
		if ( count( $docs_guidance ) > 0 ) {
			$guidance_lines = array_map(
				static function ( array $chunk ): string {
					$title   = (string) ( $chunk['title'] ?? '' );
					$excerpt = (string) ( $chunk['excerpt'] ?? '' );
					$prefix  = $title !== '' ? "{$title}: " : '';
					return "- {$prefix}{$excerpt}";
				},
				$docs_guidance
			);
			$sections[]     = "## WordPress Developer Guidance\n" . implode( "\n", $guidance_lines );
		}

		// User prompt.
		if ( $prompt !== '' ) {
			$sections[] = "## User Instruction\n{$prompt}";
		}

		return implode( "\n\n", $sections );
	}

	/**
	 * Parse and validate the LLM response against the navigation context.
	 *
	 * @param string $raw_response Raw LLM text output.
	 * @param array  $context      Navigation context used to build the prompt.
	 * @return array Validated suggestions payload.
	 */
	public static function parse_response( string $raw_response, array $context ): array {
		$cleaned = preg_replace( '/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim( $raw_response ) );
		$data    = json_decode( $cleaned, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return [
				'suggestions' => [],
				'explanation' => '',
			];
		}

		$explanation = isset( $data['explanation'] ) && is_string( $data['explanation'] )
			? sanitize_text_field( substr( $data['explanation'], 0, 500 ) )
			: '';

		$suggestions = isset( $data['suggestions'] ) && is_array( $data['suggestions'] )
			? $data['suggestions']
			: [];

		$valid = self::validate_suggestions( $suggestions );

		return [
			'suggestions' => array_slice( $valid, 0, 3 ),
			'explanation' => $explanation,
		];
	}

	/**
	 * Validate and sanitize individual suggestions.
	 *
	 * @param array $suggestions Raw suggestions from LLM.
	 * @return array Validated suggestions.
	 */
	private static function validate_suggestions( array $suggestions ): array {
		$allowed_categories = [ 'structure', 'overlay', 'accessibility' ];
		$allowed_types      = [ 'reorder', 'group', 'ungroup', 'add-submenu', 'flatten', 'set-attribute' ];
		$valid              = [];

		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}

			$label = isset( $suggestion['label'] ) && is_string( $suggestion['label'] )
				? sanitize_text_field( substr( $suggestion['label'], 0, 60 ) )
				: '';

			$description = isset( $suggestion['description'] ) && is_string( $suggestion['description'] )
				? sanitize_text_field( substr( $suggestion['description'], 0, 200 ) )
				: '';

			$category = isset( $suggestion['category'] ) && is_string( $suggestion['category'] )
				? sanitize_key( $suggestion['category'] )
				: '';

			if ( $label === '' || $description === '' ) {
				continue;
			}

			if ( ! in_array( $category, $allowed_categories, true ) ) {
				$category = 'structure';
			}

			$changes = [];
			if ( isset( $suggestion['changes'] ) && is_array( $suggestion['changes'] ) ) {
				foreach ( $suggestion['changes'] as $change ) {
					if ( ! is_array( $change ) ) {
						continue;
					}

					$type = isset( $change['type'] ) && is_string( $change['type'] )
						? sanitize_key( $change['type'] )
						: '';

					if ( ! in_array( $type, $allowed_types, true ) ) {
						continue;
					}

					$changes[] = [
						'type'   => $type,
						'target' => sanitize_text_field( (string) ( $change['target'] ?? '' ) ),
						'detail' => sanitize_text_field( (string) ( $change['detail'] ?? '' ) ),
					];
				}
			}

			if ( count( $changes ) === 0 ) {
				continue;
			}

			$valid[] = [
				'label'       => $label,
				'description' => $description,
				'category'    => $category,
				'changes'     => $changes,
			];
		}

		return $valid;
	}

	/**
	 * Format menu items as indented text for the prompt.
	 */
	private static function format_menu_items( array $items, int $depth ): string {
		$lines  = [];
		$indent = str_repeat( '  ', $depth );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type  = (string) ( $item['type'] ?? 'unknown' );
			$label = (string) ( $item['label'] ?? '' );
			$url   = (string) ( $item['url'] ?? '' );

			$line = "{$indent}- [{$type}]";
			if ( $label !== '' ) {
				$line .= " \"{$label}\"";
			}
			if ( $url !== '' ) {
				$line .= " -> {$url}";
			}

			$lines[] = $line;

			$children = is_array( $item['children'] ?? null ) ? $item['children'] : [];
			if ( count( $children ) > 0 ) {
				$lines[] = self::format_menu_items( $children, $depth + 1 );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format theme tokens into a compact string for the prompt.
	 */
	private static function format_theme_tokens( array $tokens ): string {
		$parts = [];

		$string_keys = [ 'colors', 'fontSizes', 'fontFamilies', 'spacing' ];
		foreach ( $string_keys as $key ) {
			$values = $tokens[ $key ] ?? [];
			if ( is_array( $values ) && count( $values ) > 0 ) {
				$label   = ucfirst( (string) preg_replace( '/([A-Z])/', ' $1', $key ) );
				$parts[] = "{$label}: " . implode( ', ', array_slice( $values, 0, 8 ) );
			}
		}

		return implode( "\n", $parts );
	}
}
