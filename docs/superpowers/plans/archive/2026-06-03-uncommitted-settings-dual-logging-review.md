# Uncommitted Settings and Dual Logging Review

> Status: Archived 2026-06-05. Findings were resolved in the same working tree; retained only as historical review context.

## Core Reason

The patch graduates Block Structural Actions from an experimental admin/constant toggle to default-on with only the `flavor_agent_enable_block_structural_actions` filter as a kill switch. It also reuses the Experimental Features section for a new default-on `AI Activity Dual Logging` setting that keeps local Flavor Agent request diagnostics even when core AI Request Logging is enabled.

## Findings

### Settings overview status is wired to the retired feature

The Experimental Features card still derives `On/Off` from `block_structural_actions_enabled` in `inc/Admin/Settings/State.php:40` and `inc/Admin/Settings/State.php:322`, but the only rendered setting is now `flavor_agent_dual_log_request_diagnostics` in `inc/Admin/Settings/Registrar.php:465`. A direct PHP probe confirmed that saving the new dual-logging option as `false` still renders the Experimental Features overview as `On`. That makes the settings overview misleading.

### Tests still assert the old request-logging invariant

The live plugin bootstrap adds a default-on filter in `flavor-agent.php:66`, but `RequestLoggingBridgeTest` still says enabled core logging suppresses diagnostics in `tests/phpunit/RequestLoggingBridgeTest.php:50`, and `RecommendationAbilityExecutionTest` still asserts no local diagnostic row in `tests/phpunit/RecommendationAbilityExecutionTest.php:175`. These tests pass because they exercise the class without loading `flavor-agent.php`, while a direct bootstrap probe returns `true` by default and `false` only when the new option is disabled.

### Contributor docs still describe unconditional suppression

The code now defaults to dual logging, but `docs/reference/activity-log-request-logging-coexistence.md:9`, `STATUS.md:62`, and `docs/reference/wordpress-ai-roadmap-tracking.md:111` still say Flavor Agent suppresses `request_diagnostic` rows whenever core logging is enabled. Also, `docs/flavor-agent-readme.md:195` removes the retired structural-actions option from the configured-options list but does not add `flavor_agent_dual_log_request_diagnostics`.

## State Reviewed

No staged changes. Unstaged tracked changes span settings/bootstrap/docs/tests. Four untracked superpowers plan/spec docs are present. `git diff --check` is clean.

## Verification Run

`composer run test:php -- --filter 'RequestLoggingBridgeTest|SettingsTest|SettingsRegistrarTest|BlockStructuralActionsFlagTest'` passed: 123 tests, 484 assertions.

Focused direct probes confirmed the live bootstrap behavior and the misleading Experimental Features status.

## Resolution (2026-06-03)

All three findings addressed in the same working tree:

1. **Experimental Features overview status** — `State::get_experiments_overview_status()` repointed from the retired structural gate to the `flavor_agent_dual_log_request_diagnostics` option (`inc/Admin/Settings/State.php`); `SettingsTest::test_experiments_overview_status_follows_dual_logging_setting` added (RED→GREEN). Disabling dual logging now renders the section "Off".
2. **Request-logging test coverage** — added `PluginLifecycleTest::test_bootstrap_dual_logs_request_diagnostics_by_default_with_core_logging`, which loads the bootstrap and asserts dual-log-by-default + opt-out (proven to fail when the bootstrap filter is neutralized). The class-level `RequestLoggingBridgeTest` / `RecommendationAbilityExecutionTest` isolation tests are annotated to note the bootstrap flips the default.
3. **Contributor docs** — dual-log-by-default reconciled across `activity-log-request-logging-coexistence.md` (TL;DR, revision banner, §"Persisting…", settings story, compatibility matrix, migration phases), `STATUS.md`, `wordpress-ai-roadmap-tracking.md`, and `flavor-agent-readme.md`.

Re-verification: `node scripts/verify.js --skip-e2e` → pass (1374 tests, 6610 assertions); `npm run check:docs` → clean; `git diff --check` → clean (the STATUS.md:62 trailing-CR introduced by the first doc pass was stripped).
