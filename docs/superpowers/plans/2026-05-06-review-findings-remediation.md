# Review Findings Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Close the current uncommitted-change review findings by removing implementation-detail copy from the pattern recommendation shelf and making the remediation documentation accurate before it is committed.

**Execution Status:** Completed in place against the existing uncommitted changeset on 2026-05-06. Final lint required scoped repo-native formatting fixes in `src/patterns/__tests__/PatternRecommender.test.js`, `src/admin/__tests__/activity-log.test.js`, and `src/components/__tests__/AIActivitySection.test.js`.

**Architecture:** The pattern recommender should present a compact product UI inside the inserter while keeping architectural guarantees in contributor documentation. The existing remediation plan under `docs/reference/` should become a truthful closeout artifact after verification, so future readers do not confuse already-applied work with pending work.

**Tech Stack:** WordPress plugin JavaScript, React via `@wordpress/element`, `@wordpress/scripts` Jest, repo-native docs checks, and the existing Flavor Agent verification scripts.

---

## Findings Covered

1. `src/patterns/PatternRecommender.js` renders visible implementation detail in the editor: "AI-ranked patterns stay local to this shelf. Gutenberg's native pattern registry stays unchanged."
2. `docs/reference/2026-05-06-recommendation-surface-contract-remediation-plan.md` is untracked and reads like an unfinished future-work plan even though the same changeset already implements much of it.

## File Responsibility Map

- `src/patterns/PatternRecommender.js`: Remove persistent implementation-detail shelf copy while preserving the local shelf header, count, diagnostics, and insert actions.
- `src/patterns/__tests__/PatternRecommender.test.js`: Replace positive assertions for internal copy with negative assertions that the inserter shelf does not expose native-registry implementation language.
- `docs/reference/2026-05-06-recommendation-surface-contract-remediation-plan.md`: Convert the stale open plan into a closeout-style artifact with explicit completed work and final verification evidence.
- `docs/reference/recommendation-ui-consistency.md`: Keep the "local shelf only; no native Gutenberg pattern mutation" contract in contributor documentation, not in visible editor copy.

## Task 1: Remove Pattern Shelf Implementation Copy

**Files:**
- Modify: `src/patterns/__tests__/PatternRecommender.test.js`
- Modify: `src/patterns/PatternRecommender.js`

- [x] **Step 1: Make the UI regression test fail**

In `src/patterns/__tests__/PatternRecommender.test.js`, update the first shelf assertion block in the test that transitions from an allowed-pattern warning to a matched shelf. Replace the current assertion for `"AI-ranked patterns stay local to this shelf."` with assertions against the inserter shelf text:

```js
const shelfText = inserterContainer.textContent;

expect( shelfText ).toContain( 'Hero' );
expect( shelfText ).toContain( 'Flavor Agent' );
expect( shelfText ).toContain( '1 recommendation' );
expect( shelfText ).not.toContain( 'native pattern registry' );
expect( shelfText ).not.toContain( 'Gutenberg' );
```

In the `"renders a local inserter shelf and inserts matched allowed patterns"` test, replace the same positive implementation-copy assertion with the same negative assertions:

```js
const shelfText = inserterContainer.textContent;

expect( shelfText ).toContain( 'Hero' );
expect( shelfText ).toContain( 'Recommended hero pattern.' );
expect( shelfText ).toContain( 'Flavor Agent' );
expect( shelfText ).toContain( '1 recommendation' );
expect( shelfText ).not.toContain( 'native pattern registry' );
expect( shelfText ).not.toContain( 'Gutenberg' );
```

- [x] **Step 2: Run the focused PatternRecommender test and confirm the regression**

Run:

```bash
npm run test:unit -- --runTestsByPath src/patterns/__tests__/PatternRecommender.test.js
```

Expected before implementation: FAIL because `shelfText` still contains the visible string about Gutenberg's native pattern registry.

- [x] **Step 3: Remove the implementation-copy paragraph**

In `src/patterns/PatternRecommender.js`, remove this JSX block from `PatternShelf`:

```jsx
<p className="flavor-agent-pattern-summary__copy">
	AI-ranked patterns stay local to this shelf. Gutenberg&apos;s
	native pattern registry stays unchanged.
</p>
```

Leave the header, count pill, filtered-candidate notice, recommendation items, and insert buttons in place:

```jsx
<div className="flavor-agent-pattern-summary__header">
	<span className="flavor-agent-pill flavor-agent-pill--lane">
		Flavor Agent
	</span>
	<span className="flavor-agent-pill">
		{ formatCount( items.length, 'recommendation' ) }
	</span>
</div>
<PatternFilteredCandidateNotice diagnostics={ diagnostics } />
<div className="flavor-agent-pattern-shelf__items">
```

Do not remove `.flavor-agent-pattern-summary__copy` from `src/editor.css`; `PatternStatusSummary` still uses that class for loading, setup, empty, and error states.

- [x] **Step 4: Re-run focused PatternRecommender coverage**

Run:

```bash
npm run test:unit -- --runTestsByPath src/patterns/__tests__/PatternRecommender.test.js
```

Expected after implementation: PASS.

## Task 2: Convert The Stale Remediation Artifact To A Closeout

**Files:**
- Modify: `docs/reference/2026-05-06-recommendation-surface-contract-remediation-plan.md`
- Reference: `docs/reference/recommendation-ui-consistency.md`

- [x] **Step 1: Confirm the architecture contract already lives in docs**

Run:

```bash
rg -n "does not register, reorder, or mutate Gutenberg native pattern categories|local shelf" docs/reference/recommendation-ui-consistency.md docs/FEATURE_SURFACE_MATRIX.md
```

Expected: matches in contributor-facing docs, including `docs/reference/recommendation-ui-consistency.md` and `docs/FEATURE_SURFACE_MATRIX.md`.

- [x] **Step 2: Replace the stale plan framing with closeout framing**

In `docs/reference/2026-05-06-recommendation-surface-contract-remediation-plan.md`, replace the title and introductory sections before `## Task 1` with:

```md
# Recommendation Surface Contract Remediation Closeout

**Status:** Implemented in the current uncommitted changes; final verifier evidence is recorded below.

**Goal:** Document the completed recommendation-surface contract cleanup so future readers can distinguish resolved implementation work from remaining verification gates.

**Architecture:** Pattern recommendations render only in Flavor Agent's local inserter shelf and do not register, reorder, or mutate native Gutenberg pattern categories. Block structural operations use the top-level target contract (`targetClientId`, `targetSignature`, `targetSurface`, `targetType`) across JavaScript validation, PHP validation, and LLM response sanitization.

**Tech Stack:** WordPress plugin PHP, PHPUnit, Gutenberg editor JavaScript, `@wordpress/scripts` Jest, Flavor Agent store/context utilities, repo-native build and verifier scripts.

---

## Closed Findings

- [x] Removed native `recommended` block pattern category registration and native pattern category reorder behavior from `flavor-agent.php`.
- [x] Added lifecycle coverage proving Flavor Agent does not register or reorder native Gutenberg pattern categories for pattern recommendations.
- [x] Canonicalized JS block operation validation to top-level target fields only.
- [x] Added JS, PHP validator, and LLM sanitizer coverage for nested target-only operation rejection.
- [x] Documented the local-shelf-only pattern recommendation contract in contributor-facing docs.
- [x] Kept the local-shelf-only contract out of persistent editor UI copy.

## File Responsibility Map

- `flavor-agent.php`: Owns plugin bootstrap hooks and no longer mutates native pattern categories.
- `tests/phpunit/PluginLifecycleTest.php`: Pins bootstrap behavior against native pattern category registration or reorder regressions.
- `src/utils/block-operation-catalog.js`: Owns client-side canonical block operation target normalization.
- `src/utils/__tests__/block-operation-catalog.test.js`: Pins nested target-only rejection and top-level target acceptance in JavaScript.
- `tests/phpunit/BlockOperationValidatorTest.php`: Pins server-side canonical target validation.
- `tests/phpunit/PromptRulesTest.php`: Pins LLM response normalization so nested `target` payloads do not leak into executable operation proposals.
- `docs/reference/recommendation-ui-consistency.md`: Records the local-shelf-only pattern recommendation contract for contributors.
```

- [x] **Step 3: Preserve task history without presenting it as pending work**

Rename these headings in the same file:

```md
## Task 1: Remove Native Pattern Category Mutation
```

to:

```md
## Completed Work 1: Removed Native Pattern Category Mutation
```

```md
## Task 2: Canonicalize Block Operation Targets To Top-Level Fields
```

to:

```md
## Completed Work 2: Canonicalized Block Operation Targets To Top-Level Fields
```

```md
## Task 3: Cross-Surface Regression Verification
```

to:

```md
## Verification Work
```

Then convert implemented checklist markers in the file from `- [x]` to `- [x]` for the completed implementation and focused-test steps. Leave the aggregate verification step unchecked until Step 5 below has actually passed.

- [x] **Step 4: Replace stale completion criteria with closeout evidence**

Replace the final `## Completion Criteria` section with:

```md
## Closeout Evidence

- [x] `flavor-agent.php` contains no `register_block_pattern_category( 'recommended', ... )` call and no `block_editor_settings_all` category reorder filter for pattern categories.
- [x] Pattern recommendations still render through `src/patterns/PatternRecommender.js` and `src/patterns/InserterBadge.js`, using current `visiblePatternNames`, allowed patterns, insertability filtering, and core `insertBlocks()`.
- [x] `src/utils/block-operation-catalog.js` reads operation target metadata only from top-level fields.
- [x] JS and PHP tests cover nested target-only rejection and top-level operation acceptance.
- [x] Final local verification has passed and exact commands are recorded in the implementation closeout.

## Verification Commands

- `npm run test:unit -- --runTestsByPath src/patterns/__tests__/PatternRecommender.test.js`
- `npm run test:unit -- --runTestsByPath src/components/__tests__/InlineActionFeedback.test.js src/components/__tests__/StaleResultBanner.test.js src/style-book/__tests__/dom.test.js src/utils/__tests__/block-operation-catalog.test.js`
- `composer run test:php -- tests/phpunit/PatternIndexTest.php tests/phpunit/InfraAbilitiesTest.php tests/phpunit/EditorSurfaceCapabilitiesTest.php tests/phpunit/PluginLifecycleTest.php tests/phpunit/PromptRulesTest.php tests/phpunit/BlockOperationValidatorTest.php tests/phpunit/SettingsTest.php`
- `npm run lint:js`
- `npm run build`
- `npm run check:docs`
- `npm run verify -- --skip-e2e`
```

- [x] **Step 5: Run the doc freshness gate**

Run:

```bash
npm run check:docs
```

Expected after implementation: PASS.

## Task 3: Final Verification

**Files:**
- Verify: JavaScript source and tests touched by Task 1
- Verify: Docs touched by Task 2
- Verify: Existing PHP/JS contract tests from the broader uncommitted changeset

- [x] **Step 1: Run the focused pattern recommender test**

Run:

```bash
npm run test:unit -- --runTestsByPath src/patterns/__tests__/PatternRecommender.test.js
```

Expected: PASS.

- [x] **Step 2: Re-run the focused JS suites from the review**

Run:

```bash
npm run test:unit -- --runTestsByPath src/components/__tests__/InlineActionFeedback.test.js src/components/__tests__/StaleResultBanner.test.js src/style-book/__tests__/dom.test.js src/utils/__tests__/block-operation-catalog.test.js
```

Expected: PASS.

- [x] **Step 3: Re-run the focused PHP suites from the review**

Run:

```bash
composer run test:php -- tests/phpunit/PatternIndexTest.php tests/phpunit/InfraAbilitiesTest.php tests/phpunit/EditorSurfaceCapabilitiesTest.php tests/phpunit/PluginLifecycleTest.php tests/phpunit/PromptRulesTest.php tests/phpunit/BlockOperationValidatorTest.php tests/phpunit/SettingsTest.php
```

Expected: PASS.

- [x] **Step 4: Run source lint and production build**

Run:

```bash
npm run lint:js
npm run build
```

Expected: both commands exit 0. `build/` is generated output and should remain unstaged unless the repository policy changes.

- [x] **Step 5: Run the fast aggregate verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: `VERIFY_RESULT` reports success or reports only an explicitly understood environment prerequisite. If an environment prerequisite blocks `lint-plugin`, rerun with:

```bash
npm run verify -- --skip-e2e --skip=lint-plugin
```

Record the exact verifier result in the closeout artifact before final handoff.

- [x] **Step 6: Review the final diff for scope**

Run:

```bash
git diff -- src/patterns/PatternRecommender.js src/patterns/__tests__/PatternRecommender.test.js docs/reference/2026-05-06-recommendation-surface-contract-remediation-plan.md docs/superpowers/plans/2026-05-06-review-findings-remediation.md
```

Expected: the diff only removes visible implementation copy, updates tests for that behavior, and converts stale plan language into accurate closeout language.

## Self-Review Checklist

- [x] The pattern shelf no longer exposes the phrase `native pattern registry` or the word `Gutenberg` in its persistent recommendation UI.
- [x] `PatternStatusSummary` still uses `.flavor-agent-pattern-summary__copy` for non-shelf loading, setup, empty, and error states.
- [x] The architecture contract remains documented in `docs/reference/recommendation-ui-consistency.md`.
- [x] The untracked remediation artifact no longer reads as a pending implementation plan after code is already present.
- [x] Verification commands and outcomes are recorded before claiming completion.
