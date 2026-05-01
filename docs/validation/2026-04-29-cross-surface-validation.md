# 2026-04-29 Cross-Surface Validation Artifact

## Change Summary

Cross-surface remediation pass for the uncommitted-review findings from the prior remediation wave. The implementation closes the pattern visible-scope contract, public-safe user-pattern indexing, block-undo path-drift handling, partial execution-contract merging, inserter-badge derivation, and the JS/PHP lint regressions without changing surface contracts or provider routing. Documentation, planning, and contributor artifacts are aligned to the same model:

- `Settings > Connectors` owns chat/text generation through the WordPress AI Client.
- `Settings > Flavor Agent` retains plugin-owned embedding, Qdrant, Cloudflare docs grounding, and pattern sync controls.
- Eight first-party recommendation surfaces remain documented: block, pattern, content, template, template-part, navigation, Global Styles, and Style Book.
- Cross-surface release validation is governed by `docs/reference/cross-surface-validation-gates.md`.

## Surfaces Touched

- Pattern recommendation backend: `inc/Abilities/PatternAbilities.php`, `inc/Context/SyncedPatternRepository.php`, `inc/Patterns/PatternIndex.php`, `inc/LLM/Prompt.php`, `inc/Cloudflare/AISearchClient.php`, `inc/Support/CollectsDocsGuidance.php`, `inc/Context/ServerCollector.php`.
- Pattern inserter UI: `src/patterns/InserterBadge.js`, `src/patterns/PatternRecommender.js`, `src/patterns/recommendation-utils.js`.
- Activity / undo store: `src/store/index.js`, `src/store/activity-history.js`, `src/store/activity-undo.js`, `src/store/block-targeting.js`, `src/store/update-helpers.js`.
- Tests: `tests/phpunit/PatternAbilitiesTest.php`, `tests/phpunit/PatternIndexTest.php`, `tests/phpunit/PromptRulesTest.php`, `tests/phpunit/AISearchClientTest.php`, `tests/phpunit/AgentControllerTest.php`, `src/patterns/__tests__/InserterBadge.test.js`, `src/patterns/__tests__/recommendation-utils.test.js`, `src/store/update-helpers.test.js`, `src/store/__tests__/store-actions.test.js`, `src/store/__tests__/activity-history.test.js`, `src/store/__tests__/pattern-status.test.js`, `tests/e2e/flavor-agent.smoke.spec.js`, `src/test-utils/wp-components.js`.
- Recommendation docs / contributor artifacts: `docs/SOURCE_OF_TRUTH.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/reference/abilities-and-routes.md`, `docs/reference/shared-internals.md`, `docs/reference/pattern-recommendation-audit-remediation-plan.md`, `docs/reference/pattern-recommendation-debugging.md`, `docs/features/block-recommendations.md`, `docs/features/pattern-recommendations.md`, `STATUS.md`.

## Formal Gates Triggered

| Gate | Triggered | Evidence needed |
| --- | --- | --- |
| 1. REST and shared contracts | Yes | Pattern visible-scope contract is now strictly fail-closed at the ability boundary; targeted PHPUnit/JS for ability and store contracts. |
| 2. Provider and backend routing | Yes | Indexing semantics widened to public-safe published `wp_block` posts; request-time auth still hits `read_post`. Must validate Connectors-first chat ownership and plugin-owned embedding/Qdrant path together. |
| 3. Freshness and request state | Yes | Inserter badge and resolved context signature now flow through helpers; must validate stale and fresh behavior in pattern inserter and block apply paths. |
| 4. Apply, preview, activity, and undo | Yes | Block undo path-drift no longer hard-fails when `clientId`, name, and after-attribute snapshot still match; activity persistence and ordered undo blocking must remain intact. |
| 5. Shared UI taxonomy and mode | Yes | Inserter badge derives only from renderable allowed-pattern matches; partial execution contracts now merge block-local content/config keys before filtering. |
| 6. Operator and admin paths | Yes | Settings-page chat/embedding/Qdrant ownership wording is unchanged; admin activity provenance fields unchanged. |
| 7. Multi-surface release matrix | Yes | Non-browser verifier, docs check, targeted tests, and browser evidence (or recorded blockers) must be captured below. |

## Tests Run

| Step | Command | Result |
| --- | --- | --- |
| Targeted JS unit (Fix 6) | `npm run test:unit -- src/patterns/__tests__/InserterBadge.test.js src/patterns/__tests__/recommendation-utils.test.js src/store/update-helpers.test.js src/store/__tests__/store-actions.test.js src/store/__tests__/activity-history.test.js --runInBand` | 5 suites, 131 tests passed (2.165 s) |
| Pattern store contract (Fix 5) | `npm run test:unit -- src/store/__tests__/pattern-status.test.js --runInBand` | 1 suite, 6 tests passed (1.555 s); confirms `patternBadge` slot is no longer present in store state |
| Targeted PHPUnit (Fix 6) | `vendor/bin/phpunit --filter 'PatternAbilitiesTest\|PatternIndexTest\|PromptRulesTest\|AISearchClientTest\|AgentControllerTest'` | 170 tests, 914 assertions passed (0.138 s) |
| JS lint | `npm run lint:js` | exit 0 |
| PHP lint | `composer run lint:php` | exit 0 |
| Docs freshness | `npm run check:docs` | exit 0 |
| Plugin Check path probe | `WP_PLUGIN_CHECK_PATH=/var/lib/docker/volumes/wordpress_wordpress_data/_data WORDPRESS_DB_HOST="$(docker exec wordpress-db-1 hostname -i):3306" WORDPRESS_DB_NAME=wordpress WORDPRESS_DB_USER=wordpress WORDPRESS_DB_PASSWORD=wordpress node scripts/verify.js --only=lint-plugin --output=output/verify-plugin-check` | `status=pass` per `output/verify-plugin-check/summary.json` at `2026-04-29T12:36:41.430Z`; confirms the Docker-backed WordPress root and DB env are sufficient for host-side Plugin Check. Plugin Check emitted one non-failing `WordPress.DB.SlowDBQuery.slow_db_query_tax_query` warning. |
| Aggregate non-browser verifier | `WP_PLUGIN_CHECK_PATH=/var/lib/docker/volumes/wordpress_wordpress_data/_data WORDPRESS_DB_HOST="$(docker exec wordpress-db-1 hostname -i):3306" WORDPRESS_DB_NAME=wordpress WORDPRESS_DB_USER=wordpress WORDPRESS_DB_PASSWORD=wordpress node scripts/verify.js --skip-e2e` | `status=pass` per [`output/verify/summary.json`](../../output/verify/summary.json) at `2026-04-29T12:47:24.455Z`; build, lint-js, lint-plugin, unit, lint-php, and test-php all `pass` (unit aggregate: 65 suites, 696 tests; test-php aggregate: 658 tests, 3185 assertions); `e2e-playground` and `e2e-wp70` skipped via `--skip-e2e` and covered separately below. |

Environment: Node `24.15.0`, npm `11.13.0`, PHP `8.5.4`, Composer `2.9.5`, Docker `29.1.3`.

## Dead-Code Sweep

- `patternBadge` initial state, `SET_PATTERN_RECS` reducer assignment, and `getPatternBadge` selector removed from `src/store/index.js`. The `getPatternBadgeReason` import at the store layer is gone; the helper now lives only on the consumer (`InserterBadge`) and runs against the renderable allowed-pattern subset rather than the raw recommendation list.
- `pattern-status.test.js` adds two `not.toHaveProperty( 'patternBadge' )` assertions that fail closed if the slot is ever reintroduced.
- `resolveActivityBlock` fully replaced by `resolveActivityBlockTarget` in `src/store/activity-history.js` and `src/store/activity-undo.js`, with the inline copy `'The target block changed position or type and cannot be undone automatically.'` consolidated to a single `BLOCK_TARGET_MOVED_ERROR` constant in `src/store/block-targeting.js`.
- The inline `response?.resolvedContextSignature || ''` lookup inside `src/store/index.js` now goes through `getResolvedContextSignatureFromResponse` from `src/store/update-helpers.js`, removing one ad-hoc shape access.
- `SYNCED_PATTERN_NAME_PREFIX` and `normalize_synced_pattern_payload` consolidated from `inc/Patterns/PatternIndex.php` into `inc/Context/SyncedPatternRepository.php` so the synced-pattern payload shape has a single source of truth shared by indexing and request-time rehydration.

No surface code was orphaned by these moves; static usages were migrated in the same change.

## Provider / Backend Check

- Connectors-first chat ownership unchanged. `LLM\WordPressAIClient` still routes recommendation chat through `wp_ai_client_prompt()` and the WordPress AI Client; the plugin still does not manage chat credentials.
- Plugin-owned embedding/Qdrant routing unchanged. `OpenAI\Provider`, `AzureOpenAI\EmbeddingClient`, `AzureOpenAI\QdrantClient`, and `Cloudflare\AISearchClient` still resolve through `Settings > Flavor Agent`.
- Pattern indexing scope widened from synced-only to public-safe published `wp_block` posts: `SyncedPatternRepository::for_indexable_patterns()` now passes `syncStatus = all` and `post_status = publish`, so published `synced`, `partial`, and `unsynced` user patterns are all indexed while drafts/private/trash are excluded. `PatternIndexTest` exercises both the inclusion and exclusion sides.
- Request-time auth tightened. `PatternAbilities::resolve_recommendation_candidate_payload()` now resolves any `core/block/{id}`, `type=user`, `source=synced`, or `syncedPatternId` candidate back to the live `wp_block` post via `SyncedPatternRepository::get_readable_pattern_for_recommendation()`, which short-circuits unless `current_user_can( 'read_post', $id )` passes. `PatternAbilitiesTest` asserts no embedding, Qdrant, docs, or ranker calls happen when `visiblePatternNames` is null or explicitly empty.
- Cloudflare docs grounding for the pattern surface now sets `allowForegroundWarm => false`, keeping recommendation-time docs grounding cache-only and non-blocking. `AISearchClientTest` asserts the foreground-warm pathway is suppressed for recommendation calls.

No new external dependencies, no changes to settings field IDs, no changes to provider-selection options, and no changes to operator-facing copy in `Settings > Flavor Agent` or `Settings > Connectors` are introduced by this change.

## Upstream Check

- `docs/reference/wordpress-ai-roadmap-tracking.md` snapshot date is `2026-04-28` (one day ahead of this validation). No active item on WordPress org project 240 collides with these surfaces: pattern visible-scope handling, public-safe indexing semantics, badge derivation, partial execution-contract merging, and `clientId`-stable undo are internal Flavor Agent contracts.
- The three-tier pattern API compat layer (`src/patterns/compat.js`) is untouched; no upstream pattern-API or settings-key change is introduced.
- The Abilities API surface is unchanged: no abilities added, removed, renamed, or moved between categories.

## Browser Evidence

- `npm run test:e2e:playground` 2026-04-29: full saved log was at `output/playwright/playground-run-2026-04-29.log` in the historical working tree; that artifact is no longer retained in this checkout.
  - First run: `8 passed / 1 failed / 2 skipped`. The single failure was `tests/e2e/flavor-agent.smoke.spec.js:2896 › template surface explains unavailable plugin backends`, where the Playground PHP-WASM `WebServer` crashed with `Error: Invalid state: Controller is already closed` in `@php-wasm/universal/index.js:4106` before the test could `page.goto( /wp-admin/site-editor.php )`. The two skipped tests are the same explicitly-skipped specs as the 2026-04-22 baseline (block-inspector smoke applies/persists/undoes; template surface smoke previews/applies executable templates).
  - Targeted rerun of only the failing test: `npx playwright test tests/e2e/flavor-agent.smoke.spec.js -g "template surface explains unavailable plugin backends" --reporter=list` returned `1 passed (1.2m)`, confirming the original failure was a `@php-wasm/universal` controller-closed flake at suite end, not a regression. The failing spec is a Site Editor unavailable-backend copy test and is not on a code path touched by this change. (That rerun log, `output/playwright/playground-rerun-2026-04-29.log`, was not retained in this checkout.)
  - Net pattern-surface coverage: `pattern surface smoke uses the inserter search to fetch recommendations` passed (26.2 s) on the first run, matching the 2026-04-22 baseline; `block and pattern surfaces explain unavailable providers in native UI` passed (28.2 s); `navigation surface smoke renders advisory recommendations` passed (28.6 s); template stale and advisory-only paths passed.
- `npm run test:e2e:wp70` 2026-04-29: rerun on the Docker-backed WP 7.0 harness returned `14 passed (6.1m)`, clearing the four 2026-04-22 Site Editor reds. The passing rerun includes:
  - `@wp70-site-editor global styles surface previews, applies, and undoes executable recommendations`
  - `@wp70-site-editor template-part surface smoke previews, applies, and undoes executable recommendations`
  - `@wp70-site-editor template undo survives a Site Editor refresh when the template has not drifted`
  - `@wp70-site-editor template undo is disabled after inserted pattern content changes`
  - The helper ability, Global Styles defaults/stale-state, Style Book stale-state, and template-part stale/advisory specs also passed.

## Decision

Pass.

- All seven triggered gates have evidence: targeted PHPUnit/JS green, lint and docs green, Plugin Check exercised against `/var/lib/docker/volumes/wordpress_wordpress_data/_data`, aggregate non-browser verifier green, Playground evidence effectively green after the targeted flake rerun, and Docker-backed WP 7.0 Site Editor evidence green.
- Browser evidence: Playground harness is effectively green (`9 passed / 2 skipped / 0 failed` once the harness flake is excluded by the targeted rerun); WP 7.0 harness is green (`14 passed / 0 failed`).
- No new blockers introduced. The 2026-04-22 WP 7.0 reds and the local Plugin Check environment blocker are no longer reproducible on this host with the Docker-backed WordPress root and DB env above.
- Release-blocking items for this artifact: none. The remediation plan's completion checklist is fully checked, and the cross-surface gates listed in this artifact are satisfied for the surfaces touched by this change.
