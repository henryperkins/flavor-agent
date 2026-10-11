# Settings, Backends, And Sync

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Settings page: `Settings > Flavor Agent`
- Durable setup guidance: native WordPress `Help` tabs/sidebar on the Flavor Agent settings screen
- Admin audit page: `Settings > AI Activity` is documented separately in `docs/features/activity-and-audit.md`
- Sync surface: `Sync Pattern Catalog` panel on the main Flavor Agent settings page

## Surfacing Conditions

- The page requires `manage_options`
- Settings sections always render for admins, but save-time validation only runs when relevant credentials changed and the required values are present

## What The User Can Configure

- AI Model status: text generation is configured in `Settings > Connectors`; this screen links to that shared setup and reports the active WordPress AI Client runtime.
- Embedding Model: Cloudflare account/token and effective model used by setup and the Workers AI settings-validation probe.
- Pattern Storage: private Cloudflare AI Search managed pattern index, the sole supported pattern store. This is infrastructure, not another AI model picker.
- Cloudflare AI Search Pattern Storage managed provisioning and validation status.
- Cloudflare AI Search pattern recommendation ranking threshold and max results.
- Developer Docs source status and max result count. Developer docs use Flavor Agent's built-in public endpoint and do not expose Cloudflare credential fields.
- Guidelines: the screen identifies the effective source and previews active shared guidance. Shared guidance is edited in its registered WordPress/Gutenberg screen when that destination is available to the administrator. The separate legacy fallback editor, including JSON import/export, reads and saves only Flavor Agent options. Its copy explains whether those options are effective or are an inactive fallback.
- Experimental Features: AI Activity Dual Logging controls (on by default). When on, Flavor Agent keeps `request_diagnostic` rows alongside core `Tools > AI Request Logs`. Turning it off restores suppress-and-defer, which only takes effect while core AI Request Logging is enabled: with the core experiment off, `should_persist_request_diagnostic()` returns `true` and Flavor Agent keeps writing `request_diagnostic` rows regardless of this setting — the status panel reports exactly that. Apply/undo rows are recorded either way. Exact coexistence contract: `docs/features/activity-and-audit.md#request-logging-coexistence`. Block structural actions graduated to unconditionally-on on 2026-06-03 and are no longer listed here; opt-out is now the `flavor_agent_enable_block_structural_actions` filter.
- Manual pattern sync through the `Sync Pattern Catalog` button.

Chat is no longer configured with plugin-owned chat credentials on this screen. Provider ownership, credential precedence, reasoning-effort routing, and exact backend requirements are canonical in `docs/reference/provider-precedence.md`.

## Backend Gating Rules

Readiness is surfaced to admins in this screen, but the exact gates are canonical elsewhere:

- surface availability and ability gates: `docs/reference/abilities-and-routes.md#registered-abilities`
- provider and pattern-storage readiness: `docs/reference/provider-precedence.md`
- docs-grounding source and currentness policy: `docs/reference/developer-docs-public-corpus-runbook.md`

External Developer Docs grounding is temporarily disabled by default in v0.1.3 through the default-false `flavor_agent_docs_grounding_enabled` release policy. Settings shows a neutral Temporarily disabled status, hides the inactive result-limit control, and preserves its saved value and historical backend observations. Recommendations and signature checks bypass cached and live docs, while direct lookup/validation reject before transport. Explicitly enabled development retains one cache-first best-effort search and reason-specific diagnostics; there are no docs warm/prewarm crons. Corpus trust and currency remain owned by `scripts/update-docs-ai-search.js`, and full desired-set settlement followed by unchanged public/managed/MCP freshness is required before restoring release grounding.

## Save And Validation Flow

1. The user changes settings on `Settings > Flavor Agent`
2. WordPress Settings API saves the options registered by `FlavorAgent\Settings::register_settings()`
3. Flavor Agent validates changed backend settings only when enough data is present to run the relevant validation path. Provider-specific precedence and validation requirements live in `docs/reference/provider-precedence.md`.
4. If validation fails, the plugin keeps the previous values and surfaces the error through normal Settings API notices
5. The Cloudflare Workers AI embeddings section reports the account/token/effective-model values used by setup and settings validation. A `Configured` label means saved values are present; it does not establish current connection health. Changed complete configurations still use the existing save-time validation.
6. Runtime status messages call out which WordPress AI Client path is currently serving chat and whether the Cloudflare account/token/model settings are configured.
7. Pattern setup messages describe storage readiness separately from model readiness so Pattern Storage does not look like another AI model picker. Cloudflare AI Search Pattern Storage states are: needs Cloudflare credentials from the Embedding Model section, create managed pattern index, ready, and failed/incompatible.
8. Durable setup guidance, troubleshooting, and format notes live in the native WordPress `Help` dropdown so inline page copy can stay focused on active controls and runtime state. The dashboard also provides a permission-aware handoff to the post editor, with the enabled Flavor Agent feature, supported editor runtime, text-generation prerequisite, and consequences of opening that destination described before navigation. A disabled feature links to its registered AI settings screen instead of promising an unavailable editor panel.

## Pattern Sync Flow

1. The admin clicks the `Sync Pattern Catalog` button
2. `src/admin/settings-page-controller.js` posts to `POST /flavor-agent/v1/sync-patterns`
3. `FlavorAgent\REST\Agent_Controller::handle_sync_patterns()` calls `FlavorAgent\Patterns\PatternIndex::enqueue_sync()` to schedule the catalog event and return immediately
4. `FlavorAgent\Patterns\PatternIndex::sync()` owns the Cloudflare AI Search catalog rebuild through the `flavor_agent_reindex_patterns` WP-Cron hook
5. The settings-page controller updates the sync badge, summary, metrics, and inline notice from the returned queue state, then polls `GET /flavor-agent/v1/sync-patterns` while the runtime state remains `indexing`. Opening or reloading the page during an existing sync also resumes polling and re-enables the button when the sync finishes
6. Polling calls opportunistically run due `flavor_agent_reindex_patterns` events before returning state, so a local environment with disabled or delayed WP-Cron cannot strand a manual sync in `indexing`
7. A lost or unusable response changes all client status surfaces to `Status unavailable`, retaining a qualified last-confirmed state rather than declaring the server job failed. `Check sync status` performs a status request; another sync cannot start until a valid response establishes the current state. A definite enqueue rejection reports that the sync did not start and preserves the server's recovery message.
8. Sync uses saved configuration. Unsaved changes to relevant storage or embedding fields block starting a job until the administrator saves or restores them; checking an uncertain existing job remains available.

Managed-index sync behavior and debugging checks are canonical in `docs/reference/pattern-recommendation-debugging.md`; external-service disclosure details live in `docs/reference/external-service-disclosure.md`.

## Existing Pattern Storage Migration

Cloudflare AI Search is the sole supported pattern store. Existing Qdrant settings and vectors cannot make this path ready. Migration updates local compatibility state only; it does not provision a remote index, copy vectors or pattern data, or start inference. Configure the shared Cloudflare account/token, explicitly save settings to provision or validate the managed index, and then run a new pattern sync. The legacy cosine threshold is not reused; `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search` remains the ranking setting. Old URL/key/threshold options can remain in the database until uninstall cleanup and are neither registered nor used by the supported runtime. See `docs/reference/pattern-storage-migration.md`.

## Guidelines Bridge Flow

1. Recommendation abilities declare the guideline categories they need through their `GUIDELINE_CATEGORIES` constants.
2. `FlavorAgent\AI\Abilities\RecommendationAbility::execute_callback()` passes those categories, plus any scoped block name, into `RecommendationAbilityExecution`.
3. `RecommendationAbilityExecution` calls `Guidelines::format_prompt_context()` and temporarily prepends the formatted guidance to the recommendation system instruction through the `flavor_agent_recommendation_system_instruction` filter.
4. `Guidelines` resolves the active repository through the `flavor_agent_guidelines_repository` filter, then core/Gutenberg Guidelines storage, then legacy Flavor Agent options.
5. Current shared storage is detected through `wp_knowledge`. Only published `guideline-{scope}` and `guideline-block-{block_name}` rows are read from `post_content`; when `wp_knowledge_type` is registered, rows must carry its `guideline` term. The block namespace separator is encoded as `_`, preserving distinct hyphenated block names. This matches [WordPress AI 1.4.0's Guidelines service](https://github.com/WordPress/ai/blob/1.4.0/includes/Services/Guidelines.php). The older `wp_guideline` / `wp_guideline_type` singleton-meta model and `wp_content_guideline` experiment remain read-only compatibility paths when the current post type is unavailable.
6. Legacy options are preserved even when core storage is available. The current migration status is tracked separately so a future write migration can avoid repeated imports.
7. The settings screen keeps the legacy fields, block guideline editor, and JSON import/export in an explicitly labeled fallback disclosure when another source owns the effective guidance. These controls consistently load and save legacy options; they do not write or export the active shared set. The save summary identifies fallback changes and explains when active guidance is unchanged.
8. Settings status, the active preview, recommendation block notes, and `guidelineVersion` attribution use the resolved repository. If the registered shared store has no usable guidance, existing Flavor Agent options remain the fallback. The admin fallback editor and its export use legacy options consistently.

## Primary Functions And Handlers

| Layer                 | Function / class                                                         | Role                                                                                                       |
| --------------------- | ------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| Settings page         | `Settings::add_menu()` / `Settings::render_page()`                       | Registers and renders `Settings > Flavor Agent`                                                            |
| Settings registration | `Settings::register_settings()`                                          | Registers options, sections, and fields                                                                    |
| Provider diagnostics  | `Provider::embedding_configuration()` / `InfraAbilities::check_status()` | Explain Cloudflare configuration metadata and managed pattern-storage availability                                 |
| Backend status        | `InfraAbilities::check_status()`                                         | Returns backend inventory and currently available abilities                                                |
| Theme tokens          | `InfraAbilities::get_theme_tokens()`                                     | Exposes the current theme token snapshot through an ability                                                |
| Docs grounding        | `WordPressDocsAbilities::search_wordpress_docs()`                        | Exposes trusted developer-doc grounding through an ability                                                 |
| Guidelines bridge     | `RecommendationAbilityExecution` + `Guidelines::format_prompt_context()` | Reads core Guidelines first when available, falls back to legacy options, and prepends formatted guidance to recommendation system instructions |
| Manual sync UI        | `src/admin/settings-page.js` + `src/admin/settings-page-controller.js`   | Owns settings-page sync enqueueing, polling, live status updates, and section-open persistence             |
| Pattern sync backend  | `PatternIndex::sync()`                                                   | Rebuilds the managed Cloudflare AI Search pattern catalog                                                              |

## Guardrails And Failure Modes

- Recommendation surfaces require the WordPress AI Client / Connectors chat runtime; there is no plugin-managed chat fallback.
- Pattern recommendations fail closed when Cloudflare AI Search pattern storage is not configured or Connectors text generation is unavailable.
- The Cloudflare model field participates in setup and validation; direct Workers AI embedding calls send only the settings-validation probe. The sole pattern store, Cloudflare AI Search, uses managed embeddings and a separately normalized supported model.
- Private pattern AI Search and public WordPress developer-docs AI Search are separate services with separate readiness/disclosure rules.
- Cloudflare Workers AI embeddings are the only first-party admin embedding setup path
- Guidelines are read shared-store-first when `wp_knowledge` is available, with older Guidelines models retained for compatibility; Flavor Agent does not require or assume a future `wp_register_guideline()` API
- Legacy guideline options are not deleted during the bridge phase
- Developer Docs grounding only accepts trusted official WordPress sources: stable handbook/reference pages from `developer.wordpress.org`, Developer Blog posts, and Make/Core release-cycle posts with freshness metadata.
- Sync is admin-only and does not bypass pattern-index validation or locking rules

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/sync-patterns` queues an admin-requested sync; `GET /flavor-agent/v1/sync-patterns` returns the current runtime state for polling and runs a due scheduled sync first when needed
- Ability: `flavor-agent/check-status`
- Ability: `flavor-agent/get-theme-tokens`
- Ability: `flavor-agent/search-wordpress-docs`

## Key Implementation Files

- `inc/Settings.php`
- `inc/OpenAI/Provider.php`
- `inc/Embeddings/ConfigurationValidator.php`
- `inc/Cloudflare/WorkersAIEmbeddingConfiguration.php`
- `inc/Abilities/InfraAbilities.php`
- `inc/Abilities/WordPressDocsAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php` — shared surface readiness checks; see `docs/reference/shared-internals.md`
- `inc/Patterns/PatternIndex.php`
- `inc/Patterns/Retrieval/PatternRetrievalBackendFactory.php`
- `inc/Cloudflare/AISearchClient.php`
- `inc/Cloudflare/PatternSearchClient.php`
- `src/admin/settings-page.js`
- `src/admin/settings-page-controller.js`
- `src/utils/capability-flags.js` — client-side surface capability flag derivation; see `docs/reference/shared-internals.md`
- `inc/REST/Agent_Controller.php`
