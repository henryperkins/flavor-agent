# Uncommitted Changes Review Remediation Plan

## Scope

Address the regression found in the async Cloudflare AI Search provisioning path: successful background provisioning can leave the pattern index unsynced because no pattern sync is scheduled after the validated signature is written.

## Finding

- High: `Validation::resolve_pattern_ai_search_submission_values()` sets the managed instance ID and schedules `PatternSearchInstanceManager::PROVISION_CRON_HOOK` while the validated signature is absent. Existing dependency-change hooks can run at this point, but `PatternIndex::schedule_sync()` sees Cloudflare AI Search as unconfigured and exits. Later, `PatternSearchInstanceManager::process_managed_instance_provisioning()` writes the validated signature and marks provisioning ready, but it does not mark the index dirty or schedule a pattern sync, and no option hook currently watches `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE`.

## Desired Behavior

- Saving valid Cloudflare Workers AI credentials with the Cloudflare AI Search pattern backend selected starts managed-index provisioning without blocking the settings request.
- When provisioning succeeds, Flavor Agent records the managed instance ID, validated signature, and ready provisioning state.
- Immediately after the successful provisioning state is persisted, Flavor Agent marks the pattern index stale for the Cloudflare AI Search configuration change and schedules the normal pattern sync cron.
- If provisioning fails, becomes stale, or the backend changes away from Cloudflare AI Search before the job runs, no sync is scheduled.
- The implementation remains idempotent when provisioning is retried or the cron callback is invoked more than once.

## Implementation Plan

1. Update `PatternSearchInstanceManager::process_managed_instance_provisioning()` success handling.
   - After writing `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID`, `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE`, and the ready provisioning state, call the pattern index dirty/sync path explicitly.
   - Use `PatternIndex::mark_dirty( 'cloudflare_ai_search_signature_changed' )` followed by `PatternIndex::schedule_sync( true )`.
   - Add `use FlavorAgent\Patterns\PatternIndex;` to `inc/Cloudflare/PatternSearchInstanceManager.php`.
   - Architecture note: `PatternIndex` already imports `PatternSearchInstanceManager` (`inc/Patterns/PatternIndex.php:14`), so this introduces a bidirectional module coupling. PHP tolerates it, and the directness keeps the fix contained. If the coupling becomes load-bearing elsewhere, prefer firing `do_action( 'flavor_agent_pattern_ai_search_provisioned', $signature )` and listening from `flavor-agent.php`.

2. Preserve guard behavior in non-success paths.
   - Keep the existing early return when provisioning state is not `provisioning`.
   - Keep the existing early return when the selected pattern backend is not `cloudflare_ai_search`.
   - Do not schedule sync when credentials changed mid-provisioning and the state is marked `stale`.
   - Do not schedule sync when remote provisioning returns a `WP_Error` and the state is marked `error`.

3. Add focused PHPUnit coverage. Treat each branch as a discrete test rather than a single extension.

   Success branch — extend `CloudflarePatternSearchInstanceManagerTest::test_process_managed_instance_provisioning_validates_saved_request_and_marks_ready()`:
   - Assert `false !== wp_next_scheduled( PatternIndex::CRON_HOOK )` after the callback returns.
   - Assert the stored `PatternIndex::STATE_OPTION` shows `status === 'stale'` and `stale_reason === 'cloudflare_ai_search_signature_changed'` (and the same value as the first entry of `stale_reasons`).

   Error branch — extend `test_process_managed_instance_provisioning_records_remote_error_without_validating_signature()`:
   - Add `$this->assertFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );` so a regression that schedules sync on the error path is caught.

   Credentials-changed-mid-provisioning branch — **new test** (no current coverage of `PatternSearchInstanceManager.php:118–130`):
   - Seed `OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE` with `status = 'provisioning'` and a `signature` that does NOT match the recomputed signature from current Workers AI options.
   - Run `process_managed_instance_provisioning()`.
   - Assert: provisioning state is `'stale'` with `last_error_code === 'cloudflare_pattern_ai_search_signature_changed'`, `OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE` is absent, no remote HTTP calls were made, and `false === wp_next_scheduled( PatternIndex::CRON_HOOK )`.

   Backend-changed-away branch — **new test** (no current coverage of `PatternSearchInstanceManager.php:100–106`):
   - Seed `OPTION_PATTERN_RETRIEVAL_BACKEND = Config::PATTERN_BACKEND_QDRANT` while the provisioning state is `'provisioning'`.
   - Run `process_managed_instance_provisioning()`.
   - Assert: no remote HTTP calls, provisioning state unchanged, `OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE` unchanged, and `false === wp_next_scheduled( PatternIndex::CRON_HOOK )`.

   Failed-then-retried-then-succeeded — **new test** (covers acceptance criterion below):
   - Run the callback once with the manager returning a `WP_Error` (state ends `'error'`).
   - Re-seed provisioning state to `'provisioning'` (simulating a fresh save) and stub a successful remote response.
   - Run the callback again.
   - Assert exactly one `flavor_agent_reindex_patterns` event is scheduled (use `wp_get_scheduled_event` or count via the test harness) and that the validated signature is written.

4. Consider whether an option hook should also cover manual signature writes.
   - Preferred minimal fix: schedule sync directly in the provisioning success path because that is the only new async writer introduced by this change set.
   - Optional follow-up only if another production path writes the validated signature directly: add `update_option_{Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE}` to the dependency-change hook list in `flavor-agent.php`. Avoid this unless needed to prevent redundant sync scheduling for internal option writes.

5. Verify the full affected flow.
   - Run targeted PHPUnit tests for settings, provisioning, and pattern indexing (`PatternIndexTest.php` is confirmed to exist under `tests/phpunit/`).
   - Run existing JS unit tests touched by the uncommitted changes to make sure activity-title updates remain green.
   - Run `npm run verify -- --skip-e2e` to satisfy the cross-surface validation gate (this change touches pattern retrieval, which sits behind `RankingContract` per `CLAUDE.md`). Inspect `output/verify/summary.json` for `status === 'pass'`.
   - Run `git diff --check`.

## Test Commands

```bash
git diff --check
composer run test:php -- --filter 'SettingsTest|CloudflarePatternSearchInstanceManagerTest|PatternIndexTest'
npm run test:unit -- --runTestsByPath src/admin/__tests__/activity-log-utils.test.js src/components/__tests__/AIActivitySection.test.js src/store/__tests__/store-actions.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js
npm run verify -- --skip-e2e
```

## Acceptance Criteria

- A first-time Cloudflare AI Search setup schedules provisioning on settings save.
- Successful provisioning writes the validated signature and schedules `flavor_agent_reindex_patterns`.
- The pattern index state records a Cloudflare AI Search configuration-change stale reason before the sync runs.
- Failed, stale, or backend-mismatched provisioning does not schedule pattern sync.
- Re-running the provisioning callback after success does not schedule duplicate work because the provisioning state is no longer `provisioning`.
- After a failed provisioning attempt, re-saving settings and the next successful provisioning run schedules exactly one `flavor_agent_reindex_patterns` event.
- Targeted PHPUnit and JS tests pass, `npm run verify -- --skip-e2e` reports `pass` in `output/verify/summary.json`, and `git diff --check` reports no issues.
