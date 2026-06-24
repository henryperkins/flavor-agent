# AI Activity Row Actions And Discovery Implementation Plan

> Status: Archived 2026-06-24. Implemented same-day as the first AI Activity row-actions/discovery layer (linked-row banner, selected-row actions, passive discovery badges); retained only as historical execution context. Verification evidence lives in `STATUS.md`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a bounded row-scoped action and discovery layer for `Settings > AI Activity` so an operator can move from a selected activity row to the affected WordPress surface, focus/share a specific row, and pivot to closely related activity without manually rebuilding filters or digging through the deeper technical sections first.

**Architecture:** Keep the current DataViews feed plus custom detail sidebar. Reuse the existing target-link helper in `src/admin/activity-log-utils.js`, extract the current learning-report row link builder into a shared `buildActivityPermalink( adminUrl, activityId )`, keep the existing linked-row `?activity=` flow (`getLinkedActivityEntryId()` / `requestActivityId` in `src/admin/activity-log.js` and `Repository::query_admin()` / `resolve_admin_activity_page_for_id()` in `inc/Activity/Repository.php`), and reuse the admin filters the feed already supports (`status`, `surface`, `userId`, `postType`, `entityId`, `blockPath`). Add a normalized selected-row action model, a linked-row banner, and passive discovery badges in JS instead of inventing new REST routes, permissions, or a second focus contract.

**Tech Stack:** WordPress plugin admin React via `@wordpress/element`, `@wordpress/components`, `@wordpress/dataviews/wp`, repo CSS tokens, Jest via `@wordpress/scripts`, Playwright admin coverage, existing REST/admin repository contract.

---

## File Map

- Modify `src/admin/activity-log-utils.js`: extract `buildActivityPermalink( adminUrl, activityId )`, normalize selected-row action descriptors, and normalize passive discovery badges from existing row metadata.
- Modify `src/admin/activity-log.js`: render a linked-row banner, selected-row action strip, row-pivot handlers, and feed badges while keeping the current click-to-select feed.
- Modify `src/admin/activity-log.css`: style the linked-row banner, action strip, grouped actions, and badges without nesting new cards inside the sidebar card.
- Modify `src/admin/__tests__/activity-log-utils.test.js`: cover the new link/action/badge helpers.
- Modify `src/admin/__tests__/activity-log.test.js`: cover the banner, selected-row actions, filter pivots, and badge rendering.
- Modify `tests/e2e/flavor-agent.activity.spec.js`: keep browser proof for the admin surface honest after the new action/discovery affordances land.
- Update docs after code passes: `docs/features/activity-and-audit.md`, `docs/reference/current-open-work.md`, `STATUS.md`, and `docs/SOURCE_OF_TRUTH.md`.

## Non-Goals

- No rich visual diff viewer in this slice.
- No cross-operator workflow, notifications, or approval inbox expansion.
- No DataViews Activity-layout or Details-layout migration in this slice.
- No new REST routes, route args, permission changes, or activity-table columns unless tests prove a hard gap.
- No destructive feed-row buttons; approve/reject stays in the governance section and undo stays on its existing surfaces.

## Implementation Rules

- Reuse the existing `activity` query-param focus contract; do not invent a second deep-link or permalink shape.
- Keep the feed row click-to-select model. The new actions live in the selected-row experience, not a speculative inline row-menu API.
- The selected-row action normalizer in `activity-log-utils.js` is the source of truth for action visibility. Components should render only the descriptors it returns.
- Only generate row pivots from fields the admin feed already filters on today.
- Pivot semantics stay explicit: `surface`, `status`, `postType`, and `userId` pivots use the existing explicit `is` operator; `entityId` and `blockPath` pivots use the existing text-filter operator (`contains`) unless a failing test proves a narrower operator is required.
- Keep target labels honest by reusing `buildActivityTargetLink()` output; never imply the plugin can open a more specific Style Book or template-part subview than it really can.
- Render the linked-row banner as a simple notice-style strip outside the sidebar card so the existing masthead, toolbar, and `flavor-agent-activity-log-details` region semantics stay intact.
- Discovery badges are passive only and must be backed by real row data such as pending governance state, `aiRequestLogId`, or attestation id.
- Keep badge growth intentionally small in this slice: retain the existing pending-governance badge and add at most two more compact passive badges (`AI request`, `Attestation`) unless tests prove a clearer shared label set is needed.
- If a row lacks the backing data for an action, omit the action instead of rendering a disabled control with invented copy.
- Preserve keyboard and screen-reader clarity for the selected-row details region and any new banner/action labels.
- Prefer a JS-only change. No PHP changes are expected unless tests prove the current admin metadata shape cannot support the selected-row actions or badges honestly.
- Use TDD for each production change: write the failing test first, run it, implement the minimal fix, rerun.

---

### Task 1: Extract Row Link, Action, And Badge Helpers

**Files:**
- Modify: `src/admin/activity-log-utils.js`
- Test: `src/admin/__tests__/activity-log-utils.test.js`

- [x] **Step 1: Add failing helper tests**

Add tests that prove `buildActivityPermalink( adminUrl, activityId )` builds the same `?activity=<id>` URL the learning-report links already use, selected-row action descriptors are only exposed when their backing data exists, and passive discovery badges are normalized from pending-governance, AI-request-log, and attestation metadata without inventing new state.

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js
```
Expected: FAIL because the shared helpers do not exist yet and learning-report row links are still assembled in-line.

- [x] **Step 2: Implement minimal helper extraction**

Extract `buildActivityPermalink()` from the existing learning-report code path, add a small pure selected-row action normalizer, and add a passive badge normalizer. Keep the helper inputs narrow: normalized entry data plus admin URLs, not component state. The action normalizer should be the only place that decides whether `Open target`, `Open focused view`, `Same surface`, `Same user`, `Same entity`, or `Same block path` are renderable.

- [x] **Step 3: Verify helper coverage**

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js
```
Expected: PASS.

---

### Task 2: Add The Linked-Row Banner And Selected-Row Action Strip

**Files:**
- Modify: `src/admin/activity-log.js`
- Modify: `src/admin/activity-log.css`
- Test: `src/admin/__tests__/activity-log.test.js`

- [x] **Step 1: Add failing app tests for the new selected-row actions**

Add tests that prove a linked row opened through `?activity=` shows a visible notice-style banner with a clear action, the selected-row sidebar exposes a compact action strip, the action strip only renders controls the selected row can actually back, and the existing details-region labeling still points at `flavor-agent-activity-log-details-title`. Cover at least: `Open target`, `Open focused view`, and one or more feed pivots such as `Same surface`, `Same user`, or `Same entity`.

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```
Expected: FAIL because the app still has only the single target button in the sidebar header and no linked-row banner.

- [x] **Step 2: Implement the banner and action strip**

Render a visible linked-row banner when `requestActivityId` is active, wire its clear action through `exitLinkedActivityMode()`, and replace the one-off sidebar target button with a grouped selected-row action strip that can show the honest target link, the focused-row permalink, and filter pivots built from already-supported fields. Keep the strip inside the existing sidebar header/body structure; do not introduce another `Card` wrapper.

- [x] **Step 3: Keep row pivots bounded to supported filters**

Implement the filter-pivot handlers so they reset `page` to `1`, replace only the filters they own, preserve unrelated filters when that stays honest, and clear linked-row mode before loading the broader related feed. Keep the operator mapping explicit: `surface`, `userId`, and similar exact pivots use `is`; `entityId` and `blockPath` use `contains` so the pivot matches the current feed contract.

- [x] **Step 4: Verify selected-row action behavior**

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```
Expected: PASS.

---

### Task 3: Add Passive Discovery Badges To The Feed

**Files:**
- Modify: `src/admin/activity-log.js`
- Modify: `src/admin/activity-log.css`
- Test: `src/admin/__tests__/activity-log.test.js`

- [x] **Step 1: Add failing badge-rendering tests**

Add tests that prove the feed keeps the existing pending-approval badge and can also expose compact passive badges for rows with an AI request log or attestation evidence, while leaving ordinary rows uncluttered and avoiding any new badge click behavior.

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```
Expected: FAIL because only the pending-approval badge exists today.

- [x] **Step 2: Implement minimal feed badges**

Render the normalized badges near the title cell so an operator can see which rows carry deeper evidence before opening the detail sections. Keep the badge count intentionally small and reuse one badge style family; this slice should add only the compact `AI request` and `Attestation` badges alongside the existing pending-governance badge.

- [x] **Step 3: Verify badge behavior**

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```
Expected: PASS.

---

### Task 4: Browser Proof For The Admin Surface

**Files:**
- Modify: `tests/e2e/flavor-agent.activity.spec.js`

- [x] **Step 1: Extend the mocked admin-page browser proof**

Update the mocked AI Activity Playwright spec so it proves the new linked-row banner, selected-row action strip, and passive discovery badges in a deterministic browser environment.

Run:
```bash
npm run test:e2e:playground -- tests/e2e/flavor-agent.activity.spec.js
```
Expected: FAIL until the new UI is rendered.

- [x] **Step 2: Keep the WP70 admin smoke honest**

Extend the existing WP70 admin proof only where the real repository-backed page can demonstrate the selected-row action strip or linked-row handling without new fixture complexity.

Run:
```bash
npm run test:e2e:wp70 -- tests/e2e/flavor-agent.activity.spec.js
```
Expected: FAIL only if the real admin markup is missing the new affordances or the selectors drift.

---

### Task 5: Documentation And Queue Closeout

**Files:**
- Modify: `docs/features/activity-and-audit.md`
- Modify: `docs/reference/current-open-work.md`
- Modify: `STATUS.md`
- Modify: `docs/SOURCE_OF_TRUTH.md`

- [x] **Step 1: Update docs after code passes**

Document the selected-row action/discovery layer as a bounded governance-console improvement. Update the current docs language from "row actions/discovery remain open" to "the first row actions/discovery layer shipped" while keeping the rich visual diff viewer and cross-operator workflows open. Keep the admin surface framed as a governance console rather than a full observability product.

- [x] **Step 2: Verify docs**

Run:
```bash
npm run check:docs
```
Expected: PASS.

---

### Task 6: Final Verification

**Files:**
- All changed files.

- [x] **Step 1: Run admin JS verification**

```bash
npm run build
npm run lint:js
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js
```

- [x] **Step 2: Run browser verification**

```bash
npm run test:e2e:playground -- tests/e2e/flavor-agent.activity.spec.js
npm run test:e2e:wp70 -- tests/e2e/flavor-agent.activity.spec.js
```

- [x] **Step 3: Run docs and whitespace checks**

```bash
npm run check:docs
git diff --check
```

- [x] **Step 4: Add PHP verification only if the implementation crosses the admin REST/repository contract**

If the implementation has to touch `inc/Activity/Repository.php` or `inc/REST/Agent_Controller.php`, append the matching targeted PHPUnit commands before closing the plan. Otherwise keep this slice JS-only.
