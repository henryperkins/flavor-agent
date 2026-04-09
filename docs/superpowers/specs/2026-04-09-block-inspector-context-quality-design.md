# Block Inspector Recommendation Quality: Richer Context & Better Prompt Formatting

**Date:** 2026-04-09
**Surface:** Block Inspector (recommend-block)
**Goal:** Improve recommendation quality by enriching the context the LLM receives and formatting it for better comprehension.

## Problem

Block Inspector recommendations suffer from two related quality issues:

1. **Context poverty** — The LLM sees the selected block in near-isolation. It knows the block name, attributes, and bare sibling names, but not the design context: what container it lives in, what visual properties surround it, or what compositional role it plays in the page layout.

2. **Suggestion relevance** — Without structural context, the LLM produces generic recommendations. A `core/heading` inside a dark `core/cover` in the header gets the same suggestions as a heading in a blog post body. The prompt doesn't guide the LLM to infer design intent from position.

The client-side collector (`src/context/collector.js`) already gathers `structuralAncestors`, `structuralBranch`, and `structuralIdentity`. But the prompt (`inc/LLM/Prompt.php`) dumps ancestors and branch as raw JSON blobs, and the system prompt barely references structural context. Meanwhile, siblings are bare block names with no visual attributes, and the parent container's design properties are invisible to the LLM.

## Design

Four additive changes, no breaking changes. One minor schema addition to the Abilities API `selectedBlock` input (additive properties only).

### 1. Client-side context enrichment (`src/context/collector.js`)

#### Sibling summaries

New function `getSiblingSummaries(clientId, direction, count)` alongside existing `getSiblingNames()`. Returns an array of mini-summaries:

```js
{
  block: 'core/image',
  role: 'content-image',           // from structuralIdentity if in annotated tree
  visualHints: { align: 'wide' }   // design-relevant attributes only
}
```

**Visual hints allowlist** (extracted from sibling attributes):
- `backgroundColor`, `textColor`, `gradient` (preset slugs)
- `align`, `textAlign`
- `style.color.background`, `style.color.text` (inline styles)
- `layout.type`, `layout.justifyContent`

No full attribute dump. Only design-relevant keys. Missing keys are omitted (not null).

Sibling visual hints are extracted via `getBlockAttributes()` for the allowlisted keys. Sibling structural roles are resolved by looking up each sibling's `clientId` in the cached annotated tree using `findNodePath()` — the tree contains all document blocks, so siblings are present. When a sibling is not found in the annotated tree (e.g., cache miss), the `role` field is omitted from that summary.

The context object gains two new fields:
- `siblingSummariesBefore` — array of summaries (up to 3)
- `siblingSummariesAfter` — array of summaries (up to 3)

Existing `siblingsBefore` and `siblingsAfter` (string arrays) remain unchanged.

#### Parent context

New field `parentContext` added to the context object returned by `collectBlockContext()`:

```js
parentContext: {
  block: 'core/cover',
  title: 'Cover',
  role: 'header-cover',
  job: 'Cover block in the header area.',
  visualHints: {
    backgroundColor: 'contrast',
    dimRatio: 80,
    layout: { type: 'constrained' }
  },
  childCount: 4
}
```

**Implementation:** Uses `getBlockRootClientId(clientId)` (already subscribed in `subscribeToBlockContextSources()`) to find the parent. Reads parent attributes via `getBlockAttributes()`. Looks up the parent node in the cached annotated tree for structural identity (role, job). Extracts visual hints using the same allowlist as siblings, plus `dimRatio` (cover-specific overlay opacity).

When the block is at the root level (no parent), `parentContext` is `null`.

**Visual hints allowlist structure:** A shared base allowlist is defined as a constant array. The parent extraction function extends it with parent-only keys. Both `getSiblingSummaries()` and the parent extraction use a shared `extractVisualHints(attributes, allowlist)` helper.

**Base allowlist** (siblings and parent):
- `backgroundColor`, `textColor`, `gradient` (preset slugs)
- `align`, `textAlign`
- `style.color.background`, `style.color.text` (inline styles via dot-path extraction)
- `layout.type`, `layout.justifyContent`

**Parent-only extensions:**
- `dimRatio` (cover overlay opacity)
- `minHeight`, `minHeightUnit` (container height)
- `tagName` (semantic element: header, footer, main, section, aside)

#### Context signature update

`buildBlockRecommendationContextSignature()` in `src/utils/block-recommendation-context.js` explicitly cherry-picks six fields (`blockContext`, `siblingsBefore`, `siblingsAfter`, `structuralAncestors`, `structuralBranch`, `themeTokens`). New fields are **not** included automatically. This function must be updated to include three additional fields:

```js
return buildContextSignature( {
    blockContext: context?.block || {},
    siblingsBefore: context?.siblingsBefore || [],
    siblingsAfter: context?.siblingsAfter || [],
    structuralAncestors: context?.structuralAncestors || [],
    structuralBranch: context?.structuralBranch || [],
    themeTokens: context?.themeTokens || {},
    parentContext: context?.parentContext || null,                  // NEW
    siblingSummariesBefore: context?.siblingSummariesBefore || [],  // NEW
    siblingSummariesAfter: context?.siblingSummariesAfter || [],    // NEW
} );
```

This ensures recommendations refresh when parent or sibling visual context changes. Since the new fields default to `null`/`[]`, existing contexts without these fields produce the same signature as before — no spurious invalidation.

#### Token budget

The new context sections add an estimated ~200-400 tokens per request. This is bounded by:
- Sibling summaries: max 3 per direction, each a small object with 2-4 keys
- Parent context: single object with ~6 keys
- Formatted ancestors: one line (chain) + one sentence (depth)
- Formatted branch: already capped at `maxDepth:3, maxChildren:6` from the client

No additional slicing or truncation is needed. The existing structural branch limits keep the formatted output bounded.

### 2. Prompt formatting (`inc/LLM/Prompt.php`)

#### `format_structural_ancestors(array $ancestors): string`

New private static method. Converts the ancestor array into a readable chain:

```
template-part(header) "Header slot" > group > cover[bg:contrast, overlay:80%]

Selected block is 3 levels deep in the header template part.
```

Format rules:
- Each ancestor rendered as `blockKey` with optional annotations in brackets/parens
- `template-part` and `template-part(area)` use the area when available
- Visual hints from ancestor summaries rendered as bracket annotations: `[bg:slug, overlay:N%]`
- Role/job quoted after the block key when it adds information beyond the block name
- Chain joined with ` > `
- Depth sentence appended using position data from `structuralIdentity`
- Returns empty string when ancestors array is empty

#### `format_structural_branch(array $branch, string $selected_block_name): string`

New private static method. Converts the branch summary into an indented tree:

```
template-part(header)
  group
    cover [bg:contrast, overlay:80%]
      heading <- selected
      paragraph
      buttons
    navigation (primary-navigation)
```

Format rules:
- Two-space indentation per depth level
- Selected block marked with ` <- selected`
- Visual hint annotations in brackets when present
- Role annotations in parentheses when they differ from the block key
- Respects the client's existing `maxDepth:3, maxChildren:6` limits
- Returns empty string when branch array is empty

#### `format_parent_context(array $parent_context): string`

New private static method. Single-line parent summary:

```
cover [bg:contrast, overlay:80%, layout:constrained] - "Header cover" (4 children)
```

Format rules:
- Block key with visual hints in brackets
- Role/job quoted when present
- Child count in parentheses
- Returns empty string when parent context is null/empty

#### `format_sibling_summaries(array $summaries, string $direction): string`

New private static method. Replaces bare name list when summaries are available:

```
Before:
  - paragraph (center-aligned)
  - image (wide)
After:
  - buttons
```

Format rules:
- One line per sibling, indented with `  - `
- Visual hint annotations in parentheses when present
- Falls back to bare block names when `siblingSummariesBefore`/`After` not in context

#### `build_user()` changes

The `## Surrounding blocks`, `## Structural ancestors`, and `## Structural branch` sections use the new formatters. A new `## Parent container` section is added between `## Surrounding blocks` and `## Structural ancestors`.

Fallback: When new context fields are absent (old client, Abilities API without enrichment), the existing raw-JSON formatting is preserved. The formatters check for the presence of summary/parent fields and fall back to current behavior.

#### `build_system()` additions

~8 lines added after the existing structural identity guidance (line ~83 area):

```
- When structural ancestors show the block is inside a container with a dark background
  (high dimRatio, dark backgroundColor preset), prefer light/contrast text colors and
  ensure sufficient contrast.
- When the parent container uses a constrained layout, respect its width constraints
  in dimension suggestions.
- Use sibling context to maintain visual consistency - if surrounding blocks use a
  particular alignment or color scheme, prefer suggestions that harmonize rather than
  clash.
- When the structural branch shows the block's neighborhood, consider the overall
  section composition when suggesting changes.
```

These are advisory directives. They do not introduce hard constraints or new validation rules.

### 3. PHP backend parity (Abilities API + server-side collector)

Three PHP files need changes to support the new context fields:

#### `BlockContextCollector::for_block()` — new optional parameters

```php
public function for_block(
    string $block_name,
    array $attributes = [],
    array $inner_blocks = [],
    bool $is_inside_content_only = false,
    array $parent_context = [],          // NEW - optional
    array $sibling_summaries_before = [], // NEW - optional
    array $sibling_summaries_after = []   // NEW - optional
): array
```

When provided and non-empty, these are conditionally added to the returned associative array:

```php
$result = [
    'block'          => [ /* ... existing fields ... */ ],
    'siblingsBefore' => [],
    'siblingsAfter'  => [],
    'themeTokens'    => $this->theme_token_collector->for_tokens(),
];

if ( ! empty( $parent_context ) ) {
    $result['parentContext'] = $parent_context;
}
if ( ! empty( $sibling_summaries_before ) ) {
    $result['siblingSummariesBefore'] = $sibling_summaries_before;
}
if ( ! empty( $sibling_summaries_after ) ) {
    $result['siblingSummariesAfter'] = $sibling_summaries_after;
}

return $result;
```

When empty (default), they are omitted — matching current behavior exactly.

#### `ServerCollector::for_block()` — parameter forwarding

`ServerCollector::for_block()` delegates to `BlockContextCollector::for_block()` with the same signature. It must gain the same three optional parameters and forward them through:

```php
public static function for_block(
    string $block_name,
    array $attributes = [],
    array $inner_blocks = [],
    bool $is_inside_content_only = false,
    array $parent_context = [],          // NEW
    array $sibling_summaries_before = [], // NEW
    array $sibling_summaries_after = []   // NEW
): array {
    return self::block_context_collector()->for_block(
        $block_name, $attributes, $inner_blocks, $is_inside_content_only,
        $parent_context, $sibling_summaries_before, $sibling_summaries_after
    );
}
```

All new parameters have empty-array defaults, so existing callers are unaffected.

#### `BlockAbilities::build_context_from_editor_context()` — explicit normalization

This method individually extracts and normalizes each context-level field. It does **not** passthrough arbitrary keys. Three new normalization lines are needed, following the existing pattern for `structuralAncestors` and `structuralBranch`:

```php
$parent_context = self::normalize_map( $context['parentContext'] ?? [] );
if ( ! empty( $parent_context ) ) {
    $normalized['parentContext'] = $parent_context;
}

$sibling_summaries_before = self::normalize_list( $context['siblingSummariesBefore'] ?? [] );
if ( ! empty( $sibling_summaries_before ) ) {
    $normalized['siblingSummariesBefore'] = $sibling_summaries_before;
}

$sibling_summaries_after = self::normalize_list( $context['siblingSummariesAfter'] ?? [] );
if ( ! empty( $sibling_summaries_after ) ) {
    $normalized['siblingSummariesAfter'] = $sibling_summaries_after;
}
```

The same normalization is needed in `build_context_from_selected_block()` for the Abilities path. That method receives `$selected` (the Abilities API input) and calls `ServerCollector::for_block()`. The three new fields are extracted from `$selected`, normalized, and passed as parameters to `ServerCollector::for_block()`:

```php
$parent_context            = self::normalize_map( $selected['parentContext'] ?? [] );
$sibling_summaries_before  = self::normalize_list( $selected['siblingSummariesBefore'] ?? [] );
$sibling_summaries_after   = self::normalize_list( $selected['siblingSummariesAfter'] ?? [] );

$context = ServerCollector::for_block(
    $block_name, $attributes, $inner_blocks, $is_inside_content_only,
    $parent_context, $sibling_summaries_before, $sibling_summaries_after
);
```

Since `build_context_from_editor_context()` delegates to `build_context_from_selected_block()`, the normalization in the selected-block path covers both entry points.

#### `Registration.php` — schema additions

The `selected_block_input_schema()` at line 1086 has `additionalProperties: false`, which means new fields submitted through the Abilities API `selectedBlock` input would be rejected. Three additive property definitions are needed:

```php
'parentContext' => self::open_object_schema( 'Parent container context with visual hints.' ),
'siblingSummariesBefore' => [
    'type'        => 'array',
    'description' => 'Sibling block summaries before the selected block.',
    'items'       => self::open_object_schema(),
    'default'     => [],
],
'siblingSummariesAfter' => [
    'type'        => 'array',
    'description' => 'Sibling block summaries after the selected block.',
    'items'       => self::open_object_schema(),
    'default'     => [],
],
```

These are additive optional properties with empty defaults. Existing clients that don't send them are unaffected. The REST API `editorContext` uses `additionalProperties: true` and needs no schema change.

### 4. Test strategy

**No existing tests change.** All new fields are additive. Old fields remain with identical values.

#### New PHP unit tests (`tests/phpunit/PromptFormattingTest.php`)

The four new formatting methods are `private static`. Rather than testing them directly via reflection, all formatting tests go through the public `build_user()` method, asserting on the assembled prompt string. This tests the formatting in context and avoids coupling tests to private API.

- `test_build_user_formats_ancestors_as_readable_chain` — asserts chain format (` > ` separator, bracket annotations) appears in prompt, not raw JSON
- `test_build_user_formats_branch_as_indented_tree` — asserts indented tree with `<- selected` marker
- `test_build_user_includes_parent_container_section` — asserts `## Parent container` section with visual hints
- `test_build_user_formats_sibling_summaries_with_hints` — asserts `Before:` / `After:` sections with parenthesized annotations
- `test_build_user_falls_back_to_bare_siblings_without_summaries` — old-style context produces comma-separated names
- `test_build_user_falls_back_to_json_ancestors_without_summaries` — edge case: ancestors present but no visual hints still produce readable output
- `test_build_user_omits_parent_section_when_null` — no `## Parent container` in output
- `test_build_user_preserves_existing_sections` — integration: all existing sections still present with new fields added

#### New JS unit tests (`src/context/__tests__/collector.test.js` — additions)

- `getSiblingSummaries returns visual hints for allowlisted attributes`
- `getSiblingSummaries omits non-allowlisted attributes`
- `getSiblingSummaries returns empty hints when block has no visual attributes`
- `getSiblingSummaries falls back to getBlockAttributes when not in annotated tree`
- `collectBlockContext includes parentContext for nested block`
- `collectBlockContext returns null parentContext for root-level block`
- `collectBlockContext parentContext includes structural identity from annotated tree`
- `collectBlockContext preserves existing siblingsBefore/After string arrays`

#### New JS unit tests (`src/utils/__tests__/block-recommendation-context.test.js` — additions)

- `signature includes parentContext when present`
- `signature includes siblingSummariesBefore/After when present`
- `signature unchanged when new fields are null/empty` — regression: old contexts produce same signature

#### Regression safety

- All existing `collector.test.js` assertions pass unchanged
- All existing `PromptTest.php` / `BlockAbilitiesTest.php` / `RegistrationTest.php` assertions pass unchanged
- Context signature produces identical values for contexts without new fields (new fields default to `null`/`[]`)
- REST API contract unchanged — new fields are optional in both request and response
- Abilities API schema is additive only — new optional properties with defaults
- E2E tests unaffected — they test recommendation flow, not prompt content

## Files changed

| File | Change type | Description |
|------|------------|-------------|
| `src/context/collector.js` | Modified | Add `getSiblingSummaries()`, `extractVisualHints()`, `parentContext` extraction, new context fields |
| `src/utils/block-recommendation-context.js` | Modified | Add `parentContext`, `siblingSummariesBefore`, `siblingSummariesAfter` to signature |
| `inc/LLM/Prompt.php` | Modified | Add 4 formatting methods, update `build_user()` sections, add system prompt directives |
| `inc/Context/BlockContextCollector.php` | Modified | Add optional parent/sibling parameters to `for_block()` |
| `inc/Context/ServerCollector.php` | Modified | Forward new optional parameters to `BlockContextCollector::for_block()` |
| `inc/Abilities/BlockAbilities.php` | Modified | Add explicit normalization for `parentContext`, `siblingSummariesBefore/After` in both `build_context_from_editor_context()` and `build_context_from_selected_block()` |
| `inc/Abilities/Registration.php` | Modified | Add `parentContext`, `siblingSummariesBefore`, `siblingSummariesAfter` properties to `selected_block_input_schema()` |
| `tests/phpunit/PromptFormattingTest.php` | New | Integration tests for prompt formatting through `build_user()` |
| `src/context/__tests__/collector.test.js` | Modified | Add tests for sibling summaries and parent context |
| `src/utils/__tests__/block-recommendation-context.test.js` | Modified | Add tests for signature inclusion of new fields |

## What this does NOT change

- REST API endpoint signatures or response shape
- Store actions, selectors, or state shape
- UI components (inspector panels, suggestion chips, etc.)
- Response parsing or validation (`parse_response`, `enforce_block_context_rules`)
- Activity logging or undo mechanics
- Any other recommendation surface (navigation, template, pattern, style, content)
