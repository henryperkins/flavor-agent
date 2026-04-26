# AI Activity Log Review Prompt

```text
You are reviewing the Flavor Agent AI Activity Log implementation. Treat this as a review-only pass: do not modify files, do not rewrite components, and do not propose broad redesigns unless you can tie them to a concrete bug, security issue, performance issue, missing test, or documented contract drift.

Scope
- Primary surface: the WordPress admin AI Activity page (`settings_page/flavor-agent-activity`) and its server-backed audit/activity log.
- Include editor sidebar/session/undo flows only where they create, mutate, serialize, link to, or otherwise define records shown in the AI Activity log.
- Trace the complete path before making findings: admin menu/page registration, asset enqueue/localized boot data, REST route arguments and permissions, repository writes and admin queries, serializer/admin projection, client request URL generation, DataViews/DataForm rendering, persisted view state, target links, CSS states, tests, and docs.
- Keep findings evidence-first. Grep hits are leads, not findings; open the file and confirm the actual runtime path before flagging.

Inspect at minimum
- `inc/Admin/ActivityPage.php`
- `inc/Activity/Repository.php`
- `inc/Activity/Permissions.php`
- `inc/Activity/Serializer.php`
- `inc/REST/Agent_Controller.php`
- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`
- `src/admin/activity-log.css`
- `src/admin/dataviews-runtime.css`
- `src/admin/wpds-runtime.css`
- `src/components/ActivitySessionBootstrap.js`
- `src/components/AIActivitySection.js`
- `src/store/index.js`
- `src/store/activity-history.js`
- `src/store/activity-undo.js`
- `src/store/activity-session.js`
- `src/utils/template-actions.js`
- `src/utils/style-operations.js`
- `tests/phpunit/ActivityRepositoryTest.php`
- `tests/phpunit/ActivityPermissionsTest.php`
- `tests/phpunit/AgentControllerTest.php`
- `tests/phpunit/bootstrap.php`
- `src/admin/__tests__/activity-log.test.js`
- `src/admin/__tests__/activity-log-utils.test.js`
- `src/store/__tests__/activity-history.test.js`
- `src/store/__tests__/activity-history-state.test.js`
- `src/store/__tests__/store-actions.test.js`
- `src/components/__tests__/AIActivitySection.test.js`
- `src/components/__tests__/ActivitySessionBootstrap.test.js`
- `tests/e2e/flavor-agent.activity.spec.js`
- `docs/features/activity-and-audit.md`
- `docs/reference/activity-state-machine.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/shared-internals.md`
- `docs/reference/cross-surface-validation-gates.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/SOURCE_OF_TRUTH.md`
- `STATUS.md`

Review focus areas

1. Access control and privacy
- Confirm the admin page, REST routes, and repository methods enforce the intended boundary between global admin activity (`manage_options`) and scoped/contextual activity.
- Verify nonce usage, REST permissions, `global` query handling, and capability checks cannot expose site-wide audit rows to lower-privileged users.
- Check that prompt/request/payload metadata shown in the log is intentionally exposed, escaped, and free of raw secrets, credentials, or provider keys.
- Confirm user-facing links and labels are escaped, translated where appropriate, and safe for wp-admin contexts.

2. REST contract and request validation
- Trace the `GET /activity`, `POST /activity`, and undo/update routes through `Agent_Controller`.
- Verify argument defaults, sanitization, enum handling, boolean parsing, `per_page` caps, page behavior, filter operators, search handling, and sort fields match the UI and tests.
- Confirm invalid or unsupported filter/sort combinations fail predictably or degrade safely rather than producing malformed SQL or misleading responses.
- Check response shape stability for `items`, pagination totals/pages, summary counts, filter options, and admin projection metadata.

3. Repository query correctness and performance
- Review `query_admin()` and any fallback/history code paths for bounded queries, predictable pagination, and correct total counts.
- Confirm the admin page does not decode or scan the full activity table for common filter/search/sort requests when projected columns can serve the query.
- Verify admin projection backfill/freshness behavior cannot silently hide recent records or stale metadata.
- Check SQL placeholder usage, dynamic column/operator construction, date/window filtering, relative-day logic, timezone behavior, and filters crossing midnight.
- Confirm summary counts and filter options are computed over the intended filtered set, not accidentally limited to the current page.

4. Activity state model and undo semantics
- Compare implementation against `docs/reference/activity-state-machine.md`.
- Verify status resolution for applied, undone, review-only, blocked, failed, partial, stale, and diagnostic/request states.
- Confirm ordered undo constraints, terminal transition rules, blocked undo messaging, and history persistence are represented consistently in repository rows, REST output, UI labels, tests, and docs.
- Check that review-only recommendations are distinguishable from applied mutations and do not imply reversible changes.

5. Admin UI behavior
- Trace `src/admin/activity-log.js` and `src/admin/activity-log-utils.js` from boot data to rendered DataViews/DataForm output.
- Verify field IDs, filter option mapping, search, sort, pagination, per-page changes, reset behavior, and persisted view state all match the REST contract.
- Check loading, empty, error, stale response/race, and partial-data states.
- Confirm target links route correctly to editor, site editor/global styles, settings, posts/entities, template parts, and unknown/unavailable targets.
- Verify summary cards, status icons, request details, provider/model/config labels, and timestamps are accurate and not overclaiming certainty.

6. Metadata and provenance
- Confirm admin projection metadata correctly represents provider, selected provider, provider path, model, configuration owner, credential source, connector/native source, ability, route, reference, prompt, operation labels, block paths, post/entity refs, and request diagnostics.
- Check that labels shown in wp-admin are sourced from stable metadata rather than brittle string parsing where structured values exist.
- Verify all provenance fields remain consistent across create, update, undo, query, serialization, and docs.

7. CSS, WordPress Design System, and responsive states
- Review `activity-log.css`, `dataviews-runtime.css`, and `wpds-runtime.css` for fragile assumptions about generated markup, overflow, responsive layout, status styling, and summary-card density.
- Confirm the six-card summary grid remains readable across admin widths, long labels, long URLs, translated strings, and narrow viewports.
- Check that review/blocked/failed/undone/applied states are visually distinguishable without relying on color alone.
- Avoid subjective taste findings; flag measurable accessibility, overflow, contrast, responsive, or contract drift only.

8. Tests and documentation
- Verify the closest PHP, JS unit, and Playwright tests cover any behavior being relied on by the admin log.
- If you find missing coverage, name the exact test file and the scenario it should cover.
- Confirm docs in `docs/features/activity-and-audit.md`, `docs/reference/activity-state-machine.md`, route/reference docs, `STATUS.md`, and source-of-truth docs match current behavior.
- Treat cross-surface changes as requiring the additive gates described in `docs/reference/cross-surface-validation-gates.md`.

Suggested verification commands
- `composer run test:php -- --filter '(ActivityRepositoryTest|ActivityPermissionsTest|AgentControllerTest)'`
- `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js src/components/__tests__/AIActivitySection.test.js src/components/__tests__/ActivitySessionBootstrap.test.js`
- `npm run build`
- `npm run lint:js -- --quiet`
- `vendor/bin/phpcs --standard=phpcs.xml.dist inc/Admin/ActivityPage.php inc/Activity/Repository.php inc/Activity/Permissions.php inc/Activity/Serializer.php inc/REST/Agent_Controller.php tests/phpunit/ActivityRepositoryTest.php tests/phpunit/ActivityPermissionsTest.php tests/phpunit/AgentControllerTest.php`
- `npx playwright test tests/e2e/flavor-agent.activity.spec.js`
- `npm run check:docs`
- `git diff --check`

Output format
- Lead with findings, ordered by severity (`P0`, `P1`, `P2`, `P3`).
- For each finding include: title, exact file/line references, observed behavior, impact, and the smallest practical fix.
- Separate confirmed findings from open questions. Do not list an open question as a finding unless you verified a real defect.
- Include a short "Verification Reviewed" section naming commands you ran and commands you did not run.
- If there are no findings, say so plainly and list any remaining test or environment gaps.
- Keep the review anchored to the current checkout. Do not rely on stale plans, old docs, or assumptions when the live code says otherwise.
```
