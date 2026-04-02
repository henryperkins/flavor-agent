<?php
/**
 * Template-specific LLM prompt assembly and response parsing.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class TemplatePrompt {

	private const TEMPLATE_OPERATION_ASSIGN         = 'assign_template_part';
	private const TEMPLATE_OPERATION_REPLACE        = 'replace_template_part';
	private const TEMPLATE_OPERATION_INSERT_PATTERN = 'insert_pattern';
	private const TEMPLATE_PLACEMENT_START          = 'start';
	private const TEMPLATE_PLACEMENT_END            = 'end';
	private const TEMPLATE_PLACEMENT_BEFORE_PATH    = 'before_block_path';
	private const TEMPLATE_PLACEMENT_AFTER_PATH     = 'after_block_path';

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
      "operations": [
        {
          "type": "assign_template_part",
          "slug": "part-slug-from-availableParts",
          "area": "target-area"
        },
        {
          "type": "replace_template_part",
          "currentSlug": "currently-assigned-part-slug",
          "slug": "replacement-part-slug-from-availableParts",
          "area": "target-area"
        },
        {
          "type": "insert_pattern",
          "patternName": "pattern/name-from-patterns-list",
          "placement": "before_block_path",
          "targetPath": [1]
        }
      ],
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
- operations[] MUST be the executable source of truth for the suggestion.
- Supported operation types are ONLY: assign_template_part, replace_template_part, insert_pattern.
- assign_template_part MUST include slug and area.
- assign_template_part MUST target an area from the Explicitly Empty Areas list and must not replace an already assigned template part.
- replace_template_part MUST include currentSlug, slug, and area.
- insert_pattern MUST include patternName and placement.
- insert_pattern placement MUST be one of: start, end, before_block_path, after_block_path.
- before_block_path and after_block_path MUST include targetPath.
- Any targetPath used by insert_pattern MUST match a real path from the Current Top-Level Template Structure list.
- replace_template_part.currentSlug MUST be a slug already present in the Assigned Template Parts list.
- templateParts[].slug MUST be a slug that appears in the Available Template Parts list.
- templateParts[].area MUST be an area already present in the assigned template parts or Explicitly Empty Areas list.
- patternSuggestions[] MUST be pattern name values from the Available Patterns list.
- Keep templateParts and patternSuggestions aligned with the operations you return.
- When WordPress Developer Guidance is provided, prefer suggestions that match documented block-theme and template-part practices.
- Prioritize explicitly empty template-part placeholders before replacing existing assignments.
- Respect the theme's design tokens when suggesting patterns.
- If no candidate patterns are available, focus on template part composition and leave patternSuggestions as an empty array.
- Do not invent new template-part areas when no Explicitly Empty Areas are listed.
- Do not propose free-form template rewrites or raw block markup.
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
	public static function build_user( array $context, string $prompt = '', array $docs_guidance = [] ): string {
		$sections = [];

		$type       = (string) ( $context['templateType'] ?? 'unknown' );
		$title      = (string) ( $context['title'] ?? $type );
		$sections[] = "## Template\nType: {$type}\nTitle: {$title}";

		$assigned = is_array( $context['assignedParts'] ?? null ) ? $context['assignedParts'] : [];
		if ( count( $assigned ) > 0 ) {
			$lines      = array_map(
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
			$lines      = array_map(
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

		$top_level_block_tree = is_array( $context['topLevelBlockTree'] ?? null ) ? $context['topLevelBlockTree'] : [];
		if ( count( $top_level_block_tree ) > 0 ) {
			$sections[] = "## Current Top-Level Template Structure\n" . self::format_template_block_tree( $top_level_block_tree );
		}

		$insertion_anchors = is_array( $context['topLevelInsertionAnchors'] ?? null ) ? $context['topLevelInsertionAnchors'] : [];
		if ( count( $insertion_anchors ) > 0 ) {
			$sections[] = "## Executable Pattern Insertion Anchors\n" . self::format_insertion_anchors( $insertion_anchors );
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

		if ( ! empty( $docs_guidance ) ) {
			$lines = [];

			foreach ( array_slice( $docs_guidance, 0, 3 ) as $guidance ) {
				if ( ! is_array( $guidance ) ) {
					continue;
				}

				$summary = self::format_guidance_line( $guidance );

				if ( $summary !== '' ) {
					$lines[] = '- ' . $summary;
				}
			}

			if ( count( $lines ) > 0 ) {
				$sections[] = "## WordPress Developer Guidance\n" . implode( "\n", $lines );
			}
		}

		$instruction = trim( $prompt ) !== ''
			? trim( $prompt )
			: 'Suggest improvements for this template.';
		$sections[]  = "## User Instruction\n{$instruction}";

		return implode( "\n\n", $sections );
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
				[
					'status' => 502,
					'raw'    => substr( $raw, 0, 500 ),
				]
			);
		}

		if ( ! isset( $data['suggestions'] ) || ! is_array( $data['suggestions'] ) ) {
			return new \WP_Error(
				'parse_error',
				'Template recommendation response missing "suggestions" array.',
				[ 'status' => 502 ]
			);
		}

		$unused_part_lookup    = self::build_unused_template_part_lookup( $context );
		$assigned_part_lookup  = self::build_assigned_template_part_lookup( $context );
		$allowed_area_lookup   = self::build_allowed_area_lookup( $context );
		$empty_area_lookup     = self::build_empty_area_lookup( $context );
		$pattern_lookup        = self::build_pattern_lookup( $context );
		$template_block_lookup = self::build_template_block_lookup( $context );

		$suggestions = self::validate_template_suggestions(
			$data['suggestions'],
			$unused_part_lookup,
			$assigned_part_lookup,
			$allowed_area_lookup,
			$empty_area_lookup,
			$pattern_lookup,
			$template_block_lookup
		);

		if ( count( $suggestions ) === 0 ) {
			return new \WP_Error(
				'invalid_recommendations',
				'Template recommendation response contained no actionable suggestions after validation.',
				[
					'status' => 502,
					'raw'    => substr( $raw, 0, 500 ),
				]
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
	 * @param array $assigned_part_lookup Assigned template-part lookup.
	 * @param array $allowed_area_lookup Allowed area lookup.
	 * @param array $empty_area_lookup Explicitly empty area lookup.
	 * @param array $pattern_lookup Candidate pattern lookup.
	 * @param array<string, array<string, mixed>> $template_block_lookup Template top-level block lookup keyed by path.
	 * @return array Sanitized suggestions.
	 */
	private static function validate_template_suggestions(
		array $suggestions,
		array $unused_part_lookup,
		array $assigned_part_lookup,
		array $allowed_area_lookup,
		array $empty_area_lookup,
		array $pattern_lookup,
		array $template_block_lookup
	): array {
		$valid = [];

		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) || empty( $suggestion['label'] ) ) {
				continue;
			}

			$entry = [
				'label'              => sanitize_text_field( (string) $suggestion['label'] ),
				'description'        => sanitize_text_field( (string) ( $suggestion['description'] ?? '' ) ),
				'operations'         => [],
				'templateParts'      => [],
				'patternSuggestions' => [],
			];

			$validated_template_parts      = self::validate_template_part_summaries(
				is_array( $suggestion['templateParts'] ?? null ) ? $suggestion['templateParts'] : [],
				$unused_part_lookup,
				$assigned_part_lookup,
				$empty_area_lookup,
				$allowed_area_lookup
			);
			$validated_pattern_suggestions = self::validate_pattern_summaries(
				is_array( $suggestion['patternSuggestions'] ?? null ) ? $suggestion['patternSuggestions'] : [],
				$pattern_lookup
			);
			$validated_operations_result   = self::validate_template_operations(
				is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [],
				$unused_part_lookup,
				$assigned_part_lookup,
				$allowed_area_lookup,
				$empty_area_lookup,
				$pattern_lookup,
				$template_block_lookup
			);
			$validated_operations          = $validated_operations_result['operations'];

			if ( ! empty( $validated_operations_result['invalid'] ) ) {
				continue;
			}

			if ( count( $validated_operations ) === 0 ) {
				$derived_operations = self::derive_template_operations(
					$validated_template_parts,
					$validated_pattern_suggestions,
					$assigned_part_lookup,
					$empty_area_lookup
				);

				if ( ! empty( $derived_operations['invalid'] ) ) {
					continue;
				}

				$validated_operations = $derived_operations['operations'];
			}

				$entry['operations']         = $validated_operations;
				$entry['templateParts']      = self::summarize_template_parts_from_operations(
					$validated_operations,
					$validated_template_parts
				);
				$entry['patternSuggestions'] = self::summarize_pattern_suggestions_from_operations(
					$validated_operations,
					$validated_pattern_suggestions
				);

			if (
					count( $entry['operations'] ) === 0
					&& count( $entry['templateParts'] ) === 0
					&& count( $entry['patternSuggestions'] ) === 0
				) {
				continue;
			}

			$valid[] = $entry;
		}

		return array_slice( $valid, 0, 3 );
	}

	/**
	 * @param array{bySlug: array<string, array{slug: string, area: string}>, byArea: array<string, array{slug: string, area: string}>} $assigned_part_lookup
	 * @param array<string, true> $empty_area_lookup Explicitly empty area lookup.
	 * @return array{bySlug: array<string, array{slug: string, area: string}>, byArea: array<string, array{slug: string, area: string}>, emptyAreas: array<string, true>, mutatedAreas: array<string, true>, patternInsertCount: int}
	 */
	private static function build_template_operation_state( array $assigned_part_lookup, array $empty_area_lookup ): array {
		return [
			'bySlug'             => is_array( $assigned_part_lookup['bySlug'] ?? null ) ? $assigned_part_lookup['bySlug'] : [],
			'byArea'             => is_array( $assigned_part_lookup['byArea'] ?? null ) ? $assigned_part_lookup['byArea'] : [],
			'emptyAreas'         => $empty_area_lookup,
			'mutatedAreas'       => [],
			'patternInsertCount' => 0,
		];
	}

	/**
	 * @param array $context Template context used to build the prompt.
	 * @return array<string, array{area: string}> Lookup of unused template-part slugs.
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
			$area = sanitize_key( (string) ( $part['area'] ?? '' ) );
			if ( $slug !== '' && ! isset( $assigned_lookup[ $slug ] ) ) {
				$lookup[ $slug ] = [
					'area' => $area,
				];
			}
		}

		return $lookup;
	}

	/**
	 * @param array $context Template context used to build the prompt.
	 * @return array{bySlug: array<string, array{slug: string, area: string}>, byArea: array<string, array{slug: string, area: string}>}
	 */
	private static function build_assigned_template_part_lookup( array $context ): array {
		$by_slug = [];
		$by_area = [];

		foreach ( is_array( $context['assignedParts'] ?? null ) ? $context['assignedParts'] : [] as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $part['slug'] ?? '' ) );
			$area = sanitize_key( (string) ( $part['area'] ?? '' ) );

			if ( $slug === '' ) {
				continue;
			}

			$entry            = [
				'slug' => $slug,
				'area' => $area,
			];
			$by_slug[ $slug ] = $entry;

			if ( $area !== '' && ! isset( $by_area[ $area ] ) ) {
				$by_area[ $area ] = $entry;
			}
		}

		return [
			'bySlug' => $by_slug,
			'byArea' => $by_area,
		];
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
	 * @return array<string, true> Lookup of explicitly empty template-part areas.
	 */
	private static function build_empty_area_lookup( array $context ): array {
		$lookup = [];

		foreach ( is_array( $context['emptyAreas'] ?? null ) ? $context['emptyAreas'] : [] as $area ) {
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

	/**
	 * @param array $context Template context used to build the prompt.
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_template_block_lookup( array $context ): array {
		$lookup = [];

		foreach ( is_array( $context['topLevelBlockTree'] ?? null ) ? $context['topLevelBlockTree'] : [] as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $node['path'] ?? null );

			if ( $path === null ) {
				continue;
			}

			$lookup[ self::block_path_key( $path ) ] = [
				'name'       => sanitize_text_field( (string) ( $node['name'] ?? '' ) ),
				'label'      => sanitize_text_field( (string) ( $node['label'] ?? '' ) ),
				'path'       => $path,
				'attributes' => is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [],
				'childCount' => isset( $node['childCount'] ) ? (int) $node['childCount'] : 0,
				'slot'       => is_array( $node['slot'] ?? null ) ? $node['slot'] : [],
			];
		}

		return $lookup;
	}

	/**
	 * @param array<string, mixed> $target_node
	 * @return array<string, mixed>
	 */
	private static function build_expected_target( array $target_node ): array {
		$expected = [
			'name'       => sanitize_text_field( (string) ( $target_node['name'] ?? '' ) ),
			'label'      => sanitize_text_field( (string) ( $target_node['label'] ?? '' ) ),
			'attributes' => is_array( $target_node['attributes'] ?? null ) ? $target_node['attributes'] : [],
			'childCount' => isset( $target_node['childCount'] ) ? (int) $target_node['childCount'] : 0,
		];

		$slot = is_array( $target_node['slot'] ?? null ) ? $target_node['slot'] : [];
		if ( count( $slot ) > 0 ) {
			$expected['slot'] = [
				'slug'    => sanitize_key( (string) ( $slot['slug'] ?? '' ) ),
				'area'    => sanitize_key( (string) ( $slot['area'] ?? '' ) ),
				'isEmpty' => ! empty( $slot['isEmpty'] ),
			];
		}

		return $expected;
	}

	/**
	 * @param array $template_parts Raw template-part summaries.
	 * @param array $unused_part_lookup Unused template-part lookup.
	 * @param array $assigned_part_lookup Assigned template-part lookup.
	 * @param array $empty_area_lookup Explicitly empty area lookup.
	 * @param array $allowed_area_lookup Allowed area lookup.
	 * @return array<int, array{slug: string, area: string, reason: string}>
	 */
	private static function validate_template_part_summaries(
		array $template_parts,
		array $unused_part_lookup,
		array $assigned_part_lookup,
		array $empty_area_lookup,
		array $allowed_area_lookup
	): array {
		$valid = [];
		$seen  = [];

		foreach ( $template_parts as $template_part ) {
			if ( ! is_array( $template_part ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $template_part['slug'] ?? '' ) );
			$area = sanitize_key( (string) ( $template_part['area'] ?? '' ) );

			if (
				! self::is_valid_template_part_summary(
					$slug,
					$area,
					$unused_part_lookup,
					$assigned_part_lookup,
					$empty_area_lookup,
					$allowed_area_lookup
				)
			) {
				continue;
			}

			$key = "{$slug}|{$area}";

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$valid[]      = [
				'slug'   => $slug,
				'area'   => $area,
				'reason' => sanitize_text_field( (string) ( $template_part['reason'] ?? '' ) ),
			];
		}

		return $valid;
	}

	/**
	 * @param array $pattern_suggestions Raw pattern suggestion list.
	 * @param array $pattern_lookup Candidate pattern lookup.
	 * @return string[]
	 */
	private static function validate_pattern_summaries( array $pattern_suggestions, array $pattern_lookup ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $name ) use ( $pattern_lookup ): string {
							$sanitized = sanitize_text_field( (string) $name );

							return isset( $pattern_lookup[ $sanitized ] ) ? $sanitized : '';
						},
						$pattern_suggestions
					),
					static fn( string $name ): bool => $name !== ''
				)
			)
		);
	}

	/**
	 * @param array $operations Raw operations from the LLM.
	 * @param array $unused_part_lookup Unused template-part lookup.
	 * @param array $assigned_part_lookup Assigned template-part lookup.
	 * @param array $allowed_area_lookup Allowed area lookup.
	 * @param array $empty_area_lookup Explicitly empty area lookup.
	 * @param array $pattern_lookup Candidate pattern lookup.
	 * @param array<string, array<string, mixed>> $template_block_lookup Template top-level block lookup.
	 * @return array{operations: array<int, array<string, mixed>>, invalid: bool}
	 */
	private static function validate_template_operations(
		array $operations,
		array $unused_part_lookup,
		array $assigned_part_lookup,
		array $allowed_area_lookup,
		array $empty_area_lookup,
		array $pattern_lookup,
		array $template_block_lookup
	): array {
		$valid = [];
		$state = self::build_template_operation_state( $assigned_part_lookup, $empty_area_lookup );

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $operation['type'] ?? '' ) );

			switch ( $type ) {
				case self::TEMPLATE_OPERATION_ASSIGN:
					$slug = sanitize_key( (string) ( $operation['slug'] ?? '' ) );
					$area = sanitize_key( (string) ( $operation['area'] ?? '' ) );

					if ( isset( $state['mutatedAreas'][ $area ] ) ) {
						return [
							'operations' => [],
							'invalid'    => true,
						];
					}

					if (
						! self::is_valid_unused_template_part( $slug, $area, $unused_part_lookup, $allowed_area_lookup )
						|| ! isset( $state['emptyAreas'][ $area ] )
						|| isset( $state['byArea'][ $area ] )
					) {
						continue 2;
					}

					$valid[]                        = [
						'type' => $type,
						'slug' => $slug,
						'area' => $area,
					];
					$state['byArea'][ $area ]       = [
						'slug' => $slug,
						'area' => $area,
					];
					$state['bySlug'][ $slug ]       = $state['byArea'][ $area ];
					$state['mutatedAreas'][ $area ] = true;
					unset( $state['emptyAreas'][ $area ] );
					break;

				case self::TEMPLATE_OPERATION_REPLACE:
					$slug         = sanitize_key( (string) ( $operation['slug'] ?? '' ) );
					$area         = sanitize_key( (string) ( $operation['area'] ?? '' ) );
					$current_slug = sanitize_key(
						(string) ( $operation['currentSlug'] ?? $operation['fromSlug'] ?? '' )
					);

					if ( $current_slug !== '' ) {
						$assigned_part = $state['bySlug'][ $current_slug ] ?? null;
					} else {
						$assigned_part = $state['byArea'][ $area ] ?? null;
						$current_slug  = sanitize_key( (string) ( $assigned_part['slug'] ?? '' ) );
					}

					if ( ! is_array( $assigned_part ) || $current_slug === '' ) {
						continue 2;
					}

					if ( $area === '' ) {
						$area = sanitize_key( (string) ( $assigned_part['area'] ?? '' ) );
					}

					if ( $area === '' ) {
						continue 2;
					}

					if ( isset( $state['mutatedAreas'][ $area ] ) ) {
						return [
							'operations' => [],
							'invalid'    => true,
						];
					}

					if ( ! self::is_valid_unused_template_part( $slug, $area, $unused_part_lookup, $allowed_area_lookup ) ) {
						continue 2;
					}

					if (
						sanitize_key( (string) ( $assigned_part['area'] ?? '' ) ) !== ''
						&& sanitize_key( (string) ( $assigned_part['area'] ?? '' ) ) !== $area
					) {
						continue 2;
					}

					if ( $current_slug === $slug ) {
						continue 2;
					}

					$valid[] = [
						'type'        => $type,
						'currentSlug' => $current_slug,
						'slug'        => $slug,
						'area'        => $area,
					];
					unset( $state['bySlug'][ $current_slug ] );
					$state['byArea'][ $area ]       = [
						'slug' => $slug,
						'area' => $area,
					];
					$state['bySlug'][ $slug ]       = $state['byArea'][ $area ];
					$state['mutatedAreas'][ $area ] = true;
					unset( $state['emptyAreas'][ $area ] );
					break;

				case self::TEMPLATE_OPERATION_INSERT_PATTERN:
					$pattern_name = sanitize_text_field(
						(string) ( $operation['patternName'] ?? $operation['name'] ?? '' )
					);
					$has_target_path = array_key_exists( 'targetPath', $operation );
					$placement    = sanitize_key( (string) ( $operation['placement'] ?? '' ) );
					$target_path  = self::sanitize_block_path( $operation['targetPath'] ?? null );

					if (
						$pattern_name === ''
						|| $placement === ''
						|| ! isset( $pattern_lookup[ $pattern_name ] )
					) {
						continue 2;
					}

					$allowed_placements = [
						self::TEMPLATE_PLACEMENT_START => true,
						self::TEMPLATE_PLACEMENT_END   => true,
						self::TEMPLATE_PLACEMENT_BEFORE_PATH => true,
						self::TEMPLATE_PLACEMENT_AFTER_PATH => true,
					];

					if ( $placement !== '' && ! isset( $allowed_placements[ $placement ] ) ) {
						continue 2;
					}

					if ( $has_target_path && $target_path === null ) {
						continue 2;
					}

					if (
						in_array( $placement, [ self::TEMPLATE_PLACEMENT_BEFORE_PATH, self::TEMPLATE_PLACEMENT_AFTER_PATH ], true ) &&
						(
							$target_path === null ||
							! isset( $template_block_lookup[ self::block_path_key( $target_path ) ] )
						)
					) {
						continue 2;
					}

					if ( $state['patternInsertCount'] > 0 ) {
						return [
							'operations' => [],
							'invalid'    => true,
						];
					}

					$normalized = [
						'type'        => $type,
						'patternName' => $pattern_name,
					];

					if ( $placement !== '' ) {
						$normalized['placement'] = $placement;
					}

					if (
						$target_path !== null &&
						in_array( $placement, [ self::TEMPLATE_PLACEMENT_BEFORE_PATH, self::TEMPLATE_PLACEMENT_AFTER_PATH ], true )
					) {
						$normalized['targetPath']     = $target_path;
						$normalized['expectedTarget'] = self::build_expected_target(
							$template_block_lookup[ self::block_path_key( $target_path ) ]
						);
					}

					$valid[] = $normalized;
					++$state['patternInsertCount'];
					break;
			}
		}

		return [
			'operations' => array_slice( $valid, 0, 4 ),
			'invalid'    => false,
		];
	}

	/**
	 * @param array<int, array{slug: string, area: string, reason: string}> $template_parts
	 * @param string[] $pattern_suggestions
	 * @param array $assigned_part_lookup Assigned template-part lookup.
	 * @param array<string, true> $empty_area_lookup Explicitly empty area lookup.
	 * @return array{operations: array<int, array<string, string>>, invalid: bool}
	 */
	private static function derive_template_operations(
		array $template_parts,
		array $pattern_suggestions,
		array $assigned_part_lookup,
		array $empty_area_lookup
	): array {
		unset( $pattern_suggestions );

		$operations = [];
		$seen_areas = [];

		foreach ( $template_parts as $template_part ) {
			$area          = sanitize_key( (string) ( $template_part['area'] ?? '' ) );
			$slug          = sanitize_key( (string) ( $template_part['slug'] ?? '' ) );
			$assigned_part = $area !== '' ? ( $assigned_part_lookup['byArea'][ $area ] ?? null ) : null;
			$current_slug  = sanitize_key( (string) ( $assigned_part['slug'] ?? '' ) );

			if ( $slug === '' || $area === '' ) {
				continue;
			}

			if ( isset( $seen_areas[ $area ] ) ) {
				return [
					'operations' => [],
					'invalid'    => true,
				];
			}

			$seen_areas[ $area ] = true;

			if ( $current_slug !== '' && $current_slug !== $slug ) {
				$operations[] = [
					'type'        => self::TEMPLATE_OPERATION_REPLACE,
					'currentSlug' => $current_slug,
					'slug'        => $slug,
					'area'        => $area,
				];
				continue;
			}

			if ( $current_slug === '' && isset( $empty_area_lookup[ $area ] ) ) {
				$operations[] = [
					'type' => self::TEMPLATE_OPERATION_ASSIGN,
					'slug' => $slug,
					'area' => $area,
				];
			}
		}

		return [
			'operations' => array_slice( $operations, 0, 4 ),
			'invalid'    => false,
		];
	}

	/**
	 * @param array<int, array<string, string>> $operations
	 * @param array<int, array{slug: string, area: string, reason: string}> $validated_template_parts
	 * @return array<int, array{slug: string, area: string, reason: string}>
	 */
	private static function summarize_template_parts_from_operations(
		array $operations,
		array $validated_template_parts
	): array {
		$reason_lookup = [];
		$summaries     = [];

		foreach ( $validated_template_parts as $template_part ) {
			$key                   = sprintf(
				'%s|%s',
				(string) ( $template_part['slug'] ?? '' ),
				(string) ( $template_part['area'] ?? '' )
			);
			$reason_lookup[ $key ] = sanitize_text_field( (string) ( $template_part['reason'] ?? '' ) );
		}

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $operation['type'] ?? '' ) );

			if (
				self::TEMPLATE_OPERATION_ASSIGN !== $type
				&& self::TEMPLATE_OPERATION_REPLACE !== $type
			) {
				continue;
			}

			$slug = sanitize_key( (string) ( $operation['slug'] ?? '' ) );
			$area = sanitize_key( (string) ( $operation['area'] ?? '' ) );
			$key  = "{$slug}|{$area}";

			if ( $slug === '' || $area === '' || isset( $summaries[ $key ] ) ) {
				continue;
			}

			$summaries[ $key ] = [
				'slug'   => $slug,
				'area'   => $area,
				'reason' => $reason_lookup[ $key ] ?? '',
			];
		}

		return array_values( $summaries );
	}

	/**
	 * @param array<int, array<string, string>> $operations
	 * @param string[] $pattern_suggestions
	 * @return string[]
	 */
	private static function summarize_pattern_suggestions_from_operations( array $operations, array $pattern_suggestions = [] ): array {
		$summaries = [];

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			if ( sanitize_key( (string) ( $operation['type'] ?? '' ) ) !== self::TEMPLATE_OPERATION_INSERT_PATTERN ) {
				continue;
			}

			$pattern_name = sanitize_text_field( (string) ( $operation['patternName'] ?? '' ) );

			if ( $pattern_name !== '' ) {
				$summaries[ $pattern_name ] = $pattern_name;
			}
		}

		foreach ( $pattern_suggestions as $pattern_name ) {
			$pattern_name = sanitize_text_field( (string) $pattern_name );

			if ( '' !== $pattern_name ) {
				$summaries[ $pattern_name ] = $pattern_name;
			}
		}

		return array_values( $summaries );
	}

	/**
	 * @param mixed $path
	 * @return int[]|null
	 */
	private static function sanitize_block_path( mixed $path ): ?array {
		if ( ! is_array( $path ) || count( $path ) === 0 ) {
			return null;
		}

		$normalized = [];

		foreach ( $path as $segment ) {
			if ( ! is_int( $segment ) && ! is_numeric( $segment ) ) {
				return null;
			}

			$segment = (int) $segment;

			if ( $segment < 0 ) {
				return null;
			}

			$normalized[] = $segment;
		}

		return $normalized;
	}

	/**
	 * @param int[] $path
	 */
	private static function block_path_key( array $path ): string {
		return implode( '.', $path );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 */
	private static function format_template_block_tree( array $tree ): string {
		$lines = [];

		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path        = self::sanitize_block_path( $node['path'] ?? null ) ?? [];
			$path_string = '[' . implode( ', ', $path ) . ']';
			$name        = sanitize_text_field( (string) ( $node['name'] ?? 'unknown' ) );
			$label       = sanitize_text_field( (string) ( $node['label'] ?? '' ) );
			$attributes  = is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [];
			$attr_suffix = '';

			if ( count( $attributes ) > 0 ) {
				$pairs = [];

				foreach ( $attributes as $key => $value ) {
					$display = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
					$pairs[] = "{$key}={$display}";
				}

				$attr_suffix = ' {' . implode( ', ', $pairs ) . '}';
			}

			$line = "- {$path_string} {$name}{$attr_suffix}";

			if ( $label !== '' ) {
				$line .= " - {$label}";
			}

			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<int, array<string, mixed>> $anchors
	 */
	private static function format_insertion_anchors( array $anchors ): string {
		$lines = [];

		foreach ( $anchors as $anchor ) {
			if ( ! is_array( $anchor ) ) {
				continue;
			}

			$placement = sanitize_key( (string) ( $anchor['placement'] ?? '' ) );
			$label     = sanitize_text_field( (string) ( $anchor['label'] ?? '' ) );
			$path      = self::sanitize_block_path( $anchor['targetPath'] ?? null );
			$line      = '- ' . ( $label !== '' ? $label : $placement );

			if ( $placement !== '' ) {
				$line .= " (`{$placement}`)";
			}

			if ( $path !== null ) {
				$line .= ' -> [' . implode( ', ', $path ) . ']';
			}

			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<string, array{area: string}> $unused_part_lookup
	 * @param array<string, true> $allowed_area_lookup
	 */
	private static function is_valid_unused_template_part(
		string $slug,
		string $area,
		array $unused_part_lookup,
		array $allowed_area_lookup
	): bool {
		if (
			$slug === ''
			|| $area === ''
			|| ! isset( $unused_part_lookup[ $slug ] )
			|| ! isset( $allowed_area_lookup[ $area ] )
		) {
			return false;
		}

		$part_area = sanitize_key( (string) ( $unused_part_lookup[ $slug ]['area'] ?? '' ) );

		if ( $part_area !== '' && $part_area !== 'uncategorized' && $part_area !== $area ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string, array{area: string}> $unused_part_lookup
	 * @param array{byArea?: array<string, array{slug: string, area: string}>} $assigned_part_lookup
	 * @param array<string, true> $empty_area_lookup
	 * @param array<string, true> $allowed_area_lookup
	 */
	private static function is_valid_template_part_summary(
		string $slug,
		string $area,
		array $unused_part_lookup,
		array $assigned_part_lookup,
		array $empty_area_lookup,
		array $allowed_area_lookup
	): bool {
		if ( ! self::is_valid_unused_template_part( $slug, $area, $unused_part_lookup, $allowed_area_lookup ) ) {
			return false;
		}

		return isset( $empty_area_lookup[ $area ] ) || isset( $assigned_part_lookup['byArea'][ $area ] );
	}
}
