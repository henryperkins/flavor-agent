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
- Embedding Model: Cloudflare Workers AI account ID, API token, and embedding model for Flavor Agent semantic features.
- Azure OpenAI endpoint, API key, and embedding deployment are no longer editable settings. The neutral `flavor_agent_reasoning_effort` option is used by the Connectors-routed chat runtime when present; valid legacy `flavor_agent_azure_reasoning_effort` values are read only as a fallback/migration source. Text-generation provider and model readiness belong to `Settings > Connectors`.
- Cloudflare Workers AI account ID, API token, and embedding model ID.
- Pattern Storage: Qdrant vector storage or private Cloudflare AI Search managed index. This is infrastructure, not another AI model choice.
- Qdrant URL and API key when Qdrant Pattern Storage is selected.
- Private Cloudflare AI Search account ID, namespace, instance ID, and API token when Cloudflare AI Search Pattern Storage is selected.
- Pattern recommendation ranking threshold and max results.
- Cloudflare AI Search pattern recommendation ranking threshold.
- Developer Docs source status and max result count. Developer docs use Flavor Agent's built-in public endpoint and do not expose Cloudflare credential fields.
- Guidelines: site context, copy guidelines, image guidelines, additional guidelines, and block-specific notes. When the core/Gutenberg Guidelines store is present, Flavor Agent reads that store first and keeps the legacy fields as migration/import-export tooling.
- Manual pattern sync through the `Sync Pattern Catalog` button.

Chat is no longer configured with plugin-owned chat credentials on this screen. After Workstream C of the WP 7.0 overlap remediation, chat traffic is owned by `Settings > Connectors` via the WordPress AI Client. The Embedding Model section no longer doubles as a provider selector: Cloudflare Workers AI configures Flavor Agent embeddings, while text generation uses the configured WordPress AI Client runtime. Saved provider values from older settings screens are ignored instead of pinning chat. Selecting the Cloudflare AI Search pattern backend bypasses plugin-owned embeddings and Qdrant for pattern retrieval; final pattern reranking still requires Connectors chat.

The reasoning effort setting is attached to Connectors-routed chat as provider-specific `ModelConfig::customOptions` only where Flavor Agent has a known request contract today: `codex` receives `reasoningEffort`, and `openai` receives `reasoning.effort`. Anthropic is left unmapped until its provider plugin documents the accepted reasoning payload.

## Backend Gating Rules

| Surface                       | Primary gate                                                                                                                                                                                                                                                                                                                                                                                   |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Block recommendations         | `ChatClient::is_supported()` returns `true` when the WordPress AI Client has a configured text-generation runtime through `Settings > Connectors`. Saved provider values from older settings screens are ignored.                                                                                                                                                                               |
| Pattern recommendations       | Selected pattern storage ready, WordPress AI Client chat for ranking, and a usable pattern index. Qdrant requires the single configured Embedding Model plus Qdrant. Cloudflare AI Search requires private pattern AI Search credentials and instance metadata.                                                                                                                                |
| Template recommendations      | Runtime chat configured through `Settings > Connectors`                                                                                                                                                                                                                                                                                                                                        |
| Template-part recommendations | Runtime chat configured through `Settings > Connectors`                                                                                                                                                                                                                                                                                                                                        |
| Navigation recommendations    | Runtime chat configured through `Settings > Connectors` and current user can edit theme options                                                                                                                                                                                                                                                                                                |
| Global Styles / Style Book    | Runtime chat configured through `Settings > Connectors` and current user can edit theme options                                                                                                                                                                                                                                                                                                |
| WordPress docs grounding      | Built-in public Cloudflare AI Search endpoint; recommendation-time use checks exact-query, family, entity, and generic guidance caches first. Pattern recommendations disable foreground warming; block, template, template-part, navigation, Global Styles, and Style Book requests may foreground-warm docs guidance on generic or missing fallback guidance before async warming is queued. |

## Save And Validation Flow

1. The user changes settings on `Settings > Flavor Agent`
2. WordPress Settings API saves the options registered by `FlavorAgent\Settings::register_settings()`
3. Flavor Agent validates Cloudflare Workers AI, Qdrant, and private pattern Cloudflare AI Search settings when those credential sets changed and enough data is present to run the validation. Legacy Azure/OpenAI embedding options are not rendered or save-validated from the admin screen. Developer Docs uses the built-in public endpoint and has no credential save-validation path.
4. If validation fails, the plugin keeps the previous values and surfaces the error through normal Settings API notices
5. The Cloudflare Workers AI embeddings section reports the account/token/model fields used by the runtime embedding path
6. Runtime status messages call out which WordPress AI Client path is currently serving chat and whether Cloudflare Workers AI embeddings are configured.
7. Pattern setup messages describe storage readiness separately from model readiness so Pattern Storage does not look like another AI model picker.
8. Durable setup guidance, troubleshooting, and format notes live in the native WordPress `Help` dropdown so inline page copy can stay focused on active controls and runtime state

## Pattern Sync Flow

1. The admin clicks the `Sync Pattern Catalog` button
2. `src/admin/settings-page-controller.js` posts to `POST /flavor-agent/v1/sync-patterns`
3. `FlavorAgent\REST\Agent_Controller::handle_sync_patterns()` calls `FlavorAgent\Patterns\PatternIndex::sync()`
4. The settings-page controller updates the sync badge, summary, metrics, and inline notice from the returned runtime state without a full reload

Backend-specific sync behavior:

| Pattern backend      | Sync behavior                                                                                                                                                                                                               |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Qdrant               | Diffs current registered patterns plus public-safe published synced/user `wp_block` patterns, embeds changed patterns with Cloudflare Workers AI, upserts Qdrant points, and deletes stale points.                       |
| Cloudflare AI Search | Diffs the same public-safe corpus, uploads changed patterns as markdown items to the private AI Search instance with stable item IDs and `wait_for_completion=true`, lists remote item IDs, and deletes stale remote items. |

## Guidelines Bridge Flow

1. Recommendation prompt builders call `Guidelines::format_prompt_context()`.
2. `Guidelines` resolves the active repository through the `flavor_agent_guidelines_repository` filter, then core/Gutenberg Guidelines storage, then legacy Flavor Agent options.
3. Core/Gutenberg storage is detected through the emerging `wp_guideline` post type and `wp_guideline_type` taxonomy model, with a read-only fallback for the older `wp_content_guideline` experiment shape.
4. Legacy options are preserved even when core storage is available. The current migration status is tracked separately so a future write migration can avoid repeated imports.
5. The settings screen keeps the legacy fields, block guideline editor, and JSON import/export available as migration/admin tooling when core Guidelines storage is detected.

## Primary Functions And Handlers

| Layer                 | Function / class                                                                      | Role                                                                                                       |
| --------------------- | ------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Settings page         | `Settings::add_menu()` / `Settings::render_page()`                                    | Registers and renders `Settings > Flavor Agent`                                                            |
| Settings registration | `Settings::register_settings()`                                                       | Registers options, sections, and fields                                                                    |
| Provider diagnostics  | `Provider::embedding_configuration()` / `InfraAbilities::check_status()`              | Explain Cloudflare Workers AI embedding readiness and backend availability                                 |
| Backend status        | `InfraAbilities::check_status()`                                                      | Returns backend inventory and currently available abilities                                                |
| Theme tokens          | `InfraAbilities::get_theme_tokens()`                                                  | Exposes the current theme token snapshot through an ability                                                |
| Docs grounding        | `WordPressDocsAbilities::search_wordpress_docs()`                                     | Exposes trusted developer-doc grounding through an ability                                                 |
| Guidelines bridge     | `Guidelines::get_all()` / `Guidelines::format_prompt_context()`                       | Reads core Guidelines first when available, falls back to legacy options, and formats guidance for prompts |
| Manual sync UI        | `src/admin/settings-page.js` + `src/admin/settings-page-controller.js`                | Owns settings-page sync interactions, live status updates, and section-open persistence                    |
| Pattern sync backend  | `PatternIndex::sync()`                                                                | Rebuilds the selected pattern backend catalog                                                              |

## Guardrails And Failure Modes

- Block recommendations, template work, navigation, and style surfaces require the WordPress AI Client / Connectors chat runtime; there is no plugin-managed chat fallback after Workstream C
- Pattern recommendations fail closed when the selected pattern backend is not configured or Connectors text generation is unavailable
- Flavor Agent has one embedding model choice for semantic features; Pattern Storage is a separate infrastructure choice
- Cloudflare AI Search pattern retrieval uses private site-owner Cloudflare credentials and is separate from the public WordPress developer-docs AI Search endpoint
- Cloudflare Workers AI embeddings are the only first-party admin embedding setup path
- Guidelines are read core-first when the `wp_guideline` model is available; Flavor Agent does not require or assume a future `wp_register_guideline()` API yet
- Legacy guideline options are not deleted during the bridge phase
- Developer Docs grounding only accepts guidance sourced from `developer.wordpress.org`
- Sync is admin-only and does not bypass pattern-index validation or locking rules

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/sync-patterns`
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
