# Pattern Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.
For production debugging and retrieval-backend inspection, also use `docs/reference/pattern-recommendation-debugging.md`.

## Exact Surface

- Primary surface: a Flavor Agent-owned recommendation shelf prepended inside the native block inserter
- Secondary surface: the inserter-toggle badge rendered by `src/patterns/InserterBadge.js`
- Unavailable state: when Pattern Storage or the Embedding Model is missing, the native inserter prepends a shared capability notice that explains which setup path is missing and links to `Settings > Flavor Agent` and `Settings > Connectors` when those actions are available
- There is no separate Flavor Agent sidebar for this feature; the user stays inside Gutenberg's normal inserter workflow, and the surface intentionally remains an inserter ranking and guarded insertion assist instead of participating in the lane/review/apply/undo model
- Pattern recommendations return `reviewContextSignature` and `resolvedContextSignature`; both `Insert original` and `Insert adapted` revalidate the server-resolved apply context through `resolveSignatureOnly` before dispatching core insertion, while the surface still avoids a separate Flavor Agent review/apply panel
- Non-synced recommended patterns expose `Preview adapted` and `Insert original`; preview shows an original/adapted comparison when adjustments are ready, one original preview when no changes are needed, or diagnostics when adaptation is blocked. Synced/user `core/block` reference patterns keep a single unchanged `Insert` action and are not detached

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

## Adapted Preview

For non-synced recommended patterns, the shelf offers `Preview adapted` beside `Insert original`. Preview preserves the untouched resolved block tree and applies only deterministic cosmetic mutations to a detached clone. A `ready` result renders a stacked compare panel with labeled `Original pattern` and `Adapted result` `BlockPreview` sections plus per-change summary rows. `Insert adapted` re-clones the previewed adapted tree immediately before dispatch so Gutenberg receives fresh block instances.

An `unchanged` result shows **No changes needed**, one original preview, and `Insert original`, which uses the existing original-insertion freshness and validation path. It records `adapted_preview_shown` with reason `no_changes_needed`. A `blocked` result explains why adaptation could not proceed and keeps `Insert original` available when safe.

Structured diagnostics identify missing theme presets, unsupported block controls, unmapped color or spacing presets, and ambiguous color roles. Before reporting no changes, the engine also checks presets that no rule adjusts — font size, font family, gradient, and border color attributes, core Cover/Navigation/Social Icons colors, and preset references in `style` — and reports any the theme lacks as `unresolved_theme_preset`. If other adjustments succeed, the comparison remains available with the diagnostics and unresolved values left unchanged. A zero-change result with unresolved presets is blocked. Per-value diagnostics remain in the client preview; Activity records the outcome reason without full block content.

Preset checks and color/spacing adjustments resolve the current block's theme settings, including `settings.blocks[blockName]`. Global presets remain available through inheritance; block presets override matching slugs after resolving default, theme, and custom origins within each scope. Nested blocks use their own block-type settings, and block-specific color opt-outs are respected. Border diagnostics recognize both `border.color` and Gutenberg's `__experimentalBorder.color` support keys. The same settings are collected again before adapted insertion, so a changed mapping invalidates the preview.

Inline `style` preset references also recognize CSS variables inherited from ancestor blocks inside the pattern. An existing inherited spacing variable is preserved without remapping. This follows the current ancestor branch: sibling presets, preset classes, and ancestor control permissions do not become available to a child. CSS preset names use WordPress's kebab-case normalization, so a theme slug such as `brandBlue` defines `--wp--preset--color--brand-blue`; literal CSS variable references remain case-sensitive, and raw preset attributes keep their original slugs.

The v1 mutation allowlist is intentionally narrow:

- heading levels can be adjusted to follow the nearby heading hierarchy,
- supported `align` values can be matched to the insertion container or sibling context,
- supported text/background color preset slugs can be remapped to theme palette roles,
- supported spacing preset values can be remapped to the nearest available theme spacing preset,
- `core/button` can receive a registered non-default style variation when it does not already have one.

Color remapping preserves slugs already in the active palette, including custom slugs. Off-theme semantic slugs try their own slug, then known aliases, and match only explicit palette labels: an exact slug, an exact display name, or a parenthetical label such as **Evergreen (brand)**. Names that merely contain a role word, such as Twenty Twenty-Four's **Accent / Two** or **Primary Dark**, are not role labels; entries sharing a label are ambiguous, and unlabeled colors are not assigned roles from hue or palette order. Pattern adaptation and the Style Book execution contract share WordPress's color-support defaults: declaring `supports.color` enables text and background unless explicitly disabled, while each surface also respects theme restrictions.

The preview reads a client-only `adaptationContext` from the live editor at preview time: nearby heading levels, preceding heading level, root alignment, and sibling alignments around the current insertion point. That context is not sent to ranking and is not part of the server `resolvedContextSignature`; instead the preview records a local `adaptationSignature` over the source pattern, insertion target, server-resolved signature, adaptation context, and applied changes.

`Insert adapted` rechecks that local adaptation signature immediately before insertion. If the insertion point or nearby adaptation context has drifted, Flavor Agent records `stale_blocked` with reason `adapted_preview_stale`, marks the panel stale, and refreshes recommendations instead of dispatching stale adapted blocks. If the deterministic rules cannot build a safe adapted clone, Flavor Agent records `adaptation_blocked` and leaves `Insert original` available when the original remains safe.

The adapted and original paths share the same apply-time safety gates: target-signature check, `resolveSignatureOnly` server revalidation, live allowed-block checks, awaited `insertBlocks()`, post-dispatch target verification, and wrong-target rollback. Original insertion is unchanged; synced/user `core/block` references are not adapted or detached in v1.

Recommendation outcome events added for this path are `adapted_preview_shown`, `adapted_inserted_from_preview`, `adaptation_blocked`, and `adapted_insert_failed`.

## End-To-End Flow

1. `PatternRecommender()` in `src/patterns/PatternRecommender.js` builds a base input from post type, template type, and the current visible pattern set
2. The component triggers `fetchPatternRecommendations()` on block inserter intent (open with a non-empty visible-pattern scope) and on debounced inserter-search changes, tagging each request with `requestPurpose: "inserter_ranking"`
3. The store executes the `flavor-agent/recommend-patterns` ability with the request input
4. `FlavorAgent\Abilities\RecommendationAbilityExecution` adapts the ability input to `FlavorAgent\Abilities\PatternAbilities::recommend_patterns()`
5. `PatternAbilities::recommend_patterns()` validates visible-pattern scope, backend configuration, and pattern-index runtime state, then computes review/apply signatures from the normalized request context, docs-grounding fingerprint, and stable pattern-catalog identity
6. `PatternIndex::sync()` maintains the selected retrieval backend corpus made from registered block patterns plus public-safe published user `wp_block` patterns across sync states normalized to Gutenberg's user-pattern name format, `core/block/{id}`
7. The backend builds a query string, pulls WordPress developer guidance through `AISearchClient::maybe_search_best_effort()` (via `CollectsDocsGuidance`) using the same bounded foreground grounding path as other recommendation surfaces, retrieves candidates through the selected pattern backend, rehydrates synced candidates from current published readable `wp_block` posts, records aggregate filtered-candidate diagnostics, reranks readable candidates through `ResponsesClient::rank()` with design metadata and component-score context, and filters out low-confidence results
8. The Qdrant backend embeds the pattern query through Cloudflare Workers AI and retrieves semantic and structural candidates from Qdrant
9. The Cloudflare AI Search backend sends the query and `visiblePatternNames` filter to the private pattern AI Search instance, using Cloudflare-managed indexing/search instead of `EmbeddingClient` or `QdrantClient`
10. The store saves the recommendations plus the server `resolvedContextSignature`, and `PatternRecommender()` matches them against the current allowed-pattern selector result for the active inserter root
11. If Pattern Storage or the Embedding Model is unavailable, `PatternRecommender()` mounts the shared capability notice into the native inserter container instead of silently doing nothing
12. Otherwise `InserterBadge()` derives badge state from store status and mounts the badge next to the native inserter toggle when an anchor exists
13. For non-synced patterns, the shelf offers `Preview adapted` and `Insert original`. `Preview adapted` resolves the original pattern blocks, builds an adapted clone, reads a client-only nearby heading/alignment context, applies deterministic cosmetic mutations, and renders both trees in `PatternAdaptationPreview` with a deterministic change summary.
14. The user inserts either the original blocks or a fresh clone of the previewed adapted tree from the Flavor Agent shelf. Before dispatching core block insertion, the click handler checks the client insertion-target signature, reruns `flavor-agent/recommend-patterns` with `resolveSignatureOnly: true`, and blocks insertion if the server `resolvedContextSignature` no longer matches. Adapted insertion also rechecks the local `adaptationSignature` so nearby heading/alignment drift cannot insert a stale preview. After dispatch, it verifies that Gutenberg reported the cloned blocks at the requested target; if Gutenberg inserts them elsewhere, Flavor Agent removes those cloned blocks and records a diagnostic failure instead of logging a successful insert.

## Contract Pointers

- Ability request, response, freshness fields, and retrieval backend matrix: `docs/reference/abilities-and-routes.md#pattern-recommendation-backend-matrix`
- Provider ownership and credential precedence: `docs/reference/provider-precedence.md#pattern-storage-backend-chain`
- Production debugging and backend inspection: `docs/reference/pattern-recommendation-debugging.md`

## What This Surface Can Do

- Surface ranked patterns in a local inserter shelf without rewriting Gutenberg's pattern registry
- Rank both registered patterns and synced/user patterns that Gutenberg exposes to the current insertion root
- Insert matched allowed patterns directly from that shelf while still respecting the current allowed insertion root
- Preview and compare the original and adapted block trees for non-synced patterns before insertion
- Revalidate the current server apply context before direct insertion, so docs-grounding or pattern-catalog drift cannot apply an old ranked result
- Re-run recommendations as the user changes the inserter search text
- Scope results to the current insertion root instead of returning globally valid-but-unavailable patterns
- Show inserter-level status as shelf, loading, empty, unavailable, catalog-unavailable, or error feedback
- Explain missing embedding, Qdrant, private Cloudflare AI Search, or chat setup paths inside the native inserter before any recommendation request can succeed
- Show compact "why this pattern" metadata in the inserter shelf using source signals, matched category, allowed inserter context, component scores, design metadata, and nearby-block fit where those fields are available.

## Guardrails And Failure Modes

- If `visiblePatternNames` is missing or present but empty, the backend returns no recommendations instead of suggesting invalid patterns
- Synced/user pattern candidates keep their `core/block/{id}` names through indexing and recommendation output so the frontend can match them to Gutenberg's allowed-pattern data before insertion
- Synced/user recommendation payloads are rehydrated from the current published `wp_block` post and require `current_user_can( 'read_post', $id )` before ranking or response output, even though the indexed corpus is already limited to published user patterns
- When synced/user candidates in the current visible-pattern scope are filtered because the current request cannot pass `read_post`, the response returns a de-duplicated aggregate unreadable-synced count only. The UI can explain partial or empty results without exposing pattern names, IDs, titles, or content.
- If Pattern Storage or the Embedding Model is unavailable, Flavor Agent now shows a shared why-unavailable notice in the native inserter instead of silently degrading to an empty state
- If WordPress itself exposes no block patterns — core resolves `getBlockPatterns` once per page with no `shouldInvalidate`, so a failed or empty `/wp/v2/block-patterns/patterns` response otherwise persists for the editor's lifetime — the inserter shows a catalog-unavailable notice after core finishes or a 20-second fallback wait. Retry invalidates core's resolution and starts a fresh bounded wait instead of reusing the expired timer or requesting an empty-scope ranking
- If the backend returns ranked names that Gutenberg is not currently exposing through the allowed-pattern selector, the inserter keeps the result local and explanatory instead of patching registry metadata
- If the pattern index is uninitialized, stale without a usable snapshot, or failed without a usable snapshot, the backend returns an error and may schedule a sync for admins
- If a stored recommendation lacks a server `resolvedContextSignature`, or the current `resolveSignatureOnly` response does not match the stored signature, the Insert action is blocked and the shelf refreshes recommendations for the current target
- If an adapted preview's client-only insertion context changes before `Insert adapted`, the adapted insert is blocked with `adapted_preview_stale` and the shelf refreshes recommendations for the current target
- If a pattern is a synced/user `core/block` reference, Flavor Agent keeps the unchanged reference path and does not show `Preview adapted`
- If the rules make no changes and report no unresolved presets, preview reports `unchanged` with `no_changes_needed` and offers original insertion; it does not record an adaptation failure
- If presets are missing, unsupported, unmapped, unresolved, or ambiguous, preview shows specific diagnostics. Successful adjustments may still be previewed with unresolved values preserved; zero successful adjustments with unresolved presets block adaptation
- If adapted blocks cannot be safely built, the adapted path records `adaptation_blocked` and leaves original insertion available when the original remains safe
- If Gutenberg rejects insertion, silently no-ops, or inserts cloned blocks outside the requested target, Flavor Agent records `insert_failed` for original insertion or `adapted_insert_failed` for adapted insertion. Wrong-target inserts are rolled back with `removeBlocks()` when the cloned client IDs are visible after dispatch.
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
| Adaptation context | `buildPatternAdaptationContext()` in `src/patterns/pattern-adaptation-context.js` | Reads nearby heading and alignment context from the live editor at preview time |
| Adaptation engine | `buildPatternAdaptationPreview()` in `src/patterns/pattern-adaptation.js` | Clones non-synced pattern blocks, applies deterministic cosmetic rules, and emits the local adaptation signature |
| Adapted preview UI | `PatternAdaptationPreview()` in `src/patterns/PatternAdaptationPreview.js` | Renders the labeled original/adapted compare UI with Gutenberg `BlockPreview`, deterministic change-summary rows, and adapted/original insert actions |
| Badge UI | `InserterBadge()` and `getInserterBadgeState()` | Render count/loading/error state next to the inserter toggle, counting only renderable allowed-pattern matches |
| Store request | `fetchPatternRecommendations()` in `src/store/index.js` | Sends the request and tracks request state, including the stored server apply signature |
| Store revalidation | `resolvePatternRecommendationSignature()` in `src/store/index.js` | Reposts the current pattern input with `resolveSignatureOnly` before direct shelf insertion |
| Ability wrapper | `RecommendationAbilityExecution::execute()` | Adapts the ability request to the backend handler and records request diagnostics |
| Backend ability | `PatternAbilities::recommend_patterns()` | Runs validation, selected-backend retrieval, reranking, and filtering |
| Pattern corpus | `PatternIndex::sync()` + `SyncedPatternRepository` | Indexes registered patterns plus public-safe published user `wp_block` patterns as `core/block/{id}` candidates in the selected backend |
| Retrieval selector | `PatternRetrievalBackendFactory` | Chooses Qdrant or private Cloudflare AI Search retrieval from settings/runtime state |
| Docs grounding | `AISearchClient::maybe_search_best_effort()` (via `CollectsDocsGuidance`) | Supplies cache-backed WordPress developer guidance for the ranking prompt (best-effort with shared cache and async warm on misses) |
| Qdrant embeddings | `EmbeddingClient::embed()` | Turns the query into a vector for the Qdrant backend only |
| Qdrant vector search | `QdrantClient::search()` | Retrieves semantic and structural candidates for the Qdrant backend only |
| Private AI Search | `PatternSearchClient::search_patterns()` | Retrieves filtered candidates from Cloudflare AI Search Pattern Storage |
| Design metadata and component ranking | `PatternDesignMetadata` / `PatternComponentScorer` | Adds pattern-trait text to embedding/search payloads and exposes component scores through `ranking.rankingHint.componentScores` |
| Ranking | `ResponsesClient::rank()` | Produces the final ordered recommendation set |

## Related Abilities

- Ability: `flavor-agent/recommend-patterns`
- Helper ability: `flavor-agent/list-patterns`
- Helper abilities: `flavor-agent/list-synced-patterns`, `flavor-agent/get-synced-pattern`

## Key Implementation Files

- `src/patterns/PatternRecommender.js`
- `src/patterns/PatternAdaptationPreview.js`
- `src/patterns/InserterBadge.js`
- `src/patterns/pattern-adaptation.js`
- `src/patterns/pattern-adaptation-context.js`
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
