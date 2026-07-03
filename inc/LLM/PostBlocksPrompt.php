<?php
/**
 * Post-blocks-specific LLM prompt assembly and response parsing.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\Support\FormatsDocsGuidance;

/**
 * Prompt owner for the external post-blocks surface: structural
 * recommendations over one post/page document's block tree. The operation
 * grammar (≤3 ops, overlap rejection, placement vocabulary, expectedTarget
 * fingerprints, lock rejection) is shared with the template-part surface via
 * StructuralOperationsGrammar; response parsing delegates to
 * TemplatePartPrompt::parse_response so suggestion validation has one owner.
 */
final class PostBlocksPrompt {

	use FormatsDocsGuidance;

	private const MAX_PROMPT_PATTERNS = 30;

	/**
	 * Build the system prompt for post-blocks recommendations.
	 */
	public static function build_system(): string {
		return <<<'SYSTEM'
You are a WordPress content-structure advisor. Given a single post or page document's block-tree structure, candidate patterns, theme design tokens, and WordPress guidance, suggest how to improve that one document's block composition.

Return ONLY a JSON object with this exact shape. Do not use markdown fences or add any text outside the JSON object:

{
  "suggestions": [
    {
      "label": "Short title for this suggestion",
      "description": "Why this improves the document",
      "blockHints": [
        {
          "path": [0, 1],
          "label": "Heading block", "blockName": "core/heading",
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
- Locked blocks are listed without remove/replace capabilities; never target them with `remove_block` or `replace_block_with_pattern`, and keep those ideas advisory.
- If Structural Constraints mention `contentOnly` or locked paths, keep those ideas advisory unless the path is explicitly listed as executable.
- Keep patternSuggestions aligned with any executable operations you return.
- Keep recommendations advisory-first. Do not output raw block markup or free-form rewritten block trees.
- Do not rewrite or critique the document's prose; this surface is about block structure, not copy.
- Use blockHints to point at the most relevant places in the current structure when specific focus areas exist.
- Use the WordPress Developer Guidance section as authoritative current WordPress context. Do not recommend capabilities, block supports, APIs, or editor workflows that contradict the provided guidance. If the user asks for a current WordPress feature that is absent from the guidance, keep the suggestion conservative and avoid claiming support.
- If the guidance explicitly marks the user's requested API, workflow, or feature as deprecated, unsupported, experimental, or replaced, warn about that conflict and suggest the documented replacement instead of complying with the stale request.
- Respect the theme's design tokens when suggesting patterns or structural changes.
- Treat enabledFeatures and layout in Theme Tokens as hard capability constraints.
- When a recommendation depends on color, spacing, typography, border, background, or layout controls, do not recommend patterns, operations, or attribute changes that rely on disabled features or unsupported layout capabilities.
- When multiple operations are returned, keep the plan small, explicit, and ordered.
- If no matching patterns are available, leave patternSuggestions as an empty array.
- Return 1-3 suggestions. Each should be distinct and actionable.
- Keep labels under 60 characters. Keep descriptions under 200 characters.
SYSTEM;
	}

	/**
	 * Build the user prompt from post-blocks context.
	 *
	 * @param array  $context Post-blocks context from ServerCollector::for_post_blocks().
	 * @param string $prompt  Optional user instruction.
	 * @param array  $docs_guidance WordPress docs grounding chunks.
	 */
	public static function build_user( array $context, string $prompt = '', array $docs_guidance = [] ): string {
		$max_tokens = (int) apply_filters( 'flavor_agent_prompt_budget_max_tokens', 0, 'post_blocks' );
		$budget     = new PromptBudget( $max_tokens );

		$post_id     = (int) ( $context['postId'] ?? 0 );
		$post_type   = (string) ( $context['postType'] ?? '' );
		$post_status = (string) ( $context['postStatus'] ?? '' );
		$title       = (string) ( $context['title'] ?? '' );

		$budget->add_section( 'identity', "## Document\nPost ID: {$post_id}\nType: {$post_type}\nStatus: {$post_status}\nTitle: {$title}", 100 );

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
			$budget->add_section( 'block_tree', "## Current Block Tree\n" . StructuralOperationsGrammar::format_block_tree( $block_tree ), 85 );
		} else {
			$budget->add_section( 'block_tree', "## Current Block Tree\nThis document is empty.", 85 );
		}

		$operation_targets = is_array( $context['operationTargets'] ?? null ) ? $context['operationTargets'] : [];
		if ( count( $operation_targets ) > 0 ) {
			$budget->add_section( 'operation_targets', "## Executable Operation Targets\n" . StructuralOperationsGrammar::format_operation_targets( $operation_targets ), 80 );
		}

		$insertion_anchors = is_array( $context['insertionAnchors'] ?? null ) ? $context['insertionAnchors'] : [];
		if ( count( $insertion_anchors ) > 0 ) {
			$budget->add_section( 'insertion_anchors', "## Executable Insertion Anchors\n" . StructuralOperationsGrammar::format_insertion_anchors( $insertion_anchors ), 75 );
		}

		$patterns           = is_array( $context['patterns'] ?? null ) ? $context['patterns'] : [];
		$operation_examples = StructuralOperationsGrammar::format_operation_examples( $patterns, $operation_targets, $insertion_anchors );
		if ( '' !== $operation_examples ) {
			$budget->add_section(
				'operation_examples',
				"## Executable Operation Examples\n{$operation_examples}\nUse these shapes when the user request maps to an executable target. Keep invalid or ambiguous ideas in patternSuggestions/blockHints only.",
				62
			);
		}

		$structural_constraints = is_array( $context['structuralConstraints'] ?? null ) ? $context['structuralConstraints'] : [];
		$formatted_constraints  = StructuralOperationsGrammar::format_structural_constraints( $structural_constraints );
		if ( $formatted_constraints !== '' ) {
			$budget->add_section( 'structural_constraints', "## Structural Constraints\n{$formatted_constraints}", 60 );
		}

		if ( count( $patterns ) > 0 ) {
			$max   = self::MAX_PROMPT_PATTERNS;
			$shown = array_slice( $patterns, 0, $max );
			$lines = array_map(
				static function ( array $pattern ): string {
					$name        = (string) ( $pattern['name'] ?? '' );
					$title       = (string) ( $pattern['title'] ?? '' );
					$description = (string) ( $pattern['description'] ?? '' );
					$line        = "- `{$name}`";

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

			// Priority 65 keeps the pattern vocabulary above the executable-operation
			// examples (62) and structural constraints (60) that reference pattern
			// names, mirroring the template-part surface that shares this structural
			// grammar so the two cannot drift under budget pressure.
			$budget->add_section( 'patterns', $header . implode( "\n", $lines ), 65 );
		} else {
			$budget->add_section( 'patterns', "## Available Patterns\nNo patterns are available.", 65 );
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
			: 'Suggest structural improvements for this document.';
		$budget->add_section( 'user_instruction', "## User Instruction\n{$instruction}", 95, true );

		return $budget->assemble();
	}

	/**
	 * Parse and validate a post-blocks recommendation response. Delegates to
	 * TemplatePartPrompt::parse_response so suggestion validation (block hints,
	 * pattern suggestions, operations via the shared grammar, design
	 * validation, contextual scoring) has exactly one owner; callers pass the
	 * post-blocks surface through $ranking_context.
	 *
	 * @param array<string, mixed> $context         ServerCollector::for_post_blocks() output.
	 * @param array<string, mixed> $ranking_context Ranking context; surface should be 'post-blocks'.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function parse_response( string $raw, array $context, array $ranking_context = [] ): array|\WP_Error {
		if ( ! array_key_exists( 'surface', $ranking_context ) ) {
			$ranking_context['surface'] = 'post-blocks';
		}

		if ( ! array_key_exists( 'context', $ranking_context ) ) {
			$ranking_context['context'] = $context;
		}

		return TemplatePartPrompt::parse_response( $raw, $context, $ranking_context );
	}

	/**
	 * Apply-time re-validation entry: rebuild the four lookups from a freshly
	 * collected live post-blocks context and run the shared grammar validator
	 * the recommendation used. Mirrors TemplatePartPrompt::validate_operations_for_apply;
	 * lock-carrying operation targets reject remove/replace with `target_locked`.
	 *
	 * @param array<int, mixed>    $operations Raw operations to re-validate.
	 * @param array<string, mixed> $context    ServerCollector::for_post_blocks() output.
	 * @return array{operations: array<int, array<string, mixed>>, reasons: array<int, array{code: string, severity: string, message?: string}>}
	 */
	public static function validate_operations_for_apply( array $operations, array $context ): array {
		return StructuralOperationsGrammar::validate_operations(
			$operations,
			StructuralOperationsGrammar::build_block_lookup( $context ),
			StructuralOperationsGrammar::build_pattern_lookup( $context ),
			StructuralOperationsGrammar::build_operation_target_lookup( $context ),
			StructuralOperationsGrammar::build_insertion_anchor_lookup( $context )
		);
	}
}
