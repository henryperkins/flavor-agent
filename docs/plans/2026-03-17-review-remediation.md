# Review Remediation Plan

> **For agentic workers:** Use checkbox steps (`- [ ]`) to track execution. Do not mix unrelated user changes into these tasks. Verify each chunk before moving to the next one.

**Goal:** Resolve the current review findings by turning the active WIP into a self-contained change set, restoring green verification, adding missing regression coverage around the template and inspector flows, and reconciling the active docs with the code that is actually shipped.

**Architecture:** Execute this in five ordered workstreams:
1. Freeze and stabilize the current WIP change set.
2. Restore JS lint and formatting health.
3. Backfill JS and PHP regression coverage for the new template and inspector behavior.
4. Reconcile source-of-truth docs and archive stale active plans.
5. Normalize verification entry points and run the full QA matrix.

**Tech Stack:** JavaScript (`@wordpress/data`, `@wordpress/components`, `@wordpress/scripts`, Jest), PHP 8.0+ (WordPress APIs, PHPUnit, PHPCS), Markdown docs.

**Review Items Addressed:**
- Incomplete inspector refactor changeset: tracked files depend on new untracked helper files.
- JS lint is red even though build and tests pass.
- Template and template-action logic is implemented but lightly tested.
- Active docs disagree with the current implementation and verification state.
- `npm test` is missing even though JS unit tests exist.

**Success Criteria:**
- `git status --short` shows a coherent, intentional change set.
- `npm run lint:js` passes with no errors.
- `npm run test:unit -- --runInBand` passes.
- `vendor/bin/phpunit` passes with new template coverage included.
- `vendor/bin/phpcs` passes.
- `npm run build` passes.
- `docs/flavor-agent-readme.md` and `STATUS.md` match the current code.
- Only `flavor-agent/recommend-navigation` remains stubbed in active docs.

---

## File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `src/inspector/suggestion-keys.js` | Shared key/panel normalization for inspector suggestion UI |
| Create | `src/inspector/suggestion-keys.test.js` | Unit tests for stable suggestion keys |
| Modify | `src/inspector/SettingsRecommendations.js` | Use shared key helpers and only show "Applied" on successful updates |
| Modify | `src/inspector/StylesRecommendations.js` | Same as above, plus keep style-row action icon states correct |
| Modify | `src/inspector/SuggestionChips.js` | Same as above for sub-panel chips |
| Modify | `src/store/index.js` | Preserve `applySuggestion()` success/failure contract and template result token behavior |
| Modify | `src/templates/TemplateRecommender.js` | Keep template card state reset/apply-all feedback coherent and lint-clean |
| Modify | `src/utils/template-actions.js` | Keep granular success/failure behavior testable and lint-clean |
| Create | `src/templates/template-suggestion-state.js` (if needed) | Extract pure template UI state helpers for testability |
| Create | `src/templates/__tests__/template-suggestion-state.test.js` (if helper extraction is used) | Unit coverage for template card state helpers |
| Create | `src/utils/__tests__/template-actions.test.js` | Unit coverage for template action helpers |
| Create | `tests/phpunit/TemplatePromptTest.php` | Prompt-response validation tests for template recommendations |
| Create | `tests/phpunit/TemplateAbilitiesTest.php` | End-to-end template ability tests with stubbed WP functions |
| Modify | `tests/phpunit/bootstrap.php` | Add template-related WordPress stubs and test state fixtures |
| Modify | `package.json` | Add a canonical `npm test` entry point |
| Modify | `docs/flavor-agent-readme.md` | Update current architecture and ability status |
| Modify | `STATUS.md` | Correct verification status and known issues |
| Modify | `CLAUDE.md` | Keep verification commands aligned with the package scripts |
| Move | `docs/plans/2026-03-17-recommend-template.md` -> `docs/plans/completed/` | Archive shipped implementation plan |

---

## Chunk 1: Stabilize the Active WIP

### Task 1: Snapshot the current worktree before editing

**Files:**
- No file edits yet

- [ ] **Step 1: Capture the current change set**

Run:

```bash
git status --short
git diff --stat
```

Expected:
- Tracked modifications in:
  - `src/inspector/SettingsRecommendations.js`
  - `src/inspector/StylesRecommendations.js`
  - `src/inspector/SuggestionChips.js`
  - `src/store/index.js`
  - `src/templates/TemplateRecommender.js`
  - `src/utils/template-actions.js`
- Untracked files:
  - `src/inspector/suggestion-keys.js`
  - `src/inspector/suggestion-keys.test.js`

- [ ] **Step 2: Confirm no unrelated user work is mixed into the remediation branch**

If unrelated files appear, stop and separate them before continuing. Do not silently absorb unrelated edits into the remediation work.

- [ ] **Step 3: Save the baseline for later comparison**

Run:

```bash
git diff -- src/inspector/SettingsRecommendations.js src/inspector/StylesRecommendations.js src/inspector/SuggestionChips.js src/store/index.js src/templates/TemplateRecommender.js src/utils/template-actions.js
```

Use this diff as the baseline for all later verification.

### Task 2: Make the inspector helper refactor self-contained

**Files:**
- `src/inspector/suggestion-keys.js`
- `src/inspector/suggestion-keys.test.js`
- `src/inspector/SettingsRecommendations.js`
- `src/inspector/StylesRecommendations.js`
- `src/inspector/SuggestionChips.js`
- `src/store/index.js`

- [ ] **Step 1: Ensure the new helper files are intentionally part of the change set**

Verify both files exist and are the only new inspector helper artifacts:

```bash
ls src/inspector/suggestion-keys.js src/inspector/suggestion-keys.test.js
```

- [ ] **Step 2: Verify all three inspector surfaces use the shared helper**

Run:

```bash
rg -n "getSuggestionKey|getSuggestionPanel" src/inspector
```

Expected:
- `SettingsRecommendations.js`
- `StylesRecommendations.js`
- `SuggestionChips.js`
- `suggestion-keys.js`
- `suggestion-keys.test.js`

- [ ] **Step 3: Verify no stale inline key-building logic remains**

Run:

```bash
rg -n "panel \\|\\| 'general'|panel }-|s\\.panel|suggestion\\.panel" src/inspector
```

Expected:
- Any remaining `panel` reads should be legitimate logic, not ad hoc UI-key construction.

- [ ] **Step 4: Keep the store contract explicit**

In `src/store/index.js`, retain and document this behavior:
- `applySuggestion()` returns `true` only when a safe update is actually dispatched.
- `applySuggestion()` returns `false` when the suggestion produces no allowed attribute updates.
- Activity logging happens only on success.

Do not let the UI show "Applied" when nothing changed.

- [ ] **Step 5: Stage the helper files as part of the same fix**

Run:

```bash
git add src/inspector/suggestion-keys.js src/inspector/suggestion-keys.test.js
```

- [ ] **Step 6: Run the focused JS tests for this refactor**

Run:

```bash
npm run test:unit -- --runInBand --testPathPattern="suggestion-keys|update-helpers"
```

Expected:
- Existing update-helper tests still pass.
- The new suggestion-key tests pass.

- [ ] **Step 7: Commit the self-contained inspector change set**

Suggested commit:

```bash
git add src/inspector/SettingsRecommendations.js src/inspector/StylesRecommendations.js src/inspector/SuggestionChips.js src/store/index.js
git commit -m "fix: stabilize inspector suggestion applied-state tracking"
```

If the template UI work is still in progress, keep it in a separate commit.

---

## Chunk 2: Restore Lint and Formatting Health

### Task 3: Fix the current JS lint failures

**Files:**
- `src/inspector/SettingsRecommendations.js`
- `src/inspector/StylesRecommendations.js`
- `src/inspector/suggestion-keys.test.js`
- `src/templates/TemplateRecommender.js`
- `src/utils/template-actions.js`

- [ ] **Step 1: Reproduce the current lint failure before changing anything**

Run:

```bash
npm run lint:js
```

Expected:
- Prettier failures in the inspector files.
- JSDoc / no-unused-vars failures in `TemplateRecommender.js`.
- Prettier failure in `template-actions.js`.

- [ ] **Step 2: Auto-fix the purely mechanical issues first**

Run:

```bash
npm run lint:js -- --fix
```

This should fix most Prettier-only problems in:
- `src/inspector/SettingsRecommendations.js`
- `src/inspector/StylesRecommendations.js`
- `src/inspector/suggestion-keys.test.js`
- `src/templates/TemplateRecommender.js`
- `src/utils/template-actions.js`

- [ ] **Step 3: Manually fix the remaining semantic lint failures**

In `src/templates/TemplateRecommender.js`:
- Add complete JSDoc for exported or reusable helper functions.
- Remove any no-longer-used variables.
- Keep naming consistent with actual prop names (`text`, `entities`, etc.).
- Do not leave dead helper variables introduced during refactors.

In `src/utils/template-actions.js`:
- Keep helper JSDoc aligned with actual parameter names and return types.

- [ ] **Step 4: Re-run lint until the tree is clean**

Run:

```bash
npm run lint:js
```

Expected:
- Zero errors.

- [ ] **Step 5: Re-run the production build after lint cleanup**

Run:

```bash
npm run build
```

Expected:
- Build succeeds.
- No functional diff beyond intended source changes.

- [ ] **Step 6: Commit the lint cleanup separately**

Suggested commit:

```bash
git add src/inspector/SettingsRecommendations.js src/inspector/StylesRecommendations.js src/inspector/suggestion-keys.test.js src/templates/TemplateRecommender.js src/utils/template-actions.js
git commit -m "chore: restore JS lint health for template and inspector flows"
```

---

## Chunk 3: Add Regression Coverage

### Task 4: Add focused JS coverage for template UI state

**Files:**
- `src/templates/TemplateRecommender.js`
- `src/templates/template-suggestion-state.js` (recommended extraction)
- `src/templates/__tests__/template-suggestion-state.test.js`

This logic is currently embedded in `TemplateRecommender.js` and should not remain effectively untested:
- `getSuggestionCardKey()`
- `getTemplatePartKey()`
- `areAllSuggestionActionsDone()`
- `getPendingSuggestion()`
- `getApplyAllFeedback()`

- [ ] **Step 1: Extract pure state helpers if direct component testing would be too brittle**

Create `src/templates/template-suggestion-state.js` and move the pure helper functions there.

Do not move DOM- or hook-dependent code into the helper file. Keep it pure and data-oriented.

- [ ] **Step 2: Add JS unit tests for the extracted helper module**

Create `src/templates/__tests__/template-suggestion-state.test.js` covering at least:
- `getTemplatePartKey()` returns deterministic keys.
- `areAllSuggestionActionsDone()` returns `false` for empty suggestions.
- `areAllSuggestionActionsDone()` returns `true` only when all parts and patterns are already complete.
- `getPendingSuggestion()` drops already-applied parts and patterns.
- `getApplyAllFeedback()` returns:
  - `null` when everything succeeds
  - `warning` on partial success
  - `error` when nothing applies

- [ ] **Step 3: Reconnect `TemplateRecommender.js` to the tested helpers**

After extraction:
- Import the helper functions back into `TemplateRecommender.js`.
- Keep component behavior unchanged.

- [ ] **Step 4: Run only the new template UI tests**

Run:

```bash
npm run test:unit -- --runInBand --testPathPattern="template-suggestion-state"
```

Expected:
- The new tests pass without needing WordPress runtime globals.

### Task 5: Add JS coverage for template action helpers

**Files:**
- `src/utils/template-actions.js`
- `src/utils/__tests__/template-actions.test.js`

- [ ] **Step 1: Add unit tests with mocked WordPress selectors and dispatchers**

Create `src/utils/__tests__/template-actions.test.js` and mock:
- `@wordpress/data`
- `@wordpress/block-editor`
- `@wordpress/editor`
- `@wordpress/blocks`

Cover at least:
- `findBlockByArea()` prefers an explicit empty placeholder over an already-assigned part in the same area.
- `assignTemplatePart()` returns `false` when no matching area exists.
- `assignTemplatePart()` returns `false` when the post-update block does not reflect the slug change.
- `assignTemplatePart()` returns `true` only when the slug persists and the block is selected.
- `insertPatternByName()` returns `false` when the pattern cannot be found or parses to zero blocks.
- `insertPatternByName()` returns `false` when insertion does not increase the block count.
- `applySuggestion()` returns per-item success/failure results for both parts and patterns.

- [ ] **Step 2: Keep the implementation side-effect boundaries explicit**

Do not bury `select()` / `dispatch()` calls inside untestable closures. If needed, add tiny private helper wrappers so the logic remains unit-testable without changing runtime behavior.

- [ ] **Step 3: Run the focused template-action tests**

Run:

```bash
npm run test:unit -- --runInBand --testPathPattern="template-actions"
```

Expected:
- The new tests pass and make the template action contract explicit.

### Task 6: Add PHP coverage for the template backend

**Files:**
- `tests/phpunit/bootstrap.php`
- `tests/phpunit/TemplatePromptTest.php`
- `tests/phpunit/TemplateAbilitiesTest.php`

- [ ] **Step 1: Extend the PHP bootstrap with template-related stubs**

Add test fixtures to `WordPressTestState` for:
- block templates
- template parts
- parsed blocks
- pattern registry snapshots if needed by `ServerCollector::for_patterns()`

Add or extend stub functions for:
- `get_block_template()`
- `get_block_templates()`
- `parse_blocks()`

Keep the new stubs minimal and deterministic. Do not turn `bootstrap.php` into a second runtime.

- [ ] **Step 2: Add `TemplatePromptTest.php`**

Cover at least:
- valid JSON payload is accepted and sanitized
- unknown template-part slugs are rejected
- unknown areas are rejected
- unknown pattern names are rejected
- a response with zero actionable suggestions returns `WP_Error`

- [ ] **Step 3: Add `TemplateAbilitiesTest.php`**

Cover at least:
- missing `templateRef` returns `WP_Error` with status `400`
- unresolved template returns `WP_Error` with status `404`
- successful template recommendation request:
  - resolves template context
  - calls the Azure Responses client through the existing remote-post stub
  - returns validated suggestions and explanation

- [ ] **Step 4: Run the full PHP suite**

Run:

```bash
vendor/bin/phpunit
```

Expected:
- Existing block tests still pass.
- New template tests pass.

- [ ] **Step 5: Commit the new regression coverage**

Suggested commit:

```bash
git add tests/phpunit/bootstrap.php tests/phpunit/TemplatePromptTest.php tests/phpunit/TemplateAbilitiesTest.php src/templates src/utils/__tests__/template-actions.test.js
git commit -m "test: add regression coverage for template recommendation flows"
```

---

## Chunk 4: Reconcile Docs and Archive Stale Plans

### Task 7: Update active docs so they match the current code

**Files:**
- `docs/flavor-agent-readme.md`
- `STATUS.md`
- `CLAUDE.md`

- [ ] **Step 1: Update `docs/flavor-agent-readme.md`**

Make these changes:
- In the intro, state that the plugin currently has three editor experiences:
  - block recommendations
  - pattern recommendations
  - template recommendations
- In "Abilities Status":
  - move `flavor-agent/recommend-template` from stubbed to implemented
  - leave only `flavor-agent/recommend-navigation` as stubbed
- In the verification/dev section, document the real current commands:
  - `npm test -- --runInBand` (after the package script is added)
  - `npm run lint:js`
  - `vendor/bin/phpunit`
  - `vendor/bin/phpcs`
  - `npm run build`

- [ ] **Step 2: Correct `STATUS.md`**

Replace the inaccurate known-issue statement claiming there is no PHP test suite / PHPCS coverage.

Suggested direction:
- Add a short `Verification` section listing the actual commands that pass.
- Move the remaining real risks into `Known Issues`, for example:
  - template coverage was added recently and should be kept current
  - only navigation remains unimplemented

- [ ] **Step 3: Align `CLAUDE.md` with the actual verification contract**

Keep the docs consistent with the scripts that actually exist in `package.json`.

If `npm test` is added, document it there as the standard local JS test entry point.

- [ ] **Step 4: Search for stale references in active docs**

Run:

```bash
rg -n "recommend-template|no automated PHP test suite|PHPCS coverage|Stubbed abilities returning 501|npm test" docs STATUS.md CLAUDE.md
```

Update active source-of-truth docs. Historical specs can remain historical, but active docs must be correct.

### Task 8: Archive implementation plans that are no longer active

**Files:**
- `docs/plans/2026-03-17-recommend-template.md`
- `docs/plans/completed/`

- [ ] **Step 1: Move the shipped template implementation plan to `completed/`**

Run:

```bash
mkdir -p docs/plans/completed
git mv docs/plans/2026-03-17-recommend-template.md docs/plans/completed/
```

Reason:
- The file still describes `recommend-template` as a 501 stub.
- Leaving it in the active plans folder makes the repo look less complete than it is.

- [ ] **Step 2: Review other plan files in `docs/plans/`**

If `2026-03-17-cleanup-and-contentonly-fix.md` is also fully implemented by the time this remediation lands, move it to `completed/` as a follow-up. If not, leave it active.

- [ ] **Step 3: Commit the doc reconciliation**

Suggested commit:

```bash
git add docs/flavor-agent-readme.md STATUS.md CLAUDE.md docs/plans/completed/
git commit -m "docs: reconcile active status and archive shipped template plan"
```

---

## Chunk 5: Normalize Verification Entry Points and Run Full QA

### Task 9: Make `npm test` work

**Files:**
- `package.json`

- [ ] **Step 1: Add a top-level test script**

In `package.json`, add:

```json
"test": "wp-scripts test-unit-js"
```

Keep `test:unit` for targeted or explicit runs.

- [ ] **Step 2: Re-check the common workflows**

Run:

```bash
npm test -- --runInBand
npm run test:unit -- --runInBand
```

Expected:
- Both commands run the JS test suite successfully.

- [ ] **Step 3: Update docs to use the canonical command**

Where the docs refer to the full JS suite, prefer `npm test -- --runInBand` as the most predictable entry point for humans.

### Task 10: Run the final full verification matrix

**Files:**
- No new files

- [ ] **Step 1: Run the full automated suite**

Run:

```bash
npm run lint:js
npm test -- --runInBand
vendor/bin/phpunit
vendor/bin/phpcs
npm run build
```

Expected:
- All five commands pass.

- [ ] **Step 2: Run focused manual QA in WordPress**

Check these flows:

1. Block editor / Inspector
   - Select an editable block and fetch recommendations.
   - Apply a suggestion that produces a real change: the UI should show temporary "Applied" feedback.
   - Apply a suggestion that is filtered out or produces no safe update: the UI must not show false "Applied" feedback.

2. Content-restricted and disabled blocks
   - `contentOnly` blocks should keep the explanatory notice and only surface content-safe actions.
   - `disabled` blocks should not render AI controls.

3. Pattern recommendations
   - Recommended patterns still appear in the `Recommended` category.
   - Search-triggered refresh still works.
   - The high-confidence badge still behaves as before.

4. Template recommendations
   - The panel appears only for `wp_template` entities when Azure chat config is present.
   - Fetching suggestions resets stale per-card applied state.
   - "Apply All" handles:
     - full success
     - partial success
     - total failure
   - Individual assign/insert buttons surface the right success/error feedback.

5. Settings screen
   - The sync button still loads.
   - Status still renders after the JS refactors.

- [ ] **Step 3: Capture the final repo state**

Run:

```bash
git status --short
git log --oneline -n 5
```

Expected:
- No accidental file churn.
- Commits are grouped by concern and easy to review.

- [ ] **Step 4: Update `STATUS.md` one last time if the remaining risk list changed during implementation**

Do this only after the final matrix is green.

---

## Suggested Execution Order

1. Chunk 1 - stabilize the current WIP
2. Chunk 2 - restore lint health
3. Chunk 3 - add missing regression coverage
4. Chunk 4 - reconcile docs and archive stale plans
5. Chunk 5 - normalize `npm test` and run final QA

Do not reorder this. The doc updates should describe the code that actually exists after the tests and lint fixes land.
