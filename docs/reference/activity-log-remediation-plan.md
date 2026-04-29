# AI Activity Log Remediation Plan

Date: 2026-04-29

Scope: Address the confirmed review findings for the WordPress admin AI Activity page at `settings_page/flavor-agent-activity` and the server-backed activity/audit log. This plan intentionally avoids broader redesigns and keeps the fixes tied to the two confirmed regressions.

## Findings Covered

1. `P2`: Invalid or partial admin date filters return unfiltered audit results in the projected repository path.
2. `P3`: Projected operation filter options can duplicate the same effective filter value.

## Target Outcomes

- Admin date filters never silently broaden to an unfiltered site-wide activity query when the request contains invalid, incomplete, or inverted date input.
- Projected and fallback admin query paths apply the same date-filter semantics.
- The REST contract rejects malformed date filter requests predictably.
- The admin UI avoids sending incomplete date filters while a user is still composing a filter.
- Operation filter options expose one choice per effective `operationType` value unless the query semantics are also made distinct.
- PHP, JS, Playwright, lint, and docs verification cover the changed behavior.

## Solution 1: Date Filter Contract

### Current Evidence

- `src/admin/activity-log.js` can serialize `dayOperator=between` with only `day` or only `dayEnd` when the view state contains a partial range.
- `Agent_Controller::handle_get_activity()` passes `day`, `dayEnd`, `dayOperator`, `dayRelativeValue`, and `dayRelativeUnit` directly into `ActivityRepository::query_admin()`.
- `ActivityRepository::build_admin_day_sql_filters()` returns an empty SQL filter list for incomplete `between`, inverted `between`, invalid dates, or invalid relative values.
- In the projected path, an empty SQL filter list means "no date filter."
- The fallback in-memory path does not match incomplete or inverted `between` filters, so the two runtime paths drift.

### Implementation Plan

1. Add a shared repository helper that normalizes admin date filters.
   - Input: raw `query_admin()` filter array plus the resolved activity timezone.
   - Output: a structured result such as:
     - `active`: whether the caller requested a date filter.
     - `valid`: whether the requested filter is internally complete and valid.
     - `operator`: normalized operator.
     - `start` / `end`: UTC timestamp bounds for `on`, `before`, `after`, and `between`.
     - `relativeValue` / `relativeUnit` / `threshold`: normalized relative filter data.
   - Supported operators: `on`, `before`, `after`, `between`, `inThePast`, `over`.
   - Supported relative units: use the units currently supported by `resolve_relative_threshold_timestamp()`.

2. Update `ActivityRepository::has_admin_day_filter()`.
   - Treat a request as active when it includes any date-filter value or relative value.
   - Do not make `dayOperator` alone active if no value is present; this preserves the "operator selected but no date entered" UI state as no-op.
   - Treat one-sided `between` as active and invalid, not inactive.

3. Update `ActivityRepository::build_admin_day_sql_filters()`.
   - Use the normalized date-filter helper.
   - If `active` is false, return no date SQL clauses.
   - If `active` is true and `valid` is false, return a no-match clause such as `1 = 0`.
   - If valid, return the existing bounded `created_at` clauses.
   - Keep all date bounds generated in UTC while honoring the site timezone for local day resolution.

4. Update `ActivityRepository::matches_day_filter()`.
   - Use the same normalized date-filter helper.
   - If `active` is false, return true for date matching.
   - If `active` is true and `valid` is false, return false.
   - If valid, apply the same comparison semantics as the SQL path.

5. Add REST request validation in `Agent_Controller`.
   - Add a private validator for GET `/flavor-agent/v1/activity` date-filter combinations before calling the repository.
   - Return `WP_Error` with status `400` for:
     - Unsupported `dayOperator`.
     - Invalid `day` or `dayEnd` date strings when supplied.
     - `between` with exactly one bound.
     - `between` where `day` is after `dayEnd`.
     - Relative filters without a positive `dayRelativeValue`.
     - Unsupported `dayRelativeUnit`.
   - Keep capability checks unchanged: global requests still require `manage_options`, scoped requests still use contextual permissions.

6. Update `src/admin/activity-log.js`.
   - In `appendDayFilter()`, only serialize `between` when both range values exist and `start <= end`.
   - Only serialize `on`, `before`, and `after` when a valid date value exists.
   - Only serialize `inThePast` and `over` when the relative value is a positive integer and the unit is allowed.
   - If persisted view state contains an invalid partial date filter, omit the date params from the request and let the existing reset controls clear the view.

### Tests

Add or update `tests/phpunit/ActivityRepositoryTest.php`:

- Projected `query_admin()` with `dayOperator=between` and missing `dayEnd` returns zero entries and `totalItems=0`.
- Projected `query_admin()` with `dayOperator=between` and missing `day` returns zero entries and `totalItems=0`.
- Projected `query_admin()` with inverted `between` dates returns zero entries and `totalItems=0`.
- Projected `query_admin()` with invalid `day` for `on`, `before`, or `after` returns zero entries and `totalItems=0`.
- Fallback and projected paths return the same result for invalid active date filters. Force fallback by simulating pending projection backfill if the existing test harness supports that state.

Add or update `tests/phpunit/AgentControllerTest.php`:

- GET `/activity?global=1&dayOperator=between&day=2026-03-01` returns `400`.
- GET `/activity?global=1&dayOperator=between&day=2026-03-31&dayEnd=2026-03-01` returns `400`.
- GET `/activity?global=1&dayOperator=banana&day=2026-03-01` returns `400`.
- A valid `between` request still returns `200` with the existing admin response shape.

Add or update `src/admin/__tests__/activity-log.test.js`:

- Full `between` filters still serialize `dayOperator`, `day`, and `dayEnd`.
- Partial `between` filters do not serialize a date filter.
- Inverted `between` filters do not serialize a date filter.
- Valid relative filters still serialize `dayRelativeValue` and `dayRelativeUnit`.

## Solution 2: Unique Operation Filter Options

### Current Evidence

- `ActivityRepository::derive_admin_operation_metadata()` maps `insert_pattern` and `insert_block` to the same filter value, `insert`, with different labels.
- It also maps `replace_template_part` and `assign_template_part` to the same filter value, `replace`, with different labels.
- `ActivityRepository::query_admin_sql_filter_options()` groups projected operation options by both value and label, then appends every row to the response.
- `src/admin/activity-log.js` trusts server-provided `filterOptions.operationType` and passes them directly to DataViews.
- The fallback filter-options path deduplicates by value, so projected and fallback behavior drift.

### Implementation Plan

1. Keep the existing effective filter values.
   - Do not introduce new operation values unless the query semantics are also split.
   - `operationType=insert` should continue to match both inserted patterns and inserted blocks.
   - `operationType=replace` should continue to match both template-part replacement and assignment rows.

2. Dedupe projected SQL filter options by value.
   - In `ActivityRepository::query_admin_sql_filter_options()`, store options in an associative map keyed by `value` before converting to the response list.
   - Match the fallback behavior in `build_admin_filter_options()`.

3. Use canonical labels for grouped operation values.
   - Prefer labels that describe the effective filter scope:
     - `insert` -> `Insert`
     - `replace` -> `Replace`
     - `apply-style` -> `Apply style`
     - `modify-attributes` -> `Modify attributes`
   - Keep row-level labels unchanged in serialized activity entries, so individual rows can still say `Insert pattern`, `Insert block`, `Replace template part`, or `Assign template part`.

4. Add a defensive client dedupe.
   - In `getServerFilterOptions()`, dedupe options by `value` after validating shape.
   - This protects the UI if older servers or future projection bugs return duplicates.
   - Preserve the first valid option for a value after server canonicalization.

### Tests

Add or update `tests/phpunit/ActivityRepositoryTest.php`:

- Seed one `insert_pattern` row and one `insert_block` row.
- Assert `query_admin()['filterOptions']['operationType']` contains only one `insert` option.
- Seed one `replace_template_part` row and one `assign_template_part` row.
- Assert only one `replace` option appears.
- Assert row-level `operationTypeLabel` values remain specific on entries.

Add or update `src/admin/__tests__/activity-log.test.js`:

- Inject duplicate server `operationType` options and assert the DataViews field receives one option per value.

## Documentation Updates

Update docs only where behavior is contract-relevant:

- `docs/reference/abilities-and-routes.md`
  - Document that malformed admin date filters return `400` rather than broadening the query.
  - Clarify that admin operation filters are grouped by effective action type.
- `docs/features/activity-and-audit.md`
  - Keep the relative-time statement aligned with the final validation behavior if wording changes.
- `docs/SOURCE_OF_TRUTH.md`
  - Update only if the admin audit page contract text needs to mention date-filter validation or grouped operation filters.

Run `npm run check:docs` after any docs edits.

## Verification Plan

Run the targeted checks first:

```bash
composer run test:php -- --filter '(ActivityRepositoryTest|ActivityPermissionsTest|ActivityPageTest|AgentControllerTest)'
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js src/components/__tests__/AIActivitySection.test.js src/components/__tests__/ActivitySessionBootstrap.test.js
vendor/bin/phpcs --standard=phpcs.xml.dist inc/Admin/ActivityPage.php inc/Activity/Repository.php inc/Activity/Permissions.php inc/Activity/Serializer.php inc/REST/Agent_Controller.php tests/phpunit/ActivityRepositoryTest.php tests/phpunit/ActivityPermissionsTest.php tests/phpunit/ActivityPageTest.php tests/phpunit/AgentControllerTest.php
npm run lint:js -- --quiet
npx playwright test tests/e2e/flavor-agent.activity.spec.js
npm run check:docs
git diff --check
```

Run the production asset build after source changes are complete:

```bash
npm run build
```

If the build updates generated files under `build/`, include those generated changes only if this repository expects compiled assets to be committed for the change.

## Acceptance Criteria

- Invalid active date filters cannot return a broader result set than a valid filtered query.
- Projected and fallback admin query paths agree for valid and invalid date filters.
- REST callers receive predictable `400` errors for malformed date-filter combinations.
- The admin UI does not send partial or inverted date ranges.
- `filterOptions.operationType` has unique `value` entries in both projected and fallback paths.
- Individual activity rows keep their specific operation labels.
- The focused PHP and JS tests cover the two regressions.
- The focused Playwright activity page spec still passes.
- No source-of-truth or feature documentation contradicts the implemented behavior.
