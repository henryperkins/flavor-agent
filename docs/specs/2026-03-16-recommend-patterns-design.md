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
  "prompt": "hero section with call to action"
}
```

Only `postType` is required. All other fields are optional context enrichment.

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

- **Name:** `flavor-agent-patterns`
- **Dimensions:** 3072 (text-embedding-3-large)
- **Distance:** Cosine

### Point Structure

| Field | Type | Source |
|---|---|---|
| `id` | UUID (v5) | Deterministic UUID v5 from pattern `name` using a fixed namespace |
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
{first 500 characters of content}
```

Full markup is too noisy for semantic matching (HTML comments, JSON attributes). The condensed form preserves semantic signal while keeping token cost low.

### Sync Logic

The `PatternIndex` class orchestrates sync:

1. Read all registered patterns via `ServerCollector::for_patterns()`
2. Compute fingerprint: `md5()` of sorted `[ name, title, description, categories, md5(content) ]` per pattern. This ensures content or metadata changes trigger re-indexing even when pattern slugs stay the same.
3. Compare against fingerprint stored as a WP option (`flavor_agent_pattern_fingerprint`)
4. If match → skip. If stale:
   - Diff: identify added, removed, and changed patterns
   - Embed new/changed patterns in batches of 100 (Azure OpenAI supports arrays up to 2048 inputs, but batching at 100 keeps per-request token usage and timeout risk manageable)
   - Upsert points to Qdrant, delete removed pattern points
   - Store new fingerprint in WP option

### Sync Triggers

| Trigger | Mechanism | Timing |
|---|---|---|
| Admin button | `POST /flavor-agent/v1/sync-patterns` REST route, nonce-protected, `manage_options` capability. Settings page renders a button that calls this via `apiFetch`. Returns `{ indexed: int, removed: int, fingerprint: string }`. | Immediate, user-initiated |
| Theme/plugin change | `after_switch_theme`, `activated_plugin`, `deactivated_plugin` hooks → `wp_schedule_single_event` | Background, automatic |
| Query-time | Fingerprint check at start of `recommend_patterns()`. If stale, triggers background sync via `wp_schedule_single_event` and proceeds with the current index. Does NOT block the request. | Non-blocking, automatic |

## Recommend Flow

When `recommend-patterns` is invoked:

### Step 1: Staleness Check

Compare pattern fingerprint against stored value. If stale, schedule background sync via `wp_schedule_single_event` and proceed with the current index. The next call will use the updated index. This avoids blocking the request for 10-30 seconds if hundreds of patterns need embedding.

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

### Step 4: Search Qdrant

```
POST https://...qdrant.io:6333/collections/flavor-agent-patterns/points/query
Header: api-key: ***
Body: {
  "query": [<query_vector>],
  "limit": 10,
  "with_payload": true
}
```

Returns top 10 patterns with full payload including `content`.

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

Strip markdown fences defensively (matching the existing `Prompt::parse_response()` pattern), parse JSON, validate structure. The `content` field for each recommendation is sourced from the Qdrant payload (trusted data from the WordPress pattern registry), not from the LLM output. The LLM returns pattern `name` references; the implementation maps those back to the original content stored in Qdrant. String fields (`title`, `reason`) are sanitized with `sanitize_text_field()`.

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
      "title": "Pattern Title",
      "score": 0.85,
      "reason": "One sentence explaining why this pattern fits",
      "categories": ["category-slug"]
    }
  ]
}

Rules:
- Score 0.0 to 1.0 where 1.0 = perfect fit for the context.
- Omit patterns scoring below 0.3.
- Order by score descending.
- Consider: post type conventions, block proximity, template structure,
  category relevance, and the user's stated intent.
- Do not include the pattern content in your response — only name, title,
  score, reason, and categories. Content is attached from the source data.
- Return at most 8 recommendations.
```

## File Map

```
inc/
├── AzureOpenAI/
│   ├── EmbeddingClient.php    # NEW — wp_remote_post to /openai/v1/embeddings
│   ├── ResponsesClient.php    # NEW — wp_remote_post to /openai/v1/responses
│   └── QdrantClient.php       # NEW — wp_remote_post/get to Qdrant REST API
├── Patterns/
│   └── PatternIndex.php       # NEW — sync orchestrator
├── Abilities/
│   ├── PatternAbilities.php   # MODIFY — implement recommend_patterns()
│   └── InfraAbilities.php     # MODIFY — make check_status() report recommend-patterns conditionally
├── REST/
│   └── Agent_Controller.php   # MODIFY — add POST /flavor-agent/v1/sync-patterns route
└── Settings.php               # MODIFY — add Azure OpenAI + Qdrant settings section + sync button

flavor-agent.php               # MODIFY — add after_switch_theme, activated_plugin, deactivated_plugin hooks
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

## API Notes

### Azure OpenAI URL Format

Both endpoints use the OpenAI-compatible `/openai/v1/` path, which Azure OpenAI supports natively. The `model` field in the request body specifies the deployment name. No `api-version` query parameter is required with this path format.

### Qdrant Collection Configuration

The `content` payload field should be marked as non-indexed (`"index": false` in the payload schema) since it is only returned in search results, never used for filtering. This reduces memory usage in Qdrant.

## Error Handling

All three HTTP clients (`EmbeddingClient`, `ResponsesClient`, `QdrantClient`) follow the existing `Client::chat()` pattern:

- Check `is_wp_error()` on `wp_remote_post()` result
- Check HTTP status code; return `WP_Error` for non-2xx
- Parse response body as JSON; return `WP_Error` on parse failure

Additional policies:

- **Rate limits (429):** Single retry after the `Retry-After` header value (or 2 seconds if absent)
- **Timeout:** `wp_remote_post` timeout set to 30 seconds for embedding/responses calls, 10 seconds for Qdrant
- **Credential validation:** All three clients check for non-empty credentials before making HTTP calls; missing credentials return `WP_Error('missing_credentials', ...)` immediately

## Unchanged

- `Registration.php` — ability schema already defined, no changes needed
- `Client.php` / `Prompt.php` — existing Anthropic path untouched
- JS Inspector flow — completely independent

## Backward Compatibility

- WP < 6.9: Abilities hooks don't fire. Pattern indexing infrastructure still loads but is never invoked via abilities. Could be wired to a REST endpoint separately if desired.
- Missing Azure/Qdrant credentials: `recommend_patterns()` returns `WP_Error('missing_credentials', ...)` with actionable message pointing to Settings.
- Empty Qdrant collection: First call triggers sync automatically via staleness check.

## Verification

- Settings page shows Azure OpenAI and Qdrant fields in a separate section; credentials save and read correctly
- "Sync Pattern Catalog" button calls `POST /flavor-agent/v1/sync-patterns` and reports indexed/removed counts
- Theme switch and plugin activation/deactivation trigger background re-index
- `recommend-patterns` with `{ "postType": "page", "prompt": "hero section" }` returns scored recommendations with `content` sourced from Qdrant (not LLM output)
- Stale fingerprint at query time schedules background sync and proceeds with current index (non-blocking)
- Pattern content or metadata changes (same slug) are detected by the content-aware fingerprint
- Missing Azure/Qdrant credentials return `WP_Error` with actionable message, not a fatal
- `check-status` reports `recommend-patterns` as available when Azure + Qdrant credentials are configured
- Existing `recommend-block` (Anthropic) path is unaffected
