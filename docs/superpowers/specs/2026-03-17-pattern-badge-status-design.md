# Pattern Recommendation Badge — Unified Status Surface

**Date:** 2026-03-17
**Status:** Draft
**Scope:** Enrich the `InserterBadge` component to serve as the unified status indicator for the pattern recommendation pipeline (loading, ready, error).

## Problem

The pattern recommendation system has a working data pipeline (vector search, LLM ranking, inserter patching) but almost no visible UI. The store tracks `patternStatus` through `idle -> loading -> ready/error`, but nothing renders those states. The only visual element is a static "!" badge on the inserter toggle that appears when a single recommendation scores >= 0.9.

The block recommendation side has cards, confidence bars, loading spinners, error notices, and apply buttons. The pattern side needs parity — surfaced through the badge since recommendations live in the native inserter.

## Design

### Badge States

The `InserterBadge` component gains four visual states driven by store selectors:

| State | Trigger | Badge content | Style | Tooltip |
|-------|---------|--------------|-------|---------|
| **Hidden** | `idle` with no recommendations, or loading hasn't started (see note below) | Nothing rendered | -- | -- |
| **Loading** | `patternStatus === 'loading'` | Empty circle | Accent color with CSS `pulse` animation | "Finding patterns..." |
| **Ready** | `patternStatus === 'ready'` AND `recommendations.length > 0` | Count (e.g. "3") | Static accent circle (current style, sized for digits) | Top reason or generic fallback (see Tooltip Fallback below) |
| **Error** | `patternStatus === 'error'` AND `patternError` is non-null | "!" | WP error red (`#d63638`) | Error message from API response |

When `patternStatus === 'ready'` but `recommendations.length === 0`, badge hides.

**Tooltip fallback:** `getPatternBadge()` only returns a reason for recommendations scoring >= 0.9. Under the new design the badge shows a count for any non-empty recommendation set. When `getPatternBadge()` is null but count > 0, use a generic fallback: `"N pattern recommendations"` (e.g. "3 pattern recommendations").

**`canRecommendPatterns` guard:** The badge does not read `window.flavorAgentData.canRecommendPatterns` directly. This is implicitly covered: `PatternRecommender` gates all fetches behind this flag, so when it is false, `patternStatus` stays `idle` and `recommendations` stays empty, which yields the `hidden` state. The `@wordpress/data` store is not persisted across page loads, so stale data cannot leak through.

### Store Changes

Follow the existing `setTemplateStatus(status, error)` pattern — a single action carries both status and optional error message.

**New state field:**
- `patternError: null` — stores the error message string from failed pattern fetches.

**Action changes:**
- `setPatternStatus( status, error = null )` — gains an optional `error` parameter (matching `setStatus` and `setTemplateStatus` signatures already in the store).
  ```js
  setPatternStatus( status, error = null ) {
      return { type: 'SET_PATTERN_STATUS', status, error };
  }
  ```

**Reducer changes to `SET_PATTERN_STATUS`:**
```js
case 'SET_PATTERN_STATUS':
    return {
        ...state,
        patternStatus: action.status,
        patternError: action.error ?? null,
    };
```

This ensures status and error are set atomically in a single dispatch, avoiding the ordering bug where separate dispatches could clear the error before setting the status.

**Reducer change to `SET_PATTERN_RECS` (existing):**
- Also clear `patternError` to `null` on successful recommendation receipt (the success path dispatches `SET_PATTERN_RECS` then `SET_PATTERN_STATUS('ready')` — the first clears the error, the second confirms the status).

**Thunk changes to `fetchPatternRecommendations`:**
- Success path: no change (already dispatches `SET_PATTERN_RECS` then `setPatternStatus('ready')`).
- Error path: keep the existing `dispatch( actions.setPatternRecommendations( [] ) )` call (clears stale results), then replace `dispatch( actions.setPatternStatus( 'error' ) )` with `dispatch( actions.setPatternStatus( 'error', err.message || 'Pattern recommendation request failed.' ) )`. The `SET_PATTERN_RECS([])` dispatch momentarily clears `patternError`, but the immediately following `SET_PATTERN_STATUS('error', msg)` atomically re-sets both status and error. This two-dispatch sequence is harmless — the intermediate state is never rendered because React batches synchronous dispatches.
- Add `finally` block to null `_patternAbort` when the controller matches (consistent with the template thunk pattern).

**New selector:**
- `getPatternError( state )` returns `state.patternError`.

**Existing selectors (unchanged):**
- `getPatternBadge( state )` — still returns the top reason string (used for ready-state tooltip when available).
- `isPatternLoading( state )` — still returns boolean.
- `getPatternRecommendations( state )` — still returns the array (badge reads `.length` for count).

### Component Changes — `InserterBadge.js`

**Current:** Reads `getPatternBadge()` (string or null). Renders "!" when non-null.

**New:** Reads multiple values and derives a status:

```js
const { status, count, badge, error } = useSelect( ( select ) => {
    const s = select( STORE_NAME );
    const recs = s.getPatternRecommendations();
    return {
        status: s.isPatternLoading() ? 'loading'
            : s.getPatternError() ? 'error'
            : recs.length > 0 ? 'ready'
            : 'hidden',
        count: recs.length,
        badge: s.getPatternBadge(),
        error: s.getPatternError(),
    };
}, [] );
```

**useEffect dependency change:** The existing `useEffect` that manages the DOM anchor (finding the inserter toggle, toggling the anchor class) currently depends on `[ badge ]`. This changes to `[ status ]` since status is now the gate for portal visibility (not the badge reason string).

**Rendering logic:**

```
hidden  -> return null (no portal, remove anchor class)
loading -> portal with pulsing dot, tooltip "Finding patterns..."
ready   -> portal with count text, tooltip = badge || `${count} pattern recommendation${count !== 1 ? 's' : ''}`
error   -> portal with "!", tooltip = error message
```

**Badge class:** The `<span>` gets a state-specific modifier class:
- `flavor-agent-inserter-badge flavor-agent-inserter-badge--loading`
- `flavor-agent-inserter-badge flavor-agent-inserter-badge--ready`
- `flavor-agent-inserter-badge flavor-agent-inserter-badge--error`

### CSS Changes — `editor.css`

**New rules:**

```css
/* Loading: pulsing dot */
.flavor-agent-inserter-badge--loading {
    background: var(--wp-components-color-accent, #3858e9);
    animation: flavor-agent-pulse 1.2s ease-in-out infinite;
}

@keyframes flavor-agent-pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.35); opacity: 0.7; }
}

/* Error: WP red */
.flavor-agent-inserter-badge--error {
    background: #d63638;
}

/* Ready: same accent, allow count digits */
.flavor-agent-inserter-badge--ready {
    min-width: 16px;
    padding: 0 4px;
    border-radius: 999px;
}
```

The base `.flavor-agent-inserter-badge` rule keeps position, font, and z-index. State classes are additive.

**Sizing:** Single-digit counts (1-9) fit in the current 16px circle. Double digits (10+) get `min-width: 16px` + horizontal padding via `--ready` so the badge pill-stretches. In practice `MAX_RECOMMENDATIONS` is 8, so this won't exceed single digits.

### File Change Summary

| File | Change |
|------|--------|
| `src/store/index.js` | Add `patternError` to `DEFAULT_STATE`. Extend `setPatternStatus` with optional `error` param. Update `SET_PATTERN_STATUS` reducer to set `patternError`. Clear `patternError` in `SET_PATTERN_RECS`. Pass error message in thunk catch. Add `finally` to null `_patternAbort`. Add `getPatternError` selector. |
| `src/patterns/InserterBadge.js` | Replace single `getPatternBadge` selector with multi-value select. Derive status. Change `useEffect` dep from `[badge]` to `[status]`. Render badge content, modifier class, and tooltip per state. Add generic tooltip fallback for ready state. |
| `src/editor.css` | Add `--loading`, `--error`, `--ready` modifier classes and `@keyframes flavor-agent-pulse`. |
| `src/patterns/__tests__/inserter-badge.test.js` | New test file: verify derived status logic (loading/ready/error/hidden) and tooltip fallback. |

### What Does NOT Change

- `PatternRecommender.js` — headless data fetcher, untouched.
- `recommendation-utils.js` — patching logic, untouched.
- `flavor-agent.php` — category registration, untouched.
- The inserter patching flow (metadata swap, keyword injection, "Recommended" tab) — untouched.
- `getPatternBadgeReason()` — still called inside the `SET_PATTERN_RECS` reducer to compute `patternBadge`. The `getPatternBadge()` selector is still used for the ready-state tooltip text (with the new generic fallback when it returns null).

## Testing

- **Unit (status derivation):** Test the derived status logic in isolation: loading when `isPatternLoading`, error when `getPatternError` is non-null, ready when count > 0, hidden otherwise. Test tooltip fallback: when `getPatternBadge()` is null and count is 3, tooltip should be "3 pattern recommendations".
- **Unit (reducer):** Test that dispatching the error-path sequence (`SET_PATTERN_STATUS('error', 'Some message')`) produces state where `patternError === 'Some message'` and `patternStatus === 'error'`. Test that `SET_PATTERN_RECS` clears `patternError`. Test the full success cycle: `SET_PATTERN_STATUS('loading')` -> `SET_PATTERN_RECS(recs)` -> `SET_PATTERN_STATUS('ready')` results in `patternError === null`.
- **Manual:** Verify badge states in the editor: open inserter to trigger fetch, observe loading pulse, see count appear, disconnect Qdrant to trigger error state, confirm tooltip text in each state.
- **Edge cases:** Double fetch (type in search while passive fetch is in-flight — AbortController cancels first), zero recommendations after fetch (badge should hide), error then successful retry (badge should clear error and show count).
