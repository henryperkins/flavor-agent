# CLAUDE.md — Flavor Agent

WordPress plugin: AI-assisted recommendations across native Gutenberg and wp-admin surfaces, including block Inspector guidance, post/page content drafting and critique, indexed pattern recommendations in the inserter, template and template-part composition suggestions in the Site Editor, navigation structure suggestions, Global Styles and Style Book recommendations, and server-backed AI activity history with an admin audit surface.

Entry point: `flavor-agent.php` · Requires WP 7.0+ · PHP 8.2+

## MCP Tooling

Use available MCP tools when they can speed up implementation, verification, or research. When the `wordpress-docs-ai-search` MCP server is available, consult it for WordPress plugin, Gutenberg, block editor, theme.json, and current release-cycle decisions; treat results as grounding input and still apply the plugin's trusted-source/currentness rules from `inc/Cloudflare/AISearchClient.php` and `inc/Support/DocsGroundingSourcePolicy.php`.

## Commands

```bash
npm ci                 # install JS deps reproducibly (Node 24 / npm 11 via .nvmrc; Node 20 / npm 10 also supported)
npm start              # dev build with watch (webpack via @wordpress/scripts)
npm run build          # production build → build/index.js, build/admin.js, build/activity-log.js
npm run dist           # release packaging via scripts/build-dist.sh → dist/
npm run lint:js        # ESLint on src/
npm run lint:plugin    # WP Plugin Check (requires bash + wp-cli + WP_PLUGIN_CHECK_PATH)
npm run test:unit -- --runInBand  # Jest unit tests
npm run test:e2e       # Playwright smoke suites (Playground + WP 7.0)
npm run test:e2e:playground  # fast Playground smoke suite
npm run test:e2e:wp70  # Docker-backed WP 7.0 Site Editor suite
npm run verify         # aggregate: build + lint + plugin-check + unit + PHP + E2E → output/verify/summary.json
npm run verify:strict  # verify with --strict (warnings fail the run)
npm run verify -- --skip=lint-plugin  # omit plugin-check when WP-CLI or WP root is unavailable
npm run verify -- --skip-e2e       # same pipeline without Playwright suites (fast loop)
npm run verify -- --only=build,unit  # run a subset of steps
npm run verify -- --dry-run        # print planned steps as JSON and exit
npm run check:docs     # stale-doc freshness guard
npm run ensure:local-env  # pre-flight check for docker compose + .env wiring
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

PHP tests run via `vendor/bin/phpunit`. JS tests live alongside source files (e.g. `src/store/update-helpers.test.js`) or in `__tests__/` directories.

### Local WordPress runtime

The representative runtime is WordPress nightly/trunk with these companion plugins active before validating editor, Connectors, Abilities, or MCP: `wordpress-beta-tester`, `gutenberg`, `ai`, `ai-provider-for-openai`, `ai-provider-for-anthropic`, `ai-provider-for-google`, `mcp-adapter` (installed from `WordPress/mcp-adapter`; the upstream README at v0.5.0 — 2026-04-15 — treats Composer as the primary install method and the plugin form as an alternative, and does not mention WP.org. The 22 Apr 2026 AI contributor summary recorded an intent to add WP.org as the primary distribution, but no WP.org listing is confirmed live as of 2026-06-04 and the README has not been updated. The GitHub clone remains the active local-setup path), `plugin-check`, plus `flavor-agent`. See `docs/reference/local-environment-setup.md` for setup and Plugin Check env exports.

### Agent-executable verification

`npm run verify` (`scripts/verify.js`) is the single entry point for automated verification. It runs `build`, `lint-js`, `lint-plugin`, `unit`, `lint-php`, `test-php`, `e2e-playground`, and `e2e-wp70` in order, streaming output while capturing per-step logs.

Artifacts under `output/verify/` (gitignored): `summary.json` (structured run report with `schemaVersion`, `status` of `pass`/`fail`/`incomplete`, `counts`, per-step `{status, exitCode, durationMs, startedAt, finishedAt, stdoutPath, stderrPath}`, environment) and `<step>.stdout.log` / `<step>.stderr.log`. Final stdout is `VERIFY_RESULT={...}` (one-line JSON with `status`, `summaryPath`, `counts`).

Exit codes: `0` pass, `1` any failure or required-tool-missing skip (status flips to `incomplete`), `2` argument error. `--only` / `--skip` / `--skip-e2e` skips never fail the run. `lint-plugin` requires `bash`, `wp`, and a resolvable `WP_PLUGIN_CHECK_PATH` — use `--skip=lint-plugin` when those are absent.

### Cross-surface validation gates

For any change touching more than one recommendation surface or any shared subsystem (REST/ability contracts, provider routing, freshness signatures, activity/undo, shared UI taxonomy, operator/admin paths), follow `docs/reference/cross-surface-validation-gates.md`. Treat the gates as additive release stops:

- nearest targeted PHPUnit and JS suites
- `node scripts/verify.js --skip-e2e` + inspect `output/verify/summary.json`
- `npm run check:docs` when contracts, surfacing rules, operator paths, or contributor docs change
- matching Playwright harnesses (`playground` = post-editor/block/pattern/navigation; `wp70` = Site Editor template/template-part/Global Styles/Style Book)
- if a harness is known-red or unavailable, record the blocker or an explicit waiver instead of silently skipping

## Architecture

**PHP backend** (`inc/`, PSR-4 namespace `FlavorAgent\`):

- `REST\Agent_Controller` — REST routes under `flavor-agent/v1/` (`activity`, `activity/{id}/undo`, `sync-patterns`). The seven recommendation surfaces are Abilities at `/wp-abilities/v1/abilities/{ability}/run` — see **Key Integration Points** for the capability matrix.
- `Activity\` (`Repository` / `Permissions` / `Serializer`) — server-backed activity storage, contextual capability checks, ordered undo-state updates. `Admin\ActivityPage` registers the `Settings > AI Activity` audit screen.
- `LLM\` — per-surface prompts (`Prompt`, `TemplatePrompt`, `TemplatePartPrompt`, `NavigationPrompt`, `StylePrompt`, `WritingPrompt`), token-budget assembly (`PromptBudget`), strict JSON `ResponseSchema`, WCAG AA `StyleContrastValidator` (4.5), `ThemeTokenFormatter`, `WordPressAIClient` / `ChatClient` wrappers around `wp_ai_client_prompt()` + `Settings > Connectors`.
- `Context\` — server-side context collection: block introspection (`BlockTypeIntrospector`; `shared/support-to-panel.json` asserted in sync with `src/context/block-inspector.js`), theme tokens (`ThemeTokenCollector`, content-hash invalidated), template/template-part/navigation analyzers, pattern catalog + override analyzer + candidate selector (cap 30 via `TEMPLATE_PATTERN_CANDIDATE_CAP`), `PostContentRenderer` + `PostVoiceSampleCollector`, viewport visibility, and operation validators (`BlockOperationValidator`, `BlockRecommendationExecutionContract`).
- `OpenAI\Provider`, `Embeddings\` (Qdrant utilities), `AzureOpenAI\ResponsesClient` (chat compat facade), `Cloudflare\` Workers AI / AI Search clients — chat routing, Workers AI embeddings (plugin-owned, only first-party embedding backend), Qdrant vector storage, AI Search docs grounding, private pattern search, embedding signature cache invalidation.
- `Patterns\PatternIndex` syncs registered + public synced patterns into the selected backend on theme/plugin changes; `Patterns\Retrieval\` provides `PatternRetrievalBackend` plus `Cloudflare AI Search` and `Qdrant + embeddings` impls selected by `PatternRetrievalBackendFactory`.
- `AI\FeatureBootstrap` + `AI\FlavorAgentFeature` register Flavor Agent as a downstream Experiment of the WP AI plugin via `wpai_default_feature_classes`. Targets WP AI plugin `v1.0.0+` behavior (HTTP-layer Connector Approval can block calls — see `inc/Activity/RequestLoggingBridge.php` for the AI Request Logging coexistence path, and `inc/LLM/WordPressAIClient.php` for `wpai_connector_not_approved` handling). `AI\Abilities\Recommend*Ability` declare the seven recommendation abilities (block/content/navigation/patterns/style/template/template-part) extending `RecommendationAbility`. `AI\Abilities\PreviewRecommend*Ability` declare the five read-only preview siblings (block/navigation/style/template/template-part) extending `PreviewRecommendationAbility`; they force `resolveSignatureOnly:true` and strip `clientRequest` before delegating to the parent so Abilities Explorer humans and external MCP clients can dry-run without invoking the AI Connector or writing activity rows. `Abilities\Registration` registers abilities + category; `Abilities\RecommendationAbilityExecution` is the shared executor wiring callbacks to activity/provider/pattern-retrieval; per-surface handlers in `Abilities\{Block,Content,Pattern,Template,Navigation,Style,Infra,WordPressDocs,SurfaceCapabilities}Abilities`.
- `Guidelines` (façade) + `Guidelines\` — `RepositoryResolver` (filterable via `flavor_agent_guidelines_repository`) picks `CoreGuidelinesRepository` (AI plugin's `wp_guideline` CPT + `wp_guideline_type` taxonomy) or `LegacyGuidelinesRepository` (`flavor_agent_guideline_*` options); `PromptGuidelinesFormatter` filters per-ability `GUIDELINE_CATEGORIES` at prompt-build time.
- `Settings` façade + `Admin\Settings\` (`Assets` / `Config` / `Feedback` / `Fields` / `Help` / `Page` / `Registrar` / `State` / `Utils` / `Validation`) — settings page registration, provider-aware sanitization, and live-state pulls from `QdrantClient` / `AISearchClient` / `Guidelines` / `Provider` / `PatternIndex`.
- `Support\` — cross-cutting helpers: signature hashes (`RecommendationSignature` for dedupe, `RecommendationReviewSignature`, `RecommendationResolvedSignature` for apply-time freshness), `NormalizesInput` / `StringArray` / `NonNegativeInteger` / `MetricsNormalizer`, `RankingContract` (score / reason / freshness / safety mode), `RequestTrace` (filterable + `flavor_agent_diagnostic_trace` hook), `CollectsDocsGuidance` / `FormatsDocsGuidance` / `GuidanceExcerpt` (360-char excerpts), `CoreRoadmapGuidance` (WP org project 240 cache), and `WordPressAIPolicy` (text-generation option-key allowlist).

**JS frontend** (`src/`, built with `@wordpress/scripts`):

| Path                                                  | Purpose                                                                                                                                                                 |
| ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `index.js`                                            | Entry: registers store, session bootstrap, BlockRecommendationsDocumentPanel, Inspector filter, content/pattern/template/template-part/Global Styles/Style Book plugins |
| `components/ActivitySessionBootstrap.js`              | Reloads session-scoped AI activity when the edited entity changes                                                                                                       |
| `components/AIActivitySection.js`                     | Shared recent-actions list with per-entry undo affordance                                                                                                               |
| `components/CapabilityNotice.js`                      | Backend-unavailable notice used by all eight first-party recommendation surfaces                                                                                        |
| `components/AIStatusNotice.js`                        | Contextual status feedback (loading, error, success) used across all surfaces                                                                                           |
| `components/AIAdvisorySection.js`                     | Advisory-only suggestion section for non-executable recommendations                                                                                                     |
| `components/AIReviewSection.js`                       | Review-before-apply confirmation panel for executable recommendations                                                                                                   |
| `components/DocsGroundingNotice.js`                   | Warning notice rendered when docs grounding is stale, degraded, or missing current release-cycle coverage                                                               |
| `components/InlineActionFeedback.js`                  | Inline applied-feedback pill with optional secondary action (compact / full variants)                                                                                   |
| `components/LinkedEntityText.js`                      | Inline editor-entity links rendered as Buttons inside surrounding suggestion text                                                                                       |
| `components/RecommendationHero.js`                    | Eyebrow + title + description hero card with primary/secondary action buttons                                                                                           |
| `components/RecommendationLane.js`                    | Tone-tagged grouped lane (title, badge, count, description) for surface suggestion sections                                                                             |
| `components/StaleResultBanner.js`                     | Shared stale-context warning with refresh CTA (inline + full-panel variants)                                                                                            |
| `components/SurfaceComposer.js`                       | Shared prompt textarea + fetch button with submit shortcut (Enter / Cmd+Enter)                                                                                          |
| `components/SurfacePanelIntro.js`                     | Shared eyebrow + meta + intro-copy header for surface panels                                                                                                            |
| `components/SurfaceScopeBar.js`                       | Scope label + freshness indicator + refresh action for current results                                                                                                  |
| `components/ToastRegion.js`                           | Body-portal toast region with iframe-aware `mod+alt+shift+u` focus shortcut                                                                                             |
| `components/UndoToast.js`                             | Presentational undo toast with hover/focus auto-dismiss pause and reduced-motion handling                                                                               |
| `components/surface-labels.js`                        | Shared lane/status labels and tone-pill class-name helper                                                                                                               |
| `inspector/InspectorInjector.js`                      | `editor.BlockEdit` HOC — injects AI panels into all blocks                                                                                                              |
| `inspector/BlockRecommendationsPanel.js`              | Main block prompt composer, grouped lanes, inline apply feedback, and recent-activity shell                                                                             |
| `inspector/NavigationRecommendations.js`              | Embedded advisory panel for selected `core/navigation` blocks                                                                                                           |
| `inspector/SuggestionChips.js`                        | Reusable chip component for sub-panel suggestions                                                                                                                       |
| `inspector/block-recommendation-request.js`           | Block request signature builder and freshness derivation used by the main panel and projections                                                                         |
| `inspector/block-review-state.js`                     | Review-state key builder for block review-before-apply tracking                                                                                                         |
| `inspector/panel-delegation.js`                       | Routes Inspector projections between block and navigation surfaces                                                                                                      |
| `inspector/suggestion-keys.js`                        | Stable key generation for applied-state tracking                                                                                                                        |
| `inspector/use-block-recommendation-request-data.js`  | React hook that resolves live block context + current request signature/input for the block recommendation panel                                                        |
| `inspector/use-suggestion-apply-feedback.js`          | Shared apply-feedback hook for chip/card apply state                                                                                                                    |
| `context/block-inspector.js`                          | Client-side block introspection (supports, attributes, styles)                                                                                                          |
| `context/theme-settings.js`                           | Theme settings reader (stable + experimental keys, pseudo-classes, default spacing units)                                                                               |
| `context/theme-tokens.js`                             | Design token extraction from theme.json + global styles                                                                                                                 |
| `context/collector.js`                                | Combines block + theme + structural context for LLM calls                                                                                                               |
| `store/index.js`                                      | `@wordpress/data` store (`flavor-agent`) — recommendations, template apply, undo, activity persistence                                                                  |
| `store/abilities-client.js`                           | REST client for `/wp-abilities/v1/abilities/{name}/run` with payload normalization                                                                                      |
| `store/activity-history.js`                           | Session-scoped AI activity schema and storage adapter                                                                                                                   |
| `store/activity-session.js`                           | Server-backed activity hydration and scope-key resolution per editor session                                                                                            |
| `store/activity-undo.js`                              | Cross-surface undo orchestration (block / template / template-part / global-styles / style-book)                                                                        |
| `store/block-targeting.js`                            | Resolves activity targets by clientId or blockPath for undo                                                                                                             |
| `store/executable-surface-runtime.js`                 | Executable-surface request identity + thunk runtime shared by template/style surfaces                                                                                   |
| `store/executable-surfaces.js`                        | Per-surface fetch/apply/review-freshness thunk wiring for template, template-part, global-styles, and style-book                                                        |
| `store/recommendation-outcomes.js`                    | Recommendation outcome decoration, dedupe, and diagnostic activity-entry builder (`shown`, `selected_for_review`, `stale_blocked`, etc.)                                |
| `store/request-error-details.js`                      | Request-error normalization with connector-approval (`wpai_connector_not_approved`) detection                                                                           |
| `store/toasts.js`                                     | Undo-toast slice: queue, eviction policy (cap 3, skip oldest interacted), surface→title mapping                                                                         |
| `store/undo-toast-action.js`                          | Toast-driven undo dispatcher: invokes `undoActivity`, dismisses on success, swaps to error patch on failure                                                             |
| `store/update-helpers.js`                             | Attribute update and undo snapshot helpers                                                                                                                              |
| `patterns/PatternRecommender.js`                      | Pattern recommendation fetch + local inserter shelf                                                                                                                     |
| `patterns/InserterBadge.js`                           | Inserter toggle badge for recommendation status                                                                                                                         |
| `patterns/compat.js`                                  | Stable/experimental pattern API and DOM selector adapter (three-tier: stable, `__experimentalAdditional*`, `__experimental*`)                                           |
| `patterns/pattern-insertability.js`                   | Pattern→blocks resolver (synced ref, raw blocks array, content HTML via `rawHandler`)                                                                                   |
| `patterns/recommendation-utils.js`                    | Pattern recommendation normalization and badge reason extraction                                                                                                        |
| `patterns/inserter-badge-state.js`                    | Badge state machine for recommendation status display                                                                                                                   |
| `patterns/pattern-settings.js`                        | Three-tier pattern settings key resolution and pattern read/write                                                                                                       |
| `patterns/inserter-dom.js`                            | Inserter container, search input, and toggle DOM selectors and finders                                                                                                  |
| `content/ContentRecommender.js`                       | Post/page Content Recommendations panel — draft / edit / critique modes with starter prompts                                                                            |
| `content/content-recommendation-request.js`           | Freshness derivation for content recommendations (mode + prompt + post-context request signature)                                                                       |
| `templates/TemplateRecommender.js`                    | Site Editor template preview/apply/undo panel                                                                                                                           |
| `templates/template-recommender-helpers.js`           | Template UI and operation view-model helpers                                                                                                                            |
| `template-parts/TemplatePartRecommender.js`           | Template-part-scoped AI recommendations panel                                                                                                                           |
| `template-parts/template-part-recommender-helpers.js` | Template-part view-model and operation helpers (attribute summarization, context signature)                                                                             |
| `global-styles/selectors.js`                          | Global Styles selector adapter; stable selectors first, documented experimental fallbacks only inside this boundary                                                     |
| `global-styles/GlobalStylesRecommender.js`            | Site Editor Global Styles preview/apply panel sharing executable-surface infrastructure                                                                                 |
| `style-book/StyleBookRecommender.js`                  | Style Book preview/apply panel mounted into the Site Editor Styles sidebar                                                                                              |
| `style-book/dom.js`                                   | Style Book DOM finders (sidebar mount node, iframe locator)                                                                                                             |
| `style-surfaces/presentation.js`                      | Shared style-operation formatting (path, preset slug extraction) used by Global Styles + Style Book                                                                     |
| `style-surfaces/request-input.js`                     | Shared style-scope builder for Global Styles + Style Book recommendation requests                                                                                       |
| `utils/template-operation-sequence.js`                | Template operation validation and normalization                                                                                                                         |
| `utils/template-actions.js`                           | Deterministic template execution, selection, and undo helpers                                                                                                           |
| `utils/structural-identity.js`                        | Block structural role inference and ancestor tracking                                                                                                                   |
| `utils/structural-equality.js`                        | Stable JSON serialization with sorted keys for deep equality comparisons                                                                                                |
| `utils/live-structure-snapshots.js`                   | Live editor block introspection (inner blocks, attribute summary, nested stats)                                                                                         |
| `utils/template-part-areas.js`                        | Template-part area resolution from attributes, slug, or registry                                                                                                        |
| `utils/template-types.js`                             | Template slug normalization per pattern templateTypes vocabulary                                                                                                        |
| `utils/pattern-names.js`                              | Extract distinct pattern names from collections                                                                                                                         |
| `utils/visible-patterns.js`                           | Get editor-visible pattern names for current context                                                                                                                    |
| `utils/editor-context-metadata.js`                    | Pattern override and viewport visibility summaries for LLM context                                                                                                      |
| `utils/editor-entity-contracts.js`                    | Dual-store entity resolution, post-type field definitions, and view contract hook                                                                                       |
| `utils/capability-flags.js`                           | Surface capability flag derivation from localized bootstrap data                                                                                                        |
| `utils/format-count.js`                               | Count formatting, humanization, and CSS class-name join micro-utilities                                                                                                 |
| `utils/activity-title.js`                             | Activity title truncation with word-boundary preservation                                                                                                               |
| `utils/context-signature.js`                          | Stable context signature wrapper around `stableSerialize` for freshness tracking                                                                                        |
| `utils/block-operation-catalog.js`                    | Block operation type/action constants, error codes, and sequence validators                                                                                             |
| `utils/block-allowed-pattern-context.js`              | Pattern allowlist resolver for block surface (sources, signature)                                                                                                       |
| `utils/block-execution-contract.js`                   | Block style-panel allowlist for client/server execution contract sanitization                                                                                           |
| `utils/block-recommendation-context.js`               | Block structural-summary caps aligned with PHP schema (sibling, branch, depth)                                                                                          |
| `utils/block-structural-actions.js`                   | Apply / undo for block insert-pattern and replace-block-with-pattern operations                                                                                         |
| `utils/recommendation-actionability.js`               | Actionability tier classifier (`inline-safe` / `review-safe` / `advisory`) with reason codes                                                                            |
| `utils/recommendation-design-semantics.js`            | Design-semantics normalization (section role, density, contrast, rhythm) for block/template/template-part prompts                                                       |
| `utils/recommendation-request-signature.js`           | Per-surface request signature builders for executable-surface freshness checks                                                                                          |
| `utils/recommendation-stale-reasons.js`               | Effective stale-reason resolver across client / review / server signals                                                                                                 |
| `utils/docs-grounding-warning.js`                     | Docs-grounding warning normalizer + user-facing message resolver consumed by `DocsGroundingNotice`                                                                      |
| `utils/style-design-semantics.js`                     | Style operation semantics for prompt context (path, identity, viewport visibility)                                                                                      |
| `utils/style-operations.js`                           | Apply / undo for global-styles operations via `core/edit-site` and `core` data stores                                                                                   |
| `utils/style-support-paths.js`                        | Style support-path alias normalization (e.g. `typography.__experimentalFontFamily` → `typography.fontFamily`)                                                           |
| `utils/style-validation.js`                           | CSS unit / key sanitization and free-form style-value validation                                                                                                        |
| `utils/type-guards.js`                                | Shared `isPlainObject` type guard                                                                                                                                       |
| `test-utils/setup-react-test.js`                      | React DOM test harness (container/root lifecycle, `IS_REACT_ACT_ENVIRONMENT`)                                                                                          |
| `test-utils/wp-components.js`                         | `@wordpress/components` mock factory for Jest unit tests                                                                                                                |
| `admin/settings-page.js`                              | Settings-page admin entrypoint: styles + bootstraps the settings controller                                                                                             |
| `admin/settings-page-controller.js`                   | Settings page: sync panel interactions, live status updates, and accordion persistence                                                                                  |
| `admin/activity-log.js`                               | `Settings > AI Activity` DataViews audit app with custom read-only details                                                                                              |
| `admin/activity-log-utils.js`                         | Activity-log formatting, filters, summary cards, and admin links                                                                                                        |

**Webpack** has three entry points: `src/index.js` (editor), `src/admin/settings-page.js` (settings page), and `src/admin/activity-log.js` (AI Activity admin page).

## Key Integration Points

- **Inspector injection**: `editor.BlockEdit` filter via `createHigherOrderComponent` + `<InspectorControls group="...">` for each tab (settings, styles, color, typography, dimensions, border).
- **Recommendation transport**: The seven recommendation surfaces are Abilities (not REST routes), registered via `Abilities\Registration::register_recommendation_abilities()` and reachable at `POST /wp-abilities/v1/abilities/{ability}/run` or via `@wordpress/abilities` (see `src/store/abilities-client.js` + `assets/abilities-bridge.js`). Concrete classes in `inc/AI/Abilities/Recommend*Ability.php` extend `RecommendationAbility`. Capability matrix from per-class `CAPABILITY` constant, enforced in `RecommendationAbility::permission_callback()`; escalates to `current_user_can( 'edit_post', $post_id )` when post ID is extractable:
  - `RecommendBlockAbility`, `RecommendContentAbility`, `RecommendPatternsAbility` → `edit_posts`
  - `RecommendNavigationAbility`, `RecommendStyleAbility`, `RecommendTemplateAbility`, `RecommendTemplatePartAbility` → `edit_theme_options`
- **REST API**: Remaining REST routes live under `flavor-agent/v1/`, registered in `Agent_Controller::register_routes()`. `activity` (GET/POST) and `activity/{id}/undo` (POST) use contextual `Activity\Permissions::can_access_activity_request()`; `sync-patterns` (POST) uses `manage_options`.
- **Pattern index lifecycle**: Auto-reindexes on theme switch, plugin activation/deactivation, upgrades, and relevant option changes. Uses WP cron event `flavor_agent_reindex_patterns`.
- **Docs grounding lifecycle**: Prewarm checks run on activation/init but public-endpoint prewarm only schedules when the `flavor_agent_cloudflare_ai_search_allow_public_prewarm` filter opts in; context-warm cron (`flavor_agent_warm_docs_context`) is queued after user-triggered cache misses.
- **Activity & admin audit**: Block/template/template-part/Global Styles/Style Book applies write to the server-backed activity repository; editor hydrates by scope, keeps `sessionStorage` as cache/fallback, re-validates live state before undo. `Settings > AI Activity` (`src/admin/activity-log.js`) reads the same data.
- **Abilities API**: 25 abilities across block, pattern, template, navigation, docs, infra, content, and style categories, wired via `wp_abilities_api_categories_init` + `wp_abilities_api_init`. Helper/read abilities and the five `preview-recommend-*` siblings register whenever the Abilities API and AI plugin contracts are available (independent of the Flavor Agent feature gate, so operators can verify wiring before flipping it); the seven recommendation abilities also require the Flavor Agent feature gate. The nine externally-useful read helpers and all five preview siblings declare `meta.mcp.public = true` so the universal MCP default server (when mcp-adapter is installed) surfaces them via `discover-abilities` / `execute-ability`; the three editor-internal helpers (`list-synced-patterns`, `get-synced-pattern`, `check-status`) stay Abilities-API-only. On WP 7.0 admin screens, core hydrates server-side abilities into the `@wordpress/core-abilities` store. The canonical AI plugin's `Tools > Abilities Explorer` Experiment is the local harness for click-to-run testing — see `docs/reference/local-environment-setup.md`.

## External Services

| Service                                      | Options (Settings page)                                                                                                                                                                                                                                                 |
| -------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Provider compatibility option                 | `flavor_agent_openai_provider` is legacy-only. Settings canonicalize it to `cloudflare_workers_ai`; other saved values don't select chat or embeddings.                                                                                                                  |
| Chat (all surfaces)                           | Owned by core `Settings > Connectors` via the WordPress AI Client. No plugin-managed chat credentials.                                                                                                                                                                  |
| Cloudflare Workers AI (embeddings only)       | `flavor_agent_cloudflare_workers_ai_account_id`, `flavor_agent_cloudflare_workers_ai_api_token`, `flavor_agent_cloudflare_workers_ai_embedding_model`                                                                                                                   |
| Qdrant vector DB                             | `flavor_agent_qdrant_url`, `flavor_agent_qdrant_key`                                                                                                                                                                                                                    |
| Private Cloudflare AI Search pattern backend | `flavor_agent_pattern_retrieval_backend`, `flavor_agent_cloudflare_pattern_ai_search_instance_id`; account/token/model come from the Cloudflare Workers AI Embedding Model settings                                                                                   |
| Cloudflare AI Search docs grounding          | Built-in public endpoint plus `flavor_agent_cloudflare_ai_search_max_results`; no site-owner Cloudflare credential fields                                                                                                                                            |

Each recommendation surface disables independently when its required backend is unavailable.

## Gotchas

- `build/` is gitignored — always run `npm run build` before testing in WordPress.
- `.nvmrc` defaults to Node `24.x` / npm `11.x`; `package.json`/`.npmrc` also allow Node `20.x` / npm `10.x`.
- WP-CLI is available in the container (`wp <command> --allow-root` via `docker exec wordpress-wordpress-1`).
- The `@wordpress/data` store name is `flavor-agent` (hyphenated).
- Inspector sub-panel chips use `grid-column: 1 / -1` to span ToolsPanel CSS grid — changing this breaks layout.
- The plugin respects `contentOnly` editing mode: suggestions won't propose changes to locked attributes.
- `vendor/` is gitignored — run `composer install` after cloning (and inside the container) to generate the PSR-4 autoloader.
- Localized JS globals (via `wp_localize_script`):
  - `flavorAgentData` (editor) → `restUrl`, `nonce`, `settingsUrl`, `connectorsUrl`, `canManageFlavorAgentSettings`, structured `capabilities.surfaces`, legacy per-surface `canRecommend*` flags (Blocks/Patterns/Content/Templates/TemplateParts/Navigation/GlobalStyles/StyleBook), `templatePartAreas`
  - `flavorAgentAdmin` (settings page) → `restUrl`, `nonce`
  - `flavorAgentActivityLog` (Settings > AI Activity) → `restUrl`, `nonce`, `adminUrl`, `settingsUrl`, `connectorsUrl`, `defaultPerPage`, `maxPerPage`, `locale`, `timeZone`
- Pattern settings keys and inserter DOM selectors are centralized in `src/patterns/compat.js`; the adapter resolves stable keys first, then `__experimentalAdditional*` override keys, then `__experimental*` base keys. Direct experimental usages remain in `src/context/theme-tokens.js`, `src/context/block-inspector.js`, and `src/global-styles/selectors.js` because WordPress has not promoted stable replacements yet.
- Plugin self-registers as a downstream Experiment of the WP AI plugin via `wpai_default_feature_classes` (`flavor-agent.php:30` + `inc/AI/FeatureBootstrap.php`). Editor scripts gate on `FeatureBootstrap::editor_runtime_available()`; when missing, scripts don't enqueue and an admin notice explains. Concrete abilities in `inc/AI/Abilities/Recommend*Ability.php` bind to callbacks in `inc/Abilities/{Block,Content,Navigation,Pattern,Style,Template}Abilities.php`.

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
- `docs/reference/local-environment-setup.md` — local Docker/devcontainer workflow and image pinning
- `STATUS.md` — working feature inventory and verification log
