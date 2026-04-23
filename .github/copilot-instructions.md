# Flavor Agent Copilot Instructions

WordPress plugin: AI-assisted recommendations across native Gutenberg and wp-admin surfaces, including block Inspector guidance, vector-powered pattern recommendations in the inserter, template and template-part composition suggestions in the Site Editor, navigation structure suggestions, Global Styles and Style Book recommendations, and server-backed AI activity history with an admin audit surface.

Entry point: `flavor-agent.php` · Requires WP 7.0+ · PHP 8.0+

## Build, lint, and test commands

```bash
# Dependencies (Node 24 / npm 11 default via .nvmrc; Node 20 / npm 10 also supported)
source ~/.nvm/nvm.sh && nvm use
npm ci
composer install

# Build
npm start              # dev build with watch
npm run build          # production → build/index.js, build/admin.js, build/activity-log.js

# Lint
npm run lint:js
composer lint:php      # WPCS via phpcs

# Unit tests
npm run test:unit -- --runInBand
vendor/bin/phpunit

# Single JS test
npm run test:unit -- src/store/update-helpers.test.js --runInBand

# Single PHP test file or method
vendor/bin/phpunit tests/phpunit/AgentControllerTest.php
vendor/bin/phpunit --filter test_method_name tests/phpunit/AgentControllerTest.php

# E2E tests
npm run test:e2e                # all suites
npm run test:e2e:playground     # fast Playground smoke suite
npm run test:e2e:wp70           # Docker-backed WP 7.0 Site Editor suite
npm run check:docs              # stale-doc freshness guard

# Aggregate verification (single entry point for automated runs)
npm run verify                  # build + lint + plugin-check + unit + PHP + E2E → output/verify/summary.json
npm run verify -- --skip=lint-plugin  # omit plugin-check when WP-CLI or WP root is unavailable
npm run verify -- --skip-e2e    # fast loop without Playwright suites
npm run verify -- --only=build,unit   # run a subset
npm run verify -- --dry-run     # list planned steps as JSON

# Local Docker environment
npm run wp:start       # docker compose up
npm run wp:stop        # docker compose down
npm run wp:reset       # docker compose down -v (destroys volumes)
```

`build/` and `vendor/` are gitignored. Run `npm run build` before testing changes in WordPress.

For any change that touches more than one recommendation surface or any shared subsystem such as REST or ability contracts, provider routing, freshness signatures, activity and undo, shared UI taxonomy, or operator and admin paths, follow `docs/reference/cross-surface-validation-gates.md`. Treat those gates as additive release stops: run the nearest targeted PHPUnit and JS suites, run `node scripts/verify.js --skip-e2e`, run `npm run check:docs` when contracts or contributor docs changed, and run the matching Playwright harnesses. If a browser harness is known-red or unavailable, record that blocker or an explicit waiver instead of silently skipping it.

## High-level architecture

`flavor-agent.php` is the runtime bootstrap. It wires editor asset enqueueing, REST routes, the settings page, Abilities API registration, pattern-index lifecycle hooks, and docs-grounding cron. It localizes three JS globals: `flavorAgentData` (editor — REST URL, nonce, settings/connectors URLs, `canManageFlavorAgentSettings`, structured surface capabilities, `canRecommendBlocks`, `canRecommendPatterns`, `canRecommendContent`, `canRecommendTemplates`, `canRecommendTemplateParts`, `canRecommendNavigation`, `canRecommendGlobalStyles`, `canRecommendStyleBook`, and template-part areas), `flavorAgentAdmin` (settings page), and `flavorAgentActivityLog` (AI Activity admin page — REST URL, nonce, admin URLs, `defaultPerPage`, `maxPerPage`, `locale`, and `timeZone`).

`webpack.config.js` has three entry points: `src/index.js` (editor), `src/admin/settings-page.js` (settings page), and `src/admin/activity-log.js` (AI Activity admin page). `src/index.js` is the client composition root: it self-registers the `flavor-agent` `@wordpress/data` store, installs the `editor.BlockEdit` filter, and registers `ActivitySessionBootstrap`, `BlockRecommendationsDocumentPanel`, the pattern recommender, inserter badge, template recommender, template-part recommender, Global Styles recommender, and Style Book recommender plugins.

### Recommendation surfaces

**Block recommendations** are a cross-layer flow. `src/context/collector.js` combines block introspection, theme token summaries, sibling context, and structural context. The store thunk posts that snapshot to `/flavor-agent/v1/recommend-block`. `inc/REST/Agent_Controller.php` is intentionally thin and delegates to ability handlers, so real backend behavior lives in `inc/Abilities/*`, `inc/Context/ServerCollector.php`, and the prompt/client classes under `inc/LLM/`.

**Pattern recommendations** are a separate pipeline. `src/patterns/PatternRecommender.js` does an initial fetch plus debounced refreshes, always passing `visiblePatternNames` for the current inserter root. Server-side, patterns are embedded, searched in Qdrant, reranked with Azure Responses, then returned. The client patches `__experimentalBlockPatterns` so recommended items appear in the native inserter under the `recommended` category.

**Template recommendations** are Site Editor-only. `src/templates/TemplateRecommender.js` activates only for `wp_template` entities and is advisory rather than auto-applying. **Template-part recommendations** (`src/template-parts/TemplatePartRecommender.js`) are scoped to individual template-part blocks. **Navigation recommendations** suggest structure for navigation blocks.

**Pattern indexing** is its own subsystem. `inc/Patterns/PatternIndex.php` keeps runtime state in the `flavor_agent_pattern_index_state` option, computes fingerprints, and uses a lock plus cooldown around sync work. Syncs trigger on activation, theme/plugin changes, backend-setting changes, cron (`flavor_agent_reindex_patterns`), or the admin sync button.

**Activity history** writes structured entries through the server-backed `Activity\Repository`; the editor hydrates by scope, keeps `sessionStorage` only as a cache/fallback, and validates live state before undo. The admin audit UI at `Settings > AI Activity` reads the same data.

### Abilities API integration

The plugin registers 13 abilities across block, pattern, template, navigation, docs, infra, content, and style categories via `wp_abilities_api_categories_init` and `wp_abilities_api_init`. On WP 7.0 admin screens, core auto-hydrates server-side abilities into the client-side `@wordpress/core-abilities` store.

### REST routes

All routes live under `flavor-agent/v1/`: `recommend-block`, `recommend-content`, `recommend-patterns`, `recommend-navigation`, `recommend-template`, `recommend-template-part`, `recommend-style`, `activity`, and `sync-patterns`. Block/pattern/content routes require `edit_posts`, navigation/template/style routes require `edit_theme_options`, activity uses contextual permissions, and sync requires `manage_options`.

### LLM provider architecture

`LLM\ChatClient` prefers plugin-managed providers (Azure OpenAI or OpenAI Native, selected via `flavor_agent_openai_provider`) and falls back to the WordPress 7.0 AI Client (`wp_ai_client_prompt()`). Block text-generation providers are configured separately in core under `Settings > Connectors`. The settings page manages Azure OpenAI, OpenAI Native, Qdrant, Cloudflare AI Search, and pattern sync. Each recommendation surface disables independently when its required backend is unavailable.

## Key conventions

The `@wordpress/data` store name is `flavor-agent`. Block recommendation state is keyed by block `clientId`, and block fetches use monotonically increasing request tokens so stale responses do not overwrite newer ones.

Keep `inc/REST/Agent_Controller.php` thin. New behavior should be implemented in an ability class plus shared helpers, then exposed through REST as a thin adapter so REST and Abilities API behavior stay aligned.

Respect editing restrictions from `src/store/update-helpers.js`. `contentOnly` and `disabled` are enforced in client logic; content-restricted blocks only allow content-attribute updates, and some content-only blocks that expose content only through inner blocks should receive no direct attribute updates at all.

When suggestion application changes block attributes, preserve the nested-merge behavior in `buildSafeAttributeUpdates()`. `metadata` and `style` updates are merged, not replaced wholesale.

`ServerCollector` and the client collector have different jobs. `inc/Context/ServerCollector.php` maps block supports to Inspector panels and gathers server-visible data, while `src/context/collector.js` adds editor-only structural and theme context. Keep their `SUPPORT_TO_PANEL` mappings synchronized. Changes to the recommendation payload often need coordinated edits in both layers.

Pattern recommendations patch editor settings non-destructively. `src/patterns/recommendation-utils.js` preserves original pattern `description`, `keywords`, and `categories` in a module-level map so the inserter overlay can be reverted cleanly. Do not mutate the registry payload in place without preserving rollback behavior.

Pattern settings keys and inserter DOM selectors are centralized in `src/patterns/compat.js`. The adapter resolves stable keys first, then `__experimentalAdditional*` override keys, then `__experimental*` base keys.

`visiblePatternNames` should come from the current inserter root. That scoping is important for pattern recommendations, while the template recommender intentionally stays advisory and broader in scope.

Settings sanitization has an established pattern: Azure, Qdrant, and Cloudflare validation only runs when submitted values actually change, validation results are deduplicated within a single save request via fingerprinted static state, and failed validation keeps the previously saved value while surfacing a Settings API error.

PHP unit tests do not boot a real WordPress environment. `tests/phpunit/bootstrap.php` provides a stub harness for core functions and classes. JS unit tests live next to source as `*.test.js` files or in `__tests__/` directories.

Inspector sub-panel suggestion chips rely on the existing ToolsPanel grid layout. The CSS `grid-column: 1 / -1` span behavior for those chips is intentional and easy to break if the layout is refactored casually.

`.nvmrc` defaults the JS toolchain to Node 24.x / npm 11.x, while `package.json` and `.npmrc` also keep the previously verified Node 20.x / npm 10.x toolchain supported under `engine-strict`.

## Documentation

- `docs/SOURCE_OF_TRUTH.md` — definitive project reference
- `docs/FEATURE_SURFACE_MATRIX.md` — fastest map of every shipped surface, gate, and apply/undo path
- `docs/reference/cross-surface-validation-gates.md` — additive release gates and required evidence for multi-surface or shared-subsystem changes
- `docs/reference/abilities-and-routes.md` — canonical REST and Abilities contract map
- `STATUS.md` — working feature inventory and verification log
