<?php
/**
 * Template-part-specific LLM prompt assembly and response parsing.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\Support\DesignSemantics;
use FlavorAgent\Support\FormatsDocsGuidance;
use FlavorAgent\Support\RecommendationContextScorer;
use FlavorAgent\Support\RankingContract;
use FlavorAgent\Support\ValidationReason;

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
          "label": "Navigation block", "blockName": "core/navigation",
          "reason": "Why this block is the right place to focus"
        }
      ],
      "patternSuggestions": ["pattern/name-from-patterns-list"],
      "operations": [
        {
          "type": "insert_pattern", "expectedBlockName": null,
          "patternName": "pattern/name-from-patterns-list",
          "placement": "before_block_path",
          "targetPath": [0, 1]
        }
      ],
      "confidence": 0.85,
      "ranking": null
    }
  ],
  "explanation": "Overall reasoning for these recommendations"
}

Rules:
- confidence MUST be a number from 0 to 1 indicating your certainty in this suggestion, or null to defer to the system's deterministic ranking.
- Every suggestion item MUST include ranking. Return ranking as null when you do not have a structured ranking object. When ranking is an object, include score, reason, sourceSignals, designPrinciple, and risk, and use null for unknown ranking object values; the plugin will blend score with deterministic validation signals.
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
- Do not include `expectedTarget`; the server attaches the live block fingerprint from `targetPath`.
- `replace_block_with_pattern` MUST include `targetPath`, `expectedBlockName`, and `patternName`.
- `remove_block` MUST include `targetPath` and `expectedBlockName`.
- Every executable `targetPath` MUST resolve to a real path from the Executable Operation Targets or Executable Insertion Anchors lists.
- `expectedBlockName` MUST match the real block at `targetPath`.
- Prefer valid `operations[]` when the user request maps directly to the Executable Operation Examples. Keep unsupported or ambiguous ideas in `patternSuggestions` and `blockHints`.
- If Structural Constraints mention `contentOnly` or locked paths, keep those ideas advisory unless the path is explicitly listed as executable.
- Keep patternSuggestions aligned with any executable operations you return.
- Keep recommendations advisory-first. Do not output raw block markup or free-form rewritten block trees.
- Use blockHints to point at the most relevant places in the current structure when specific focus areas exist.
- Use the WordPress Developer Guidance section as authoritative current WordPress context. Do not recommend capabilities, block supports, APIs, or editor workflows that contradict the provided guidance. If the user asks for a current WordPress feature that is absent from the guidance, keep the suggestion conservative and avoid claiming support.
- If the guidance explicitly marks the user's requested API, workflow, or feature as deprecated, unsupported, experimental, or replaced, warn about that conflict and suggest the documented replacement instead of complying with the stale request.
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
		$max_tokens = (int) apply_filters( 'flavor_agent_prompt_budget_max_tokens', 0, 'template_part' );
		$budget     = new PromptBudget( $max_tokens );

		$ref   = (string) ( $context['templatePartRef'] ?? 'unknown' );
		$slug  = (string) ( $context['slug'] ?? '' );
		$title = (string) ( $context['title'] ?? $slug );
		$area  = (string) ( $context['area'] ?? 'uncategorized' );

		$budget->add_section( 'identity', "## Template Part\nRef: {$ref}\nSlug: {$slug}\nTitle: {$title}\nArea: {$area}", 100 );

		$top_level_blocks = is_array( $context['topLevelBlocks'] ?? null ) ? $context['topLevelBlocks'] : [];
		if ( count( $top_level_blocks ) > 0 ) {
			$budget->add_section( 'top_level_blocks', '## Top-Level Blocks' . "\n" . implode( ', ', $top_level_blocks ), 90 );
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
			$budget->add_section( 'structure_summary', "## Structure Summary\n" . implode( "\n", $structure_lines ), 70 );
		}

		$block_tree = is_array( $context['blockTree'] ?? null ) ? $context['blockTree'] : [];
		if ( count( $block_tree ) > 0 ) {
			$budget->add_section( 'block_tree', "## Current Block Tree\n" . self::format_block_tree( $block_tree ), 85 );
		} else {
			$budget->add_section( 'block_tree', "## Current Block Tree\nThis template part is empty.", 85 );
		}

		$operation_targets = is_array( $context['operationTargets'] ?? null ) ? $context['operationTargets'] : [];
		if ( count( $operation_targets ) > 0 ) {
			$budget->add_section( 'operation_targets', "## Executable Operation Targets\n" . self::format_operation_targets( $operation_targets ), 80 );
		}

		$insertion_anchors = is_array( $context['insertionAnchors'] ?? null ) ? $context['insertionAnchors'] : [];
		if ( count( $insertion_anchors ) > 0 ) {
			$budget->add_section( 'insertion_anchors', "## Executable Insertion Anchors\n" . self::format_insertion_anchors( $insertion_anchors ), 75 );
		}

		$patterns           = is_array( $context['patterns'] ?? null ) ? $context['patterns'] : [];
		$operation_examples = self::format_operation_examples( $patterns, $operation_targets, $insertion_anchors );
		if ( '' !== $operation_examples ) {
			$budget->add_section(
				'operation_examples',
				"## Executable Operation Examples\n{$operation_examples}\nUse these shapes when the user request maps to an executable target. Keep invalid or ambiguous ideas in patternSuggestions/blockHints only.",
				62
			);
		}

		$structural_constraints = is_array( $context['structuralConstraints'] ?? null ) ? $context['structuralConstraints'] : [];
		$formatted_constraints  = self::format_structural_constraints( $structural_constraints );
		if ( $formatted_constraints !== '' ) {
			$budget->add_section( 'structural_constraints', "## Structural Constraints\n{$formatted_constraints}", 60 );
		}

		$design_semantics      = DesignSemantics::normalize(
			$context['designSemantics'] ?? [],
			'template-part'
		);
		$design_semantic_lines = DesignSemantics::format_prompt_lines(
			$design_semantics,
			80
		);

		if ( ! empty( $design_semantic_lines ) ) {
			$budget->add_section(
				'design_semantics',
				"## Design semantic context\n" . implode( "\n", $design_semantic_lines ),
				58
			);
		}

		$current_pattern_overrides = is_array( $context['currentPatternOverrides'] ?? null )
			? $context['currentPatternOverrides']
			: [];
		$budget->add_section(
			'pattern_overrides',
			"## Current Pattern Override Blocks\n" . self::format_current_pattern_overrides( $current_pattern_overrides ),
			50
		);

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

			$budget->add_section( 'patterns', $header . implode( "\n", $lines ), 55 );
		} else {
			$budget->add_section( 'patterns', "## Available Patterns\nNo area-relevant patterns are available.", 55 );
		}

		$theme_tokens = ThemeTokenFormatter::format(
			is_array( $context['themeTokens'] ?? null ) ? $context['themeTokens'] : []
		);
		if ( $theme_tokens !== '' ) {
			$budget->add_section( 'theme_tokens', "## Theme Tokens\n{$theme_tokens}", 30 );
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
				$budget->add_section( 'docs_guidance', "## WordPress Developer Guidance\n" . implode( "\n", $lines ), 20 );
			}
		}

		$instruction = trim( $prompt ) !== ''
			? trim( $prompt )
			: 'Suggest improvements for this template part.';
		$budget->add_section( 'user_instruction', "## User Instruction\n{$instruction}", 95, true );

		foreach ( self::get_few_shot_examples() as $index => $example ) {
			$budget->add_section( 'few_shot_' . $index, $example, 10 );
		}

		return $budget->assemble();
	}

	public static function get_few_shot_examples(): array {
		return [
			<<<'EXAMPLE'
## Example - header template part with a weak utility row

Input context:
- Template part: `header`
- Current block tree includes `core/site-logo` and `core/navigation`
- Available patterns: `example/header-utility-row`
- Insert anchors include `before_block_path` for the navigation block

Expected response:
{"suggestions":[{"label":"Add a utility row before navigation","description":"Insert the utility pattern ahead of the menu cluster to separate branding from utility links.","blockHints":[{"path":[0,1],"label":"Navigation block","blockName":"core/navigation","reason":"This is the busiest structural target in the header."}],"patternSuggestions":["example/header-utility-row"],"operations":[{"type":"insert_pattern","patternName":"example/header-utility-row","placement":"before_block_path","targetPath":[0,1],"expectedBlockName":null}],"confidence":0.8,"ranking":null}],"explanation":"Use a small pre-navigation utility row instead of overloading the main menu."}
EXAMPLE
			,
		];
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
	public static function parse_response( string $raw, array $context, array $ranking_context = [] ): array|\WP_Error {
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
			$insertion_anchor_lookup,
			$context,
			$ranking_context
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
		return StructuralOperationsGrammar::build_block_lookup( $context );
	}

	/**
	 * @return array<string, true>
	 */
	private static function build_pattern_lookup( array $context ): array {
		return StructuralOperationsGrammar::build_pattern_lookup( $context );
	}

	/**
	 * @param array $context Template-part context used to build the prompt.
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_operation_target_lookup( array $context ): array {
		return StructuralOperationsGrammar::build_operation_target_lookup( $context );
	}

	/**
	 * @param array $context Template-part context used to build the prompt.
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_insertion_anchor_lookup( array $context ): array {
		return StructuralOperationsGrammar::build_insertion_anchor_lookup( $context );
	}

	/**
	 * @param array<string, mixed> $target_node
	 * @return array<string, mixed>
	 */
	private static function build_expected_target( array $target_node ): array {
		return StructuralOperationsGrammar::build_expected_target( $target_node );
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
		array $insertion_anchor_lookup,
		array $context = [],
		array $ranking_context = []
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
			$operations_result   = self::validate_operations(
				is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [],
				$block_lookup,
				$pattern_lookup,
				$operation_target_lookup,
				$insertion_anchor_lookup
			);
			$operations          = $operations_result['operations'];
			$validation_reasons  = ValidationReason::normalize( $operations_result['reasons'] );

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
				'validationReasons'  => $validation_reasons,
			];

			if ( isset( $suggestion['contentBlockCount'] ) && is_numeric( $suggestion['contentBlockCount'] ) ) {
				$entry['contentBlockCount'] = max( 0, (int) $suggestion['contentBlockCount'] );
			}

			$ranking_input       = is_array( $suggestion['ranking'] ?? null ) ? $suggestion['ranking'] : [];
			$model_score         = RankingContract::resolve_score_candidate(
				$ranking_input['score'] ?? null,
				$suggestion['score'] ?? null,
				$ranking_input['confidence'] ?? null,
				$suggestion['confidence'] ?? null
			);
			$deterministic_score = RankingContract::derive_score(
				0.45,
				[
					'has_operations'    => [] !== $operations ? 0.25 : 0.0,
					'has_block_hints'   => [] !== $block_hints ? 0.15 : 0.0,
					'has_pattern_hints' => [] !== $pattern_suggestions ? 0.1 : 0.0,
					'has_description'   => '' !== $description ? 0.05 : 0.0,
				]
			);
			$entry               = self::apply_design_validation( $entry, $context, $ranking_context );
			$contextual_result   = self::score_contextual_recommendation( $entry, $context, $ranking_context );
			$context_score       = is_array( $contextual_result ) ? $contextual_result['score'] : null;
			$computed_score      = RankingContract::blend_score(
				[
					'model'         => $model_score,
					'deterministic' => $deterministic_score,
					'context'       => $context_score,
				]
			);
			$source_signals      = [ 'llm_response', 'template_part_surface' ];

			if ( [] !== $operations ) {
				$source_signals[] = 'has_operations';
			}
			if ( [] !== $block_hints ) {
				$source_signals[] = 'has_block_hints';
			}
			if ( [] !== $pattern_suggestions ) {
				$source_signals[] = 'has_pattern_suggestions';
			}
			if ( isset( $entry['qualitySignals'] ) ) {
				$source_signals[] = 'design_validator_v1';
			}
			if ( is_array( $contextual_result ) ) {
				$source_signals[] = 'contextual_ranking_v1';
			}

			$ranking_metadata = $ranking_input;
			unset( $ranking_metadata['score'], $ranking_metadata['confidence'] );

			$entry['ranking'] = RankingContract::normalize(
				$ranking_metadata,
				array_merge(
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
					],
					RankingContract::contextual_component_defaults(
						$model_score,
						$deterministic_score,
						$contextual_result,
						$computed_score
					)
				)
			);

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

	private static function apply_design_validation( array $entry, array $context, array $ranking_context ): array {
		if ( [] === $ranking_context ) {
			return $entry;
		}

		$analysis_context = is_array( $ranking_context['context'] ?? null ) ? $ranking_context['context'] : $context;
		$result           = \FlavorAgent\Support\RecommendationDesignValidator::analyze( $entry, $analysis_context );

		$entry['qualitySignals']    = $result['qualitySignals'];
		$entry['validationReasons'] = ValidationReason::normalize(
			array_merge(
				is_array( $entry['validationReasons'] ?? null ) ? $entry['validationReasons'] : [],
				$result['validationReasons']
			)
		);

		return $entry;
	}

	private static function score_contextual_recommendation( array $suggestion, array $context, array $ranking_context ): ?array {
		if ( [] === $ranking_context ) {
			return null;
		}

		return RecommendationContextScorer::score(
			[
				'surface'       => $ranking_context['surface'] ?? 'template-part',
				'group'         => 'suggestions',
				'suggestion'    => $suggestion,
				'context'       => is_array( $ranking_context['context'] ?? null ) ? $ranking_context['context'] : $context,
				'prompt'        => $ranking_context['prompt'] ?? '',
				'docsGrounding' => is_array( $ranking_context['docsGrounding'] ?? null ) ? $ranking_context['docsGrounding'] : [],
			]
		);
	}

	/**
	 * Validate generation-time template-part operations, returning surviving
	 * executable operations alongside the specific rejection reasons that were
	 * accumulated. Each deterministic rejection branch emits a precise
	 * ValidationReason vocabulary code; no branch funnels into the generic
	 * operation_validation_failed fallback.
	 *
	 * @param array<int, mixed>                   $operations
	 * @param array<string, array<string, mixed>> $block_lookup
	 * @param array<string, true>                 $pattern_lookup
	 * @param array<string, array<string, mixed>> $operation_target_lookup
	 * @param array<string, array<string, mixed>> $insertion_anchor_lookup
	 * @return array{operations: array<int, array<string, mixed>>, reasons: array<int, array{code: string, severity: string, message?: string}>}
	 */
	private static function validate_operations(
		array $operations,
		array $block_lookup,
		array $pattern_lookup,
		array $operation_target_lookup,
		array $insertion_anchor_lookup
	): array {
		return StructuralOperationsGrammar::validate_operations(
			$operations,
			$block_lookup,
			$pattern_lookup,
			$operation_target_lookup,
			$insertion_anchor_lookup
		);
	}

	/**
	 * Test seam exposing the private generation-time template-part operation
	 * validator so the per-branch reason-code coverage suite can assert one
	 * specific code per rejection branch without driving the full parse_response
	 * pipeline. The validator takes four positional lookup arguments; this seam
	 * unpacks them from a keyed array.
	 *
	 * @param array<int, mixed>    $operations Raw operations from a suggestion.
	 * @param array<string, mixed> $lookups    Keyed validator lookups
	 *                                         (block/pattern/target/anchor).
	 * @return array{operations: array<int, array<string, mixed>>, reasons: array<int, array{code: string, severity: string, message?: string}>}
	 */
	public static function validate_operations_for_tests( array $operations, array $lookups ): array {
		return self::validate_operations(
			$operations,
			is_array( $lookups['block'] ?? null ) ? $lookups['block'] : [],
			is_array( $lookups['pattern'] ?? null ) ? $lookups['pattern'] : [],
			is_array( $lookups['target'] ?? null ) ? $lookups['target'] : [],
			is_array( $lookups['anchor'] ?? null ) ? $lookups['anchor'] : []
		);
	}

	/**
	 * Apply-time re-validation entry: rebuild the four lookups from a freshly
	 * collected live context and run the same generation-time validator the
	 * recommendation used. Mirrors StylePrompt::validate_operations_for_apply.
	 *
	 * @param array<int, mixed>    $operations Raw operations to re-validate.
	 * @param array<string, mixed> $context    TemplatePartContextCollector::for_template_part() output.
	 * @return array{operations: array<int, array<string, mixed>>, reasons: array<int, array{code: string, severity: string, message?: string}>}
	 */
	public static function validate_operations_for_apply( array $operations, array $context ): array {
		return self::validate_operations(
			$operations,
			self::build_block_lookup( $context ),
			self::build_pattern_lookup( $context ),
			self::build_operation_target_lookup( $context ),
			self::build_insertion_anchor_lookup( $context )
		);
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
		return StructuralOperationsGrammar::sanitize_block_path( $path );
	}

	/**
	 * @param array<int, int[]> $targeted_paths
	 * @param int[]            $target_path
	 */
	private static function has_overlapping_template_part_operation_path( array $targeted_paths, array $target_path ): bool {
		return StructuralOperationsGrammar::has_overlapping_operation_path( $targeted_paths, $target_path );
	}

	/**
	 * @param int[] $path
	 */
	private static function block_path_key( array $path ): string {
		return StructuralOperationsGrammar::block_path_key( $path );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 */
	private static function format_block_tree( array $tree, int $depth = 0 ): string {
		return StructuralOperationsGrammar::format_block_tree( $tree, $depth );
	}

	/**
	 * @param array<int, array<string, mixed>> $targets
	 */
	private static function format_operation_targets( array $targets ): string {
		return StructuralOperationsGrammar::format_operation_targets( $targets );
	}

	/**
	 * @param array<int, array<string, mixed>> $anchors
	 */
	private static function format_insertion_anchors( array $anchors ): string {
		return StructuralOperationsGrammar::format_insertion_anchors( $anchors );
	}

	/**
	 * @param array<int, array<string, mixed>> $patterns
	 * @param array<int, array<string, mixed>> $targets
	 * @param array<int, array<string, mixed>> $anchors
	 */
	private static function format_operation_examples( array $patterns, array $targets, array $anchors ): string {
		return StructuralOperationsGrammar::format_operation_examples( $patterns, $targets, $anchors );
	}

	private static function format_structural_constraints( array $constraints ): string {
		return StructuralOperationsGrammar::format_structural_constraints( $constraints );
	}

	private static function humanize_block_name( string $block_name ): string {
		return StructuralOperationsGrammar::humanize_block_name( $block_name );
	}
}
