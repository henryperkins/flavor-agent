# 2026-05-02 Template Surface Release Closeout

## Decision

Pass for the bounded template recommendation release scope.

No additional template-surface work remains for release inside the documented
stop line: review-first deterministic operations, bounded pattern insertion,
advisory fallback, activity, and undo. Broad multi-operation template
transactions remain deferred until deterministic safety is proven.

## Scope

- Template recommendations in the Site Editor.
- Release evidence for review, confirm-apply, activity, stale refresh,
  advisory-only fallback, failed-operation rollback, refresh-safe undo, and
  drift-disabled undo.
- Incidental Abilities REST compatibility needed to keep the WP 7.0 browser
  harness green for read-only helper ability smoke coverage.

## Evidence

| Command | Result |
| --- | --- |
| `vendor/bin/phpunit tests/phpunit/TemplatePromptTest.php tests/phpunit/TemplateAbilitiesTest.php tests/phpunit/AgentControllerTest.php tests/phpunit/RegistrationTest.php` | Passed: 27 tests, 76 assertions. |
| `npm run test:unit -- src/templates/__tests__/TemplateRecommender.test.js src/templates/__tests__/template-recommender-helpers.test.js src/utils/__tests__/template-actions.test.js src/store/__tests__/store-actions.test.js src/store/__tests__/template-apply-state.test.js --runInBand` | Passed: 5 suites, 192 tests. |
| `npm run test:e2e:wp70 -- tests/e2e/flavor-agent-helper-abilities.spec.js` | Passed: 5 tests. |
| `npm run test:e2e:wp70` | Passed: 20 tests. Includes template preview/apply/activity, template-part coverage, refresh-safe template undo, and drift-disabled template undo. |
| `npm run test:e2e:playground -- tests/e2e/flavor-agent.smoke.spec.js -g "template surface keeps (stale results visible\|advisory-only suggestions visible)"` | Passed: 2 tests. Covers stale-refresh disabling review/apply until refresh and advisory-only/manual guidance without executable controls. |
| `node scripts/verify.js --skip-e2e` | Passed: `VERIFY_RESULT={"status":"pass","summaryPath":"output/verify/summary.json","counts":{"total":8,"passed":6,"failed":0,"skipped":2}}`. Build, JS lint, Plugin Check, JS unit, PHP lint, and PHPUnit passed; E2E steps were intentionally skipped and covered by the browser rows above. |
| `npm run check:docs` | Passed. |

## Notes

- WP 7.0 now requires read-only abilities to run with `GET`; the helper browser
  smoke client serializes read-only input as nested query parameters.
- Optional read-only object inputs now declare an empty-object default so omitted
  `GET` input validates before ability callbacks run.
- The template operation rollback tests confirm that a failed operation leaves
  the template state unchanged or reverted.
