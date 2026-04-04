# Flavor Agent

Flavor Agent is a WordPress plugin that adds AI-assisted recommendations directly to the block editor.

This document is the editor-flow reference. For overall doc ownership and reading order, start with `docs/README.md`. For canonical scope and inventory, use `docs/SOURCE_OF_TRUTH.md`. For current verified state, use `STATUS.md`. For exact surface-by-surface conditions, use `docs/FEATURE_SURFACE_MATRIX.md` and the deep dives in `docs/features/`.

It currently has five primary editor experiences:

- Block recommendations in the native Inspector, powered by the selected plugin provider when chat is configured here and otherwise falling back to the WordPress AI Client plus core Connectors.
- Pattern recommendations in the native inserter, powered by the active OpenAI provider (Azure OpenAI or OpenAI Native) plus Qdrant.
- Navigation recommendations in the native Inspector for selected `core/navigation` blocks, powered by the active OpenAI provider and scoped as advisory guidance today.
- Template recommendations in the Site Editor, powered by the active OpenAI provider with validated template-part and pattern operations.
- Template-part recommendations in the Site Editor, scoped to individual template parts with a narrow review-confirm-apply path for validated bounded operations.

There is no separate approval sidebar in the current codebase. Block suggestions apply inline in the Inspector, pattern recommendations patch the native inserter, navigation remains advisory-only in the Inspector, and template plus template-part suggestions use a review-confirm-apply flow inside the document settings panel when the returned operations are validated. Block, template, and template-part applies also write activity entries with inline undo; the current UX is still editor-scoped even though persistence now flows through the shared server-backed activity backend, with `sessionStorage` retained only as an editor cache/fallback.

## Current Architecture

```text
flavor-agent/
├── flavor-agent.php              # Bootstrap, lifecycle hooks, REST + Abilities registration, editor asset enqueue
├── uninstall.php                 # Removes plugin-owned options, sync state, grounding caches, and cron hooks
├── composer.json                 # PSR-4 autoload + PHP tooling
├── package.json                  # @wordpress/scripts build, lint, unit/e2e tests
├── .env.example                  # Local WordPress/Docker defaults
├── .nvmrc                        # Default Node major version (latest LTS)
├── .npmrc                        # engine-strict gate for supported Node/npm majors
├── docker-compose.yml            # Local WordPress + MariaDB + phpMyAdmin stack
├── .devcontainer/                # VS Code devcontainer config
├── build/                        # Built editor/admin assets loaded by WordPress
├── dist/                         # Packaged release artifacts (plugin ZIP)
├── docker/                       # Local WordPress dev image
├── output/                       # Test and automation output (for example Playwright artifacts)
├── scripts/                      # Local WordPress helper scripts
│
├── inc/
│   ├── Abilities/                # Block, pattern, template, navigation, docs, and infra ability handlers
│   ├── Activity/                 # Server-backed AI activity repository, permissions, and serialization
│   ├── AzureOpenAI/              # Deployment validation, embeddings, Responses API, and Qdrant clients
│   ├── Cloudflare/               # AI Search grounding + prewarm pipeline
│   ├── Context/                  # Server-side block/theme/pattern/template/navigation collectors
│   ├── LLM/                      # WordPress AI client wrapper + prompt/response handling
│   ├── OpenAI/                   # Provider selection and connector-aware credential resolution
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
│   ├── template-parts/           # Site Editor template-part recommender
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

- Passive fetch on editor load when the active provider plus Qdrant are configured.
- Search-triggered refresh when the inserter search box changes.
- Native pattern patching through `src/patterns/compat.js`, which probes future stable `blockPatterns` / `blockPatternCategories` keys for forward compatibility but currently lands on `__experimentalAdditional*` or `__experimental*` settings on Gutenberg trunk / WordPress 7.0.
- A toolbar badge that shows recommendation count, loading state, or error state next to the inserter toggle.

The server behavior is:

- Check persisted pattern index runtime state.
- Embed the query with the active provider's embeddings configuration.
- Retrieve candidates from Qdrant using semantic and optional structural search passes.
- Rank candidates with the active provider's responses configuration.
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
- Rank template composition suggestions with the active provider's responses configuration.
- Validate returned operations, template parts, and pattern names against the collected context before rendering or applying them in the panel.

### Template Part Recommendations

Template-part recommendations are exposed through `POST /flavor-agent/v1/recommend-template-part` and the `flavor-agent/recommend-template-part` ability.

The client behavior is:

- Available only while editing a `wp_template_part` entity in the Site Editor.
- Uses a dedicated `PluginDocumentSettingPanel` implemented in `src/template-parts/TemplatePartRecommender.js`.
- Uses the shared `flavor-agent` data store for request state, preview state, apply state, editor-scoped activity hydration, and undo.
- Renders advisory suggestion cards with block-focus links and pattern-browse links, and surfaces preview/apply controls only for validated executable suggestions.
- Supports validated bounded operations: `insert_pattern`, `replace_block_with_pattern`, and `remove_block`, with `start`, `end`, `before_block_path`, and `after_block_path` placement where applicable.

The server behavior is:

- Resolve the active template part from the Site Editor reference.
- Collect template-part identity, inferred area, structural summaries, candidate patterns filtered by request `visiblePatternNames` when available, theme tokens, and WordPress docs guidance.
- Rank template-part suggestions with the active provider's responses configuration.
- Return explanatory text plus advisory `blockHints`, `patternSuggestions`, and optional validated `operations`.

This surface remains advisory-first overall: unsupported or ambiguous recommendations stay browse-only, and only validated bounded operations participate in preview/apply/undo.

### Style Recommendations

Global Styles and Style Book recommendations are exposed through `POST /flavor-agent/v1/recommend-style` and the `flavor-agent/recommend-style` ability.

The client behavior is:

- Available only while the Site Editor Styles sidebar is active for the current `root/globalStyles` entity; Style Book additionally requires an active example target block.
- Prefers native Styles sidebar mounts and falls back to `PluginDocumentSettingPanel` shells implemented in `src/global-styles/GlobalStylesRecommender.js` and `src/style-book/StyleBookRecommender.js`.
- Uses the shared `flavor-agent` data store for request state, preview state, apply state, editor-scoped activity hydration, and undo.
- Renders advisory or executable suggestion cards, with preview/apply controls only for validated `set_styles`, `set_block_styles`, and `set_theme_variation` operations.

The server behavior is:

- Resolve the current style-surface scope, current user config, available theme style variations or active Style Book target details, and theme-token source diagnostics.
- Collect theme tokens plus the supported site-level or block-scoped style paths for the active surface.
- Rank Global Styles and Style Book suggestions with the active provider's responses configuration.
- Return explanatory text plus optional validated site-level or block-scoped style operations bounded to supported paths and registered variations.

This surface is explicitly theme-safe: raw CSS, `customCSS`, unsupported style paths, width/height transforms, and pseudo-element-only operations stay out of scope for the first milestone.

### AI Activity and Undo

Applied block, template, template-part, Global Styles, and Style Book suggestions write structured activity records through the server-backed activity repository, keyed to the current post, template, template-part, Global Styles, or Style Book scope. The editor hydrates that log on load and keeps `sessionStorage` only as a fast cache/fallback. Undo remains inline and editor-scoped: the newest valid tail of AI actions can be undone when the live state still matches the recorded post-apply snapshot, while older entries are blocked until newer AI actions are undone.

## Settings

The plugin exposes a Settings API screen at `Settings > Flavor Agent`.

When chat credentials are configured on that screen, Flavor Agent uses the selected provider for pattern, template, template-part, Global Styles, Style Book, and navigation recommendations. If not, block recommendations still fall back to the core `Settings > Connectors` screen through the WordPress AI Client path.
When OpenAI Native is selected, Flavor Agent still owns the chat and embedding model IDs for block/pattern/template/template-part/Global Styles/Style Book/navigation work, but credential resolution prefers a plugin-saved override and otherwise inherits the core OpenAI connector lifecycle: `OPENAI_API_KEY` environment variable, `OPENAI_API_KEY` PHP constant, then the `Settings > Connectors` database value. The OpenAI Native settings copy also tells the user which source is currently effective and whether the core OpenAI connector is registered/configured.

When the Cloudflare AI Search account ID, instance ID, or token changes and all three fields are present, the plugin validates the configured account, instance, and token by running a lightweight probe search that must return trusted `developer.wordpress.org` guidance, and keeps the previous values if validation fails. This allows documented AI Search Run tokens to pass validation without requiring instance metadata read access. Successful saves still use the standard Settings API notice flow, and failed validation surfaces the Cloudflare error on the same screen.

Configured options:

- `flavor_agent_openai_provider`
- `flavor_agent_azure_openai_endpoint`
- `flavor_agent_azure_openai_key`
- `flavor_agent_azure_embedding_deployment`
- `flavor_agent_azure_chat_deployment`
- `flavor_agent_openai_native_api_key`
- `flavor_agent_openai_native_chat_model`
- `flavor_agent_openai_native_embedding_model`
- `flavor_agent_qdrant_url`
- `flavor_agent_qdrant_key`
- `flavor_agent_cloudflare_ai_search_account_id`
- `flavor_agent_cloudflare_ai_search_instance_id`
- `flavor_agent_cloudflare_ai_search_api_token`
- `flavor_agent_cloudflare_ai_search_max_results`

`flavor_agent_openai_native_api_key` is optional once the core OpenAI connector is configured. Flavor Agent still keeps the native chat and embedding model IDs in its own settings either way.

The same screen also includes a `Sync Pattern Catalog` action that calls `POST /flavor-agent/v1/sync-patterns` and reports the current pattern index status.

## Abilities Status

Implemented abilities:

- `flavor-agent/recommend-block`
- `flavor-agent/recommend-content`
- `flavor-agent/introspect-block`
- `flavor-agent/recommend-patterns`
- `flavor-agent/list-patterns`
- `flavor-agent/recommend-template` (accepts optional `visiblePatternNames`)
- `flavor-agent/recommend-template-part`
- `flavor-agent/recommend-style`
- `flavor-agent/list-template-parts`
- `flavor-agent/search-wordpress-docs`
- `flavor-agent/get-theme-tokens`
- `flavor-agent/recommend-navigation`
- `flavor-agent/check-status`

All currently registered abilities in the tree are implemented. On WordPress 7.0 admin screens, core now enqueues `@wordpress/core-abilities`, so these server-registered abilities are also hydrated into the client-side `core/abilities` store automatically. Flavor Agent's first-party editor UI still uses its own REST endpoints and `flavor-agent` data store so prompt scoping, preview/apply, and undo stay tightly bounded.

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
source ~/.nvm/nvm.sh && nvm use
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
- The pattern surface now routes settings-key and DOM-selector differences through `src/patterns/compat.js`, probing future stable APIs first but treating `__experimentalAdditional*`, `__experimental*`, and `__experimentalGetAllowedPatterns` as the current upstream baseline on Gutenberg trunk / WordPress 7.0.
- Theme-token source selection now lives in `src/context/theme-settings.js`, which promotes the stable `features` path only when parity with `__experimentalFeatures` is proven and otherwise passes the experimental source through to `src/context/theme-tokens.js`.
- Flavor Agent now targets WordPress 7.0+, so block attribute role detection reads only the stable `role` key. Compatibility with deprecated `__experimentalRole` is intentionally no longer preserved.
- The repo now treats Node 24 / npm 11 as the default JS toolchain via `.nvmrc`, while `package.json` keeps the previously verified Node 20 / npm 10 toolchain supported under `engine-strict`.
