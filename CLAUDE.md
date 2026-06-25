# CLAUDE.md — Flavor Agent

WordPress plugin: a governance layer for AI changes to a live site — schema-bounded operations, review-gated structural changes, server-side attribution and audit, reversible applies with freshness/drift checks (see `docs/reference/governance-layer.md`) — demonstrated through AI-assisted recommendations across native Gutenberg and wp-admin surfaces: block Inspector guidance, post/page content drafting and critique, indexed pattern recommendations in the inserter, template and template-part composition suggestions in the Site Editor, navigation structure suggestions, Global Styles and Style Book recommendations, and server-backed AI activity history with an admin audit surface.

Entry point: `flavor-agent.php` · Requires WP 7.0+ · PHP 8.2+

## MCP Tooling

Use available MCP tools when they can speed up implementation, verification, or research. When the `wordpress-docs-ai-search` MCP server is available, consult it for Gutenberg, block editor, REST API, theme/theme.json, code-reference, Developer Blog, and current release-cycle decisions covered by the managed corpus; do not treat it as complete Plugin Handbook coverage unless the runbook's corpus scopes include that source. Treat results as grounding input. Trust and currency of the corpus are owned by `scripts/update-docs-ai-search.js` at ingestion time; the runtime (`inc/Cloudflare/AISearchClient.php`, `inc/Support/DocsGroundingSourcePolicy.php`) only applies structural URL hygiene and non-gating source labels.

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
wp flavor-agent attestation verify att_xxx  # verify a stored Ring III attestation from the site runtime
php tools/attestation-verify.php https://site.example att_xxx  # verify the public REST/JWKS envelope externally
```

PHP tests run via `vendor/bin/phpunit`. JS tests live alongside source files (e.g. `src/store/update-helpers.test.js`) or in `__tests__/` directories.

### Local WordPress runtime

The representative runtime is WordPress nightly/trunk with these companion plugins active before validating editor, Connectors, Abilities, or MCP: `wordpress-beta-tester`, `gutenberg`, `ai`, `ai-provider-for-openai`, `ai-provider-for-anthropic`, `ai-provider-for-google`, `mcp-adapter` (installed from `WordPress/mcp-adapter`; the upstream README at v0.5.0 — 2026-04-15 — treats Composer as the primary install method and the plugin form as an alternative, and does not mention WP.org. The 22 Apr 2026 AI contributor summary recorded an intent to add WP.org as the primary distribution, but no WP.org listing is confirmed live as of 2026-06-04 and the README has not been updated. The GitHub clone remains the active local-setup path), `plugin-check`, plus `flavor-agent`. See `docs/reference/local-environment-setup.md` for setup and Plugin Check env exports.

### Agent-executable verification

`npm run verify` (`scripts/verify.js`) is the single entry point for automated verification. It runs `build`, `lint-js`, `lint-plugin`, `unit`, `lint-php`, `test-php`, `e2e-playground`, and `e2e-wp70` in order, streaming output while capturing per-step logs.

Artifacts under `output/verify/` (gitignored): `summary.json` (structured run report with `schemaVersion`, `status` of `pass`/`fail`/`incomplete`, `counts`, per-step `{status, exitCode, durationMs, startedAt, finishedAt, stdoutPath, stderrPath}`, environment) and `<step>.stdout.log` / `<step>.stderr.log`. Final stdout is `VERIFY_RESULT={...}` (one-line JSON with `status`, `summaryPath`, `counts`).

Exit codes: `0` pass, `1` any failure or required-tool-missing skip (status flips to `incomplete`), `2` argument error. `--only` / `--skip` / `--skip-e2e` skips never fail the run. `lint-plugin` requires `bash` plus either host WP-CLI (`wp` + a resolvable `WP_PLUGIN_CHECK_PATH`) or the Docker path (`PLUGIN_CHECK_USE_DOCKER=1` with the compose `wordpress` container running; no host `wp` needed) — use `--skip=lint-plugin` when neither is available.

### Cross-surface validation gates

For any change touching more than one recommendation surface or any shared subsystem (REST/ability contracts, provider routing, freshness signatures, activity/undo, shared UI taxonomy, operator/admin paths), follow `docs/reference/cross-surface-validation-gates.md`. Treat the gates as additive release stops:

- nearest targeted PHPUnit and JS suites
- `node scripts/verify.js --skip-e2e` + inspect `output/verify/summary.json`
- `npm run check:docs` when contracts, surfacing rules, operator paths, or contributor docs change
- matching Playwright harnesses (`playground` = post-editor/block/pattern/navigation; `wp70` = Site Editor template/template-part/Global Styles/Style Book)
- if a harness is known-red or unavailable, record the blocker or an explicit waiver instead of silently skipping

## Architecture

**PHP backend** (`inc/`, PSR-4 namespace `FlavorAgent\`) — thirteen namespaces spanning `REST\` routes, `Activity\` + `Attestation\` storage, `CLI\`, per-surface `LLM\` prompts, server-side `Context\` collection, the provider/embeddings clients (`OpenAI\`, `Embeddings\`, `AzureOpenAI\`, `Cloudflare\`), `Patterns\` indexing/retrieval, `AI\` feature + Abilities registration, `Guidelines\`, governed `Apply\`, `Settings`, and cross-cutting `Support\` helpers. See [docs/reference/php-backend-architecture.md](docs/reference/php-backend-architecture.md) for the namespace-by-namespace map.

**JS frontend** (`src/`, built with `@wordpress/scripts`) — the editor entry (`index.js`), shared `components/`, Inspector injection (`inspector/`), client-side `context/` collection, the `flavor-agent` `store/`, per-surface panels (`patterns/`, `content/`, `templates/`, `template-parts/`, `global-styles/`, `style-book/`, `style-surfaces/`), `utils/` helpers, `test-utils/`, and the `admin/` settings + AI Activity apps. See [docs/reference/js-frontend-architecture.md](docs/reference/js-frontend-architecture.md) for the full per-module table.

**Webpack** has three entry points: `src/index.js` (editor), `src/admin/settings-page.js` (settings page), and `src/admin/activity-log.js` (AI Activity admin page).

## Key Integration Points

- **Inspector injection**: `editor.BlockEdit` filter via `createHigherOrderComponent` + `<InspectorControls group="...">` for each tab (settings, styles, color, typography, dimensions, border).
- **Recommendation transport**: The seven recommendation surfaces are Abilities (not REST routes), registered via `Abilities\Registration::register_recommendation_abilities()` and reachable at `POST /wp-abilities/v1/abilities/{ability}/run` or via `@wordpress/abilities` (see `src/store/abilities-client.js` + `assets/abilities-bridge.js`). Concrete classes in `inc/AI/Abilities/Recommend*Ability.php` extend `RecommendationAbility`. Capability matrix from per-class `CAPABILITY` constant, enforced in `RecommendationAbility::permission_callback()`; escalates to `current_user_can( 'edit_post', $post_id )` when post ID is extractable:
  - `RecommendBlockAbility`, `RecommendContentAbility`, `RecommendPatternsAbility` → `edit_posts`
  - `RecommendNavigationAbility`, `RecommendStyleAbility`, `RecommendTemplateAbility`, `RecommendTemplatePartAbility` → `edit_theme_options`
- **REST API**: Remaining REST routes live under `flavor-agent/v1/`, registered in `Agent_Controller::register_routes()`. `activity` (GET/POST) and `activity/{id}/undo` (POST) use contextual `Activity\Permissions::can_access_activity_request()`; `activity/{id}/decision` (POST, external-apply approval) uses `manage_options` plus the row's mutation capability via `Activity\Permissions::can_decide_activity_request()`; `sync-patterns` (POST) uses `manage_options`.
- **Ring III attestation**: Attestation REST routes live under `flavor-agent/v1/attestations`: `attestations/{id}` returns the byte-exact signed envelope, `attestations/keys` returns the JWKS, and `attestations/{id}/subject-state` returns the live canonical subject slice. Verification must trust the signed statement bytes, not the decoded convenience view or route-reported digest.
- **Pattern index lifecycle**: Auto-reindexes on theme switch, plugin activation/deactivation, upgrades, and relevant option changes. Uses WP cron event `flavor_agent_reindex_patterns`.
- **Docs grounding lifecycle**: Best-effort only — each recommendation runs one cached corpus search (`AISearchClient::maybe_search_best_effort`, 6h query cache); a transport failure attaches no guidance and records an `ok`/`unreachable` signal in `flavor_agent_docs_runtime_state`. Grounding never blocks a recommendation; there are no warm/prewarm crons (activation and deactivation both clear the legacy `flavor_agent_prewarm_docs` / `flavor_agent_warm_docs_context` hooks by name for upgrading sites).
- **Activity & admin approval/audit**: Block/template/template-part/Global Styles/Style Book applies write to the server-backed activity repository; editor hydrates by scope, keeps `sessionStorage` as cache/fallback, re-validates live state before undo. `Settings > AI Activity` (`src/admin/activity-log.js`) reads the same data and approves/rejects pending external style applies.
- **Abilities API**: 30 abilities across block, pattern, template, navigation, docs, infra, content, style, and apply categories, wired via `wp_abilities_api_categories_init` + `wp_abilities_api_init`. Helper/read abilities and the five `preview-recommend-*` siblings register whenever the Abilities API and AI plugin contracts are available (independent of the Flavor Agent feature gate, so operators can verify wiring before flipping it); the seven recommendation abilities also require the Flavor Agent feature gate. The ten externally-useful read helpers and all five preview siblings declare `meta.mcp.public = true` so the universal MCP default server (when mcp-adapter is installed) surfaces them via `discover-abilities` / `execute-ability`; the three editor-internal helpers (`list-synced-patterns`, `get-synced-pattern`, `check-status`) stay Abilities-API-only. On WP 7.0 admin screens, core hydrates server-side abilities into the `@wordpress/core-abilities` store. The canonical AI plugin's `Tools > Abilities Explorer` Experiment is the local harness for click-to-run testing — see `docs/reference/local-environment-setup.md`.

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
- `docs/reference/governance-layer.md` — canonical governance-layer contract map: pillars, enforcing code, surface loop coverage, and external-agent parity boundaries
- `docs/reference/ring-iii-attestation-design.md` — Ring III attestation design, trust boundary, public-safe predicate allowlist, verifier outcomes, and honesty statement
- `docs/reference/cross-surface-validation-gates.md` — additive release gates and required evidence for multi-surface or shared-subsystem changes
- `docs/reference/wordpress-ai-roadmap-tracking.md` — active conflicts between WordPress org project 240 (the AI Planning & Roadmap board) and Flavor Agent surfaces, with a refresh procedure
- `docs/features/README.md` — entry point for detailed per-surface docs
- `docs/reference/abilities-and-routes.md` — canonical REST and Abilities contract map
- `docs/reference/shared-internals.md` — cross-cutting store utilities, shared UI components, and context helpers
- `docs/reference/php-backend-architecture.md` — namespace-by-namespace map of the `inc/` PHP backend
- `docs/reference/js-frontend-architecture.md` — per-module map of the `src/` editor and admin JavaScript
- `docs/flavor-agent-readme.md` — architecture companion and editor-flow reference
- `docs/reference/local-environment-setup.md` — local Docker/devcontainer workflow and image pinning
- `STATUS.md` — working feature inventory and verification log
