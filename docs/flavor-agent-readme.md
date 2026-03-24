# Flavor Agent

Flavor Agent is a WordPress plugin that adds AI-assisted recommendations directly to the block editor.

It currently has four primary editor experiences:

- Block recommendations in the native Inspector, powered by the WordPress AI Client and core Connectors.
- Pattern recommendations in the native inserter, powered by Azure OpenAI embeddings + responses and Qdrant.
- Template recommendations in the Site Editor, powered by the Azure OpenAI Responses API with validated template-part and pattern operations.
- Template-part recommendations in the Site Editor, scoped to individual template parts.

There is no separate approval sidebar in the current codebase. Block suggestions apply inline in the Inspector, pattern recommendations patch the native inserter, and template suggestions use a review-confirm-apply flow inside the document settings panel. Block and template applies also write session-scoped AI activity entries with inline undo.

## Current Architecture

```text
flavor-agent/
├── flavor-agent.php              # Bootstrap, lifecycle hooks, REST + Abilities registration, editor asset enqueue
├── uninstall.php                 # Removes plugin-owned options, sync state, grounding caches, and cron hooks
├── composer.json                 # PSR-4 autoload + PHP tooling
├── package.json                  # @wordpress/scripts build, lint, unit/e2e tests
├── .env.example                  # Local WordPress/Docker defaults
├── .nvmrc                        # Supported Node major version
├── .npmrc                        # engine-strict pin for Node 20 / npm 10
├── docker-compose.yml            # Local WordPress + MariaDB + phpMyAdmin stack
├── .devcontainer/                # VS Code devcontainer config
├── docker/                       # Local WordPress dev image
├── scripts/                      # Local WordPress helper scripts
│
├── inc/
│   ├── Abilities/                # Block, pattern, template, navigation, docs, and infra ability handlers
│   ├── AzureOpenAI/              # Deployment validation, embeddings, Responses API, and Qdrant clients
│   ├── Cloudflare/               # AI Search grounding + prewarm pipeline
│   ├── Context/                  # Server-side block/theme/pattern/template/navigation collectors
│   ├── LLM/                      # WordPress AI client wrapper + prompt/response handling
│   ├── Patterns/                 # Pattern index state, sync, fingerprinting, scheduling
│   ├── REST/                     # Editor-facing REST routes
│   ├── Support/                  # Shared sanitization helpers
│   └── Settings.php              # Settings API page, validation, and sync/diagnostics panels
│
├── src/
│   ├── admin/                    # Settings-screen sync button script
│   ├── components/               # Shared activity history/session bootstrap UI
│   ├── context/                  # Editor-side block and theme collectors
│   ├── inspector/                # InspectorControls injection and recommendation UI
│   ├── patterns/                 # Inserter recommendation patching, badge, and compat adapter
│   ├── store/                    # @wordpress/data store, undo state, and persistence
│   ├── templates/                # Site Editor template recommender + preview/apply helpers
│   └── utils/                    # Template execution, pattern scoping, and structural helpers
│
├── tests/
│   ├── e2e/                      # Playwright smoke tests + Playground MU loader
│   └── phpunit/                  # PHPUnit suites and WP stubs
│
└── docs/                         # Specs, references, local dev notes, plans, and repo guides
```

## Editor Flows

### Block Recommendations

When a block is selected, the plugin injects an `AI Recommendations` panel into the native Inspector. Clicking `Get Suggestions` sends a block context snapshot to `POST /flavor-agent/v1/recommend-block`.

The request includes:

- Block name, current attributes, styles, and supported Inspector panels.
- Theme token summaries from `theme.json` and global settings.
- Nearby sibling blocks.
- Content-only lock state and current `metadata.blockVisibility`, when present.

The response is parsed into `settings`, `styles`, and `block` suggestion groups. Applying a suggestion now uses a safe nested merge path so partial `style` and `metadata` updates do not wipe unrelated state. Loading and error state are tracked per selected block, and the backend now mirrors the same `disabled` / `contentOnly` restriction matrix that the editor enforces client-side.

### Pattern Recommendations

Pattern recommendations are exposed through `POST /flavor-agent/v1/recommend-patterns` and the `flavor-agent/recommend-patterns` ability.

The client behavior is:

- Passive fetch on editor load when Azure/Qdrant configuration is present.
- Search-triggered refresh when the inserter search box changes.
- Native pattern patching through `src/patterns/compat.js`, which prefers stable `blockPatterns` / `blockPatternCategories` keys, then `__experimentalAdditional*` override keys, and falls back to `__experimental*` base variants when needed.
- A toolbar `!` badge when any recommendation score is `>= 0.9`.

The server behavior is:

- Check persisted pattern index runtime state.
- Embed the query with Azure OpenAI.
- Retrieve candidates from Qdrant using semantic and optional structural search passes.
- Rank candidates with the Azure OpenAI Responses API.
- Rehydrate registry-owned fields (`title`, `categories`, `content`) from stored payloads.

`visiblePatternNames` is now derived from the active inserter root, so nested insertion contexts only receive patterns WordPress already allows in that specific surface.

### Template Recommendations

Template recommendations are exposed through `POST /flavor-agent/v1/recommend-template` and the `flavor-agent/recommend-template` ability.

The client behavior is:

- Available only while editing a `wp_template` entity in the Site Editor.
- Suggestions can be previewed before apply; the user explicitly confirms each validated operation set before the template is mutated.
- Supported executable operations are `assign_template_part`, `replace_template_part`, and `insert_pattern`.
- Template candidate patterns are narrowed by current inserter-root `visiblePatternNames` when the editor exposes that context.

The server behavior is:

- Resolve the active template from the Site Editor reference.
- Collect assigned template-part slots, available template parts, and typed plus generic pattern candidates (filtered by client-side `visiblePatternNames` when provided, before the candidate cap).
- Rank template composition suggestions with the Azure OpenAI Responses API.
- Validate returned operations, template parts, and pattern names against the collected context before rendering or applying them in the panel.

### AI Activity and Undo

Applied block and template suggestions write structured activity records into `sessionStorage`, keyed to the current post or template. The latest compatible action can be undone from the inline success notice or the shared `Recent AI Actions` list when the live editor state still matches the recorded post-apply snapshot.

## Settings

The plugin exposes a Settings API screen at `Settings > Flavor Agent`.

Block recommendation providers are configured separately in the core `Settings > Connectors` screen.

When the Cloudflare AI Search account ID, instance ID, or token changes and all three fields are present, the plugin validates the configured account, instance, and token by running a lightweight probe search that must return trusted `developer.wordpress.org` guidance, and keeps the previous values if validation fails. This allows documented AI Search Run tokens to pass validation without requiring instance metadata read access. Successful saves still use the standard Settings API notice flow, and failed validation surfaces the Cloudflare error on the same screen.

Configured options:

- `flavor_agent_azure_openai_endpoint`
- `flavor_agent_azure_openai_key`
- `flavor_agent_azure_embedding_deployment`
- `flavor_agent_azure_chat_deployment`
- `flavor_agent_qdrant_url`
- `flavor_agent_qdrant_key`
- `flavor_agent_cloudflare_ai_search_account_id`
- `flavor_agent_cloudflare_ai_search_instance_id`
- `flavor_agent_cloudflare_ai_search_api_token`
- `flavor_agent_cloudflare_ai_search_max_results`

The same screen also includes a `Sync Pattern Catalog` action that calls `POST /flavor-agent/v1/sync-patterns` and reports the current pattern index status.

## Abilities Status

Implemented abilities:

- `flavor-agent/recommend-block`
- `flavor-agent/introspect-block`
- `flavor-agent/recommend-patterns`
- `flavor-agent/list-patterns`
- `flavor-agent/recommend-template` (accepts optional `visiblePatternNames`)
- `flavor-agent/recommend-template-part`
- `flavor-agent/list-template-parts`
- `flavor-agent/search-wordpress-docs`
- `flavor-agent/get-theme-tokens`
- `flavor-agent/recommend-navigation`
- `flavor-agent/check-status`

All currently registered abilities in the tree are implemented. The Abilities API path is additive for WordPress versions that expose those hooks; the editor-side REST and injected UI path remains the primary runtime path.

## Pattern Index Lifecycle

Pattern indexing is managed by `FlavorAgent\Patterns\PatternIndex`.

Current lifecycle behavior:

- Activation marks the catalog dirty and schedules a sync when the vector backends are configured.
- Theme switches, plugin activation/deactivation, upgrades, and relevant settings changes mark the index dirty and schedule a background refresh.
- Deactivation clears the scheduled reindex hook and any active sync lock.
- Uninstall removes plugin-owned options, Cloudflare grounding options, pattern index state, and the scheduled reindex hook.

## Development

Install dependencies:

```bash
composer install
source ~/.nvm/nvm.sh && nvm use 20
npm ci
```

Build and verify:

```bash
npm run build
npm run lint:js
npm run test:unit -- --runInBand
npm run test:e2e
composer lint:php
vendor/bin/phpunit
```

## Compatibility Notes

- Plugin header now targets WordPress 7.0+ and PHP 8.0+.
- The editor-side inserter enhancement uses DOM access for the search input observer and the toolbar badge anchor. It is editor-specific code, not a DOM-free abstraction.
- The pattern surface now routes settings-key and DOM-selector differences through `src/patterns/compat.js`, preferring stable APIs, then `__experimentalAdditional*` override keys, and falling back to `__experimental*` base variants only when needed.
- `__experimentalFeatures` in `src/context/theme-tokens.js` and `__experimentalRole` in `src/context/block-inspector.js` still have no stable replacements and remain direct integrations.
