<?php
/**
 * Template-specific LLM prompt assembly and response parsing.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class TemplatePrompt {

	/**
	 * Build the system prompt for template recommendations.
	 */
	public static function build_system(): string {
		return <<<'SYSTEM'
You are a WordPress template composition advisor. Given a template type, its currently assigned template parts, explicitly empty template-part placeholders, available template parts, candidate patterns, and theme design tokens, suggest how to improve the template's structure.

Return ONLY a JSON object with this exact shape. Do not use markdown fences or add any text outside the JSON object:

{
  "suggestions": [
    {
      "label": "Short title for this suggestion",
      "description": "Why this improves the template",
      "templateParts": [
        {
          "slug": "part-slug-from-availableParts",
          "area": "target-area",
          "reason": "Why this part fits this area"
        }
      ],
      "patternSuggestions": ["pattern/name-from-patterns-list"]
    }
  ],
  "explanation": "Overall reasoning for these recommendations"
}

Rules:
- templateParts[].slug MUST be a slug that appears in the Available Template Parts list.
- templateParts[].area MUST be an area already present in the assigned template parts or Explicitly Empty Areas list.
- patternSuggestions[] MUST be pattern name values from the Available Patterns list.
- Prioritize explicitly empty template-part placeholders before replacing existing assignments.
- Respect the theme's design tokens when suggesting patterns.
- If no candidate patterns are available, focus on template part composition and leave patternSuggestions as an empty array.
- Do not invent new template-part areas when no Explicitly Empty Areas are listed.
- Return 1-3 suggestions. Each should be distinct and actionable.
- Keep labels under 60 characters. Keep descriptions under 200 characters.
SYSTEM;
	}

	/**
	 * Build the user prompt from template context.
	 *
	 * @param array  $context Template context from ServerCollector::for_template().
	 * @param string $prompt  Optional user instruction.
	 */
	public static function build_user( array $context, string $prompt = '' ): string {
		$sections = [];

		$type     = (string) ( $context['templateType'] ?? 'unknown' );
		$title    = (string) ( $context['title'] ?? $type );
		$sections[] = "## Template\nType: {$type}\nTitle: {$title}";

		$assigned = is_array( $context['assignedParts'] ?? null ) ? $context['assignedParts'] : [];
		if ( count( $assigned ) > 0 ) {
			$lines = array_map(
				static fn( array $part ): string => sprintf(
					'- `%s` -> area: `%s`',
					(string) ( $part['slug'] ?? '' ),
					(string) ( $part['area'] ?? '' )
				),
				$assigned
			);
			$sections[] = "## Assigned Template Parts\n" . implode( "\n", $lines );
		} else {
			$sections[] = "## Assigned Template Parts\nNone - this template has no template parts assigned.";
		}

		$empty_areas = is_array( $context['emptyAreas'] ?? null ) ? $context['emptyAreas'] : [];
		$sections[]  = count( $empty_areas ) > 0
			? "## Explicitly Empty Areas\n" . implode( ', ', $empty_areas )
			: "## Explicitly Empty Areas\nNone detected.";

		$assigned_slugs = array_column( $assigned, 'slug' );
		$available      = array_values(
			array_filter(
				is_array( $context['availableParts'] ?? null ) ? $context['availableParts'] : [],
				static fn( array $part ): bool => ! in_array( $part['slug'] ?? '', $assigned_slugs, true )
			)
		);
		if ( count( $available ) > 0 ) {
			$lines = array_map(
				static fn( array $part ): string => sprintf(
					'- `%s` - %s (area: %s)',
					(string) ( $part['slug'] ?? '' ),
					(string) ( $part['title'] ?? '' ),
					(string) ( $part['area'] ?? '' )
				),
				$available
			);
			$sections[] = "## Available Template Parts\n" . implode( "\n", $lines );
		} else {
			$sections[] = "## Available Template Parts\nNo unused template parts available.";
		}

		$patterns = is_array( $context['patterns'] ?? null ) ? $context['patterns'] : [];
		if ( count( $patterns ) > 0 ) {
			$max   = 30;
			$shown = array_slice( $patterns, 0, $max );
			$lines = array_map(
				static function ( array $pattern ): string {
					$name        = (string) ( $pattern['name'] ?? '' );
					$title       = (string) ( $pattern['title'] ?? '' );
					$description = (string) ( $pattern['description'] ?? '' );
					$match_type  = (string) ( $pattern['matchType'] ?? '' );
					$match_label = '';

					if ( $match_type === 'typed' ) {
						$match_label = ' [typed match]';
					} elseif ( $match_type === 'generic' ) {
						$match_label = ' [generic fallback]';
					}

					$line = "- `{$name}`{$match_label}";
					if ( $title !== '' ) {
						$line .= " - {$title}";
					}
					if ( $description !== '' ) {
						$line .= ": {$description}";
					}

					return $line;
				},
				$shown
			);

			$header = "## Available Patterns\n";
			if ( count( $patterns ) > $max ) {
				$header .= 'Showing ' . $max . ' of ' . count( $patterns ) . " patterns.\n";
			}

			$sections[] = $header . implode( "\n", $lines );
		} else {
			$sections[] = "## Available Patterns\nNo patterns available for this template type.";
		}

		$tokens = is_array( $context['themeTokens'] ?? null ) ? $context['themeTokens'] : [];
		if ( ! empty( $tokens ) ) {
			$token_lines = [];
			if ( ! empty( $tokens['colors'] ) ) {
				$token_lines[] = 'Colors: ' . implode( ', ', array_slice( (array) $tokens['colors'], 0, 12 ) );
			}
			if ( ! empty( $tokens['fontFamilies'] ) ) {
				$token_lines[] = 'Fonts: ' . implode( ', ', (array) $tokens['fontFamilies'] );
			}
			if ( ! empty( $tokens['fontSizes'] ) ) {
				$token_lines[] = 'Font sizes: ' . implode( ', ', (array) $tokens['fontSizes'] );
			}
			if ( ! empty( $tokens['spacing'] ) ) {
				$token_lines[] = 'Spacing scale: ' . implode( ', ', array_slice( (array) $tokens['spacing'], 0, 7 ) );
			}
			if ( count( $token_lines ) > 0 ) {
				$sections[] = "## Theme Tokens\n" . implode( "\n", $token_lines );
			}
		}

		$instruction = trim( $prompt ) !== ''
			? trim( $prompt )
			: 'Suggest improvements for this template.';
		$sections[] = "## User Instruction\n{$instruction}";

		return implode( "\n\n", $sections );
	}

	/**
	 * Parse and validate an LLM response for template recommendations.
	 *
	 * @param string $raw     Raw LLM response text.
	 * @param array  $context Template context used to build the prompt.
	 * @return array|\WP_Error Validated payload or error.
	 */
	public static function parse_response( string $raw, array $context ): array|\WP_Error {
		$cleaned = preg_replace( '/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim( $raw ) );
		$data    = json_decode( is_string( $cleaned ) ? $cleaned : '', true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'parse_error',
				'Failed to parse template recommendation response as JSON: ' . json_last_error_msg(),
				[ 'status' => 502, 'raw' => substr( $raw, 0, 500 ) ]
			);
		}

		if ( ! isset( $data['suggestions'] ) || ! is_array( $data['suggestions'] ) ) {
			return new \WP_Error(
				'parse_error',
				'Template recommendation response missing "suggestions" array.',
				[ 'status' => 502 ]
			);
		}

		$suggestions = self::validate_template_suggestions(
			$data['suggestions'],
			self::build_unused_template_part_lookup( $context ),
			self::build_allowed_area_lookup( $context ),
			self::build_pattern_lookup( $context )
		);

		if ( count( $suggestions ) === 0 ) {
			return new \WP_Error(
				'invalid_recommendations',
				'Template recommendation response contained no actionable suggestions after validation.',
				[ 'status' => 502, 'raw' => substr( $raw, 0, 500 ) ]
			);
		}

		return [
			'suggestions' => $suggestions,
			'explanation' => sanitize_text_field( (string) ( $data['explanation'] ?? '' ) ),
		];
	}

	/**
	 * @param array $suggestions        Raw suggestion array from the LLM.
	 * @param array $unused_part_lookup Unused template-part slug lookup.
	 * @param array $allowed_area_lookup Allowed area lookup.
	 * @param array $pattern_lookup Candidate pattern lookup.
	 * @return array Sanitized suggestions.
	 */
	private static function validate_template_suggestions(
		array $suggestions,
		array $unused_part_lookup,
		array $allowed_area_lookup,
		array $pattern_lookup
	): array {
		$valid = [];

		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) || empty( $suggestion['label'] ) ) {
				continue;
			}

			$entry = [
				'label'       => sanitize_text_field( (string) $suggestion['label'] ),
				'description' => sanitize_text_field( (string) ( $suggestion['description'] ?? '' ) ),
				'templateParts' => [],
				'patternSuggestions' => [],
			];

			if ( isset( $suggestion['templateParts'] ) && is_array( $suggestion['templateParts'] ) ) {
				$seen_template_parts = [];

				foreach ( $suggestion['templateParts'] as $template_part ) {
					if ( ! is_array( $template_part ) || empty( $template_part['slug'] ) || empty( $template_part['area'] ) ) {
						continue;
					}

					$slug = sanitize_key( (string) $template_part['slug'] );
					$area = sanitize_key( (string) $template_part['area'] );

					if (
						$slug === ''
						|| $area === ''
						|| ! isset( $unused_part_lookup[ $slug ] )
						|| ! isset( $allowed_area_lookup[ $area ] )
					) {
						continue;
					}

					$key = "{$slug}|{$area}";
					if ( isset( $seen_template_parts[ $key ] ) ) {
						continue;
					}
					$seen_template_parts[ $key ] = true;

					$entry['templateParts'][] = [
						'slug'   => $slug,
						'area'   => $area,
						'reason' => sanitize_text_field( (string) ( $template_part['reason'] ?? '' ) ),
					];
				}
			}

			if ( isset( $suggestion['patternSuggestions'] ) && is_array( $suggestion['patternSuggestions'] ) ) {
				$entry['patternSuggestions'] = array_values(
					array_unique(
						array_filter(
							array_map(
								static function ( $name ) use ( $pattern_lookup ): string {
									$sanitized = sanitize_text_field( (string) $name );

									return isset( $pattern_lookup[ $sanitized ] ) ? $sanitized : '';
								},
								$suggestion['patternSuggestions']
							),
							static fn( string $name ): bool => $name !== ''
						)
					)
				);
			}

			if (
				count( $entry['templateParts'] ) === 0
				&& count( $entry['patternSuggestions'] ) === 0
			) {
				continue;
			}

			$valid[] = $entry;
		}

		return array_slice( $valid, 0, 3 );
	}

	/**
	 * @param array $context Template context used to build the prompt.
	 * @return array<string, true> Lookup of unused template-part slugs.
	 */
	private static function build_unused_template_part_lookup( array $context ): array {
		$assigned_lookup = [];

		foreach ( is_array( $context['assignedParts'] ?? null ) ? $context['assignedParts'] : [] as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $part['slug'] ?? '' ) );
			if ( $slug !== '' ) {
				$assigned_lookup[ $slug ] = true;
			}
		}

		$lookup = [];
		foreach ( is_array( $context['availableParts'] ?? null ) ? $context['availableParts'] : [] as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $part['slug'] ?? '' ) );
			if ( $slug !== '' && ! isset( $assigned_lookup[ $slug ] ) ) {
				$lookup[ $slug ] = true;
			}
		}

		return $lookup;
	}

	/**
	 * @param array $context Template context used to build the prompt.
	 * @return array<string, true> Lookup of allowed template-part areas.
	 */
	private static function build_allowed_area_lookup( array $context ): array {
		$areas = is_array( $context['allowedAreas'] ?? null ) ? $context['allowedAreas'] : [];

		if ( count( $areas ) === 0 ) {
			foreach ( is_array( $context['assignedParts'] ?? null ) ? $context['assignedParts'] : [] as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}

				$areas[] = (string) ( $part['area'] ?? '' );
			}
			foreach ( is_array( $context['emptyAreas'] ?? null ) ? $context['emptyAreas'] : [] as $area ) {
				$areas[] = (string) $area;
			}
		}

		$lookup = [];
		foreach ( $areas as $area ) {
			$sanitized = sanitize_key( (string) $area );
			if ( $sanitized !== '' ) {
				$lookup[ $sanitized ] = true;
			}
		}

		return $lookup;
	}

	/**
	 * @param array $context Template context used to build the prompt.
	 * @return array<string, true> Lookup of candidate pattern names.
	 */
	private static function build_pattern_lookup( array $context ): array {
		$lookup = [];

		foreach ( is_array( $context['patterns'] ?? null ) ? $context['patterns'] : [] as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $pattern['name'] ?? '' ) );
			if ( $name !== '' ) {
				$lookup[ $name ] = true;
			}
		}

		return $lookup;
	}
}
