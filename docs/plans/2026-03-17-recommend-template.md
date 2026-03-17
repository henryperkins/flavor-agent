# Recommend Template Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fill the `recommend-template` 501 stub with an Azure OpenAI–backed pipeline that suggests template-part composition and pattern assignments, and surface results in a `PluginDocumentSettingPanel` in the Site Editor's Template tab.

**Architecture:** PHP backend assembles template context (`ServerCollector::for_template`), builds a prompt (`TemplatePrompt`), calls Azure OpenAI (`ResponsesClient::rank`), and parses the response. A thin REST route exposes it. The JS frontend adds template state to the existing `@wordpress/data` store and renders a `TemplateRecommender` component via `registerPlugin`.

**Tech Stack:** PHP 8.0+ (WordPress Abilities API, REST API), JavaScript (React, `@wordpress/data`, `@wordpress/plugins`), Jest for JS unit tests, `@wordpress/scripts` for build.

**Spec:** `docs/specs/2026-03-17-recommend-template-design.md`

---

## File Structure

| Action | Path | Responsibility |
|--------|------|---------------|
| Create | `inc/LLM/TemplatePrompt.php` | System/user prompt assembly + response parsing |
| Create | `src/utils/template-types.js` | Shared `KNOWN_TEMPLATE_TYPES` + `normalizeTemplateType` |
| Create | `src/utils/__tests__/template-types.test.js` | Unit tests for `normalizeTemplateType` |
| Create | `src/templates/TemplateRecommender.js` | Site Editor panel component |
| Modify | `inc/Context/ServerCollector.php:339` | Add `for_template()` + 4 helpers before class closing brace |
| Modify | `inc/Abilities/TemplateAbilities.php:19-25` | Replace 501 stub with full pipeline |
| Modify | `inc/Abilities/Registration.php:179-195` | Update input/output schemas |
| Modify | `inc/Abilities/InfraAbilities.php:44-46` | Add recommend-template to availableAbilities |
| Modify | `inc/REST/Agent_Controller.php:48-80` | Add thin REST route after recommend-patterns |
| Modify | `flavor-agent.php:103-115` | Add `canRecommendTemplates` localization flag |
| Modify | `src/store/index.js:20-28,127-161,188-195,214-216` | Template state, thunk, reducer, selectors |
| Modify | `src/patterns/PatternRecommender.js:12-72` | Import from shared util instead of local defs |
| Modify | `src/index.js:20-27` | Register `<TemplateRecommender />` |

---

## Chunk 1: JS Shared Util

### Task 1: Create template-types shared util with tests

**Files:**
- Create: `src/utils/template-types.js`
- Create: `src/utils/__tests__/template-types.test.js`

- [ ] **Step 1: Write tests for normalizeTemplateType**

```js
// src/utils/__tests__/template-types.test.js
import { KNOWN_TEMPLATE_TYPES, normalizeTemplateType } from '../template-types';

describe( 'template-types', () => {
	test( 'KNOWN_TEMPLATE_TYPES contains expected entries', () => {
		expect( KNOWN_TEMPLATE_TYPES.has( 'single' ) ).toBe( true );
		expect( KNOWN_TEMPLATE_TYPES.has( '404' ) ).toBe( true );
		expect( KNOWN_TEMPLATE_TYPES.has( 'front-page' ) ).toBe( true );
		expect( KNOWN_TEMPLATE_TYPES.has( 'nonexistent' ) ).toBe( false );
	} );

	test( 'normalizeTemplateType returns exact match', () => {
		expect( normalizeTemplateType( 'single' ) ).toBe( 'single' );
		expect( normalizeTemplateType( 'page' ) ).toBe( 'page' );
		expect( normalizeTemplateType( '404' ) ).toBe( '404' );
	} );

	test( 'normalizeTemplateType normalizes compound slugs', () => {
		expect( normalizeTemplateType( 'single-post' ) ).toBe( 'single' );
		expect( normalizeTemplateType( 'archive-product' ) ).toBe( 'archive' );
	} );

	test( 'normalizeTemplateType returns undefined for unknown', () => {
		expect( normalizeTemplateType( 'custom-layout' ) ).toBeUndefined();
		expect( normalizeTemplateType( '' ) ).toBeUndefined();
		expect( normalizeTemplateType( undefined ) ).toBeUndefined();
	} );
} );
```

- [ ] **Step 2: Run tests — expect FAIL (module not found)**

Run: `npm run test:unit -- --testPathPattern=template-types`
Expected: FAIL — `Cannot find module '../template-types'`

- [ ] **Step 3: Create the shared util**

```js
// src/utils/template-types.js

/**
 * Known pattern template types that match the vocabulary used in
 * registered patterns' templateTypes arrays.
 */
export const KNOWN_TEMPLATE_TYPES = new Set( [
	'index',
	'home',
	'front-page',
	'singular',
	'single',
	'page',
	'archive',
	'author',
	'category',
	'tag',
	'taxonomy',
	'date',
	'search',
	'404',
] );

/**
 * Normalize a template slug to the vocabulary used by pattern template types.
 *
 * @param {string|undefined} slug Template slug from the editor.
 * @return {string|undefined} Normalized template type.
 */
export function normalizeTemplateType( slug ) {
	if ( ! slug ) {
		return undefined;
	}

	if ( KNOWN_TEMPLATE_TYPES.has( slug ) ) {
		return slug;
	}

	const base = slug.split( '-' )[ 0 ];

	if ( KNOWN_TEMPLATE_TYPES.has( base ) ) {
		return base;
	}

	return undefined;
}
```

- [ ] **Step 4: Run tests — all should PASS**

Run: `npm run test:unit -- --testPathPattern=template-types`
Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/utils/template-types.js src/utils/__tests__/template-types.test.js
git commit -m "feat: extract template-types shared util with tests"
```

---

### Task 2: Update PatternRecommender to use shared util

**Files:**
- Modify: `src/patterns/PatternRecommender.js:32-72`

- [ ] **Step 1: Replace local definitions with import**

In `src/patterns/PatternRecommender.js`, replace the `KNOWN_TEMPLATE_TYPES` constant and `normalizeTemplateType` function (lines 32-72) with an import from the shared util. Add this import after line 23:

```js
import {
	KNOWN_TEMPLATE_TYPES,
	normalizeTemplateType,
} from '../utils/template-types';
```

Then delete lines 28-72 (the comment + `KNOWN_TEMPLATE_TYPES` Set + `normalizeTemplateType` function).

- [ ] **Step 2: Run existing tests and build**

Run: `npm run test:unit && npm run build`
Expected: All tests pass, build succeeds. PatternRecommender behavior unchanged.

- [ ] **Step 3: Commit**

```bash
git add src/patterns/PatternRecommender.js
git commit -m "refactor: use shared template-types util in PatternRecommender"
```

---

## Chunk 2: PHP Backend — Context & Prompts

### Task 3: Create TemplatePrompt class

**Files:**
- Create: `inc/LLM/TemplatePrompt.php`

- [ ] **Step 1: Create the TemplatePrompt class**

```php
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
You are a WordPress template composition advisor. Given a template type, its currently assigned template parts, empty areas, available template parts, candidate patterns, and theme design tokens, suggest how to improve the template's structure.

Return ONLY a JSON object with this exact shape — no markdown fences, no text outside the JSON:

{
  "suggestions": [
    {
      "label": "Short title for this suggestion",
      "description": "Why this improves the template",
      "templateParts": [
        {
          "slug": "part-slug-from-availableParts",
          "area": "the-target-area",
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
- patternSuggestions[] MUST be pattern name values from the Available Patterns list.
- Prioritize filling empty areas over replacing existing assignments.
- Respect the theme's design tokens when suggesting patterns.
- If no candidate patterns are available, focus on template part composition and leave patternSuggestions as an empty array.
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

		$type  = $context['templateType'] ?? 'unknown';
		$title = $context['title'] ?? $type;
		$sections[] = "## Template\nType: {$type}\nTitle: {$title}";

		$assigned = $context['assignedParts'] ?? [];
		if ( count( $assigned ) > 0 ) {
			$lines = array_map(
				fn( $p ) => "- **{$p['slug']}** → area: `{$p['area']}`",
				$assigned
			);
			$sections[] = "## Assigned Template Parts\n" . implode( "\n", $lines );
		} else {
			$sections[] = "## Assigned Template Parts\nNone — this template has no template parts assigned.";
		}

		$empty = $context['emptyAreas'] ?? [];
		if ( count( $empty ) > 0 ) {
			$sections[] = "## Empty Areas\n" . implode( ', ', $empty );
		}

		$assigned_slugs = array_column( $assigned, 'slug' );
		$available      = array_values( array_filter(
			$context['availableParts'] ?? [],
			fn( $p ) => ! in_array( $p['slug'], $assigned_slugs, true )
		) );
		if ( count( $available ) > 0 ) {
			$lines = array_map(
				fn( $p ) => "- `{$p['slug']}` — {$p['title']} (area: {$p['area']})",
				$available
			);
			$sections[] = "## Available Template Parts\n" . implode( "\n", $lines );
		} else {
			$sections[] = "## Available Template Parts\nNo unused template parts available.";
		}

		$patterns = $context['patterns'] ?? [];
		if ( count( $patterns ) > 0 ) {
			$max   = 30;
			$shown = array_slice( $patterns, 0, $max );
			$lines = array_map(
				fn( $p ) => "- `{$p['name']}` — {$p['title']}"
					. ( ! empty( $p['description'] ) ? ": {$p['description']}" : '' ),
				$shown
			);
			$header = "## Available Patterns\n";
			if ( count( $patterns ) > $max ) {
				$header .= 'Showing ' . $max . ' of ' . count( $patterns ) . " patterns (typed matches first).\n";
			}
			$sections[] = $header . implode( "\n", $lines );
		} else {
			$sections[] = "## Available Patterns\nNo patterns available for this template type.";
		}

		// Theme tokens are flat string arrays from ServerCollector::for_tokens()
		// e.g. colors = ["primary: #1a4548", "secondary: #f0f0f0"]
		$tokens = $context['themeTokens'] ?? [];
		if ( ! empty( $tokens ) ) {
			$token_lines = [];
			if ( ! empty( $tokens['colors'] ) ) {
				$token_lines[] = 'Colors: ' . implode( ', ', array_slice( $tokens['colors'], 0, 12 ) );
			}
			if ( ! empty( $tokens['fontFamilies'] ) ) {
				$token_lines[] = 'Fonts: ' . implode( ', ', $tokens['fontFamilies'] );
			}
			if ( ! empty( $tokens['fontSizes'] ) ) {
				$token_lines[] = 'Font sizes: ' . implode( ', ', $tokens['fontSizes'] );
			}
			if ( ! empty( $tokens['spacing'] ) ) {
				$token_lines[] = 'Spacing scale: ' . implode( ', ', array_slice( $tokens['spacing'], 0, 7 ) );
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
	 * @param string $raw Raw LLM response text.
	 * @return array|\WP_Error Validated payload or error.
	 */
	public static function parse_response( string $raw ): array|\WP_Error {
		$cleaned = preg_replace( '/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim( $raw ) );

		$data = json_decode( $cleaned, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'parse_error',
				'Failed to parse template recommendation response as JSON: ' . json_last_error_msg(),
				[ 'status' => 500, 'raw' => substr( $raw, 0, 500 ) ]
			);
		}

		if ( ! isset( $data['suggestions'] ) || ! is_array( $data['suggestions'] ) ) {
			return new \WP_Error(
				'parse_error',
				'Template recommendation response missing "suggestions" array.',
				[ 'status' => 500 ]
			);
		}

		return [
			'suggestions' => self::validate_template_suggestions( $data['suggestions'] ),
			'explanation' => sanitize_text_field( $data['explanation'] ?? '' ),
		];
	}

	/**
	 * @param array $suggestions Raw suggestion array from LLM.
	 * @return array Sanitized suggestions.
	 */
	private static function validate_template_suggestions( array $suggestions ): array {
		$valid = [];

		foreach ( $suggestions as $s ) {
			if ( ! is_array( $s ) || empty( $s['label'] ) ) {
				continue;
			}

			$entry = [
				'label'       => sanitize_text_field( $s['label'] ),
				'description' => sanitize_text_field( $s['description'] ?? '' ),
			];

			if ( isset( $s['templateParts'] ) && is_array( $s['templateParts'] ) ) {
				$entry['templateParts'] = [];
				foreach ( $s['templateParts'] as $tp ) {
					if ( ! is_array( $tp ) || empty( $tp['slug'] ) || empty( $tp['area'] ) ) {
						continue;
					}
					$entry['templateParts'][] = [
						'slug'   => sanitize_key( $tp['slug'] ),
						'area'   => sanitize_key( $tp['area'] ),
						'reason' => sanitize_text_field( $tp['reason'] ?? '' ),
					];
				}
			} else {
				$entry['templateParts'] = [];
			}

			if ( isset( $s['patternSuggestions'] ) && is_array( $s['patternSuggestions'] ) ) {
				$entry['patternSuggestions'] = array_values( array_filter(
					array_map( 'sanitize_text_field', $s['patternSuggestions'] ),
					fn( $name ) => $name !== ''
				) );
			} else {
				$entry['patternSuggestions'] = [];
			}

			$valid[] = $entry;
		}

		return $valid;
	}
}
```

- [ ] **Step 2: Verify PHP syntax**

Run: `docker exec wordpress-wordpress-1 php -l wp-content/plugins/flavor-agent/inc/LLM/TemplatePrompt.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add inc/LLM/TemplatePrompt.php
git commit -m "feat: add TemplatePrompt class for template recommendation prompts"
```

---

### Task 4: Add ServerCollector::for_template() and helpers

**Files:**
- Modify: `inc/Context/ServerCollector.php:339` (add before closing brace on line 340)

- [ ] **Step 1: Add the KNOWN_TEMPLATE_TYPES constant and helpers**

Insert the following methods before the closing `}` of the class (before line 340 of `inc/Context/ServerCollector.php`):

```php
	private const KNOWN_TEMPLATE_TYPES = [
		'index', 'home', 'front-page', 'singular', 'single', 'page',
		'archive', 'author', 'category', 'tag', 'taxonomy', 'date',
		'search', '404',
	];

	/**
	 * Assemble context for a template recommendation.
	 *
	 * @param string      $template_ref  Template identifier from the Site Editor.
	 * @param string|null $template_type Normalized template type. Derived if null.
	 * @return array|\WP_Error Template context or error.
	 */
	public static function for_template( string $template_ref, ?string $template_type = null ): array|\WP_Error {
		// Resolve the template.
		$template = null;

		if ( str_contains( $template_ref, '//' ) ) {
			$template = get_block_template( $template_ref, 'wp_template' );
		}

		if ( ! $template ) {
			$slug      = str_contains( $template_ref, '//' )
				? substr( $template_ref, strpos( $template_ref, '//' ) + 2 )
				: $template_ref;
			$templates = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template' );
			$template  = $templates[0] ?? null;
		}

		if ( ! $template ) {
			return new \WP_Error(
				'template_not_found',
				'Could not resolve the current template from the Site Editor context.',
				[ 'status' => 404 ]
			);
		}

		// Derive template type if not provided.
		if ( $template_type === null ) {
			$slug_for_type = str_contains( $template_ref, '//' )
				? substr( $template_ref, strpos( $template_ref, '//' ) + 2 )
				: $template_ref;
			$template_type = self::derive_template_type( $slug_for_type );
		}

		// Available parts and lookup.
		$available_raw   = self::for_template_parts();
		$part_area_lookup = [];
		foreach ( $available_raw as $part ) {
			$part_area_lookup[ $part['slug'] ] = $part['area'];
		}

		// Assigned parts from template content.
		$blocks         = parse_blocks( $template->content ?? '' );
		$assigned_parts = self::extract_assigned_parts( $blocks, $part_area_lookup );

		// Empty areas.
		$assigned_areas = array_unique( array_column( $assigned_parts, 'area' ) );
		$known_areas    = array_unique( array_column( $available_raw, 'area' ) );
		$empty_areas    = array_values( array_diff( $known_areas, $assigned_areas ) );

		// Pattern candidates.
		$patterns = self::collect_template_candidate_patterns( $template_type );

		return [
			'templateRef'    => $template_ref,
			'templateType'   => $template_type,
			'title'          => $template->title ?? $template_ref,
			'assignedParts'  => $assigned_parts,
			'emptyAreas'     => $empty_areas,
			'availableParts' => $available_raw,
			'patterns'       => $patterns,
			'themeTokens'    => self::for_tokens(),
		];
	}

	/**
	 * Walk parsed blocks recursively and extract assigned template parts.
	 *
	 * @param array $blocks           Parsed block array from parse_blocks().
	 * @param array $part_area_lookup Map of slug => area from available parts.
	 * @return array Assigned parts with slug and area.
	 */
	private static function extract_assigned_parts( array $blocks, array $part_area_lookup ): array {
		$parts = [];

		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === 'core/template-part' ) {
				$slug = $block['attrs']['slug'] ?? '';
				if ( $slug !== '' ) {
					$area    = $block['attrs']['area']
						?? ( $part_area_lookup[ $slug ] ?? '' );
					$parts[] = [
						'slug' => $slug,
						'area' => $area,
					];
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$parts = array_merge(
					$parts,
					self::extract_assigned_parts( $block['innerBlocks'], $part_area_lookup )
				);
			}
		}

		return $parts;
	}

	/**
	 * Normalize a template ref to a known template type.
	 *
	 * @param string $ref Template slug or ref.
	 * @return string|null Normalized type or null.
	 */
	private static function derive_template_type( string $ref ): ?string {
		if ( in_array( $ref, self::KNOWN_TEMPLATE_TYPES, true ) ) {
			return $ref;
		}

		$base = explode( '-', $ref )[0];
		if ( in_array( $base, self::KNOWN_TEMPLATE_TYPES, true ) ) {
			return $base;
		}

		return null;
	}

	/**
	 * Collect candidate patterns for a template type.
	 *
	 * Typed matches (patterns with matching templateTypes) sort first,
	 * followed by generic patterns (empty templateTypes). Deduped by name.
	 *
	 * @param string|null $template_type Normalized template type.
	 * @return array Pattern candidates.
	 */
	private static function collect_template_candidate_patterns( ?string $template_type ): array {
		$all_patterns = self::for_patterns();

		if ( $template_type === null ) {
			return $all_patterns;
		}

		$typed   = [];
		$generic = [];
		$seen    = [];

		foreach ( $all_patterns as $pattern ) {
			$name = $pattern['name'] ?? '';
			if ( $name === '' || isset( $seen[ $name ] ) ) {
				continue;
			}

			// Strip content — not needed for the prompt, avoids payload bloat.
			unset( $pattern['content'] );

			$types = $pattern['templateTypes'] ?? [];
			if ( is_array( $types ) && in_array( $template_type, $types, true ) ) {
				$typed[]       = $pattern;
				$seen[ $name ] = true;
			} elseif ( empty( $types ) ) {
				$generic[]     = $pattern;
				$seen[ $name ] = true;
			}
		}

		return array_merge( $typed, $generic );
	}
```

- [ ] **Step 2: Verify PHP syntax**

Run: `docker exec wordpress-wordpress-1 php -l wp-content/plugins/flavor-agent/inc/Context/ServerCollector.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add inc/Context/ServerCollector.php
git commit -m "feat: add ServerCollector::for_template() with recursive part extraction"
```

---

## Chunk 3: PHP Backend — Pipeline & REST

### Task 5: Update Registration.php schemas

**Files:**
- Modify: `inc/Abilities/Registration.php:179-195`

- [ ] **Step 1: Replace the input/output schemas**

In `inc/Abilities/Registration.php`, replace the `input_schema` and `output_schema` blocks inside the `recommend-template` registration (lines 179-195) with:

```php
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'templateRef'  => [ 'type' => 'string', 'description' => 'Template identifier from the Site Editor.' ],
					'templateType' => [ 'type' => 'string', 'description' => 'Normalized template type (single, page, 404, etc.). Derived from templateRef if absent.' ],
					'prompt'       => [ 'type' => 'string' ],
				],
				'required' => [ 'templateRef' ],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'suggestions' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'label'              => [ 'type' => 'string' ],
								'description'        => [ 'type' => 'string' ],
								'templateParts'      => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'slug'   => [ 'type' => 'string' ],
											'area'   => [ 'type' => 'string' ],
											'reason' => [ 'type' => 'string' ],
										],
									],
								],
								'patternSuggestions' => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
							],
						],
					],
					'explanation' => [ 'type' => 'string' ],
				],
			],
```

- [ ] **Step 2: Verify PHP syntax**

Run: `docker exec wordpress-wordpress-1 php -l wp-content/plugins/flavor-agent/inc/Abilities/Registration.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add inc/Abilities/Registration.php
git commit -m "feat: expand recommend-template input/output schemas"
```

---

### Task 6: Implement TemplateAbilities::recommend_template()

**Files:**
- Modify: `inc/Abilities/TemplateAbilities.php:1-27`

- [ ] **Step 1: Replace the full file**

Replace the contents of `inc/Abilities/TemplateAbilities.php` with:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\TemplatePrompt;

final class TemplateAbilities {

	public static function list_template_parts( array $input ): array {
		$area = $input['area'] ?? null;

		return [
			'templateParts' => ServerCollector::for_template_parts( $area ),
		];
	}

	/**
	 * Recommend template-part composition and patterns for a template.
	 *
	 * @param array $input { templateRef: string, templateType?: string, prompt?: string }
	 * @return array|\WP_Error Suggestions payload or error.
	 */
	public static function recommend_template( array $input ): array|\WP_Error {
		$template_ref  = $input['templateRef'] ?? '';
		$template_type = $input['templateType'] ?? null;
		$prompt        = $input['prompt'] ?? '';

		if ( $template_ref === '' ) {
			return new \WP_Error(
				'missing_template_ref',
				'A templateRef is required.',
				[ 'status' => 400 ]
			);
		}

		$context = ServerCollector::for_template( $template_ref, $template_type );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$system = TemplatePrompt::build_system();
		$user   = TemplatePrompt::build_user( $context, $prompt );

		$result = ResponsesClient::rank( $system, $user );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return TemplatePrompt::parse_response( $result );
	}
}
```

- [ ] **Step 2: Verify PHP syntax**

Run: `docker exec wordpress-wordpress-1 php -l wp-content/plugins/flavor-agent/inc/Abilities/TemplateAbilities.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add inc/Abilities/TemplateAbilities.php
git commit -m "feat: implement recommend_template() pipeline via Azure OpenAI"
```

---

### Task 7: Add REST route

**Files:**
- Modify: `inc/REST/Agent_Controller.php:48-80` (add after recommend-patterns route)
- Modify: `inc/REST/Agent_Controller.php` (add handler method)

- [ ] **Step 1: Register the route**

In `Agent_Controller::register_routes()`, add after the `recommend-patterns` route registration (after line 80):

```php
		register_rest_route( self::NAMESPACE, '/recommend-template', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_recommend_template' ],
			'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
			'args'                => [
				'templateRef'  => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => fn( $v ) => is_string( $v ) && $v !== '',
				],
				'templateType' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'prompt'       => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
```

- [ ] **Step 2: Add the thin handler method**

Add this method to the `Agent_Controller` class (after `handle_recommend_patterns`):

```php
	/**
	 * Handle POST /recommend-template — thin adapter.
	 */
	public static function handle_recommend_template( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = [
			'templateRef' => $request->get_param( 'templateRef' ),
		];

		$template_type = $request->get_param( 'templateType' );
		if ( is_string( $template_type ) && $template_type !== '' ) {
			$input['templateType'] = $template_type;
		}

		$prompt = $request->get_param( 'prompt' );
		if ( is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		$result = TemplateAbilities::recommend_template( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}
```

Also add the `use` import at the top of the file if not present:

```php
use FlavorAgent\Abilities\TemplateAbilities;
```

- [ ] **Step 3: Verify PHP syntax**

Run: `docker exec wordpress-wordpress-1 php -l wp-content/plugins/flavor-agent/inc/REST/Agent_Controller.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add inc/REST/Agent_Controller.php
git commit -m "feat: add POST /recommend-template REST route (thin adapter)"
```

---

### Task 8: Update check-status and localization

**Files:**
- Modify: `inc/Abilities/InfraAbilities.php:44-46`
- Modify: `flavor-agent.php:103-115`

- [ ] **Step 1: Add recommend-template to check-status**

In `inc/Abilities/InfraAbilities.php`, after the `recommend-patterns` gating block (after line 46), add:

```php
		$azure_chat_configured = (bool) (
			get_option( 'flavor_agent_azure_openai_endpoint' )
			&& get_option( 'flavor_agent_azure_openai_key' )
			&& get_option( 'flavor_agent_azure_chat_deployment' )
		);

		if ( $azure_chat_configured ) {
			$abilities[] = 'flavor-agent/recommend-template';
		}
```

Note: if `$azure_configured` already checks all four Azure options (endpoint + key + embedding + chat), `$azure_chat_configured` is a lighter check requiring only three. If `$azure_configured` is true, `$azure_chat_configured` is also true — but the reverse is not necessarily so.

- [ ] **Step 2: Add canRecommendTemplates to localized data**

In `flavor-agent.php`, inside the `wp_localize_script` call (before the closing `]` around line 114), add:

```php
		'canRecommendTemplates' => (bool) (
			get_option( 'flavor_agent_azure_openai_endpoint' )
			&& get_option( 'flavor_agent_azure_openai_key' )
			&& get_option( 'flavor_agent_azure_chat_deployment' )
		),
```

- [ ] **Step 3: Verify PHP syntax on both files**

Run: `docker exec wordpress-wordpress-1 php -l wp-content/plugins/flavor-agent/inc/Abilities/InfraAbilities.php && docker exec wordpress-wordpress-1 php -l wp-content/plugins/flavor-agent/flavor-agent.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add inc/Abilities/InfraAbilities.php flavor-agent.php
git commit -m "feat: gate recommend-template on Azure chat config in check-status and JS"
```

---

## Chunk 4: JS Frontend

### Task 9: Add template state to the store

**Files:**
- Modify: `src/store/index.js:20-28,127-161,188-195,214-216`

- [ ] **Step 1: Add template state to DEFAULT_STATE**

In `src/store/index.js`, add to the `DEFAULT_STATE` object (after `patternBadge: null,` around line 27):

```js
	templateRecommendations: [],
	templateExplanation: '',
	templateStatus: 'idle',
	templateError: null,
	templateRef: null,
```

- [ ] **Step 2: Add template actions**

Add these actions to the `actions` object (after the `fetchPatternRecommendations` thunk):

```js
	setTemplateStatus( status, error = null ) {
		return { type: 'SET_TEMPLATE_STATUS', status, error };
	},

	setTemplateRecommendations( templateRef, payload ) {
		return { type: 'SET_TEMPLATE_RECS', templateRef, payload };
	},

	clearTemplateRecommendations() {
		return { type: 'CLEAR_TEMPLATE_RECS' };
	},

	fetchTemplateRecommendations( input ) {
		return async ( { dispatch } ) => {
			if ( actions._templateAbort ) {
				actions._templateAbort.abort();
			}
			const controller = new AbortController();
			actions._templateAbort = controller;

			dispatch( actions.setTemplateStatus( 'loading' ) );

			try {
				const result = await apiFetch( {
					path: '/flavor-agent/v1/recommend-template',
					method: 'POST',
					data: input,
					signal: controller.signal,
				} );
				dispatch(
					actions.setTemplateRecommendations(
						input.templateRef,
						result
					)
				);
			} catch ( err ) {
				if ( err.name === 'AbortError' ) {
					return;
				}
				// Clear stale results on error — matches pattern thunk behavior.
				dispatch(
					actions.setTemplateRecommendations(
						input.templateRef,
						{ suggestions: [], explanation: '' }
					)
				);
				dispatch(
					actions.setTemplateStatus(
						'error',
						err?.message || 'Template recommendation request failed.'
					)
				);
			}
		};
	},
```

- [ ] **Step 3: Add template reducer cases**

Add to the reducer `switch` block (after the `SET_PATTERN_RECS` case):

```js
			case 'SET_TEMPLATE_STATUS':
				return {
					...state,
					templateStatus: action.status,
					templateError: action.error ?? null,
				};
			case 'SET_TEMPLATE_RECS':
				return {
					...state,
					templateRecommendations: action.payload?.suggestions ?? [],
					templateExplanation: action.payload?.explanation ?? '',
					templateRef: action.templateRef,
					templateStatus: 'ready',
					templateError: null,
				};
			case 'CLEAR_TEMPLATE_RECS':
				return {
					...state,
					templateRecommendations: [],
					templateExplanation: '',
					templateStatus: 'idle',
					templateError: null,
					templateRef: null,
				};
```

- [ ] **Step 4: Add template selectors**

Add to the `selectors` object (after `isPatternLoading`):

```js
	getTemplateRecommendations: ( state ) => state.templateRecommendations,
	getTemplateExplanation: ( state ) => state.templateExplanation,
	getTemplateError: ( state ) => state.templateError,
	getTemplateResultRef: ( state ) => state.templateRef,
	isTemplateLoading: ( state ) => state.templateStatus === 'loading',
	getTemplateStatus: ( state ) => state.templateStatus,
```

- [ ] **Step 5: Build and verify**

Run: `npm run build`
Expected: Build succeeds with no errors.

- [ ] **Step 6: Commit**

```bash
git add src/store/index.js
git commit -m "feat: add template recommendation state to store"
```

---

### Task 10: Create TemplateRecommender component

**Files:**
- Create: `src/templates/TemplateRecommender.js`

- [ ] **Step 1: Create the component**

```js
/**
 * Template Recommender
 *
 * Renders AI template composition suggestions in the Site Editor's
 * Template tab via PluginDocumentSettingPanel.
 */
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';

import { STORE_NAME } from '../store';
import { normalizeTemplateType } from '../utils/template-types';

export default function TemplateRecommender() {
	const canRecommend = window.flavorAgentData?.canRecommendTemplates;

	const templateRef = useSelect( ( select ) => {
		const editSite = select( 'core/edit-site' );
		if ( ! editSite?.getEditedPostType ) {
			return null;
		}
		if ( editSite.getEditedPostType() !== 'wp_template' ) {
			return null;
		}
		return editSite.getEditedPostId() || null;
	}, [] );

	// Strip theme// prefix before normalizing — getEditedPostId() may
	// return canonical refs like "theme-slug//single-post".
	const templateSlug = templateRef?.includes( '//' )
		? templateRef.split( '//' )[ 1 ]
		: templateRef;
	const templateType = normalizeTemplateType( templateSlug );

	const { recommendations, explanation, error, resultRef, isLoading } =
		useSelect(
			( select ) => {
				const s = select( STORE_NAME );
				return {
					recommendations: s.getTemplateRecommendations(),
					explanation: s.getTemplateExplanation(),
					error: s.getTemplateError(),
					resultRef: s.getTemplateResultRef(),
					isLoading: s.isTemplateLoading(),
				};
			},
			[]
		);

	// Build pattern name → title lookup for human-readable display.
	const patternTitleMap = useSelect( ( select ) => {
		const settings = select( 'core/block-editor' ).getSettings();
		const patterns = settings?.__experimentalBlockPatterns ?? [];
		const map = {};
		patterns.forEach( ( p ) => {
			if ( p?.name ) {
				map[ p.name ] = p.title || p.name;
			}
		} );
		return map;
	}, [] );

	const { fetchTemplateRecommendations, clearTemplateRecommendations } =
		useDispatch( STORE_NAME );

	const [ prompt, setPrompt ] = useState( '' );
	const prevRef = useRef( templateRef );

	// Clear stale recommendations when the edited template changes.
	useEffect( () => {
		if ( prevRef.current !== templateRef ) {
			clearTemplateRecommendations();
			setPrompt( '' );
			prevRef.current = templateRef;
		}
	}, [ templateRef, clearTemplateRecommendations ] );

	const handleFetch = useCallback( () => {
		if ( ! templateRef ) {
			return;
		}

		const input = { templateRef };
		if ( templateType ) {
			input.templateType = templateType;
		}
		const trimmed = prompt.trim();
		if ( trimmed ) {
			input.prompt = trimmed;
		}

		fetchTemplateRecommendations( input );
	}, [ templateRef, templateType, prompt, fetchTemplateRecommendations ] );

	if ( ! canRecommend || ! templateRef ) {
		return null;
	}

	const hasResults =
		resultRef === templateRef && recommendations.length > 0;

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-template-recommendations"
			title="AI Template Recommendations"
			className="flavor-agent-template-panel"
		>
			<div style={ { marginBottom: '8px' } }>
				<textarea
					placeholder="What are you trying to achieve with this template?"
					value={ prompt }
					onChange={ ( e ) => setPrompt( e.target.value ) }
					rows={ 2 }
					style={ { width: '100%', resize: 'vertical' } }
					aria-label="Describe what you want to achieve with this template"
				/>
			</div>

			<Button
				variant="primary"
				onClick={ handleFetch }
				disabled={ isLoading }
				style={ { width: '100%', justifyContent: 'center' } }
			>
				{ isLoading ? <Spinner /> : 'Get Suggestions' }
			</Button>

			{ isLoading && (
				<Notice
					status="info"
					isDismissible={ false }
					style={ { marginTop: '8px' } }
				>
					Analyzing template structure…
				</Notice>
			) }

			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					style={ { marginTop: '8px' } }
				>
					{ error }
				</Notice>
			) }

			{ hasResults && explanation && (
				<p
					style={ {
						marginTop: '8px',
						fontSize: '12px',
						color: 'var(--wp-components-color-foreground-secondary, #757575)',
					} }
				>
					{ explanation }
				</p>
			) }

			{ hasResults &&
				recommendations.map( ( suggestion, idx ) => (
					<SuggestionCard
						key={ `${ suggestion.label }-${ idx }` }
						suggestion={ suggestion }
						patternTitleMap={ patternTitleMap }
					/>
				) ) }
		</PluginDocumentSettingPanel>
	);
}

function SuggestionCard( { suggestion, patternTitleMap = {} } ) {
	return (
		<div
			style={ {
				marginTop: '12px',
				padding: '10px',
				border: '1px solid var(--wp-components-color-accent, #3858e9)',
				borderRadius: '4px',
			} }
		>
			<div style={ { fontWeight: 600, marginBottom: '4px' } }>
				{ suggestion.label }
			</div>
			{ suggestion.description && (
				<p
					style={ {
						fontSize: '12px',
						margin: '0 0 8px',
						color: 'var(--wp-components-color-foreground-secondary, #757575)',
					} }
				>
					{ suggestion.description }
				</p>
			) }

			{ suggestion.templateParts?.length > 0 && (
				<div style={ { marginBottom: '6px' } }>
					<div
						style={ {
							fontSize: '11px',
							fontWeight: 600,
							textTransform: 'uppercase',
							letterSpacing: '0.5px',
							marginBottom: '4px',
						} }
					>
						Template Parts
					</div>
					{ suggestion.templateParts.map( ( tp ) => (
						<div
							key={ `${ tp.slug }-${ tp.area }` }
							style={ { fontSize: '12px', marginLeft: '8px' } }
						>
							<code>{ tp.slug }</code> → { tp.area }
							{ tp.reason && (
								<span
									style={ {
										color: 'var(--wp-components-color-foreground-secondary, #757575)',
									} }
								>
									{ ' ' }— { tp.reason }
								</span>
							) }
						</div>
					) ) }
				</div>
			) }

			{ suggestion.patternSuggestions?.length > 0 && (
				<div>
					<div
						style={ {
							fontSize: '11px',
							fontWeight: 600,
							textTransform: 'uppercase',
							letterSpacing: '0.5px',
							marginBottom: '4px',
						} }
					>
						Suggested Patterns
					</div>
					{ suggestion.patternSuggestions.map( ( name ) => (
						<div
							key={ name }
							style={ {
								fontSize: '12px',
								marginLeft: '8px',
							} }
						>
							{ patternTitleMap[ name ] || (
								<code>{ name }</code>
							) }
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}
```

- [ ] **Step 2: Build and verify**

Run: `npm run build`
Expected: Build succeeds (the component isn't registered yet, so it won't be included — but the import will be verified in the next task).

- [ ] **Step 3: Commit**

```bash
git add src/templates/TemplateRecommender.js
git commit -m "feat: add TemplateRecommender component for Site Editor"
```

---

### Task 11: Register TemplateRecommender in entry point

**Files:**
- Modify: `src/index.js:20-27`

- [ ] **Step 1: Add import and register**

In `src/index.js`, add the import after the `InserterBadge` import (after line 20):

```js
import TemplateRecommender from './templates/TemplateRecommender';
```

Then add `<TemplateRecommender />` inside the `registerPlugin` render fragment (after `<InserterBadge />`):

```jsx
registerPlugin( 'flavor-agent', {
	render: () => (
		<>
			<PatternRecommender />
			<InserterBadge />
			<TemplateRecommender />
		</>
	),
} );
```

- [ ] **Step 2: Run full test suite and build**

Run: `npm run test:unit && npm run lint:js && npm run build`
Expected: All tests pass, lint clean, build succeeds.

- [ ] **Step 3: Commit**

```bash
git add src/index.js
git commit -m "feat: register TemplateRecommender in plugin entry point"
```

---

## Verification

After all tasks:

- [ ] Run full JS test suite: `npm run test:unit`
- [ ] Run lint: `npm run lint:js`
- [ ] Run build: `npm run build`
- [ ] Verify PHP syntax on all modified files:
  ```bash
  docker exec wordpress-wordpress-1 bash -c '
    for f in \
      wp-content/plugins/flavor-agent/inc/LLM/TemplatePrompt.php \
      wp-content/plugins/flavor-agent/inc/Context/ServerCollector.php \
      wp-content/plugins/flavor-agent/inc/Abilities/TemplateAbilities.php \
      wp-content/plugins/flavor-agent/inc/Abilities/Registration.php \
      wp-content/plugins/flavor-agent/inc/Abilities/InfraAbilities.php \
      wp-content/plugins/flavor-agent/inc/REST/Agent_Controller.php \
      wp-content/plugins/flavor-agent/flavor-agent.php; do
      php -l "$f"
    done
  '
  ```
- [ ] Verify `build/index.js` includes the TemplateRecommender component

### Runtime verification (requires running WordPress instance)

- [ ] Verify REST route is registered:
  ```bash
  docker exec wordpress-wordpress-1 wp eval \
    'echo rest_url("flavor-agent/v1/recommend-template");' --allow-root
  ```
  Expected: prints the full REST URL.

- [ ] Verify missing-config error path (no Azure keys configured):
  ```bash
  docker exec wordpress-wordpress-1 wp eval '
    $result = FlavorAgent\Abilities\TemplateAbilities::recommend_template([
      "templateRef" => "twentytwentyfive//index",
    ]);
    echo is_wp_error($result) ? $result->get_error_code() : "ok";
  ' --allow-root
  ```
  Expected: `missing_credentials` (from ResponsesClient) or `template_not_found` if theme not active.

- [ ] Verify `getEditedPostId()` return shape in the Site Editor:
  Open the browser console in the Site Editor while editing a template and run:
  ```js
  wp.data.select('core/edit-site').getEditedPostId()
  ```
  Confirm whether it returns a string like `"theme//slug"` or a bare slug. Document the result — this validates the `templateRef` handling.

- [ ] Verify the PluginDocumentSettingPanel appears:
  Open the Site Editor, edit any template. Check the Template tab in the sidebar for "AI Template Recommendations". If `canRecommendTemplates` is false (no Azure config), the panel should not appear.

- [ ] Update `STATUS.md`: move `recommend-template` from "Stubbed" to "Working"
