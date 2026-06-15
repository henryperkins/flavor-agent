# Toast Accessibility & Timing Cleanup — Design

- **Date:** 2026-06-15
- **Status:** Implemented; historical design context. The shipped implementation plan is archived at `docs/superpowers/plans/archive/2026-06-15-toast-a11y-timing-cleanup.md`, and current behavior is summarized in `docs/reference/current-open-work.md`.
- **Branch:** `toast-a11y-timing-cleanup`
- **Owner:** Henry Perkins

## Problem

The `docs/reference/current-open-work.md` "Toast accessibility and timing cleanup" row
records a root review note (sourced from the now-deleted `ConfirmedFindings.txt`) of four
issues in the undo-toast subsystem: drift-prone duration duplication, a missing landmark
role on the toast portal root, title-only disabled-Undo explanations, and duplicate
surface-title casing maps.

A 2026-06-15 grounding pass against the live code found the row is partly stale and the
implementation is mature. **This is a sweep-and-cleanup task: remove duplication and reuse
what already exists — do not add divergent new mechanisms, dependencies, or features.**

### Already resolved (no work — context only)

- **Landmark role** — `ToastRegion.getRegionRoot()` sets `role="region"` + `aria-label`
  (`src/components/ToastRegion.js:42-46`), asserted in
  `src/components/__tests__/ToastRegion.test.js:87-88`.
- **Disabled-Undo explanation** — `UndoToast` wires `aria-describedby` → a
  `screen-reader-text` span, not just `title` (`src/components/UndoToast.js:242-244,315-319`),
  asserted in `src/components/__tests__/UndoToast.test.js:147-154`.

## Scope (locked)

| ID | Item | Nature |
| -- | ---- | ------ |
| T1 | Single-source the success auto-dismiss duration | Removes a duplicated literal |
| M1 | Normalize surface-casing once | Removes a duplicated casing map |
| C1 | Use `@wordpress/compose` `useReducedMotion()` instead of hand-rolled detection | Deletes ~30 lines that recreate an existing hook |
| A5 | Undo button resting-border contrast ≥ 3:1 | One-line latent WCAG 1.4.11 fix |

T1/M1/C1 remove code or duplication; A5 is a one-line contrast fix. None add a dependency
(`@wordpress/compose` is already a dependency) or a new mechanism.

## Components

### T1 — Duration single-sourced

`TOAST_DEFAULTS.successMs` (= `DEFAULT_SUCCESS_MS` = 6000) in `src/store/toasts.js` is the
source of truth; the error duration (`TOAST_DEFAULTS.errorMs` = 8000) is already
single-sourced via `undo-toast-action.js`.

- `src/components/UndoToast.js`: change the `autoDismissMs = 6000` default param to
  `autoDismissMs = TOAST_DEFAULTS.successMs`, importing `TOAST_DEFAULTS` from
  `../store/toasts` (consistent with `ToastRegion.js`, which already imports it).
- `src/editor.css:2795`: keep the `var(--flavor-agent-toast-auto-dismiss-ms, 6000ms)`
  fallback (CSS cannot import JS; the var is always set at runtime from `UndoToast`'s inline
  `progressStyle`). Add a one-line comment marking the literal a fallback bound to
  `TOAST_DEFAULTS.successMs` so a future change updates both knowingly.

### M1 — Surface-casing normalized once

`src/store/toasts.js` encodes surface casing twice: the `SURFACE_TITLE_ALIASES`
camelCase→kebab map (used by `getSurfaceTitle`) and the dual-case `buildToastDetail` switch
(`case 'templatePart': case 'template-part':` …).

- Add `normalizeSurfaceKey( surface )` that applies the **existing** `SURFACE_TITLE_ALIASES`
  map and returns the canonical kebab key, or the input unchanged. (Reuses the map already
  present — no new mapping table.)
- `getSurfaceTitle` normalizes, then looks up `SURFACE_TITLE_BY_KEY`.
- `buildToastDetail` switches on `normalizeSurfaceKey( surface )` with kebab-only cases,
  removing the dual-case labels.
- The stored `toast.surface` field stays the raw input value (no downstream consumer reads
  it; minimizes test churn).

### C1 — Reuse `useReducedMotion()`

`src/components/UndoToast.js` reimplements reduced-motion detection that
`@wordpress/compose` already provides: `getReducedMotionPreference()` (lines 30-39), the
`useState` initializer (lines 57-59), and the `matchMedia` change-subscription `useEffect`
(lines 71-92).

- Replace all of the above with `import { useReducedMotion } from '@wordpress/compose';` and
  `const reducedMotion = useReducedMotion();`. `@wordpress/compose` is already a declared
  dependency; no `package.json` change.
- The rest of the component is unchanged: `reducedMotion` still gates the auto-dismiss timer,
  the `__persistent` text, and the `is-static` progress class.
- The hook is SSR/jsdom-safe (returns `false` when `matchMedia` is unavailable), matching the
  current behavior.

### A5 — Undo button resting-border contrast

`src/editor.css`: the Undo button's resting border
(`.flavor-agent-toast__action.components-button`) is `rgba(255,255,255,0.28)` ≈ 2.3:1 against
the `#17232a` toast ground — under the 3:1 WCAG 1.4.11 non-text bar (exempt only via the
visible "Undo" label). Bump to `rgba(255,255,255,0.4)` (≈ 3.7:1). Hover and `:focus-visible`
states already exceed the bar and are unchanged. Pure CSS, one value.

## Error handling

No behavioral changes. T1/M1/C1 are refactors; existing guards (timer cleanup, jsdom safety)
are preserved.

## Testing strategy

Unit (Jest, jsdom):

- `src/store/__tests__/toasts.test.js` (M1): `normalizeSurfaceKey` maps camelCase aliases to
  kebab; `getSurfaceTitle` and `buildToastDetail` resolve correctly for both casings of
  `templatePart` / `globalStyles` / `styleBook`. Existing surface→title cases stay green.
- `src/components/__tests__/UndoToast.test.js` (T1/C1): default `autoDismissMs` equals
  `TOAST_DEFAULTS.successMs`; reduced-motion tests stay green — `useReducedMotion()` reads
  `matchMedia` internally, so the existing `window.matchMedia` mock (lines 24-25) drives the
  hook; extend the mock with `addEventListener`/`removeEventListener` no-ops if the hook
  requires them.

Gates (per `docs/reference/cross-surface-validation-gates.md` — shared toast UI is consumed
by block/template/template-part/global-styles/style-book applies):

- nearest targeted Jest suites above;
- `node scripts/verify.js --skip-e2e`, then inspect `output/verify/summary.json`;
- `npm run check:docs` (contributor doc change below);
- `npm run test:e2e:playground` if practical (toast still appears after a block apply).
- A5 has no unit test (plugin CSS contrast is not unit-tested here); verify the new ratio with
  a contrast checker (`rgba(255,255,255,0.4)` on `#17232a` ≈ 3.7:1) and confirm hover /
  `:focus-visible` states are visually unchanged.

## Docs / governance updates

`docs/reference/current-open-work.md`: remove the "Toast accessibility and timing cleanup"
row from **Current Implementation Candidates** and note completion (2026-06-15) — record that
the landmark-role and disabled-Undo findings were already resolved, and that the sweep
single-sourced the duration, normalized surface-casing, adopted `useReducedMotion()`, and
raised the Undo button's resting-border contrast to ≥ 3:1. The `ConfirmedFindings.txt` source
pointer is dangling (file deleted) and is dropped with the row.

## Deferred — not cleanup (out of scope for this task)

These came from the broader a11y sweep but **add** behavior/code rather than remove
duplication, so they do not belong in a cleanup pass. Captured here for a future a11y-
enhancement spec:

- **A1 · `speak()` announcer** — the toast already uses the project-wide declarative
  `role="status"` / `aria-live` pattern (same idiom as `SurfaceScopeBar`, `SuggestionChips`,
  `StaleResultBanner`, `InlineActionFeedback`). Switching to `@wordpress/a11y` `speak()` would
  **diverge** from that pattern and add a dependency — the opposite of cleanup.
- **A2 · `aria-keyshortcuts` for the undo chord** — net-new feature; no existing code to
  reuse.
- **A3 · focus return on dismissal** — net-new focus-management code; no existing helper to
  reuse.
- **C2 · shared same-origin iframe-document helper** — `ToastRegion.getSameOriginIframeDocument()`
  overlaps `src/style-book/dom.js` iframe access, but the usages differ and extracting a shared
  util crosses into the Style Book surface. Out of scope for a toast cleanup; note as a future
  consolidation candidate.

## Non-goals

- The two already-resolved findings (landmark role, disabled-Undo) — untouched.
- Any visual redesign, palette, eviction-policy, pause-on-hover, or error-duration change.

## Rollout

Single branch `toast-a11y-timing-cleanup`; implemented test-first per item, verified via the
gates above, then a docs pass. No migration, no data changes, no new dependency, no feature
flag.
