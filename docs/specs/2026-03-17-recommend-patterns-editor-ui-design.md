# Recommend Patterns Editor UI

## Goal

Surface the `recommend-patterns` RAG pipeline inside the native block inserter so users discover AI-ranked patterns where they already look for patterns — the Patterns tab. No sidebar, no modal, no separate UI. The inserter search bar becomes the intent signal.

## Interaction Model

### Two modes

**Passive (editor load):** When the editor initializes, the client checks `flavorAgentData.canRecommendPatterns` (localized from PHP). If true, it sends `{ postType, templateType?, visiblePatternNames }` to the recommend-patterns endpoint. `visiblePatternNames` comes from the current inserter context so the server avoids ranking patterns the editor cannot render. Results populate a "Recommended" category at the top of the Patterns tab with contextually relevant patterns. This runs once and requires no user action. This flag is only a cheap client-side gate for obvious misconfiguration; it does **not** prove the vector index is already warm.

**Active (search-as-trigger):** When the user types in the Patterns tab search bar, the text becomes the `prompt` field in a fresh RAG query alongside `postType`, `templateType?`, `visiblePatternNames`, and the currently selected block (if any) as `blockContext`. The "Recommended" category updates with intent-aware, semantically matched results. Native keyword results continue to appear from other categories.

### Inserter badge

When any passive recommendation scores ≥ 0.9, a `!` indicator appears next to the `+` inserter toggle button in the editor toolbar. Hovering shows a tooltip with the top recommendation's `reason` (e.g., "A hero pattern matches your page layout perfectly"). This signals "there's something worth checking" without interrupting editing.

### Pattern presentation

Each recommended pattern appears in the native inserter with:

- **Title** — the registered pattern title (unchanged)
- **Description** — replaced with the `reason` field from the LLM ranking, explaining why this pattern fits the current page context ("Matches your page layout — the constrained group pairs well with the heading blocks already here")
- **Sort order** — native inserter order is preserved. The client does **not** globally reorder the shared pattern array, which avoids perturbing every other category and keyword result.
- **Keywords** — enriched with terms extracted from the `reason`. This means recommended patterns may additionally appear in native keyword search results for related queries — this is intentional, not a side effect. Patterns that are *not* in the current recommendation set retain their original keywords untouched.
- **Insertion** — standard native inserter click-to-insert. No approval flow, no pending state. The pattern content is the registered pattern content, not LLM-generated markup.

**Note:** The server-side ranking already filters out patterns scoring below 0.3 (in `PatternAbilities::recommend_patterns()`). The client receives only viable candidates.

## Architecture

### External services (existing, unchanged)

| Service | Used for |
|---|---|
| Azure OpenAI (text-embedding-3-large) | Embed the search query |
| Qdrant Cloud | Two-pass vector retrieval (semantic + structural) |
| Azure OpenAI (GPT-5.4 Responses API) | Rank candidates, generate reasons |

### Data flow

```
Editor loads
  → JS checks flavorAgentData.canRecommendPatterns — skip if false
  → JS reads postType from core/editor, templateType best-effort from the current editing surface
  → POST /flavor-agent/v1/recommend-patterns { postType, templateType?, visiblePatternNames }
  → PatternAbilities::recommend_patterns() (existing RAG pipeline)
  → Response: { recommendations: [{ name, title, score, reason, categories, content }] }
  → JS: patchInserterPatterns() — read-modify-write on __experimentalBlockPatterns:
      - remove 'recommended' category from all patterns (cleanup previous run)
      - add 'recommended' to categories of matching patterns
      - replace description with reason
      - enrich keywords with terms from reason
  → JS: if any score ≥ 0.9, set patternBadge in store

User types in Patterns tab search bar
  → JS debounces input (400ms), cancels any in-flight request via AbortController
  → POST /flavor-agent/v1/recommend-patterns { postType, templateType?, blockContext?, prompt, visiblePatternNames }
  → Same pipeline, fresh results
  → JS: patchInserterPatterns() with new recommendations (cleanup + apply)
  → Recommended category re-renders with search-aware results
```

### PHP changes

**`flavor-agent.php`** — register the pattern category on `init` and position it first via `block_editor_settings_all`:

```php
add_action( 'init', function () {
    register_block_pattern_category( 'recommended', [
        'label' => __( 'Recommended', 'flavor-agent' ),
    ] );
} );

add_filter( 'block_editor_settings_all', function ( $settings ) {
    // Position "Recommended" category first.
    $cats = $settings['__experimentalBlockPatternCategories'] ?? [];
    $recommended = null;
    $rest = [];

    foreach ( $cats as $cat ) {
        if ( ( $cat['name'] ?? '' ) === 'recommended' ) {
            $recommended = $cat;
        } else {
            $rest[] = $cat;
        }
    }

    if ( $recommended ) {
        $settings['__experimentalBlockPatternCategories'] = array_merge(
            [ $recommended ],
            $rest
        );
    }

    return $settings;
} );
```

The existing `flavor_agent_enqueue_editor()` function must also localize a `canRecommendPatterns` flag so the client can skip obvious misconfiguration before attempting the passive load:

```php
wp_localize_script( 'flavor-agent-editor', 'flavorAgentData', [
    'restUrl'         => rest_url( 'flavor-agent/v1/' ),
    'nonce'           => wp_create_nonce( 'wp_rest' ),
    'hasApiKey'       => (bool) get_option( 'flavor_agent_api_key' ),
    'canRecommendPatterns' => (bool) (
        get_option( 'flavor_agent_azure_openai_endpoint' )
        && get_option( 'flavor_agent_azure_openai_key' )
        && get_option( 'flavor_agent_azure_embedding_deployment' )
        && get_option( 'flavor_agent_azure_chat_deployment' )
        && get_option( 'flavor_agent_qdrant_url' )
        && get_option( 'flavor_agent_qdrant_key' )
    ),
] );
```

**`inc/REST/Agent_Controller.php`** — add `POST /flavor-agent/v1/recommend-patterns`:

```php
register_rest_route( self::NAMESPACE, '/recommend-patterns', [
    'methods'             => 'POST',
    'callback'            => [ __CLASS__, 'handle_recommend_patterns' ],
    'permission_callback' => fn() => current_user_can( 'edit_posts' ),
    'args'                => [
        'postType'     => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
        ],
        'templateType' => [
            'required'          => false,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
        ],
        'blockContext'  => [
            'required'   => false,
            'type'       => 'object',
            'properties' => [
                'blockName'  => [ 'type' => 'string' ],
                'attributes' => [ 'type' => 'object' ],
            ],
        ],
        'prompt'       => [
            'required'          => false,
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_textarea_field',
        ],
        'visiblePatternNames' => [
            'required'          => false,
            'type'              => 'array',
            'sanitize_callback' => [ __CLASS__, 'sanitize_string_array' ],
        ],
    ],
] );
```

Handler delegates to `PatternAbilities::recommend_patterns()` with the input fields mapped directly.

**Why a REST route when the Abilities API already exposes `flavor-agent/recommend-patterns`?** The REST route is the editor client's entry point on all WordPress versions (6.5+). The Abilities API (WP 6.9+) exposes the same ability for external AI orchestrators. Both call `PatternAbilities::recommend_patterns()`. The REST route exists because the editor JS uses `apiFetch` with a known path, while the Abilities API is a discovery layer for third-party agents. Maintaining both is intentional — one serves the editor UI, the other serves the ecosystem.

**Adjacent spec follow-up:** `2026-03-16-abilities-api-integration-design.md` still describes `Agent_Controller.php` as exposing only `recommend-block` and `sync-patterns`. If this iteration lands, that adjacent spec should be updated to list the additive `recommend-patterns` route so the published file map stays accurate.

### JS changes

#### Template type resolution

`postType` comes from `select('core/editor').getCurrentPostType()`. `templateType` is best-effort and depends on the editing surface:

- **Post editor:** the available template value is a template *assignment* slug, not a core pattern template type like `'single'`, `'page'`, or `'archive'`. Phase 1 therefore omits `templateType` in the post editor.
- **Site editor:** derive `templateType` from the currently edited template entity only when it can be normalized to the same pattern template type vocabulary used by registered patterns. If the current template cannot be normalized cleanly, omit `templateType`.

Phase 1 treats `templateType` as optional context enrichment, not a guaranteed field.

####  Store additions

**`src/store/index.js`** — add pattern recommendation state with isolated lifecycle (under "Store additions"):

| Addition | Purpose |
|---|---|
| `patternRecommendations: []` | Current recommendations array |
| `patternStatus: 'idle'` | `'idle'` / `'loading'` / `'ready'` / `'error'` — independent from the block `status` field |
| `patternBadge: null` | Top reason string for ≥ 0.9 score, or null |
| `setPatternStatus( status )` | Action: sets `patternStatus` only (must NOT call `setStatus()`) |
| `setPatternRecommendations( recs )` | Action: stores recommendations + computes badge |
| `fetchPatternRecommendations( input )` | Thunk: calls REST endpoint using `setPatternStatus`, dispatches results. Uses `AbortController` — stores the controller so the next call can abort in-flight requests. |
| `getPatternRecommendations( state )` | Selector |
| `getPatternBadge( state )` | Selector |
| `isPatternLoading( state )` | Selector: reads `patternStatus`, not `status` |

**Critical:** The pattern recommendation lifecycle is fully isolated from the per-block recommendation lifecycle. `fetchPatternRecommendations` uses `setPatternStatus`, never `setStatus`. This prevents pattern loading from showing a spinner in the Inspector panel and vice versa.

**`src/patterns/PatternRecommender.js`** — new component registered as a plugin:

Responsibilities:
1. On mount: check `window.flavorAgentData?.canRecommendPatterns`. If false, return null (no-op).
2. Read `postType` from `core/editor`, resolve `templateType` per the rules above, then dispatch `fetchPatternRecommendations({ postType, templateType })`
3. When recommendations arrive: call `patchInserterPatterns()` (see below)
4. Detect inserter visibility via `select('core/editor').isInserterOpened()` store selector (not DOM observation)
5. Build `visiblePatternNames` from `__experimentalGetAllowedPatterns()` when available, falling back to `__experimentalBlockPatterns` names only when the selector is unavailable.
6. When inserter is open: use a `MutationObserver` on the inserter panel to detect when a search input appears (match by `role="searchbox"` or the `.block-editor-inserter__search` class). Attach an `input` event listener (debounced 400ms).
7. On search input change: abort any in-flight request, dispatch `fetchPatternRecommendations` with the search text as `prompt`, plus `postType`, `templateType`, `visiblePatternNames`, and current `blockContext` if a block is selected
7. When inserter closes: detach observer and listener
8. When new results arrive: call `patchInserterPatterns()` with fresh recommendations

**`patchInserterPatterns()` helper** (inside `PatternRecommender.js` or a shared utility):

This is the critical read-modify-write function with full rollback capability. It maintains a module-level `Map<string, { description: string, keywords: string[] }>` called `originalMetadata` that preserves the original state of every pattern it has ever mutated. This ensures any pattern can be fully restored regardless of how many patch cycles have run.

On every invocation it must:
1. Read the **current** `__experimentalBlockPatterns` from `select('core/block-editor').getSettings()` — never cache a stale copy
2. Clone the array (shallow clone of the array, shallow clone of each mutated pattern object)
3. **Rollback all previous mutations:** For every pattern whose `name` exists in `originalMetadata`:
   - Restore `description` to the stored original
   - Restore `keywords` to the stored original
   - Remove `'recommended'` from `categories`
   - Delete the entry from `originalMetadata` (clean slate)
4. **Apply new recommendations:** For each recommendation, find the matching pattern by `name` in the cloned array. If found:
   - Save `{ description, keywords }` into `originalMetadata` (preserving the *current* values, which are now guaranteed to be originals thanks to step 3)
   - Add `'recommended'` to its `categories`
   - Replace `description` with the recommendation's `reason`
   - Merge extracted keywords from `reason` into `keywords` (deduplicated)
5. Call `dispatch('core/block-editor').updateSettings({ __experimentalBlockPatterns: patchedArray })`
6. Do **not** globally sort the patched array. Recommended patterns are tagged and described in place so other native categories retain Gutenberg's ordering.

If called with an empty recommendations array (e.g., on error), step 3 still runs — restoring all patterns to their original state. This is the "clear recommendations" path.

**Important:** `updateSettings()` performs a shallow merge at the top level but replaces each key entirely. The entire `__experimentalBlockPatterns` array must be passed as a complete replacement. Always read the current value immediately before writing to avoid overwriting concurrent changes.

**API surface note:** `__experimentalBlockPatterns` and `__experimentalBlockPatternCategories` are legacy keys still present in WordPress 6.5-6.9 for backward compatibility. The patching code is isolated behind `patchInserterPatterns()` so it can be updated in one place if WordPress migrates to a new API surface (e.g., `getPatterns()` from the `core` store). Monitor Gutenberg trunk for changes.

**`src/patterns/InserterBadge.js`** — new component registered as a plugin:

Responsibilities:
1. Select `patternBadge` from the store
2. If null: render nothing
3. If non-null: locate the inserter toggle button in the toolbar by querying for `button.block-editor-inserter__toggle` (the stable class used since WordPress 6.0). Fallback: query by `aria-label` containing "inserter" (case-insensitive, to handle translations). If neither found: render nothing (graceful degradation).
4. Render a small `!` indicator adjacent to the button using a React portal attached to the button's parent element
5. Wrap in `Tooltip` from `@wordpress/components` showing the `patternBadge` reason text
6. The badge is purely informational — clicking it does not programmatically open the inserter (the `+` button handles that natively)

**Locale consideration:** The `aria-label` text is translatable. The primary selector (`button.block-editor-inserter__toggle`) does not depend on translated text. The `aria-label` fallback is a safety net, not the primary strategy.

**`src/index.js`** — switch to `registerPlugin()`:

```js
import { registerPlugin } from '@wordpress/plugins';

// Data store (self-registering).
import './store';

// Inspector injection (self-registering filter).
import './inspector/InspectorInjector';

// Plugin components.
import PatternRecommender from './patterns/PatternRecommender';
import InserterBadge from './patterns/InserterBadge';

registerPlugin( 'flavor-agent', {
    render: () => (
        <>
            <PatternRecommender />
            <InserterBadge />
        </>
    ),
} );
```

**`package.json`** — add `@wordpress/plugins` dependency:

```json
"@wordpress/plugins": "^7.0.0"
```

### Search interception detail

The Patterns tab search input is internal to `@wordpress/block-editor`. There is no public filter for intercepting search queries. The approach:

1. `PatternRecommender` uses `useSelect` to watch `select('core/block-editor').isInserterOpened()`. When it transitions to `true`, start observing.
2. Use `MutationObserver` on `document.body` (or the editor wrapper) to detect when the inserter panel and its search input appear in the DOM. Match by `role="searchbox"` within a `.block-editor-inserter` container.
3. Attach an `input` event listener to the search input (debounced 400ms via `AbortController` cancellation).
4. When the user types, the listener fires `fetchPatternRecommendations` with `prompt` set to the input value. The previous in-flight request is aborted via `AbortController.abort()`.
5. When results return, `patchInserterPatterns()` runs the read-modify-write cycle on `__experimentalBlockPatterns`. The native inserter re-renders because settings changed.
6. When `isInserterOpened()` transitions to `false`, disconnect the `MutationObserver` and remove the `input` listener.

**Patterns tab vs. other tabs:** The search input is shared across inserter tabs. Active search triggers a RAG query regardless of which tab is active. This is acceptable because the Recommended category only appears in the Patterns tab — modifying pattern metadata has no visible effect in the Blocks or Media tabs.

**Graceful degradation:** If the search input cannot be located in the DOM (selector mismatch, WordPress version change, or unexpected markup), the `MutationObserver` times out after 3 seconds and stops retrying. The feature degrades to passive-only recommendations — the "Recommended" category still populates on editor load, but search-triggered updates do not fire. This is an acceptable fallback because passive mode is the primary value; active search is an enhancement.

**Version coupling:** The DOM selectors (`.block-editor-inserter__search`, `role="searchbox"`, `button.block-editor-inserter__toggle`) are documented as of WordPress 6.9 / Gutenberg 19.x. If a future Gutenberg release changes this markup, only `PatternRecommender.js` and `InserterBadge.js` need updating — the rest of the pipeline is decoupled.

### Cancellation

Active search uses `AbortController` to cancel in-flight requests. The `fetchPatternRecommendations` thunk:
1. Stores the current `AbortController` in the store (or a module-level variable)
2. Before each new request: abort the previous controller, create a new one
3. Pass `controller.signal` to `apiFetch` via its `signal` option
4. On abort: the fetch resolves with an `AbortError` which the thunk silently ignores (does not set error state)

This also handles the race condition where passive results arrive after the user has started typing: the active request aborts the passive one.

### Edge cases

**Cold start (no index):** `recommend_patterns()` returns `WP_Error('index_warming')`. The client receives a non-200 response, sets `patternStatus: 'error'`, and does not patch any patterns. The "Recommended" category exists but has no patterns assigned — native inserter hides empty categories. No error shown to the user.

**Missing or incomplete backend configuration:** `canRecommendPatterns` is `false` → client never makes the request → no Recommended category shown.

**Qdrant ↔ registry mismatch:** RAG returns pattern names from the vector index. Client matches by `name` against `__experimentalBlockPatterns`. Patterns not found in the registry are silently dropped. This handles theme switches and plugin deactivation cleanly.

**Passive results arrive after active search starts:** The active request aborts the passive one via `AbortController`. Only the most recent request's results are applied.

**Score threshold:** The ≥ 0.9 badge threshold is a constant in `PatternRecommender.js`. It can be adjusted without architectural changes.

## File Map

### New files

| File | Responsibility |
|---|---|
| `src/patterns/PatternRecommender.js` | Fetch recommendations, patch inserter settings, observe search |
| `src/patterns/InserterBadge.js` | Render `!` indicator on inserter button for high-score recs |

### Modified files

| File | Change |
|---|---|
| `src/store/index.js` | Add `patternRecommendations`, `patternStatus`, `patternBadge` state + actions + selectors (isolated lifecycle) |
| `src/index.js` | Switch to `registerPlugin()` rendering `PatternRecommender` + `InserterBadge` |
| `inc/REST/Agent_Controller.php` | Add `POST /recommend-patterns` route + handler |
| `flavor-agent.php` | Register `recommended` pattern category + `block_editor_settings_all` filter + update `wp_localize_script` with `canRecommendPatterns` |
| `package.json` | Add `@wordpress/plugins` dependency |

### Unchanged

| File | Why |
|---|---|
| `inc/Abilities/PatternAbilities.php` | Already implements the full RAG pipeline — the REST route calls it |
| `inc/AzureOpenAI/*` | Backend clients unchanged |
| `inc/Patterns/PatternIndex.php` | Sync lifecycle unchanged |
| `inc/Settings.php` | Admin settings unchanged |
| `src/inspector/*` | Per-block recommendation flow unchanged |
| `src/context/*` | Block context collection unchanged |
| `src/admin/*` | Admin sync button unchanged |

## Verification

- Editor loads → "Recommended" category appears in Patterns tab with contextually ranked patterns
- Each recommended pattern shows a contextual `reason` as its description
- Recommended patterns appear only when they are visible in the current inserter context; native category ordering is otherwise preserved
- Typing in the Patterns search bar triggers a fresh RAG query after 400ms debounce
- Search results update the Recommended category with intent-aware patterns
- Previous recommendations are cleaned up before new ones are applied
- Non-recommended patterns retain their original description and keywords; recommended patterns may additionally appear in native keyword results due to enriched keywords (intentional)
- Score ≥ 0.9 shows `!` badge on the inserter `+` button with hover tooltip
- No badge when all scores are below threshold
- Cold start (no index): Recommended category is empty/hidden, no errors shown
- Missing or incomplete backend configuration (`canRecommendPatterns` false): no request made, no errors
- Clicking a recommended pattern inserts it normally via native inserter behavior
- Passive results arriving after active search starts are discarded (AbortController)
- Existing per-block Inspector recommendations (Anthropic path) are unaffected
- Pattern recommendation loading does not trigger Inspector loading spinner
- `npm run build` succeeds with no new warnings
