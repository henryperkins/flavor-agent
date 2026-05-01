# Code Duplication Audit — Flavor Agent

> **Generated:** 2026-04-02
> **Scope:** `src/` (JS), `inc/` (PHP), `tests/` and `src/**/__tests__/` (tests)
> **Metric:** ~2,000 consolidatable lines across 18 documented patterns in 60+ files, plus 4 follow-up candidates identified during verification

---

## Table of Contents

1. [Completed Consolidations](#1-completed-consolidations)
2. [Executive Summary](#2-executive-summary)
3. [JS Source Patterns](#3-js-source-patterns)
   - [D01 — Fetch Recommendation Thunks](#d01--fetch-recommendation-thunks)
   - [D02 — Suggestion Apply Feedback Hook](#d02--suggestion-apply-feedback-hook)
   - [D03 — `formatCount()` Clones](#d03--formatcount-clones)
   - [D04 — `humanizeLabel()` / `humanizeValue()` Clones](#d04--humanizelabel--humanizevalue-clones)
   - [D05 — Context Signature Builders](#d05--context-signature-builders)
   - [D06 — Suggestion Panel Grouping](#d06--suggestion-panel-grouping)
   - [D07 — Recommender Lifecycle Cleanup](#d07--recommender-lifecycle-cleanup)
   - [D08 — MutationObserver with Timeout](#d08--mutationobserver-with-timeout)
4. [PHP Source Patterns](#4-php-source-patterns)
   - [D09 — `normalize_input()` / `normalize_map()`](#d09--normalize_input--normalize_map)
   - [D10 — WordPress Docs Guidance Collection](#d10--wordpress-docs-guidance-collection)
   - [D11 — HTTP Client Request/Retry/Parse](#d11--http-client-requestretryparse)
   - [D12 — REST Route Handler Structure](#d12--rest-route-handler-structure)
   - [D13 — Prompt `parse_response()` Boilerplate](#d13--prompt-parse_response-boilerplate)
5. [Test Code Patterns](#5-test-code-patterns)
   - [D14 — `@wordpress/components` Mock](#d14--wordpresscomponents-mock)
   - [D15 — DOM Container / React Root Setup](#d15--dom-container--react-root-setup)
   - [D16 — `@wordpress/data` Mock](#d16--wordpressdata-mock)
   - [D17 — Selector / State Factories](#d17--selector--state-factories)
   - [D18 — Suggestion Fixture Factories](#d18--suggestion-fixture-factories)
6. [Consolidation Priority Matrix](#6-consolidation-priority-matrix)
7. [Proposed Shared Module Map](#7-proposed-shared-module-map)
8. [Verification Follow-Up](#8-verification-follow-up)

---

## 1. Completed Consolidations

| Date | Pattern | Files Changed | Shared Destination |
|------|---------|---------------|--------------------|
| 2026-04-02 | D01 — Fetch recommendation thunks | 6 thunks → 1 helper | `src/store/index.js` |
| 2026-04-02 | D02 — Suggestion apply feedback | 3 components → 1 hook | `src/inspector/use-suggestion-apply-feedback.js` |
| 2026-04-02 | D06 — Suggestion panel grouping | 2 components → 1 utility | `src/inspector/group-by-panel.js` |
| 2026-04-02 | D10 — Docs guidance collection | 5 methods + 1 variant → 1 helper | `inc/Support/CollectsDocsGuidance.php` |
| 2026-04-02 | D03 — `formatCount()` | 9 files → 1 utility | `src/utils/format-count.js` |
| 2026-04-02 | D04 — `humanizeString()` | 2 files → merged | `src/utils/format-count.js` |
| 2026-04-02 | V03 — `joinClassNames()` | 3 files → 1 utility | `src/utils/format-count.js` |
| 2026-04-02 | D09 — `normalize_input()` | 4 classes → 1 trait | `inc/Support/NormalizesInput.php` |

**Total savings:** ~710 lines consolidated across JS and PHP

---

## 2. Executive Summary

| Layer | Patterns | Affected Files | Estimated Savings |
|-------|----------|----------------|-------------------|
| JS source | 8 | ~20 | ~800 lines |
| PHP source | 5 | ~15 | ~400 lines |
| Tests | 5 | ~30 | ~900 lines |
| **Total** | **18** | **~65** | **~2,000 lines** |

Three patterns account for the majority of savings:
1. **D01 — Fetch Recommendation Thunks** (~350 lines, 7 near-identical thunks in one file)
2. **D17 — Selector / State Factories** (~400 lines, 5+ test files)
3. **D14 — `@wordpress/components` Mock** (~250 lines, 15 test files)

Verification pass corrections: D05 had one missing JS occurrence, D13 had one related non-prompt parser occurrence, D14-D17 test counts were materially higher than first captured, D07 is stale/unconfirmed in the current tree, and D08 currently exists only inside `PatternRecommender.js`.

---

## 3. JS Source Patterns

### D01 — Fetch Recommendation Thunks

**Severity:** Critical · **Effort:** High · **Savings:** ~350 lines

**Status:** ✅ Done (2026-04-02)

**Location:** `src/store/index.js`

Seven thunks follow the same skeleton: abort previous → create AbortController → dispatch loading status → `apiFetch` POST → dispatch result or error → cleanup in `finally`.

| Thunk | Lines | Endpoint | AbortController | Request Token | Input Destructuring | Response Shape |
|-------|-------|----------|-----------------|---------------|---------------------|----------------|
| `fetchBlockRecommendations` | 1366–1421 | `/recommend-block` | ❌ | ✅ `getBlockRequestToken` | 3 separate params | Transformed via `sanitizeRecommendationsForContext()` |
| `fetchPatternRecommendations` | 1558–1601 | `/recommend-patterns` | ✅ `_patternAbort` | ❌ | `input` | `result.recommendations \|\| []` |
| `fetchNavigationRecommendations` | 1603–1671 | `/recommend-navigation` | ✅ `_navigationAbort` | ✅ `getNavigationRequestToken` | Destructures `blockClientId` | Raw `result` |
| `fetchTemplateRecommendations` | 2447–2508 | `/recommend-template` | ✅ `_templateAbort` | ✅ `getTemplateRequestToken` | `input` whole | Raw `result` |
| `fetchTemplatePartRecommendations` | 2510–2571 | `/recommend-template-part` | ✅ `_templatePartAbort` | ✅ `getTemplatePartRequestToken` | `input` whole | Raw `result` |
| `fetchGlobalStylesRecommendations` | 2573–2637 | `/recommend-style` | ✅ `_globalStylesAbort` | ✅ `getGlobalStylesRequestToken` | Destructures `contextSignature` | Raw `result` |
| `fetchStyleBookRecommendations` | 2639–2703 | `/recommend-style` | ✅ `_styleBookAbort` | ✅ `getStyleBookRequestToken` | Destructures `contextSignature` | Raw `result` |

**Identical code across 6 of 7 thunks (all except Block):**

```js
// 1. Abort previous
if ( actions._<surface>Abort ) {
    actions._<surface>Abort.abort();
}
const controller = new AbortController();
actions._<surface>Abort = controller;

// 2. Request token
const requestToken = ( select.get<Surface>RequestToken?.() || 0 ) + 1;

// 3. Loading dispatch
dispatch( actions.set<Surface>Status( 'loading', null, requestToken ) );

// 4. apiFetch with signal
const result = await apiFetch( {
    path: '/flavor-agent/v1/<endpoint>',
    method: 'POST',
    data: <requestData>,
    signal: controller.signal,
} );

// 5. Success dispatch
dispatch( actions.set<Surface>Recommendations( <id>, result, <prompt>, requestToken ) );

// 6. Error handling
catch ( err ) {
    if ( err.name === 'AbortError' ) return;
    dispatch( actions.set<Surface>Recommendations( <id>, { suggestions: [], explanation: '' }, <prompt>, requestToken ) );
    dispatch( actions.set<Surface>Status( 'error', err?.message || '<Surface> recommendation request failed.', requestToken ) );
}

// 7. Finally
finally {
    if ( actions._<surface>Abort === controller ) {
        actions._<surface>Abort = null;
    }
}
```

**Notable outliers:**
- `fetchBlockRecommendations` — intentionally remains bespoke because it has no AbortController flow, uses per-block request state, and performs `sanitizeRecommendationsForContext()` before dispatching an explicit `'ready'` state
- `fetchPatternRecommendations` — still has no request token, but now participates in the shared helper via surface-specific callbacks
- `fetchGlobalStylesRecommendations` and `fetchStyleBookRecommendations` — now share the same helper and retain only surface-specific request extraction and dispatch callbacks

**Completed consolidation:**

```js
function getEmptySuggestionResponse() {
    return { suggestions: [], explanation: '' };
}

async function runAbortableRecommendationRequest( {
    abortKey,
    buildRequest = () => ( {} ),
    dispatch,
    endpoint,
    input,
    onError,
    onLoading,
    onSuccess,
    select,
} ) { ... }
```

Implementation note: this landed as an internal helper in `src/store/index.js` instead of a new `src/store/create-fetch-thunk.js` module. That kept the refactor local while still removing the repeated abort/request/cleanup skeleton from six thunks.

Verification note: focused coverage in `src/store/__tests__/store-actions.test.js` now includes navigation request failure fallback and pattern abort cleanup, in addition to the existing token/read-path assertions for the tokenized surfaces.

---

### D02 — Suggestion Apply Feedback Hook

**Severity:** High · **Effort:** Medium · **Savings:** ~150 lines

**Status:** ✅ Done (2026-04-02)

**Files (3):**

| File | Lines | Has `feedback` state? | Feedback shape |
|------|-------|-----------------------|----------------|
| `src/inspector/BlockRecommendationsPanel.js` (historical equivalent of `SettingsRecommendations`) | N/A | ✅ | `{ key, panel, label, type }` |
| `src/inspector/SuggestionChips.js` (historical equivalent of `StylesRecommendations`) | N/A | ❌ | Only `appliedKey` |
| `src/inspector/SuggestionChips.js` | 15–66 | ✅ | `{ key, label }` |

**Shared skeleton (identical across all 3):**

```js
const [ appliedKey, setAppliedKey ] = useState( null );
const [ feedback, setFeedback ] = useState( null );        // absent in Settings
const resetTimerRef = useRef( null );

// Cleanup on unmount
useEffect( () => {
    return () => { if ( resetTimerRef.current ) window.clearTimeout( resetTimerRef.current ); };
}, [] );

// Reset on suggestion change
useEffect( () => {
    if ( resetTimerRef.current ) { window.clearTimeout( resetTimerRef.current ); resetTimerRef.current = null; }
    setAppliedKey( null );
    setFeedback( null );  // absent in Settings
}, [ suggestions ] );

// Apply handler with timer
const handleApply = useCallback( async ( suggestion, key? ) => {
    const didApply = await applySuggestion( clientId, suggestion );
    if ( ! didApply ) return;
    if ( resetTimerRef.current ) window.clearTimeout( resetTimerRef.current );
    setAppliedKey( key );
    setFeedback( { ... } );
    resetTimerRef.current = window.setTimeout( () => {
        setAppliedKey( null ); setFeedback( null ); resetTimerRef.current = null;
    }, FEEDBACK_MS );
}, [ clientId, applySuggestion ] );
```

**Completed consolidation:** Shared hook `src/inspector/use-suggestion-apply-feedback.js` now owns the timer lifecycle, suggestion-change reset, and apply handler.

```js
useSuggestionApplyFeedback( {
    applySuggestion,
    buildFeedback,
    clientId,
    getKey,
    suggestions,
} )
```

The hook returns `{ appliedKey, feedback, handleApply }`, allowing older specialized panel flows to ignore `feedback` while `SuggestionChips` keeps the surface-specific feedback payload used by the current Inspector recommendations path.

Verification note: focused inspector tests pass for `SuggestionChips` and `BlockRecommendationsPanel`; the historical `SettingsRecommendations` and `StylesRecommendations` coverage is no longer present in this tree.

---

### D03 — `formatCount()` Clones

**Severity:** High · **Effort:** Easy · **Savings:** ~30 lines

Nine identical (or near-identical) definitions across 9 files:

| File | Function Name | Lines | Extra Validation |
|------|--------------|-------|------------------|
| `src/templates/template-recommender-helpers.js` | `formatCount` (exported) | 62–64 | ❌ |
| `src/style-book/StyleBookRecommender.js` | `formatCount` | 153–154 | ❌ |
| `src/template-parts/TemplatePartRecommender.js` | `formatCount` | 42–44 | ❌ |
| `src/inspector/NavigationRecommendations.js` | `formatCount` | 19–21 | ❌ |
| `src/inspector/SuggestionChips.js` | `formatCount` | N/A | ❌ |
| `src/inspector/BlockRecommendationsPanel.js` | `formatCount` | N/A | ❌ |
| `src/global-styles/GlobalStylesRecommender.js` | `formatCount` | 179–181 | ❌ |
| `src/components/AIReviewSection.js` | `formatCountLabel` | 3–9 | ✅ `Number.isFinite`, `count < 0`, falsy `noun` |
| `src/components/AIAdvisorySection.js` | `formatCountLabel` | 1–7 | ✅ Same as above |

**Core implementation (7 of 9):**
```js
function formatCount( count, noun ) {
    return `${ count } ${ count === 1 ? noun : `${ noun }s` }`;
}
```

**Enhanced variant (2 of 9):**
```js
function formatCountLabel( count, noun ) {
    if ( ! Number.isFinite( count ) || count < 0 || ! noun ) return '';
    return `${ count } ${ count === 1 ? noun : `${ noun }s` }`;
}
```

**Proposed consolidation:** Single export in `src/utils/format-count.js` using the enhanced variant (superset behavior).

---

### D04 — `humanizeLabel()` / `humanizeValue()` Clones

**Severity:** Medium · **Effort:** Easy · **Savings:** ~20 lines

| File | Function Name | Lines | Algorithm |
|------|--------------|-------|-----------|
| `src/template-parts/TemplatePartRecommender.js` | `humanizeLabel` | 56–66 | Split on `[-_]`, filter, capitalize, join with space |
| `src/inspector/NavigationRecommendations.js` | `humanizeValue` | 23–29 | Same algorithm, wraps in `String(value \|\| '')` |
| `src/admin/activity-log-utils.js` | `humanizeValueLabel` | 199–212 | Extended: also replaces `/` with space, splits on `\s+` |

**Proposed consolidation:** `src/utils/humanize-string.js` with the most capable variant (activity-log-utils version), since it handles all separator types the others use.

---

### D05 — Context Signature Builders

**Severity:** Medium · **Effort:** Medium · **Savings:** ~100 lines

Four `buildXxxContextSignature` functions that all JSON-stringify a request context object:

| File | Function | Lines | Strategy |
|------|----------|-------|----------|
| `src/inspector/NavigationRecommendations.js` | `buildNavigationContextSignature` | 84–102 | Calls `buildNavigationFetchInput()`, deletes `blockClientId`, stringifies |
| `src/templates/template-recommender-helpers.js` | `buildTemplateRecommendationContextSignature` | 187–204 | Manually constructs object from params, stringifies |
| `src/template-parts/TemplatePartRecommender.js` | `buildTemplatePartRecommendationContextSignature` | 104–115 | Normalizes visible pattern names, sorts, stringifies |
| `src/utils/style-operations.js` | `buildGlobalStylesRecommendationContextSignature` | 381–414 | Most complex — normalizes deeply, sorts keys, stringifies |

**Assessment:** These share the same role (stable context fingerprinting for stale-result detection) but differ in normalization depth. A shared helper would likely fit Navigation, Template, and Template Part. The Style variant remains specialized because it deeply normalizes and sorts nested keys.

---

### D06 — Suggestion Panel Grouping

**Severity:** Medium · **Effort:** Easy · **Savings:** ~30 lines

**Status:** ✅ Done (2026-04-02)

**Files (2):**
- `src/inspector/SuggestionChips.js` *(legacy flow equivalent)*
- `src/inspector/BlockRecommendationsPanel.js` *(legacy flow equivalent)*

Both implement the same grouping loop:
```js
const grouped = {};
for ( const s of suggestions ) {
    const key = getSuggestionPanel( s );
    if ( DELEGATED_PANELS.has( key ) ) continue;
    if ( ! grouped[ key ] ) grouped[ key ] = [];
    grouped[ key ].push( s );
}
```

Suggestion chips also pre-filters `style_variation` type and builds `delegatedPanelTitles`.

**Completed consolidation:** Shared utility `src/inspector/group-by-panel.js` now groups suggestions by normalized panel key while skipping excluded panels.

```js
groupByPanel( suggestions, excludedPanels )
```

`SuggestionChips` uses it after filtering out style variations, and `BlockRecommendationsPanel` uses it directly in the current consolidated suggestions flow.

Verification note: focused unit tests for `SuggestionChips` and `BlockRecommendationsPanel` cover the merged apply/feedback path.

---

### D07 — Recommender Lifecycle Cleanup

**Severity:** Low · **Effort:** N/A · **Savings:** None currently confirmed

**Verification update:** The originally documented 4-file clone is stale. In the current tree, the explicit ref cleanup skeleton below is clearly present only in `src/patterns/PatternRecommender.js` (lines 290–309):

```js
const cleanupBindings = () => {
    if ( debounceRef.current ) { clearTimeout( debounceRef.current ); debounceRef.current = null; }
    if ( observerRef.current ) { observerRef.current.disconnect(); observerRef.current = null; }
    if ( listenerRef.current ) {
        listenerRef.current.el.removeEventListener( 'input', listenerRef.current.fn );
        listenerRef.current = null;
    }
};
```

**Assessment:** This is no longer an active cross-file duplication pattern. Remove it from the active consolidation queue unless similar ref cleanup blocks reappear elsewhere.

---

### D08 — MutationObserver with Timeout

**Severity:** Low · **Effort:** Medium · **Savings:** ~25 lines

**Files:** `src/patterns/PatternRecommender.js` (lines 222–254 and 337–369)

Same observer + timeout wrapper for waiting on DOM elements before attaching either the inserter notice or the search-input listener.

**Verification update:** The earlier `TemplateRecommender.js` occurrence is stale; the current tree only shows this duplicate inside `PatternRecommender.js`.

**Proposed consolidation:** Utility `observeDOMUntil( findFn, attachFn, timeoutMs )`.

---

## 4. PHP Source Patterns

### D09 — `normalize_input()` / `normalize_map()`

**Severity:** Critical · **Effort:** Easy · **Savings:** ~25 lines

**Exact-duplicate `normalize_input()` in 4 classes:**

| Class | Lines | Body |
|-------|-------|------|
| `PatternAbilities` | 507–513 | `if (is_object) get_object_vars; return is_array ? $input : []` |
| `TemplateAbilities` | 149–155 | **Byte-identical** |
| `NavigationAbilities` | 70–76 | **Byte-identical** |
| `WordPressDocsAbilities` | 47–53 | **Byte-identical** |

**Related `normalize_map()` variants (NOT exact copies):**

| Class | Lines | Behavior |
|-------|-------|----------|
| `BlockAbilities` | 441–445 | Delegates to recursive `normalize_value()` |
| `StyleAbilities` | 804–810 | Same body as `normalize_input()` but named `normalize_map` |

`StyleAbilities::normalize_map()` is byte-identical to `normalize_input()`. `BlockAbilities::normalize_map()` is intentionally different (recursive normalization).

**`normalize_list()` also duplicated:**

| Class | Lines | Behavior |
|-------|-------|----------|
| `BlockAbilities` | 447–451 | `array_values( normalize_map( $value ) )` |
| `StyleAbilities` | 815–821 | Inline object/array check + `array_values()` |

**Proposed consolidation:** Trait `Support\NormalizesInput` with `normalize_input()`. The `normalize_map`/`normalize_list` variants in Block and Style should remain class-specific since they serve different purposes (recursive vs flat).

---

### D10 — WordPress Docs Guidance Collection

**Severity:** High · **Effort:** Medium · **Savings:** ~100 lines

**Status:** ✅ Done (2026-04-02)

**`collect_wordpress_docs_guidance()` in 5 classes:**

| Class | Lines | Entity Key Source |
|-------|-------|-------------------|
| `BlockAbilities` | 294–300 | Computed via `build_wordpress_docs_entity_key()` |
| `PatternAbilities` | 354–360 | Computed; passes `$entity_key` to family context builder |
| `TemplateAbilities` | 451–457 | Computed |
| `NavigationAbilities` | 81–87 | Hardcoded `'core/navigation'` |
| `StyleAbilities` | 273–279 | Computed |

Also `TemplateAbilities::collect_template_part_wordpress_docs_guidance()` (lines 462–468) with inline `AISearchClient::resolve_entity_key()`.

**Core pattern (identical in all 5):**
```php
private static function collect_wordpress_docs_guidance( array $context, string $prompt ): array {
    $query          = self::build_wordpress_docs_query( $context, $prompt );
    $entity_key     = <varies>;
    $family_context = self::build_wordpress_docs_family_context( $context );
    return AISearchClient::maybe_search_with_cache_fallbacks( $query, $entity_key, $family_context );
}
```

**Sub-methods also duplicated in structure but domain-specific in content:**

| Method | Block | Pattern | Template | Navigation | Style |
|--------|-------|---------|----------|------------|-------|
| `build_wordpress_docs_query()` | 309–380 | 362–402 | 480–536 | 92–122 | 281–345 |
| `build_wordpress_docs_entity_key()` | 302–307 | 404–430 | 470–478 | ❌ hardcoded | 347–361 |
| `build_wordpress_docs_family_context()` | 385–439 | 436–470 | 592–642 | 127–146 | 366–404 |

The query/entity-key/family-context methods have **different logic** per surface — only the orchestration (`collect_wordpress_docs_guidance` itself) is truly duplicated.

**Completed consolidation:** Shared helper `inc/Support/CollectsDocsGuidance.php` now owns the query/entity-key/family-context orchestration while leaving each surface's domain-specific builders in place.

```php
CollectsDocsGuidance::collect(
    $build_query,
    $build_entity_key,
    $build_family_context,
    $context,
    $prompt
)
```

The helper supports the two existing outliers without changing public behavior:
- `PatternAbilities` still passes the resolved `$entity_key` into its family-context builder.
- `TemplateAbilities::collect_template_part_wordpress_docs_guidance()` still derives the entity key from the built query.

Verification note: focused PHPUnit coverage passes for `DocsGroundingEntityCacheTest`, `NavigationAbilitiesTest`, and `StyleAbilitiesTest`, and the docs-grounding cache suite now includes a template-part query-cache case.

---

### D11 — HTTP Client Request/Retry/Parse

**Severity:** High · **Effort:** Medium · **Savings:** ~100 lines

**Files:** `AzureOpenAI/EmbeddingClient.php` (103–144), `AzureOpenAI/ResponsesClient.php` (93–140)

**Line-by-line comparison:**

| Section | EmbeddingClient | ResponsesClient | Identical? |
|---------|----------------|-----------------|------------|
| Method signature | `→ array\|WP_Error` | `→ string\|WP_Error` | **No** (return type) |
| `REQUEST_TIMEOUT` | 60 | 90 | **No** (tuning) |
| `wp_remote_post()` call | Lines 104–111 | Lines 94–101 | **Yes** |
| Transport error normalization | Lines 113–120 | Lines 103–110 | **Yes** |
| 429 retry logic | Lines 122–129 | Lines 114–119 | **Yes** |
| JSON decode | Lines 131–132 | Lines 121–122 | **Yes** |
| HTTP status error | `'embedding_error'` | `'responses_error'` | **No** (error code) |
| Parse validation | `empty($data['data'])` | `json_last_error()` only | **No** |
| Response extraction | Returns `$data` array | Calls `extract_response_text()` → returns `string` | **No** (essential) |

**~20 lines are byte-identical** (the HTTP call, transport error handling, 429 retry, JSON decode). The divergence is in post-parse validation and return type.

**Also related:** `Cloudflare/AISearchClient::request_search()` (lines 800–847) — similar structure but different enough: hardcoded timeout (20s), no retry-after logic, no `ConfigurationValidator` normalization.

**Proposed consolidation:** Extract a shared `BaseHttpClient::post_with_retry()` method handling `wp_remote_post`, transport error normalization, 429 retry, and JSON decode. Each client provides a callback for response validation/extraction.

---

### D12 — REST Route Handler Structure

**Severity:** Medium · **Effort:** Medium · **Savings:** ~80 lines

**File:** `inc/REST/Agent_Controller.php`

Seven recommendation handlers all follow the same pattern:

```php
public static function handle_recommend_<surface>( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
    $input = [];
    // 1. Extract required params
    // 2. Conditionally add optional params (prompt, visiblePatternNames, etc.)
    // 3. Call ability
    $result = <Ability>::recommend_<surface>( $input );
    // 4. Error check
    if ( is_wp_error( $result ) ) { return $result; }
    // 5. Return response
    return new \WP_REST_Response( $result, 200 );
}
```

| Handler | Lines | Required Params | Optional Params | Ability Call |
|---------|-------|----------------|-----------------|--------------|
| `handle_recommend_block` | 463–483 | `editorContext`, `prompt` | — | `BlockAbilities::recommend_block()` |
| `handle_recommend_patterns` | 495–528 | `postType` | `templateType`, `blockContext`, `prompt`, `visiblePatternNames` | `PatternAbilities::recommend_patterns()` |
| `handle_recommend_navigation` | 533–560 | — | `menuId`, `navigationMarkup`, `prompt` | `NavigationAbilities::recommend_navigation()` |
| `handle_recommend_template` | 565–603 | `templateRef` | `templateType`, `prompt`, `visiblePatternNames`, `editorSlots`, `editorStructure` | `TemplateAbilities::recommend_template()` |
| `handle_recommend_style` | 608–630 | `scope`, `styleContext` | `prompt` | `StyleAbilities::recommend_style()` |
| `handle_recommend_template_part` | 635–658 | `templatePartRef` | `prompt`, `visiblePatternNames` | `TemplateAbilities::recommend_template_part()` |
| `handle_sync_patterns` | 485–493 | — | — | `PatternIndex::sync()` |

**Assessment:** The param extraction is sufficiently varied (type coercion, array sanitization, structured value sanitization) that a generic factory would trade readability for DRY. The real duplication is in the tail: `is_wp_error` check + `WP_REST_Response` construction — only ~5 lines each. **Low consolidation ROI; keep as-is for clarity.**

---

### D13 — Prompt `parse_response()` Boilerplate

**Severity:** Medium · **Effort:** Medium · **Savings:** ~60 lines

**Primary files (5):** `Prompt.php`, `TemplatePrompt.php`, `TemplatePartPrompt.php`, `NavigationPrompt.php`, `StylePrompt.php`

All `parse_response()` methods share the same opening:

```php
$cleaned = preg_replace( '/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim( $raw ) );
$data    = json_decode( is_string( $cleaned ) ? $cleaned : '', true );

if ( json_last_error() !== JSON_ERROR_NONE ) {
    return new \WP_Error( 'parse_error', 'Failed to parse <surface> response as JSON: ' . json_last_error_msg(),
        [ 'status' => 502, 'raw' => substr( $raw, 0, 500 ) ] );
}
```

Then each class applies domain-specific validation (suggestions array check, context-aware lookups, etc.)

**Related occurrence:** `inc/Abilities/PatternAbilities.php` (lines 252–260) repeats the same fence stripping and JSON decode flow when parsing the Responses API ranking output.

**Exception:** `NavigationPrompt::parse_response()` returns an empty array on error instead of `WP_Error`.

**Proposed consolidation:** Static utility `LLM\ResponseParser::decode_json( string $raw, string $label ): array|WP_Error` that handles fence stripping, JSON decode, and error reporting. Each prompt class, and the pattern ranking parser, can call this before applying domain-specific validation.

---

## 5. Test Code Patterns

### D14 — `@wordpress/components` Mock

**Severity:** High · **Effort:** Medium · **Savings:** ~250 lines

**15 test files** each define their own `jest.mock('@wordpress/components', ...)` with overlapping component stubs:

| Component | Files Using It | Variations |
|-----------|---------------|------------|
| `Button` | 13 of 15 | Props differ: some accept `className`, `href`, `label`, `title`, `...props` |
| `Notice` | 7 of 15 | Some accept `onDismiss`, some render only `children` |
| `TextareaControl` | 6 of 15 | Some accept `className`, one uses `onInput` + `onChange` |
| `PanelBody` | 3 of 15 | Attribute name varies: `data-panel` vs `data-panel-title` |
| `Tooltip` | 3 of 15 | Renders `Fragment` passthrough |
| `ButtonGroup` | 1 of 15 | — |
| `Spinner` | 0 of 15 | Not currently mocked |

**Exact duplicates found:**
- `AIActivitySection.test.js` ≡ `AIReviewSection.test.js` (Button only)
- `TemplateRecommender.test.js` ≡ `TemplatePartRecommender.test.js` (Button + Notice + TextareaControl + Tooltip)

Additional current occurrences beyond the original draft include `StyleBookRecommender.test.js`, `GlobalStylesRecommender.test.js`, `SuggestionChips.test.js`, and `InserterBadge.test.js`.

**Proposed consolidation:**

```
src/test-utils/wp-components.js
```

Export a `mockWpComponents()` factory that returns the superset of all component stubs. Each test file does:
```js
jest.mock( '@wordpress/components', () => require( '../../test-utils/wp-components' ).mockWpComponents() );
```

The superset `Button` should accept `{ children, className, disabled, onClick, href, label, title, ...props }` to cover all current usages.

---

### D15 — DOM Container / React Root Setup

**Severity:** High · **Effort:** Easy · **Savings:** ~180 lines

**17 test files** with identical or near-identical `beforeEach` / `afterEach`:

```js
let container = null;
let root = null;
window.IS_REACT_ACT_ENVIRONMENT = true;

beforeEach( () => {
    container = document.createElement( 'div' );
    document.body.appendChild( container );
    root = createRoot( container );
} );

afterEach( () => {
    act( () => { root.unmount(); } );
    container.remove();
} );
```

This now spans the original 8 files plus `StyleBookRecommender.test.js`, `GlobalStylesRecommender.test.js`, `PatternRecommender.test.js`, `SuggestionChips.test.js`, `TemplateRecommender.test.js`, `TemplatePartRecommender.test.js`, and `InserterBadge.test.js`.

**Proposed consolidation:**

```js
// src/__tests__/helpers/setup-react-test.js
export function setupReactTest() {
    let container, root;
    beforeEach( () => { container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container); } );
    afterEach( () => { act( () => root.unmount() ); container.remove(); } );
    return { getContainer: () => container, getRoot: () => root };
}
```

---

### D16 — `@wordpress/data` Mock

**Severity:** Medium · **Effort:** Easy · **Savings:** ~80 lines

**21 test files** with identical or near-identical mock:

```js
jest.mock( '@wordpress/data', () => ( {
    useDispatch: ( ...args ) => mockUseDispatch( ...args ),
    useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );
```

Some files omit `useSelect` in the current mock setup, with `find-inserter-search-input.test.js` and other fixture-heavy files as common examples.

Representative omitted occurrences from the initial draft include `style-operations.test.js`, `theme-tokens.test.js`, `collector.test.js`, `block-inspector.test.js`, `compat.test.js`, `visible-patterns.test.js`, and `find-inserter-search-input.test.js`.

**Proposed consolidation:** `src/__tests__/mocks/wp-data.js` exporting `mockUseDispatch`, `mockUseSelect`, and a `setupWpDataMock( { useSelect = true } )` helper.

---

### D17 — Selector / State Factories

**Severity:** High · **Effort:** Medium · **Savings:** ~400 lines

**5+ test files** with 50–100+ lines each of selector mocking:

| File | Factory Functions | Lines |
|------|-------------------|-------|
| `NavigationRecommendations.test.js` | `createSelectors()` | 76–140 |
| `BlockRecommendationsPanel.test.js` | `createState()`, `selectStore()` | 109–233 |
| `TemplateRecommender.test.js` | `createSelectors()`, `createState()`, `selectStore()` | 147–365 |
| `TemplatePartRecommender.test.js` | `createState()`, `selectStore()` | 122–174 |
| `PatternRecommender.test.js` | `createSelectMap()` | 71–97 |

All follow the same meta-pattern: provide a mutable state reference, mock `useSelect` to read from it, mock `useDispatch` to return jest.fn() dispatch actions.

**Proposed consolidation:** `src/__tests__/helpers/selector-factory.js` with a generic `createStoreTestHarness( defaultState, dispatchActions )` that returns `{ state, selectors, setPartialState }`.

---

### D18 — Suggestion Fixture Factories

**Severity:** Low · **Effort:** Easy · **Savings:** ~20 lines

**Files:** legacy pattern no longer exists in the current tree; use focused fixture builders in remaining test files instead.

Both define `makeSuggestion( panel, label )` with identical core and minor field additions.

**Proposed consolidation:** `src/__tests__/fixtures/suggestions.js` with `makeSuggestion( panel, label, overrides = {} )`.

---

## 6. Consolidation Priority Matrix

| ID | Pattern | Status | Priority | Effort | Savings | Risk |
|----|---------|--------|----------|--------|---------|------|
| D01 | Fetch thunks | ✅ Done | 🔴 Critical | High | ~350 | Low — helper preserves abort/token semantics and has focused tests |
| D09 | `normalize_input()` | ✅ Done | 🔴 Critical | Easy | ~25 | Low — pure function, exact copies |
| D03 | `formatCount()` | ✅ Done | 🟠 High | Easy | ~30 | Low — pure function |
| D02 | Apply feedback hook | ✅ Done | 🟠 High | Medium | ~150 | Low — shared hook keeps surface-specific feedback shape |
| D10 | Docs guidance collection | ✅ Done | 🟠 High | Medium | ~100 | Low — helper centralizes orchestration only |
| D11 | HTTP client | 🔴 Pending | 🟠 High | Medium | ~100 | Medium — retry behavior is critical |
| D14 | WP components mock | 🔴 Pending | 🟠 High | Medium | ~250 | Low — test-only |
| D15 | React test setup | 🔴 Pending | 🟠 High | Easy | ~180 | Low — test-only |
| D17 | Selector factories | 🔴 Pending | 🟠 High | Medium | ~400 | Low — test-only |
| D04 | `humanizeLabel()` | ✅ Done | 🟡 Medium | Easy | ~20 | Low |
| D05 | Context signatures | 🔴 Pending | 🟡 Medium | Medium | ~100 | Medium — Style variant is specialized |
| D06 | Panel grouping | ✅ Done | 🟡 Medium | Easy | ~30 | Low — tiny utility preserves panel order and delegation filters |
| D07 | Lifecycle cleanup | ⚪ Stale | N/A | N/A | N/A | Current tree no longer shows the claimed 4-file duplicate |
| D13 | Prompt parse boilerplate | 🔴 Pending | 🟡 Medium | Medium | ~60 | Low |
| D16 | WP data mock | 🔴 Pending | 🟡 Medium | Easy | ~80 | Low — test-only |
| D12 | REST handlers | 🔴 Pending | 🟡 Medium | Medium | ~80 | Medium — readability tradeoff |
| D08 | Observer timeout | 🔴 Pending | 🟢 Low | Medium | ~25 | Low |
| D18 | Suggestion fixtures | 🔴 Pending | 🟢 Low | Easy | ~20 | Low |

Verification update: D07 is stale/unconfirmed in the current tree. Section 8 captures four additional follow-up candidates discovered after the initial draft.

**Recommended execution order:**

1. **Quick wins (< 30 min each):** D09 ✅, D03 ✅, D04 ✅, D15, D18
2. **Medium wins (30–60 min each):** D13, D16
3. **High-impact refactors (1–2 hrs each):** D11, D14, D17
4. **Deferred / judgment calls:** D05, D08, D12, plus the follow-up candidates in Section 8
5. **Remove from the active queue:** D07 (stale/unconfirmed in the current tree)

---

## 7. Proposed Shared Module Map

### JS — Shared helpers and modules

```
src/
├── utils/
│   ├── format-count.js                  ← D03 ✅ — formatCount / humanizeString / joinClassNames
│   └── humanize-string.js               ← D04 ✅ — merged into format-count.js
├── inspector/
│   ├── use-suggestion-apply-feedback.js ← D02 ✅ — shared apply-feedback timer hook
│   └── group-by-panel.js                ← D06 ✅ — suggestion grouping utility
├── store/
│   └── index.js                         ← D01 ✅ — internal `runAbortableRecommendationRequest()` helper
├── hooks/
│   └── use-recommendation-reset.js      ← Follow-up — shared invalidation/reset effect
├── __tests__/
│   ├── mocks/
│   │   ├── wp-components.js             ← D14 — shared component stubs
│   │   └── wp-data.js                   ← D16 — shared data mock
│   ├── helpers/
│   │   ├── setup-react-test.js          ← D15 — DOM container setup
│   │   └── selector-factory.js          ← D17 — store test harness
│   └── fixtures/
│       └── suggestions.js               ← D18 — suggestion factories
```

### PHP — New shared modules

```
inc/
├── Support/
│   ├── NormalizesInput.php              ← D09 ✅ — normalize_input trait
│   ├── CollectsDocsGuidance.php         ← D10 ✅ — docs guidance orchestration
│   └── WordPressDocsHelper.php          ← D10 proposal superseded by helper above
├── AzureOpenAI/
│   └── BaseHttpClient.php               ← D11 — shared HTTP post/retry/parse
├── LLM/
│   └── ResponseParser.php               ← D13 — JSON fence strip + decode
```

## 8. Verification Follow-Up

These candidates were identified during the verification pass after the initial draft. They are not folded into the 18-pattern totals above yet.

### V01 — Recommendation Invalidation / Reset Effect

**Severity:** High · **Effort:** Medium · **Savings:** ~120 lines

**Files (5):**
- `src/templates/TemplateRecommender.js` (524–558)
- `src/template-parts/TemplatePartRecommender.js` (436–471)
- `src/global-styles/GlobalStylesRecommender.js` (752–794)
- `src/style-book/StyleBookRecommender.js` (748–788)
- `src/inspector/NavigationRecommendations.js` (183–215)

All compare a previous entity ref plus a previous context signature, decide whether stale recommendations should be cleared, and reset the prompt when the edited entity changes.

**Proposed consolidation:** Hook such as `useRecommendationReset( { entityKey, contextSignature, hasStoredResult, isLoading, clearRecommendations, resetPromptOnEntityChange } )`.

---

### V02 — Styles Sidebar Portal Observer

**Severity:** High · **Effort:** Medium · **Savings:** ~50 lines

**Files (2):**
- `src/global-styles/GlobalStylesRecommender.js` (678–745)
- `src/style-book/StyleBookRecommender.js` (675–741)

These effects share the same `MutationObserver` lifecycle, `ensurePortalNode()` flow, and portal-node cleanup. The main difference is the sidebar slot class name and enablement gating.

**Proposed consolidation:** Hook or utility such as `useStylesSidebarSlot( { isEnabled, slotClassName } )`.

---

### V03 — `joinClassNames()` Helper Clones

**Status:** ✅ Done (2026-04-02) — merged into `src/utils/format-count.js`
**Severity:** Low · **Effort:** Easy · **Savings:** ~6 lines

**Files (3):**
- `src/components/AIStatusNotice.js` (3–5)
- `src/components/AIReviewSection.js` (11–13)
- `src/components/AIAdvisorySection.js` (9–11)

Each file defines the same helper:

```js
function joinClassNames( ...values ) {
    return values.filter( Boolean ).join( ' ' );
}
```

**Consolidated into:** `src/utils/format-count.js`

---

### V04 — Status Notice Option Assembly

**Severity:** Medium · **Effort:** Medium · **Savings:** ~80 lines

**Files (6):**
- `src/inspector/BlockRecommendationsPanel.js` (218–239)
- `src/inspector/NavigationRecommendations.js` (168–177)
- `src/templates/TemplateRecommender.js` (608–633)
- `src/template-parts/TemplatePartRecommender.js` (517–545)
- `src/global-styles/GlobalStylesRecommender.js` (644–667)
- `src/style-book/StyleBookRecommender.js` (616–638)

Each surface assembles a very similar options object for `getSurfaceStatusNotice()` or `buildNotice()`, with repeated booleans, status fields, and success/empty messaging.

**Assessment:** The messages still vary by surface, so a helper should only extract the common option plumbing rather than force a single fully generic notice builder.
