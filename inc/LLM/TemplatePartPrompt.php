<?php
/**
 * Template-part-specific LLM prompt assembly and response parsing.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\Support\FormatsDocsGuidance;
use FlavorAgent\Support\RankingContract;

final class TemplatePartPrompt {

	use FormatsDocsGuidance;

	private const MAX_PROMPT_PATTERNS = 30;

	/**
	 * Build the system prompt for template-part recommendations.
	 */
	public static function build_system(): string {
		return <<<'SYSTEM'
You are a WordPress template-part composition advisor. Given a single template part's area, block-tree structure, candidate patterns, theme design tokens, and WordPress guidance, suggest how to improve that one template part.

Return ONLY a JSON object with this exact shape. Do not use markdown fences or add any text outside the JSON object:

{
  "suggestions": [
    {
      "label": "Short title for this suggestion",
      "description": "Why this improves the template part",
      "blockHints": [
        {
          "path": [0, 1],
          "label": "Navigation block",
          "reason": "Why this block is the right place to focus"
        }
      ],
      "patternSuggestions": ["pattern/name-from-patterns-list"],
      "operations": [
        {
          "type": "insert_pattern",
          "patternName": "pattern/name-from-patterns-list",
          "placement": "before_block_path",
          "targetPath": [0, 1]
        }
      ]
    }
  ],
  "explanation": "Overall reasoning for these recommendations"
}

Rules:
- blockHints[].path MUST be a real path from the Current Block Tree.
- blockHints[].label should be short and human-readable.
- patternSuggestions[] MUST be pattern name values from the Available Patterns list.
- operations[] is optional and advisory-first. Only return it when the change is fully safe and deterministic.
- operations[] may contain at most 3 entries.
- Supported operation types are:
  - `insert_pattern`
  - `replace_block_with_pattern`
  - `remove_block`
- operations[].patternName MUST be a pattern name from the Available Patterns list.
- `insert_pattern` placements MUST be one of:
  - `start`
  - `end`
  - `before_block_path`
  - `after_block_path`
- `before_block_path` and `after_block_path` MUST include `targetPath`.
- Any path-based operation SHOULD include `expectedTarget` with the live block fingerprint from the executable target list.
- `replace_block_with_pattern` MUST include `targetPath`, `expectedBlockName`, and `patternName`.
- `remove_block` MUST include `targetPath` and `expectedBlockName`.
- Every executable `targetPath` MUST resolve to a real path from the Executable Operation Targets or Executable Insertion Anchors lists.
- `expectedBlockName` MUST match the real block at `targetPath`.
- If Structural Constraints mention `contentOnly` or locked paths, keep those ideas advisory unless the path is explicitly listed as executable.
- Keep patternSuggestions aligned with any executable operations you return.
- Keep recommendations advisory-first. Do not output raw block markup or free-form rewritten block trees.
- Use blockHints to point at the most relevant places in the current structure when specific focus areas exist.
- When WordPress Developer Guidance is provided, prefer suggestions that match documented block-theme and template-part practices.
- Respect the theme's design tokens when suggesting patterns or structural changes.
- Treat enabledFeatures and layout in Theme Tokens as hard capability constraints.
- When a recommendation depends on color, spacing, typography, border, background, or layout controls, do not recommend patterns, operations, or attribute changes that rely on disabled features or unsupported layout capabilities.
- When Current Pattern Override Blocks are listed, treat them as intentional override boundaries. Prefer suggestions that preserve or work with those customizable blocks, and call out the tradeoff if you suggest replacing around them.
- When multiple operations are returned, keep the plan small, explicit, and ordered.
- If no matching patterns are available, leave patternSuggestions as an empty array.
- Do not invent Pattern Overrides when none are listed.
- Return 1-3 suggestions. Each should be distinct and actionable.
- Keep labels under 60 characters. Keep descriptions under 200 characters.
SYSTEM;
	}

	/**
	 * Build the user prompt from template-part context.
	 *
	 * @param array  $context Template-part context from ServerCollector::for_template_part().
	 * @param string $prompt  Optional user instruction.
	 * @param array  $docs_guidance WordPress docs grounding chunks.
	 */
	public static function build_user( array $context, string $prompt = '', array $docs_guidance = [] ): string {
		$sections = [];

		$ref   = (string) ( $context['templatePartRef'] ?? 'unknown' );
		$slug  = (string) ( $context['slug'] ?? '' );
		$title = (string) ( $context['title'] ?? $slug );
		$area  = (string) ( $context['area'] ?? 'uncategorized' );

		$sections[] = "## Template Part\nRef: {$ref}\nSlug: {$slug}\nTitle: {$title}\nArea: {$area}";

		$top_level_blocks = is_array( $context['topLevelBlocks'] ?? null ) ? $context['topLevelBlocks'] : [];
		if ( count( $top_level_blocks ) > 0 ) {
			$sections[] = '## Top-Level Blocks' . "\n" . implode( ', ', $top_level_blocks );
		}

		$structure_stats = is_array( $context['structureStats'] ?? null ) ? $context['structureStats'] : [];
		$block_counts    = is_array( $context['blockCounts'] ?? null ) ? $context['blockCounts'] : [];
		$structure_lines = [];

		if ( isset( $structure_stats['blockCount'] ) ) {
			$structure_lines[] = 'Block count: ' . (int) $structure_stats['blockCount'];
		}

		if ( isset( $structure_stats['maxDepth'] ) ) {
			$structure_lines[] = 'Max depth: ' . (int) $structure_stats['maxDepth'];
		}

		if ( ! empty( $structure_stats['firstTopLevelBlock'] ) ) {
			$structure_lines[] = 'First top-level block: ' . (string) $structure_stats['firstTopLevelBlock'];
		}

		if ( ! empty( $structure_stats['lastTopLevelBlock'] ) ) {
			$structure_lines[] = 'Last top-level block: ' . (string) $structure_stats['lastTopLevelBlock'];
		}

		if ( array_key_exists( 'hasSingleWrapperGroup', $structure_stats ) ) {
			$structure_lines[] = 'Single wrapper group: ' . ( $structure_stats['hasSingleWrapperGroup'] ? 'yes' : 'no' );
		}

		if ( array_key_exists( 'isNearlyEmpty', $structure_stats ) ) {
			$structure_lines[] = 'Nearly empty: ' . ( $structure_stats['isNearlyEmpty'] ? 'yes' : 'no' );
		}

		foreach (
			[
				'hasNavigation',
				'containsLogo',
				'containsSiteTitle',
				'containsSearch',
				'containsSocialLinks',
				'containsQuery',
				'containsColumns',
				'containsButtons',
				'containsSpacer',
				'containsSeparator',
			] as $flag
		) {
			if ( array_key_exists( $flag, $structure_stats ) ) {
				$structure_lines[] = $flag . ': ' . ( $structure_stats[ $flag ] ? 'yes' : 'no' );
			}
		}

		if ( ! empty( $block_counts ) ) {
			$formatted_counts = [];

			foreach ( $block_counts as $block_name => $count ) {
				$formatted_counts[] = "{$block_name} × " . (int) $count;
			}

			$structure_lines[] = 'Block counts: ' . implode( ', ', array_slice( $formatted_counts, 0, 10 ) );
		}

		if ( count( $structure_lines ) > 0 ) {
			$sections[] = "## Structure Summary\n" . implode( "\n", $structure_lines );
		}

		$block_tree = is_array( $context['blockTree'] ?? null ) ? $context['blockTree'] : [];
		if ( count( $block_tree ) > 0 ) {
			$sections[] = "## Current Block Tree\n" . self::format_block_tree( $block_tree );
		} else {
			$sections[] = "## Current Block Tree\nThis template part is empty.";
		}

		$operation_targets = is_array( $context['operationTargets'] ?? null ) ? $context['operationTargets'] : [];
		if ( count( $operation_targets ) > 0 ) {
			$sections[] = "## Executable Operation Targets\n" . self::format_operation_targets( $operation_targets );
		}

		$insertion_anchors = is_array( $context['insertionAnchors'] ?? null ) ? $context['insertionAnchors'] : [];
		if ( count( $insertion_anchors ) > 0 ) {
			$sections[] = "## Executable Insertion Anchors\n" . self::format_insertion_anchors( $insertion_anchors );
		}

		$structural_constraints = is_array( $context['structuralConstraints'] ?? null ) ? $context['structuralConstraints'] : [];
		$formatted_constraints  = self::format_structural_constraints( $structural_constraints );
		if ( $formatted_constraints !== '' ) {
			$sections[] = "## Structural Constraints\n{$formatted_constraints}";
		}

		$current_pattern_overrides = is_array( $context['currentPatternOverrides'] ?? null )
			? $context['currentPatternOverrides']
			: [];
		$sections[]                = "## Current Pattern Override Blocks\n"
			. self::format_current_pattern_overrides( $current_pattern_overrides );

		$patterns = is_array( $context['patterns'] ?? null ) ? $context['patterns'] : [];
		if ( count( $patterns ) > 0 ) {
			$max   = self::MAX_PROMPT_PATTERNS;
			$shown = array_slice( $patterns, 0, $max );
			$lines = array_map(
				static function ( array $pattern ): string {
					$name        = (string) ( $pattern['name'] ?? '' );
					$title       = (string) ( $pattern['title'] ?? '' );
					$description = (string) ( $pattern['description'] ?? '' );
					$match_type  = (string) ( $pattern['matchType'] ?? '' );
					$line        = "- `{$name}`";

					if ( $title !== '' ) {
						$line .= " - {$title}";
					}

					if ( $match_type !== '' ) {
						$line .= " [{$match_type}]";
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
			$sections[] = "## Available Patterns\nNo area-relevant patterns are available.";
		}

		$theme_tokens = ThemeTokenFormatter::format(
			is_array( $context['themeTokens'] ?? null ) ? $context['themeTokens'] : []
		);
		if ( $theme_tokens !== '' ) {
			$sections[] = "## Theme Tokens\n{$theme_tokens}";
		}

		if ( count( $docs_guidance ) > 0 ) {
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
			: 'Suggest improvements for this template part.';
		$sections[]  = "## User Instruction\n{$instruction}";

		return implode( "\n\n", $sections );
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	private static function format_current_pattern_overrides( array $summary ): string {
		$blocks = is_array( $summary['blocks'] ?? null ) ? $summary['blocks'] : [];

		if ( [] === $blocks ) {
			return 'None detected.';
		}

		$lines = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$label               = sanitize_text_field( (string) ( $block['label'] ?? ( $block['name'] ?? 'Block' ) ) );
			$name                = sanitize_text_field( (string) ( $block['name'] ?? '' ) );
			$path                = self::format_block_path_label( $block['path'] ?? null );
			$override_attributes = array_values(
				array_filter(
					array_map(
						static fn( $attribute ): string => sanitize_text_field( (string) $attribute ),
						is_array( $block['overrideAttributes'] ?? null ) ? $block['overrideAttributes'] : []
					),
					static fn( string $attribute ): bool => '' !== $attribute
				)
			);
			$details             = [];

			if ( [] !== $override_attributes ) {
				$details[] = 'overridable attributes: `' . implode( '`, `', $override_attributes ) . '`';
			}

			if ( ! empty( $block['usesDefaultBinding'] ) ) {
				$details[] = 'uses default binding expansion';
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

	private static function format_block_path_label( mixed $path ): string {
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
	 * Parse and validate an LLM response for template-part recommendations.
	 *
	 * @param string $raw     Raw LLM response text.
	 * @param array  $context Template-part context used to build the prompt.
	 * @return array|\WP_Error Validated payload or error.
	 */
	public static function parse_response( string $raw, array $context ): array|\WP_Error {
		$cleaned = preg_replace( '/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim( $raw ) );
		$data    = json_decode( is_string( $cleaned ) ? $cleaned : '', true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'parse_error',
				'Failed to parse template-part recommendation response as JSON: ' . json_last_error_msg(),
				[
					'status' => 502,
					'raw'    => substr( $raw, 0, 500 ),
				]
			);
		}

		if ( ! isset( $data['suggestions'] ) || ! is_array( $data['suggestions'] ) ) {
			return new \WP_Error(
				'parse_error',
				'Template-part recommendation response missing "suggestions" array.',
				[ 'status' => 502 ]
			);
		}

		$block_lookup            = self::build_block_lookup( $context );
		$operation_target_lookup = self::build_operation_target_lookup( $context );
		$insertion_anchor_lookup = self::build_insertion_anchor_lookup( $context );
		$pattern_lookup          = self::build_pattern_lookup( $context );
		$suggestions             = self::validate_suggestions(
			$data['suggestions'],
			$block_lookup,
			$pattern_lookup,
			$operation_target_lookup,
			$insertion_anchor_lookup
		);

		if ( count( $suggestions ) === 0 ) {
			return new \WP_Error(
				'invalid_recommendations',
				'Template-part recommendation response contained no actionable suggestions after validation.',
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
	 * @param array<string, mixed> $context
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_block_lookup( array $context ): array {
		if ( array_key_exists( 'allBlockPaths', $context ) && is_array( $context['allBlockPaths'] ) ) {
			return self::build_flat_block_lookup( $context['allBlockPaths'] );
		}

		$tree = is_array( $context['blockTree'] ?? null ) ? $context['blockTree'] : [];
		return self::build_tree_block_lookup( $tree );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_tree_block_lookup( array $tree ): array {
		$lookup = [];

		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $node['path'] ?? null );

			if ( $path !== null ) {
				$lookup[ self::block_path_key( $path ) ] = [
					'name'       => (string) ( $node['name'] ?? '' ),
					'path'       => $path,
					'label'      => sanitize_text_field( (string) ( $node['label'] ?? '' ) ),
					'attributes' => is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [],
					'childCount' => isset( $node['childCount'] ) ? (int) $node['childCount'] : 0,
					'slot'       => is_array( $node['slot'] ?? null ) ? $node['slot'] : [],
				];
			}

			$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
			$lookup   = array_merge( $lookup, self::build_tree_block_lookup( $children ) );
		}

		return $lookup;
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_flat_block_lookup( array $nodes ): array {
		$lookup = [];

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $node['path'] ?? null );

			if ( null === $path ) {
				continue;
			}

			$lookup[ self::block_path_key( $path ) ] = [
				'name'       => (string) ( $node['name'] ?? '' ),
				'path'       => $path,
				'label'      => sanitize_text_field( (string) ( $node['label'] ?? '' ) ),
				'attributes' => is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [],
				'childCount' => isset( $node['childCount'] ) ? (int) $node['childCount'] : 0,
				'slot'       => is_array( $node['slot'] ?? null ) ? $node['slot'] : [],
			];
		}

		return $lookup;
	}

	/**
	 * @return array<string, true>
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
	 * @param array $context Template-part context used to build the prompt.
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_operation_target_lookup( array $context ): array {
		$lookup = [];

		foreach ( is_array( $context['operationTargets'] ?? null ) ? $context['operationTargets'] : [] as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $target['path'] ?? null );

			if ( $path === null ) {
				continue;
			}

			$lookup[ self::block_path_key( $path ) ] = [
				'name'              => sanitize_text_field( (string) ( $target['name'] ?? '' ) ),
				'allowedOperations' => is_array( $target['allowedOperations'] ?? null ) ? array_map( 'sanitize_key', $target['allowedOperations'] ) : [],
				'allowedInsertions' => is_array( $target['allowedInsertions'] ?? null ) ? array_map( 'sanitize_key', $target['allowedInsertions'] ) : [],
			];
		}

		return $lookup;
	}

	/**
	 * @param array $context Template-part context used to build the prompt.
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_insertion_anchor_lookup( array $context ): array {
		$lookup = [];

		foreach ( is_array( $context['insertionAnchors'] ?? null ) ? $context['insertionAnchors'] : [] as $anchor ) {
			if ( ! is_array( $anchor ) ) {
				continue;
			}

			$placement = sanitize_key( (string) ( $anchor['placement'] ?? '' ) );
			$path      = self::sanitize_block_path( $anchor['targetPath'] ?? null );

			if ( $placement === '' ) {
				continue;
			}

			if ( $path === null ) {
				$lookup[ $placement ] = [
					'placement' => $placement,
				];
				continue;
			}

			$lookup[ $placement . '|' . self::block_path_key( $path ) ] = [
				'placement'  => $placement,
				'targetPath' => $path,
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
	 * @param array<int, mixed>                   $suggestions
	 * @param array<string, array<string, mixed>> $block_lookup
	 * @param array<string, true>                 $pattern_lookup
	 * @param array<string, array<string, mixed>> $operation_target_lookup
	 * @param array<string, array<string, mixed>> $insertion_anchor_lookup
	 * @return array<int, array<string, mixed>>
	 */
	private static function validate_suggestions(
		array $suggestions,
		array $block_lookup,
		array $pattern_lookup,
		array $operation_target_lookup,
		array $insertion_anchor_lookup
	): array {
		$valid = [];
		$order = 0;

		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}

			$label       = sanitize_text_field( substr( (string) ( $suggestion['label'] ?? '' ), 0, 60 ) );
			$description = sanitize_text_field(
				substr(
					(string) ( $suggestion['description'] ?? '' ),
					0,
					200
				)
			);

			if ( $label === '' || $description === '' ) {
				continue;
			}

			$block_hints         = self::validate_block_hints(
				is_array( $suggestion['blockHints'] ?? null ) ? $suggestion['blockHints'] : [],
				$block_lookup
			);
			$pattern_suggestions = self::validate_pattern_suggestions(
				is_array( $suggestion['patternSuggestions'] ?? null ) ? $suggestion['patternSuggestions'] : [],
				$pattern_lookup
			);
			$operations          = self::validate_operations(
				is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [],
				$block_lookup,
				$pattern_lookup,
				$operation_target_lookup,
				$insertion_anchor_lookup
			);

			if ( count( $operations ) > 0 ) {
				foreach ( $operations as $operation ) {
					if (
						! in_array(
							$operation['type'] ?? '',
							[ 'insert_pattern', 'replace_block_with_pattern' ],
							true
						)
					) {
						continue;
					}

					$pattern_name = sanitize_text_field( (string) ( $operation['patternName'] ?? '' ) );

					if ( $pattern_name === '' || in_array( $pattern_name, $pattern_suggestions, true ) ) {
						continue;
					}

					$pattern_suggestions[] = $pattern_name;
				}
			}

			if ( count( $block_hints ) === 0 && count( $pattern_suggestions ) === 0 && count( $operations ) === 0 ) {
				continue;
			}

			$entry = [
				'label'              => $label,
				'description'        => $description,
				'blockHints'         => $block_hints,
				'patternSuggestions' => $pattern_suggestions,
				'operations'         => $operations,
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
						'has_operations'    => [] !== $operations ? 0.25 : 0.0,
						'has_block_hints'   => [] !== $block_hints ? 0.15 : 0.0,
						'has_pattern_hints' => [] !== $pattern_suggestions ? 0.1 : 0.0,
						'has_description'   => '' !== $description ? 0.05 : 0.0,
					]
				);
			}
			$source_signals = [ 'llm_response', 'template_part_surface' ];

			if ( [] !== $operations ) {
				$source_signals[] = 'has_operations';
			}
			if ( [] !== $block_hints ) {
				$source_signals[] = 'has_block_hints';
			}
			if ( [] !== $pattern_suggestions ) {
				$source_signals[] = 'has_pattern_suggestions';
			}

			if ( array_key_exists( 'ranking', $suggestion ) || isset( $suggestion['confidence'] ) || isset( $suggestion['score'] ) ) {
				$entry['ranking'] = RankingContract::normalize(
					$ranking_input,
					[
						'score'         => $computed_score,
						'reason'        => $description,
						'sourceSignals' => $source_signals,
						'safetyMode'    => 'validated',
						'freshnessMeta' => [
							'source'  => 'llm',
							'surface' => 'template_part',
						],
						'operations'    => $operations,
					]
				);
			}

			$entry['_rankScore'] = $computed_score;
			$entry['_rankOrder'] = $order++;
			$valid[]             = $entry;
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

		return array_slice(
			array_map(
				static function ( array $suggestion ): array {
					unset( $suggestion['_rankOrder'] );
					unset( $suggestion['_rankScore'] );
					return $suggestion;
				},
				$valid
			),
			0,
			3
		);
	}

	/**
	 * @param array<int, mixed>                   $operations
	 * @param array<string, array<string, mixed>> $block_lookup
	 * @param array<string, true>                 $pattern_lookup
	 * @param array<string, array<string, mixed>> $operation_target_lookup
	 * @param array<string, array<string, mixed>> $insertion_anchor_lookup
	 * @return array<int, array<string, string>>
	 */
	private static function validate_operations(
		array $operations,
		array $block_lookup,
		array $pattern_lookup,
		array $operation_target_lookup,
		array $insertion_anchor_lookup
	): array {
		if ( count( $operations ) > 3 ) {
			return [];
		}

		$valid              = [];
		$allowed_placements = [
			'start'             => true,
			'end'               => true,
			'before_block_path' => true,
			'after_block_path'  => true,
		];

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $operation['type'] ?? '' ) );

			switch ( $type ) {
				case 'insert_pattern':
					$pattern_name = sanitize_text_field(
						(string) ( $operation['patternName'] ?? $operation['name'] ?? '' )
					);
					$placement    = sanitize_key( (string) ( $operation['placement'] ?? '' ) );
					$target_path  = self::sanitize_block_path( $operation['targetPath'] ?? null );

					if (
						'' === $pattern_name
						|| ! isset( $pattern_lookup[ $pattern_name ] )
						|| ! isset( $allowed_placements[ $placement ] )
					) {
						continue 2;
					}

					if (
						in_array( $placement, [ 'before_block_path', 'after_block_path' ], true )
						&& (
							null === $target_path ||
							! isset(
								$insertion_anchor_lookup[ $placement . '|' . self::block_path_key( $target_path ) ]
							)
						)
					) {
						continue 2;
					}

					if (
						in_array( $placement, [ 'start', 'end' ], true )
						&& ! isset( $insertion_anchor_lookup[ $placement ] )
					) {
						continue 2;
					}

						$normalized = [
							'type'        => 'insert_pattern',
							'patternName' => $pattern_name,
							'placement'   => $placement,
						];

						if ( null !== $target_path ) {
							$normalized['targetPath'] = $target_path;

							$target_node = $block_lookup[ self::block_path_key( $target_path ) ] ?? null;
							if ( is_array( $target_node ) ) {
								$normalized['expectedTarget'] = self::build_expected_target( $target_node );
							}
						}

						$valid[] = $normalized;
					break;

				case 'replace_block_with_pattern':
					$pattern_name        = sanitize_text_field(
						(string) ( $operation['patternName'] ?? $operation['name'] ?? '' )
					);
					$expected_block_name = sanitize_text_field( (string) ( $operation['expectedBlockName'] ?? '' ) );
					$target_path         = self::sanitize_block_path( $operation['targetPath'] ?? null );

					if (
						'' === $pattern_name ||
						'' === $expected_block_name ||
						null === $target_path ||
						! isset( $pattern_lookup[ $pattern_name ] )
					) {
						continue 2;
					}

					$path_key    = self::block_path_key( $target_path );
					$target_node = $block_lookup[ $path_key ] ?? null;
					$target_meta = $operation_target_lookup[ $path_key ] ?? null;

					if (
						! is_array( $target_node ) ||
						! is_array( $target_meta ) ||
						! in_array( 'replace_block_with_pattern', $target_meta['allowedOperations'] ?? [], true ) ||
						sanitize_text_field( (string) ( $target_node['name'] ?? '' ) ) !== $expected_block_name
					) {
						continue 2;
					}

						$valid[] = [
							'type'              => 'replace_block_with_pattern',
							'patternName'       => $pattern_name,
							'expectedBlockName' => $expected_block_name,
							'expectedTarget'    => self::build_expected_target( $target_node ),
							'targetPath'        => $target_path,
						];
					break;

				case 'remove_block':
					$expected_block_name = sanitize_text_field( (string) ( $operation['expectedBlockName'] ?? '' ) );
					$target_path         = self::sanitize_block_path( $operation['targetPath'] ?? null );

					if ( '' === $expected_block_name || null === $target_path ) {
						continue 2;
					}

					$path_key    = self::block_path_key( $target_path );
					$target_node = $block_lookup[ $path_key ] ?? null;
					$target_meta = $operation_target_lookup[ $path_key ] ?? null;

					if (
						! is_array( $target_node ) ||
						! is_array( $target_meta ) ||
						! in_array( 'remove_block', $target_meta['allowedOperations'] ?? [], true ) ||
						sanitize_text_field( (string) ( $target_node['name'] ?? '' ) ) !== $expected_block_name
					) {
						continue 2;
					}

						$valid[] = [
							'type'              => 'remove_block',
							'expectedBlockName' => $expected_block_name,
							'expectedTarget'    => self::build_expected_target( $target_node ),
							'targetPath'        => $target_path,
						];
					break;
			}
		}

		return $valid;
	}

	/**
	 * @param array<int, mixed>                   $block_hints
	 * @param array<string, array<string, mixed>> $block_lookup
	 * @return array<int, array<string, mixed>>
	 */
	private static function validate_block_hints( array $block_hints, array $block_lookup ): array {
		$valid = [];
		$seen  = [];

		foreach ( $block_hints as $hint ) {
			if ( ! is_array( $hint ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $hint['path'] ?? null );

			if ( $path === null ) {
				continue;
			}

			$key = self::block_path_key( $path );

			if ( isset( $seen[ $key ] ) || ! isset( $block_lookup[ $key ] ) ) {
				continue;
			}

			$block_name = sanitize_text_field( (string) ( $block_lookup[ $key ]['name'] ?? '' ) );
			$label      = sanitize_text_field( substr( (string) ( $hint['label'] ?? '' ), 0, 80 ) );

			if ( $label === '' ) {
				$label = self::humanize_block_name( $block_name );
			}

			$valid[]      = [
				'path'      => $path,
				'label'     => $label,
				'blockName' => $block_name,
				'reason'    => sanitize_text_field( substr( (string) ( $hint['reason'] ?? '' ), 0, 160 ) ),
			];
			$seen[ $key ] = true;
		}

		return array_slice( $valid, 0, 4 );
	}

	/**
	 * @param array<int, mixed>   $patterns
	 * @param array<string, true> $pattern_lookup
	 * @return string[]
	 */
	private static function validate_pattern_suggestions( array $patterns, array $pattern_lookup ): array {
		$valid = [];
		$seen  = [];

		foreach ( $patterns as $pattern ) {
			$name = '';

			if ( is_array( $pattern ) ) {
				$name = sanitize_text_field( (string) ( $pattern['name'] ?? '' ) );
			} elseif ( is_string( $pattern ) ) {
				$name = sanitize_text_field( $pattern );
			}

			if ( $name === '' || isset( $seen[ $name ] ) || ! isset( $pattern_lookup[ $name ] ) ) {
				continue;
			}

			$valid[]       = $name;
			$seen[ $name ] = true;
		}

		return array_slice( $valid, 0, 3 );
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
	private static function format_block_tree( array $tree, int $depth = 0 ): string {
		$lines  = [];
		$indent = str_repeat( '  ', $depth );

		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path        = self::sanitize_block_path( $node['path'] ?? null ) ?? [];
			$path_string = '[' . implode( ', ', $path ) . ']';
			$name        = (string) ( $node['name'] ?? 'unknown' );
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

			$lines[] = "{$indent}- {$path_string} {$name}{$attr_suffix}";

			$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
			if ( count( $children ) > 0 ) {
				$lines[] = self::format_block_tree( $children, $depth + 1 );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<int, array<string, mixed>> $targets
	 */
	private static function format_operation_targets( array $targets ): string {
		$lines = [];

		foreach ( $targets as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$path               = self::sanitize_block_path( $target['path'] ?? null ) ?? [];
			$path_string        = '[' . implode( ', ', $path ) . ']';
			$name               = sanitize_text_field( (string) ( $target['name'] ?? 'unknown' ) );
			$label              = sanitize_text_field( (string) ( $target['label'] ?? self::humanize_block_name( $name ) ) );
			$allowed_operations = is_array( $target['allowedOperations'] ?? null ) ? array_filter( array_map( 'sanitize_key', $target['allowedOperations'] ) ) : [];
			$allowed_insertions = is_array( $target['allowedInsertions'] ?? null ) ? array_filter( array_map( 'sanitize_key', $target['allowedInsertions'] ) ) : [];

			$capabilities = array_merge( $allowed_operations, $allowed_insertions );
			$lines[]      = sprintf(
				'- %s %s (%s)',
				$path_string,
				$label !== '' ? $label : $name,
				implode( ', ', $capabilities )
			);
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

	private static function format_structural_constraints( array $constraints ): string {
		$lines = [];

		$content_only_paths = is_array( $constraints['contentOnlyPaths'] ?? null ) ? $constraints['contentOnlyPaths'] : [];
		if ( count( $content_only_paths ) > 0 ) {
			$lines[] = 'contentOnly paths: ' . implode(
				', ',
				array_map(
					static fn( array $path ): string => '[' . implode( ', ', $path ) . ']',
					array_filter( $content_only_paths, 'is_array' )
				)
			);
		}

		$locked_paths = is_array( $constraints['lockedPaths'] ?? null ) ? $constraints['lockedPaths'] : [];
		if ( count( $locked_paths ) > 0 ) {
			$lines[] = 'Locked paths: ' . implode(
				', ',
				array_map(
					static fn( array $path ): string => '[' . implode( ', ', $path ) . ']',
					array_filter( $locked_paths, 'is_array' )
				)
			);
		}

		return implode( "\n", $lines );
	}

	private static function humanize_block_name( string $block_name ): string {
		if ( str_starts_with( $block_name, 'core/' ) ) {
			$block_name = substr( $block_name, 5 );
		}

		return ucwords( str_replace( '-', ' ', $block_name ) );
	}
}
