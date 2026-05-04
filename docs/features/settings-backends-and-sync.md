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

- Provider selection: direct Azure OpenAI, OpenAI Native, and Cloudflare Workers AI options configure plugin-owned embeddings for the Qdrant pattern backend; connector-backed options pin chat to that connector while Qdrant embeddings still require a configured direct backend; OpenAI Native can also pin chat to the OpenAI connector when that connector is available
- Azure OpenAI endpoint, API key, embedding deployment, and default reasoning effort for supported Connectors-routed chat providers
- OpenAI Native API key override and embedding model ID
- Cloudflare Workers AI account ID, API token, and embedding model ID
- Qdrant URL and API key
- Pattern retrieval backend: Qdrant or private Cloudflare AI Search
- Private Cloudflare AI Search account ID, namespace, instance ID, and API token for the pattern retrieval backend
- Pattern recommendation ranking threshold and max results
- Cloudflare AI Search pattern recommendation ranking threshold
- Cloudflare AI Search max result count
- Cloudflare AI Search override credentials for older installs or explicit custom-endpoint use
- Guidelines: site context, copy guidelines, image guidelines, additional guidelines, and block-specific notes. When the core/Gutenberg Guidelines store is present, Flavor Agent reads that store first and keeps the legacy fields as migration/import-export tooling.
- Manual pattern sync through the `Sync Pattern Catalog` button

Chat is no longer configured with plugin-owned chat credentials on this screen. After Workstream C of the WP 7.0 overlap remediation, chat traffic is owned by `Settings > Connectors` via the WordPress AI Client. Selecting `azure_openai` routes Qdrant-backend embeddings only and does not fall back to another chat provider. Selecting `openai_native` routes Qdrant-backend embeddings and can pin chat to the OpenAI connector when that connector is available. Selecting `cloudflare_workers_ai` routes Qdrant-backend embeddings to Workers AI and delegates chat to the configured WordPress AI Client runtime without pinning a Cloudflare provider. Selecting any connector-backed provider pins chat to that connector while Qdrant embeddings fall back to a configured direct Azure/OpenAI Native backend. Cloudflare Workers AI must be explicitly selected and is not used as an implicit embedding fallback. Selecting the Cloudflare AI Search pattern backend bypasses plugin-owned embeddings and Qdrant for pattern retrieval; final pattern reranking still requires Connectors chat.

The reasoning effort setting is attached to Connectors-routed chat as provider-specific `ModelConfig::customOptions` only where Flavor Agent has a known request contract today: `codex` receives `reasoningEffort`, and `openai` receives `reasoning.effort`. `openai_native` uses the OpenAI mapping because chat resolves to the OpenAI connector. Anthropic is left unmapped until its provider plugin documents the accepted reasoning payload.

## Backend Gating Rules

| Surface                       | Primary gate                                                                                                                                                                                                                                                                                                                                                                                   |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Block recommendations         | `ChatClient::is_supported()` returns `true` when the selected connector, or the OpenAI connector that matches OpenAI Native, is available through `Settings > Connectors`.                                                                                                                                                                                                                     |
| Pattern recommendations       | Selected pattern backend ready, a selected/matching WordPress AI Client chat connector for ranking, and a usable pattern index. Qdrant requires any configured direct embedding backend (`azure_openai`, `openai_native`, or explicitly selected `cloudflare_workers_ai`) plus Qdrant. Cloudflare AI Search requires private pattern AI Search credentials and instance metadata.                 |
| Template recommendations      | Runtime chat configured through `Settings > Connectors`                                                                                                                                                                                                                                                                                                                                        |
| Template-part recommendations | Runtime chat configured through `Settings > Connectors`                                                                                                                                                                                                                                                                                                                                        |
| Navigation recommendations    | Runtime chat configured through `Settings > Connectors` and current user can edit theme options                                                                                                                                                                                                                                                                                                |
| Global Styles / Style Book    | Runtime chat configured through `Settings > Connectors` and current user can edit theme options                                                                                                                                                                                                                                                                                                |
| WordPress docs grounding      | Built-in public Cloudflare AI Search endpoint; recommendation-time use checks exact-query, family, entity, and generic guidance caches first. Pattern recommendations disable foreground warming; block, template, template-part, navigation, Global Styles, and Style Book requests may foreground-warm docs guidance on generic or missing fallback guidance before async warming is queued. |

## Save And Validation Flow

1. The user changes settings on `Settings > Flavor Agent`
2. WordPress Settings API saves the options registered by `FlavorAgent\Settings::register_settings()`
3. Flavor Agent validates Azure, OpenAI Native, Cloudflare Workers AI, Qdrant, and private pattern Cloudflare AI Search settings when those credential sets changed and enough data is present to run the validation. Direct-provider fields submitted while a connector-backed provider is pinned for chat still validate as Qdrant embedding credentials. Cloudflare AI Search docs-grounding override credentials are only revalidated when those override fields are still being used.
4. If validation fails, the plugin keeps the previous values and surfaces the error through normal Settings API notices
5. The OpenAI Native embeddings section reports the current effective API key source and whether the core OpenAI connector is registered/configured
6. Connector-backed providers appear in the dropdown only when the WordPress AI Client reports that they currently support text generation
7. Runtime status messages call out which selected or matching connector-backed provider is currently serving chat and reflect the embeddings backend in use; unselected providers are not used as fallback
8. Durable setup guidance, troubleshooting, and format notes live in the native WordPress `Help` dropdown so inline page copy can stay focused on active controls and runtime state

## Pattern Sync Flow

1. The admin clicks the `Sync Pattern Catalog` button
2. `src/admin/settings-page-controller.js` posts to `POST /flavor-agent/v1/sync-patterns`
3. `FlavorAgent\REST\Agent_Controller::handle_sync_patterns()` calls `FlavorAgent\Patterns\PatternIndex::sync()`
4. The settings-page controller updates the sync badge, summary, metrics, and inline notice from the returned runtime state without a full reload

Backend-specific sync behavior:

| Pattern backend | Sync behavior |
| --- | --- |
| Qdrant | Diffs current registered patterns plus public-safe published synced/user `wp_block` patterns, embeds changed patterns with the selected plugin-owned embedding backend, upserts Qdrant points, and deletes stale points. |
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
| Provider diagnostics  | `Provider::openai_connector_status()` / `Provider::native_effective_api_key_source()` | Explain effective OpenAI Native credential ownership                                                       |
| Backend status        | `InfraAbilities::check_status()`                                                      | Returns backend inventory and currently available abilities                                                |
| Theme tokens          | `InfraAbilities::get_theme_tokens()`                                                  | Exposes the current theme token snapshot through an ability                                                |
| Docs grounding        | `WordPressDocsAbilities::search_wordpress_docs()`                                     | Exposes trusted developer-doc grounding through an ability                                                 |
| Guidelines bridge     | `Guidelines::get_all()` / `Guidelines::format_prompt_context()`                       | Reads core Guidelines first when available, falls back to legacy options, and formats guidance for prompts |
| Manual sync UI        | `src/admin/settings-page.js` + `src/admin/settings-page-controller.js`                | Owns settings-page sync interactions, live status updates, and section-open persistence                    |
| Pattern sync backend  | `PatternIndex::sync()`                                                                | Rebuilds the selected pattern backend catalog                                                               |

## Guardrails And Failure Modes

- Block recommendations, template work, navigation, and style surfaces require the WordPress AI Client / Connectors chat runtime; there is no plugin-managed chat fallback after Workstream C
- Pattern recommendations fail closed when the selected pattern backend is not configured or Connectors text generation is unavailable
- Connector-backed providers currently apply only to chat; Qdrant pattern embeddings remain plugin-managed
- Cloudflare AI Search pattern retrieval uses private site-owner Cloudflare credentials and is separate from the public WordPress developer-docs AI Search endpoint
- Cloudflare Workers AI embeddings require explicit provider selection; they are not used as an implicit fallback from Azure OpenAI, OpenAI Native, or connector-backed selections
- Guidelines are read core-first when the `wp_guideline` model is available; Flavor Agent does not require or assume a future `wp_register_guideline()` API yet
- Legacy guideline options are not deleted during the bridge phase
- Cloudflare validation only accepts guidance sourced from `developer.wordpress.org`
- Sync is admin-only and does not bypass pattern-index validation or locking rules

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/sync-patterns`
- Ability: `flavor-agent/check-status`
- Ability: `flavor-agent/get-theme-tokens`
- Ability: `flavor-agent/search-wordpress-docs`

## Key Implementation Files

- `inc/Settings.php`
- `inc/OpenAI/Provider.php`
- `inc/AzureOpenAI/ConfigurationValidator.php`
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
