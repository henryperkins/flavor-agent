# AI Activity Rich Visual Diff Viewer Implementation Plan

> Archived 2026-06-24 after the rich visual diff viewer shipped in `Settings > AI Activity`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a richer visual diff viewer for style-governance rows in `Settings > AI Activity` so operators can inspect before/proposed/after changes faster than the current plain comparison table and raw state snapshots.

**Architecture:** Keep the existing admin activity route, `getGovernanceDetails()` model, and server-provided `before` / `after` state payloads. Extend the current style comparison helpers in `src/admin/activity-log-utils.js` so they emit presentation-ready diff rows with explicit value kinds, swatch or chip metadata, and viewer statuses that preserve the existing governance lifecycle semantics already derived from `entry.status` / `apply.status`. The visual metadata itself should still come only from the current `set_styles`, `set_block_styles`, and `set_theme_variation` operations plus the stored snapshots. Replace the current plain `GovernanceComparisonTable` in `src/admin/activity-log.js` with a richer, style-only viewer that reuses those rows, leaves the raw `State snapshots` section intact as fallback evidence, and never implies that a pending/rejected/failed row changed the site. Theme-variation rows stay honest to the current payload: if the entry does not expose a truthful pre/post variation identity, render only a proposed variation chip plus the raw snapshot fallback rather than inventing a before/after variation diff. This slice stays JS-only unless tests prove the current payload cannot honestly support the viewer.

**Tech Stack:** admin React via `@wordpress/element`, `@wordpress/components`, DataViews, repo CSS tokens, Jest via `@wordpress/scripts`, Playwright admin coverage.

---

## File Map

- Modify `src/admin/activity-log-utils.js`: add visual-diff row normalization on top of the existing governance comparison helpers.
- Modify `src/admin/activity-log.js`: replace the plain comparison table with a richer style-only viewer in the governance evidence section.
- Modify `src/admin/activity-log.css`: add diff-viewer layout, swatch, chip, and status styles without nesting a second card inside the selected-row detail card.
- Modify `src/admin/__tests__/activity-log-utils.test.js`: cover value-kind normalization, swatch metadata, and lifecycle-accurate pending/applied/undone/blocked behavior.
- Modify `src/admin/__tests__/activity-log.test.js`: cover rendered visual diff states across lifecycle rows, fallback rows, and the existing details-section ordering.
- Modify `tests/e2e/flavor-agent.activity.spec.js`: keep the mocked admin browser proof honest after the richer diff viewer lands.
- Modify `tests/e2e/flavor-agent.approvals.spec.js`: keep the real WP70 governance/approval browser proof honest after the viewer replaces the old comparison table.
- Update docs after code passes: `docs/features/activity-and-audit.md`, `docs/reference/current-open-work.md`, `docs/reference/abilities-and-routes.md`, `docs/releases/v0.1.0.md`, `docs/flavoragentportfoliopackage.md`, `STATUS.md`, and `docs/SOURCE_OF_TRUTH.md`.

## Non-Goals

- No new REST routes, route args, DB columns, or serializer fields for this slice.
- No generic diff framework for block, template, template-part, content, or request-diagnostic rows.
- No new executor surfaces or cross-operator workflow.
- No removal of the raw `State snapshots` section; raw evidence stays available below the richer viewer.
- No design-system rewrite of the full admin page; keep the linked-row banner, selected-row actions, passive badges, and governance evidence layout as the baseline.

## Implementation Rules

- The richer viewer is style-only: support `set_styles`, `set_block_styles`, and `set_theme_variation`; unknown operations must fall back to safe text rows instead of pretending to be visual diffs.
- Pending, rejected, expired, and failed rows must read as "proposed only"; they must never show an applied-state swatch or imply the site changed.
- Applied, undone, and blocked rows may reuse the same viewer, but their status treatment must come from the existing lifecycle model (`entry.status` / `apply.status`) rather than inferred from display values or operation titles alone.
- `set_theme_variation` rows may only show a proposed variation chip unless the current payload exposes a truthful before/after variation identity. Do not label a variation as applied or unchanged from the title alone.
- Prefer deriving all display metadata from the existing comparison rows and snapshots. If a display cannot be supported honestly from current data, fall back to plain text instead of inventing semantics.
- Keep raw diagnostic text and raw state snapshots available after the richer viewer so an operator can still inspect the underlying evidence.
- Use TDD for each production change: write the failing test first, run it, implement the minimal fix, rerun.
- Stop and write a separate plan if truthful rendering requires new PHP payload fields or a second deep-link contract.

---

### Task 1: Normalize Rich Style Diff Rows

**Files:**
- Modify: `src/admin/activity-log-utils.js`
- Test: `src/admin/__tests__/activity-log-utils.test.js`

- [ ] **Step 1: Add failing helper tests**

Add tests that prove the governance comparison helpers can normalize rich viewer rows for:

- Global Styles color preset changes with swatch-ready metadata.
- Style Book block-scoped style changes with block labels preserved.
- Theme variation rows as proposed variation chips, with before/after text only when the current payload can support it honestly.
- Pending rows that show baseline/proposed values without implying an applied after-state.
- Undone and blocked rows that preserve their existing lifecycle treatment instead of collapsing into generic applied/proposed display states.
- Unknown operations that fall back to a plain text row with `unsupported` status.

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js
```
Expected: FAIL because the richer viewer metadata does not exist yet.

- [ ] **Step 2: Implement minimal row normalization**

Add a small exported helper such as `getStyleVisualDiffRows( entry )` that builds on the current comparison logic and emits:

- `kind` (`color`, `spacing`, `variation`, `text`, `unsupported`)
- `label`
- normalized `before`, `proposed`, and `after` display strings
- optional swatch or chip metadata for renderable values
- a bounded viewer `status` that preserves the current lifecycle semantics already derived by the governance helpers, not by re-inferring state from the operation labels or display values

For `set_theme_variation`, allow the helper to emit a proposed-only chip state when the stored snapshots do not reveal a truthful current/applied variation identity.

Keep the existing plain comparison helper available until the UI migration is complete.

- [ ] **Step 3: Verify helper coverage**

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js
```
Expected: PASS.

---

### Task 2: Replace The Plain Comparison Table With A Rich Viewer

**Files:**
- Modify: `src/admin/activity-log.js`
- Modify: `src/admin/activity-log.css`
- Test: `src/admin/__tests__/activity-log.test.js`

- [ ] **Step 1: Add failing admin UI tests**

Add tests that prove the selected-row governance section:

- renders a rich visual diff viewer instead of the old plain comparison grid,
- shows swatches or chips for supported values,
- keeps unsupported rows as plain text fallbacks,
- keeps pending rows labeled as proposed-only,
- keeps undone and blocked rows visually distinct from both proposed-only and currently applied states,
- and still leaves the `State snapshots` detail section available below the richer viewer.

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```
Expected: FAIL because the admin UI still renders the plain comparison table.

- [ ] **Step 2: Implement the visual diff viewer**

Replace `GovernanceComparisonTable` with a richer viewer component that:

- renders status-aware rows grouped under the existing governance evidence section,
- uses color swatches, variation pills, and bounded value chips where the data supports them,
- falls back to plain text cells for unsupported or ambiguous rows,
- keeps theme-variation rows in a proposed-only presentation when the payload does not prove a truthful before/after variation identity,
- and preserves the existing banner, summary, operation lists, provenance, decision controls, and raw snapshots ordering.

Add only the CSS needed for the viewer itself; do not widen into a general admin redesign.

- [ ] **Step 3: Verify the selected-row UI**

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```
Expected: PASS.

---

### Task 3: Browser Proof For The Governance Viewer

**Files:**
- Modify: `tests/e2e/flavor-agent.activity.spec.js`
- Modify: `tests/e2e/flavor-agent.approvals.spec.js`

- [ ] **Step 1: Extend the mocked admin browser proof**

Update the mocked AI Activity Playwright spec so it proves the richer viewer renders at least one visual diff row for a style-governance entry and keeps pending/proposed semantics honest.

Run:
```bash
npm run test:e2e:playground -- tests/e2e/flavor-agent.activity.spec.js
```
Expected: FAIL until the richer viewer is rendered.

- [ ] **Step 2: Keep the WP70 admin smoke honest**

Extend the existing WP70 MySQL governance/approval spec only where the real page can demonstrate the viewer without adding fixture-only data contracts. Keep the existing decision-route and fail-closed assertions intact while updating any selectors that currently depend on the plain comparison table roles. Do not invent new real-backend fixtures just to cover `undone` or `blocked` viewer states if the current seeded admin flow cannot demonstrate them honestly; keep those lifecycle guarantees in Jest.

Run:
```bash
npm run test:e2e:wp70 -- tests/e2e/flavor-agent.approvals.spec.js
```
Expected: FAIL only if the real admin markup or selectors drift.

---

### Task 4: Documentation And Queue Closeout

**Files:**
- Modify: `docs/features/activity-and-audit.md`
- Modify: `docs/reference/current-open-work.md`
- Modify: `docs/reference/abilities-and-routes.md`
- Modify: `docs/releases/v0.1.0.md`
- Modify: `docs/flavoragentportfoliopackage.md`
- Modify: `STATUS.md`
- Modify: `docs/SOURCE_OF_TRUTH.md`

- [ ] **Step 1: Update docs after code passes**

Document the richer visual diff viewer as a shipped bounded governance-console improvement. Update the current docs language from "no rich visual diff viewer yet" to "the first rich visual diff layer shipped," sweep the active product/status docs that still carry the old open-state wording, keep template/template-part external-apply executors and cross-operator workflows open, and archive this plan under `docs/superpowers/plans/archive/` once the slice lands. Do not rewrite archived historical plans or this plan's historical task text just to satisfy the verification grep.

- [ ] **Step 2: Verify docs**

Run:
```bash
! rg -n "no rich visual diff viewer yet|there is still no rich visual diff viewer|richer visual diff inspection.*open|rich visual diff viewer.*open" docs/features/activity-and-audit.md docs/reference/current-open-work.md docs/reference/abilities-and-routes.md docs/releases/v0.1.0.md docs/flavoragentportfoliopackage.md docs/SOURCE_OF_TRUTH.md STATUS.md
npm run check:docs
```
Expected: PASS.

---

### Task 5: Final Verification

**Files:**
- All changed files.

- [ ] **Step 1: Run admin JS verification**

```bash
npm run build
npm run lint:js
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js
```

- [ ] **Step 2: Run browser verification**

```bash
npm run test:e2e:playground -- tests/e2e/flavor-agent.activity.spec.js
npm run test:e2e:wp70 -- tests/e2e/flavor-agent.approvals.spec.js
```

- [ ] **Step 3: Run docs and whitespace checks**

```bash
! rg -n "no rich visual diff viewer yet|there is still no rich visual diff viewer|richer visual diff inspection.*open|rich visual diff viewer.*open" docs/features/activity-and-audit.md docs/reference/current-open-work.md docs/reference/abilities-and-routes.md docs/releases/v0.1.0.md docs/flavoragentportfoliopackage.md docs/SOURCE_OF_TRUTH.md STATUS.md
npm run check:docs
git diff --check
```

- [ ] **Step 4: Add PHP verification only if tests prove the payload contract must change**

If this slice unexpectedly touches `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, or `inc/REST/Agent_Controller.php`, append the matching targeted PHPUnit commands before closing the plan. Otherwise keep it JS-only.
