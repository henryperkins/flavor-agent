# CLAUDE.md — Flavor Agent

WordPress plugin: AI-assisted recommendations across native Gutenberg and wp-admin surfaces, including block Inspector guidance, post/page content drafting and critique, vector-powered pattern recommendations in the inserter, template and template-part composition suggestions in the Site Editor, navigation structure suggestions, Global Styles and Style Book recommendations, and server-backed AI activity history with an admin audit surface.

Entry point: `flavor-agent.php` · Requires WP 7.0+ · PHP 8.0+

## Commands

```bash
npm ci                 # install JS deps reproducibly (Node 24 / npm 11 via .nvmrc; Node 20 / npm 10 also supported)
npm start              # dev build with watch (webpack via @wordpress/scripts)
npm run build          # production build → build/index.js, build/admin.js, build/activity-log.js
npm run lint:js        # ESLint on src/
npm run test:unit -- --runInBand  # Jest unit tests
npm run test:e2e       # Playwright smoke suites (Playground + WP 7.0)
npm run test:e2e:playground  # fast Playground smoke suite
npm run test:e2e:wp70  # Docker-backed WP 7.0 Site Editor suite
npm run verify         # aggregate: build + lint + plugin-check + unit + PHP + E2E → output/verify/summary.json
npm run verify -- --skip=lint-plugin  # omit plugin-check when WP-CLI or WP root is unavailable
npm run verify -- --skip-e2e       # same pipeline without Playwright suites (fast loop)
npm run verify -- --only=build,unit  # run a subset of steps
npm run verify -- --dry-run        # print planned steps as JSON and exit
npm run check:docs     # stale-doc freshness guard
npm run wp:start       # docker compose up; follow docs/reference/local-environment-setup.md for nightly + companion plugins
npm run wp:stop        # docker compose down
npm run wp:reset       # docker compose down -v (destroys volumes)
npm run wp:e2e:wp70:bootstrap  # provision WP 7.0 browser harness
npm run wp:e2e:wp70:teardown   # stop WP 7.0 browser harness

composer install       # install PHP deps (PSR-4 autoloader)
composer lint:php      # WPCS via phpcs
composer test:php      # PHPUnit tests
vendor/bin/phpunit     # PHPUnit tests (direct)
```

PHP tests run via `vendor/bin/phpunit`. JS tests live alongside source files (e.g. `store/update-helpers.test.js`) or in `__tests__/` directories.

### Local WordPress runtime

The representative local WordPress runtime is not a stock stable install. It should run WordPress nightly/trunk and have these active companion plugins before validating editor, Connectors, Abilities, or MCP behavior: `wordpress-beta-tester`, `gutenberg`, `ai`, `ai-services`, `ai-provider-for-openai`, `ai-provider-for-anthropic`, `mcp-adapter`, and `plugin-check`, plus `flavor-agent`. MCP Adapter is installed from `WordPress/mcp-adapter`, not the WordPress.org plugin directory. See `docs/reference/local-environment-setup.md` for the exact setup and Plugin Check environment exports.

### Agent-executable verification

`npm run verify` (implemented in `scripts/verify.js`) is the single entry point for automated verification. It runs `build`, `lint-js`, `lint-plugin`, `unit`, `lint-php`, `test-php`, `e2e-playground`, and `e2e-wp70` in order, streaming output while capturing per-step logs.

Artifacts (all under `output/verify/`, which is gitignored):

- `summary.json` — structured run report (`schemaVersion`, `status` of `pass`/`fail`/`incomplete`, `counts`, per-step `{status, exitCode, durationMs, startedAt, finishedAt, stdoutPath, stderrPath}`, artifact paths changed by the current run, environment)
- `<step>.stdout.log` and `<step>.stderr.log` — full per-step output

The final stdout line is `VERIFY_RESULT={...}` (one-line JSON with `status`, `summaryPath`, `counts`) so agents can parse the outcome without reading the full report. Exit codes: `0` pass, `1` any failure or implicitly skipped step (required tool missing), `2` argument error. A step marked `skipped` via `--only`/`--skip`/`--skip-e2e` never fails the run; a step skipped because its required tool is unavailable flips the overall status to `incomplete` (exit `1`). `lint-plugin` specifically requires `bash`, `wp`, and a resolvable WordPress root for `WP_PLUGIN_CHECK_PATH`; use `--skip=lint-plugin` when those prerequisites are intentionally absent.

### Cross-surface validation gates

For any change that touches more than one recommendation surface or any shared subsystem such as REST or ability contracts, provider routing, freshness signatures, activity and undo, shared UI taxonomy, or operator and admin paths, follow `docs/reference/cross-surface-validation-gates.md`.

Treat the gates there as additive release stops:

- run the nearest targeted PHPUnit and JS suites
- run `node scripts/verify.js --skip-e2e` and inspect `output/verify/summary.json`
- run `npm run check:docs` when contracts, surfacing rules, operator paths, or contributor docs changed
- run the matching Playwright harnesses (`playground` for post-editor, block, pattern, and navigation flows; `wp70` for Site Editor template, template-part, Global Styles, and Style Book flows)
- if a browser harness is known-red or unavailable, record that blocker or an explicit waiver instead of silently skipping it

## Architecture

**PHP backend** (`inc/`, PSR-4 namespace `FlavorAgent\`):

| Namespace                                 | Purpose                                                                                                                                                                |
| ----------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `REST\Agent_Controller`                   | REST routes under `flavor-agent/v1/` (recommend-block, recommend-patterns, recommend-navigation, recommend-template, recommend-template-part, activity, sync-patterns) |
| `Admin\ActivityPage`                      | Registers `Settings > AI Activity` and enqueues the admin audit app                                                                                                    |
| `Activity\Repository`                     | Server-backed AI activity storage, query, create, and ordered undo-state updates                                                                                       |
| `Activity\Permissions`                    | Contextual capability checks for activity queries and mutations                                                                                                        |
| `Activity\Serializer`                     | Activity entry normalization and storage serialization                                                                                                                 |
| `LLM\ChatClient`                          | Chat wrapper that routes recommendation requests through the WordPress AI Client and `Settings > Connectors`                                                           |
| `LLM\WordPressAIClient`                   | Wrapper around the WordPress 7.0 AI client for recommendations via `wp_ai_client_prompt()` and `is_supported_for_text_generation()`                                    |
| `LLM\Prompt`                              | Block recommendation prompt assembly and response parsing                                                                                                              |
| `LLM\TemplatePrompt`                      | Template recommendation prompt assembly and executable operation parsing                                                                                               |
| `LLM\TemplatePartPrompt`                  | Template-part recommendation prompt assembly and response parsing                                                                                                      |
| `LLM\NavigationPrompt`                    | Navigation recommendation prompt assembly and response parsing                                                                                                         |
| `LLM\StylePrompt`                         | Style recommendation prompt assembly, operation parsing, and path validation                                                                                           |
| `LLM\WritingPrompt`                       | Content recommendation prompt assembly (scaffold)                                                                                                                      |
| `Context\ServerCollector`                 | Gathers server-side block, template, template-part, navigation, and theme context                                                                                      |
| `OpenAI\Provider`                         | Embedding provider selection, connector-backed chat pinning, and credential fallback metadata                                                                          |
| `AzureOpenAI\ConfigurationValidator`      | Settings-time backend validation helpers                                                                                                                               |
| `AzureOpenAI\EmbeddingClient`             | Azure OpenAI / OpenAI Native embeddings API                                                                                                                            |
| `AzureOpenAI\QdrantClient`                | Qdrant vector DB for pattern similarity search                                                                                                                         |
| `AzureOpenAI\ResponsesClient`             | Compatibility facade that routes ranking/chat through `WordPressAIClient`                                                                                              |
| `Cloudflare\AISearchClient`               | WordPress developer-doc grounding, cache, and prewarm pipeline                                                                                                         |
| `Patterns\PatternIndex`                   | Embeds registered patterns into Qdrant; syncs on theme/plugin changes                                                                                                  |
| `Abilities\Registration`                  | Registers abilities + category with WordPress Abilities API                                                                                                            |
| `Abilities\BlockAbilities`                | Block recommendation and introspection handlers                                                                                                                        |
| `Abilities\PatternAbilities`              | Pattern listing and vector-powered recommendation handlers                                                                                                             |
| `Abilities\TemplateAbilities`             | Template and template-part composition recommendation handlers                                                                                                         |
| `Abilities\NavigationAbilities`           | Navigation structure recommendation handler                                                                                                                            |
| `Abilities\WordPressDocsAbilities`        | WordPress developer docs search via Cloudflare AI Search                                                                                                               |
| `Abilities\InfraAbilities`                | Theme token extraction and status check handlers                                                                                                                       |
| `Abilities\StyleAbilities`                | Global Styles and Style Book recommendation handlers                                                                                                                   |
| `Abilities\ContentAbilities`              | Content recommendation handlers for the post/page Content Recommendations panel                                                                                        |
| `Abilities\SurfaceCapabilities`           | Shared surface readiness checks and localized capability flag assembly                                                                                                 |
| `Settings`                                | Admin settings page (provider selection, Azure/OpenAI Native/Qdrant/Cloudflare, validation, sync, diagnostics)                                                         |
| `Support\CollectsDocsGuidance`            | Docs-guidance collection trait shared across prompt surfaces                                                                                                           |
| `Support\FormatsDocsGuidance`             | Docs-guidance formatting helpers                                                                                                                                       |
| `Support\MetricsNormalizer`               | Non-negative metric integer normalization for tokens, latency, and byte counts                                                                                         |
| `Support\NormalizesInput`                 | Structured input normalization trait used by abilities and REST handlers                                                                                               |
| `Support\RankingContract`                 | Suggestion contract normalization (score, reason, source signals, freshness meta, safety mode)                                                                         |
| `Support\RecommendationResolvedSignature` | Stable hash of surface + resolved apply context for apply-time freshness checks                                                                                        |
| `Support\RecommendationReviewSignature`   | Stable hash of surface + review context for review-time freshness checks                                                                                               |
| `Support\StringArray`                     | String array sanitization utility                                                                                                                                      |

**JS frontend** (`src/`, built with `@wordpress/scripts`):

| Path                                         | Purpose                                                                                                                                                                 |
| -------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `index.js`                                   | Entry: registers store, session bootstrap, BlockRecommendationsDocumentPanel, Inspector filter, content/pattern/template/template-part/Global Styles/Style Book plugins |
| `components/ActivitySessionBootstrap.js`     | Reloads session-scoped AI activity when the edited entity changes                                                                                                       |
| `components/AIActivitySection.js`            | Shared recent-actions list with per-entry undo affordance                                                                                                               |
| `components/CapabilityNotice.js`             | Backend-unavailable notice used by all eight first-party recommendation surfaces                                                                                        |
| `components/AIStatusNotice.js`               | Contextual status feedback (loading, error, success) used across all surfaces                                                                                           |
| `components/AIAdvisorySection.js`            | Advisory-only suggestion section for non-executable recommendations                                                                                                     |
| `components/AIReviewSection.js`              | Review-before-apply confirmation panel for executable recommendations                                                                                                   |
| `inspector/InspectorInjector.js`             | `editor.BlockEdit` HOC — injects AI panels into all blocks                                                                                                              |
| `inspector/BlockRecommendationsPanel.js`     | Main block prompt composer, grouped lanes, inline apply feedback, and recent-activity shell                                                                             |
| `inspector/NavigationRecommendations.js`     | Embedded advisory panel for selected `core/navigation` blocks                                                                                                           |
| `inspector/SuggestionChips.js`               | Reusable chip component for sub-panel suggestions                                                                                                                       |
| `inspector/block-recommendation-request.js`  | Block request signature builder and freshness derivation used by the main panel and projections                                                                         |
| `inspector/group-by-panel.js`                | Panel-grouping utility for Inspector suggestions                                                                                                                        |
| `inspector/panel-delegation.js`              | Routes Inspector projections between block and navigation surfaces                                                                                                      |
| `inspector/suggestion-keys.js`               | Stable key generation for applied-state tracking                                                                                                                        |
| `inspector/use-suggestion-apply-feedback.js` | Shared apply-feedback hook for chip/card apply state                                                                                                                    |
| `context/block-inspector.js`                 | Client-side block introspection (supports, attributes, styles)                                                                                                          |
| `context/theme-tokens.js`                    | Design token extraction from theme.json + global styles                                                                                                                 |
| `context/collector.js`                       | Combines block + theme + structural context for LLM calls                                                                                                               |
| `store/index.js`                             | `@wordpress/data` store (`flavor-agent`) — recommendations, template apply, undo, activity persistence                                                                  |
| `store/activity-history.js`                  | Session-scoped AI activity schema and storage adapter                                                                                                                   |
| `store/update-helpers.js`                    | Attribute update and undo snapshot helpers                                                                                                                              |
| `store/block-targeting.js`                   | Resolves activity targets by clientId or blockPath for undo                                                                                                             |
| `patterns/PatternRecommender.js`             | Pattern recommendation fetch + local inserter shelf                                                                                                                     |
| `patterns/InserterBadge.js`                  | Inserter toggle badge for recommendation status                                                                                                                         |
| `patterns/compat.js`                         | Stable/experimental pattern API and DOM selector adapter (three-tier: stable, `__experimentalAdditional*`, `__experimental*`)                                           |
| `patterns/find-inserter-search-input.js`     | Re-export wrapper for backward compatibility                                                                                                                            |
| `patterns/recommendation-utils.js`           | Pattern recommendation normalization and badge reason extraction                                                                                                        |
| `patterns/inserter-badge-state.js`           | Badge state machine for recommendation status display                                                                                                                   |
| `patterns/pattern-settings.js`               | Three-tier pattern settings key resolution and pattern read/write                                                                                                       |
| `patterns/inserter-dom.js`                   | Inserter container, search input, and toggle DOM selectors and finders                                                                                                  |
| `templates/TemplateRecommender.js`           | Site Editor template preview/apply/undo panel                                                                                                                           |
| `templates/template-recommender-helpers.js`  | Template UI and operation view-model helpers                                                                                                                            |
| `template-parts/TemplatePartRecommender.js`  | Template-part-scoped AI recommendations panel                                                                                                                           |
| `utils/template-operation-sequence.js`       | Template operation validation and normalization                                                                                                                         |
| `utils/template-actions.js`                  | Deterministic template execution, selection, and undo helpers                                                                                                           |
| `utils/structural-identity.js`               | Block structural role inference and ancestor tracking                                                                                                                   |
| `utils/template-part-areas.js`               | Template-part area resolution from attributes, slug, or registry                                                                                                        |
| `utils/template-types.js`                    | Template slug normalization per pattern templateTypes vocabulary                                                                                                        |
| `utils/pattern-names.js`                     | Extract distinct pattern names from collections                                                                                                                         |
| `utils/visible-patterns.js`                  | Get editor-visible pattern names for current context                                                                                                                    |
| `utils/editor-context-metadata.js`           | Pattern override and viewport visibility summaries for LLM context                                                                                                      |
| `utils/editor-entity-contracts.js`           | Dual-store entity resolution, post-type field definitions, and view contract hook                                                                                       |
| `utils/capability-flags.js`                  | Surface capability flag derivation from localized bootstrap data                                                                                                        |
| `utils/format-count.js`                      | Count formatting, humanization, and CSS class-name join micro-utilities                                                                                                 |
| `admin/settings-page.js`                     | Settings-page admin entrypoint: styles + bootstraps the settings controller                                                                                             |
| `admin/settings-page-controller.js`          | Settings page: sync panel interactions, live status updates, and accordion persistence                                                                                  |
| `admin/activity-log.js`                      | `Settings > AI Activity` DataViews/DataForm audit app                                                                                                                   |
| `admin/activity-log-utils.js`                | Activity-log formatting, filters, summary cards, and admin links                                                                                                        |

**Webpack** has three entry points: `src/index.js` (editor), `src/admin/settings-page.js` (settings page), and `src/admin/activity-log.js` (AI Activity admin page).

## Key Integration Points

- **Inspector injection**: `editor.BlockEdit` filter via `createHigherOrderComponent` + `<InspectorControls group="...">` for each tab (settings, styles, color, typography, dimensions, border).
- **REST API**: All routes live under `flavor-agent/v1/`, registered in `Agent_Controller::register_routes()`. `recommend-block`, `recommend-content`, and `recommend-patterns` use `edit_posts`; `recommend-navigation`, `recommend-style`, `recommend-template`, and `recommend-template-part` use `edit_theme_options`; activity routes use contextual `Activity\Permissions`; and `sync-patterns` uses `manage_options`.
- **Pattern index lifecycle**: Auto-reindexes on theme switch, plugin activation/deactivation, upgrades, and relevant option changes. Uses WP cron event `flavor_agent_reindex_patterns`.
- **Docs grounding lifecycle**: Prewarm and context-warm cron events (`flavor_agent_prewarm_docs`, `flavor_agent_warm_docs_context`) scheduled on activation.
- **Activity history**: Block, template, template-part, Global Styles, and Style Book applies write structured activity entries through the server-backed activity repository; the editor hydrates by scope, keeps `sessionStorage` only as a cache/fallback, and validates live state again before undo.
- **Admin audit UI**: `Settings > AI Activity` is powered by `src/admin/activity-log.js` and reads the same server-backed activity data used by inline editor history.
- **Abilities API**: Hooks into `wp_abilities_api_categories_init` and `wp_abilities_api_init`. Registers 20 abilities across block, pattern, template, navigation, docs, infra, content, and style categories, including design inspection helpers. On WordPress 7.0 admin screens, core also hydrates those server-side abilities into the client-side `@wordpress/core-abilities` store automatically.

## External Services

| Service                       | Options (Settings page)                                                                                                                                                                         |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Embeddings provider selection | `flavor_agent_openai_provider` (`azure_openai`, `openai_native`, or a connector ID that pins chat to that connector while embeddings fall back to a configured direct backend)                  |
| Chat (all surfaces)           | Owned by core `Settings > Connectors` via the WordPress AI Client. No plugin-managed chat credentials.                                                                                          |
| Azure OpenAI (embeddings)     | `flavor_agent_azure_openai_endpoint`, `flavor_agent_azure_openai_key`, `flavor_agent_azure_embedding_deployment`                                                                                |
| OpenAI Native (embeddings)    | `flavor_agent_openai_native_api_key`, `flavor_agent_openai_native_embedding_model`                                                                                                              |
| Qdrant vector DB              | `flavor_agent_qdrant_url`, `flavor_agent_qdrant_key`                                                                                                                                            |
| Cloudflare AI Search          | `flavor_agent_cloudflare_ai_search_account_id`, `flavor_agent_cloudflare_ai_search_instance_id`, `flavor_agent_cloudflare_ai_search_api_token`, `flavor_agent_cloudflare_ai_search_max_results` |

Each recommendation surface disables independently when its required backend is unavailable.

## Gotchas

- `build/` is gitignored — always run `npm run build` before testing in WordPress.
- `.nvmrc` now defaults the JS toolchain to Node `24.x` / npm `11.x`, and `package.json`/`.npmrc` also allow the previously verified Node `20.x` / npm `10.x` toolchain.
- WP-CLI is available in the container (`wp <command> --allow-root` via `docker exec wordpress-wordpress-1`).
- The `@wordpress/data` store name is `flavor-agent` (hyphenated).
- Inspector sub-panel chips use `grid-column: 1 / -1` to span ToolsPanel CSS grid — changing this breaks layout.
- The plugin respects `contentOnly` editing mode: suggestions won't propose changes to locked attributes.
- `vendor/` is gitignored — run `composer install` after cloning (and inside the container) to generate the PSR-4 autoloader.
- The JS global `flavorAgentData` (localized via `wp_localize_script`) exposes `restUrl`, `nonce`, `settingsUrl`, `connectorsUrl`, `canManageFlavorAgentSettings`, the structured `capabilities.surfaces` map, the legacy `canRecommendBlocks` / `canRecommendPatterns` / `canRecommendContent` / `canRecommendTemplates` / `canRecommendTemplateParts` / `canRecommendNavigation` / `canRecommendGlobalStyles` / `canRecommendStyleBook` flags, and `templatePartAreas` to the editor script.
- The JS global `flavorAgentAdmin` (localized on the main settings page) exposes `restUrl` and `nonce` to the settings-page admin script.
- The JS global `flavorAgentActivityLog` (localized on `Settings > AI Activity`) exposes `restUrl`, `nonce`, `adminUrl`, `settingsUrl`, `connectorsUrl`, `defaultPerPage`, `maxPerPage`, `locale`, and `timeZone` to the activity-log app.
- Pattern settings keys and inserter DOM selectors are centralized in `src/patterns/compat.js`; the adapter resolves stable keys first, then `__experimentalAdditional*` override keys, then `__experimental*` base keys. Direct experimental usages remain in `src/context/theme-tokens.js` and `src/context/block-inspector.js` because WordPress has not promoted stable replacements yet.

## Docs

- `docs/README.md` — documentation backbone: reading order, ownership, and update contract
- `docs/SOURCE_OF_TRUTH.md` — definitive project reference: scope, architecture, inventory, roadmap, definition of done
- `docs/FEATURE_SURFACE_MATRIX.md` — fastest map of every shipped surface, gate, and apply/undo path
- `docs/reference/cross-surface-validation-gates.md` — additive release gates and required evidence for multi-surface or shared-subsystem changes
- `docs/reference/wordpress-ai-roadmap-tracking.md` — active conflicts between WordPress org project 240 (the AI Planning & Roadmap board) and Flavor Agent surfaces, with a refresh procedure
- `docs/features/README.md` — entry point for detailed per-surface docs
- `docs/reference/abilities-and-routes.md` — canonical REST and Abilities contract map
- `docs/reference/shared-internals.md` — cross-cutting store utilities, shared UI components, and context helpers
- `docs/flavor-agent-readme.md` — architecture companion and editor-flow reference
- `docs/local-wordpress-ide.md` — local Docker/devcontainer workflow
- `STATUS.md` — working feature inventory and verification log
