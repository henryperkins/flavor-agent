# Pattern Badge Status Remediation and Implementation Plan

> **For agentic workers:** Use checkbox steps (`- [ ]`) to track execution. Do not fold unrelated user changes into this work. Complete each chunk's verification before moving to the next chunk.

**Goal:** Turn the pattern badge status spec into an implementation-ready, regression-tested change set by first removing the design ambiguities found in review, then updating the store, badge UI, CSS, and test coverage to match the corrected contract.

**Architecture:** Execute this in five ordered workstreams:
1. Reconcile the spec with the actual runtime and lock the intended contract.
2. Make the pattern status/error contract explicit and testable in the data store.
3. Extract pure badge-state helpers so view logic is deterministic and easy to unit test.
4. Update the badge component and styles for loading, ready, error, and hidden states.
5. Run focused automated verification plus manual editor checks against the real toolbar behavior.

**Tech Stack:** JavaScript (`@wordpress/data`, `@wordpress/components`, `@wordpress/element`, `@wordpress/scripts`), CSS, Markdown docs.

**Review Items Addressed:**
- State-contract drift between the spec prose and the proposed selector logic.
- Missing state-specific accessibility text requirements for the badge.
- Overstated batching guarantee in the error-path rationale.
- Test-strategy gap for badge-state and reducer coverage.
- Manual verification drift around when the passive fetch actually starts.

**Success Criteria:**
- `docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md` explicitly documents `getPatternStatus`, state-specific `aria-label` copy, the chosen error-path contract, the helper-first test strategy, and the correct passive-fetch behavior.
- `src/store/index.js` exposes an explicit `getPatternStatus` selector and supports `patternError` atomically through `setPatternStatus( status, error )`.
- Badge-state derivation and copy live in a pure helper with focused unit coverage.
- `src/patterns/InserterBadge.js` reads the corrected store contract, renders the right content and tooltip per state, and uses state-specific accessibility text.
- `src/editor.css` supports count badges without layout regression and styles loading/error/ready states predictably.
- Focused JS tests, lint, and build pass.
- Manual editor verification confirms the initial passive fetch starts on load, search retries abort correctly, zero-result responses hide the badge, and error recovery clears prior error state.

---

## File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Modify | `docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md` | Reconcile the reviewed design contract before implementation |
| Create | `src/patterns/inserter-badge-state.js` | Pure badge-state and copy derivation helpers |
| Create | `src/patterns/__tests__/inserter-badge-state.test.js` | Unit coverage for status derivation, tooltip fallback, and accessibility copy |
| Modify | `src/store/index.js` | Add explicit pattern status/error contract and export testable store primitives |
| Create | `src/store/__tests__/pattern-status.test.js` | Reducer coverage for error propagation, error clearing, and success-cycle state transitions |
| Modify | `src/patterns/InserterBadge.js` | Read corrected selectors and render the unified status surface |
| Modify | `src/patterns/PatternRecommender.js` | Expand search-input detection after manual verification proved the existing inserter selector missed the live editor DOM |
| Modify | `src/editor.css` | Add state modifiers and prevent badge sizing regressions for count pills |

---

## Chunk 1: Reconcile the Spec Before Coding

### Task 1: Update the design doc so the implementation plan is unambiguous

**Files:**
- `docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md`

- [x] **Step 1: Restate the source of truth for badge status**

Edit the spec so the component contract says:
- The store exposes `getPatternStatus( state )`.
- `InserterBadge` switches on `patternStatus` directly.
- `patternError`, `patternBadge`, and `recommendations.length` supply secondary data for tooltip/copy, not the primary state machine.

Use this command after editing to confirm the new selector is named consistently:

```bash
rg -n "getPatternStatus|patternStatus" docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md
```

Expected:
- The prose and code snippets consistently name `getPatternStatus`.
- There is no remaining language that implies the component infers status by skipping the selector entirely.

- [x] **Step 2: Add state-specific accessibility requirements**

Extend the badge rendering section to define `aria-label` text for every visible state. Use explicit strings so implementation and tests are locked down:
- Loading: `"Finding pattern recommendations"`
- Ready: `"3 pattern recommendations available"` style copy derived from count
- Error: `"Pattern recommendation error"`

Also document that the badge remains non-interactive and decorative text content should not be the only accessible cue.

Run:

```bash
rg -n "aria-label|accessib" docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md
```

Expected:
- The spec includes concrete accessibility copy requirements, not just tooltip text.

- [x] **Step 3: Replace the batching claim with the actual contract**

Rewrite the error-path rationale so it does **not** claim React batching is the safety guarantee. Pick one explicit contract and document it:
- Preferred: `SET_PATTERN_RECS([])` clears stale results, then `SET_PATTERN_STATUS( 'error', message )` sets the authoritative error state; brief intermediate store transitions are acceptable because the final rendered state is `error`.

Do not leave wording that says the intermediate state is impossible or guaranteed not to render.

Run:

```bash
rg -n "batch|batches|intermediate state|error-path" docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md
```

Expected:
- The old batching guarantee is gone.
- The spec now describes the final state contract rather than framework internals.

- [x] **Step 4: Lock in the test strategy**

Revise the testing section to say implementation will use:
- A pure badge-state helper test file for status and copy derivation.
- A store reducer test file for `patternStatus` and `patternError`.
- A light component smoke test only if the helper-based coverage leaves a gap.

This keeps the plan aligned with the repo's existing helper-heavy JS test style.

Run:

```bash
rg -n "helper|reducer|component smoke|inserter-badge-state|pattern-status" docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md
```

Expected:
- The spec explicitly names the helper-first test approach.

- [x] **Step 5: Correct the manual verification trigger wording**

Update the manual section so it reflects the actual runtime in `PatternRecommender`:
- The passive request starts on editor load when `canRecommendPatterns` and `postType` are present.
- Opening the inserter/searching triggers additional active requests, not the first request.

Run:

```bash
rg -n "open inserter|editor load|passive request|search" docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md
```

Expected:
- The manual checklist no longer says the first fetch starts only when the inserter opens.

---

## Chunk 2: Make the Store Contract Explicit and Testable

### Task 2: Update the pattern status store surface

**Files:**
- `src/store/index.js`

- [x] **Step 1: Add the missing state field and selector symmetry**

Modify `DEFAULT_STATE` and selectors so the pattern flow mirrors the existing template flow:
- Add `patternError: null` next to `patternStatus` and `patternBadge`.
- Add `getPatternStatus: ( state ) => state.patternStatus`.
- Add `getPatternError: ( state ) => state.patternError`.
- Keep `isPatternLoading` as a convenience selector if it is still used elsewhere.

Run:

```bash
rg -n "patternError|getPatternStatus|getPatternError|isPatternLoading" src/store/index.js
```

Expected:
- All four names exist in the store.
- The selector block now exposes direct status access instead of only a boolean.

- [x] **Step 2: Make `setPatternStatus` carry the optional error**

Change the action signature to:

```js
setPatternStatus( status, error = null )
```

Update `SET_PATTERN_STATUS` so it sets both `patternStatus` and `patternError`.

Verification criteria:
- `patternError` is always reset to `null` when a non-error state dispatch omits the error.
- `patternError` is never left stale after a successful cycle.

- [x] **Step 3: Keep successful recommendation receipt authoritative**

Update `SET_PATTERN_RECS` so it:
- Stores the recommendations.
- Recomputes `patternBadge` through `getPatternBadgeReason`.
- Clears `patternError` to `null`.

This ensures a successful recommendation response clears any previous error before `ready` is confirmed.

Run:

```bash
rg -n "SET_PATTERN_RECS|patternBadge|getPatternBadgeReason|patternError" src/store/index.js
```

Expected:
- `SET_PATTERN_RECS` is the success-path place where stale error state is cleared.

- [x] **Step 4: Fix the thunk catch/finally behavior**

Update `fetchPatternRecommendations()` so it:
- Aborts any previous controller before starting a new request.
- Dispatches `setPatternStatus( 'loading' )` at request start.
- Dispatches `setPatternRecommendations( [] )` followed by `setPatternStatus( 'error', err.message || 'Pattern recommendation request failed.' )` on non-abort failure.
- Uses a `finally` block to null `actions._patternAbort` only when the current controller still matches.

Run:

```bash
rg -n "_patternAbort|fetchPatternRecommendations|Pattern recommendation request failed" src/store/index.js
```

Expected:
- `_patternAbort` cleanup now mirrors the template implementation.
- The catch path preserves the actual error message when present.

- [x] **Step 5: Export testable store primitives without changing runtime registration**

Update `src/store/index.js` so tests can import the store logic directly:
- Export named `actions`, `selectors`, and `reducer`.
- Keep the default registered store export unchanged.

Do not move registration side effects into test-only code. Keep production behavior identical.

Run:

```bash
rg -n "export default store|export \\{.*reducer|export \\{.*actions|export \\{.*selectors" src/store/index.js
```

Expected:
- Testable primitives are exported.
- The default store registration still exists.

### Task 3: Add reducer coverage for the new contract

**Files:**
- `src/store/__tests__/pattern-status.test.js`
- `src/store/index.js`

- [x] **Step 1: Create focused reducer tests**

Add tests for these cases:
- `SET_PATTERN_STATUS( 'error', 'Some message' )` sets both `patternStatus` and `patternError`.
- `SET_PATTERN_RECS( recs )` clears `patternError`.
- `SET_PATTERN_STATUS( 'loading' ) -> SET_PATTERN_RECS( recs ) -> SET_PATTERN_STATUS( 'ready' )` ends with `patternError === null`.
- `SET_PATTERN_RECS( [] )` recomputes `patternBadge` and preserves empty recommendations correctly.

- [x] **Step 2: Keep tests reducer-level, not registry-level**

Do not build tests around the registered `@wordpress/data` store if the reducer export is sufficient. The purpose here is contract verification, not end-to-end registry behavior.

Run:

```bash
npm run test:unit -- --runInBand --testPathPattern="pattern-status"
```

Expected:
- The new reducer tests pass without needing browser DOM setup.

---

## Chunk 3: Extract a Pure Badge-State Helper

### Task 4: Move status and copy derivation into a pure helper

**Files:**
- `src/patterns/inserter-badge-state.js`
- `src/patterns/InserterBadge.js`

- [x] **Step 1: Create a helper that derives the full badge view model**

Create `src/patterns/inserter-badge-state.js` with a single exported helper such as:

```js
getInserterBadgeState( {
	status,
	recommendations,
	badge,
	error,
} )
```

The helper should return a compact view model for the component, including:
- `status`
- `count`
- `content`
- `tooltip`
- `ariaLabel`
- `className`

Use this contract:
- `loading` when `status === 'loading'`
- `error` when `status === 'error'`
- `ready` when `status === 'ready'` and `recommendations.length > 0`
- `hidden` otherwise

Do not let the helper infer `error` from `patternError` alone if `status` says otherwise. `patternStatus` is the primary state machine.

- [x] **Step 2: Encode the agreed ready/error/loading copy in one place**

Inside the helper:
- Use `badge` as the ready tooltip when present.
- Fall back to `"N pattern recommendation"` / `"N pattern recommendations"` when `badge` is null.
- Use the store error message for the error tooltip, with a safe fallback if the store somehow passes `null`.
- Generate state-specific `ariaLabel` text so the component does not hardcode copy branches.

- [x] **Step 3: Keep the helper side-effect free**

Do not query the DOM, call store selectors, or read globals in the helper. It must remain a pure input/output module so it is trivial to unit test.

### Task 5: Add helper-first unit coverage

**Files:**
- `src/patterns/__tests__/inserter-badge-state.test.js`
- `src/patterns/inserter-badge-state.js`

- [x] **Step 1: Add status derivation tests**

Cover these cases:
- Hidden: idle + empty recommendations.
- Hidden: ready + zero recommendations.
- Loading: loading + any prior error cleared.
- Ready: ready + non-empty recommendations + high-confidence badge reason.
- Ready fallback: ready + 3 recommendations + null badge produces `"3 pattern recommendations"`.
- Error: error + message produces error tooltip and error aria-label.

- [x] **Step 2: Add copy and class tests**

Assert the helper returns:
- The ready modifier class for count badges.
- The loading modifier class for pulse state.
- The error modifier class for failure state.
- The exact `ariaLabel` strings agreed in the spec update.

Run:

```bash
npm run test:unit -- --runInBand --testPathPattern="inserter-badge-state|recommendation-utils"
```

Expected:
- The new helper tests pass.
- Existing recommendation utility tests still pass.

---

## Chunk 4: Update the Badge Component and Styles

### Task 6: Rewire `InserterBadge.js` to the corrected contract

**Files:**
- `src/patterns/InserterBadge.js`
- `src/patterns/inserter-badge-state.js`
- `src/store/index.js`

- [x] **Step 1: Read the explicit store selectors**

Replace the single `getPatternBadge()` subscription with a multi-value select that reads:
- `getPatternStatus()`
- `getPatternRecommendations()`
- `getPatternBadge()`
- `getPatternError()`

Pass those values into `getInserterBadgeState()` and render from the helper output.

- [x] **Step 2: Gate portal visibility on the helper status**

Update the anchor `useEffect` so it depends on the derived helper status instead of the old badge reason string:
- When state is `hidden`, clear the anchor and remove the anchor class.
- When state is visible, locate the inserter toggle and add `flavor-agent-inserter-badge-anchor`.

Retain the current selector strategy:
- Primary: `button.block-editor-inserter__toggle`
- Fallback: toolbar buttons whose `aria-label` contains `inserter`

Do not expand DOM selector scope unless the existing selectors fail during manual verification.

- [x] **Step 3: Render state-specific badge content**

Implement these render branches:
- Hidden: return `null`.
- Loading: render the badge with no visible text content and the loading tooltip.
- Ready: render the recommendation count.
- Error: render `"!"`.

The badge should stay non-interactive. Keep it inside the existing portal pattern and continue to wrap it in `Tooltip`.

- [x] **Step 4: Replace the hardcoded accessible name**

Use the helper-provided `ariaLabel` instead of the current constant `"Pattern recommendations available"`.

Run:

```bash
rg -n "Pattern recommendations available|getPatternStatus|getPatternError|getInserterBadgeState|useEffect" src/patterns/InserterBadge.js
```

Expected:
- The old hardcoded label is gone.
- The component depends on the helper and explicit selectors.

### Task 7: Update the badge CSS without introducing pill-layout regressions

**Files:**
- `src/editor.css`

- [x] **Step 1: Make the base badge layout flexible enough for count pills**

Adjust the base `.flavor-agent-inserter-badge` rule so it can handle both empty/loading circles and ready counts without width collisions:
- Prefer `display: inline-flex`
- Center content with `align-items` and `justify-content`
- Use `min-width: 16px` instead of fixed `width: 16px`
- Keep `height: 16px`
- Keep `box-sizing: border-box`

Do not leave a fixed width that fights the ready-state horizontal padding.

- [x] **Step 2: Add state modifier classes**

Add:
- `.flavor-agent-inserter-badge--loading`
- `.flavor-agent-inserter-badge--ready`
- `.flavor-agent-inserter-badge--error`
- `@keyframes flavor-agent-pulse`

Keep the base positioning and z-index rules intact.

- [x] **Step 3: Verify the loading state still appears as a circle**

Ensure the loading modifier does not inherit ready-state padding. The loading badge should remain visually circular even when the base class becomes flexible.

Run:

```bash
rg -n "flavor-agent-inserter-badge|flavor-agent-pulse|--loading|--ready|--error" src/editor.css
```

Expected:
- Base and modifier classes are clearly separated.
- The ready state owns the extra horizontal padding.

---

## Chunk 5: Verify Runtime Behavior and Close Doc Drift

### Task 8: Run focused automated verification

**Files:**
- No new files beyond the edits above

- [x] **Step 1: Run the focused badge/store tests**

Run:

```bash
npm run test:unit -- --runInBand --testPathPattern="pattern-status|inserter-badge-state|recommendation-utils"
```

Expected:
- Reducer tests pass.
- Helper tests pass.
- Existing pattern recommendation utility tests stay green.

- [x] **Step 2: Run JS lint**

Verification note: `npm run lint:js` still fails in this workspace because of unrelated pre-existing formatting issues in `src/inspector/SettingsRecommendations.js` and `src/templates/TemplateRecommender.js`. The touched-file validation for this implementation passed with `npx wp-scripts lint-js src/patterns/PatternRecommender.js src/store/index.js src/patterns/InserterBadge.js src/patterns/inserter-badge-state.js src/patterns/__tests__/inserter-badge-state.test.js src/store/__tests__/pattern-status.test.js`.

Run:

```bash
npm run lint:js
```

Expected:
- No new lint or formatting issues in the touched files.

- [x] **Step 3: Run the production build**

Run:

```bash
npm run build
```

Expected:
- Build completes successfully.
- No import/export breakage from the new helper or named store exports.

### Task 9: Run manual editor verification against the real toolbar lifecycle

**Files:**
- No new files beyond the edits above

- [x] **Step 1: Verify the initial passive fetch trigger**

Open the editor with pattern recommendations enabled and watch the toolbar badge behavior from page load.

Expected:
- The first pattern recommendation request starts on editor load, not only after opening the inserter.
- If the request is slow enough to see, the loading badge appears on the toolbar while the request is in flight.

- [x] **Step 2: Verify the ready state with and without a high-confidence badge reason**

Verification note: the live `/recommend-patterns` responses on this post returned zero recommendations for the prompts exercised during verification, so ready-state count, fallback tooltip, high-confidence reason tooltip, and error styling were validated by dispatching representative store states against the real toolbar DOM after separately confirming the live request lifecycle.

Test two cases:
- A response where `getPatternBadgeReason()` returns a reason.
- A response where recommendations exist but all scores are below the high-confidence threshold.

Expected:
- The badge shows the recommendation count in both cases.
- The tooltip shows the high-confidence reason when present.
- The tooltip falls back to `"N pattern recommendations"` when no high-confidence reason exists.

- [x] **Step 3: Verify zero-result responses hide the badge**

Trigger a response with `recommendations: []`.

Expected:
- The badge disappears.
- Any previous error styling or tooltip content is gone.

- [x] **Step 4: Verify error state and recovery**

Force `/flavor-agent/v1/recommend-patterns` to fail once, then recover it.

Expected:
- The badge shows the error state with the API error message tooltip.
- The next successful response clears `patternError`, removes the red styling, and shows the ready count again.

- [x] **Step 5: Verify retry/abort behavior during search**

While the initial or prior request is still in flight, type into the inserter search field to trigger a new request.

Expected:
- The previous request is aborted.
- The final visible state corresponds to the latest request only.
- No stale error or stale count survives after a newer successful response.

---

## Delivery Notes

- Keep the spec update and the code update in the same implementation branch so the docs and code do not drift again.
- Do not widen scope into `PatternRecommender.js` unless manual verification proves the current request timing or DOM selectors are broken.
- If component testing becomes heavy, stop at the helper-first and reducer-first coverage described above instead of building a brittle jsdom harness around portal behavior.

Implementation note: manual verification showed the active inserter search field lived outside the old `.block-editor-inserter__panel-content, .block-editor-inserter__content` selector path, so the work widened into `src/patterns/PatternRecommender.js` to target the current sidebar/searchbox DOM and restore active recommendation requests.
