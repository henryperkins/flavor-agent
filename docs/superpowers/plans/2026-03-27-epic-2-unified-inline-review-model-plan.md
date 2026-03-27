# Epic 2 Unified Inline Review Model Completion Plan

> **For agentic workers:** This plan operationalizes only Epic 2 from `docs/2026-03-25-roadmap-aligned-execution-plan.md`. Keep the work inside the existing block, navigation, template, and template-part surfaces. Do not expand scope into new recommendation surfaces, navigation apply, floating chat UI, or approval queues. Favor a test-first flow for shared JS store and UI changes before touching browser smoke coverage.

**Goal:** Complete Epic 2 so Flavor Agent feels like one Gutenberg-native review system across its existing editor surfaces, and satisfy both the Epic 2 acceptance criteria and the repo-level completion gates from the roadmap.

**Roadmap anchors:**
1. Epic 2 scope and exit criteria: `docs/2026-03-25-roadmap-aligned-execution-plan.md` lines 231-326.
2. Repo-level completion gates: `docs/2026-03-25-roadmap-aligned-execution-plan.md` lines 608-622.
3. Current surface docs:
   - `docs/features/block-recommendations.md`
   - `docs/features/navigation-recommendations.md`
   - `docs/features/template-recommendations.md`
   - `docs/features/template-part-recommendations.md`

**Epic 2 acceptance criteria to satisfy:**
1. The same user can move between all Flavor Agent surfaces without relearning the interaction model.
2. Structural actions are always reviewable before mutation.
3. Activity and undo presentation are consistent across all surfaces.

**Architecture constraints that must remain true:**
1. Block-level attribute updates can still apply inline when the change is local and safe.
2. Template and template-part structural actions stay preview-first and deterministic.
3. Navigation remains advisory-only through v1.0.
4. Notes support, if added, is adapter-only and must not create a dependency on unstable APIs.
5. No floating assistant shell, cross-document approval queue, or broader agent workspace is part of this epic.

**Current gap summary:**
1. Shared activity and undo already exist, but review/status UI is still mostly surface-specific.
2. The store tracks multiple status families, but not the normalized Epic 2 action states (`advisory-ready`, `preview-ready`, `undoing`, etc.) as one learned-once model.
3. Navigation, block, template, and template-part surfaces still use different copy and affordance patterns for success, advisory guidance, preview, and undo.
4. Epic 2 acceptance coverage exists in pieces, but it has not yet been rerun and recorded as one milestone closeout, especially on the Docker-backed WP 7.0 harness.

**Primary implementation area:** editor-side JS and docs. No PHP contract expansion is expected for Epic 2, but the roadmap still requires full PHP, JS, browser, and docs verification before the epic can be marked complete.

**Tech stack:** JavaScript, React via `@wordpress/element`, `@wordpress/components`, `@wordpress/data`, Jest-style unit tests through `@wordpress/scripts`, Playwright, Markdown docs.

---

### Task 1: Lock The Shared Interaction Contract

**Why:** Epic 2 is a UX/model-unification milestone. Before refactoring panels, the repo needs one explicit interaction contract that all four surfaces share.

**Files:**
- Modify: `src/store/index.js`
- Modify: `src/editor.css`
- Modify: `docs/features/block-recommendations.md`
- Modify: `docs/features/navigation-recommendations.md`
- Modify: `docs/features/template-recommendations.md`
- Modify: `docs/features/template-part-recommendations.md`

- [ ] **Step 1: Define one normalized surface state vocabulary**

Establish the Epic 2 action states called for by the roadmap:
- `idle`
- `loading`
- `advisory-ready`
- `preview-ready`
- `applying`
- `success`
- `undoing`
- `error`

Document which surfaces are allowed to enter which states:
- block: may enter `success` from inline apply without preview,
- navigation: advisory-only, no apply path,
- template and template-part: must reach `preview-ready` before mutation.

- [ ] **Step 2: Define one panel anatomy**

Every surface should present the same learned-once sequence:
1. prompt,
2. suggestions,
3. explanation,
4. review where needed,
5. apply where allowed,
6. undo and history.

The visible wording can still be surface-specific in nouns, but the layout and semantic stages should be shared.

- [ ] **Step 3: Record the contract in the surface docs before final closeout**

Update the four surface docs so they describe one shared interaction model instead of four adjacent but independently-described flows.

---

### Task 2: Extract Shared Review And Status Components

**Why:** Epic 2 explicitly calls for shared review/status components instead of surface-by-surface rendering logic.

**Files:**
- Add: `src/components/AIReviewSection.js`
- Add: `src/components/AIStatusNotice.js`
- Add: `src/components/AIAdvisorySection.js`
- Modify: `src/components/AIActivitySection.js`
- Modify: `src/editor.css`
- Add: `src/components/__tests__/AIReviewSection.test.js`
- Add: `src/components/__tests__/AIStatusNotice.test.js`
- Add: `src/components/__tests__/AIAdvisorySection.test.js`
- Add/Modify: `src/components/__tests__/AIActivitySection.test.js`

- [ ] **Step 1: Add a shared status notice component**

Create `AIStatusNotice` to render normalized success, error, undo, and advisory states with consistent structure and button placement.

It should cover:
- fetch errors,
- apply errors,
- undo errors,
- post-apply success with inline `Undo`,
- post-undo success,
- advisory-only informational status where needed.

- [ ] **Step 2: Add a shared advisory section component**

Create `AIAdvisorySection` for surfaces that intentionally do not mutate content, or for suggestion groups that remain non-executable after validation.

Navigation should be the first consumer.
Template-part advisory-only suggestion cards should be able to reuse the same shell.

- [ ] **Step 3: Add a shared review section component**

Create `AIReviewSection` for preview-before-apply flows so template and template-part surfaces share:
- preview state framing,
- executable/advisory labeling,
- confirm/cancel affordances,
- operation summary layout.

- [ ] **Step 4: Keep `AIActivitySection` as the shared history block**

Do not replace the activity component; instead, make the new shared components compose around it so activity/undo remains visually and structurally consistent everywhere it exists.

- [ ] **Step 5: Add or extend component-level tests**

Add focused unit coverage for the new shared components rather than relying only on larger panel tests to catch state-model regressions.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/components/__tests__/AIStatusNotice.test.js src/components/__tests__/AIAdvisorySection.test.js src/components/__tests__/AIReviewSection.test.js src/components/__tests__/AIActivitySection.test.js
```

Expected: PASS after the shared component layer is stable.

---

### Task 3: Normalize Store State And Selectors

**Why:** The current store is capable, but its state model is still surface-specific. Epic 2 needs a shared semantic state layer that drives the new components.

**Files:**
- Modify: `src/store/index.js`
- Modify: `src/store/activity-history.js`
- Modify: `src/store/__tests__/store-actions.test.js`
- Modify: `src/store/__tests__/activity-history.test.js`
- Modify: `src/store/__tests__/activity-history-state.test.js`
- Add/Modify: `src/store/__tests__/navigation-request-state.test.js`
- Add/Modify: `src/store/__tests__/template-apply-state.test.js`
- Add/Modify: `src/store/__tests__/block-request-state.test.js`

- [ ] **Step 1: Add normalized selectors for shared UI consumption**

Expose selectors that answer shared questions, for example:
- what is the current interaction state,
- is the surface advisory-only,
- is a preview required,
- is an apply action currently allowed,
- what status notice should render,
- what undo affordance is valid.

These selectors should hide per-surface implementation detail from the components.

- [ ] **Step 2: Map each surface into the normalized state model**

Ensure:
- block recommendation fetch/apply/undo maps cleanly into the shared state vocabulary,
- navigation maps into `loading`, `advisory-ready`, and `error` without pretending an apply path exists,
- template and template-part recommendation flows map into `preview-ready`, `applying`, `success`, `undoing`, and `error`.

- [ ] **Step 3: Preserve existing activity and undo invariants**

Do not weaken current ordered-undo behavior. The normalized state model must layer on top of the existing activity and undo rules rather than bypassing them.

- [ ] **Step 4: Add state-focused regression coverage**

Use targeted store tests to verify normalized state transitions directly, especially:
- advisory-only readiness,
- preview readiness,
- inline apply success,
- undoing,
- error reset behavior across surfaces.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/store/__tests__/block-request-state.test.js src/store/__tests__/navigation-request-state.test.js src/store/__tests__/template-apply-state.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js
```

Expected: PASS.

---

### Task 4: Migrate Block And Navigation Surfaces Onto The Shared Model

**Why:** The block inspector already contains both an executable flow and the nested advisory-only navigation flow. It is the fastest place to prove the learned-once model works.

**Files:**
- Modify: `src/inspector/BlockRecommendationsPanel.js`
- Modify: `src/inspector/NavigationRecommendations.js`
- Modify: `src/inspector/InspectorInjector.js`
- Modify: `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
- Modify: `src/inspector/__tests__/NavigationRecommendations.test.js`
- Modify: `docs/features/block-recommendations.md`
- Modify: `docs/features/navigation-recommendations.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`

- [ ] **Step 1: Refactor block notices and undo presentation to the shared components**

Replace surface-local success/error/undo notice markup with shared status rendering while preserving:
- safe inline apply for block attribute updates,
- content-only restrictions,
- latest-tail inline undo behavior.

- [ ] **Step 2: Make the inline-apply rationale explicit**

Epic 2 requires the block surface to explain why block suggestions can apply inline while structural surfaces require review. The explanation does not need to be long, but it must be explicit and consistent.

- [ ] **Step 3: Move navigation onto the shared advisory shell**

Refactor navigation recommendations so:
- advisory-only state is presented through the shared advisory component,
- copy and status treatment match the shared model,
- navigation remains intentionally non-executable.

- [ ] **Step 4: Extend panel-level tests**

Cover:
- shared status rendering on block success/error/undo,
- block inline apply + undo still functioning,
- navigation advisory-ready state,
- navigation still exposing no apply contract.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/inspector/__tests__/BlockRecommendationsPanel.test.js src/inspector/__tests__/NavigationRecommendations.test.js
```

Expected: PASS.

---

### Task 5: Migrate Template And Template-Part Surfaces Onto The Shared Review Model

**Why:** These surfaces already implement preview-confirm-apply, but they still own too much of their review/status UI independently.

**Files:**
- Modify: `src/templates/TemplateRecommender.js`
- Modify: `src/templates/template-recommender-helpers.js`
- Modify: `src/template-parts/TemplatePartRecommender.js`
- Modify: `src/utils/template-actions.js`
- Modify: `src/templates/__tests__/TemplateRecommender.test.js`
- Modify: `src/template-parts/__tests__/TemplatePartRecommender.test.js`
- Modify: `docs/features/template-recommendations.md`
- Modify: `docs/features/template-part-recommendations.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`

- [ ] **Step 1: Render preview state through `AIReviewSection`**

Template and template-part preview flows should share:
- the preview header,
- operation summary region,
- confirm/cancel controls,
- executable versus advisory treatment.

- [ ] **Step 2: Keep structural mutation preview-first**

Do not introduce any fast-apply path for template or template-part operations. Structural actions must remain reviewable before mutation to satisfy Epic 2 exit criterion 2.

- [ ] **Step 3: Preserve advisory-only template-part suggestions**

Template-part suggestions that fail deterministic validation must stay visible but non-executable, rendered through the shared advisory model instead of surface-specific fallback wording.

- [ ] **Step 4: Keep activity and undo presentation aligned with block**

After apply, template and template-part surfaces should use the same shared status and activity treatment as block wherever the semantics overlap.

- [ ] **Step 5: Extend panel-level tests**

Cover:
- preview state rendering,
- advisory-only suggestion rendering,
- successful apply state,
- undo state,
- no regression in current entity-link and focus-block helper behavior.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js
```

Expected: PASS.

---

### Task 6: Add The Thin Notes Adapter Layer

**Why:** Epic 2 includes future-proofing for Notes-style suggestions, but explicitly forbids taking a hard dependency on unstable APIs.

**Files:**
- Add: `src/review/notes-adapter.js`
- Add: `src/review/__tests__/notes-adapter.test.js`
- Modify: `docs/SOURCE_OF_TRUTH.md`

- [ ] **Step 1: Add a shape-only adapter**

Create a small adapter that can translate shared review evidence into a Notes-compatible representation later.

It may define normalization helpers, payload shapes, or serialization helpers, but it must not:
- enqueue Notes UI,
- depend on unstable editor APIs at runtime,
- alter the current review flow.

- [ ] **Step 2: Add direct adapter coverage**

Add direct unit coverage for the adapter shape so future Notes projection work can reuse it without changing the current UI contract.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/review/__tests__/notes-adapter.test.js
```

Expected: PASS.

- [ ] **Step 3: Keep the adapter optional and unused by default**

If the current UI does not need to call it yet, that is acceptable. The point is to prevent future review-surface rewiring, not to ship Notes integration in Epic 2.

---

### Task 7: Satisfy Epic 2 Acceptance Tests

**Why:** The roadmap names exact Epic 2 acceptance commands. Those should be run and recorded directly, not inferred from older partial runs.

**Files:**
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`
- Modify: `STATUS.md`

- [ ] **Step 1: Run the Epic 2 unit acceptance command**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/components/__tests__/AIActivitySection.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js src/inspector/__tests__/NavigationRecommendations.test.js src/templates/__tests__/TemplateRecommender.test.js
```

Expected: PASS.

- [ ] **Step 2: Keep browser smoke explicit for the four Epic 2 scenarios**

`tests/e2e/flavor-agent.smoke.spec.js` already exercises these scenarios across the playground and WP 7.0 harnesses; keep them explicit and strengthen naming/assertions if needed so the mapping to Epic 2 stays obvious:
- block inline apply + undo,
- navigation remains advisory-only,
- template preview-confirm-apply,
- template-part preview-confirm-apply.

- [ ] **Step 3: Run browser acceptance on both harnesses**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:playground -- --reporter=line
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70 -- --reporter=line
```

Expected: PASS. `npm run test:e2e -- --reporter=line` is also acceptable as the combined gate because the current package script already chains both harnesses. If the WP 7.0 command fails only because Docker is unavailable, the epic is not yet fully closed on that host; rerun on a Docker-capable environment and record that pass in `STATUS.md`.

---

### Task 8: Satisfy The Repo-Level Completion Gates And Close The Docs Loop

**Why:** The roadmap says no epic is complete until the repo-wide PHP, JS, browser, and docs gates are green.

**Files:**
- Modify: `STATUS.md`
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/2026-03-25-roadmap-aligned-execution-plan.md`
- Modify: `docs/features/activity-and-audit.md`
- Modify: any updated `docs/features/*.md` and `docs/FEATURE_SURFACE_MATRIX.md`

- [ ] **Step 1: Run the repo-level PHP gates**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
composer lint:php
vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 2: Run the repo-level JS gates**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run lint:js
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand
```

Expected: PASS.

- [ ] **Step 3: Update the documentation backbone**

Record the shared interaction model in:
- `STATUS.md` as current verified state plus exact command results,
- `docs/SOURCE_OF_TRUTH.md` as the current product contract,
- `docs/features/activity-and-audit.md` as the shared activity/undo contract,
- `docs/FEATURE_SURFACE_MATRIX.md` and the four surface docs as shipped behavior.

- [ ] **Step 4: Mark Epic 2 complete in the roadmap**

Once the gates are green and the docs are updated, annotate the Epic 2 work in `docs/2026-03-25-roadmap-aligned-execution-plan.md` the same way Epic 1 was marked complete.

Do not mark Epic 2 complete before:
- both browser commands are green,
- docs are updated,
- the shared interaction model is actually visible in the shipped surfaces.

---

### Final Verification Checklist

- [ ] A user can move between block, navigation, template, and template-part surfaces without relearning prompt, explanation, review, apply/advisory, and undo/history behavior.
- [ ] Navigation is still advisory-only and visibly framed that way through the shared model.
- [ ] Block suggestions still support safe inline apply, and the UI now makes that exception explicit.
- [ ] Template and template-part suggestions still require preview before structural mutation.
- [ ] Activity and undo presentation now looks and behaves consistently across all executable surfaces.
- [ ] Epic 2 acceptance tests are recorded in `STATUS.md`.
- [ ] Repo-level completion gates are recorded in `STATUS.md`.
- [ ] Epic 2 is explicitly marked complete in the roadmap only after the above are true.

---

### Suggested Commit

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
git add docs/superpowers/plans/2026-03-27-epic-2-unified-inline-review-model-plan.md
git commit -m "docs: add epic 2 completion plan"
```
