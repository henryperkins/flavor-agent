# Pattern Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Primary surface: the native block inserter, patched so recommended patterns appear in a `Recommended` category
- Secondary surface: the inserter-toggle badge rendered by `src/patterns/InserterBadge.js`
- There is no separate Flavor Agent sidebar for this feature; the user stays inside Gutenberg's normal inserter workflow

## Surfacing Conditions

- `window.flavorAgentData.canRecommendPatterns` must be true; that requires the active provider's chat and embedding backends plus Qdrant credentials
- A post type must be available from `core/editor`
- Passive fetch runs when the editor loads
- Active refresh runs when the inserter search input changes while the inserter is open
- Results are scoped by `visiblePatternNames`, derived from the current inserter root so nested insertion contexts only see patterns WordPress already allows there

## End-To-End Flow

1. `PatternRecommender()` in `src/patterns/PatternRecommender.js` builds a base input from post type, template type, and the current visible pattern set
2. The component triggers `fetchPatternRecommendations()` on editor load and on debounced inserter-search changes
3. The store posts the request to `POST /flavor-agent/v1/recommend-patterns`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_patterns()` adapts the REST request to `FlavorAgent\Abilities\PatternAbilities::recommend_patterns()`
5. `PatternAbilities::recommend_patterns()` validates backend configuration and pattern-index runtime state
6. The backend builds a query string, embeds it through `EmbeddingClient::embed()`, retrieves candidates from Qdrant in semantic and structural passes, reranks them through `ResponsesClient::rank()`, and filters out low-confidence results
7. The store saves the recommendations and `patchInserterPatterns()` rewrites the native pattern registry metadata through the compatibility layer
8. `InserterBadge()` derives badge state from store status and mounts the badge next to the native inserter toggle when an anchor exists
9. The user inserts a recommended pattern through the normal WordPress inserter flow

## What This Surface Can Do

- Add a `Recommended` category to the native inserter data
- Enrich recommended patterns with contextual descriptions and extracted keywords
- Re-run recommendations as the user changes the inserter search text
- Scope results to the current insertion root instead of returning globally valid-but-unavailable patterns
- Show inserter-level status as count, loading, or error feedback

## Guardrails And Failure Modes

- If `visiblePatternNames` is present but empty, the backend returns no recommendations instead of suggesting invalid patterns
- If the pattern index is uninitialized, stale without a usable snapshot, or failed without a usable snapshot, the backend returns an error and may schedule a sync for admins
- The badge fails closed when the inserter DOM anchor cannot be found
- Flavor Agent does not directly insert or undo recommended patterns; insertion still belongs to the core editor workflow

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `PatternRecommender()` in `src/patterns/PatternRecommender.js` | Watches editor state, search input, and visible pattern context |
| Inserter patching | `patchInserterPatterns()` in `src/patterns/PatternRecommender.js` | Rewrites native pattern metadata for the recommended set |
| Badge UI | `InserterBadge()` and `getInserterBadgeState()` | Render count/loading/error state next to the inserter toggle |
| Store request | `fetchPatternRecommendations()` in `src/store/index.js` | Sends the request and tracks request state |
| REST handler | `Agent_Controller::handle_recommend_patterns()` | Adapts the REST request to the backend ability |
| Backend ability | `PatternAbilities::recommend_patterns()` | Runs validation, retrieval, reranking, and filtering |
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
- `src/patterns/inserter-badge-state.js`
- `src/patterns/compat.js`
- `src/patterns/pattern-settings.js`
- `src/patterns/inserter-dom.js`
- `src/utils/visible-patterns.js`
- `src/store/index.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Patterns/PatternIndex.php`
