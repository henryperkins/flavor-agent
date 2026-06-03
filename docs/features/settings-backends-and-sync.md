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
- Embedding Model: plugin-owned Cloudflare Workers AI credentials and effective embedding model for semantic features.
- Pattern Storage: Qdrant vector storage or private Cloudflare AI Search managed pattern index. This is infrastructure, not another AI model picker.
- Qdrant connection fields when Qdrant Pattern Storage is selected.
- Cloudflare AI Search Pattern Storage managed status when that backend is selected.
- Pattern recommendation ranking threshold and max results.
- Cloudflare AI Search pattern recommendation ranking threshold.
- Developer Docs source status and max result count. Developer docs use Flavor Agent's built-in public endpoint and do not expose Cloudflare credential fields.
- Guidelines: site context, copy guidelines, image guidelines, additional guidelines, and block-specific notes. When the core/Gutenberg Guidelines store is present, Flavor Agent reads that store first and keeps the legacy fields as migration/import-export tooling.
- Experimental Features: AI Activity Dual Logging controls. Block structural actions graduated to unconditionally-on on 2026-06-03 and are no longer listed here; opt-out is now the `flavor_agent_enable_block_structural_actions` filter.
- Manual pattern sync through the `Sync Pattern Catalog` button.

Chat is no longer configured with plugin-owned chat credentials on this screen. Provider ownership, credential precedence, reasoning-effort routing, and exact backend requirements are canonical in `docs/reference/provider-precedence.md`.

## Backend Gating Rules

Readiness is surfaced to admins in this screen, but the exact gates are canonical elsewhere:

- surface availability and ability gates: `docs/reference/abilities-and-routes.md#registered-abilities`
- provider and pattern-storage readiness: `docs/reference/provider-precedence.md`
- docs-grounding source and currentness policy: `docs/reference/developer-docs-public-corpus-runbook.md`

Recommendation-time Developer Docs grounding has two checks: the per-request guidance must contain trusted official Developer Docs chunks, and the built-in public corpus coverage check reports whether the managed endpoint currently includes a stable Developer Docs source plus a current Make/Core or Developer Blog release-cycle source. The source-coverage check is cached and may be refreshed by normal recommendation/docs requests, while signature-only freshness checks read local cache state only. Missing current release-cycle coverage is advisory: `missing-current-release-cycle` always remains actionable with the trusted-but-degraded warning, whether or not `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` (or the `flavor_agent_docs_grounding_require_current_coverage` filter) enables the release gate — the gate controls whether the coverage probe runs and surfaces the warning, not whether recommendations are withheld. Recommendations are withheld only when no trusted official guidance is present, when stable Developer Docs are absent (`missing-developer-docs`), or when the coverage probe fails (transport error).

## Save And Validation Flow

1. The user changes settings on `Settings > Flavor Agent`
2. WordPress Settings API saves the options registered by `FlavorAgent\Settings::register_settings()`
3. Flavor Agent validates changed backend settings only when enough data is present to run the relevant validation path. Provider-specific precedence and validation requirements live in `docs/reference/provider-precedence.md`.
4. If validation fails, the plugin keeps the previous values and surfaces the error through normal Settings API notices
5. The Cloudflare Workers AI embeddings section reports the account/token/effective-model values used by the runtime embedding path
6. Runtime status messages call out which WordPress AI Client path is currently serving chat and whether Cloudflare Workers AI embeddings are configured.
7. Pattern setup messages describe storage readiness separately from model readiness so Pattern Storage does not look like another AI model picker. Cloudflare AI Search Pattern Storage states are: needs Cloudflare credentials from the Embedding Model section, create managed pattern index, ready, and failed/incompatible.
8. Durable setup guidance, troubleshooting, and format notes live in the native WordPress `Help` dropdown so inline page copy can stay focused on active controls and runtime state

## Pattern Sync Flow

1. The admin clicks the `Sync Pattern Catalog` button
2. `src/admin/settings-page-controller.js` posts to `POST /flavor-agent/v1/sync-patterns`
3. `FlavorAgent\REST\Agent_Controller::handle_sync_patterns()` calls `FlavorAgent\Patterns\PatternIndex::enqueue_sync()` to schedule the catalog event and return immediately
4. `FlavorAgent\Patterns\PatternIndex::sync()` owns the selected backend rebuild through the `flavor_agent_reindex_patterns` WP-Cron hook
5. The settings-page controller updates the sync badge, summary, metrics, and inline notice from the returned queue state, then polls `GET /flavor-agent/v1/sync-patterns` while the runtime state remains `indexing`
6. Polling calls opportunistically run due `flavor_agent_reindex_patterns` events before returning state, so a local environment with disabled or delayed WP-Cron cannot strand a manual sync in `indexing`

Backend-specific sync behavior and debugging checks are canonical in `docs/reference/pattern-recommendation-debugging.md`; external-service disclosure details live in `docs/reference/external-service-disclosure.md`.

## Guidelines Bridge Flow

1. Recommendation abilities declare the guideline categories they need through their `GUIDELINE_CATEGORIES` constants.
2. `FlavorAgent\AI\Abilities\RecommendationAbility::execute_callback()` passes those categories, plus any scoped block name, into `RecommendationAbilityExecution`.
3. `RecommendationAbilityExecution` calls `Guidelines::format_prompt_context()` and temporarily prepends the formatted guidance to the recommendation system instruction through the `flavor_agent_recommendation_system_instruction` filter.
4. `Guidelines` resolves the active repository through the `flavor_agent_guidelines_repository` filter, then core/Gutenberg Guidelines storage, then legacy Flavor Agent options.
5. Core/Gutenberg storage is detected through the emerging `wp_guideline` post type and `wp_guideline_type` taxonomy model, with a read-only fallback for the older `wp_content_guideline` experiment shape.
6. Legacy options are preserved even when core storage is available. The current migration status is tracked separately so a future write migration can avoid repeated imports.
7. The settings screen keeps the legacy fields, block guideline editor, and JSON import/export available as migration/admin tooling when core Guidelines storage is detected.

## Primary Functions And Handlers

| Layer                 | Function / class                                                         | Role                                                                                                       |
| --------------------- | ------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| Settings page         | `Settings::add_menu()` / `Settings::render_page()`                       | Registers and renders `Settings > Flavor Agent`                                                            |
| Settings registration | `Settings::register_settings()`                                          | Registers options, sections, and fields                                                                    |
| Provider diagnostics  | `Provider::embedding_configuration()` / `InfraAbilities::check_status()` | Explain Cloudflare Workers AI embedding readiness and backend availability                                 |
| Backend status        | `InfraAbilities::check_status()`                                         | Returns backend inventory and currently available abilities                                                |
| Theme tokens          | `InfraAbilities::get_theme_tokens()`                                     | Exposes the current theme token snapshot through an ability                                                |
| Docs grounding        | `WordPressDocsAbilities::search_wordpress_docs()`                        | Exposes trusted developer-doc grounding through an ability                                                 |
| Guidelines bridge     | `RecommendationAbilityExecution` + `Guidelines::format_prompt_context()` | Reads core Guidelines first when available, falls back to legacy options, and prepends formatted guidance to recommendation system instructions |
| Manual sync UI        | `src/admin/settings-page.js` + `src/admin/settings-page-controller.js`   | Owns settings-page sync enqueueing, polling, live status updates, and section-open persistence             |
| Pattern sync backend  | `PatternIndex::sync()`                                                   | Rebuilds the selected pattern backend catalog                                                              |

## Guardrails And Failure Modes

- Recommendation surfaces require the WordPress AI Client / Connectors chat runtime; there is no plugin-managed chat fallback.
- Pattern recommendations fail closed when the selected pattern backend is not configured or Connectors text generation is unavailable.
- Flavor Agent has one embedding model choice for semantic features; Pattern Storage is a separate infrastructure choice.
- Private pattern AI Search and public WordPress developer-docs AI Search are separate services with separate readiness/disclosure rules.
- Cloudflare Workers AI embeddings are the only first-party admin embedding setup path
- Guidelines are read core-first when the `wp_guideline` model is available; Flavor Agent does not require or assume a future `wp_register_guideline()` API yet
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
