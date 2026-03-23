# CLAUDE.md — Flavor Agent

WordPress plugin: AI-powered block recommendations in the Gutenberg Inspector, vector-powered pattern recommendations in the inserter, template composition suggestions in the Site Editor, template-part recommendations, and navigation structure suggestions.

Entry point: `flavor-agent.php` · Requires WP 7.0+ · PHP 8.0+

## Commands

```bash
npm ci                 # install JS deps reproducibly (Node 20 / npm 10)
npm start              # dev build with watch (webpack via @wordpress/scripts)
npm run build          # production build → build/index.js, build/admin.js
npm run lint:js        # ESLint on src/
npm run test:unit -- --runInBand  # Jest unit tests
npm run test:e2e       # Playwright smoke tests
npm run wp:start       # docker compose up (local dev)
npm run wp:stop        # docker compose down
npm run wp:reset       # docker compose down -v (destroys volumes)

composer install       # install PHP deps (PSR-4 autoloader)
composer lint:php      # WPCS via phpcs
composer test:php      # PHPUnit tests
vendor/bin/phpunit     # PHPUnit tests (direct)
```

PHP tests run via `vendor/bin/phpunit`. JS tests live alongside source files (e.g. `store/update-helpers.test.js`) or in `__tests__/` directories.

## Architecture

**PHP backend** (`inc/`, PSR-4 namespace `FlavorAgent\`):

| Namespace | Purpose |
|-----------|---------|
| `REST\Agent_Controller` | REST routes under `flavor-agent/v1/` (recommend-block, recommend-patterns, recommend-template, recommend-template-part, sync-patterns) |
| `LLM\WordPressAIClient` | Wrapper around the WordPress 7.0 AI client for block recommendations |
| `LLM\Prompt` | Block recommendation prompt assembly and response parsing |
| `LLM\TemplatePrompt` | Template recommendation prompt assembly and executable operation parsing |
| `LLM\TemplatePartPrompt` | Template-part recommendation prompt assembly and response parsing |
| `LLM\NavigationPrompt` | Navigation recommendation prompt assembly and response parsing |
| `Context\ServerCollector` | Gathers server-side block, template, template-part, navigation, and theme context |
| `OpenAI\Provider` | Provider selection (Azure OpenAI vs OpenAI Native), credential fallback chain |
| `AzureOpenAI\ConfigurationValidator` | Settings-time backend validation helpers |
| `AzureOpenAI\EmbeddingClient` | Azure OpenAI / OpenAI Native embeddings API |
| `AzureOpenAI\QdrantClient` | Qdrant vector DB for pattern similarity search |
| `AzureOpenAI\ResponsesClient` | Azure OpenAI / OpenAI Native Responses API for ranking/chat |
| `Cloudflare\AISearchClient` | WordPress developer-doc grounding, cache, and prewarm pipeline |
| `Patterns\PatternIndex` | Embeds registered patterns into Qdrant; syncs on theme/plugin changes |
| `Abilities\Registration` | Registers abilities + category with WordPress Abilities API |
| `Abilities\BlockAbilities` | Block recommendation and introspection handlers |
| `Abilities\PatternAbilities` | Pattern listing and vector-powered recommendation handlers |
| `Abilities\TemplateAbilities` | Template and template-part composition recommendation handlers |
| `Abilities\NavigationAbilities` | Navigation structure recommendation handler |
| `Abilities\WordPressDocsAbilities` | WordPress developer docs search via Cloudflare AI Search |
| `Abilities\InfraAbilities` | Theme token extraction and status check handlers |
| `Settings` | Admin settings page (provider selection, Azure/OpenAI Native/Qdrant/Cloudflare, validation, sync, diagnostics) |
| `Support\StringArray` | String array sanitization utility |

**JS frontend** (`src/`, built with `@wordpress/scripts`):

| Path | Purpose |
|------|---------|
| `index.js` | Entry: registers store, session bootstrap, Inspector filter, pattern/template/template-part plugins |
| `components/ActivitySessionBootstrap.js` | Reloads session-scoped AI activity when the edited entity changes |
| `components/AIActivitySection.js` | Shared recent-actions list with per-entry undo affordance |
| `inspector/InspectorInjector.js` | `editor.BlockEdit` HOC — injects AI panels into all blocks |
| `inspector/SettingsRecommendations.js` | Settings tab suggestions |
| `inspector/StylesRecommendations.js` | Appearance tab suggestions + style variation pills |
| `inspector/SuggestionChips.js` | Reusable chip component for sub-panel suggestions |
| `inspector/suggestion-keys.js` | Stable key generation for applied-state tracking |
| `context/block-inspector.js` | Client-side block introspection (supports, attributes, styles) |
| `context/theme-tokens.js` | Design token extraction from theme.json + global styles |
| `context/collector.js` | Combines block + theme + structural context for LLM calls |
| `store/index.js` | `@wordpress/data` store (`flavor-agent`) — recommendations, template apply, undo, activity persistence |
| `store/activity-history.js` | Session-scoped AI activity schema and storage adapter |
| `store/update-helpers.js` | Attribute update and undo snapshot helpers |
| `patterns/PatternRecommender.js` | Pattern recommendation fetch + inserter patching |
| `patterns/InserterBadge.js` | Inserter toggle badge for recommendation status |
| `patterns/compat.js` | Stable/experimental pattern API and DOM selector adapter |
| `patterns/find-inserter-search-input.js` | Re-export wrapper for backward compatibility |
| `patterns/recommendation-utils.js` | Pattern metadata patching and badge reason extraction |
| `patterns/inserter-badge-state.js` | Badge state machine for recommendation status display |
| `templates/TemplateRecommender.js` | Site Editor template preview/apply/undo panel |
| `templates/template-recommender-helpers.js` | Template UI and operation view-model helpers |
| `template-parts/TemplatePartRecommender.js` | Template-part-scoped AI recommendations panel |
| `utils/template-operation-sequence.js` | Template operation validation and normalization |
| `utils/template-actions.js` | Deterministic template execution, selection, and undo helpers |
| `utils/structural-identity.js` | Block structural role inference and ancestor tracking |
| `utils/template-part-areas.js` | Template-part area resolution from attributes, slug, or registry |
| `utils/template-types.js` | Template slug normalization per pattern templateTypes vocabulary |
| `utils/pattern-names.js` | Extract distinct pattern names from collections |
| `utils/visible-patterns.js` | Get editor-visible pattern names for current context |
| `admin/sync-button.js` | Admin page: manual pattern index sync button |

**Webpack** has two entry points: `src/index.js` (editor) and `src/admin/sync-button.js` (admin page).

## Key Integration Points

- **Inspector injection**: `editor.BlockEdit` filter via `createHigherOrderComponent` + `<InspectorControls group="...">` for each tab (settings, styles, color, typography, dimensions, border).
- **REST API**: All routes live under `flavor-agent/v1/`, registered in `Agent_Controller::register_routes()`. `recommend-block` and `recommend-patterns` use `edit_posts`, `recommend-template` and `recommend-template-part` use `edit_theme_options`, and `sync-patterns` uses `manage_options`.
- **Pattern index lifecycle**: Auto-reindexes on theme switch, plugin activation/deactivation, upgrades, and relevant option changes. Uses WP cron event `flavor_agent_reindex_patterns`.
- **Docs grounding lifecycle**: Prewarm and context-warm cron events (`flavor_agent_prewarm_docs`, `flavor_agent_warm_docs_context`) scheduled on activation.
- **Activity history**: Block and template applies write structured session-scoped activity records keyed by the current post/template reference and validated again before undo.
- **Abilities API**: Hooks into `wp_abilities_api_categories_init` and `wp_abilities_api_init`. Registers 11 abilities across block, pattern, template, navigation, docs, and infra categories.

## External Services

| Service | Options (Settings page) |
|---------|------------------------|
| Provider selection | `flavor_agent_openai_provider` (`azure_openai` or `openai_native`) |
| WordPress AI Client providers | Core `Settings > Connectors` screen |
| Azure OpenAI (chat) | `flavor_agent_azure_openai_endpoint`, `flavor_agent_azure_openai_key`, `flavor_agent_azure_chat_deployment` |
| Azure OpenAI (embeddings) | `flavor_agent_azure_openai_endpoint`, `flavor_agent_azure_openai_key`, `flavor_agent_azure_embedding_deployment` |
| OpenAI Native (chat) | `flavor_agent_openai_native_api_key`, `flavor_agent_openai_native_chat_model` |
| OpenAI Native (embeddings) | `flavor_agent_openai_native_api_key`, `flavor_agent_openai_native_embedding_model` |
| Qdrant vector DB | `flavor_agent_qdrant_url`, `flavor_agent_qdrant_key` |
| Cloudflare AI Search | `flavor_agent_cloudflare_ai_search_account_id`, `flavor_agent_cloudflare_ai_search_instance_id`, `flavor_agent_cloudflare_ai_search_api_token`, `flavor_agent_cloudflare_ai_search_max_results` |

Each recommendation surface disables independently when its required backend is unavailable.

## Gotchas

- `build/` is gitignored — always run `npm run build` before testing in WordPress.
- `.nvmrc` and `.npmrc` pin the supported JS toolchain to Node `20.x` / npm `10.x`; newer npm versions fail `npm ci` on this repo.
- WP-CLI is available in the container (`wp <command> --allow-root` via `docker exec wordpress-wordpress-1`).
- The `@wordpress/data` store name is `flavor-agent` (hyphenated).
- Inspector sub-panel chips use `grid-column: 1 / -1` to span ToolsPanel CSS grid — changing this breaks layout.
- The plugin respects `contentOnly` editing mode: suggestions won't propose changes to locked attributes.
- `vendor/` is gitignored — run `composer install` after cloning (and inside the container) to generate the PSR-4 autoloader.
- The JS global `flavorAgentData` (localized via `wp_localize_script`) exposes `restUrl`, `nonce`, `canRecommendBlocks`, `canRecommendPatterns`, `canRecommendTemplates`, `canRecommendTemplateParts`, and `templatePartAreas` to the editor script.
- A second JS global `flavorAgentAdmin` (localized on the admin settings page) exposes `restUrl` and `nonce` to the admin script.
- Pattern settings keys and inserter DOM selectors are centralized in `src/patterns/compat.js`; direct experimental usages remain in `src/context/theme-tokens.js` and `src/context/block-inspector.js` because WordPress has not promoted stable replacements yet.

## Docs

- `docs/SOURCE_OF_TRUTH.md` — definitive project reference: scope, architecture, inventory, roadmap, definition of done
- `docs/flavor-agent-readme.md` — detailed architecture and LLM prompt/response format
- `docs/local-wordpress-ide.md` — local Docker/devcontainer workflow
- `docs/NEXT_STEPS_PLAN.md` — execution-plan snapshot and backlog history
- `STATUS.md` — working feature inventory and verification log
