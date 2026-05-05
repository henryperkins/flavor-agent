# Canonical AI Integration — Remediation Plan

**Date:** 2026-05-05
**Pairs with:** [2026-05-04-canonical-ai-integration-design.md](2026-05-04-canonical-ai-integration-design.md)
**Driver:** Henry Perkins
**Status:** Superseded for execution by `../plans/2026-05-05-canonical-ai-integration-guide-remediation.md`

> **Execution note added 2026-05-05:** This spec remains useful background, but it is no longer the execution plan of record. Execute `docs/superpowers/plans/2026-05-05-canonical-ai-integration-guide-remediation.md` instead. The current working tree already invalidates F3/F4/F5 as written:
>
> - F3's per-post denial coverage exists in `tests/phpunit/RegistrationTest.php`.
> - F4's bridge-unavailable throw claim is stale; `src/store/abilities-client.js` falls back to Abilities REST when the bridge is absent or unusable.
> - F5's seven dead `REQUEST_META_ROUTES` entries are already gone; `inc/REST/Agent_Controller.php` only keeps `sync-patterns`.
>
> Do not implement F3/F4/F5 literally without re-checking the current source.

## Purpose

The 2026-05-04 canonical AI integration refactor moved the seven `recommend-*` surfaces from `/flavor-agent/v1/recommend-*` REST routes onto the Abilities API and registered Flavor Agent as a downstream Experiment of the WordPress AI plugin. Reviewing the uncommitted state surfaced 14 findings spanning correctness, cleanup, integration breadth, and release communication. This document organizes them into sequenced phases with explicit gates, so the work can ship without losing fidelity.

Per `docs/reference/cross-surface-validation-gates.md`, every phase below is treated as additive: each lands its own validation evidence before the next begins.

## Status snapshot

| Finding | State | Owner |
| --- | --- | --- |
| Hook-timing bug — recommend-* abilities never registered | **Fixed** (in working tree, verified end-to-end) | Henry |
| Untracked refactor files staged | **Done** (16 files staged) | Henry |
| CLAUDE.md "Key Integration Points → REST API" stale | **Patched** | Henry |
| Activity-log diagnostic gap memory entry | **Updated** | Henry |
| All other findings | Pending — see phases below | — |

## Findings, prioritized

| # | Finding | Severity | Phase |
| --- | --- | --- | --- |
| F1 | REST recommend-* endpoints removed without compatibility shims | High (breaking) | 1 |
| F2 | Editor enqueue now hard-depends on the AI plugin being active | Medium (UX/rollout) | 1 |
| F3 | Per-post permission tightening (`edit_post` vs `edit_posts`) | Medium (behavioral) | 1 |
| F4 | Brittle bridge timing — `executeFlavorAgentAbility` without signal throws if script-module not loaded | Medium (race) | 2 |
| F5 | Dead `recommend-*` entries in `Agent_Controller::REQUEST_META_ROUTES` | Low (cleanup) | 2 |
| F6 | Dead `$request_token` local in `RecommendationAbilityExecution::execute()` | Low (cleanup) | 2 |
| F7 | `recommendation_feature_enabled()` evaluated at `wp_abilities_api_init` reads pre-`init:15` filter state | Low (gate fidelity) | 2 |
| F8 | Recommend-* abilities are reachable via the universal MCP bridge but not surfaced as first-class MCP tools | Medium (integration breadth) | 3 |
| F9 | WP 7.0 E2E harness lacks companion plugins (no `mcp-adapter`, no AI plugin) | Medium (test coverage) | 3 |
| F10 | Release-notes / changelog entry for breaking REST removal | High (comms) | 4 |
| F11 | Cross-surface validation gates not yet run for this refactor | High (regression risk) | 4 |
| F12 | `wpai_register_features` action vs `wpai_default_feature_classes` filter — current path correct, no action needed beyond confirming | Info | — |
| F13 | `php-mcp-schema` is transitive through `mcp-adapter`; flavor-agent does not declare it | Info | — |
| F14 | Default MCP server's `tools/list` returns only the 3 universal bridge tools, not auto-discovered abilities | Info | Documented in Phase 3 |

## Phase 1 — Stabilize the breaking change surface

**Goal:** Decide and implement the REST/permission/dependency stance so external integrators and downstream users have a clear deprecation path.

### F1 — REST shim policy

The seven `POST /flavor-agent/v1/recommend-*` endpoints are gone. External consumers (other plugins, headless integrations, automation, and any browser tab still loading the previous editor JS bundle) will receive `404 rest_no_route`.

**Decision required:** *thin shim* vs *clean break*.

- **Option A — thin shim, one release window.** Restore the seven REST routes in `Agent_Controller::register_routes()` as one-line forwarders that call `RecommendationAbilityExecution::execute()` with the same surface key. Add a `Deprecation: ...` response header, log a `_doing_it_wrong` once per request, and remove in v0.2.0.
  - Pros: zero customer-visible regression in v0.1.x.
  - Cons: keeps a bypass around the `RecommendationAbility::permission_callback` per-post escalation unless we replicate it in the shim.
- **Option B — clean break.** Drop the routes, document the migration in `readme.txt` and `CHANGELOG.md`, bump to a major version (or 0.2.0 since pre-1.0).
  - Pros: simpler; no double-permission-implementation drift.
  - Cons: callers must update before upgrading.

**Recommendation:** Option B — pre-1.0 license to reshape the API. Land a single CHANGELOG entry with explicit migration ("`POST /flavor-agent/v1/recommend-block` → `POST /wp-abilities/v1/abilities/flavor-agent/recommend-block/run`, body `{ "input": { ... } }`").

### F2 — Editor enqueue dependency on the AI plugin

Today: when the AI plugin is absent, `flavor_agent_enqueue_editor` never fires (its hook is registered inside `FlavorAgentFeature::register()`), and `FeatureBootstrap::render_missing_contract_notice` shows a `manage_options` admin warning.

**Tasks:**
- Add the AI plugin to `Requires Plugins:` in the main plugin header (canonical WP.org dependency mechanism).
- Mention the dependency in `readme.txt` "Requires" and "Installation" sections.
- Confirm the admin notice copy points users at the right install path (link to AI plugin page on WP.org).
- Decide whether the notice should auto-suppress on `Settings > AI` (where the user is already mid-install).

### F3 — Permission tightening

`RecommendationAbility::permission_callback` escalates from `current_user_can(static::CAPABILITY)` to `current_user_can('edit_post', $post_id)` whenever a post ID is extractable from the input. This is *more* secure than the previous `edit_posts` general check, but it is a behavioral change.

**Tasks:**
- Document the matrix in `docs/reference/abilities-and-routes.md`:
  - Block / Content / Patterns: `edit_post( $post_id )` if `$post_id > 0`, else `edit_posts`.
  - Navigation / Style / Template / Template-part: `edit_theme_options` (no per-post escalation; these surfaces are template-scoped).
- Add a phpunit test that explicitly covers the case "user with `edit_posts` but not `edit_post` for a specific private post" → expects 403 from the ability run endpoint.
- Mention in CHANGELOG under "Security hardening".

### Phase 1 verification

- `vendor/bin/phpunit tests/phpunit/RecommendationAbilityExecutionTest.php tests/phpunit/FeatureBootstrapTest.php` (must pass).
- New phpunit test for F3 (per-post denial path).
- `node scripts/verify.js --skip-e2e` clean; inspect `output/verify/summary.json`.
- `npm run check:docs` passes after CLAUDE.md / abilities-and-routes / readme updates.

## Phase 2 — Cleanup and gate-fidelity polish

**Goal:** Remove dead code, harden the bridge race, and tighten the feature-enabled gate.

### F4 — Bridge race

`executeFlavorAgentAbility` (`src/store/abilities-client.js`) without a signal requires `window.flavorAgentAbilities.executeAbility` to be loaded, otherwise throws `flavor_agent_abilities_bridge_unavailable`. The bridge is enqueued via `wp_enqueue_script_module` (async) while the editor is a regular script (sync). Three call sites use the no-signal path:

- `src/store/index.js:583` — apply-time freshness probe
- `src/store/index.js:2295` — block resolved-signature recheck
- `src/store/executable-surface-runtime.js:285` — review-stage freshness probe

**Tasks:**
- Add a "wait-for-bridge" helper with a short bounded poll (e.g., 50ms × 20 = 1s ceiling) before throwing. Polls `window.flavorAgentAbilities?.executeAbility`. If still unavailable after the ceiling, fall back to `apiFetch` against `/wp-abilities/v1/abilities/{name}/run` — the same path the signal-bearing branch uses today.
- Update the existing Jest test in `src/store/__tests__/abilities-client.test.js:92` to reflect the new behavior (poll + fallback).
- Document the policy in `docs/reference/shared-internals.md`: "freshness probes survive a slow script-module load by polling briefly and then falling back to REST."

### F5 — Dead REQUEST_META_ROUTES entries

`inc/REST/Agent_Controller.php:19-54` contains seven `recommend-*` entries that are unused now (only `sync-patterns` is referenced from `handle_sync_patterns`).

**Tasks:**
- Delete the seven dead entries.
- Update the `private const REQUEST_META_ROUTES` PHPDoc accordingly.
- If F1 lands as Option A (shim), keep the entries; revisit only at shim removal.

### F6 — Dead `$request_token` local

`inc/Abilities/RecommendationAbilityExecution.php:31`:

```php
$request_token = self::latest_request_token( $ability_name, $surface, $client_request );
```

The variable is never read; the only purpose is the transient-update side effect.

**Tasks:**
- Drop the assignment, call as a statement, and rename the helper to make the intent obvious — e.g., `update_latest_request_token( ... )` returning `void`.
- Confirm the existing `should_persist_request_diagnostic` consumer still works.

### F7 — Pre-`init:15` filter decoration

`FeatureBootstrap::recommendation_feature_enabled()` runs at `wp_abilities_api_init` (very early) and reads option values without the AI plugin's filter decoration (which is registered at `init:15`). For default-on rollout this is fine. For sites that wire `wpai_features_enabled` or `wpai_feature_flavor-agent_enabled` filters via plugin code, the gate evaluates against raw options at registration time but against decorated values everywhere else (e.g., `SurfaceCapabilities::build`, `mcp_adapter_init`).

**Tasks:**
- Document the timing in a `// Why:` comment above `register_global_helper_abilities`.
- Consider deferring the recommend-* registration to a later moment if real users hit this. *Option:* register recommend-* abilities unconditionally on `wp_abilities_api_init`, but enforce the feature toggle in `RecommendationAbility::permission_callback` (returning a `WP_Error('flavor_agent_feature_disabled', ..., ['status' => 503])`).
- Land tracking note in `docs/reference/wordpress-ai-roadmap-tracking.md` if upstream changes the timing.

### Phase 2 verification

- `npm run test:unit -- --runInBand`
- `composer test:php`
- `npm run lint:js` and `composer lint:php`
- `node scripts/verify.js --skip-e2e`

## Phase 3 — MCP server integration breadth

**Goal:** Make the seven recommend-* abilities first-class MCP tools and confirm test harnesses cover the path.

### F8 — Dedicated flavor-agent MCP server (Path B)

Per the upstream-source review (`/home/dev/mcp-adapter`), `McpAdapter::create_server()` is gated to fire only during the `mcp_adapter_init` action, which runs at `init:20` (admin) or `rest_api_init:15` (REST) — strictly after AI plugin's `Loader::initialize_features` at `init:15`. That means `recommendation_feature_enabled()` evaluates correctly here (the F7 caveat does not apply).

**Tasks:**
- Add `inc/MCP/ServerBootstrap.php` (new namespace `FlavorAgent\MCP`) with a single static `register()` call hooked from `flavor-agent.php`:

  ```php
  add_action( 'mcp_adapter_init', [ FlavorAgent\MCP\ServerBootstrap::class, 'register' ] );
  ```

  The static `register()` body:

  ```php
  if ( ! class_exists( \WP\MCP\Core\McpAdapter::class ) ) {
      return;
  }
  if ( ! \FlavorAgent\AI\FeatureBootstrap::recommendation_feature_enabled() ) {
      return;
  }
  $tools  = array_keys( \FlavorAgent\Abilities\Registration::recommendation_ability_classes() );
  $result = \WP\MCP\Core\McpAdapter::instance()->create_server(
      'flavor-agent',
      'mcp',
      'flavor-agent',
      __( 'Flavor Agent', 'flavor-agent' ),
      __( 'AI-assisted WordPress recommendations across blocks, content, navigation, patterns, styles, templates, and template parts.', 'flavor-agent' ),
      FLAVOR_AGENT_VERSION,
      [ \WP\MCP\Transport\HttpTransport::class ],
      \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
      \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
      $tools,
      [],
      [],
      static fn(): bool => current_user_can( 'edit_posts' )
  );
  if ( is_wp_error( $result ) ) {
      error_log( '[flavor-agent] MCP server registration failed: ' . $result->get_error_code() . ' — ' . $result->get_error_message() );
  }
  ```

- Add `tests/phpunit/MCPServerBootstrapTest.php`:
  - Asserts `register()` no-ops when `WP\MCP\Core\McpAdapter` class is missing.
  - Asserts `register()` no-ops when `recommendation_feature_enabled()` returns false.
  - Asserts the canonical class names referenced are PSR-4 resolvable from the deployed mcp-adapter (skip in unit env, exercise in integration).
- Run end-to-end discovery curl against the running container:
  - `tools/list` on `/wp-json/mcp/flavor-agent` should return 7 entries (`flavor-agent-recommend-block`, etc.).
  - `tools/call` with name `flavor-agent-recommend-block` and a minimal input must round-trip through `RecommendationAbility::execute_callback` and return a structured result.
- Document the new MCP surface in `docs/reference/abilities-and-routes.md` (next to the abilities listing) and add a one-paragraph entry to `readme.txt` "Integrations".

### F9 — WP 7.0 E2E harness companion-plugin parity

The Docker-backed WP 7.0 harness (`flavor-agent-wp70-wordpress-1`) currently has no companion plugins active. If MCP/abilities behavior is part of any WP 7.0 e2e flow we want to lock down, the harness needs the same plugin set as the main env.

**Tasks:**
- Inspect `npm run wp:e2e:wp70:bootstrap` (per `package.json`); identify where its plugin install lives.
- Add `wordpress-beta-tester`, `gutenberg`, `ai`, `ai-services`, `ai-provider-for-anthropic`, `ai-provider-for-openai`, `plugin-check`, plus `mcp-adapter` cloned from GitHub (per `local-environment-setup.md`).
- If the bootstrap is intentionally minimal for speed, **don't** force the parity — instead add a one-line note in the harness README saying MCP/abilities tests should target the main env, and run the WP 7.0 suite without those gates.
- Decide based on whether any current `tests/e2e/*.spec.js` exercise MCP or abilities routes.

### Phase 3 verification

- `vendor/bin/phpunit tests/phpunit/MCPServerBootstrapTest.php`
- Manual curl probe (capture in a one-off script under `scripts/diag/mcp-discovery-probe.sh` for repeatability).
- `npm run test:e2e:wp70` (only if F9 lands the parity work).
- `npm run check:docs` after readme/abilities-and-routes updates.

## Phase 4 — Verification, documentation, and release prep

**Goal:** Run the cross-surface validation gates, document migration, and stage the changeset for review.

### F10 — Release notes / changelog

**Tasks:**
- Add `CHANGELOG.md` entry under the next version (likely 0.2.0):
  - "BREAKING: removed `POST /flavor-agent/v1/recommend-*` REST endpoints. Recommendations now run through the WordPress Abilities API at `POST /wp-abilities/v1/abilities/flavor-agent/recommend-*/run`. See migration notes."
  - Behavioral: per-post `edit_post` permission escalation when a post ID is in input.
  - New: registers as a downstream Experiment of the AI plugin via `wpai_default_feature_classes`. Editor UI requires the AI plugin to be active.
  - New: dedicated MCP server at `/wp-json/mcp/flavor-agent` exposing all seven recommendation surfaces as MCP tools.
  - Closed: activity-log diagnostic gap on block/template/template-part/style surfaces (rows now persist for every "Get Suggestion").
- Add a "Migration from `recommend-*` REST routes" section to `readme.txt` with one curl pair per surface (before/after).

### F11 — Cross-surface validation gates

This refactor touches REST contracts, ability contracts, all 8 surfaces, activity, freshness, editor enqueue, and the MCP integration boundary — every cross-surface gate applies.

**Tasks:**
- `node scripts/verify.js --skip-e2e` — capture `output/verify/summary.json`, attach to PR.
- `npm run check:docs` — must pass after all doc updates.
- `npm run test:e2e:playground` — post-editor / block / pattern / navigation flows.
- `npm run test:e2e:wp70` — Site Editor template / template-part / Global Styles / Style Book flows. If harness is unavailable, record an explicit waiver per `docs/reference/cross-surface-validation-gates.md`.
- Manual smoke against the running container:
  - Editor: open a post, click "Get Suggestion" on a paragraph block, verify activity-log entry appears.
  - Site Editor: same for a template.
  - Settings → AI Activity: confirm the new entries surface, with `wp-abilities:flavor-agent/recommend-*` route strings.
  - MCP: `curl` the new `/wp-json/mcp/flavor-agent` server's `tools/list`, confirm 7 entries.

### F12, F13, F14 — informational, no work needed

- F12: `wpai_default_feature_classes` filter is the right entrypoint. Confirmed via upstream Loader.php.
- F13: `php-mcp-schema` is a transitive dep of `mcp-adapter`. No direct require in flavor-agent's `composer.json`.
- F14: The default MCP server intentionally hardcodes its tools list to the three universal bridge tools (`mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, `mcp-adapter-execute-ability`). Auto-discovery only applies to resources/prompts via per-ability `meta.mcp` metadata. This is documented behavior, not a bug; recording here so future readers don't re-investigate.

## Sequencing and timing

| Phase | Effort | Dependency | Earliest start |
| --- | --- | --- | --- |
| Phase 1 | ~1 dev-day | F1 decision (Henry) | After this plan is approved |
| Phase 2 | ~0.5 dev-day | Phase 1 | Right after Phase 1 lands |
| Phase 3 | ~1 dev-day | None (parallelizable with Phase 2) | After this plan is approved |
| Phase 4 | ~0.5 dev-day | Phases 1–3 | Right after Phase 3 lands |

Total: ~3 dev-days end-to-end. Phases 2 and 3 can run in parallel by different agents/sessions.

## Open decisions

1. **REST shim policy for F1** — Option A (thin shim, one release) vs Option B (clean break). Recommendation: B.
2. **WP 7.0 harness parity for F9** — provision MCP/AI plugins or note as out-of-scope. Recommendation: out-of-scope unless a specific e2e adds a hard need.
3. **F7 deferred-registration alternative** — keep the `init`-priority gate or move to `permission_callback`-time gate. Recommendation: keep current gate; revisit if real users hit edge cases.

## Out of scope

- Plugin-directory submission tasks tracked separately under the 2026-05-31 release goal.
- Cloudflare AI Search private pattern instance setup — orthogonal to this refactor.
- Additional MCP servers beyond the dedicated flavor-agent server (e.g., per-surface or per-cap servers) — viable future work, not required now.
- OAuth 2.1 self-hosted MCP auth — upstream-blocked; tracked at the WordPress AI level.

## Verification artifacts to attach to the eventual PR

- `output/verify/summary.json` from `node scripts/verify.js --skip-e2e`
- Diff hunks for the eight files touched per phase (surface check)
- One-shot MCP discovery curl output (Phase 3)
- Migration table in `CHANGELOG.md` (Phase 4)
- Memory entry "AI Activity log records every Get Suggestion" referenced for the closed gap (already updated 2026-05-04)
