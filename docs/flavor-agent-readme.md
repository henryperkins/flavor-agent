# Flavor Agent

Flavor Agent is a WordPress plugin that adds AI-assisted recommendations directly to the native Gutenberg editor and wp-admin surfaces.

This document is the editor-flow reference. For overall doc ownership and reading order, start with `docs/README.md`. For canonical scope and inventory, use `docs/SOURCE_OF_TRUTH.md`. For current verified state, use `STATUS.md`. For exact surface-by-surface conditions, use `docs/FEATURE_SURFACE_MATRIX.md` and the deep dives in `docs/features/`. For the current shared-surface taxonomy and intentional exceptions, use `docs/reference/recommendation-ui-consistency.md`. For release evidence when a change crosses surfaces or shared subsystems, use `docs/reference/cross-surface-validation-gates.md`.

It currently has eight primary editor experiences:

- Block recommendations in the native Inspector, powered by the WordPress AI Client and `Settings > Connectors`.
- Pattern recommendations in the native inserter, powered by plugin-owned Azure OpenAI or OpenAI Native embeddings, Qdrant, and Connectors-owned chat for reranking.
- Content recommendations in the post/page document sidebar for draft, edit, and critique passes.
- Navigation recommendations in the native Inspector for selected `core/navigation` blocks, powered by Connectors-owned chat and scoped as advisory guidance today.
- Template recommendations in the Site Editor, powered by Connectors-owned chat with validated template-part and pattern operations.
- Template-part recommendations in the Site Editor, scoped to individual template parts with a narrow review-confirm-apply path for validated bounded operations.
- Global Styles recommendations in the Site Editor Styles sidebar, bounded to validated `theme.json` paths and theme-backed values.
- Style Book recommendations in the Site Editor Styles sidebar, scoped to per-block style paths and theme-backed values.

It also ships the AI Activity admin audit surface in wp-admin.

There is no separate approval sidebar in the current codebase. The eight surfaces intentionally split into four interaction models: direct apply for safe local block updates, review-before-apply for template/template-part/Global Styles/Style Book, editorial draft/edit/critique for content, and ranking/browse-only for patterns. Navigation is an advisory-only nested subsection inside block recommendations, and delegated block Inspector sub-panels now act only as passive mirrors of the main block result rather than standalone request/apply surfaces. Block, template, template-part, Global Styles, and Style Book applies write activity entries with inline undo; content, pattern, and navigation requests can persist read-only diagnostics when document scope is available. The current UX is still editor-scoped even though persistence now flows through the shared server-backed activity backend, with `sessionStorage` retained only as an editor cache/fallback. The admin AI Activity screen in wp-admin reads the same server-backed activity data.

## Current Architecture

```text
flavor-agent/
├── flavor-agent.php              # Bootstrap, lifecycle hooks, REST + Abilities registration, editor asset enqueue
├── uninstall.php                 # Cleans legacy/provider/vector/docs options, sync lock, and selected cron hooks
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
│   ├── Abilities/                # Block, content, pattern, template, navigation, style, docs, and infra abilities
│   ├── Activity/                 # Server-backed AI activity repository, permissions, and serialization
│   ├── Admin/                    # Settings page and AI Activity admin page registration/assets
│   ├── AzureOpenAI/              # Deployment validation, embeddings, Responses API, and Qdrant clients
│   ├── Cloudflare/               # AI Search grounding + prewarm pipeline
│   ├── Context/                  # Server-side block/theme/pattern/template/navigation collectors
│   ├── Guidelines/               # Core-first and legacy guideline storage adapters
│   ├── LLM/                      # WordPress AI client wrapper + prompt/response handling
│   ├── OpenAI/                   # Provider selection and connector-aware credential resolution
│   ├── Patterns/                 # Pattern index state, sync, fingerprinting, scheduling
│   ├── REST/                     # Editor-facing REST routes
│   ├── Support/                  # Shared sanitization helpers
│   └── Settings.php              # Settings API page, validation, and sync/diagnostics panels
│
├── src/
│   ├── admin/                    # Settings screen and AI Activity admin apps
│   ├── components/               # Shared recommendation, activity, status, and capability UI
│   ├── content/                  # Post/page content recommendation panel
│   ├── context/                  # Editor-side block and theme collectors
│   ├── global-styles/            # Site Editor Global Styles recommender
│   ├── inspector/                # InspectorControls injection, main block panel, and passive native sub-panel mirrors
│   ├── patterns/                 # Inserter recommendation shelf, badge, settings, and DOM compat adapters
│   ├── review/                   # Shape-only future Notes/comment projection adapter
│   ├── store/                    # @wordpress/data store, undo state, and persistence
│   ├── style-book/               # Site Editor Style Book recommender and portal discovery
│   ├── style-surfaces/           # Shared style-surface presentation and request helpers
│   ├── templates/                # Site Editor template recommender + preview/apply helpers
│   ├── template-parts/           # Site Editor template-part recommender
│   ├── test-utils/               # JS unit-test helpers
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

This surface now has three deliberate layers. The main block panel keeps direct apply for safe local attribute changes, broader block ideas render through the shared advisory section, and stale results stay visible with an explicit refresh action instead of silently clearing. Delegated native Inspector sub-panels now mirror the current settings/style result passively instead of acting as separate apply surfaces, and selected `core/navigation` blocks add a nested advisory-only `Recommended Next Changes` flow. Content-only blocks suppress style execution while still allowing contract-valid advisory block ideas to remain visible.

Block attribute role detection now reads the stable `role` key only. Compatibility with deprecated `__experimentalRole` is intentionally no longer preserved on the WordPress 7.0+ support floor.

### Pattern Recommendations

Pattern recommendations are exposed through `POST /flavor-agent/v1/recommend-patterns` and the `flavor-agent/recommend-patterns` ability.

The client behavior is:

- Passive fetch on editor load when the active embedding backend, Qdrant, and Connectors chat are configured.
- Search-triggered refresh when the inserter search box changes.
- A local inserter shelf that only renders ranked patterns currently exposed by Gutenberg's allowed-pattern selector for the active insertion root.
- A toolbar badge that shows recommendation count, loading state, or error state next to the inserter toggle.

The server behavior is:

- Check persisted pattern index runtime state.
- Embed the query with the active plugin-owned embeddings configuration.
- Retrieve candidates from Qdrant using semantic and optional structural search passes.
- Rank candidates with the active Connectors-owned chat runtime.
- Rehydrate registry-owned fields (`title`, `categories`, `content`) from stored payloads.

`visiblePatternNames` is now derived from the active inserter root, so nested insertion contexts only receive patterns WordPress already allows in that specific surface.

This surface is intentionally ranking/browse-only. Flavor Agent reports loading, empty, error, count, and local-shelf state inside the inserter, but it does not create its own review/apply/undo contract for patterns.

### Template Recommendations

Template recommendations are exposed through `POST /flavor-agent/v1/recommend-template` and the `flavor-agent/recommend-template` ability.

The client behavior is:

- Available only while editing a `wp_template` entity in the Site Editor.
- Suggestions use the shared featured-hero plus `Review first` / `Manual ideas` split; the user explicitly opens review and confirms each validated operation set before the template is mutated.
- Supported executable operations are `assign_template_part`, `replace_template_part`, and `insert_pattern`.
- Template candidate patterns are narrowed by current inserter-root `visiblePatternNames` when the editor exposes that context.
- Same-template drift keeps prior results visible as stale reference material and disables review/apply until refresh.

The server behavior is:

- Resolve the active template from the Site Editor reference.
- Collect assigned template-part slots, available template parts, and typed plus generic pattern candidates (filtered by client-side `visiblePatternNames` when provided, before the candidate cap).
- Rank template composition suggestions with the active Connectors-owned chat runtime.
- Validate returned operations, template parts, and pattern names against the collected context before rendering or applying them in the panel.

### Template Part Recommendations

Template-part recommendations are exposed through `POST /flavor-agent/v1/recommend-template-part` and the `flavor-agent/recommend-template-part` ability.

The client behavior is:

- Available only while editing a `wp_template_part` entity in the Site Editor.
- Uses a dedicated `PluginDocumentSettingPanel` implemented in `src/template-parts/TemplatePartRecommender.js`.
- Uses the shared `flavor-agent` data store for request state, preview state, apply state, editor-scoped activity hydration, and undo.
- Uses the shared featured-hero plus `Review first` / `Manual ideas` split, renders advisory links for focus blocks and patterns, and surfaces review/apply controls only for validated executable suggestions.
- Supports validated bounded operations: `insert_pattern`, `replace_block_with_pattern`, and `remove_block`, with `start`, `end`, `before_block_path`, and `after_block_path` placement where applicable.
- Same-scope drift preserves stale results for reference and disables review/apply until refresh.

The server behavior is:

- Resolve the active template part from the Site Editor reference.
- Collect template-part identity, inferred area, structural summaries, candidate patterns filtered by request `visiblePatternNames` when available, theme tokens, and WordPress docs guidance.
- Rank template-part suggestions with the active Connectors-owned chat runtime.
- Return explanatory text plus advisory `blockHints`, `patternSuggestions`, and optional validated `operations`.

This surface remains advisory-first overall: unsupported or ambiguous recommendations stay browse-only, and only validated bounded operations participate in preview/apply/undo.

### Style Recommendations

Global Styles and Style Book recommendations are exposed through `POST /flavor-agent/v1/recommend-style` and the `flavor-agent/recommend-style` ability.

The client behavior is:

- Available only while the Site Editor Styles sidebar is active for the current `root/globalStyles` entity; Style Book additionally requires an active example target block.
- Prefers portal-first native Styles sidebar mounts and falls back to `PluginDocumentSettingPanel` shells implemented in `src/global-styles/GlobalStylesRecommender.js` and `src/style-book/StyleBookRecommender.js`.
- Uses the shared `flavor-agent` data store for request state, preview state, apply state, editor-scoped activity hydration, and undo.
- Uses the shared featured-hero plus `Review first` / `Manual ideas` split, generic `Confirm Apply` review CTA, and scoped stale/refresh treatment. Preview/apply controls only appear for validated `set_styles`, `set_block_styles`, and `set_theme_variation` operations.

The server behavior is:

- Resolve the current style-surface scope, current user config, available theme style variations or active Style Book target details, and theme-token source diagnostics.
- Collect theme tokens plus the supported site-level or block-scoped style paths for the active surface.
- Rank Global Styles and Style Book suggestions with the active Connectors-owned chat runtime.
- Return explanatory text plus optional validated site-level or block-scoped style operations bounded to supported paths and registered variations.

This surface is explicitly theme-safe: raw CSS, `customCSS`, unsupported style paths, width/height transforms, and pseudo-element-only operations stay out of scope for the first milestone.

### AI Activity and Undo

Applied block, template, template-part, Global Styles, and Style Book suggestions write structured activity records through the server-backed activity repository, keyed to the current post, template, template-part, Global Styles, or Style Book scope. The editor hydrates that log on load and keeps `sessionStorage` only as a fast cache/fallback. Undo remains inline and editor-scoped: the newest valid tail of AI actions can be undone when the live state still matches the recorded post-apply snapshot, while older entries are blocked until newer AI actions are undone.

## Settings

The plugin exposes a Settings API screen at `Settings > Flavor Agent`.

Flavor Agent resolves chat through the WordPress AI Client and `Settings > Connectors`. The Azure OpenAI and OpenAI Native fields on this screen now configure plugin-owned embeddings for pattern sync; they no longer provide a direct chat fallback. Selecting a connector-backed provider pins chat to that connector while embeddings fall back to a configured direct Azure/OpenAI Native backend.
When OpenAI Native is selected for embeddings, credential resolution prefers a plugin-saved override and otherwise inherits the core OpenAI connector lifecycle: `OPENAI_API_KEY` environment variable, `OPENAI_API_KEY` PHP constant, then the `Settings > Connectors` database value. The OpenAI Native settings copy reports which source is currently effective.

Flavor Agent now uses a managed public Cloudflare AI Search endpoint for trusted `developer.wordpress.org` grounding, so site owners do not need to enter Cloudflare account, instance, or token values. Legacy Cloudflare credentials remain supported internally for backwards compatibility, and the legacy validation flow still probes trusted `developer.wordpress.org` guidance before accepting changed credentials.

Configured options:

- `flavor_agent_openai_provider`
- `flavor_agent_azure_openai_endpoint`
- `flavor_agent_azure_openai_key`
- `flavor_agent_azure_embedding_deployment`
- `flavor_agent_azure_reasoning_effort`
- `flavor_agent_openai_native_api_key`
- `flavor_agent_openai_native_embedding_model`
- `flavor_agent_qdrant_url`
- `flavor_agent_qdrant_key`
- `flavor_agent_pattern_recommendation_threshold`
- `flavor_agent_pattern_max_recommendations`
- `flavor_agent_cloudflare_ai_search_account_id`
- `flavor_agent_cloudflare_ai_search_instance_id`
- `flavor_agent_cloudflare_ai_search_api_token`
- `flavor_agent_cloudflare_ai_search_max_results`
- `flavor_agent_guideline_site`
- `flavor_agent_guideline_copy`
- `flavor_agent_guideline_images`
- `flavor_agent_guideline_additional`
- `flavor_agent_guideline_blocks`

`flavor_agent_openai_native_api_key` is optional once the core OpenAI connector is configured. Flavor Agent still keeps the native embedding model ID in its own settings either way, but chat always uses the WordPress AI Client and `Settings > Connectors`.

`flavor_agent_azure_reasoning_effort` is a legacy-named setting used as the default reasoning-effort control for Connectors-routed chat requests. The optional Cloudflare account, instance, and token fields are shown only as legacy/custom-endpoint overrides; leaving them blank uses the managed public docs endpoint.

The same screen also includes a `Sync Pattern Catalog` action that calls `POST /flavor-agent/v1/sync-patterns` and refreshes the live sync status panel in place.

## Abilities Status

Implemented abilities:

- `flavor-agent/recommend-block`
- `flavor-agent/recommend-content`
- `flavor-agent/introspect-block`
- `flavor-agent/list-allowed-blocks`
- `flavor-agent/recommend-patterns`
- `flavor-agent/list-patterns`
- `flavor-agent/get-pattern`
- `flavor-agent/list-synced-patterns`
- `flavor-agent/get-synced-pattern`
- `flavor-agent/recommend-template` (accepts optional `visiblePatternNames`)
- `flavor-agent/recommend-template-part`
- `flavor-agent/recommend-style`
- `flavor-agent/list-template-parts`
- `flavor-agent/search-wordpress-docs`
- `flavor-agent/get-active-theme`
- `flavor-agent/get-theme-presets`
- `flavor-agent/get-theme-styles`
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
- Uninstall removes legacy/direct provider, Qdrant, and Cloudflare grounding options, pattern index state, docs warm state/queue, the sync lock, and selected scheduled hooks.

## Development

Install dependencies:

```bash
composer install
source ~/.nvm/nvm.sh && nvm use
npm ci
```

Prepare the representative local WordPress runtime before manual editor or connector testing:

```bash
npm run wp:start
```

`wp:start` only starts the Docker containers. Follow `docs/reference/local-environment-setup.md` to install WordPress nightly/trunk and activate the required companion plugins: WordPress Beta Tester, Gutenberg, AI, AI Services, OpenAI and Anthropic provider connectors, MCP Adapter, Plugin Check, and Flavor Agent.

Build and verify:

```bash
npm run build
npm run lint:js
npm run test:unit -- --runInBand
npm run test:e2e
composer lint:php
vendor/bin/phpunit
```

For cross-surface or shared-subsystem changes, treat `docs/reference/cross-surface-validation-gates.md` as the sign-off checklist: run the nearest targeted suites, `node scripts/verify.js --skip-e2e`, `npm run check:docs` when docs or contracts changed, and the matching Playwright harnesses or record the blocker.

## Compatibility Notes

- Plugin header now targets WordPress 7.0+ and PHP 8.0+.
- The editor-side inserter enhancement uses DOM access for the search input observer and the toolbar badge anchor. It is editor-specific code, not a DOM-free abstraction.
- The pattern surface now routes settings-key and DOM-selector differences through `src/patterns/compat.js`, probing future stable APIs first but treating `__experimentalAdditional*`, `__experimental*`, and `__experimentalGetAllowedPatterns` as the current upstream baseline on Gutenberg trunk / WordPress 7.0.
- Theme-token source selection now lives in `src/context/theme-settings.js`, which promotes the stable `features` path only when parity with `__experimentalFeatures` is proven and otherwise passes the experimental source through to `src/context/theme-tokens.js`.
- Flavor Agent now targets WordPress 7.0+, so block attribute role detection reads only the stable `role` key. Compatibility with deprecated `__experimentalRole` is intentionally no longer preserved.
- The repo now treats Node 24 / npm 11 as the default JS toolchain via `.nvmrc`, while `package.json` keeps the previously verified Node 20 / npm 10 toolchain supported under `engine-strict`.
