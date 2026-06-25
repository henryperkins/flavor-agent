# Pattern Adapted Preview V2 Comparison Implementation Plan

> Status: Archived 2026-06-24. Implemented same-day as the adapted-preview comparison follow-up (`Original pattern` / `Adapted result` compare sections plus deterministic change-summary rows); retained only as historical execution context. Verification evidence lives in `STATUS.md`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deepen the existing adapted preview trust loop by letting the user compare the original and adapted pattern render plus the exact cosmetic changes before insertion, without widening into content rewriting, synced detachment, or a second mutation engine.

**Architecture:** Keep the current client-side deterministic adaptation engine, `resolveSignatureOnly` server freshness check, and shared original/adapted insertion path. Reuse the existing `plan.changes` metadata from `src/patterns/pattern-adaptation.js` plus the original resolved pattern blocks already available in `PatternRecommender()` to power a bounded compare UI inside `PatternAdaptationPreview`. The compare UI should show the original render, the adapted render, and a normalized per-change summary, while leaving insertion semantics unchanged: `Insert adapted` still re-clones the adapted tree immediately before dispatch, `Insert original` still dispatches the untouched blocks, and synced/user `core/block` references remain unchanged references with no adapted preview. This slice stays JS-only.

**Tech Stack:** React via `@wordpress/element`, Gutenberg `BlockPreview`, editor CSS in `src/editor.css`, Jest via `@wordpress/scripts`, Playwright smoke coverage.

---

## File Map

- Modify `src/patterns/PatternRecommender.js`: keep the original resolved blocks alongside the adapted preview state and pass them into the preview component.
- Modify `src/patterns/PatternAdaptationPreview.js`: render the bounded original/adapted comparison UI plus the richer per-change summary.
- Modify `src/patterns/pattern-adaptation.js` only if the preview needs slightly richer deterministic change metadata; do not widen the mutation rule set in this slice.
- Modify `src/editor.css`: add compare-view layout and change-summary styling for the existing inserter-mounted preview panel.
- Modify `src/patterns/__tests__/PatternAdaptationPreview.test.js`: cover compare rendering, change summaries, and stale/blocked degradation.
- Modify `src/patterns/__tests__/PatternRecommender.test.js`: cover original-block threading, compare entry-point behavior, and unchanged insertion semantics.
- Modify `tests/e2e/flavor-agent.smoke.spec.js`: keep real-browser adapted-preview coverage honest after the compare UI lands.
- Update docs after code passes: `docs/features/pattern-recommendations.md`, `docs/features/pattern-recommendations-adapted-preview.md`, `docs/reference/current-open-work.md`, `STATUS.md`, and `docs/SOURCE_OF_TRUTH.md`.

## Non-Goals

- No content text adaptation, model-authored adaptation plans, or prompt-contract changes.
- No synced-pattern detachment, Pattern Overrides authoring, or per-instance content override work.
- No new ranking request fields, pattern-index behavior, or backend schema changes.
- No block-operation expansion, section/page planning, or parameterized pattern executor work.
- No default-action change; `Preview adapted` remains the trust gate and `Insert original` stays available.

## Implementation Rules

- Use the existing deterministic engine and `plan.changes` output as the source of truth for comparison copy. Do not infer extra changes from rendered DOM.
- The compare UI must make it obvious that both previews come from the exact block trees used for insertion; no screenshots, screenshots-as-fallbacks, or generated prose-only explanations.
- Keep stale and blocked behavior fail-closed: stale previews should still block adapted insertion and blocked previews should still allow the unchanged original path.
- Treat inserter width as a real constraint. Prefer a bounded compare layout (for example, original/adapted tabs or stacked previews) over a desktop-only side-by-side assumption.
- Synced/user `core/block` references remain outside this slice. The compare UI must not appear for unchanged synced references.
- Use TDD for each production change: write the failing test first, run it, implement the minimal fix, rerun.
- Stop and write a separate plan if truthful comparison requires server participation, pattern detachment semantics, or new activity event types.

---

### Task 1: Thread Original Blocks And Comparison Metadata Into The Preview State

**Files:**
- Modify: `src/patterns/PatternRecommender.js`
- Modify: `src/patterns/PatternAdaptationPreview.js`
- Test: `src/patterns/__tests__/PatternRecommender.test.js`

- [ ] **Step 1: Add failing recommender tests**

Add tests that prove opening `Preview adapted` stores the original resolved block tree alongside the adapted clone, keeps the existing `changes` metadata, and does not change the current insertion behavior for `Insert adapted` or `Insert original`.

Run:
```bash
npm run test:unit -- --runInBand src/patterns/__tests__/PatternRecommender.test.js
```
Expected: FAIL because the preview state does not yet retain original blocks for comparison.

- [ ] **Step 2: Implement minimal preview-state threading**

Update `PatternRecommender()` so the adapted preview state carries:

- the original resolved blocks,
- the adapted blocks,
- the existing deterministic `changes`,
- and the same freshness metadata the current preview already uses.

Do not change insertion semantics or outcome-event names.

- [ ] **Step 3: Verify recommender coverage**

Run:
```bash
npm run test:unit -- --runInBand src/patterns/__tests__/PatternRecommender.test.js
```
Expected: PASS.

---

### Task 2: Add The Original/Adapted Compare UI

**Files:**
- Modify: `src/patterns/PatternAdaptationPreview.js`
- Modify: `src/editor.css`
- Test: `src/patterns/__tests__/PatternAdaptationPreview.test.js`

- [ ] **Step 1: Add failing preview-component tests**

Add tests that prove the preview component:

- renders both an original and adapted comparison path from real block arrays,
- shows the normalized change summary with from/to detail where available,
- preserves the existing stale and blocked copy,
- and keeps the `Insert adapted`, `Insert original`, and `Close` actions intact.

Run:
```bash
npm run test:unit -- --runInBand src/patterns/__tests__/PatternAdaptationPreview.test.js
```
Expected: FAIL because the component still renders only the adapted preview and a short reason list.

- [ ] **Step 2: Implement the bounded compare UI**

Replace the current single-preview presentation with a bounded compare view that fits the inserter:

- show both the original and adapted render through Gutenberg `BlockPreview`,
- keep the existing adapted change reasons, but expand them into a change summary with block/attribute/from/to detail when the deterministic plan exposes it,
- and preserve the current action buttons and stale/blocked messaging.

If simultaneous dual previews are too cramped for the inserter width, use a compare toggle or stacked layout rather than widening into a modal workflow.

- [ ] **Step 3: Verify the preview component**

Run:
```bash
npm run test:unit -- --runInBand src/patterns/__tests__/PatternAdaptationPreview.test.js
```
Expected: PASS.

---

### Task 3: Keep Browser Proof Honest

**Files:**
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`

- [ ] **Step 1: Extend the adapted-preview smoke**

Update the existing pattern adapted-preview browser spec so it proves the compare UI renders before insertion and that the adapted insert path still uses the adapted tree rather than the original one.

Run:
```bash
npm run test:e2e:playground -- tests/e2e/flavor-agent.smoke.spec.js
```
Expected: FAIL until the compare UI is present.

- [ ] **Step 2: Keep the smoke focused**

Limit browser assertions to the compare surface already exercised by the existing adapted-preview smoke. Do not widen into a full pattern-ranking rerun.

---

### Task 4: Documentation And Queue Closeout

**Files:**
- Modify: `docs/features/pattern-recommendations.md`
- Modify: `docs/features/pattern-recommendations-adapted-preview.md`
- Modify: `docs/reference/current-open-work.md`
- Modify: `STATUS.md`
- Modify: `docs/SOURCE_OF_TRUTH.md`

- [ ] **Step 1: Update docs after code passes**

Document adapted-preview v2 as a trust-and-comparison follow-up to the shipped deterministic preview. Keep content adaptation, synced detachment, model-assisted plans, and block-operation expansion open.

- [ ] **Step 2: Verify docs**

Run:
```bash
npm run check:docs
```
Expected: PASS.

---

### Task 5: Final Verification

**Files:**
- All changed files.

- [ ] **Step 1: Run targeted pattern JS verification**

```bash
npm run test:unit -- --runInBand src/patterns/__tests__/PatternAdaptationPreview.test.js src/patterns/__tests__/PatternRecommender.test.js
```

- [ ] **Step 2: Run the real-browser smoke**

```bash
npm run test:e2e:playground -- tests/e2e/flavor-agent.smoke.spec.js
```

- [ ] **Step 3: Run docs and whitespace checks**

```bash
npm run check:docs
git diff --check
```

- [ ] **Step 4: Add broader verification only if the implementation crosses the current JS-only boundary**

If the slice touches PHP ability/output contracts, pattern activity normalization, or shared editor insertion validators beyond the preview surface, append the matching targeted PHPUnit or broader JS suites before closing the plan. Otherwise keep v2 comparison as a bounded JS-only follow-up.
