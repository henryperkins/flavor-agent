# Toast Accessibility & Timing Cleanup Implementation Plan

> Status: Archived 2026-06-15. Implemented same-day as the toast accessibility and timing cleanup; retained only as historical execution context. Verification evidence is the focused toast Jest bundle, `npm run check:docs`, touched-file JS lint, and `npm run verify -- --skip-e2e` with Plugin Check blocked by the local database connection.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove duplication and reuse existing platform code in the undo-toast subsystem (single-source the auto-dismiss duration, normalize surface-casing once, adopt `@wordpress/compose` `useReducedMotion()`), and fix one latent Undo-button border-contrast gap.

**Architecture:** Pure cleanup. Three of the four changes delete code or duplication; one is a one-line CSS contrast fix. No new dependency, no new mechanism, no behavior change for end users. Each item is a refactor guarded by characterization tests (keep the suite green) plus one new guard test for the duration default.

**Tech Stack:** `@wordpress/scripts` (webpack + Jest), React, `@wordpress/data`, `@wordpress/compose`, plain CSS.

Design source: `docs/superpowers/specs/2026-06-15-toast-a11y-timing-cleanup-design.md`.

---

## Prerequisites (git state)

The working tree has unrelated uncommitted edits in `docs/reference/current-open-work.md`,
`docs/reference/governance-layer.md`, and `docs/reference/wordpress-ai-roadmap-tracking.md`
(the June-15 roadmap refresh) plus the new spec file. Before Task 1:

- [ ] **P1: Decide the roadmap-refresh docs.** Commit them on their own branch/commit, or
  `git stash push docs/reference/current-open-work.md docs/reference/governance-layer.md docs/reference/wordpress-ai-roadmap-tracking.md`.
  This keeps Task 6's docs commit clean (Task 6 re-touches `current-open-work.md`). If they are
  stashed, restore them afterward.

- [ ] **P2: Create the branch and commit the spec.**

```bash
git checkout -b toast-a11y-timing-cleanup
git add docs/superpowers/specs/2026-06-15-toast-a11y-timing-cleanup-design.md
git commit -m "docs: toast a11y/timing cleanup design spec"
```

---

## Task 1: M1 — normalize surface-casing once

Eliminate the duplicated surface-casing knowledge in `src/store/toasts.js`: today the
camelCase→kebab map (`SURFACE_TITLE_ALIASES`, used by `getSurfaceTitle`) and the dual-case
`buildToastDetail` switch both encode it. Add a private `normalizeSurfaceKey()` (reusing the
existing alias map) and switch on canonical kebab keys only.

**Files:**
- Modify: `src/store/toasts.js`
- Test: `src/store/__tests__/toasts.test.js`

- [ ] **Step 1: Add characterization tests for the camelCase detail aliases.**

In `src/store/__tests__/toasts.test.js`, inside the existing `describe` that contains
`it( 'global-styles detail includes path and value', … )`, add these three tests immediately
after that test (before the block's closing `} );`):

```js
it( 'template-part detail resolves for the camelCase surface alias', () => {
	const result = buildToastForActivity( {
		surface: 'templatePart',
		persistedEntry: { id: 'a' },
		suggestion: { area: 'footer' },
		extras: { operations: [ {} ] },
	} );

	expect( result.detail ).toBe( 'footer · 1 ops' );
} );

it( 'global-styles detail resolves for the camelCase surface alias', () => {
	const result = buildToastForActivity( {
		surface: 'globalStyles',
		persistedEntry: { id: 'a' },
		suggestion: { stylePath: 'color.text', value: '#111' },
	} );

	expect( result.detail ).toBe( 'color.text · #111' );
} );

it( 'style-book detail resolves for the camelCase surface alias', () => {
	const result = buildToastForActivity( {
		surface: 'styleBook',
		persistedEntry: { id: 'a' },
		suggestion: { blockName: 'core/button', styleVariation: 'outline' },
	} );

	expect( result.detail ).toBe( 'core/button · outline' );
} );
```

- [ ] **Step 2: Run the tests to establish the green baseline.**

Run: `npm run test:unit -- src/store/__tests__/toasts.test.js --runInBand`
Expected: PASS. (These pass against the current dual-case switch — they are the guard that
the refactor must not break. This is a refactor, so the baseline is green, not red.)

- [ ] **Step 3: Add `normalizeSurfaceKey()` and use it.**

In `src/store/toasts.js`, add a private helper directly above `getSurfaceTitle`:

```js
function normalizeSurfaceKey( surface ) {
	if ( typeof surface !== 'string' ) {
		return '';
	}

	return SURFACE_TITLE_ALIASES[ surface ] || surface;
}
```

Replace the body of `getSurfaceTitle` with:

```js
function getSurfaceTitle( surface ) {
	const surfaceKey = normalizeSurfaceKey( surface );

	if ( surfaceKey && SURFACE_TITLE_BY_KEY[ surfaceKey ] ) {
		return SURFACE_TITLE_BY_KEY[ surfaceKey ];
	}

	return FALLBACK_SURFACE_TITLE;
}
```

Replace `buildToastDetail` with the kebab-only switch (dual-case labels removed):

```js
function buildToastDetail( surface, suggestion, extras ) {
	switch ( normalizeSurfaceKey( surface ) ) {
		case 'block':
			return formatBlockDetail( suggestion, extras );
		case 'template':
			return formatTemplateDetail( suggestion, extras );
		case 'template-part':
			return formatTemplatePartDetail( suggestion, extras );
		case 'global-styles':
			return formatStyleDetail( suggestion );
		case 'style-book':
			return formatStyleBookDetail( suggestion );
		default:
			return suggestion?.label || '';
	}
}
```

- [ ] **Step 4: Run the tests to verify the refactor kept them green.**

Run: `npm run test:unit -- src/store/__tests__/toasts.test.js --runInBand`
Expected: PASS (the three new alias tests plus the existing `maps each surface key to its
title` both-casing test all green).

- [ ] **Step 5: Commit.**

```bash
git add src/store/toasts.js src/store/__tests__/toasts.test.js
git commit -m "refactor(toasts): normalize surface-casing through one helper"
```

---

## Task 2: T1 — single-source the success auto-dismiss duration

`UndoToast` hardcodes `autoDismissMs = 6000` as its default param while
`TOAST_DEFAULTS.successMs` (in `src/store/toasts.js`) is the source of truth.

**Files:**
- Modify: `src/components/UndoToast.js`
- Modify: `src/editor.css`
- Test: `src/components/__tests__/UndoToast.test.js`

- [ ] **Step 1: Add a guard test for the default duration.**

In `src/components/__tests__/UndoToast.test.js`, add an import below `import UndoToast from '../UndoToast';` (line 19):

```js
import { TOAST_DEFAULTS } from '../../store/toasts';
```

Inside the `describe( 'UndoToast — auto-dismiss timer', … )` block (it already calls
`jest.useFakeTimers()` in `beforeEach`), add:

```js
test( 'defaults the auto-dismiss duration to TOAST_DEFAULTS.successMs', () => {
	const props = renderToast( { autoDismissMs: undefined } );

	act( () => {
		jest.advanceTimersByTime( TOAST_DEFAULTS.successMs - 1 );
	} );
	expect( props.onDismiss ).not.toHaveBeenCalled();

	act( () => {
		jest.advanceTimersByTime( 1 );
	} );
	expect( props.onDismiss ).toHaveBeenCalledWith( 'toast-1' );
} );
```

- [ ] **Step 2: Run the test to establish the green baseline.**

Run: `npm run test:unit -- src/components/__tests__/UndoToast.test.js -t "defaults the auto-dismiss" --runInBand`
Expected: PASS (current default literal `6000` equals `TOAST_DEFAULTS.successMs`). This guard
fails in future if the default param and the constant drift apart.

- [ ] **Step 3: Point the default param at the constant.**

In `src/components/UndoToast.js`, add the import alongside the other local imports (after
`import { joinClassNames } from '../utils/format-count';`):

```js
import { TOAST_DEFAULTS } from '../store/toasts';
```

Change the default param in the component signature from:

```js
	autoDismissMs = 6000,
```

to:

```js
	autoDismissMs = TOAST_DEFAULTS.successMs,
```

- [ ] **Step 4: Comment the CSS fallback so it is knowingly bound to the constant.**

In `src/editor.css`, find the `.flavor-agent-toast__progress` rule and add a comment above the
`animation:` line. Change:

```css
.flavor-agent-toast__progress {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 2px;
  background: var(--flavor-agent-color-accent);
  transform-origin: left center;
  animation: flavor-agent-toast-progress var(--flavor-agent-toast-auto-dismiss-ms, 6000ms) linear forwards;
}
```

to:

```css
.flavor-agent-toast__progress {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 2px;
  background: var(--flavor-agent-color-accent);
  transform-origin: left center;
  /* Fallback only; the runtime value is set inline from TOAST_DEFAULTS.successMs
     (src/store/toasts.js) via UndoToast's progressStyle. Keep the two in sync. */
  animation: flavor-agent-toast-progress var(--flavor-agent-toast-auto-dismiss-ms, 6000ms) linear forwards;
}
```

- [ ] **Step 5: Run the toast component tests.**

Run: `npm run test:unit -- src/components/__tests__/UndoToast.test.js --runInBand`
Expected: PASS (the new guard test plus all existing timer/render tests).

- [ ] **Step 6: Commit.**

```bash
git add src/components/UndoToast.js src/editor.css src/components/__tests__/UndoToast.test.js
git commit -m "refactor(toasts): single-source the success auto-dismiss duration"
```

---

## Task 3: C1 — reuse `@wordpress/compose` `useReducedMotion()`

`UndoToast` hand-rolls reduced-motion detection (a `getReducedMotionPreference()` helper, a
`useState` initializer, and a `matchMedia` change-subscription `useEffect`) that
`@wordpress/compose` already provides via `useReducedMotion()`. `@wordpress/compose` is
already a declared dependency.

**Files:**
- Modify: `src/components/UndoToast.js`
- Test: `src/components/__tests__/UndoToast.test.js` (run only; no edits expected)

- [ ] **Step 1: Establish the reduced-motion green baseline.**

Run: `npm run test:unit -- src/components/__tests__/UndoToast.test.js -t "reduced" --runInBand`
Expected: PASS (`renders a static persistence cue …` and `reduced-motion disables auto-dismiss
entirely`). These are the guard for this refactor.

- [ ] **Step 2: Add the `useReducedMotion` import.**

In `src/components/UndoToast.js`, add to the `@wordpress/*` imports (after
`import { Button } from '@wordpress/components';`):

```js
import { useReducedMotion } from '@wordpress/compose';
```

- [ ] **Step 3: Delete the hand-rolled `getReducedMotionPreference` helper.**

Remove this entire function (between the `VARIANT_ICONS` constant and the component):

```js
function getReducedMotionPreference() {
	if (
		typeof window === 'undefined' ||
		typeof window.matchMedia !== 'function'
	) {
		return false;
	}

	return window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
}
```

- [ ] **Step 4: Replace the `reducedMotion` state with the hook.**

Change:

```js
	const [ reducedMotion, setReducedMotion ] = useState(
		getReducedMotionPreference()
	);
```

to:

```js
	const reducedMotion = useReducedMotion();
```

- [ ] **Step 5: Delete the hand-rolled `matchMedia` change-subscription effect.**

Remove this entire `useEffect` (the one with the "Track reduced-motion preference changes"
comment):

```js
	// Track reduced-motion preference changes so a mid-session toggle takes
	// effect on subsequent toasts.
	useEffect( () => {
		if (
			typeof window === 'undefined' ||
			typeof window.matchMedia !== 'function'
		) {
			return undefined;
		}

		const mq = window.matchMedia( '(prefers-reduced-motion: reduce)' );
		const handler = ( event ) => setReducedMotion( event.matches );

		if ( typeof mq.addEventListener === 'function' ) {
			mq.addEventListener( 'change', handler );

			return () => mq.removeEventListener( 'change', handler );
		}

		// Older browsers.
		mq.addListener( handler );

		return () => mq.removeListener( handler );
	}, [] );
```

- [ ] **Step 6: Run the full toast component suite.**

Run: `npm run test:unit -- src/components/__tests__/UndoToast.test.js --runInBand`
Expected: PASS. The existing `setReducedMotion( true/false )` helper reassigns
`window.matchMedia`, which the real `useReducedMotion()` reads internally (the mock already
supplies `addEventListener`/`removeEventListener` no-ops at lines 28-31). If any reduced-motion
test regresses, confirm the mock object still exposes `matches`, `addEventListener`, and
`removeEventListener` — do **not** add new behavior to make it pass.

- [ ] **Step 7: Lint to confirm no now-unused imports remain.**

Run: `npm run lint:js -- src/components/UndoToast.js`
Expected: PASS. (`useState`/`useEffect`/`useRef`/`useCallback` are still used elsewhere in the
component; only the reduced-motion helper, state, and effect were removed.)

- [ ] **Step 8: Commit.**

```bash
git add src/components/UndoToast.js
git commit -m "refactor(toasts): use @wordpress/compose useReducedMotion"
```

---

## Task 4: A5 — Undo button resting-border contrast ≥ 3:1

The Undo button's resting border `rgba(255,255,255,0.28)` ≈ 2.3:1 against the `#17232a` toast
ground, under the WCAG 1.4.11 3:1 non-text bar. Raise it to `rgba(255,255,255,0.4)` (≈ 3.7:1).

**Files:**
- Modify: `src/editor.css`

- [ ] **Step 1: Raise the border alpha.**

In `src/editor.css`, in the `.flavor-agent-toast .flavor-agent-toast__action.components-button`
rule, change:

```css
  border: 1px solid rgba(255, 255, 255, 0.28);
```

to:

```css
  border: 1px solid rgba(255, 255, 255, 0.4);
```

Leave the `:hover` (`0.32`) and `:focus-visible` rules unchanged — both already exceed 3:1.

- [ ] **Step 2: Verify the ratio.**

Confirm with any contrast checker that `#737b7f` (the effective color of
`rgba(255,255,255,0.4)` composited on `#17232a`) vs `#17232a` is ≥ 3:1 (≈ 3.7:1). No unit test
(plugin CSS contrast is not unit-tested in this repo).

- [ ] **Step 3: Commit.**

```bash
git add src/editor.css
git commit -m "fix(toasts): raise Undo button resting-border contrast to >=3:1"
```

---

## Task 5: Verify the full gate

Run the shared-subsystem gates from `docs/reference/cross-surface-validation-gates.md` (the
toast UI is consumed by block/template/template-part/global-styles/style-book applies).

- [ ] **Step 1: Run all four toast test files.**

Run: `npm run test:unit -- src/store/__tests__/toasts.test.js src/store/__tests__/undo-toast-action.test.js src/components/__tests__/UndoToast.test.js src/components/__tests__/ToastRegion.test.js --runInBand`
Expected: PASS, no skipped toast tests.

- [ ] **Step 2: Run the aggregate verifier (no E2E).**

Run: `node scripts/verify.js --skip-e2e`
Expected: final stdout `VERIFY_RESULT={…"status":"pass"…}`.

- [ ] **Step 3: Inspect the structured report.**

Run: `cat output/verify/summary.json`
Expected: `"status": "pass"`; `build`, `lint-js`, `unit`, `lint-php`, `test-php` all
`"status":"pass"` (or `lint-plugin` an explicit required-tool-missing skip — acceptable per
CLAUDE.md; if so the overall status may be `incomplete`, which is fine for this CSS/JS-only
change). No `fail` steps.

- [ ] **Step 4 (optional): Playground smoke.**

Run: `npm run test:e2e:playground`
Expected: PASS. If the harness is unavailable, record the skip as a waiver per the validation
gates rather than silently omitting it.

---

## Task 6: Docs — close the open-work row

**Files:**
- Modify: `docs/reference/current-open-work.md`

- [ ] **Step 1: Remove the toast row from Current Implementation Candidates.**

Delete this entire table row (it is the only row whose first cell is "Toast accessibility and
timing cleanup"):

```
| Toast accessibility and timing cleanup | A root review note reports drift-prone toast duration duplication, missing landmark role on the toast portal root, title-only disabled Undo explanations, and duplicate surface-title casing maps. | `ConfirmedFindings.txt`; `src/store/toasts.js`; `src/components/ToastRegion.js`; `src/components/UndoToast.js`; `src/editor.css` | Treat as a focused UI/accessibility cleanup with JS unit coverage and manual screen-reader semantics review where practical. |
```

- [ ] **Step 2: Add a completion note in the Status section.**

In `docs/reference/current-open-work.md`, immediately after the
`2026-06-15 upstream AI roadmap refresh:` paragraph, add:

```
2026-06-15 toast cleanup: the "Toast accessibility and timing cleanup" candidate is resolved. Two of the four original findings (landmark role on the portal root, disabled-Undo `aria-describedby` explanation) were already implemented and test-covered; the sweep single-sourced the success auto-dismiss duration, normalized surface-casing through one helper, adopted `@wordpress/compose` `useReducedMotion()`, and raised the Undo button's resting-border contrast to ≥ 3:1. The `aria-keyshortcuts`, `speak()` announcer, and focus-return ideas were deferred to a future a11y-enhancement spec (they add behavior rather than remove duplication). The `ConfirmedFindings.txt` source is deleted; its remaining rows now have no backing file.
```

- [ ] **Step 3: Run the docs freshness guard.**

Run: `npm run check:docs`
Expected: exit code 0.

- [ ] **Step 4: Commit.**

```bash
git add docs/reference/current-open-work.md
git commit -m "docs: close the toast a11y/timing cleanup open-work row"
```

---

## Self-Review (completed during planning)

- **Spec coverage:** T1 → Task 2; M1 → Task 1; C1 → Task 3; A5 → Task 4; verification gates →
  Task 5; docs/governance update → Task 6. The two already-resolved findings are explicitly
  out of scope (no task), matching the spec. Deferred items (A1/A2/A3/C2) have no task, by
  design.
- **Placeholder scan:** none — every code/CSS step shows the literal before/after.
- **Type/name consistency:** `normalizeSurfaceKey`, `TOAST_DEFAULTS.successMs`,
  `useReducedMotion` used consistently across tasks; kebab keys (`template-part`,
  `global-styles`, `style-book`) match `SURFACE_TITLE_BY_KEY`.
- **Refactor honesty:** Tasks 1–3 are refactors; their "baseline" steps expect green (guard
  tests), not red. Only behavior-preserving edits follow.
