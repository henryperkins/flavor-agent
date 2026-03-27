# Flavor Agent - Status

> Last updated: 2026-03-26

## Working

### Abilities API (WordPress 7.0+)

| Ability | Handler | Description |
| --- | --- | --- |
| `flavor-agent/recommend-block` | `BlockAbilities` | Block recommendation pipeline using `ServerCollector`, `Prompt`, and the WordPress AI Client |
| `flavor-agent/introspect-block` | `BlockAbilities` | Block type registry introspection |
| `flavor-agent/recommend-patterns` | `PatternAbilities` | Provider-selected embeddings + Qdrant retrieval + LLM reranking |
| `flavor-agent/recommend-template` | `TemplateAbilities` | Provider-selected template composition suggestions for Site Editor templates |
| `flavor-agent/recommend-template-part` | `TemplateAbilities` | Template-part composition suggestions with validated bounded composition operations for Site Editor template parts |
| `flavor-agent/list-patterns` | `PatternAbilities` | Pattern registry listing with filters |
| `flavor-agent/list-template-parts` | `TemplateAbilities` | Template part listing with optional area filter |
| `flavor-agent/search-wordpress-docs` | `WordPressDocsAbilities` | Official WordPress developer-doc grounding search backed by Cloudflare AI Search |
| `flavor-agent/get-theme-tokens` | `InfraAbilities` | Theme preset and global style token extraction |
| `flavor-agent/recommend-navigation` | `NavigationAbilities` | Navigation structure, overlay behavior, and organization recommendations |
| `flavor-agent/check-status` | `InfraAbilities` | Backend inventory, OpenAI Native credential metadata, and current-user ability availability status |

### REST API

| Route | Permission | Description |
| --- | --- | --- |
| `POST /flavor-agent/v1/recommend-block` | `edit_posts` | Block recommendations from client-provided editor context |
| `POST /flavor-agent/v1/recommend-patterns` | `edit_posts` | Pattern recommendations for the inserter |
| `POST /flavor-agent/v1/recommend-navigation` | `edit_theme_options` | Advisory navigation recommendations for selected `core/navigation` blocks |
| `POST /flavor-agent/v1/recommend-template` | `edit_theme_options` | Template composition recommendations for the Site Editor |
| `POST /flavor-agent/v1/recommend-template-part` | `edit_theme_options` | Template-part composition recommendations for the Site Editor |
| `GET/POST /flavor-agent/v1/activity` | contextual editor/theme capability; sitewide GET requires `manage_options` | Activity query and persistence for AI actions |
| `POST /flavor-agent/v1/activity/{id}/undo` | contextual editor/theme capability | Persisted undo status transition for an activity entry |
| `POST /flavor-agent/v1/sync-patterns` | `manage_options` | Manual pattern index sync |

### Editor UI

- Inspector sidebar recommendation panel for selected, editable blocks with per-block loading and error state
- Content-only blocks keep the panel but only allow content-safe suggestions, and disabled blocks do not render AI controls
- Pattern inserter integration with a `Recommended` category, toolbar badge for high-confidence matches, and root-aware allowed-pattern scoping; pattern API access and DOM discovery are centralized through `src/patterns/compat.js` so all experimental/stable transitions are handled in one place
- Pattern recommendation and indexing backends now have direct PHPUnit coverage for backend gating, runtime-state handling, Qdrant retrieval/reranking, fingerprinting, scheduling, full/incremental sync, deletion, lock contention, and remote failure persistence
- Site Editor template recommendation panel for `wp_template` documents with review-confirm-apply support for validated template-part assignment/replacement and pattern insertion operations
- Site Editor template-part recommendation panel for `wp_template_part` documents with advisory block-focus links, pattern-browse links, and review-confirm-apply support for validated bounded operations: `insert_pattern`, `replace_block_with_pattern`, and `remove_block`, with start/end and before/after-block-path placement where applicable
- Inspector-panel navigation recommendations for selected `core/navigation` blocks with advisory structure, overlay, and accessibility guidance
- Block, template, and template-part apply flows now capture structured AI activity records, expose inline `Undo`, and render a minimal editor-scoped `Recent AI Actions` history in the active panel
- AI activity now persists through the server-backed activity repository and is hydrated back into editor-scoped history, while template and template-part undo still rely on stable locators plus recorded post-apply snapshots; legacy clientId-only template entries load as undo unavailable
- Admin settings screen with provider selection, Azure OpenAI / OpenAI Native, Qdrant, and Cloudflare AI Search configuration plus pattern sync controls; block providers still come from `Settings > Connectors`, and the OpenAI Native section now reports the effective credential source plus core OpenAI connector registration/configuration state
- Settings saves now surface the standard Settings API success notice plus plugin-scoped Azure, Qdrant, and Cloudflare validation errors
- WordPress docs grounding only accepts chunks sourced from `developer.wordpress.org`
- Azure OpenAI credentials are revalidated only when the endpoint, key, or deployments change and all four fields are present; both the embeddings and responses deployments must validate before new values are saved
- Qdrant credentials are revalidated only when the URL or key changes and both fields are present; the configured `/collections` endpoint must return the expected payload before new values are saved
- Cloudflare AI Search credentials are revalidated only when the account ID, instance ID, or token changes; the new credentials must pass a lightweight probe search returning trusted `developer.wordpress.org` guidance before they are saved, which keeps the settings flow compatible with documented AI Search Run tokens
- Recommendation-time WordPress docs grounding remains cache-only and non-blocking; exact-query cache is authoritative and warmed block/template entity cache is only a fallback
- Explicit `flavor-agent/search-wordpress-docs` requests always seed the exact-query cache and only seed entity cache when a valid `entityKey` or legacy query inference resolves
- Docs grounding prewarm: on plugin activation and successful Cloudflare credential changes, an async WP-Cron job seeds the entity cache for 16 high-frequency entities (8 core blocks, 7 template types, core/navigation) using the same trust-filtered Cloudflare search pipeline; exact entity misses now also fall back to prewarmed generic guidance families for template, template-part, and editor guidance before returning empty; throttled by credential fingerprint + 1-hour cooldown; admin diagnostics panel shows last prewarm status, timestamp, and warmed/failed counts

### Admin UI

- Settings > `AI Activity` now opens a dedicated wp-admin audit page for recent server-backed Flavor Agent actions across block, template, and template-part surfaces
- The admin page uses WordPress `DataViews` with the `activity` layout as the default feed, plus persisted/resettable view preferences and grouped summary cards for recorded, applied, undone, and review-needed activity
- A read-only `DataForm` details panel surfaces stored request metadata, ordered undo status, before/after summaries, and quick links back to the affected entity, plugin settings, and core Connectors when available
- Sitewide activity queries now flow through the same REST route and repository, but only `manage_options` users can access unscoped/global reads

## Known Issues

- `composer lint:php` is now green across `flavor-agent.php`, `inc/`, `tests/phpunit`, and `uninstall.php`, but `tests/phpunit/bootstrap.php` remains intentionally excluded because the multi-namespace stub harness is not a realistic WPCS target without a dedicated refactor.
- JS tooling now expects Node `20.x` with npm `10.x`; on this host, the global Node `24.14.0` / npm `11.9.0` pair fails `npm ci` immediately via `engine-strict` (`EBADENGINE`), so the repo now pins the supported toolchain instead of assuming the global default.
- Browser coverage is intentionally split by harness: `npm run test:e2e:playground` stays on the stable WordPress `6.9.4` Playground smoke path for quick post-editor coverage, while `npm run test:e2e:wp70` provisions a dedicated Docker-backed WordPress `7.0` Site Editor stack plus repo-local block theme fixture for refresh/drift-sensitive flows that Playground cannot hold open reliably. The default `npm run test:e2e` command now aggregates both harnesses, and the checked-in smoke suite now covers block, navigation, pattern, template, and `wp_template_part` surfaces. The remaining operational prerequisite is Docker on PATH for the WP 7.0 half.
- WordPress `7.0` is still pre-release as of 2026-03-26, with general release scheduled for 2026-04-09. The Docker-backed Site Editor harness still pins `wordpress:beta-7.0-beta4-php8.2-apache`; swap that override once the official stable image exists.
- `recommend-navigation` now has a first-party inspector surface, plugin REST route, and checked-in browser smoke, but it remains advisory-only. There is still no validated navigation apply contract.
- AI activity now has a first admin audit screen in wp-admin, but the feature is still not a full observability product: there is no diff-oriented inspection view, no abilities-backed row-action layer yet, and no cross-device/operator workflows beyond the recent-activity timeline.
- Live recommendation execution with valid LLM credentials was not rerun in this pass.

## Open Backlog

- Decide whether navigation should remain advisory-only or grow a bounded apply contract, while keeping the UX native to the Inspector and Site Editor.
- Deepen the new admin activity page with richer diagnostics, before/after inspection, and a cleaner action/discovery layer rather than treating audit visibility as greenfield work.
- Rerun live provider-backed recommendation execution with valid credentials to refresh end-to-end verification on the active provider path.
- Swap the Docker-backed WP 7.0 browser harness from the beta image to the official stable `7.0` image once it exists, and keep Docker available in environments that run that harness.
- Interactivity API runtime work is explicitly future-facing, not part of the current remediation backlog, because the shipped plugin is still editor/admin only and has no front-end runtime surface that needs it.

## Recent Verification

- 2026-03-26 template-part-followthrough: `vendor/bin/phpunit --filter "(TemplatePartPromptTest|RegistrationTest|AISearchClientTest)"` passed (`33` tests, `178` assertions).
- 2026-03-26 template-part-followthrough: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/utils/__tests__/template-actions.test.js src/patterns/__tests__/compat.test.js src/context/__tests__/theme-tokens.test.js src/inspector/__tests__/NavigationRecommendations.test.js src/template-parts` passed (`4` suites, `78` tests).
- 2026-03-26 template-part-followthrough: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run build` passed.
- 2026-03-26 template-part-followthrough: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:playground -- --reporter=line` passed (`4` Playwright smoke tests: block apply/persist/undo, navigation advisory recommendations, pattern inserter flow, template preview/apply/undo).
- 2026-03-26 template-part-followthrough: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70 -- --reporter=line -g "template-part surface smoke"` could not run on this host because `docker` was not found on `PATH`.
- 2026-03-26 activity-admin-log: `vendor/bin/phpunit --filter "(ActivityRepositoryTest|AgentControllerTest|ActivityPermissionsTest)"` passed (`25` tests, `96` assertions).
- 2026-03-26 activity-admin-log: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npx wp-scripts lint-js src/admin/activity-log.js src/admin/activity-log-utils.js src/admin/__tests__/activity-log-utils.test.js` passed.
- 2026-03-26 activity-admin-log: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js` passed (`1` suite, `3` tests).
- 2026-03-26 activity-admin-log: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run build` passed.
- 2026-03-25 connectors-alignment: `vendor/bin/phpcs inc/OpenAI/Provider.php inc/Abilities/InfraAbilities.php inc/Settings.php tests/phpunit/bootstrap.php tests/phpunit/InfraAbilitiesTest.php tests/phpunit/SettingsTest.php` passed.
- 2026-03-25 connectors-alignment: `vendor/bin/phpunit --filter "(SettingsTest|InfraAbilitiesTest)"` passed (`26` tests, `160` assertions).
- 2026-03-24 docs-freshness: `vendor/bin/phpunit` passed (`179` tests, `910` assertions).
- 2026-03-24 docs-freshness: `npm run test:unit -- --runInBand` passed (`26` suites, `179` tests).
- 2026-03-24 plan-5: `npx wp-scripts lint-js src/context/theme-settings.js src/context/theme-tokens.js src/patterns/pattern-settings.js src/patterns/inserter-dom.js src/patterns/compat.js src/patterns/PatternRecommender.js src/patterns/InserterBadge.js src/context/__tests__/theme-tokens.test.js src/patterns/__tests__/compat.test.js src/patterns/__tests__/PatternRecommender.test.js src/patterns/__tests__/InserterBadge.test.js` passed.
- 2026-03-24 plan-5: `npm run test:unit -- --runInBand src/context/__tests__/theme-tokens.test.js src/patterns/__tests__/compat.test.js src/patterns/__tests__/PatternRecommender.test.js src/patterns/__tests__/InserterBadge.test.js` passed (`4` suites, `49` tests).
- 2026-03-24 plan-5: `npm run test:unit -- --runInBand` passed (`23` suites, `169` tests).
- 2026-03-24 template-part-executor: `vendor/bin/phpunit` passed (`149` tests, `767` assertions).
- 2026-03-24 template-part-executor: `npm run test:unit -- --runInBand` passed (`20` suites, `153` tests).
- 2026-03-24 plan-2: `vendor/bin/phpunit --filter Pattern` passed (`28` tests, `170` assertions).
- 2026-03-24 plan-2: `vendor/bin/phpunit` passed (`171` tests, `872` assertions).
- 2026-03-24 docs-alignment: `vendor/bin/phpunit` passed (`146` tests, `757` assertions).
- 2026-03-24 docs-alignment: `npm run test:unit -- --runInBand` passed (`19` suites, `143` tests).
- 2026-03-23 compat: `npm run lint:js` passed (after `--fix`).
- 2026-03-23 compat: `npm run test:unit -- --runInBand` passed (`19` suites, `115` tests).
- 2026-03-23 compat: `composer lint:php` passed.
- 2026-03-23 prewarm: `vendor/bin/phpunit` passed (`106` tests, `526` assertions).
- 2026-03-23 remediation: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm ci` passed (`1849` packages added; peer/deprecation warnings only).
- 2026-03-23 remediation: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run lint:js` passed.
- 2026-03-23 remediation: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand` passed (`18` suites, `81` tests).
- 2026-03-23 remediation: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run build` passed.
- 2026-03-23 remediation: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e -- --reporter=line` passed (`3` Playwright smoke tests: block apply/persist/undo, pattern badge flow, template preview/apply/undo).
- 2026-03-23 remediation: `vendor/bin/phpunit` passed (`117` tests, `588` assertions).
- 2026-03-23 remediation: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand` passed (`19` suites, `130` tests).
- 2026-03-23 remediation: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e -- --reporter=line` passed on the then-current single-harness setup (`3` tests passed; the WP 7.0 Site Editor refresh/drift coverage was still skipped on that date before the separate `npm run test:e2e:wp70` harness landed).
- 2026-03-23 exploratory: `PLAYWRIGHT_PORT=9403 npm run test:e2e -- --reporter=line` against a manual WordPress `7.0` Playground server passed the two post-editor smokes, then failed on `/wp-admin/site-editor.php` when the Playground runtime crashed (`Invalid state: Controller is already closed` -> `ERR_CONNECTION_REFUSED`).
- 2026-03-23 remediation: `vendor/bin/phpunit` passed (`87` tests, `392` assertions).
- 2026-03-23 remediation: `composer lint:php` passed.
- 2026-03-19 remediation: `npm run lint:js` passed.
- 2026-03-19 remediation: `npm run test:unit -- --runInBand` passed.
- 2026-03-19 remediation: `npm run build` passed.
- 2026-03-19 remediation: `vendor/bin/phpunit` passed (`51` tests, `231` assertions).
- 2026-03-19 remediation: `vendor/bin/phpcs --standard=phpcs.xml.dist inc/Cloudflare/AISearchClient.php inc/Settings.php tests/phpunit/AISearchClientTest.php tests/phpunit/SettingsTest.php` passed.
- 2026-03-19 remediation: `vendor/bin/phpcs --standard=phpcs.xml.dist flavor-agent.php inc/Abilities/BlockAbilities.php inc/Abilities/InfraAbilities.php inc/Abilities/Registration.php inc/Context/ServerCollector.php inc/LLM/Prompt.php inc/LLM/WordPressAIClient.php inc/Settings.php uninstall.php tests/phpunit/AgentControllerTest.php tests/phpunit/InfraAbilitiesTest.php tests/phpunit/PromptGuidanceTest.php tests/phpunit/RegistrationTest.php tests/phpunit/ServerCollectorTest.php` passed.

## Documentation

- **`docs/README.md`** -- Documentation entry point: purpose, reading order, ownership, and update contract
- **`docs/SOURCE_OF_TRUTH.md`** -- Definitive project reference: scope, architecture, inventory, roadmap, definition of done
- **`docs/FEATURE_SURFACE_MATRIX.md`** -- Fast matrix for every shipped surface: location, surfacing conditions, gating, and apply/undo support
- **`docs/features/README.md`** -- Entry point for the detailed surface docs in `docs/features/`
- **`docs/reference/abilities-and-routes.md`** -- Canonical mapping of Abilities API contracts, REST routes, permissions, and first-party callers
- **`docs/flavor-agent-readme.md`** -- Architecture details and editor-flow companion reference
- **`docs/2026-03-25-roadmap-aligned-execution-plan.md`** -- Active forward plan aligned to WordPress 7.0, Gutenberg, and official AI plugin roadmaps
- **`docs/local-wordpress-ide.md`** -- Local Docker/devcontainer workflow and daily development setup
- **`docs/wordpress-7.0-gutenberg-22.8-reference.md`** -- WordPress 7.0 / Gutenberg 22.8 reference snapshot for compatibility and API research
- **`docs/wordpress-7.0-developer-docs-index.md`** -- Discovery snapshot of official WordPress 7.0 developer documentation sources
- **`docs/wp7-migration-opportunities.md`** -- Point-in-time WordPress 7.0 migration assessment; useful for follow-up ideas, not the live backlog
