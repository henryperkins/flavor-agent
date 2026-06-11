# Abilities And Routes Reference

This document is the contract reference for Flavor Agent's programmatic surfaces.

Use it when you need to answer:

- which ability or route owns a feature
- what capability and backend gates apply
- whether a first-party UI uses REST, abilities, or both

## How First-Party UI And Abilities Relate

- The shipped Gutenberg editor UI uses the `flavor-agent` data store and executes recommendation abilities through the WordPress Abilities API
- The Abilities API is the sole active contract for the seven `recommend-*` surfaces when the WordPress AI plugin contracts are available and the Flavor Agent AI feature is enabled
- Activity creation, admin decisions, and manual pattern sync remain REST routes. Activity read/list/undo for external style applies are exposed as abilities via `get-activity`, `list-activity`, and `undo-activity`
- Pattern, template, and template-part first-party surfaces also read the shared post-type entity contract from `src/utils/editor-entity-contracts.js`, which normalizes built-in field metadata and safe fallbacks when no live WordPress view config is exposed, so panel visibility, title-field expectations, template-part area labels, and the patched pattern category stay aligned with the current entity contract

## Registered Abilities

| Ability                                | Permission                                                                                                        | Extra gate                                                                                                                                                                                                                                                                                                                                                                                                    | What it returns or does                                                                                                                                                                                                                                                                                                   | First-party surface                                                  |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| `flavor-agent/recommend-block`         | `edit_posts`; any positive post ID resolved from request/document context also requires `edit_post` for that post | Meaningful output requires `ChatClient::is_supported()` and trusted WordPress Developer Docs grounding                                                                                                                                                                                                                                                                                                        | Block recommendation payload with `settings`, `styles`, `block`, `explanation`, and `docsGrounding`                                                                                                                                                                                                                       | Block Inspector recommendations                                      |
| `flavor-agent/recommend-content`       | `edit_posts`; any positive post ID resolved from request/document context also requires `edit_post` for that post | Connectors text-generation provider configured                                                                                                                                                                                                                                                                                                                                                                | Draft, edit, or critique payload for blog posts, essays, and site copy in Henry Perkins's voice, with notes and critique issues containing original text, problem, and suggested revision. Positive `postId` requests render current-post blocks server-side before prompting; absent or `0` uses the text fallback path. | Post/page Content Recommendations panel plus external-agent contract |
| `flavor-agent/introspect-block`        | `edit_posts`                                                                                                      | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | Block registry manifest: supports, Inspector panels, attributes, styles, and variations                                                                                                                                                                                                                                   | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/list-allowed-blocks`     | `edit_posts`                                                                                                      | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | Site-wide registered block manifests plus `total`, with optional search, category, pagination, and variation controls; not filtered by current inserter context                                                                                                                                                           | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/recommend-patterns`      | `edit_posts`; any positive post ID resolved from request/document context also requires `edit_post` for that post | Selected pattern backend configured, Connectors text generation, usable pattern index, and trusted WordPress Developer Docs grounding. Qdrant backend uses Flavor Agent's plugin-owned Cloudflare Workers AI embedding configuration plus Qdrant; Cloudflare AI Search backend requires the validated managed pattern AI Search instance plus the Cloudflare Workers AI account/API token/normalized-AI-Search-model signature used to validate that instance | Ranked registered and synced/user patterns that are in the supplied visible scope and currently readable, plus `docsGrounding`                                                                                                                                                                                            | Pattern inserter recommendations                                     |
| `flavor-agent/list-patterns`           | `edit_posts`                                                                                                      | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | Registered block patterns with optional category, block-type, template-type, search, pagination, and `includeContent` controls, plus `total`                                                                                                                                                                              | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/get-pattern`             | `edit_posts`                                                                                                      | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | One registered block pattern by name; `patternId` is an alias for the returned string `id`                                                                                                                                                                                                                                | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/list-synced-patterns`    | `edit_posts`                                                                                                      | Per-post read access with published browse fallback                                                                                                                                                                                                                                                                                                                                                           | Caller-readable or published `wp_block` pattern entities filtered by `syncStatus` (`synced`, `partial`, `unsynced`, or `all`), with optional search, pagination, `includeContent`, and `total`                                                                                                                            | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/get-synced-pattern`      | `edit_posts`                                                                                                      | Per-post read access with published browse fallback                                                                                                                                                                                                                                                                                                                                                           | One caller-readable or published `wp_block` pattern entity by numeric post ID                                                                                                                                                                                                                                             | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/recommend-template`      | `edit_theme_options`                                                                                              | Connectors text-generation provider configured and trusted WordPress Developer Docs grounding                                                                                                                                                                                                                                                                                                                  | Template suggestions plus validated template-part operations and bounded pattern insertions with explicit placement, optional anchor metadata, and `docsGrounding`                                                                                                                                                        | Site Editor template panel                                           |
| `flavor-agent/recommend-template-part` | `edit_theme_options`                                                                                              | Connectors text-generation provider configured and trusted WordPress Developer Docs grounding                                                                                                                                                                                                                                                                                                                  | Template-part suggestions, focus blocks, patterns, validated bounded operations constrained by executable paths and anchors, and `docsGrounding`                                                                                                                                                                          | Site Editor template-part panel                                      |
| `flavor-agent/recommend-style`         | `edit_theme_options`                                                                                              | Connectors text-generation provider configured and trusted WordPress Developer Docs grounding                                                                                                                                                                                                                                                                                                                  | Shared style suggestions for Global Styles and Style Book, constrained to validated `theme.json` paths, theme-backed values, Global Styles-only theme variations, and `docsGrounding`                                                                                                                                    | Site Editor Global Styles and Style Book panels                      |
| `flavor-agent/list-template-parts`     | `edit_posts` or `edit_theme_options`                                                                              | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | Registered template parts, optionally filtered by area, with content returned only to theme-capable callers                                                                                                                                                                                                               | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/list-templates`          | `edit_posts` or `edit_theme_options`                                                                              | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | Registered block templates with id (the templateRef accepted by recommend-template), slug, title, and description; content returned only to theme-capable callers                                                                                                                                                         | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/recommend-navigation`    | `edit_theme_options`                                                                                              | Connectors text-generation provider configured for useful output and trusted WordPress Developer Docs grounding                                                                                                                                                                                                                                                                                                | Advisory navigation suggestion groups, explanation, and `docsGrounding`                                                                                                                                                                                                                                                   | Navigation guidance inside the block panel                           |
| `flavor-agent/search-wordpress-docs`   | `manage_options`                                                                                                  | Uses Flavor Agent's built-in public WordPress Developer Docs AI Search endpoint; execution fails closed for missing queries, invalid or unavailable endpoint config, HTTP/search/parse errors, or untrusted source filtering; successful searches with no trusted chunks return an empty `guidance` array and an unavailable `docsGrounding` envelope                                                             | Trusted WordPress developer-doc guidance, optional entity-cache warming, `docsGrounding`, and `docsGroundingFingerprint`                                                                                                                                                                                                  | No direct first-party editor UI; admin and external-agent surface    |
| `flavor-agent/get-active-theme`        | `edit_posts`                                                                                                      | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | Active theme name, stylesheet, template, and version                                                                                                                                                                                                                                                                      | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/get-theme-presets`       | `edit_posts`                                                                                                      | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | Theme preset families from global settings: color, gradient, typography, spacing, shadow, and duotone                                                                                                                                                                                                                     | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/get-theme-styles`        | `edit_posts`                                                                                                      | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | Applied global theme styles plus extracted element and pseudo-state summaries                                                                                                                                                                                                                                             | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/get-theme-tokens`        | `edit_posts`                                                                                                      | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | Theme token snapshot: colors, typography, spacing, layout, and related feature flags                                                                                                                                                                                                                                      | No direct first-party UI; helper and external-agent surface          |
| `flavor-agent/check-status`            | `edit_posts`                                                                                                      | None beyond capability                                                                                                                                                                                                                                                                                                                                                                                        | Backend inventory, active model hint, currently available ability list, and per-surface readiness map                                                                                                                                                                                                                     | Settings diagnostics and external-agent surface                      |
| `flavor-agent/preview-recommend-block` | `edit_posts`; any positive post ID resolved from request/document context also requires `edit_post` for that post | None beyond capability (delegates to `flavor-agent/recommend-block`'s permission_callback)                                                                                                                                                                                                                                                                                                                    | Signature-only preflight: returns `resolvedContextSignature` derived from the same request the parent ability would accept. Forces `resolveSignatureOnly:true` and strips `clientRequest` server-side — no chat call, no docs warming, no activity row                                                                  | No first-party editor UI; Abilities Explorer and external MCP surface |
| `flavor-agent/preview-recommend-navigation` | `edit_theme_options`                                                                                              | None beyond capability (delegates to `flavor-agent/recommend-navigation`'s permission_callback)                                                                                                                                                                                                                                                                                                               | Signature-only preflight: returns `reviewContextSignature`. Forces `resolveSignatureOnly:true` and strips `clientRequest` server-side                                                                                                                                                                                     | No first-party editor UI; Abilities Explorer and external MCP surface |
| `flavor-agent/preview-recommend-style` | `edit_theme_options`                                                                                              | None beyond capability (delegates to `flavor-agent/recommend-style`'s permission_callback)                                                                                                                                                                                                                                                                                                                    | Signature-only preflight: returns `reviewContextSignature` and `resolvedContextSignature`. Forces `resolveSignatureOnly:true` and strips `clientRequest` server-side                                                                                                                                                      | No first-party editor UI; Abilities Explorer and external MCP surface |
| `flavor-agent/preview-recommend-template` | `edit_theme_options`                                                                                              | None beyond capability (delegates to `flavor-agent/recommend-template`'s permission_callback)                                                                                                                                                                                                                                                                                                                 | Signature-only preflight: returns `reviewContextSignature` and `resolvedContextSignature`. Forces `resolveSignatureOnly:true` and strips `clientRequest` server-side                                                                                                                                                      | No first-party editor UI; Abilities Explorer and external MCP surface |
| `flavor-agent/preview-recommend-template-part` | `edit_theme_options`                                                                                              | None beyond capability (delegates to `flavor-agent/recommend-template-part`'s permission_callback)                                                                                                                                                                                                                                                                                                            | Signature-only preflight: returns `reviewContextSignature` and `resolvedContextSignature`. Forces `resolveSignatureOnly:true` and strips `clientRequest` server-side                                                                                                                                                      | No first-party editor UI; Abilities Explorer and external MCP surface |
| `flavor-agent/request-style-apply`     | `edit_theme_options`                                                                                              | Feature-gated; exposed on the dedicated MCP server only                                                                                                                                                                                                                                                                                                                                                       | Queues a review-gated Global Styles / Style Book apply from a `recommend-style` result and re-checks freshness at request; mutates nothing until a site admin approves it in `Settings > AI Activity`                                                                                                                      | No first-party editor UI; external-agent surface                     |
| `flavor-agent/get-activity`            | Contextual (`Activity\Permissions`)                                                                              | Feature-gated; dedicated MCP server only                                                                                                                                                                                                                                                                                                                                                                      | One activity entry by id; the agent's status-polling and attribution read                                                                                                                                                                                                                                                | No first-party editor UI; external-agent surface                     |
| `flavor-agent/list-activity`           | Contextual, scoped (`Activity\Permissions`)                                                                      | Feature-gated; dedicated MCP server only                                                                                                                                                                                                                                                                                                                                                                      | Scoped activity list with surface/status filters; admin-global reads stay REST-only                                                                                                                                                                                                                                       | No first-party editor UI; external-agent surface                     |
| `flavor-agent/undo-activity`           | Contextual per row (style rows: `edit_theme_options`)                                                            | Feature-gated; dedicated MCP server only                                                                                                                                                                                                                                                                                                                                                                      | Server-side ordered undo with drift checks for executed style rows                                                                                                                                                                                                                                                       | No first-party editor UI; external-agent surface                     |

## Ability Notes

- `inc/Abilities/Registration.php` defines 30 ability contracts. The 14 helper/read abilities register during `wp_abilities_api_init` when `wp_register_ability()` is available; the `flavor-agent` category registers separately during `wp_abilities_api_categories_init` when `wp_register_ability_category()` is available. The five `preview-recommend-*` siblings register on the same always-on path but are gated on `FeatureBootstrap::canonical_contracts_available()` (Abilities API plus the WP AI plugin's `Abstract_Ability` contract) because they extend that abstract class. The seven AI recommendation abilities (`recommend-block`, `recommend-content`, `recommend-patterns`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, and `recommend-style`) and the four external-apply abilities (`request-style-apply`, `get-activity`, `list-activity`, and `undo-activity`) register only when the WordPress AI plugin feature contracts are available and the Flavor Agent AI feature is enabled in `Settings > AI`. The preview siblings are deliberately available BEFORE the feature gate is flipped so operators can use the Abilities Explorer to verify wiring without first enabling recommendations.
- On supported WordPress 7.0+ admin screens, Flavor Agent prefers `window.flavorAgentAbilities.executeAbility` from its small `@wordpress/abilities` bridge for non-abortable requests. If the bridge is unavailable or bridge execution throws `ability_not_found` / `Ability not found`, the first-party store POSTs directly to the canonical Abilities REST run route. Requests with an `AbortSignal` use REST directly so cancellation works reliably.
- The five `preview-recommend-*` siblings and ten externally-useful read helpers (`introspect-block`, `list-allowed-blocks`, `list-patterns`, `get-pattern`, `list-template-parts`, `list-templates`, `get-active-theme`, `get-theme-presets`, `get-theme-styles`, `get-theme-tokens`) declare `meta.mcp.public = true` so the universal MCP default server (when mcp-adapter is installed) surfaces them through its `discover-abilities` / `execute-ability` flow. The seven write-side recommendation abilities are deliberately **excluded** from `meta.mcp.public` — they are curated onto the dedicated server only (next bullet), with the read-only preview siblings standing in as the universal server's recommend-surface dry-run. Three helpers stay Abilities-API-only: `list-synced-patterns` and `get-synced-pattern` (can return draft `wp_block` content), and `check-status` (exposes backend-config inventory).
- When the MCP Adapter is active and the recommendation feature is enabled, Flavor Agent also registers a dedicated server at `/wp-json/mcp/flavor-agent`. The dedicated server exposes the seven recommendation abilities plus the four external-apply abilities (`request-style-apply`, `get-activity`, `list-activity`, and `undo-activity`) as first-class MCP tools — eleven in all — so external agents see them directly in `tools/list`; the preview siblings stay off the dedicated server (operator preflight is via the universal default bridge); the universal MCP default server remains available for generic ability discovery and execution.
- Meta-tool call shape (universal default server): list with `discover-abilities`, then run with `execute-ability`, whose input schema (from `mcp-adapter`'s `ExecuteAbilityAbility`) takes `ability_name` (the full ability name) and `parameters` (the ability's input object) — **both required**. For example, `execute-ability { "ability_name": "flavor-agent/get-active-theme", "parameters": {} }`; passing `name` / `input` fails schema validation. Dedicated-server tools at `/wp-json/mcp/flavor-agent` are invoked directly by tool name through `tools/call`, not wrapped in `execute-ability`.
- The dedicated server transport gate allows callers with either `edit_posts` or `edit_theme_options`, then each tool's own ability permission callback applies the exact surface policy. This avoids blocking theme-scoped tools for users who can edit site styles/templates but do not have post-editing capability.
- All 30 defined abilities declare behavior annotations. The seven AI recommendation abilities keep WP-format `meta.annotations.readonly` unset so core and `@wordpress/core-abilities` run calls stay POST for large prompt/editor payloads; they declare `destructive:false`, `idempotent:false`, and `openWorld:true` (LLM provider and docs-grounding backends are external services), and they do not claim direct MCP `readOnlyHint:true` because execution can persist request diagnostics and freshness tokens. The 13 data-read abilities declare WP-format `readonly:true`, `destructive:false`, and `idempotent:true`; `openWorld` stays unset because they read local WordPress registry, theme, and entity state. The five `preview-recommend-*` siblings declare the same `readonly:true, destructive:false, idempotent:true` matrix as the read helpers because they force `resolveSignatureOnly:true` server-side and strip `clientRequest`, so execution never persists diagnostics or freshness tokens — equal inputs always yield equal signature outputs. `flavor-agent/search-wordpress-docs` requires `manage_options` and is not advertised as read-only because direct searches can warm exact/entity caches, update Developer Docs runtime diagnostics, and persist docs-grounding Activity diagnostics; it declares `openWorld:true` because the docs-grounding backend is an external Cloudflare AI Search call. The four external-apply abilities split by mutation profile: `get-activity` and `list-activity` declare `readonly:true, destructive:false, idempotent:true` because they only read activity rows; `request-style-apply` queues a write and declares `destructive:false, idempotent:false` with `readonly` unset; `undo-activity` declares `destructive:true, idempotent:false` because it reverses an executed style apply.
- Block, pattern, template, template-part, navigation, Global Styles, and Style Book recommendations now include `docsGrounding` and `docsGroundingFingerprint`. If trusted WordPress Developer Docs grounding is unavailable, those surfaces return `flavor_agent_docs_grounding_unavailable` with HTTP `503` before calling chat or pattern reranking. Trusted stale or degraded grounding remains usable but is exposed in the response so clients can warn. `recommend-content` is intentionally exempt because it is an editorial writing lane rather than a WordPress capability/currentness recommendation.
- `flavor-agent/recommend-block` accepts the first-party editor `editorContext` payload and the external-client `selectedBlock` alias. `BlockAbilities::recommend_block()` normalizes both paths into a single prompt context.
- When `window.flavorAgentData.enableBlockStructuralActions` is true, the first-party `editorContext` also includes a client-computed `blockOperationContext` with selected-block target identity, target signature, lock/content-only state, and allowed pattern metadata from Gutenberg's allowed-pattern selector. The flag is default-on and resolves only through the `flavor_agent_enable_block_structural_actions` runtime filter; executable structural block operations stay empty when the flag, pattern context, target, lock, or catalog validation fails.
- Normalized block suggestions may include `operations`, `proposedOperations`, and `rejectedOperations`. `operations` contains only `FlavorAgent\Context\BlockOperationValidator`-approved block structural operations from the v1 catalog (`insert_pattern` and `replace_block_with_pattern`); `proposedOperations` preserves sanitized model proposals for diagnostics; `rejectedOperations` records standardized validator rejection codes and sanitized proposal payloads. In the editor, the JS catalog revalidates the PHP-approved operation and fails closed with `client_server_operation_mismatch` if the browser validation identity disagrees before review/apply.
- All four executable suggestion surfaces (block, style, template, template-part) may also include `validationReasons` — a bounded list of `{ code, severity, message? }` drawn from the versioned `validation-reasons-v1` vocabulary (canonical source `shared/validation-reasons.json`, mirrored in `inc/Support/ValidationReason.php` and `src/utils/validation-reasons.js`). The schema types `code` as a **bounded string, not an enum**, so the growing vocabulary can never fail strict client-side ajv on a new code. On block it is a pass-through projection of `rejectedOperations` codes (zero regression); on style/template/template-part each deterministic generation- or apply-time rejection maps to a specific code (`operation_validation_failed` only for genuinely unmappable failures). The primary code (highest severity, then first) rides recommendation-outcome records — the per-suggestion `shown.rankingSet[]` entry, the `validation_blocked.reason` slot, and a sibling `validationReason` on engaged (`selected_for_review`/apply) outcomes — alongside `validationVocabularyVersion`, feeding the live learning loop. `RecommendationContextScorer` reads the same structured reasons (severity `rejected`/`downgraded`) as a ranking penalty.
- `flavor-agent/check-status` now reports the runtime-gated `availableAbilities` list plus a `surfaces` map that explains per-surface ready / unavailable state for block, pattern, template, template-part, navigation, Global Styles, and Style Book UIs. Recommendation ability availability also requires the Flavor Agent AI feature gate.
- The `surfaces` map uses the keys `block`, `pattern`, `content`, `template`, `templatePart`, `navigation`, `globalStyles`, and `styleBook`. Each entry returns `available`, `reason`, `owner`, `actions`, `configurationLabel`, `configurationUrl`, `message`, and `advisoryOnly`.
- `flavor-agent/get-pattern` resolves only by registered pattern name. The returned `id` is the same string as `name`, and `patternId` is a convenience alias for that same value.
- `flavor-agent/list-patterns` supports `search`, `limit`, `offset`, and `includeContent`, returns `total`, and omits `content` by default.
- `flavor-agent/list-synced-patterns` accepts `synced`, `partial`, `unsynced`, or `all`. It queries `wp_block` posts with `post_status = any`, keeps the helper browse fallback that allows published posts when `read_post` is denied, supports `search`, `limit`, `offset`, and `includeContent`, returns `total`, and omits `content` by default.
- `flavor-agent/get-synced-pattern` uses the same helper browse fallback for published `wp_block` patterns. That fallback is not reused by recommendation ranking.
- `flavor-agent/recommend-patterns` requires `visiblePatternNames` from the current inserter root; missing or empty scope returns an empty recommendation list. Post/page/custom post document scopes from the editor should carry `document.entityId` and `document.scopeKey`, and any positive post ID resolved from `postContext`, `editorContext`, `document`, top-level `postId` / `post_id`, or a numeric scope key requires `current_user_can( 'edit_post', $id )` after the base `edit_posts` check. It indexes registered patterns plus public-safe published user `wp_block` patterns across synced, partial, and unsynced states in the selected pattern backend, then rehydrates user candidates through current published `wp_block` state and `read_post` access before ranking or response output. User candidates keep Gutenberg's `core/block/{id}` names and carry `type: user`, `source: synced`, `syncedPatternId`, and `syncStatus` metadata in recommendation payloads. The response may include `diagnostics.filteredCandidates.unreadableSyncedPatterns`, a de-duplicated aggregate count of visible-scope user candidates skipped because the current request could not read the source `wp_block`. This diagnostic is intentionally non-identifying and is safe for the inserter UI to display.
- Pattern recommendations support two retrieval backends. The default Qdrant backend embeds queries with Flavor Agent's plugin-owned Cloudflare Workers AI embedding configuration and searches Qdrant with a `name` payload filter derived from `visiblePatternNames` before both semantic and structural passes. The Cloudflare AI Search backend sends query text plus `ai_search_options.retrieval.filters.pattern_name` derived from `visiblePatternNames` to a private site-owner Cloudflare AI Search pattern instance and does not call `EmbeddingClient` or `QdrantClient`. Both backends still rerank through `ResponsesClient::rank()` / the WordPress AI Client chat runtime.
- Pattern recommendation request diagnostics include `pattern_backend`, `patternBackend`, and `embedding_provider` metadata. For Qdrant, `embedding_provider` is the full active plugin-owned Cloudflare Workers AI embedding request meta. For Cloudflare AI Search, it identifies managed private Cloudflare AI Search pattern-instance ownership.
- `flavor-agent/list-allowed-blocks` returns the whole registered block registry rather than context-aware inserter results. It now also supports `search`, `category`, `limit`, `offset`, `includeVariations`, and `maxVariations`, returns `total`, and omits `variations` by default. `introspect-block` still returns up to 10 variations; `list-allowed-blocks` truncates only when `includeVariations` is enabled.
- `flavor-agent/get-theme-styles` returns both raw `styles` and extracted summaries. `elementStyles.base`, `hover`, and `focus` are color-only objects, while `focusVisible` preserves the full `:focus-visible` object.
- Helper permissions are intentionally asymmetric: `get-active-theme`, `get-theme-presets`, `get-theme-styles`, and `get-theme-tokens` require `edit_posts`; `list-template-parts` and `list-templates` allow either editor or theme capability at the boundary but silently coerce `includeContent: true` to metadata-only unless the caller has `edit_theme_options`; the theme-oriented recommendation surfaces remain `edit_theme_options` only.
- The executable first-party editor surfaces (`block`, `template`, `template-part`, `global-styles`, and `style-book`) still compute a local request signature from the live context signature plus the composer prompt and scoped entity ref. Pattern recommendations compute an analogous insertion-target signature for direct shelf insertion. These signatures remain client-local and are not POSTed back to PHP.
- The five executable editor surfaces and the Pattern Inserter shelf store a server `resolvedContextSignature` on normal responses. PHP computes that hash from the server-normalized apply or insertion context plus the sanitized prompt, so it still captures server-only context such as theme tokens, pattern candidates, selected pattern backend state, and Style Book block-manifest details.
- Template, template-part, Global Styles, Style Book, advisory navigation, and pattern responses also store a server `reviewContextSignature`. These review hashes cover server-owned context plus the docs-grounding fingerprint so background review freshness and insertion safety can track server drift and currentness changes without embedding docs guidance text.
- `flavor-agent/recommend-block`, `flavor-agent/recommend-patterns`, `flavor-agent/recommend-template`, `flavor-agent/recommend-template-part`, `flavor-agent/recommend-navigation`, and `flavor-agent/recommend-style` accept an optional boolean `resolveSignatureOnly`. When true, they resolve only the current server freshness signature fields and docs-grounding summary from local cache state. They do not call the model, perform foreground docs searches, queue docs warming, queue Core roadmap warming, or write runtime docs diagnostics. For `recommend-patterns`, the signature-only path also skips retrieval and reranking.
- Pattern recommendations remain ranking/browse-only: the signature-only contract protects direct core inserter dispatch from stale server context, but it does not create a Flavor Agent review/apply/undo activity contract.

## REST Routes

| Route                                      | Permission                                                            | First-party caller                             | Backend owner                                                                                              | Notes                                                                                                                                   |
| ------------------------------------------ | --------------------------------------------------------------------- | ---------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /flavor-agent/v1/activity`            | Contextual editor/theme capability; `manage_options` for global reads | `loadActivitySession()` and admin activity log | `ActivityRepository::query()` for scoped reads; `ActivityRepository::query_admin()` for global admin reads | Scoped queries power editor/theme history; global admin reads return pagination, summary, and filter-option metadata for the audit page |
| `POST /flavor-agent/v1/activity`           | Contextual editor/theme capability                                    | Store-side activity persistence                | `ActivityRepository::create()`                                                                             | Persists server-backed activity entries, including executable apply rows and scoped read-only `request_diagnostic` audit rows           |
| `POST /flavor-agent/v1/activity/{id}/undo` | Contextual editor/theme capability                                    | `undoActivity()`                               | `ActivityRepository::update_undo_status()`                                                                 | Persists undo-status transitions                                                                                                        |
| `POST /flavor-agent/v1/activity/{id}/decision` | `manage_options` AND the row's mutation capability                | `src/admin/activity-log.js` approvals UI       | `FlavorAgent\Apply\PendingApplyDecision::decide()`                                                         | Approves or rejects a pending external apply; `{ decision: approve\|reject, note? }`. Approve re-checks freshness and executes server-side through `StyleApplyExecutor` |
| `POST /flavor-agent/v1/sync-patterns`      | `manage_options`                                                      | `src/admin/settings-page-controller.js`        | `PatternIndex::enqueue_sync()`                                                                              | Queues the admin-requested pattern catalog sync and returns settings-panel queue state                                                  |
| `GET /flavor-agent/v1/sync-patterns`       | `manage_options`                                                      | `src/admin/settings-page-controller.js`        | `PatternIndex::run_due_sync()` + `PatternIndex::get_runtime_state()`                                       | Runs any due scheduled pattern sync before returning current pattern sync state for settings-panel polling                              |

## Activity Route Notes

- Activity creation and admin decisions remain REST routes. Activity read/list/undo for governed external style applies are exposed as abilities via `get-activity`, `list-activity`, and `undo-activity`.
- `POST /flavor-agent/v1/activity` persists the request provenance that the UI shows later: backend/provider label, model, provider path, configuration owner, credential source, selected provider, fallback usage, route, ability, prompt, reference, token usage, and latency when the originating client includes them.
- The repository projects the admin-audit fields it needs for filtering into schema-versioned table columns (`admin_post_type`, `admin_operation_type`, `admin_provider`, `admin_provider_path`, `admin_configuration_owner`, `admin_credential_source`, `admin_selected_provider`, `admin_request_ability`, `admin_request_route`, `admin_request_reference`, `admin_request_prompt`, and related identifiers) so `Settings > AI Activity` does not need to decode every historical `request_json` payload to filter by provenance.
- `GET /flavor-agent/v1/activity` exposes the wp-admin audit feed when `global=true` or when `scopeKey` is omitted/empty; global reads still require `manage_options`. It rejects malformed active admin date filters with `400` instead of broadening the query; the wp-admin UI also blocks incomplete or inverted persisted date filters until the filter is completed or reset. Its `filterOptions.operationType` values include effective action groups such as `insert` and `replace` plus read-only diagnostics such as `request-diagnostic`. It is still intentionally a first audit slice rather than a full observability console: the response includes timeline entries, summary counts, pagination, filter options, and before/after state payloads that the admin UI renders as structured diff summaries, but not a rich visual diff viewer or broader operator workflows.
- Pending external-apply rows carry `entry.apply` (`status`, `requestedBy`, `expiresAt`, `operations`, freshness `signatures`, and decision provenance such as `decidedBy`/`decidedAt`/`decisionNote`), hydrated from the stored `request.apply` payload. The `execution_result` column mirrors the lifecycle for SQL filtering — `pending`/`rejected`/`expired`/`failed` before execution, becoming `applied` on approved execution. Non-executed rows (pending, rejected, expired, approval-failed) never participate in ordered undo and never block undo of older executed rows.

## Example Contracts

### Check-Status Response Shape

```json
{
  "configured": true,
  "model": "gpt-5.4-mini",
  "availableAbilities": [
    "flavor-agent/introspect-block",
    "flavor-agent/get-theme-styles",
    "flavor-agent/check-status"
  ],
  "surfaces": {
    "block": {
      "available": true,
      "reason": "ready",
      "owner": "connectors",
      "actions": [],
      "configurationLabel": "",
      "configurationUrl": "",
      "message": "Configure a text-generation provider in Settings > Connectors to enable block recommendations.",
      "advisoryOnly": false
    },
    "navigation": {
      "available": false,
      "reason": "missing_theme_capability",
      "owner": "connectors",
      "actions": [],
      "configurationLabel": "",
      "configurationUrl": "",
      "message": "Navigation recommendations require the edit_theme_options capability.",
      "advisoryOnly": true
    }
  },
  "backends": {
    "wordpress_ai_client": {
      "configured": true
    },
    "cloudflare_workers_ai": {
      "configured": true,
      "embeddingModel": "@cf/qwen/qwen3-embedding-0.6b"
    }
  }
}
```

`configured` currently means the Flavor Agent recommendation feature is enabled and either chat recommendations are ready or the selected pattern recommendation pipeline is ready. Docs-search readiness is reported separately in `backends.cloudflare_ai_search` and can be true even while `configured` is false; `backends.cloudflare_ai_search` describes the built-in public WordPress docs AI Search endpoint, while private pattern AI Search readiness is represented by the selected pattern index/backend readiness.

### Pattern Recommendation Backend Matrix

| Pattern backend      | Embeddings                        | Vector/index service | Search service       | Visible scope filter                                         | Required settings                                                                                                                                                         |
| -------------------- | --------------------------------- | -------------------- | -------------------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Qdrant               | Cloudflare Workers AI             | Qdrant               | Qdrant               | Qdrant payload filter on `name`                              | Cloudflare Workers AI embeddings, Qdrant, Connectors chat                                                                                                                 |
| Cloudflare AI Search | AI Search managed embedding model | Cloudflare AI Search | Cloudflare AI Search | AI Search `ai_search_options.retrieval.filters.pattern_name` | Cloudflare Workers AI account/token plus normalized-AI-Search-model signature validation, validated managed `flavor-agent-patterns-{site_hash}` instance, Connectors chat |

### Pattern Recommendation Response Shape

```json
{
  "recommendations": [
    {
      "name": "core/hero-with-cover",
      "title": "Hero with cover image",
      "description": "A full-width hero section with a heading and call to action.",
      "score": 0.91,
      "source": "registered"
    }
  ],
  "docsGrounding": {
    "status": "available"
  },
  "docsGroundingFingerprint": "sha256-of-docs-grounding",
  "reviewContextSignature": "sha256-of-pattern-review-context-and-prompt",
  "resolvedContextSignature": "sha256-of-pattern-insertion-context-and-prompt",
  "diagnostics": {
    "filteredCandidates": {
      "unreadableSyncedPatterns": 0
    },
    "pipelineTrace": {
      "backendRetrieved": 5,
      "visibleScopeDropped": 0,
      "rehydrationDropped": 0,
      "candidatePool": 4,
      "diversityDropped": 0,
      "llmReturned": 3,
      "llmNameMismatchDropped": 0,
      "llmMalformedDropped": 0,
      "belowThresholdDropped": 1,
      "duplicateRowsCollapsed": 0,
      "returnedRecommendations": 2
    },
    "dropReasons": {
      "below_threshold": 1
    }
  }
}
```

Normal responses include ranked recommendations, aggregate-only pipeline diagnostics, and review/apply signatures. The final `score` and `ranking.blendedScore` are computed from the model score, deterministic backend score, and contextual scorer before thresholding. Signature-only responses return the same top-level signature and docs-grounding fields with an empty recommendation list and zeroed diagnostics.

### List-Allowed-Blocks Response Shape

```json
{
  "total": 237,
  "blocks": [
    {
      "name": "core/group",
      "title": "Group",
      "category": "design",
      "description": "Gather blocks in a container.",
      "supports": {
        "spacing": {
          "padding": true
        }
      },
      "inspectorPanels": {
        "dimensions": ["minHeight"],
        "general": ["tagName"]
      },
      "bindableAttributes": [],
      "contentAttributes": {},
      "configAttributes": {
        "tagName": {
          "type": "string",
          "default": "div",
          "role": "config"
        }
      },
      "styles": [
        {
          "name": "default",
          "label": "Default",
          "isDefault": true
        }
      ],
      "variations": [],
      "supportsContentRole": false,
      "parent": null,
      "allowedBlocks": ["core/heading", "core/paragraph"],
      "apiVersion": 3
    }
  ]
}
```

This response is the full registered block registry sorted by title and then name. It is not filtered by the current inserter context. `total` reports the unpaginated match count. `variations` are omitted by default and truncate to the first `maxVariations` entries only when `includeVariations: true`.

### List-Synced-Patterns Response Shape

```json
{
  "total": 2,
  "patterns": [
    {
      "id": 42,
      "title": "Announcement Bar",
      "slug": "announcement-bar",
      "status": "draft",
      "authorId": 1,
      "dateGmt": "2026-04-23 13:40:18",
      "modifiedGmt": "2026-04-23 13:52:09",
      "syncStatus": "partial",
      "wpPatternSyncStatus": "partial"
    }
  ]
}
```

The request-side `syncStatus` filter accepts `synced`, `partial`, `unsynced`, or `all`. The list response omits `content` by default, returns `total`, and the underlying query uses `post_status = any`.

### Theme Styles Response Shape

```json
{
  "styles": {
    "elements": {
      "link": {
        "color": {
          "text": "var(--wp--preset--color--contrast)"
        },
        ":hover": {
          "color": {
            "text": "var(--wp--preset--color--accent)"
          }
        },
        ":focus-visible": {
          "color": {
            "text": "var(--wp--preset--color--accent)"
          },
          "outline": {
            "color": "var(--wp--preset--color--accent)",
            "style": "solid",
            "width": "2px"
          }
        }
      }
    }
  },
  "elementStyles": {
    "link": {
      "base": {
        "text": "var(--wp--preset--color--contrast)"
      },
      "hover": {
        "text": "var(--wp--preset--color--accent)"
      },
      "focus": {},
      "focusVisible": {
        "color": {
          "text": "var(--wp--preset--color--accent)"
        },
        "outline": {
          "color": "var(--wp--preset--color--accent)",
          "style": "solid",
          "width": "2px"
        }
      }
    }
  },
  "blockPseudoStyles": {
    "core/button": {
      ":hover": {
        "color": {
          "background": "var(--wp--preset--color--accent)"
        }
      }
    }
  },
  "diagnostics": {
    "source": "server",
    "settingsKey": "wp_get_global_settings",
    "reason": "server-global-settings"
  }
}
```

`elementStyles.base`, `hover`, and `focus` expose only color maps. `focusVisible` preserves the full `:focus-visible` pseudo-state object.

### Block Recommendation Ability Input

```json
{
  "editorContext": {
    "block": {
      "name": "core/group",
      "currentAttributes": {
        "layout": {
          "type": "constrained"
        }
      },
      "editingMode": "default",
      "isInsideContentOnly": false
    },
    "siblingsBefore": ["core/heading"],
    "siblingsAfter": ["core/paragraph"],
    "themeTokens": {
      "colors": ["contrast"],
      "spacing": ["40", "50", "60"]
    }
  },
  "prompt": "Give this section more breathing room.",
  "clientId": "block-client-id"
}
```

### Block Recommendation Structural Operation Shape

```json
{
  "block": [
    {
      "label": "Add a hero after this block",
      "description": "Use the registered hero pattern directly after the selected section.",
      "type": "pattern_replacement",
      "attributeUpdates": {},
      "operations": [
        {
          "catalogVersion": 1,
          "type": "insert_pattern",
          "patternName": "theme/hero",
          "targetClientId": "block-client-id",
          "targetType": "block",
          "targetSignature": "sha256-of-selected-target",
          "position": "insert_after",
          "expectedTarget": {
            "clientId": "block-client-id",
            "name": "core/group"
          }
        }
      ],
      "proposedOperations": [
        {
          "type": "insert_pattern",
          "patternName": "theme/hero",
          "targetClientId": "block-client-id",
          "position": "insert_after"
        }
      ],
      "rejectedOperations": [],
      "validationReasons": []
    }
  ],
  "explanation": "The selected section can safely be followed by the allowed hero pattern.",
  "resolvedContextSignature": "sha256-of-surface-apply-context-and-prompt"
}
```

The normalized block operation metadata is `catalogVersion`, `type`, `patternName`, `targetClientId`, `targetType`, `targetSignature`, `position` for `insert_pattern`, `action: "replace"` for `replace_block_with_pattern`, and `expectedTarget` when the server can record the selected target fingerprint. Rejection entries use the same operation shape under `operation` plus one of the catalog rejection codes: `block_structural_actions_disabled`, `multi_operation_unsupported`, `invalid_operation_payload`, `unknown_operation_type`, `missing_pattern_name`, `pattern_not_available`, `missing_target_client_id`, `stale_target`, `cross_surface_target`, `invalid_target_type`, `locked_target`, `content_only_target`, `invalid_insertion_position`, `action_not_allowed`, or the client-side fail-closed code `client_server_operation_mismatch`.

### Template Ability Request

```json
{
  "templateRef": "theme//single-post",
  "templateType": "single",
  "prompt": "Introduce a supporting sidebar.",
  "visiblePatternNames": ["core/post-meta-two-column"],
  "editorSlots": {
    "assignedParts": [
      {
        "slug": "header",
        "area": "header"
      }
    ],
    "emptyAreas": ["sidebar"],
    "allowedAreas": ["header", "footer", "sidebar"]
  },
  "editorStructure": {
    "topLevelBlockTree": [
      {
        "path": [0],
        "name": "core/template-part",
        "label": "Header",
        "attributes": { "slug": "header", "area": "header" },
        "childCount": 3,
        "slot": { "slug": "header", "area": "header", "isEmpty": false }
      },
      {
        "path": [1],
        "name": "core/group",
        "label": "Content",
        "attributes": {},
        "childCount": 2
      }
    ],
    "structureStats": {
      "blockCount": 4,
      "maxDepth": 2,
      "topLevelBlockCount": 2,
      "hasNavigation": false,
      "hasQuery": true,
      "hasTemplateParts": true,
      "firstTopLevelBlock": "core/template-part",
      "lastTopLevelBlock": "core/group"
    },
    "currentPatternOverrides": {
      "hasOverrides": false,
      "blockCount": 0,
      "blockNames": [],
      "blocks": []
    },
    "currentViewportVisibility": {
      "hasVisibilityRules": false,
      "blockCount": 0,
      "blocks": []
    }
  }
}
```

The client sends live slot data including `assignedParts`, `emptyAreas`, and `allowedAreas`. The server keeps canonical saved capability metadata and computes the effective `allowedAreas` set by merging that live snapshot with the saved template contract. Empty templates still send `editorSlots` and `editorStructure` with empty arrays and zeroed stats.

### Template-Part Ability Request

```json
{
  "templatePartRef": "theme//header",
  "prompt": "Create a stronger utility row above the main navigation.",
  "visiblePatternNames": ["core/header-with-utility-row"],
  "editorStructure": {
    "blockTree": [
      {
        "path": [0],
        "name": "core/group",
        "label": "Group",
        "attributes": { "tagName": "header" },
        "childCount": 2,
        "children": [
          {
            "path": [0, 0],
            "name": "core/site-logo",
            "label": "Site Logo",
            "attributes": {},
            "childCount": 0,
            "children": []
          },
          {
            "path": [0, 1],
            "name": "core/navigation",
            "label": "Navigation",
            "attributes": { "overlayMenu": "mobile" },
            "childCount": 0,
            "children": []
          }
        ]
      }
    ],
    "allBlockPaths": [
      {
        "path": [0],
        "name": "core/group",
        "label": "Group",
        "attributes": { "tagName": "header" },
        "childCount": 2
      },
      {
        "path": [0, 1],
        "name": "core/navigation",
        "label": "Navigation",
        "attributes": { "overlayMenu": "mobile" },
        "childCount": 0
      }
    ],
    "topLevelBlocks": ["core/group"],
    "blockCounts": {
      "core/group": 1,
      "core/navigation": 1
    },
    "structureStats": {
      "blockCount": 2,
      "maxDepth": 2,
      "hasNavigation": true,
      "containsLogo": false,
      "containsSiteTitle": false,
      "containsSearch": false,
      "containsSocialLinks": false,
      "containsQuery": false,
      "containsColumns": false,
      "containsButtons": false,
      "containsSpacer": false,
      "containsSeparator": false,
      "firstTopLevelBlock": "core/group",
      "lastTopLevelBlock": "core/group",
      "hasSingleWrapperGroup": true,
      "isNearlyEmpty": false
    },
    "currentPatternOverrides": {
      "hasOverrides": false,
      "blockCount": 0,
      "blockNames": [],
      "blocks": []
    },
    "operationTargets": [
      {
        "path": [0, 1],
        "name": "core/navigation",
        "label": "Navigation",
        "allowedOperations": ["replace_block_with_pattern", "remove_block"],
        "allowedInsertions": ["before_block_path", "after_block_path"]
      }
    ],
    "insertionAnchors": [
      { "placement": "start", "label": "Start of template part" },
      { "placement": "end", "label": "End of template part" },
      {
        "placement": "before_block_path",
        "targetPath": [0, 1],
        "blockName": "core/navigation",
        "label": "Before Navigation"
      }
    ],
    "structuralConstraints": {
      "contentOnlyPaths": [],
      "lockedPaths": [],
      "hasContentOnly": false,
      "hasLockedBlocks": false
    }
  }
}
```

`editorStructure.blockTree` is the prompt-facing summary. `editorStructure.allBlockPaths` is the full live path index the server uses to validate deep executable paths and stale signatures. Empty template parts still send the same keys with empty trees, zeroed stats, no operation targets, and start/end anchors.

### Template Ability Response Shape

```json
{
  "suggestions": [
    {
      "label": "Lead with the hero before the header",
      "description": "Use the validated top-level anchor so the intro lands ahead of the current header slot.",
      "operations": [
        {
          "type": "insert_pattern",
          "patternName": "core/post-meta-two-column",
          "placement": "before_block_path",
          "targetPath": [1],
          "expectedTarget": {
            "name": "core/group",
            "label": "Content",
            "attributes": {},
            "childCount": 2
          }
        }
      ],
      "templateParts": [],
      "patternSuggestions": ["core/post-meta-two-column"]
    }
  ],
  "explanation": "The top-level template structure exposes a safe anchor before the existing header block.",
  "reviewContextSignature": "sha256-of-surface-review-context-and-prompt",
  "resolvedContextSignature": "sha256-of-surface-apply-context-and-prompt"
}
```

### Style Ability Request

```json
{
  "scope": {
    "surface": "global-styles",
    "scopeKey": "global_styles:17",
    "globalStylesId": "17",
    "postType": "global_styles",
    "entityId": "17",
    "entityKind": "root",
    "entityName": "globalStyles"
  },
  "styleContext": {
    "currentConfig": {
      "settings": {},
      "styles": {}
    },
    "mergedConfig": {
      "settings": {},
      "styles": {}
    },
    "availableVariations": [
      {
        "title": "Midnight"
      }
    ],
    "themeTokenDiagnostics": {
      "source": "server",
      "settingsKey": "wp_get_global_settings",
      "reason": "server-global-settings"
    },
    "templateStructure": [
      {
        "name": "core/template-part",
        "innerBlocks": [{ "name": "core/site-title" }]
      },
      {
        "name": "core/group",
        "innerBlocks": [{ "name": "core/query-title" }]
      }
    ],
    "templateVisibility": {
      "hasVisibilityRules": false,
      "blockCount": 0,
      "blocks": []
    }
  },
  "prompt": "Make the site feel more editorial."
}
```

### Style Book Ability Request

```json
{
  "scope": {
    "surface": "style-book",
    "scopeKey": "style_book:17:core/group",
    "globalStylesId": "17",
    "entityKind": "block",
    "entityName": "styleBook",
    "entityId": "core/group",
    "blockName": "core/group",
    "blockTitle": "Group"
  },
  "styleContext": {
    "currentConfig": {
      "settings": {},
      "styles": {}
    },
    "mergedConfig": {
      "settings": {},
      "styles": {}
    },
    "styleBookTarget": {
      "blockName": "core/group",
      "blockTitle": "Group",
      "currentStyles": {},
      "mergedStyles": {}
    },
    "templateStructure": [
      {
        "name": "core/template-part",
        "innerBlocks": [{ "name": "core/heading" }]
      },
      {
        "name": "core/group",
        "innerBlocks": [{ "name": "core/paragraph" }]
      }
    ],
    "templateVisibility": {
      "hasVisibilityRules": false,
      "blockCount": 0,
      "blocks": []
    }
  },
  "prompt": "Make this block feel more editorial."
}
```

### Template-Part Ability Response Shape

```json
{
  "suggestions": [
    {
      "label": "Add a utility row before navigation",
      "description": "Strengthen secondary navigation without crowding the main row.",
      "blockHints": [
        {
          "path": [0, 1],
          "label": "Navigation block",
          "blockName": "core/navigation",
          "reason": "This is the existing structural anchor."
        }
      ],
      "patternSuggestions": ["core/header-with-utility-row"],
      "operations": [
        {
          "type": "insert_pattern",
          "patternName": "core/header-with-utility-row",
          "placement": "before_block_path",
          "targetPath": [0, 1]
        }
      ]
    }
  ],
  "explanation": "The current header supports a bounded insertion before the existing navigation block.",
  "reviewContextSignature": "sha256-of-surface-review-context-and-prompt",
  "resolvedContextSignature": "sha256-of-surface-apply-context-and-prompt"
}
```

### Navigation Response Shape

```json
{
  "suggestions": [
    {
      "label": "Flatten low-value nesting",
      "description": "Reducing one submenu level makes key destinations easier to scan.",
      "category": "structure",
      "changes": [
        {
          "type": "flatten",
          "targetPath": [2],
          "target": "Resources submenu",
          "detail": "Promote the two most-used links to the top level."
        }
      ]
    }
  ],
  "explanation": "The current menu depth is heavier than the observed information hierarchy needs.",
  "reviewContextSignature": "sha256-of-surface-review-context-and-prompt"
}
```

### Pattern Sync Response Shape

```json
{
  "queued": true,
  "scheduled": true,
  "scheduledAt": "2026-05-09T12:00:05+00:00",
  "status": "indexing",
  "runtimeState": {
    "status": "indexing",
    "pattern_backend": "qdrant"
  },
  "requestMeta": {
    "route": "POST /flavor-agent/v1/sync-patterns"
  }
}
```

The POST response shape is backend-neutral because it only reports queue status. The GET response returns the live `runtimeState` plus `requestMeta.route` for settings polling. The persisted `flavor_agent_pattern_index_state` records `pattern_backend`, Qdrant state fields for Qdrant, and `cloudflare_ai_search_namespace`, `cloudflare_ai_search_instance`, plus `cloudflare_ai_search_signature` for Cloudflare AI Search. Managed pattern-storage instances use Cloudflare's `default` namespace.

### Activity Entry Shape

```json
{
  "surface": "template-part",
  "suggestion": "Add a utility row before navigation",
  "request": {
    "prompt": "Create a stronger utility row above the main navigation.",
    "reference": "template-part:theme//header:3"
  },
  "document": {
    "scopeKey": "wp_template_part:theme//header",
    "postType": "wp_template_part",
    "entityId": "theme//header"
  },
  "undo": {
    "status": "available"
  }
}
```

## Sequence Cheatsheet

```text
Block UI -> store -> flavor-agent/recommend-block ability -> BlockAbilities -> ChatClient -> Prompt -> UI
Pattern UI -> store -> flavor-agent/recommend-patterns ability -> PatternAbilities -> selected retrieval backend + Responses -> local inserter shelf
Navigation UI -> store -> flavor-agent/recommend-navigation ability -> NavigationAbilities -> NavigationPrompt -> advisory UI
Template UI -> store -> flavor-agent/recommend-template ability -> TemplateAbilities -> TemplatePrompt -> preview/apply/undo
Template-part UI -> store -> flavor-agent/recommend-template-part ability -> TemplateAbilities -> TemplatePartPrompt -> preview/apply/undo
Global Styles / Style Book UI -> store -> flavor-agent/recommend-style ability -> StyleAbilities -> StylePrompt -> preview/apply/undo
Apply flow -> activity create -> inline activity UI -> undo -> activity/{id}/undo
```

## Recommendation Ability Notes

- Recommendation abilities sanitize and normalize structured inputs before handing them to the surface backend
- Normal `recommend-block` responses include `resolvedContextSignature`. Pattern, template, template-part, and style responses include `reviewContextSignature`, `resolvedContextSignature`, and `docsGroundingFingerprint`, and navigation includes `reviewContextSignature` plus `docsGroundingFingerprint` as its server freshness fields.
- Signature-only requests return only the current freshness field(s) and docs-grounding status after normalizing the current server context and prompt; they compute docs grounding from local cache state and skip model calls, foreground docs searches, async docs warming, Core roadmap warming, and runtime docs diagnostics.
- `flavor-agent/recommend-patterns` also skips pattern retrieval and reranking in signature-only mode.
- `flavor-agent/recommend-patterns` reports aggregate-only `diagnostics.pipelineTrace` and allow-listed `diagnostics.dropReasons` for retrieval, visible-scope, rehydration, ranker-shape, and blended-threshold drops. These diagnostics intentionally avoid raw pattern labels or payload content.
- `flavor-agent/recommend-patterns` can return synced/user pattern recommendations by their `core/block/{id}` names when those names are present in the current `visiblePatternNames` set.
- `flavor-agent/recommend-patterns` does not accept `editorStructure`; the current pattern ability contract ignores it
- Template recommendation requests carry an editor-collected `editorStructure` with the live top-level block tree, zeroed empty-state stats when needed, current pattern-override summaries, and current viewport-visibility summaries; the server replaces that mutable slice atomically and derives insertion anchors from the live tree
- Template recommendation requests also carry live `editorSlots.assignedParts`, `editorSlots.emptyAreas`, and `editorSlots.allowedAreas`; the server keeps canonical saved capability metadata and computes effective `allowedAreas` by merging those live areas with the saved template contract
- Template-part requests accept a full live `editorStructure` slice: `blockTree`, `allBlockPaths`, `topLevelBlocks`, `blockCounts`, `structureStats`, `currentPatternOverrides`, `operationTargets`, `insertionAnchors`, and `structuralConstraints`
- Template-part executable paths are validated against `editorStructure.allBlockPaths`, so deep unsaved paths remain valid even when the prompt-facing `blockTree` is depth-limited
- Global Styles and Style Book requests carry live `styleContext.templateStructure` and `styleContext.templateVisibility` snapshots from the current editor canvas so style docs grounding and prompt shaping stay aligned with the template the user is actually looking at
- Docs grounding stays surface-specific: template requests scope guidance with live slot occupancy, top-level structure, visible patterns, pattern overrides, and viewport visibility; template-part requests scope guidance with operation targets, insertion anchors, structural constraints, and live block paths; style requests scope guidance with supported style paths, available variations or the active Style Book target, design semantics, and the live template structure/visibility snapshot
- Navigation response groups now validate structural `changes[].targetPath` values against the current menu target inventory instead of trusting free-form target text alone
- Activity permissions are contextual: post-like scopes use `edit_posts` or `edit_post`, while template, template-part, navigation, Global Styles, and Style Book scopes require `edit_theme_options`
- Manual sync is intentionally admin-only because it queues mutation of shared vector-index state

## Primary Source Files

- `inc/Abilities/Registration.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/Abilities/WordPressDocsAbilities.php`
- `inc/Abilities/InfraAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `inc/REST/Agent_Controller.php`
- `src/store/index.js`

## Contract Verification Checklist

When updating any ability or REST contract, keep these sources aligned:

1. Ability registrations and schemas in `inc/Abilities/Registration.php`
2. REST route registrations, permission callbacks, and sanitization in `inc/REST/Agent_Controller.php`
3. First-party callers and payload shaping in `src/store/index.js` and the relevant surface module
