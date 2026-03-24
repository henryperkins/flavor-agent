# Flavor Agent - Status

> Last updated: 2026-03-24

## Working

### Abilities API (WordPress 7.0+)

| Ability | Handler | Description |
| --- | --- | --- |
| `flavor-agent/recommend-block` | `BlockAbilities` | Block recommendation pipeline using `ServerCollector`, `Prompt`, and the WordPress AI Client |
| `flavor-agent/introspect-block` | `BlockAbilities` | Block type registry introspection |
| `flavor-agent/recommend-patterns` | `PatternAbilities` | Azure OpenAI embeddings + Qdrant retrieval + LLM reranking |
| `flavor-agent/recommend-template` | `TemplateAbilities` | Azure OpenAI template composition suggestions for Site Editor templates |
| `flavor-agent/list-patterns` | `PatternAbilities` | Pattern registry listing with filters |
| `flavor-agent/list-template-parts` | `TemplateAbilities` | Template part listing with optional area filter |
| `flavor-agent/search-wordpress-docs` | `WordPressDocsAbilities` | Official WordPress developer-doc grounding search backed by Cloudflare AI Search |
| `flavor-agent/get-theme-tokens` | `InfraAbilities` | Theme preset and global style token extraction |
| `flavor-agent/recommend-navigation` | `NavigationAbilities` | Navigation structure, overlay behavior, and organization recommendations |
| `flavor-agent/check-status` | `InfraAbilities` | Backend inventory plus current-user ability availability status |

### REST API

| Route | Permission | Description |
| --- | --- | --- |
| `POST /flavor-agent/v1/recommend-block` | `edit_posts` | Block recommendations from client-provided editor context |
| `POST /flavor-agent/v1/recommend-patterns` | `edit_posts` | Pattern recommendations for the inserter |
| `POST /flavor-agent/v1/recommend-template` | `edit_theme_options` | Template composition recommendations for the Site Editor |
| `POST /flavor-agent/v1/sync-patterns` | `manage_options` | Manual pattern index sync |

### Editor UI

- Inspector sidebar recommendation panel for selected, editable blocks with per-block loading and error state
- Content-only blocks keep the panel but only allow content-safe suggestions, and disabled blocks do not render AI controls
- Pattern inserter integration with a `Recommended` category, toolbar badge for high-confidence matches, and root-aware allowed-pattern scoping; pattern API access and DOM discovery are centralized through `src/patterns/compat.js` so all experimental/stable transitions are handled in one place
- Site Editor template recommendation panel for `wp_template` documents with review-confirm-apply support for validated template-part assignment/replacement and pattern insertion operations
- Block and template apply flows now capture structured AI activity records, expose inline `Undo`, and render a minimal `Recent AI Actions` session history in the active panel
- AI activity history is session-durable per post/template via `sessionStorage`; template undo now persists stable locators plus recorded post-apply snapshots, so same-session refreshes stay undoable when the live template still matches the recorded state, while legacy clientId-only template entries load as undo unavailable
- Admin settings screen with Azure/Qdrant/Cloudflare configuration and pattern sync controls; block providers come from `Settings > Connectors`
- Settings saves now surface the standard Settings API success notice plus plugin-scoped Azure, Qdrant, and Cloudflare validation errors
- WordPress docs grounding only accepts chunks sourced from `developer.wordpress.org`
- Azure OpenAI credentials are revalidated only when the endpoint, key, or deployments change and all four fields are present; both the embeddings and responses deployments must validate before new values are saved
- Qdrant credentials are revalidated only when the URL or key changes and both fields are present; the configured `/collections` endpoint must return the expected payload before new values are saved
- Cloudflare AI Search credentials are revalidated only when the account ID, instance ID, or token changes; the new credentials must pass a lightweight probe search returning trusted `developer.wordpress.org` guidance before they are saved, which keeps the settings flow compatible with documented AI Search Run tokens
- Recommendation-time WordPress docs grounding remains cache-only and non-blocking; exact-query cache is authoritative and warmed block/template entity cache is only a fallback
- Explicit `flavor-agent/search-wordpress-docs` requests always seed the exact-query cache and only seed entity cache when a valid `entityKey` or legacy query inference resolves
- Docs grounding prewarm: on plugin activation and successful Cloudflare credential changes, an async WP-Cron job seeds the entity cache for 16 high-frequency entities (8 core blocks, 7 template types, core/navigation) using the same trust-filtered Cloudflare search pipeline; throttled by credential fingerprint + 1-hour cooldown; admin diagnostics panel shows last prewarm status, timestamp, and warmed/failed counts

## Known Issues

- `composer lint:php` is now green across `flavor-agent.php`, `inc/`, `tests/phpunit`, and `uninstall.php`, but `tests/phpunit/bootstrap.php` remains intentionally excluded because the multi-namespace stub harness is not a realistic WPCS target without a dedicated refactor.
- JS tooling now expects Node `20.x` with npm `10.x`; on this host, the global Node `24.14.0` / npm `11.9.0` pair fails `npm ci` immediately via `engine-strict` (`EBADENGINE`), so the repo now pins the supported toolchain instead of assuming the global default.
- Browser smoke coverage now runs on a stable WordPress `6.9.4` Playground harness and covers block apply-plus-undo with same-session refresh persistence, the pattern inserter search/request badge flow, and the Site Editor template preview-apply-undo flow. A WordPress `7.0` Playground run does boot the post editor, but the Site Editor path currently crashes the Playground runtime with `Invalid state: Controller is already closed`, so the checked-in harness remains on `6.9.4`.
- The Site Editor Playground harness still crashes when the browser revisits the template canvas route in the same session, so the new refresh/drift template Playwright cases are checked in as `fixme`; the underlying refresh-safe and drift-safe undo logic is covered by unit tests and manual same-session browser flow remains green.
- AI activity history is still session-scoped only; there is no server-backed audit log or cross-session review UI yet.
- Live recommendation execution with valid LLM credentials was not rerun in this pass.
- Pattern surface now uses a central compatibility adapter (`src/patterns/compat.js`) that prefers stable APIs, falls back to `__experimentalAdditional*` then `__experimental*` variants, and degrades cleanly when none exist. DOM inserter selectors are also centralized there. `__experimentalFeatures` (theme-tokens) and `__experimentalRole` (block-inspector) remain in their original files since they have no stable replacement yet.

## Recent Verification

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
- 2026-03-23 remediation: `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e -- --reporter=line` passed (`3` tests passed, `2` `fixme` skips for Site Editor refresh/drift harness gaps).
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

- **`docs/SOURCE_OF_TRUTH.md`** -- Definitive project reference: scope, architecture, inventory, roadmap, definition of done
- **`docs/flavor-agent-readme.md`** -- Architecture details and editor flow reference
- **`docs/local-wordpress-ide.md`** -- Local Docker/devcontainer workflow and daily development setup
- **`docs/NEXT_STEPS_PLAN.md`** -- Execution-plan snapshot from the 2026-03-23 repo review; several early phases are now implemented in the tree
- **`docs/wordpress-7.0-gutenberg-22.8-reference.md`** -- WordPress 7.0 / Gutenberg 22.8 compatibility reference
- **`docs/2026-03-18-cloudflare-ai-search-grounding-assessment.md`** -- Cloudflare AI Search integration assessment

Historical docs (superseded early designs) have been moved to `docs/historical/`.
