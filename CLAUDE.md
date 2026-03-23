# CLAUDE.md — Flavor Agent

WordPress plugin: LLM-powered block recommendations in the Gutenberg Inspector sidebar + vector-powered pattern recommendations in the inserter.

Entry point: `flavor-agent.php` · Requires WP 7.0+ · PHP 8.0+

## Commands

```bash
npm install            # install JS deps
npm start              # dev build with watch (webpack via @wordpress/scripts)
npm run build          # production build → build/index.js, build/admin.js
npm run lint:js        # ESLint on src/
npm run test:unit      # Jest unit tests (src/**/__tests__/*.test.js)

composer install       # install PHP deps (PSR-4 autoloader)
```

PHP tests run via `vendor/bin/phpunit`. JS tests live alongside source files (e.g. `store/update-helpers.test.js`) or in `__tests__/` directories.

## Architecture

**PHP backend** (`inc/`, PSR-4 namespace `FlavorAgent\`):

| Namespace | Purpose |
|-----------|---------|
| `REST\Agent_Controller` | REST routes under `flavor-agent/v1/` (recommend-block, recommend-patterns, recommend-template, sync-patterns) |
| `LLM\WordPressAIClient` | Wrapper around the WordPress 7.0 AI client for block recommendations |
| `LLM\Prompt` | System/user prompt assembly for block and pattern recommendations |
| `LLM\NavigationPrompt` | Navigation recommendation prompt assembly and response parsing |
| `Context\ServerCollector` | Gathers server-side context (post type, template, theme.json tokens) |
| `AzureOpenAI\EmbeddingClient` | Azure OpenAI embeddings API |
| `AzureOpenAI\QdrantClient` | Qdrant vector DB for pattern similarity search |
| `AzureOpenAI\ResponsesClient` | Azure OpenAI chat completions |
| `Patterns\PatternIndex` | Embeds registered patterns into Qdrant; syncs on theme/plugin changes |
| `Abilities\Registration` | Registers abilities + category with WordPress Abilities API (WP 6.9+) |
| `Abilities\*Abilities` | Individual ability handlers (Block, Pattern, Infra, Navigation, Template) |
| `Settings` | Admin settings page (Azure/Qdrant/Cloudflare + pattern sync) |

**JS frontend** (`src/`, built with `@wordpress/scripts`):

| Path | Purpose |
|------|---------|
| `index.js` | Entry: registers store, Inspector filter, sidebar plugin |
| `inspector/InspectorInjector.js` | `editor.BlockEdit` HOC — injects AI panels into all blocks |
| `inspector/SettingsRecommendations.js` | Settings tab suggestions |
| `inspector/StylesRecommendations.js` | Appearance tab suggestions + style variation pills |
| `inspector/SuggestionChips.js` | Reusable chip component for sub-panel suggestions |
| `context/block-inspector.js` | Client-side block introspection (supports, attributes, styles) |
| `context/theme-tokens.js` | Design token extraction from theme.json + global styles |
| `context/collector.js` | Combines block + theme context for LLM calls |
| `store/index.js` | `@wordpress/data` store (`flavor-agent`) — per-block, per-tab state |
| `store/update-helpers.js` | Attribute update logic (with tests) |
| `patterns/PatternRecommender.js` | Pattern recommendation UI in editor |
| `patterns/InserterBadge.js` | Badges for recommended patterns in the inserter |
| `patterns/recommendation-utils.js` | Scoring/filtering utilities (with tests) |
| `admin/sync-button.js` | Admin page: manual pattern index sync button |

**Webpack** has two entry points: `src/index.js` (editor) and `src/admin/sync-button.js` (admin page).

## Key Integration Points

- **Inspector injection**: `editor.BlockEdit` filter via `createHigherOrderComponent` + `<InspectorControls group="...">` for each tab (settings, styles, color, typography, dimensions, border).
- **REST API**: All routes under `flavor-agent/v1/`, registered in `Agent_Controller::register_routes()`. Permission: `edit_posts`.
- **Pattern index lifecycle**: Auto-reindexes on theme switch, plugin activation/deactivation, and when relevant options change. Uses WP cron event `flavor_agent_reindex_patterns`.
- **Abilities API**: Hooks into `wp_abilities_api_categories_init` and `wp_abilities_api_init` (WP 6.9+ only).

## External Services

| Service | Options (Settings page) |
|---------|------------------------|
| WordPress AI Client providers | Core `Settings > Connectors` screen |
| Azure OpenAI (chat) | `flavor_agent_azure_openai_endpoint`, `flavor_agent_azure_openai_key`, `flavor_agent_azure_chat_deployment` |
| Azure OpenAI (embeddings) | `flavor_agent_azure_openai_endpoint`, `flavor_agent_azure_openai_key`, `flavor_agent_azure_embedding_deployment` |
| Qdrant vector DB | `flavor_agent_qdrant_url`, `flavor_agent_qdrant_key` |
| Cloudflare AI Search | `flavor_agent_cloudflare_ai_search_account_id`, `flavor_agent_cloudflare_ai_search_instance_id`, `flavor_agent_cloudflare_ai_search_api_token`, `flavor_agent_cloudflare_ai_search_max_results` |

Each recommendation surface disables independently when its required backend is unavailable.

## Gotchas

- `build/` is gitignored — always run `npm run build` before testing in WordPress.
- WP-CLI is available in the container (`wp <command> --allow-root` via `docker exec wordpress-wordpress-1`).
- The `@wordpress/data` store name is `flavor-agent` (hyphenated).
- Inspector sub-panel chips use `grid-column: 1 / -1` to span ToolsPanel CSS grid — changing this breaks layout.
- The plugin respects `contentOnly` editing mode: suggestions won't propose changes to locked attributes.
- `vendor/` is gitignored — run `composer install` after cloning (and inside the container) to generate the PSR-4 autoloader.
- The JS global `flavorAgentData` (localized via `wp_localize_script`) exposes `restUrl`, `nonce`, `canRecommendBlocks`, and `canRecommendPatterns` to the editor script.

## Docs

- `docs/SOURCE_OF_TRUTH.md` — definitive project reference: scope, architecture, inventory, roadmap, definition of done
- `docs/flavor-agent-readme.md` — detailed architecture and LLM prompt/response format
- `STATUS.md` — working/stubbed features and verification log
