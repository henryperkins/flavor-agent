# AI Activity And Undo Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#ai-activity-and-undo)

## Release Role

AI Activity belongs because review/apply/undo needs provenance. Inline activity
sections and the admin audit page support trust and recovery without becoming an
observability product.

Release verdict: keep as a support surface.

Release quality: release-ready as support infrastructure if it is not marketed
or expanded as observability.

## Stop Line

Ship:

- Inline recent actions for executable editor scopes.
- Ordered newest-valid-tail undo.
- Read-only admin audit.
- Search/filter/details for diagnostics and provenance.

Do not ship:

- A general observability product.
- Metrics dashboards.
- Provider latency/cost analytics.
- Admin row-action undo.
- Cross-user activity intervention.

Per-entry token usage and latency may remain in read-only details when they
serve provenance or diagnostics. Do not aggregate them into dashboards, cost
reports, provider rankings, or observability workflows.

## Next Steps

- [x] Confirm `manage_options` gates the admin page.
- [x] Confirm malformed filters fail closed.
- [x] Confirm operation filters dedupe by effective value while row labels
  remain specific.
- [x] Confirm retention/pruning expectations are documented or intentionally
  deferred.
- [x] Keep admin activity copy framed as audit/provenance, not monitoring.
- [x] Keep admin activity read-only.

## Verification Gate

- [x] Re-run activity PHPUnit coverage after changes.
- [x] Re-run admin JS coverage after changes.
- [x] Re-run activity Playwright coverage after changes.

Release evidence recorded 2026-05-02:

- `composer run test:php -- --filter 'ActivityRepositoryTest|ActivityPermissionsTest|AgentControllerTest|ActivityPageTest|PluginLifecycleTest'`
  passed with 94 tests and 488 assertions.
- `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js src/components/__tests__/AIActivitySection.test.js src/components/__tests__/ActivitySessionBootstrap.test.js`
  passed with 7 suites and 144 tests.
- `npx playwright test tests/e2e/flavor-agent.activity.spec.js` passed with
  2 tests.
- `npm run check:docs` passed.
- `node scripts/verify.js --skip-e2e` passed with
  `VERIFY_RESULT={"status":"pass","summaryPath":"output/verify/summary.json","counts":{"total":8,"passed":6,"failed":0,"skipped":2}}`.
- `npm run verify` passed with
  `VERIFY_RESULT={"status":"pass","summaryPath":"output/verify/summary.json","counts":{"total":8,"passed":8,"failed":0,"skipped":0}}`.
