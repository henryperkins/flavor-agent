# Pattern Recommendation Badge - Unified Status Surface

**Date:** 2026-03-17
**Status:** Implemented reference spec
**Scope:** Enrich the `InserterBadge` component to serve as the unified status indicator for the pattern recommendation pipeline (loading, ready, error).

> Historical note: this design is already implemented in the current tree.
> Use `docs/features/pattern-recommendations.md`, `docs/FEATURE_SURFACE_MATRIX.md`, and `STATUS.md` for current shipped behavior.

## Problem

The pattern recommendation system has a working data pipeline (vector search, LLM ranking, inserter patching) but almost no visible UI. The store tracks `patternStatus` through `idle -> loading -> ready/error`, but nothing renders those states. The only visual element is a static "!" badge on the inserter toggle that appears when a single recommendation scores >= 0.9.

The block recommendation side has cards, confidence bars, loading spinners, error notices, and apply buttons. The pattern side needs parity, surfaced through the badge since recommendations live in the native inserter.

## Design

### Source of Truth

The store exposes `getPatternStatus( state )`, and `InserterBadge` switches on that selector directly. `patternError`, `patternBadge`, and `recommendations.length` are secondary inputs that fill in copy and styling after the primary state machine is known; they do not replace `patternStatus` as the contract boundary.

### Badge States

| State | Trigger | Badge content | Style | Tooltip | `aria-label` |
|-------|---------|--------------|-------|---------|--------------|
| **Hidden** | `patternStatus === 'idle'` with no recommendations, or `patternStatus === 'ready'` with `recommendations.length === 0` | Nothing rendered | -- | -- | -- |
| **Loading** | `patternStatus === 'loading'` | Empty badge | Accent circle with CSS `pulse` animation | "Finding patterns..." | "Finding pattern recommendations" |
| **Ready** | `patternStatus === 'ready'` and `recommendations.length > 0` | Count (for example `"3"`) | Accent pill sized for digits | Top reason or generic fallback | Count-derived copy such as "3 pattern recommendations available" |
| **Error** | `patternStatus === 'error'` | `"!"` | WP error red (`#d63638`) | Store error message or safe fallback | "Pattern recommendation error" |

When `patternStatus === 'ready'` but `recommendations.length === 0`, the badge hides.

**Tooltip fallback:** `getPatternBadge()` still returns the first high-confidence reason (score >= 0.9). The badge now shows a count for any non-empty recommendation set, so when `getPatternBadge()` is null but count > 0 the tooltip falls back to `"N pattern recommendation"` / `"N pattern recommendations"`.

### Accessibility Requirements

- The badge remains non-interactive.
- Decorative text content is not the only accessible cue; every visible state provides an explicit `aria-label`.
- Loading uses `"Finding pattern recommendations"`.
- Ready uses count-derived copy such as `"3 pattern recommendations available"`.
- Error uses `"Pattern recommendation error"`.

### `canRecommendPatterns` Guard

The badge does not read `window.flavorAgentData.canRecommendPatterns` directly. `PatternRecommender` gates fetches behind that flag, so when it is false `patternStatus` stays `idle` and `recommendations` stays empty, which yields the hidden state. The `@wordpress/data` store is not persisted across page loads, so stale data cannot leak through.

### Store Changes

Follow the existing `setTemplateStatus( status, error )` pattern so pattern status and optional error travel together.

**New state field:**
- `patternError: null` stores the error message string from failed pattern fetches.

**Action changes:**
- `setPatternStatus( status, error = null )`

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

**Reducer changes to `SET_PATTERN_RECS`:**
- Store recommendations.
- Recompute `patternBadge` through `getPatternBadgeReason()`.
- Clear `patternError` to `null`.

This makes successful recommendation receipt the authoritative success path that clears stale errors before `ready` is confirmed.

**Thunk changes to `fetchPatternRecommendations()`:**
- Abort any previous controller before starting a new request.
- Dispatch `setPatternStatus( 'loading' )` at request start.
- On success, dispatch `SET_PATTERN_RECS` and then `setPatternStatus( 'ready' )`.
- On failure, dispatch `setPatternRecommendations( [] )` followed by `setPatternStatus( 'error', err.message || 'Pattern recommendation request failed.' )`.
- Use a `finally` block to clear `_patternAbort` only when the current controller still matches.

The error-path contract is the final rendered state, not React batching internals. Brief intermediate store transitions are acceptable; the badge contract is defined by the final `patternStatus` and secondary fields after the thunk completes its synchronous dispatch sequence.

**Selectors:**
- `getPatternStatus( state )`
- `getPatternError( state )`
- `getPatternBadge( state )`
- `getPatternRecommendations( state )`
- `isPatternLoading( state )` remains a convenience selector for any existing callers.

**Exports for tests:**
- Export named `actions`, `selectors`, and `reducer` from `src/store/index.js`.
- Keep the default registered store export unchanged.

### Pure Badge-State Helper

Create `src/patterns/inserter-badge-state.js` with a pure helper:

```js
getInserterBadgeState( {
	status,
	recommendations,
	badge,
	error,
} )
```

The helper returns a compact view model for the component:
- `status`
- `count`
- `content`
- `tooltip`
- `ariaLabel`
- `className`

The helper uses this contract:
- `loading` when `status === 'loading'`
- `error` when `status === 'error'`
- `ready` when `status === 'ready'` and `recommendations.length > 0`
- `hidden` otherwise

The helper is side-effect free. It does not query the DOM, call store selectors, or read globals.

### Component Changes - `InserterBadge.js`

`InserterBadge` reads explicit store selectors, passes them into `getInserterBadgeState()`, and renders from the returned view model.

```js
const badgeState = useSelect( ( select ) => {
	const store = select( STORE_NAME );

	return getInserterBadgeState( {
		status: store.getPatternStatus(),
		recommendations: store.getPatternRecommendations(),
		badge: store.getPatternBadge(),
		error: store.getPatternError(),
	} );
}, [] );
```

**`useEffect` dependency change:** The DOM-anchor effect now depends on `badgeState.status`, because portal visibility is driven by the explicit status contract rather than the presence of a high-confidence reason string.

**Rendering logic:**
- `hidden` -> return `null`
- `loading` -> render the badge with no visible text and loading tooltip/copy
- `ready` -> render the recommendation count
- `error` -> render `"!"`

The badge stays non-interactive and continues to render through the existing portal and `Tooltip` wrapper.

### CSS Changes - `editor.css`

The base `.flavor-agent-inserter-badge` rule becomes flexible enough for both empty/loading circles and count pills:

```css
.flavor-agent-inserter-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 16px;
	height: 16px;
	padding: 0;
	box-sizing: border-box;
	border-radius: 999px;
}
```

Add state modifiers:

```css
.flavor-agent-inserter-badge--loading {
	animation: flavor-agent-pulse 1.2s ease-in-out infinite;
}

.flavor-agent-inserter-badge--ready {
	padding: 0 4px;
}

.flavor-agent-inserter-badge--error {
	background: #d63638;
}
```

Keep the base positioning and z-index rules intact. The loading state remains visually circular because the extra horizontal padding belongs only to the ready modifier.

### File Change Summary

| File | Change |
|------|--------|
| `docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md` | Lock the explicit `getPatternStatus` contract, state-specific accessibility copy, error-path contract, helper-first test strategy, and corrected manual verification wording |
| `src/store/index.js` | Add `patternError`, explicit pattern selectors, `setPatternStatus( status, error )`, `SET_PATTERN_RECS` error clearing, `_patternAbort` cleanup, and named store primitive exports |
| `src/patterns/inserter-badge-state.js` | Pure badge-state and copy derivation helper |
| `src/patterns/InserterBadge.js` | Consume the explicit store contract and render from the helper view model |
| `src/editor.css` | Flexible base badge layout plus `--loading`, `--ready`, and `--error` modifiers |
| `src/patterns/__tests__/inserter-badge-state.test.js` | Helper-first coverage for state derivation, tooltip fallback, classes, and accessibility copy |
| `src/store/__tests__/pattern-status.test.js` | Reducer coverage for pattern status/error transitions and badge recalculation |

### What Does Not Change

- `PatternRecommender.js` remains the headless fetcher.
- `recommendation-utils.js` remains the patching utility and still computes high-confidence badge reasons.
- The inserter patching flow (metadata swap, keyword injection, "Recommended" tab) does not change.
- `flavor-agent.php` and category registration do not change.

## Testing

- **Helper-first unit coverage:** `src/patterns/__tests__/inserter-badge-state.test.js` verifies hidden/loading/ready/error derivation, tooltip fallback, modifier classes, and the exact `aria-label` strings.
- **Reducer-level coverage:** `src/store/__tests__/pattern-status.test.js` verifies `patternStatus` and `patternError` together, stale error clearing on success, and badge recomputation for empty and non-empty recommendation sets.
- **Component smoke coverage:** Only add a light component smoke test if helper and reducer coverage leave a gap. The implementation should not start with a brittle jsdom portal harness.
- **Focused regression check:** Keep `src/patterns/__tests__/recommendation-utils.test.js` in the targeted run so the badge-reason helper remains aligned with the new UI contract.
- **Manual verification:** The passive request starts on editor load when `canRecommendPatterns` and `postType` are present. Opening the inserter and typing in search trigger additional active requests, not the first request. Search-input detection is limited to live inserter containers; global fallback to arbitrary page searchboxes is not allowed. Manual checks should confirm loading, ready-with-reason, ready-with-fallback, zero-result hidden, error-recovery behavior, and that unrelated search fields do not trigger pattern requests.
