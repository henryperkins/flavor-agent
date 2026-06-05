# Pattern Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.
For production debugging and retrieval-backend inspection, also use `docs/reference/pattern-recommendation-debugging.md`.

## Exact Surface

- Primary surface: a Flavor Agent-owned recommendation shelf prepended inside the native block inserter
- Secondary surface: the inserter-toggle badge rendered by `src/patterns/InserterBadge.js`
- Unavailable state: when Pattern Storage or the Embedding Model is missing, the native inserter prepends a shared capability notice that explains which setup path is missing and links to `Settings > Flavor Agent` and `Settings > Connectors` when those actions are available
- There is no separate Flavor Agent sidebar for this feature; the user stays inside Gutenberg's normal inserter workflow, and the surface intentionally remains ranking/browse-only instead of participating in the lane/review/apply/undo model
- Pattern recommendations return `reviewContextSignature` and `resolvedContextSignature`; the direct Insert button revalidates the server-resolved apply context through `resolveSignatureOnly` before dispatching core insertion, while the surface still avoids a separate Flavor Agent review/apply panel

## Surfacing Conditions

- `window.flavorAgentData.canRecommendPatterns` must be true; that requires a usable text-generation provider in `Settings > Connectors` plus a selected Pattern Storage backend with a ready usable pattern index in `Settings > Flavor Agent`
- Qdrant storage readiness requires the Cloudflare Workers AI Embedding Model plus Qdrant URL/key
- Cloudflare AI Search backend readiness requires the Embedding Model Cloudflare account/token plus the normalized AI Search embedding model and a managed site-owned Cloudflare AI Search pattern instance validated against those values; it does not call plugin-owned embedding generation or Qdrant
- A post type must be available from `core/editor`
- No model-backed ranking runs on editor load; the first real ranking is sent only after the block inserter opens with a non-empty visible-pattern scope
- Active refresh runs when the inserter search input changes while the inserter is open
- Results are scoped by `visiblePatternNames`, derived from the current inserter root so nested insertion contexts only see patterns WordPress already allows there; requests without that scope, or with an explicit empty scope, return no recommendations
- Recommended items only render when the current Gutenberg allowed-pattern selector exposes a matching pattern for this insertion point; otherwise the inserter shows an explicit “not currently exposing those patterns” message instead of patching core registry data
- When `window.flavorAgentData.canRecommendPatterns` is false, no fetch runs and opening the inserter shows the shared unavailable-state notice instead of a silent no-op

## Ranking Warm-Up And Target Cache

Pattern recommendations do not run a model-backed ranking request on editor load. The editor may warm capability, backend, connector, docs-grounding, and visible-pattern readiness state, but the first real `recommend-patterns` call is sent only after block inserter intent and only when the current insertion point exposes non-empty `visiblePatternNames`.

Completed base inserter-open rankings are cached per insertion target. The cache key is the pattern request signature over post type, template type, insertion context, visible pattern scope, selected block context, and the server-provided `patternRuntimeSignature`. Search refinements re-fetch and are intentionally not cached, so a search never overwrites the cached base ranking and unread search keys never accumulate. Cache hits hydrate the store with a fresh request token and preserve the stored request signature, insertion-target signature, server `resolvedContextSignature`, docs-grounding warning, diagnostics, and runtime signature. Insert still revalidates the server apply context before dispatching blocks.

When a real inserter-intent request ends before a model call, diagnostics carry `diagnostics.modelRequest.attempted === false` with an allow-listed reason such as `no_rankable_candidates` or `missing_visible_patterns`. Activity renders that as a no-model diagnostic instead of implying a missing core AI request log.

## End-To-End Flow

1. `PatternRecommender()` in `src/patterns/PatternRecommender.js` builds a base input from post type, template type, and the current visible pattern set
2. The component triggers `fetchPatternRecommendations()` on block inserter intent (open with a non-empty visible-pattern scope) and on debounced inserter-search changes, tagging each request with `requestPurpose: "inserter_ranking"`
3. The store executes the `flavor-agent/recommend-patterns` ability with the request input
4. `FlavorAgent\Abilities\RecommendationAbilityExecution` adapts the ability input to `FlavorAgent\Abilities\PatternAbilities::recommend_patterns()`
5. `PatternAbilities::recommend_patterns()` validates visible-pattern scope, backend configuration, and pattern-index runtime state, then computes review/apply signatures from the normalized request context, docs-grounding fingerprint, and stable pattern-catalog identity
6. `PatternIndex::sync()` maintains the selected retrieval backend corpus made from registered block patterns plus public-safe published user `wp_block` patterns across sync states normalized to Gutenberg's user-pattern name format, `core/block/{id}`
7. The backend builds a query string, pulls WordPress developer guidance through `AISearchClient::maybe_search_with_cache_fallbacks()` using the same bounded foreground grounding path as other recommendation surfaces, retrieves candidates through the selected pattern backend, rehydrates synced candidates from current published readable `wp_block` posts, records aggregate filtered-candidate diagnostics, reranks readable candidates through `ResponsesClient::rank()`, and filters out low-confidence results
8. The Qdrant backend embeds the pattern query through Cloudflare Workers AI and retrieves semantic and structural candidates from Qdrant
9. The Cloudflare AI Search backend sends the query and `visiblePatternNames` filter to the private pattern AI Search instance, using Cloudflare-managed indexing/search instead of `EmbeddingClient` or `QdrantClient`
10. The store saves the recommendations plus the server `resolvedContextSignature`, and `PatternRecommender()` matches them against the current allowed-pattern selector result for the active inserter root
11. If Pattern Storage or the Embedding Model is unavailable, `PatternRecommender()` mounts the shared capability notice into the native inserter container instead of silently doing nothing
12. Otherwise `InserterBadge()` derives badge state from store status and mounts the badge next to the native inserter toggle when an anchor exists
13. The user inserts a recommended pattern directly from the Flavor Agent shelf. Before dispatching core block insertion, the click handler checks the client insertion-target signature, reruns `flavor-agent/recommend-patterns` with `resolveSignatureOnly: true`, and blocks insertion if the server `resolvedContextSignature` no longer matches. After dispatch, it verifies that Gutenberg reported the cloned blocks at the requested target; if Gutenberg inserts them elsewhere, Flavor Agent removes those cloned blocks and records a diagnostic failure instead of logging a successful insert.

## Contract Pointers

- Ability request, response, freshness fields, and retrieval backend matrix: `docs/reference/abilities-and-routes.md#pattern-recommendation-backend-matrix`
- Provider ownership and credential precedence: `docs/reference/provider-precedence.md#pattern-storage-backend-chain`
- Production debugging and backend inspection: `docs/reference/pattern-recommendation-debugging.md`

## What This Surface Can Do

- Surface ranked patterns in a local inserter shelf without rewriting Gutenberg's pattern registry
- Rank both registered patterns and synced/user patterns that Gutenberg exposes to the current insertion root
- Insert matched allowed patterns directly from that shelf while still respecting the current allowed insertion root
- Revalidate the current server apply context before direct insertion, so docs-grounding or pattern-catalog drift cannot apply an old ranked result
- Re-run recommendations as the user changes the inserter search text
- Scope results to the current insertion root instead of returning globally valid-but-unavailable patterns
- Show inserter-level status as shelf, loading, empty, unavailable, or error feedback
- Explain missing embedding, Qdrant, private Cloudflare AI Search, or chat setup paths inside the native inserter before any recommendation request can succeed
- Show compact "why this pattern" metadata in the inserter shelf using source signals, matched category, allowed inserter context, and nearby-block fit where those fields are available.

## Guardrails And Failure Modes

- If `visiblePatternNames` is missing or present but empty, the backend returns no recommendations instead of suggesting invalid patterns
- Synced/user pattern candidates keep their `core/block/{id}` names through indexing and recommendation output so the frontend can match them to Gutenberg's allowed-pattern data before insertion
- Synced/user recommendation payloads are rehydrated from the current published `wp_block` post and require `current_user_can( 'read_post', $id )` before ranking or response output, even though the indexed corpus is already limited to published user patterns
- When synced/user candidates in the current visible-pattern scope are filtered because the current request cannot pass `read_post`, the response returns a de-duplicated aggregate unreadable-synced count only. The UI can explain partial or empty results without exposing pattern names, IDs, titles, or content.
- If Pattern Storage or the Embedding Model is unavailable, Flavor Agent now shows a shared why-unavailable notice in the native inserter instead of silently degrading to an empty state
- If the backend returns ranked names that Gutenberg is not currently exposing through the allowed-pattern selector, the inserter keeps the result local and explanatory instead of patching registry metadata
- If the pattern index is uninitialized, stale without a usable snapshot, or failed without a usable snapshot, the backend returns an error and may schedule a sync for admins
- If a stored recommendation lacks a server `resolvedContextSignature`, or the current `resolveSignatureOnly` response does not match the stored signature, the Insert action is blocked and the shelf refreshes recommendations for the current target
- If Gutenberg rejects insertion, silently no-ops, or inserts cloned blocks outside the requested target, Flavor Agent records an `insert_failed` recommendation outcome. Wrong-target inserts are rolled back with `removeBlocks()` when the cloned client IDs are visible after dispatch.
- Cloudflare AI Search sync uploads only public-safe current pattern content. It preserves owner-marker items and unknown remote items, and deletes only stale item IDs that were recorded in the previous Flavor Agent pattern fingerprint state. If a synced pattern later becomes private, draft, trashed, or unreadable before the next sync, request-time rehydration drops it before ranking or response output.
- WordPress docs grounding uses the shared cache/fallback collector and may perform one bounded foreground warm before reranking. If trusted grounding remains unavailable after candidate retrieval, pattern recommendations return `flavor_agent_docs_grounding_unavailable` instead of calling the reranker; stale or degraded trusted grounding proceeds with warning metadata.
- The badge fails closed when the inserter DOM anchor cannot be found and only counts recommendations that the current allowed-pattern selector can render
- Pattern Overrides and `blockVisibility` stay recommendation-only inputs for ranking and explanation; they do not widen insertion scope beyond the native `visiblePatternNames` contract
- Flavor Agent does not add its own executable undo/activity contract for pattern insertion; successful insertion still lands in the core editor workflow. Scoped recommendation request diagnostics and recommendation-outcome diagnostics can still be persisted for audit.

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `PatternRecommender()` in `src/patterns/PatternRecommender.js` | Watches editor state, search input, and visible pattern context |
| Unavailable notice | `PatternRecommender()` + `CapabilityNotice` | Mount shared why-unavailable messaging into the native inserter when backends are missing |
| Inserter shelf | `PatternRecommender()` in `src/patterns/PatternRecommender.js` | Renders the local recommendation shelf and dispatches core block insertion for matched allowed patterns |
| Badge UI | `InserterBadge()` and `getInserterBadgeState()` | Render count/loading/error state next to the inserter toggle, counting only renderable allowed-pattern matches |
| Store request | `fetchPatternRecommendations()` in `src/store/index.js` | Sends the request and tracks request state, including the stored server apply signature |
| Store revalidation | `resolvePatternRecommendationSignature()` in `src/store/index.js` | Reposts the current pattern input with `resolveSignatureOnly` before direct shelf insertion |
| Ability wrapper | `RecommendationAbilityExecution::execute()` | Adapts the ability request to the backend handler and records request diagnostics |
| Backend ability | `PatternAbilities::recommend_patterns()` | Runs validation, selected-backend retrieval, reranking, and filtering |
| Pattern corpus | `PatternIndex::sync()` + `SyncedPatternRepository` | Indexes registered patterns plus public-safe published user `wp_block` patterns as `core/block/{id}` candidates in the selected backend |
| Retrieval selector | `PatternRetrievalBackendFactory` | Chooses Qdrant or private Cloudflare AI Search retrieval from settings/runtime state |
| Docs grounding | `AISearchClient::maybe_search_with_cache_fallbacks()` | Supplies cache-backed WordPress developer guidance for the ranking prompt and queues async warming on misses |
| Qdrant embeddings | `EmbeddingClient::embed()` | Turns the query into a vector for the Qdrant backend only |
| Qdrant vector search | `QdrantClient::search()` | Retrieves semantic and structural candidates for the Qdrant backend only |
| Private AI Search | `PatternSearchClient::search_patterns()` | Retrieves filtered candidates from Cloudflare AI Search Pattern Storage |
| Ranking | `ResponsesClient::rank()` | Produces the final ordered recommendation set |

## Related Abilities

- Ability: `flavor-agent/recommend-patterns`
- Helper ability: `flavor-agent/list-patterns`
- Helper abilities: `flavor-agent/list-synced-patterns`, `flavor-agent/get-synced-pattern`

## Key Implementation Files

- `src/patterns/PatternRecommender.js`
- `src/patterns/InserterBadge.js`
- `src/patterns/inserter-badge-state.js` — badge state machine; see `docs/reference/shared-internals.md`
- `src/patterns/recommendation-utils.js` — renderable recommendation matching and badge reason extraction; see `docs/reference/shared-internals.md`
- `src/patterns/compat.js` — re-export facade for pattern settings and inserter DOM; see `docs/reference/shared-internals.md`
- `src/patterns/pattern-settings.js` — three-tier pattern API adapter; see `docs/reference/shared-internals.md`
- `src/patterns/inserter-dom.js` — inserter DOM selectors and finders; see `docs/reference/shared-internals.md`
- `src/utils/visible-patterns.js`
- `src/utils/template-types.js` — template slug normalization; see `docs/reference/shared-internals.md`
- `src/components/CapabilityNotice.js` — shared backend-unavailable notice; see `docs/reference/shared-internals.md`
- `src/store/index.js`
- `src/store/abilities-client.js`
- `inc/Abilities/RecommendationAbilityExecution.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Patterns/PatternIndex.php`
- `inc/Patterns/Retrieval/PatternRetrievalBackendFactory.php`
- `inc/Context/SyncedPatternRepository.php`
