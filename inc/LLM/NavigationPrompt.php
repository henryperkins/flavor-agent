<?php
/**
 * Navigation-specific LLM prompt assembly and response parsing.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\Support\FormatsDocsGuidance;
use FlavorAgent\Support\RankingContract;

final class NavigationPrompt {

	use FormatsDocsGuidance;

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
          "type": "reorder|group|ungroup|add-submenu|flatten",
          "targetPath": [1],
          "target": "Description of what to change",
          "detail": "Specific recommendation"
        },
        {
          "type": "set-attribute",
          "target": "overlayMenu",
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
- Structural changes (reorder, group, ungroup, add-submenu, flatten) MUST include targetPath.
- Structural changes MUST use a real targetPath from the Current Menu Target Inventory list.
- set-attribute changes should reference real core/navigation block attributes (overlayMenu, openSubmenusOnClick, hasIcon, icon, maxNestingLevel, showSubmenuIcon).
- In WordPress 7.0+, navigation overlays are a first-class template-part area (navigation-overlay). When relevant overlay template parts are listed for this navigation, prefer referencing them over suggesting inline overlay configuration.
- Treat site-wide overlay counts and slugs in Overlay Context as background capability signals only. Do not assume a specific overlay part applies unless it appears in the Navigation Overlay Template Parts list.
- Do not suggest adding menu items that do not exist in the current structure. Suggest reorganization of what is already there.
- Use the provided location, overlay, and structure summaries to explain why a suggestion fits this navigation's current role.
- When WordPress Developer Guidance is provided, prefer suggestions that match documented navigation block practices.
- Respect the theme's design tokens when suggesting visual changes.
- Treat enabledFeatures and layout in Theme Design Tokens as hard capability constraints.
- When a recommendation depends on color, spacing, typography, border, background, or layout controls, do not recommend changes that rely on disabled features or unsupported layout capabilities.
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

		if ( array_key_exists( 'menuId', $context ) && null !== $context['menuId'] ) {
			$sections[] = 'Menu id: ' . (int) $context['menuId'];
		}

		if ( array_key_exists( 'menuItemCount', $context ) ) {
			$sections[] = 'Menu item count: ' . (int) $context['menuItemCount'];
		}

		if ( array_key_exists( 'maxDepth', $context ) ) {
			$sections[] = 'Max depth: ' . (int) $context['maxDepth'];
		}

		$location_details = is_array( $context['locationDetails'] ?? null ) ? $context['locationDetails'] : [];
		if ( count( $location_details ) > 0 ) {
			$lines = [];
			foreach ( $location_details as $key => $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$display = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
				$lines[] = "- `{$key}`: {$display}";
			}

			if ( count( $lines ) > 0 ) {
				$sections[] = "## Location Context\n" . implode( "\n", $lines );
			}
		}

		$editor_context_lines = self::format_editor_context(
			is_array( $context['editorContext'] ?? null ) ? $context['editorContext'] : []
		);
		if ( $editor_context_lines !== '' ) {
			$sections[] = "## Live Editor Context\n{$editor_context_lines}";
		}

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

		$target_inventory = is_array( $context['targetInventory'] ?? null ) ? $context['targetInventory'] : [];
		if ( count( $target_inventory ) > 0 ) {
			$sections[] = "## Current Menu Target Inventory\n" . self::format_target_inventory( $target_inventory );
		}

		$structure_summary = is_array( $context['structureSummary'] ?? null ) ? $context['structureSummary'] : [];
		if ( count( $structure_summary ) > 0 ) {
			$lines = [];

			foreach ( $structure_summary as $key => $value ) {
				if ( is_array( $value ) ) {
					if ( count( $value ) === 0 ) {
						continue;
					}

					$lines[] = '- `' . $key . '`: ' . implode( ', ', array_map( 'strval', $value ) );
					continue;
				}

				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$display = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
				$lines[] = "- `{$key}`: {$display}";
			}

			if ( count( $lines ) > 0 ) {
				$sections[] = "## Structure Summary\n" . implode( "\n", $lines );
			}
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

		$overlay_context = is_array( $context['overlayContext'] ?? null ) ? $context['overlayContext'] : [];
		if ( count( $overlay_context ) > 0 ) {
			$lines = [];
			foreach ( $overlay_context as $key => $value ) {
				if ( is_array( $value ) ) {
					if ( count( $value ) === 0 ) {
						continue;
					}

					$lines[] = '- `' . $key . '`: ' . implode( ', ', array_map( 'strval', $value ) );
					continue;
				}

				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$display = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
				$lines[] = "- `{$key}`: {$display}";
			}

			if ( count( $lines ) > 0 ) {
				$sections[] = "## Overlay Context\n" . implode( "\n", $lines );
			}
		}

		// Theme tokens.
		$tokens      = is_array( $context['themeTokens'] ?? null ) ? $context['themeTokens'] : [];
		$token_lines = ThemeTokenFormatter::format( $tokens );
		if ( $token_lines !== '' ) {
			$sections[] = "## Theme Design Tokens\n{$token_lines}";
		}

		// WordPress docs guidance.
		if ( count( $docs_guidance ) > 0 ) {
			$guidance_lines = [];

			foreach ( array_slice( $docs_guidance, 0, 3 ) as $chunk ) {
				if ( ! is_array( $chunk ) ) {
					continue;
				}

				$summary = self::format_guidance_line( $chunk );

				if ( $summary !== '' ) {
					$guidance_lines[] = '- ' . $summary;
				}
			}

			if ( count( $guidance_lines ) > 0 ) {
				$sections[] = "## WordPress Developer Guidance\n" . implode( "\n", $guidance_lines );
			}
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

		$valid = self::validate_suggestions( $suggestions, self::build_target_lookup( $context ) );

		return [
			'suggestions' => array_slice( $valid, 0, 3 ),
			'explanation' => $explanation,
		];
	}

	/**
	 * Validate and sanitize individual suggestions.
	 *
	 * @param array $suggestions Raw suggestions from LLM.
	 * @param array<string, true> $target_lookup Lookup of valid current menu target paths.
	 * @return array Validated suggestions.
	 */
	private static function validate_suggestions( array $suggestions, array $target_lookup ): array {
		$allowed_categories = [ 'structure', 'overlay', 'accessibility' ];
		$allowed_types      = [ 'reorder', 'group', 'ungroup', 'add-submenu', 'flatten', 'set-attribute' ];
		$structural_types   = [ 'reorder', 'group', 'ungroup', 'add-submenu', 'flatten' ];
		$allowed_attributes = [
			'overlayMenu'         => true,
			'openSubmenusOnClick' => true,
			'hasIcon'             => true,
			'icon'                => true,
			'maxNestingLevel'     => true,
			'showSubmenuIcon'     => true,
		];
		$valid              = [];
		$order              = 0;

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

					$type   = isset( $change['type'] ) && is_string( $change['type'] )
						? sanitize_key( $change['type'] )
						: '';
					$target = sanitize_text_field( (string) ( $change['target'] ?? '' ) );
					$detail = sanitize_text_field( (string) ( $change['detail'] ?? '' ) );

					if ( ! in_array( $type, $allowed_types, true ) ) {
						continue;
					}

					if ( $target === '' || $detail === '' ) {
						continue;
					}

					if ( $type === 'set-attribute' ) {
						if ( ! isset( $allowed_attributes[ $target ] ) ) {
							continue;
						}

						$changes[] = [
							'type'   => $type,
							'target' => $target,
							'detail' => $detail,
						];
						continue;
					}

					$target_path = self::sanitize_target_path( $change['targetPath'] ?? null );

					if (
						! in_array( $type, $structural_types, true )
						|| null === $target_path
						|| ! isset( $target_lookup[ self::target_path_key( $target_path ) ] )
					) {
						continue;
					}

					$changes[] = [
						'type'       => $type,
						'targetPath' => $target_path,
						'target'     => $target,
						'detail'     => $detail,
					];
				}
			}

			if ( count( $changes ) === 0 ) {
				continue;
			}

			$entry = [
				'label'       => $label,
				'description' => $description,
				'category'    => $category,
				'changes'     => $changes,
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
						'change_count' => min( 0.25, count( $changes ) * 0.08 ),
						'is_structure' => 'structure' === $category ? 0.1 : 0.0,
						'is_overlay'   => 'overlay' === $category ? 0.08 : 0.0,
						'has_detail'   => '' !== $description ? 0.07 : 0.0,
					]
				);
			}
			$source_signals = [ 'llm_response', 'navigation_surface', 'category_' . $category ];

			if ( count( $changes ) > 0 ) {
				$source_signals[] = 'has_changes';
			}

			if ( array_key_exists( 'ranking', $suggestion ) || isset( $suggestion['confidence'] ) || isset( $suggestion['score'] ) || isset( $suggestion['advisoryType'] ) ) {
				$entry['ranking'] = RankingContract::normalize(
					$ranking_input,
					[
						'score'         => $computed_score,
						'reason'        => $description,
						'sourceSignals' => $source_signals,
						'safetyMode'    => 'validated',
						'freshnessMeta' => [
							'source'  => 'llm',
							'surface' => 'navigation',
						],
						'advisoryType'  => (string) ( $suggestion['advisoryType'] ?? $category ),
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
			$path  = self::sanitize_target_path( $item['path'] ?? null );

			$line = "{$indent}- [{$type}]";
			if ( null !== $path ) {
				$line .= ' ' . self::format_target_path( $path );
			}
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
	 * @param array<int, array<string, mixed>> $target_inventory
	 */
	private static function format_target_inventory( array $target_inventory ): string {
		$lines = [];

		foreach ( $target_inventory as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$path  = self::sanitize_target_path( $target['path'] ?? null );
			$type  = sanitize_key( (string) ( $target['type'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $target['label'] ?? '' ) );
			$depth = isset( $target['depth'] ) ? max( 0, (int) $target['depth'] ) : 0;

			if ( null === $path ) {
				continue;
			}

			$line = '- ' . self::format_target_path( $path );

			if ( $type !== '' ) {
				$line .= " `{$type}`";
			}

			if ( $label !== '' ) {
				$line .= " \"{$label}\"";
			}

			$line   .= ' depth=' . $depth;
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<string, mixed> $editor_context
	 */
	private static function format_editor_context( array $editor_context ): string {
		if ( [] === $editor_context ) {
			return '';
		}

		$lines = [];
		$block = is_array( $editor_context['block'] ?? null ) ? $editor_context['block'] : [];

		$block_name = sanitize_text_field( (string) ( $block['name'] ?? '' ) );
		if ( '' !== $block_name ) {
			$lines[] = "- `block`: {$block_name}";
		}

		$block_title = sanitize_text_field( (string) ( $block['title'] ?? '' ) );
		if ( '' !== $block_title ) {
			$lines[] = "- `title`: {$block_title}";
		}

		$identity = is_array( $block['structuralIdentity'] ?? null ) ? $block['structuralIdentity'] : [];
		foreach ( [ 'role', 'location', 'templateArea', 'templatePartSlug' ] as $key ) {
			$value = sanitize_text_field( (string) ( $identity[ $key ] ?? '' ) );

			if ( '' !== $value ) {
				$lines[] = "- `{$key}`: {$value}";
			}
		}

		$siblings_before = is_array( $editor_context['siblingsBefore'] ?? null ) ? $editor_context['siblingsBefore'] : [];
		if ( [] !== $siblings_before ) {
			$lines[] = '- `siblingsBefore`: ' . implode( ', ', array_map( 'strval', $siblings_before ) );
		}

		$siblings_after = is_array( $editor_context['siblingsAfter'] ?? null ) ? $editor_context['siblingsAfter'] : [];
		if ( [] !== $siblings_after ) {
			$lines[] = '- `siblingsAfter`: ' . implode( ', ', array_map( 'strval', $siblings_after ) );
		}

		$ancestors        = is_array( $editor_context['structuralAncestors'] ?? null ) ? $editor_context['structuralAncestors'] : [];
		$ancestor_summary = self::format_structural_summaries( $ancestors );
		if ( '' !== $ancestor_summary ) {
			$lines[] = "- `structuralAncestors`: {$ancestor_summary}";
		}

		$branch         = is_array( $editor_context['structuralBranch'] ?? null ) ? $editor_context['structuralBranch'] : [];
		$branch_summary = self::format_structural_branch( $branch );
		if ( '' !== $branch_summary ) {
			$lines[] = "- `structuralBranch`: {$branch_summary}";
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<int, array<string, mixed>> $summaries
	 */
	private static function format_structural_summaries( array $summaries ): string {
		$parts = [];

		foreach ( array_slice( $summaries, 0, 4 ) as $summary ) {
			if ( ! is_array( $summary ) ) {
				continue;
			}

			$piece = [];

			foreach ( [ 'block', 'role', 'location', 'templateArea', 'templatePartSlug' ] as $key ) {
				$value = sanitize_text_field( (string) ( $summary[ $key ] ?? '' ) );

				if ( '' === $value ) {
					continue;
				}

				$piece[] = 'block' === $key ? $value : "{$key}={$value}";
			}

			if ( [] !== $piece ) {
				$parts[] = implode( ' ', $piece );
			}
		}

		return implode( ' | ', $parts );
	}

	/**
	 * @param array<int, array<string, mixed>> $branch
	 */
	private static function format_structural_branch( array $branch ): string {
		$parts = [];

		foreach ( array_slice( $branch, 0, 3 ) as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$summary = self::format_structural_summaries( [ $node ] );
			if ( '' === $summary ) {
				continue;
			}

			$child_count = is_array( $node['children'] ?? null ) ? count( $node['children'] ) : 0;
			if ( $child_count > 0 ) {
				$summary .= " children={$child_count}";
			}

			$parts[] = $summary;
		}

		return implode( ' | ', $parts );
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, true>
	 */
	private static function build_target_lookup( array $context ): array {
		$lookup           = [];
		$target_inventory = is_array( $context['targetInventory'] ?? null ) ? $context['targetInventory'] : [];

		if ( [] === $target_inventory ) {
			$target_inventory = self::flatten_menu_items(
				is_array( $context['menuItems'] ?? null ) ? $context['menuItems'] : []
			);
		}

		foreach ( $target_inventory as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$path = self::sanitize_target_path( $target['path'] ?? null );

			if ( null === $path ) {
				continue;
			}

			$lookup[ self::target_path_key( $path ) ] = true;
		}

		return $lookup;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten_menu_items( array $items ): array {
		$flattened = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$flattened[] = $item;

			$children = is_array( $item['children'] ?? null ) ? $item['children'] : [];

			if ( [] !== $children ) {
				$flattened = array_merge( $flattened, self::flatten_menu_items( $children ) );
			}
		}

		return $flattened;
	}

	/**
	 * @return array<int, int>|null
	 */
	private static function sanitize_target_path( mixed $path ): ?array {
		if ( ! is_array( $path ) || [] === $path ) {
			return null;
		}

		$normalized = [];

		foreach ( $path as $segment ) {
			if ( ! is_numeric( $segment ) ) {
				return null;
			}

			$normalized[] = max( 0, (int) $segment );
		}

		return $normalized;
	}

	/**
	 * @param array<int, int> $path
	 */
	private static function format_target_path( array $path ): string {
		return '[' . implode( ', ', $path ) . ']';
	}

	/**
	 * @param array<int, int> $path
	 */
	private static function target_path_key( array $path ): string {
		return implode( '.', $path );
	}
}
