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

**Visual hints allowlist** (source extraction paths from sibling attributes):
- `backgroundColor`, `textColor`, `gradient` (preset slugs)
- `align`, `textAlign`
- `style.color.background`, `style.color.text` (inline styles)
- `layout.type`, `layout.justifyContent`

No full attribute dump. Only design-relevant keys. Missing keys are omitted (not null).

The allowlist above describes the source paths only. The serialized `visualHints`
payload uses one canonical nested-object shape rather than flattening dot-paths:

```js
{
  backgroundColor: 'contrast',
  align: 'wide',
  style: { color: { text: 'var(--wp--preset--color--contrast)' } },
  layout: { type: 'constrained', justifyContent: 'center' }
}
```

Top-level attribute keys stay top-level, while nested `style.*` and `layout.*`
paths are preserved under `style` and `layout`. JS signatures, PHP
normalizers, and `Registration.php` schemas should all use this nested-object
form so equivalent contexts hash and validate consistently.

Sibling visual hints are extracted via `getBlockAttributes()` for the allowlisted keys. Sibling structural roles are resolved by looking up each sibling's `clientId` in the cached annotated tree using `findNodePath()`. That lookup is best-effort: the collector's annotated tree is depth-bounded (`getAnnotatedBlockTree()` currently uses its default max depth), so a sibling may be missing because of cache staleness or because the node falls outside the annotated-tree depth window. When a sibling is not found, the `role` field is omitted from that summary, but visual hints still come from `getBlockAttributes()`.

The context object gains two new fields:
- `siblingSummariesBefore` — array of summaries (up to 3)
- `siblingSummariesAfter` — array of summaries (up to 3)

Existing `siblingsBefore` and `siblingsAfter` (string arrays) remain unchanged.

#### Parent context

New optional field `parentContext` added to the context object returned by
`collectBlockContext()` when the selected block has a parent:

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

**Implementation:** Uses `getBlockRootClientId(clientId)` to find the parent.
Reads parent attributes via `getBlockAttributes()`. Looks up the parent node in
the cached annotated tree for structural identity (role, job). That identity
lookup is also best-effort because the annotated tree is depth-bounded; when
the parent is not found, `role` / `job` are omitted and the prompt still
receives the parent's block name, child count, and visual hints. Visual hints
are extracted using the same allowlist as siblings, plus `dimRatio`
(cover-specific overlay opacity).

When the block is at the root level (no parent), `parentContext` is omitted
from the context object entirely. The signature helper can still hash
`context?.parentContext || null` internally so missing and empty values
normalize the same way, but the documented request contract should prefer
omission over a nullable field.

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

#### Structural summaries stay unchanged

`structuralAncestors` and `structuralBranch` keep their current schema. This design does **not** add `visualHints` to those summaries. Prompt formatting for those sections therefore uses the existing structural fields already present in the summaries (`block`, `title`, `role`, `job`, `location`, `templateArea`, `templatePartSlug`, and for branch nodes `children`, `isSelected`, `childCount`, `moreChildren`) and relies on `parentContext` for container-level visual hints. The branch formatter must preserve `childCount` / `moreChildren` cues so truncated client summaries do not silently lose information about undisplayed children.

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

This ensures recommendations refresh when parent or sibling visual context changes. Because the signature payload now explicitly includes three new keys, existing contexts will receive a different signature after this change. That one-time invalidation is desirable so cached recommendations refresh against the richer prompt. Afterward, the `null`/`[]` defaults keep signatures stable for unchanged contexts.

#### Live selector subscriptions

Adding the new fields to the signature helper is not sufficient by itself.
`getLiveBlockContextSignature()` only recomputes when
`subscribeToBlockContextSources()` touches the selector reads that drive the
context. Update that subscription helper to explicitly:

- read `getBlockAttributes( rootId )` when a parent exists so parent visual
  changes invalidate the live signature
- derive the local sibling window from `getBlockOrder( rootId )` and read
  `getBlockAttributes()` for the up-to-3 sibling ids before and after the
  selected block that feed `getSiblingSummaries()`
- continue reading `getBlockOrder( rootId )` and `getBlocks()` so structural
  tree and ordering changes still invalidate the signature

This subscription change is what makes live recommendation freshness react to
parent/sibling visual edits while the selected `clientId` stays the same.

#### Token budget

The new context sections add an estimated ~200-400 tokens per request. This is bounded by:
- Sibling summaries: max 3 per direction, each a small object with 2-4 keys
- Parent context: single object with ~6 keys
- Formatted ancestors: one line (chain) + one sentence (depth)
- Formatted branch: already capped at `maxDepth:3, maxChildren:6` from the client

No additional slicing or truncation is needed. The existing structural branch limits keep the formatted output bounded.

### 2. Prompt formatting (`inc/LLM/Prompt.php`)

#### `format_structural_ancestors(array $ancestors, array $selected_identity = []): string`

New private static method. Converts the ancestor array into a readable chain:

```
template-part(header) "Header slot" > group > cover

Selected block is 3 levels deep in the header template part.
```

Format rules:
- Each ancestor rendered as `blockKey` with optional annotations in parens/quotes
- `title` is optional metadata; when present it may be used to improve readability, but the formatter must not depend on it
- `template-part` and `template-part(area)` use the area when available
- Role/job quoted after the block key when it adds information beyond the block name
- Chain joined with ` > `
- Depth sentence appended when `$selected_identity['position']['depth']` is available
- The depth sentence may reference `templateArea` or `location` from `$selected_identity` when available
- Returns empty string when ancestors array is empty

#### `format_structural_branch(array $branch, string $selected_block_name): string`

New private static method. Converts the branch summary into an indented tree:

```
template-part(header)
  group (8 children)
    cover (header-cover)
      heading <- selected
      paragraph
      buttons
    navigation (primary-navigation)
    ... +2 more children not shown
```

Format rules:
- Two-space indentation per depth level
- Selected block marked with ` <- selected`
- Role annotations in parentheses when they add information beyond the block key
- `childCount` is surfaced for containers when it adds useful context
- `moreChildren` is surfaced as an explicit truncation cue (for example `... +2 more children not shown`) so the model knows the tree is partial
- Uses the existing structural summary fields only; no visual-hint expansion is required for branch nodes
- Respects the client's existing `maxDepth:3, maxChildren:6` limits
- Returns empty string when branch array is empty

#### `format_parent_context(?array $parent_context): string`

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

The `## Surrounding blocks` section uses sibling summaries when present and
falls back to the existing bare-name lists otherwise. A new
`## Parent container` section is added between `## Surrounding blocks` and
`## Structural ancestors`. The `## Structural ancestors` and
`## Structural branch` sections switch from raw JSON to the new readable
formatters for all current clients, because they only depend on structural
summary fields already present in the existing context payload. This is an
intentional prompt-behavior change for existing structural sections, not a
purely additive prompt change.

Fallback: When `parentContext` is absent, the parent section is omitted. When
`siblingSummariesBefore`/`After` are absent, the existing bare-name `Before:` /
`After:` output is preserved. No raw-JSON fallback is needed for ancestors or
branch.

#### `build_system()` additions

~8 lines added after the existing structural identity guidance (line ~83 area):

```
- When parent container context shows a dark or high-overlay container
  (for example, high dimRatio or a dark/contrast background preset), prefer
  light/contrast text colors and ensure sufficient contrast.
- When the parent container uses a constrained layout, respect its width constraints
  in dimension suggestions.
- Use sibling context to maintain visual consistency - if surrounding blocks use a
  particular alignment or color scheme, prefer suggestions that harmonize rather than
  clash.
- Use structural ancestors and the structural branch to infer section role and
  composition (header, footer, hero, article body, sidebar) when deciding whether a
  suggestion fits the selected block's neighborhood.
```

These are advisory directives. They do not introduce hard constraints or new validation rules.

### 3. PHP backend parity (Abilities API + server-side collector)

Four PHP files need changes to support the new context fields:

#### Shared normalization helpers for additive parent/sibling context

The current `selectedBlock` normalization path accepts arbitrary nested objects. That is too loose for these new fields because it would let direct Abilities callers send full attribute dumps, unsupported visual-hint keys, or arbitrarily long sibling-summary arrays.

Add shared private normalizers in `BlockAbilities` so both the `editorContext` and `selectedBlock` paths enforce the same bounded contract the client collector uses:

- `normalize_parent_context( mixed $raw_parent ): array`
- `normalize_sibling_summaries( mixed $raw_summaries ): array`
- `normalize_visual_hints( mixed $raw_hints, bool $allow_parent_extensions = false ): array`

Behavior:

- Cherry-pick only documented top-level keys for `parentContext` (`block`, `title`, `role`, `job`, `visualHints`, `childCount`)
- Cherry-pick only documented top-level keys for sibling summaries (`block`, `role`, `visualHints`)
- Normalize `visualHints` to the same allowlist and canonical nested-object
  shape as the client collector
- Allow parent-only visual hint extensions (`dimRatio`, `minHeight`, `minHeightUnit`, `tagName`) only for `parentContext`
- Drop unsupported keys entirely rather than preserving them
- Clamp sibling summary arrays to the first 3 items per direction to match the client collector and token-budget assumptions
- Omit empty `visualHints`, empty summary objects, and empty parent context after normalization

For compatibility, the server normalizers may treat `null`, missing, or empty
`parentContext` values as equivalent and drop them. The documented
`selectedBlock` contract, however, should describe `parentContext` as an
optional object that is omitted when unavailable rather than as a nullable
field.

These helpers keep live editor requests and direct Abilities callers aligned, and they preserve the prompt's bounded shape even when the caller is not the block editor.

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

#### `BlockAbilities::build_context_from_editor_context()` — explicit normalization from raw editor context

This method individually extracts and normalizes each context-level field from the raw `editorContext` payload. It does **not** passthrough arbitrary keys. It also rebuilds a reduced `$selected` array and delegates only that subset to `build_context_from_selected_block()`, so additive top-level context fields are **not** preserved automatically through delegation. The new fields must therefore be normalized from `$context` inside `build_context_from_editor_context()` itself:

```php
$parent_context = self::normalize_parent_context( $context['parentContext'] ?? [] );
if ( ! empty( $parent_context ) ) {
    $normalized['parentContext'] = $parent_context;
}

$sibling_summaries_before = self::normalize_sibling_summaries( $context['siblingSummariesBefore'] ?? [] );
if ( ! empty( $sibling_summaries_before ) ) {
    $normalized['siblingSummariesBefore'] = $sibling_summaries_before;
}

$sibling_summaries_after = self::normalize_sibling_summaries( $context['siblingSummariesAfter'] ?? [] );
if ( ! empty( $sibling_summaries_after ) ) {
    $normalized['siblingSummariesAfter'] = $sibling_summaries_after;
}
```

This is not a blind passthrough. The new normalizers intentionally drop unsupported keys so the server preserves the documented prompt contract even if the client payload is noisy.

#### `BlockAbilities::build_context_from_selected_block()` — normalization and forwarding for the Abilities input path

The Abilities `selectedBlock` path still needs the same three fields normalized from `$selected` and forwarded into `ServerCollector::for_block()`:

```php
$parent_context            = self::normalize_parent_context( $selected['parentContext'] ?? [] );
$sibling_summaries_before  = self::normalize_sibling_summaries( $selected['siblingSummariesBefore'] ?? [] );
$sibling_summaries_after   = self::normalize_sibling_summaries( $selected['siblingSummariesAfter'] ?? [] );

$context = ServerCollector::for_block(
    $block_name, $attributes, $inner_blocks, $is_inside_content_only,
    $parent_context, $sibling_summaries_before, $sibling_summaries_after
);
```

Because the live block inspector sends `editorContext` while the Abilities API accepts `selectedBlock`, normalization is required in both methods: `build_context_from_editor_context()` preserves the live client snapshot, and `build_context_from_selected_block()` preserves direct Abilities callers.

#### `Registration.php` — schema additions

The `selected_block_input_schema()` at line 1086 has `additionalProperties: false`, which means new fields submitted through the Abilities API `selectedBlock` input would be rejected. Three additive property definitions are needed:

```php
'parentContext' => self::parent_context_schema(), // optional object; omit when unavailable
'siblingSummariesBefore' => [
    'type'        => 'array',
    'description' => 'Sibling block summaries before the selected block.',
    'items'       => self::sibling_summary_schema(),
    'maxItems'    => 3,
    'default'     => [],
],
'siblingSummariesAfter' => [
    'type'        => 'array',
    'description' => 'Sibling block summaries after the selected block.',
    'items'       => self::sibling_summary_schema(),
    'maxItems'    => 3,
    'default'     => [],
],
```

Those helper schemas should document the actual bounded shape rather than using a generic open object:

- `parent_context_schema()` with `block`, `title`, `role`, `job`, `childCount`, and `visualHints`
- `sibling_summary_schema()` with `block`, `role`, and `visualHints`
- `visual_hints_schema( bool $include_parent_extensions = false )` with the
  allowlisted keys only, using the canonical nested-object shape for
  `style.color.*` and `layout.*`

This keeps direct Abilities callers aligned with the documented contract and prevents request-side schema validation from implying that arbitrary payloads are supported.

While touching `Registration.php`, `structural_summary_item_schema()` should
also add the existing optional `title`, `childCount`, and `moreChildren`
fields. The client already emits them through `toStructuralSummary()` /
`summarizeTree()`, and the new prompt formatters treat them as real branch
context rather than incidental extras. When `$include_children` is true,
`children.items` should use the same bounded structural-summary shape
(implemented with a small depth-limited helper that matches the client's
`maxDepth` window) instead of a generic open object so direct Abilities callers
see the same documented child item contract.

These are additive optional properties. `parentContext` is an optional object
that is omitted when unavailable, while the sibling summary arrays default to
`[]`. Existing clients that do not send them are unaffected. The REST
`editorContext` route remains an open object at the route level, but
`BlockAbilities` still normalizes these fields explicitly before prompt
construction.

### 4. Test strategy

**Most existing tests should remain unchanged, but prompt-formatting coverage is
not purely additive.** The new context fields are additive, while
`Prompt::build_user()` intentionally changes the rendered content of the
existing `## Structural ancestors` and `## Structural branch` sections from raw
JSON to readable text.

#### New PHP unit tests (`tests/phpunit/PromptFormattingTest.php`)

The four new formatting methods are `private static`. Rather than testing them directly via reflection, all formatting tests go through the public `build_user()` method, asserting on the assembled prompt string. This tests the formatting in context and avoids coupling tests to private API.

- `test_build_user_formats_ancestors_as_readable_chain` — asserts chain format (` > ` separator, readable annotations) appears in prompt, not raw JSON
- `test_build_user_appends_depth_sentence_when_position_available` — asserts selected block depth sentence is included when `structuralIdentity.position.depth` is present
- `test_build_user_formats_branch_as_indented_tree` — asserts indented tree with `<- selected` marker
- `test_build_user_preserves_branch_truncation_cues` — asserts `childCount` / `moreChildren` become readable "partial tree" cues instead of being dropped
- `test_build_user_includes_parent_container_section` — asserts `## Parent container` section with visual hints
- `test_build_user_formats_sibling_summaries_with_hints` — asserts `Before:` / `After:` sections with parenthesized annotations
- `test_build_user_falls_back_to_bare_siblings_without_summaries` — old-style context produces comma-separated names
- `test_build_user_omits_parent_section_when_parent_context_absent` — no `## Parent container` in output
- `test_build_user_preserves_existing_sections` — integration: all existing sections still present with new fields added

#### PHP unit test additions (`tests/phpunit/BlockAbilitiesTest.php`)

- `test_prepare_recommend_block_input_preserves_parent_context_from_editor_context`
- `test_prepare_recommend_block_input_preserves_sibling_summaries_from_editor_context`
- `test_prepare_recommend_block_input_normalizes_parent_and_sibling_context_from_selected_block`
- `test_prepare_recommend_block_input_drops_unsupported_visual_hint_keys_and_caps_sibling_summary_length`

#### PHP unit test additions (`tests/phpunit/RegistrationTest.php`)

- `test_register_abilities_exposes_parent_context_and_sibling_summary_selected_block_schema`
- `test_parent_context_and_sibling_summary_schema_document_bounded_known_properties`
- `test_structural_summary_schema_exposes_title_child_count_and_more_children`
- `test_structural_summary_children_schema_uses_bounded_known_properties`

#### PHP unit test additions (`tests/phpunit/ServerCollectorTest.php`)

- `test_for_block_preserves_additive_parent_and_sibling_context_fields`

#### New JS unit tests (`src/context/__tests__/collector.test.js` — additions)

- `getSiblingSummaries returns visual hints for allowlisted attributes`
- `getSiblingSummaries omits non-allowlisted attributes`
- `getSiblingSummaries returns empty hints when block has no visual attributes`
- `getSiblingSummaries falls back to getBlockAttributes when not in annotated tree or outside the annotated-tree depth window`
- `collectBlockContext includes parentContext for nested block`
- `collectBlockContext omits parentContext for root-level block`
- `collectBlockContext parentContext includes structural identity from annotated tree`
- `collectBlockContext omits parent/sibling role metadata when structural lookup misses due to depth-bounded annotated tree`
- `collectBlockContext preserves existing siblingsBefore/After string arrays`
- `getLiveBlockContextSignature changes when parent visual context changes`
- `getLiveBlockContextSignature changes when sibling visual context changes`

#### New JS unit tests (`src/utils/__tests__/block-recommendation-context.test.js` — additions)

- `signature includes parentContext when present`
- `signature includes siblingSummariesBefore/After when present`
- `signature treats missing new fields the same as null/empty` — regression: equivalent contexts remain stable after enrichment

#### Live freshness verification

The signature helper tests are necessary but not sufficient. Freshness also
depends on `getLiveBlockContextSignature()` subscribing to the right selector
sources before calling `collectBlockContext()`. Add explicit coverage for the
live collector path so parent/sibling visual edits cause recommendation
refreshes even when the selected `clientId` is unchanged, and so the new parent
and sibling `getBlockAttributes()` subscription reads are exercised directly.

#### Regression safety

- All existing `collector.test.js` assertions pass unchanged
- Existing `BlockAbilitiesTest.php` / `RegistrationTest.php` assertions unrelated
  to the new fields should pass unchanged
- Prompt behavior changes intentionally: structural section content is reformatted
  even when new parent/sibling fields are absent, so prompt tests must assert
  the new readable output rather than raw JSON
- Context signature intentionally changes once because the signature payload now includes three new keys; unchanged contexts remain stable after that because the new fields default to `null`/`[]`
- REST route and recommendation response shape unchanged — new context fields are optional request-side additions on the editor/Abilities input path
- Abilities API schema additions are additive — sibling arrays default to `[]`,
  and `parentContext` is an optional object omitted when unavailable
- E2E tests unaffected — they test recommendation flow, not prompt content

## Files changed

| File | Change type | Description |
|------|------------|-------------|
| `src/context/collector.js` | Modified | Add `getSiblingSummaries()`, `extractVisualHints()`, `parentContext` extraction, new context fields, explicit live selector subscriptions for freshness, and depth-bounded structural lookup fallback |
| `src/utils/block-recommendation-context.js` | Modified | Add `parentContext`, `siblingSummariesBefore`, `siblingSummariesAfter` to signature |
| `inc/LLM/Prompt.php` | Modified | Add 4 formatting methods, update `build_user()` sections, preserve branch truncation cues, add system prompt directives |
| `inc/Context/BlockContextCollector.php` | Modified | Add optional parent/sibling parameters to `for_block()` |
| `inc/Context/ServerCollector.php` | Modified | Forward new optional parameters to `BlockContextCollector::for_block()` |
| `inc/Abilities/BlockAbilities.php` | Modified | Normalize `parentContext` and sibling summaries with allowlisted, bounded helpers in both the `editorContext` and `selectedBlock` paths |
| `inc/Abilities/Registration.php` | Modified | Add `parentContext`, `siblingSummariesBefore`, `siblingSummariesAfter` properties with dedicated bounded schemas, plus `title`, `childCount`, `moreChildren`, and bounded child-item shape on structural summary items |
| `tests/phpunit/PromptFormattingTest.php` | New | Integration tests for prompt formatting through `build_user()` |
| `tests/phpunit/BlockAbilitiesTest.php` | Modified | Add coverage for `editorContext` and `selectedBlock` normalization of parent/sibling context |
| `tests/phpunit/RegistrationTest.php` | Modified | Assert the selected-block schema exposes `parentContext` and sibling summary fields plus bounded structural summary/truncation metadata |
| `tests/phpunit/ServerCollectorTest.php` | Modified | Add additive forwarding coverage for parent and sibling context fields |
| `src/context/__tests__/collector.test.js` | Modified | Add tests for sibling summaries, parent context, depth-bounded structural fallbacks, and live signature freshness |
| `src/utils/__tests__/block-recommendation-context.test.js` | Modified | Add tests for signature inclusion of new fields |

## What this does NOT change

- The recommend-block REST route or its response payload shape
- Store actions, selectors, or state shape
- UI components (inspector panels, suggestion chips, etc.)
- Response parsing or validation (`parse_response`, `enforce_block_context_rules`)
- Activity logging or undo mechanics
- Any other recommendation surface (navigation, template, pattern, style, content)
