# Pattern Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.
For production debugging and live Qdrant inspection, also use `docs/reference/pattern-recommendation-debugging.md`.

## Exact Surface

- Primary surface: a Flavor Agent-owned recommendation shelf prepended inside the native block inserter
- Secondary surface: the inserter-toggle badge rendered by `src/patterns/InserterBadge.js`
- Unavailable state: when pattern backends are missing, the native inserter prepends a shared capability notice that explains which setup path is missing and links to `Settings > Flavor Agent` and `Settings > Connectors` when those actions are available
- There is no separate Flavor Agent sidebar for this feature; the user stays inside Gutenberg's normal inserter workflow, and the surface intentionally remains ranking/browse-only instead of participating in the lane/review/apply model
- Pattern recommendations do not use `resolvedContextSignature` and do not accept `resolveSignatureOnly`; freshness for this surface stays request-time and backend-runtime scoped rather than review/apply scoped

## Surfacing Conditions

- `window.flavorAgentData.canRecommendPatterns` must be true; that requires a compatible embedding backend plus Qdrant in `Settings > Flavor Agent`, and a usable text-generation provider in `Settings > Connectors`
- A post type must be available from `core/editor`
- Passive fetch runs when the editor loads
- Active refresh runs when the inserter search input changes while the inserter is open
- Results are scoped by `visiblePatternNames`, derived from the current inserter root so nested insertion contexts only see patterns WordPress already allows there; requests without that scope, or with an explicit empty scope, return no recommendations
- Recommended items only render when the current Gutenberg allowed-pattern selector exposes a matching pattern for this insertion point; otherwise the inserter shows an explicit “not currently exposing those patterns” message instead of patching core registry data
- When `window.flavorAgentData.canRecommendPatterns` is false, no fetch runs and opening the inserter shows the shared unavailable-state notice instead of a silent no-op

## End-To-End Flow

1. `PatternRecommender()` in `src/patterns/PatternRecommender.js` builds a base input from post type, template type, and the current visible pattern set
2. The component triggers `fetchPatternRecommendations()` on editor load and on debounced inserter-search changes
3. The store posts the request to `POST /flavor-agent/v1/recommend-patterns`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_patterns()` adapts the REST request to `FlavorAgent\Abilities\PatternAbilities::recommend_patterns()`
5. `PatternAbilities::recommend_patterns()` validates visible-pattern scope, backend configuration, and pattern-index runtime state
6. `PatternIndex::sync()` maintains a Qdrant corpus made from registered block patterns plus public-safe published user `wp_block` patterns across sync states normalized to Gutenberg's user-pattern name format, `core/block/{id}`
7. The backend builds a query string, pulls cache-backed WordPress developer guidance through `AISearchClient::maybe_search_with_cache_fallbacks()` without foreground AI Search warming, embeds the pattern query through `EmbeddingClient::embed()`, retrieves candidates from Qdrant in semantic and structural passes, rehydrates synced candidates from current readable `wp_block` posts, reranks them through `ResponsesClient::rank()`, and filters out low-confidence results
8. The store saves the recommendations and `PatternRecommender()` matches them against the current allowed-pattern selector result for the active inserter root
9. If pattern backends are unavailable, `PatternRecommender()` mounts the shared capability notice into the native inserter container instead of silently doing nothing
10. Otherwise `InserterBadge()` derives badge state from store status and mounts the badge next to the native inserter toggle when an anchor exists
11. The user inserts a recommended pattern directly from the Flavor Agent shelf, which dispatches the same core block insertion flow Gutenberg uses for pattern insertion

## What This Surface Can Do

- Surface ranked patterns in a local inserter shelf without rewriting Gutenberg's pattern registry
- Rank both registered patterns and synced/user patterns that Gutenberg exposes to the current insertion root
- Insert matched allowed patterns directly from that shelf while still respecting the current allowed insertion root
- Re-run recommendations as the user changes the inserter search text
- Scope results to the current insertion root instead of returning globally valid-but-unavailable patterns
- Show inserter-level status as shelf, loading, empty, unavailable, or error feedback
- Explain missing embedding, Qdrant, or chat setup paths inside the native inserter before any recommendation request can succeed

## Guardrails And Failure Modes

- If `visiblePatternNames` is missing or present but empty, the backend returns no recommendations instead of suggesting invalid patterns
- Synced/user pattern candidates keep their `core/block/{id}` names through indexing and recommendation output so the frontend can match them to Gutenberg's allowed-pattern data before insertion
- Synced/user recommendation payloads are rehydrated from the current `wp_block` post and require `current_user_can( 'read_post', $id )` before ranking or response output, even though the indexed corpus is already limited to published user patterns
- If the pattern backends are unavailable, Flavor Agent now shows a shared why-unavailable notice in the native inserter instead of silently degrading to an empty state
- If the backend returns ranked names that Gutenberg is not currently exposing through the allowed-pattern selector, the inserter keeps the result local and explanatory instead of patching registry metadata
- If the pattern index is uninitialized, stale without a usable snapshot, or failed without a usable snapshot, the backend returns an error and may schedule a sync for admins
- WordPress docs grounding is cache-only and non-blocking; cache misses fall back to the existing retrieval-and-rerank path, schedule async cache warming, and never perform live AI Search in the foreground request
- The badge fails closed when the inserter DOM anchor cannot be found and only counts recommendations that the current allowed-pattern selector can render
- Pattern Overrides and `blockVisibility` stay recommendation-only inputs for ranking and explanation; they do not widen insertion scope beyond the native `visiblePatternNames` contract
- Flavor Agent does not add its own executable undo/activity contract for pattern insertion; insertion still lands in the core editor workflow. Scoped recommendation request diagnostics can still be persisted for audit.

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `PatternRecommender()` in `src/patterns/PatternRecommender.js` | Watches editor state, search input, and visible pattern context |
| Unavailable notice | `PatternRecommender()` + `CapabilityNotice` | Mount shared why-unavailable messaging into the native inserter when backends are missing |
| Inserter shelf | `PatternRecommender()` in `src/patterns/PatternRecommender.js` | Renders the local recommendation shelf and dispatches core block insertion for matched allowed patterns |
| Badge UI | `InserterBadge()` and `getInserterBadgeState()` | Render count/loading/error state next to the inserter toggle, counting only renderable allowed-pattern matches |
| Store request | `fetchPatternRecommendations()` in `src/store/index.js` | Sends the request and tracks request state |
| REST handler | `Agent_Controller::handle_recommend_patterns()` | Adapts the REST request to the backend ability |
| Backend ability | `PatternAbilities::recommend_patterns()` | Runs validation, retrieval, reranking, and filtering |
| Pattern corpus | `PatternIndex::sync()` + `SyncedPatternRepository` | Indexes registered patterns plus public-safe published user `wp_block` patterns as `core/block/{id}` candidates |
| Docs grounding | `AISearchClient::maybe_search_with_cache_fallbacks()` | Supplies cache-backed WordPress developer guidance for the ranking prompt and queues async warming on misses |
| Embeddings | `EmbeddingClient::embed()` | Turns the query into a vector |
| Vector search | `QdrantClient::search()` | Retrieves semantic and structural candidates |
| Ranking | `ResponsesClient::rank()` | Produces the final ordered recommendation set |

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/recommend-patterns`
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
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Patterns/PatternIndex.php`
- `inc/Context/SyncedPatternRepository.php`
