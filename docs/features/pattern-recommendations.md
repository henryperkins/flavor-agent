# Pattern Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.
For production debugging and live Qdrant inspection, also use `docs/reference/pattern-recommendation-debugging.md`.

## Exact Surface

- Primary surface: the native block inserter, patched so recommended patterns appear in a `Recommended` category
- Secondary surface: the inserter-toggle badge rendered by `src/patterns/InserterBadge.js`
- Unavailable state: when pattern backends are missing, the native inserter prepends a shared capability notice that explains which setup path is missing and links to `Settings > Flavor Agent` and `Settings > Connectors` when those actions are available
- There is no separate Flavor Agent sidebar for this feature; the user stays inside Gutenberg's normal inserter workflow, and the surface intentionally remains ranking/browse-only instead of participating in the lane/review/apply model
- Pattern recommendations do not use `resolvedContextSignature` and do not accept `resolveSignatureOnly`; freshness for this surface stays request-time and backend-runtime scoped rather than review/apply scoped

## Surfacing Conditions

- `window.flavorAgentData.canRecommendPatterns` must be true; that requires a compatible embedding backend plus Qdrant in `Settings > Flavor Agent`, and a usable chat provider from `Settings > Flavor Agent` or `Settings > Connectors`
- A post type must be available from `core/editor`
- The shared `wp_block` entity contract from `usePostTypeEntityContract()` must resolve so Flavor Agent can align the patched inserter category with the current WordPress pattern-entity contract while still defaulting the category slug to `recommended` when no live view config is exposed
- Passive fetch runs when the editor loads
- Active refresh runs when the inserter search input changes while the inserter is open
- Results are scoped by `visiblePatternNames`, derived from the current inserter root so nested insertion contexts only see patterns WordPress already allows there
- When `window.flavorAgentData.canRecommendPatterns` is false, no fetch runs and opening the inserter shows the shared unavailable-state notice instead of a silent no-op

## End-To-End Flow

1. `PatternRecommender()` in `src/patterns/PatternRecommender.js` builds a base input from post type, template type, and the current visible pattern set. The normalized `wp_block` entity contract from `usePostTypeEntityContract()` is resolved separately and used during the inserter-patching phase to align the recommended category slug
2. The component triggers `fetchPatternRecommendations()` on editor load and on debounced inserter-search changes
3. The store posts the request to `POST /flavor-agent/v1/recommend-patterns`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_patterns()` adapts the REST request to `FlavorAgent\Abilities\PatternAbilities::recommend_patterns()`
5. `PatternAbilities::recommend_patterns()` validates backend configuration and pattern-index runtime state
6. The backend builds a query string, pulls cache-backed WordPress developer guidance through `AISearchClient::maybe_search_with_cache_fallbacks()`, embeds the pattern query through `EmbeddingClient::embed()`, retrieves candidates from Qdrant in semantic and structural passes, reranks them through `ResponsesClient::rank()`, and filters out low-confidence results
7. The store saves the recommendations and `patchInserterPatterns()` rewrites the native pattern registry metadata through the compatibility layer, using the contract-derived recommended category slug and falling back to `recommended` instead of assuming a hard-coded `Recommended` category key
8. If pattern backends are unavailable, `PatternRecommender()` mounts the shared capability notice into the native inserter container instead of silently doing nothing
9. Otherwise `InserterBadge()` derives badge state from store status and mounts the badge next to the native inserter toggle when an anchor exists
10. The user inserts a recommended pattern through the normal WordPress inserter flow

## What This Surface Can Do

- Add a `Recommended` category to the native inserter data
- Align the patched recommended-category slug to the shared `wp_block` entity contract so Flavor Agent stays compatible with current WordPress pattern views while defaulting safely to `recommended`
- Enrich recommended patterns with contextual descriptions and extracted keywords
- Re-run recommendations as the user changes the inserter search text
- Scope results to the current insertion root instead of returning globally valid-but-unavailable patterns
- Show inserter-level status as count, loading, or error feedback
- Explain missing embedding, Qdrant, or chat setup paths inside the native inserter before any recommendation request can succeed

## Guardrails And Failure Modes

- If `visiblePatternNames` is present but empty, the backend returns no recommendations instead of suggesting invalid patterns
- If the pattern backends are unavailable, Flavor Agent now shows a shared why-unavailable notice in the native inserter instead of silently degrading to an empty state
- If the pattern index is uninitialized, stale without a usable snapshot, or failed without a usable snapshot, the backend returns an error and may schedule a sync for admins
- WordPress docs grounding is cache-only and non-blocking; cache misses fall back to the existing retrieval-and-rerank path instead of failing the request
- The badge fails closed when the inserter DOM anchor cannot be found
- Pattern Overrides and `blockVisibility` stay recommendation-only inputs for ranking and explanation; they do not widen insertion scope beyond the native `visiblePatternNames` contract
- Flavor Agent does not directly insert or undo recommended patterns; insertion still belongs to the core editor workflow

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `PatternRecommender()` in `src/patterns/PatternRecommender.js` | Watches editor state, search input, and visible pattern context |
| Unavailable notice | `PatternRecommender()` + `CapabilityNotice` | Mount shared why-unavailable messaging into the native inserter when backends are missing |
| Inserter patching | `patchInserterPatterns()` in `src/patterns/PatternRecommender.js` | Rewrites native pattern metadata for the recommended set |
| Badge UI | `InserterBadge()` and `getInserterBadgeState()` | Render count/loading/error state next to the inserter toggle |
| Store request | `fetchPatternRecommendations()` in `src/store/index.js` | Sends the request and tracks request state |
| REST handler | `Agent_Controller::handle_recommend_patterns()` | Adapts the REST request to the backend ability |
| Backend ability | `PatternAbilities::recommend_patterns()` | Runs validation, retrieval, reranking, and filtering |
| Docs grounding | `AISearchClient::maybe_search_with_cache_fallbacks()` | Supplies cache-backed WordPress developer guidance for the ranking prompt |
| Embeddings | `EmbeddingClient::embed()` | Turns the query into a vector |
| Vector search | `QdrantClient::search()` | Retrieves semantic and structural candidates |
| Ranking | `ResponsesClient::rank()` | Produces the final ordered recommendation set |

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/recommend-patterns`
- Ability: `flavor-agent/recommend-patterns`
- Helper ability: `flavor-agent/list-patterns`

## Key Implementation Files

- `src/patterns/PatternRecommender.js`
- `src/patterns/InserterBadge.js`
- `src/patterns/inserter-badge-state.js` — badge state machine; see `docs/reference/shared-internals.md`
- `src/patterns/recommendation-utils.js` — pattern metadata patching and badge reason extraction; see `docs/reference/shared-internals.md`
- `src/patterns/compat.js` — re-export facade for pattern settings and inserter DOM; see `docs/reference/shared-internals.md`
- `src/patterns/pattern-settings.js` — three-tier pattern API adapter; see `docs/reference/shared-internals.md`
- `src/patterns/inserter-dom.js` — inserter DOM selectors and finders; see `docs/reference/shared-internals.md`
- `src/utils/visible-patterns.js`
- `src/utils/editor-entity-contracts.js` — dual-store entity resolution and `usePostTypeEntityContract` hook; see `docs/reference/shared-internals.md`
- `src/utils/template-types.js` — template slug normalization; see `docs/reference/shared-internals.md`
- `src/components/CapabilityNotice.js` — shared backend-unavailable notice; see `docs/reference/shared-internals.md`
- `src/store/index.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Patterns/PatternIndex.php`
