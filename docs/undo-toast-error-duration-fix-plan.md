# Undo Toast Error Duration Fix Plan

## Goal

Ensure a toast that changes from success to error after a failed undo always receives a fresh error auto-dismiss duration instead of inheriting the partially elapsed success timer.

## Finding

- `src/components/UndoToast.js` schedules the auto-dismiss timeout before resetting `remainingRef` when `variant` or `autoDismissMs` changes.
- `src/store/undo-toast-action.js` now keeps a toast visible and updates it to `variant: 'error'` when `undoActivity()` returns `{ ok: false }`.
- If the success toast was already near dismissal, the updated error toast can disappear almost immediately rather than using `TOAST_DEFAULTS.errorMs`.

## Root Cause

The timer effect depends on `isPaused`, `reducedMotion`, and `id`, while the reset effect depends on `autoDismissMs` and `variant`. On an error update, React runs the existing timer scheduling effect before the separate reset effect, so the newly scheduled timeout can still use the stale `remainingRef.current` from the previous success toast.

## Implementation Plan

1. Update the timer reset flow in `src/components/UndoToast.js`.
   - Track the active timer identity using `variant`, `autoDismissMs`, and `id`.
   - Ensure `remainingRef.current` is reset to the new `autoDismissMs` before any timeout is scheduled for the updated toast state.
   - Keep pause/resume behavior unchanged for hover, focus, and reduced motion.

2. Prefer the smallest safe code change.
   - Merge the reset behavior into the scheduling effect or introduce a tiny ref that records the last timer configuration.
   - Avoid changing `ToastRegion`, toast reducer behavior, or store action contracts unless tests expose an additional issue.

3. Add regression coverage in `src/components/__tests__/UndoToast.test.js`.
   - Render a success toast with `autoDismissMs: 6000`.
   - Advance most of the success duration.
   - Re-render the same toast id as `variant: 'error'` with `autoDismissMs: TOAST_DEFAULTS.errorMs` or an explicit shorter test value.
   - Assert the toast does not dismiss after the old remaining success time.
   - Assert it dismisses only after the full new error duration elapses.

4. Keep existing behavior covered.
   - Existing tests for cumulative unpaused duration must continue to pass.
   - Existing tests for hover and focus pause coordination must continue to pass.
   - Existing tests for reduced motion must continue to pass.

## Verification Plan

Run the focused JS test suite after implementation:

```bash
npm run test:unit -- src/components/__tests__/UndoToast.test.js src/store/__tests__/undo-toast-action.test.js
```

Run the broader changed JS test set to guard adjacent behavior:

```bash
npm run test:unit -- src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js src/components/__tests__/UndoToast.test.js src/store/__tests__/undo-toast-action.test.js
```

Run whitespace validation:

```bash
```

## Acceptance Criteria

- Error toasts created from failed undo attempts remain visible for their configured error duration.
- Existing pause/resume behavior remains unchanged.
- No changes are required to public REST contracts or docs beyond this implementation plan.
- Focused tests pass.
