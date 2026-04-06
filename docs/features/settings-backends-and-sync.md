# Settings, Backends, And Sync

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Settings page: `Settings > Flavor Agent`
- Admin audit page: `Settings > AI Activity` is documented separately in `docs/features/activity-and-audit.md`
- Sync surface: `Sync Pattern Catalog` panel on the main Flavor Agent settings page

## Surfacing Conditions

- The page requires `manage_options`
- Settings sections always render for admins, but save-time validation only runs when relevant credentials changed and the required values are present

## What The User Can Configure

- Active AI provider selection (`azure_openai`, `openai_native`, or a configured Connectors-backed provider)
- Azure OpenAI endpoint, API key, embedding deployment, and chat deployment
- OpenAI Native API key override plus embedding and chat model IDs
- Qdrant URL and API key
- Cloudflare AI Search account ID, instance ID, API token, and max result count
- Manual pattern sync through the `Sync Pattern Catalog` button

## Backend Gating Rules

| Surface | Primary gate |
|---|---|
| Block recommendations | `ChatClient::is_supported()`; honors the selected provider when it is a configured connector-backed provider, otherwise uses the selected direct provider when configured here, otherwise falls back to the generic WordPress AI Client / Connectors path |
| Pattern recommendations | Active provider embeddings, Qdrant configured, any usable chat provider (direct or connector-backed), and a usable pattern index |
| Template recommendations | Active provider chat configured (direct or connector-backed) |
| Template-part recommendations | Active provider chat configured (direct or connector-backed) |
| Navigation recommendations | Active provider chat configured (direct or connector-backed) and current user can edit theme options |
| WordPress docs grounding | Optional Cloudflare AI Search configuration; recommendation-time use is cache-only and non-blocking |

## Save And Validation Flow

1. The user changes settings on `Settings > Flavor Agent`
2. WordPress Settings API saves the options registered by `FlavorAgent\Settings::register_settings()`
3. Flavor Agent validates Azure, OpenAI Native, Qdrant, and Cloudflare settings only when those credential sets changed and enough data is present to run the validation
4. If validation fails, the plugin keeps the previous values and surfaces the error through normal Settings API notices
5. If OpenAI Native is selected, the page also reports the current effective API key source and whether the core OpenAI connector is registered/configured
6. Connector-backed providers appear in the dropdown only when the WordPress AI Client reports that they currently support text generation

## Pattern Sync Flow

1. The admin clicks the `Sync Pattern Catalog` button
2. `src/admin/sync-button.js` posts to `POST /flavor-agent/v1/sync-patterns`
3. `FlavorAgent\REST\Agent_Controller::handle_sync_patterns()` calls `FlavorAgent\Patterns\PatternIndex::sync()`
4. The UI reports indexed, removed, and status counts inline on the settings page

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| Settings page | `Settings::add_menu()` / `Settings::render_page()` | Registers and renders `Settings > Flavor Agent` |
| Settings registration | `Settings::register_settings()` | Registers options, sections, and fields |
| Provider diagnostics | `Provider::openai_connector_status()` / `Provider::native_effective_api_key_source()` | Explain effective OpenAI Native credential ownership |
| Backend status | `InfraAbilities::check_status()` | Returns backend inventory and currently available abilities |
| Theme tokens | `InfraAbilities::get_theme_tokens()` | Exposes the current theme token snapshot through an ability |
| Docs grounding | `WordPressDocsAbilities::search_wordpress_docs()` | Exposes trusted developer-doc grounding through an ability |
| Manual sync UI | `src/admin/sync-button.js` | Calls the sync route and renders the result |
| Pattern sync backend | `PatternIndex::sync()` | Rebuilds the vector-backed pattern catalog |

## Guardrails And Failure Modes

- Block recommendations do not require plugin-managed chat credentials if the WordPress AI Client / Connectors path is available
- Pattern recommendations fail closed when either the active direct provider or Qdrant is not configured
- Connector-backed providers currently apply only to chat surfaces; pattern embeddings remain plugin-managed
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
- `inc/Abilities/InfraAbilities.php`
- `inc/Abilities/WordPressDocsAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php` — shared surface readiness checks; see `docs/reference/shared-internals.md`
- `inc/Patterns/PatternIndex.php`
- `inc/Cloudflare/AISearchClient.php`
- `src/admin/sync-button.js`
- `src/utils/capability-flags.js` — client-side surface capability flag derivation; see `docs/reference/shared-internals.md`
- `inc/REST/Agent_Controller.php`
