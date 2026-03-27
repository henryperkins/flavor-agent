# AI Activity UI Review Remediation Plan

> **For agentic workers:** Use a test-first flow. These findings are in the admin React surface, so add or extend Jest coverage before changing behavior. Steps use checkbox syntax for tracking.

**Goal:** Address all four findings from the March 27, 2026 AI Activity UI review:
1. stale persisted pagination can render a false empty state,
2. the detail sidebar can drift out of sync with the visible feed,
3. the icon-only settings button in the detail card is unlabeled,
4. the feed uses imperative navigation for "Open affected target".

**Architecture:** Keep the fix set inside the admin JS bundle. Add one app-level Jest harness for `ActivityLogApp`, clamp persisted page state against live pagination before rendering, derive selection from the currently visible rows instead of the full dataset, label the icon-only settings control, and remove the imperative "open target" row action in favor of the existing anchor-backed links already present in the sidebar/detail form.

**Tech Stack:** JavaScript, Jest, `@wordpress/components`, `@wordpress/dataviews`, `@wordpress/api-fetch`

---

### Task 1: Add Admin Activity Log Interaction Coverage

**Why:** Current tests cover utilities and the editor-side `AIActivitySection`, but none of the reviewed findings live there. All four issues sit in `ActivityLogApp` state and action wiring.

**Files:**
- Modify: `src/admin/activity-log.js`
- Add: `src/admin/__tests__/activity-log.test.js`

- [ ] **Step 1: Export a testable app surface**

Update `src/admin/activity-log.js` so `ActivityLogApp` is a named export while keeping the current root bootstrap behavior intact.

- [ ] **Step 2: Create an app-level Jest harness**

Add `src/admin/__tests__/activity-log.test.js` with lightweight mocks for:
- `@wordpress/api-fetch`
- `@wordpress/components`
- `@wordpress/dataviews/wp`

Mirror the repo’s existing React test style:
- use `createRoot`
- wrap updates with `act`
- avoid introducing a new test framework unless already present

- [ ] **Step 3: Centralize reusable boot data + entries**

Inside the new test file, add helpers for:
- `bootData`
- a small activity entry factory
- seeding `window.localStorage` for persisted view state

- [ ] **Step 4: Verify the new harness can render the page**

Start with a smoke test that:
- mocks a successful `apiFetch` response,
- renders `ActivityLogApp`,
- waits for the rows to appear,
- asserts the error state is absent.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```

Expected: PASS once the test harness is complete.

---

### Task 2: Clamp Persisted Pagination To Live Results

**Why:** `normalizeStoredActivityView()` accepts any positive `page`, and `filterSortAndPaginate()` returns an empty `data` array for out-of-range pages. A stale saved page therefore produces a false "No matching activity" screen even when entries exist.

**Files:**
- Modify: `src/admin/activity-log.js`
- Modify: `src/admin/activity-log-utils.js`
- Modify: `src/admin/__tests__/activity-log-utils.test.js`
- Add/Modify: `src/admin/__tests__/activity-log.test.js`

- [ ] **Step 1: Write the failing regression test**

In `src/admin/__tests__/activity-log.test.js`:
- seed persisted view state with `page: 5`,
- mock a server response with fewer than one page of results at the saved page size,
- render the app,
- assert the feed shows real entries rather than the empty state.

This test should fail against current code because `filterSortAndPaginate()` returns `data: []` for the stale page.

- [ ] **Step 2: Add a pure page-clamping helper**

In `src/admin/activity-log-utils.js`, add a helper that:
- accepts a normalized view plus pagination metadata,
- clamps `page` to `1..totalPages`,
- leaves the rest of the view untouched.

Suggested shape:

```js
export function clampActivityViewPage( view, paginationInfo = {} ) {}
```

- [ ] **Step 3: Unit test the helper directly**

Extend `src/admin/__tests__/activity-log-utils.test.js` to cover:
- page already in range,
- page above `totalPages`,
- empty/unknown pagination data,
- `totalPages: 0` collapsing back to page `1`.

- [ ] **Step 4: Render with an effective clamped view**

In `ActivityLogApp`:
- compute processed data from an effective view whose page is clamped to the current pagination metadata,
- avoid rendering a transient empty feed when the only problem is an out-of-range saved page,
- sync the corrected page back into React state and persisted storage after render.

The important behavior is:
- users should immediately see the last available page of entries,
- the saved view should self-heal from `page: 5` to the last valid page.

- [ ] **Step 5: Re-run targeted tests**

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js
```

Expected: PASS.

---

### Task 3: Keep The Detail Sidebar Synced To The Visible Feed

**Why:** The current sidebar lookup falls back from `processedData.data` to the full `entries` array. After search, filtering, or page changes, the details panel can keep showing an entry that is no longer visible in the feed.

**Files:**
- Modify: `src/admin/activity-log.js`
- Add/Modify: `src/admin/__tests__/activity-log.test.js`

- [ ] **Step 1: Write a failing selection-sync test**

Add a test that:
1. renders multiple entries,
2. selects a non-default entry,
3. changes the view so that entry is no longer visible on the current filtered/page result,
4. asserts the sidebar reselects the first visible row or clears when no visible rows remain.

- [ ] **Step 2: Remove the full-dataset fallback**

In `src/admin/activity-log.js`, stop resolving `selectedEntry` from `entries.find(...)`. Selection should be driven by the currently visible `processedData.data` rows only.

- [ ] **Step 3: Add a visible-row synchronization effect**

Add an effect that watches the visible list and:
- preserves the current selection when it remains visible,
- switches to the first visible row when the selected item disappears from the current result set,
- clears selection when the current result set is empty.

This effect should key off the displayed rows, not only the raw `entries` array.

- [ ] **Step 4: Re-run the app test file**

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```

Expected: PASS.

---

### Task 4: Add An Accessible Name To The Icon-Only Settings Button

**Why:** The settings control in the detail-card header renders only an icon. Screen readers currently encounter an unlabeled interactive element.

**Files:**
- Modify: `src/admin/activity-log.js`
- Add/Modify: `src/admin/__tests__/activity-log.test.js`

- [ ] **Step 1: Write the failing accessibility regression**

Add a test that renders the detail header with a selected entry and asserts the settings control exposes an accessible name such as `Open Flavor Agent settings`.

- [ ] **Step 2: Label the control**

Update the icon-only `Button` in `ActivityEntryDetails` to include an accessible label.

Use a button/anchor-compatible label mechanism supported by the current component tree, for example:
- `aria-label`,
- and, if appropriate for the WordPress button variant in use, matching tooltip text.

- [ ] **Step 3: Keep the visual treatment unchanged**

Do not add visible text unless design requirements change. This fix is accessibility-only.

- [ ] **Step 4: Re-run the app test file**

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```

Expected: PASS.

---

### Task 5: Replace Imperative Target Navigation With Anchor-Backed Navigation

**Why:** The DataViews row action uses `window.location.assign()`. That bypasses standard link behavior such as Cmd/Ctrl-click, middle-click, and opening in a new tab.

**Files:**
- Modify: `src/admin/activity-log.js`
- Add/Modify: `src/admin/__tests__/activity-log.test.js`

- [ ] **Step 1: Write the failing behavior test**

Add a test that inspects the actions passed into `DataViews` and confirms the feed no longer exposes an imperative `open-target` callback.

If the DataViews mock makes that awkward, assert the inverse:
- no `window.location.assign` spy is exercised by feed actions,
- target navigation remains available through real `href`-backed controls in the detail panel.

- [ ] **Step 2: Remove the imperative row action**

In `src/admin/activity-log.js`, remove the `open-target` action from the `actions` array passed to `DataViews`.

Keep `inspect` as the feed action.

- [ ] **Step 3: Preserve native navigation paths**

Retain the existing anchor-backed target links already present in:
- the detail-card header `Open target` button,
- the `Affected target` detail field.

These already satisfy the requirement because they use `href` instead of imperative navigation.

- [ ] **Step 4: Re-run the app test file**

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```

Expected: PASS.

---

### Final Verification

- [ ] **Step 1: Run targeted unit coverage**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js src/components/__tests__/AIActivitySection.test.js
```

- [ ] **Step 2: Run JS lint**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
npm run lint:js
```

- [ ] **Step 3: Manual wp-admin verification**

In `Settings > AI Activity`:
1. Seed a stale saved page in `localStorage` and confirm the screen self-recovers to a valid page instead of showing a false empty state.
2. Select a row, then change search/filter/page so it disappears; confirm the sidebar updates to the currently visible row set.
3. Inspect the detail header with a screen reader or browser accessibility tree; confirm the settings control is labeled.
4. Confirm target navigation is available through real links in the sidebar/detail form and the feed no longer relies on imperative navigation.

- [ ] **Step 4: Commit**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
git add src/admin/activity-log.js src/admin/activity-log-utils.js src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js docs/superpowers/plans/2026-03-27-ai-activity-ui-review-remediation.md
git commit -m "docs: add AI activity UI remediation plan"
```
