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

You receive a block's current state, its available Inspector panels (what it supports), and the active theme's design tokens (colors, fonts, spacing, shadows).

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
  "attributeUpdates": { "attributeName": "value" },
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
- If the block already has good values, return fewer or no suggestions.
- Return 2-6 suggestions total. Prioritize high-impact visual improvements.
- For style objects in attributeUpdates, use the nested style format:
  { "style": { "color": { "background": "var(--wp--preset--color--accent)" } } }
  or preset attributes like { "backgroundColor": "accent" }.
SYSTEM;
    }

    /**
     * Build the user prompt from editor context.
     */
    public static function build_user( array $context, string $prompt = '' ): string {
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

        if ( ! empty( $block['styles'] ) ) {
            $parts[] = 'Style variations: ' . wp_json_encode( $block['styles'] );
        }

        if ( ! empty( $block['activeStyle'] ) ) {
            $parts[] = 'Active style: ' . $block['activeStyle'];
        }

        if ( ! empty( $block['editingMode'] ) && $block['editingMode'] !== 'default' ) {
            $parts[] = 'Editing mode: ' . $block['editingMode'];
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

        if ( ! empty( $prompt ) ) {
            $parts[] = '';
            $parts[] = '## User instruction';
            $parts[] = $prompt;
        }

        return implode( "\n", $parts );
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
                [ 'status' => 500, 'raw' => substr( $raw, 0, 500 ) ]
            );
        }

        return [
            'settings'    => self::validate_suggestions( $data['settings'] ?? [] ),
            'styles'      => self::validate_suggestions( $data['styles'] ?? [] ),
            'block'       => self::validate_suggestions( $data['block'] ?? [] ),
            'explanation' => sanitize_text_field( $data['explanation'] ?? '' ),
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
                'attributeUpdates' => self::sanitize_attribute_updates( $s['attributeUpdates'] ?? [] ),
                'confidence'       => isset( $s['confidence'] ) ? (float) $s['confidence'] : null,
                'preview'          => isset( $s['preview'] ) ? sanitize_text_field( $s['preview'] ) : null,
                'presetSlug'       => isset( $s['presetSlug'] ) ? sanitize_key( $s['presetSlug'] ) : null,
                'cssVar'           => isset( $s['cssVar'] ) ? sanitize_text_field( $s['cssVar'] ) : null,
            ];
        }
        return $valid;
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
            $out = [];
            foreach ( $data as $key => $value ) {
                $out[ sanitize_key( $key ) ] = self::sanitize_attribute_updates( $value, $depth + 1 );
            }
            return $out;
        }
        if ( is_bool( $data ) || is_int( $data ) || is_float( $data ) || is_null( $data ) ) {
            return $data;
        }
        return null;
    }
}
