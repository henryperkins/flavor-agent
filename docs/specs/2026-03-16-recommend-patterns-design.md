# Recommend Patterns: Vector-Powered Pattern Recommendation

## Goal

Implement the `flavor-agent/recommend-patterns` ability so it returns scored, context-aware pattern recommendations. Patterns are pre-indexed in a vector database; at query time, semantic search finds candidates and an LLM ranks them for the caller's editing context.

## Architecture

Two independent LLM paths coexist in the plugin. The existing Anthropic path (`recommend-block`) is untouched.

```
  recommend-block (existing)          recommend-patterns (new)
  ─────────────────────────           ──────────────────────────
  Anthropic Messages API              Azure OpenAI (two endpoints)
  via Client::chat()
                                      ┌─ /openai/v1/embeddings
                                      │  text-embedding-3-large (3072 dim)
                                      │  Embed query + index patterns
                                      │
                                      ├─ Qdrant Cloud (Azure East US)
                                      │  Store vectors, cosine similarity
                                      │  → top 10 candidates
                                      │
                                      └─ /openai/v1/responses
                                         gpt-5.4 deployment
                                         Rank candidates, return scored list
```

### External Services

| Service | Endpoint | Auth |
|---|---|---|
| Azure OpenAI | `https://fifteenmodels.openai.azure.com/` | `api-key` header |
| Qdrant Cloud | `https://...eastus-0.azure.cloud.qdrant.io:6333` | `api-key` header |

### Azure OpenAI Deployments

| Purpose | Deployment Name | Model |
|---|---|---|
| Embeddings | `text-embedding-3-large` | text-embedding-3-large (3072 dimensions) |
| LLM Ranking | `gpt-5.4` | GPT-5.4 via Responses API |

## Ability Schema (already registered)

### Input

```json
{
  "postType": "page",
  "blockContext": { "blockName": "core/group", "attributes": {} },
  "templateType": "single",
  "prompt": "hero section with call to action",
  "visiblePatternNames": ["theme/hero-cta", "theme/feature-grid"]
}
```

Only `postType` is required. All other fields are optional context enrichment. `visiblePatternNames` is a client-supplied allowlist from the current inserter context so the server can avoid ranking patterns the editor cannot render.

### Output

```json
{
  "recommendations": [
    {
      "name": "theme/hero-cta",
      "title": "Hero with CTA",
      "score": 0.92,
      "reason": "Full-width hero with prominent call-to-action button, ideal for page headers.",
      "categories": ["featured", "call-to-action"],
      "content": "<!-- wp:group -->..."
    }
  ]
}
```

## Pattern Indexing

### Qdrant Collection

- **Name:** `flavor-agent-patterns-{hash}`
- **Dimensions:** 3072 (text-embedding-3-large)
- **Distance:** Cosine

The collection name is site/environment scoped. The suffix hash is derived from `home_url( '/' )`, `get_current_blog_id()`, and `wp_get_environment_type()`, which prevents multiple WordPress installs from overwriting each other's pattern catalogs when they share one Qdrant account.

**Bootstrap:** `QdrantClient::ensure_collection()` checks whether the resolved collection exists (`GET /collections/flavor-agent-patterns-{hash}`) and creates it if missing (`PUT /collections/flavor-agent-patterns-{hash}` with vectors config). This is called at the start of every sync operation and on the Settings page sync button. The `content` payload field remains unindexed because only the structural keyword fields receive payload indexes.

**Payload indexes:** `blockTypes`, `templateTypes`, and `categories` are created as keyword payload indexes to support filtered search (see Step 4).

### Point Structure

| Field | Type | Source |
|---|---|---|
| `id` | UUID (v5) | Deterministic UUID v5 from pattern `name` using a fixed namespace; safe because each site/environment uses its own collection |
| `vector` | float[3072] | text-embedding-3-large output |
| `payload.name` | string | Pattern registry |
| `payload.title` | string | Pattern registry |
| `payload.description` | string | Pattern registry |
| `payload.categories` | string[] | Pattern registry |
| `payload.blockTypes` | string[] | Pattern registry |
| `payload.templateTypes` | string[] | Pattern registry |
| `payload.content` | string | Full block markup |

### Embedding Input

Condensed representation per pattern (~500-800 tokens):

```
{title}
{description}
Categories: {categories joined by comma}
Block types: {blockTypes joined by comma}
Template types: {templateTypes joined by comma}
{first 500 characters of content}
```

Full markup is too noisy for semantic matching (HTML comments, JSON attributes). The condensed form preserves semantic signal while keeping token cost low. `templateTypes` and `blockTypes` are included in both the embedding text and as indexed payload fields for hybrid retrieval.

### Sync Logic

The `PatternIndex` class orchestrates sync:

1. Acquire a sync lock via `wp_options` transient (`flavor_agent_sync_lock`, 5-minute TTL). If lock exists and is not expired, skip. This prevents overlapping syncs from the admin button, lifecycle hooks, and query-time triggers.
2. Call `QdrantClient::ensure_collection()` to create the collection if it doesn't exist.
3. Read all registered patterns via `ServerCollector::for_patterns()`
4. Compute fingerprint: `md5()` of sorted `[ name, title, description, categories, blockTypes, templateTypes, md5(content), EMBEDDING_RECIPE_VERSION ]` per pattern. The `EMBEDDING_RECIPE_VERSION` is a class constant incremented when the embedding text template changes, ensuring recipe changes force re-indexing.
5. Read the persisted state option `flavor_agent_pattern_index_state`, which stores `{ status, fingerprint, qdrant_url, qdrant_collection, azure_openai_endpoint, embedding_deployment, last_synced_at, last_attempt_at, indexed_count, last_error, pattern_fingerprints }`.
6. Compare the new fingerprint + endpoint/model settings against the persisted state. If all match and `status === ready` → skip. If the fingerprint changed, the resolved Qdrant collection changed, Qdrant endpoint changed, Azure OpenAI endpoint changed, embedding deployment changed, or the state is `uninitialized`, `stale`, or `error` → re-index.
7. Before remote calls start, persist `status = indexing` and `last_attempt_at`.
8. If re-indexing:
   - Diff: identify added, removed, and changed patterns using persisted per-pattern fingerprints
   - Embed new/changed patterns in batches of 100 (Azure OpenAI supports arrays up to 2048 inputs, but batching at 100 keeps per-request token usage and timeout risk manageable)
   - Upsert points to Qdrant, delete removed pattern points
   - Persist `status = ready`, `fingerprint`, `indexed_count`, `last_synced_at`, and clear `last_error`
9. If sync fails, persist `status = error` plus `last_error` and leave the previous ready index untouched if one exists.
10. Release sync lock.

### Index State

`flavor_agent_pattern_index_state` is the single source of truth for request-time behavior:

- `uninitialized` — no usable index exists yet
- `indexing` — a sync is in progress
- `ready` — a usable index exists and matches the last successful sync
- `stale` — a usable index exists, but lifecycle hooks or config changes marked it dirty and a refresh is pending
- `error` — the most recent sync failed; the previous ready index may or may not still exist

Request handling depends on this state rather than assuming that "stale" and "usable" are the same thing.

### Sync Triggers

| Trigger | Mechanism | Timing |
|---|---|---|
| Admin button | `POST /flavor-agent/v1/sync-patterns` REST route, nonce-protected, `manage_options` capability. Settings page enqueues a small admin JS file (`build/admin.js`) on the settings screen only (`admin_enqueue_scripts` with page hook check) that calls this route via `wp.apiFetch` or `fetch` with `X-WP-Nonce`. Returns `{ indexed: int, removed: int, fingerprint: string, status: string }`. Button shows a spinner during the request and a success/error notice on completion. | Immediate, user-initiated |
| Theme/plugin change | `after_switch_theme`, `activated_plugin`, `deactivated_plugin`, and `upgrader_process_complete` hooks mark the index dirty and force-schedule `flavor_agent_reindex_patterns` | Background, automatic |
| Relevant settings change | `update_option_*` hooks for Azure embedding/Qdrant settings plus `update_option_home` mark the index dirty and force-schedule a refresh. Chat deployment changes do not invalidate the vector index. | Background, automatic |
| Query-time | `recommend_patterns()` reads the persisted runtime state and performs only cheap config drift checks. If state is `stale` and the caller can `manage_options`, it may schedule one background sync if no job is already queued and the cooldown window has elapsed. Non-admin editors never schedule remote sync work. If state is `uninitialized`, `indexing`, or `error` and no ready index exists, the request returns a warming/unavailable `WP_Error` instead of pretending recommendations are ready. | Role-sensitive, automatic |

**Cron hook:** `flavor_agent_reindex_patterns` is registered in `flavor-agent.php` via `add_action( 'flavor_agent_reindex_patterns', [ PatternIndex::class, 'sync' ] )`. All background triggers call the same scheduler helper, which checks `wp_next_scheduled()` plus a cooldown window before enqueueing. The sync lock (step 1 of Sync Logic) remains the final protection against overlap once the job starts.

## Recommend Flow

When `recommend-patterns` is invoked:

### Step 1: Staleness Check

Read `flavor_agent_pattern_index_state` and branch by status. Request-time checks do **not** recompute the full pattern fingerprint; runtime state is driven by persisted sync state plus cheap endpoint/collection drift checks:

- `ready` — continue immediately
- `stale` — continue with the last ready index and schedule a background refresh only if the caller is allowed to do so and no refresh is already queued
- `uninitialized` or `indexing` with no ready index — schedule an allowed background sync, then return `WP_Error( 'index_warming', 'Pattern catalog is building. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.', [ 'status' => 503 ] )`
- `error` with no ready index — return `WP_Error( 'index_unavailable', 'Pattern catalog sync failed. Review the last sync error in Settings > Flavor Agent.', [ 'status' => 503 ] )`

This preserves the non-blocking behavior for a stale-but-usable catalog while making cold-start behavior explicit.

### Step 2: Build Query String

Concatenate available input into natural language:

```
"Recommend patterns for a {postType} post"
+ " near a {blockContext.blockName} block" (if present)
+ " in a {templateType} template" (if present)
+ ". {prompt}" (if present)
```

### Step 3: Embed Query

```
POST https://fifteenmodels.openai.azure.com/openai/v1/embeddings
Header: api-key: ***
Body: { "model": "text-embedding-3-large", "input": "<query string>" }
```

Returns 3072-dimension vector.

### Step 4: Search Qdrant (two-pass retrieval)

Use two retrieval passes so structurally relevant patterns are surfaced without excluding generic patterns:

```
Pass A: pure semantic search
POST https://...qdrant.io:6333/collections/flavor-agent-patterns-{hash}/points/query
Header: api-key: ***
Body: {
  "query": [<query_vector>],
  "limit": 8,
  "with_payload": true
}

Pass B: structural search (only when `templateType` or `blockContext.blockName` is present)
POST https://...qdrant.io:6333/collections/flavor-agent-patterns-{hash}/points/query
Header: api-key: ***
Body: {
  "query": [<query_vector>],
  "limit": 6,
  "with_payload": true,
  "filter": {
    "should": [
      { "key": "templateTypes", "match": { "value": "<templateType>" } },
      { "key": "blockTypes", "match": { "value": "<blockContext.blockName>" } }
    ]
  }
}
```

The plugin unions both result sets, optionally filters to `visiblePatternNames`, dedupes by `payload.name`, keeps the best Qdrant score per pattern, and forwards the top 12 candidates to the LLM. When an allowlist is present, the Qdrant pass limits are raised before filtering so invisible patterns are less likely to crowd out visible candidates. Patterns with empty `templateTypes` or `blockTypes` remain eligible through Pass A, so generic patterns are not excluded by structural filtering. Payload indexes support Pass B; the structural pass is not treated as a score boost inside Qdrant itself.

Returns up to 12 deduped patterns with full payload including `content`.

### Step 5: Rank via Responses API

```
POST https://fifteenmodels.openai.azure.com/openai/v1/responses
Header: api-key: ***
Body: {
  "model": "gpt-5.4",
  "instructions": "<pattern ranking system prompt>",
  "input": "<candidates + editing context>"
}
```

### Step 6: Parse and Return

Strip markdown fences defensively (matching the existing `Prompt::parse_response()` pattern), parse JSON, validate structure. The LLM returns only `name`, `score`, and `reason` per recommendation. All registry-owned fields (`title`, `categories`, `content`) are rehydrated from the Qdrant payload keyed by `name`, not from LLM output. This eliminates drift between the recommendation and the actual registered pattern. Only `score` (float, clamped 0-1) and `reason` (`sanitize_text_field()`) come from the LLM. Pattern names that don't match any Qdrant candidate are dropped.

## System Prompt (Pattern Ranking)

```
You are a WordPress pattern recommendation engine.

You receive a list of candidate block patterns and an editing context
(post type, nearby block, template type, user instruction).

Your job: score each pattern for relevance to the context and explain why.

Respond with a JSON object (no markdown fences, no text outside the JSON):

{
  "recommendations": [
    {
      "name": "pattern-slug",
      "score": 0.85,
      "reason": "One sentence explaining why this pattern fits"
    }
  ]
}

Rules:
- Score 0.0 to 1.0 where 1.0 = perfect fit for the context.
- Omit patterns scoring below 0.3.
- Order by score descending.
- Consider: post type conventions, block proximity, template structure,
  category relevance, and the user's stated intent.
- Return only name, score, and reason per pattern. Title, categories,
  and content are attached from the source data.
- Return at most 8 recommendations.
```

## File Map

```
inc/
├── Abilities/
│   ├── Registration.php      # MODIFY — expand check-status schema with additive backends metadata
│   ├── PatternAbilities.php   # MODIFY — implement recommend_patterns()
│   └── InfraAbilities.php     # MODIFY — check_status() returns dynamic ability readiness
├── AzureOpenAI/
│   ├── EmbeddingClient.php    # NEW — wp_remote_post to /openai/v1/embeddings
│   ├── ResponsesClient.php    # NEW — wp_remote_post to /openai/v1/responses
│   └── QdrantClient.php       # NEW — wp_remote_post/get to Qdrant REST API
├── Patterns/
│   └── PatternIndex.php       # NEW — sync orchestrator
├── REST/
│   └── Agent_Controller.php   # MODIFY — add POST /flavor-agent/v1/sync-patterns route
└── Settings.php               # MODIFY — add Azure OpenAI + Qdrant settings section + sync button

flavor-agent.php               # MODIFY — add lifecycle hooks + cron hook registration
webpack.config.js              # NEW — extend @wordpress/scripts with explicit `index` + `admin` entries
src/admin/
└── sync-button.js             # NEW — settings page sync button (enqueued only on settings screen)
```

### New Settings Fields

| Option Key | Label | Type |
|---|---|---|
| `flavor_agent_azure_openai_endpoint` | Azure OpenAI Endpoint | url |
| `flavor_agent_azure_openai_key` | Azure OpenAI API Key | password |
| `flavor_agent_azure_embedding_deployment` | Embedding Deployment Name | text |
| `flavor_agent_azure_chat_deployment` | Chat Deployment Name | text |
| `flavor_agent_qdrant_url` | Qdrant Cloud URL | url |
| `flavor_agent_qdrant_key` | Qdrant Cloud API Key | password |

### Autoload Additions

`composer.json` PSR-4 mapping already covers `FlavorAgent\\` → `inc/`, so new namespaces `FlavorAgent\AzureOpenAI` and `FlavorAgent\Patterns` are autoloaded automatically.

### Admin Asset Build

The plugin currently builds only `src/index.js`. To ship a settings-page sync button, the project must extend the default `@wordpress/scripts` webpack config with explicit multi-entry output:

- `index` → `src/index.js`
- `admin` → `src/admin/sync-button.js`

Expected build artifacts:

- `build/index.js` + `build/index.asset.php`
- `build/admin.js` + `build/admin.asset.php`

`Settings.php` (or a small bootstrap helper it calls) is responsible for enqueuing `build/admin.js` only on the Flavor Agent settings page and localizing the REST root + `wp_rest` nonce for the sync button. `npm run build` must emit these artifacts before the feature is considered complete.

## API Notes

### Azure OpenAI URL Format

Both endpoints use the OpenAI-compatible `/openai/v1/` path, which Azure OpenAI supports natively. The `model` field in the request body specifies the deployment name. No `api-version` query parameter is required with this path format.

### check-status Contract Update

`InfraAbilities::check_status()` currently returns `{ configured: bool, model: string, availableAbilities: string[] }`. Because this ability is discoverable via the Abilities API, the top-level contract stays backward compatible and gains additive backend metadata:

- `configured` remains a boolean for backward compatibility and continues to reflect whether the existing Anthropic recommendation path is configured
- `model` remains the active Anthropic model string (or `null` if Anthropic is not configured)
- `availableAbilities` is computed dynamically: read-only implemented abilities are always listed; `recommend-block` requires Anthropic; `recommend-patterns` requires Azure OpenAI + Qdrant; stubbed abilities are omitted until implemented
- New `backends` object provides full readiness detail without exposing secrets:
  - `anthropic: { configured: bool, model: string|null }`
  - `azure_openai: { configured: bool, chatDeployment: string|null, embeddingDeployment: string|null }`
  - `qdrant: { configured: bool }`

This is an additive change, not a breaking one. `Registration.php` and the adjacent abilities spec must be updated in the same pass so the published schema matches the implementation exactly.

### Adjacent Spec Update

The abilities-api-integration spec (`2026-03-16-abilities-api-integration-design.md`) should be updated in the same pass to reflect:
- `Agent_Controller.php` and `Settings.php` are modified by the recommend-patterns iteration (they are not "unchanged" globally)
- `PatternAbilities.php` now implements `recommend_patterns()` using Azure OpenAI + Qdrant
- `Registration.php` and `InfraAbilities.php` have an additive `check-status` contract update

## Error Handling

All three HTTP clients (`EmbeddingClient`, `ResponsesClient`, `QdrantClient`) follow the existing `Client::chat()` pattern:

- Check `is_wp_error()` on `wp_remote_post()` result
- Check HTTP status code; return `WP_Error` for non-2xx
- Parse response body as JSON; return `WP_Error` on parse failure

Additional policies:

- **Rate limits (429):** Single retry after the `Retry-After` header value (or 2 seconds if absent)
- **Timeout:** `wp_remote_post` timeout set to 30 seconds for embedding/responses calls, 10 seconds for Qdrant
- **Credential validation:** All three clients check for non-empty credentials before making HTTP calls; missing credentials return `WP_Error('missing_credentials', ...)` immediately
- **Cold start:** If no ready index exists yet, `recommend_patterns()` returns `WP_Error( 'index_warming', ... )` instead of a successful empty recommendation list
- **Retry scheduling:** Query-time requests must not enqueue duplicate cron jobs; use `wp_next_scheduled()` plus a cooldown window before scheduling remote sync work

## Unchanged

- `Client.php` / `Prompt.php` — existing Anthropic path untouched
- Existing editor-facing JS Inspector recommendation flow — untouched

## Backward Compatibility

- WP < 6.9: Abilities hooks don't fire, so `recommend-patterns` is not discoverable via the Abilities API. The admin settings page and `POST /flavor-agent/v1/sync-patterns` route can still build the pattern index once this iteration lands.
- Missing Azure/Qdrant credentials: `recommend_patterns()` returns `WP_Error('missing_credentials', ...)` with actionable message pointing to Settings.
- Empty or missing Qdrant collection on a fresh install: the first recommendation request returns `index_warming` (or `index_unavailable` after a failed sync) until a ready index exists. First sync creates the collection via `QdrantClient::ensure_collection()`.
- Existing `check-status` consumers continue to receive top-level `configured` and `model` fields; backend-specific details arrive in the additive `backends` object.

## Verification

- Settings page shows Azure OpenAI and Qdrant fields in a separate section; credentials save and read correctly
- `npm run build` emits `build/admin.js` and `build/admin.asset.php` in addition to the existing editor assets
- "Sync Pattern Catalog" button calls `POST /flavor-agent/v1/sync-patterns` and reports indexed/removed counts plus sync status
- Theme switch and plugin activation/deactivation trigger background re-index
- First `recommend-patterns` call on a cold install returns `index_warming` until the initial catalog sync completes
- `recommend-patterns` with `{ "postType": "page", "prompt": "hero section" }` returns scored recommendations with `content` sourced from Qdrant (not LLM output) once the index is ready
- Stale fingerprint at query time schedules background sync only when the caller is allowed to do so and a refresh is not already queued; the request still proceeds with the current ready index
- Pattern content or metadata changes (same slug) are detected by the content-aware fingerprint
- Missing Azure/Qdrant credentials return `WP_Error` with actionable message, not a fatal
- Generic patterns remain eligible even when `templateType` or `blockContext.blockName` is present
- Editors without `manage_options` can request recommendations but do not enqueue remote sync work
- `check-status` preserves top-level `configured`/`model` and reports `recommend-patterns` as available when Azure + Qdrant credentials are configured
- Existing `recommend-block` (Anthropic) path is unaffected

## Execution Plan

### Phase 1: Contract Alignment

1. Update `Registration.php` so the published `check-status` schema matches the additive contract above.
2. Update `InfraAbilities::check_status()` to compute `availableAbilities` dynamically and populate the new `backends` object without exposing secrets.
3. Verify the runtime `check-status` payload against the registered ability schema.
4. Update the adjacent abilities integration spec in the same change.

### Phase 2: Build And Settings UX

1. Add `webpack.config.js` that extends the default `@wordpress/scripts` config with explicit `index` and `admin` entries.
2. Create `src/admin/sync-button.js` and emit `build/admin.js` plus `build/admin.asset.php`.
3. Extend `Settings.php` with the new Azure OpenAI and Qdrant fields plus a read-only sync status panel.
4. Enqueue the admin script only on the Flavor Agent settings page and localize the REST root + `wp_rest` nonce.
5. Verify the sync button loads without asset 404s or console errors.

### Phase 3: Index Lifecycle And Cold Start

1. Implement `PatternIndex` state persistence with `uninitialized`, `indexing`, `ready`, `stale`, and `error`.
2. Persist sync metadata (`last_attempt_at`, `last_synced_at`, `indexed_count`, `last_error`) alongside the fingerprint.
3. Implement cold-start request handling so `recommend_patterns()` returns `index_warming` until a ready index exists.
4. Surface the current sync state and last error on the settings page.
5. Verify fresh-install, ready, stale, and error-state behavior separately.

### Phase 4: Retrieval And Ranking

1. Implement query embedding against Azure OpenAI using the configured embedding deployment.
2. Implement Pass A pure semantic Qdrant retrieval.
3. Implement Pass B structural retrieval only when `templateType` or `blockContext.blockName` is present.
4. Union, dedupe, and score-merge the Qdrant candidates before sending them to the ranking LLM.
5. Rehydrate `title`, `categories`, and `content` from Qdrant payloads after the LLM returns `name`, `score`, and `reason`.
6. Verify that generic patterns remain in the candidate set when structural context is present.

### Phase 5: Scheduling And Cost Control

1. Add a single scheduler helper that all sync triggers use.
2. Guard scheduling with `wp_next_scheduled()` and a cooldown window so repeated stale requests do not enqueue duplicate jobs.
3. Allow query-time scheduling only for privileged callers; non-admin editors can detect stale state but do not trigger remote sync work.
4. Keep the manual REST sync route restricted to `manage_options`.
5. Verify behavior with both admin and editor roles.

### Phase 6: Regression Sweep

1. Re-run the Abilities API inventory checks and ensure schema drift is gone.
2. Confirm the existing Anthropic `recommend-block` path is unaffected.
3. Update any remaining spec references that still say `Agent_Controller.php` or `Settings.php` are unchanged.
4. Re-run the verification checklist above before implementation is marked complete.
