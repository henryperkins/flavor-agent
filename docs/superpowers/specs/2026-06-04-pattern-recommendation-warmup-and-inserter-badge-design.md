# Pattern Recommendation Warm-Up & Inserter Badge — Design

- **Date:** 2026-06-04
- **Status:** Approved design; ready for implementation planning after review.
- **Scope:** Pattern recommender only. Client trigger behavior, request logging semantics for no-op pattern probes, and inserter badge DOM anchoring. No changes to ranking prompts, pattern retrieval backends, or apply semantics.

## Problem

Pattern recommendations currently run a passive request when the editor loads. That request is keyed to whatever `getBlockInsertionPoint()` reports at the time, even though a document can have many valid insertion points: each block-list root has `child count + 1` positions, and nested containers multiply that count. The initial insertion point can therefore be stale before the user opens the inserter.

The passive request also creates misleading Activity rows when Gutenberg has not exposed a useful visible pattern scope yet. `PatternAbilities::recommend_patterns()` correctly short-circuits when `visiblePatternNames` is missing or empty, but `RecommendationAbilityExecution` still records a local `request_diagnostic` with a request token and no linked core AI request log. The admin detail then says the AI request log is unavailable even though no model request was attempted.

The first real ranking is not fast enough to hide behind a just-in-time request. Recent linked pattern ranking rows on the local stack show about 10.7s fastest, 14.9s average, and 19.5s slowest. Moving real ranking to inserter-open therefore needs an explicit loading state and a cache/warm-up strategy.

Separately, the inserter badge is intended to appear on the `+` inserter button, but current DOM discovery can anchor it to the neighboring list-view/document-outline control. The helper first accepts any `button.block-editor-inserter__toggle`, then falls back to broad aria-label matching, and `InserterBadge` portals into the matched button's parent. That is too loose for current Gutenberg toolbar markup.

## Goals

1. Treat the first real ranking as insertion-point-specific, not editor-load-specific.
2. Keep useful early work, but make it non-ranking and non-intent-assuming.
3. Avoid local Activity rows that imply a missing core AI request log when no model request was made.
4. Show the badge only on the actual block inserter `+` control.
5. Preserve existing freshness/apply safeguards, including insertion-target signature checks before insertion.

## Non-Goals

- No ranking prompt rewrite.
- No backend swap or model tuning.
- No attempt to pre-rank every possible insertion point.
- No change to direct/external ability behavior: the server still gracefully returns empty recommendations when called with missing or empty `visiblePatternNames`.
- No change to pattern insertion rollback or resolved-signature apply validation.

## Decisions

### 1. Split warm-up from ranking

Editor load may run a lightweight pattern recommendation warm-up, but warm-up must not call the model ranker. It may prepare or inspect shared prerequisites:

- feature/capability readiness;
- selected backend and pattern-index state;
- connector approval/readiness state;
- cached docs-grounding state if already available;
- current Gutenberg pattern availability hydration.

Warm-up must not persist a `request_diagnostic` row that looks like a model-backed recommendation request. If a diagnostic is needed later, it should use an explicit warm-up/no-op reason rather than carrying a request token without a core request log. Real inserter-intent requests may still end before the model, such as when retrieval finds no rankable candidates; those diagnostics should remain visible, but their admin copy must say no model request was attempted instead of implying that a linked core request log is missing.

### 2. First real ranking happens on inserter intent

The first model-backed `recommend-patterns` request should run only when the user opens or focuses the block inserter and the current insertion point has a real pattern scope:

- `isInserterOpen === true`;
- `canRecommend === true`;
- `effectivePostType` exists;
- current `visiblePatternNames.length > 0`;
- current insertion target has a stable signature built from post type, template type, root client ID, insertion index, and insertion context.

Search text inside the inserter still refines the same intent path. The request input should be built from the current insertion point at the moment of inserter use, not from an editor-load snapshot.

### 3. Cache by real insertion target

Completed rankings should be cached by a short-lived key that includes:

- `postType`;
- `templateType`;
- `rootClientId`;
- `insertionIndex`;
- normalized insertion context signature;
- `visiblePatternNames` signature;
- prompt/search text;
- selected pattern backend identity/configuration signature where already available.

If the user reopens the inserter at the same target and scope, the cached result can render immediately. If the insertion point changes, Flavor Agent should show loading and request a new ranking rather than reusing stale recommendations.

The existing apply-time resolved-signature check remains the final guard. Cache hits are a display optimization, not permission to skip freshness validation.

### 4. Show an honest loading state

Because measured first-rank latency is commonly 13-17s and can approach 20s, the inserter UI should show a clear pending state when ranking is in progress for the current target. The badge may indicate loading only when it is anchored to the correct `+` control and the current target is rankable.

No-results copy should remain distinct:

- no-patterns insertion point: the current spot does not accept patterns;
- empty ranking: patterns were available, but no strong match was found;
- loading: real ranking is in progress for the current inserter target.

### 5. Tighten inserter badge targeting

`findInserterToggle()` should positively identify the block inserter `+` control and avoid the document-outline/list-view button. The selector strategy should:

- prefer current Gutenberg's specific inserter toggle class only when the aria-label also identifies block inserter behavior;
- accept label patterns like `Block Inserter` or `Toggle block inserter`;
- reject labels/classes associated with list view, outline, document overview, hierarchy, or structure navigation;
- avoid a generic `/inserter/i` fallback if it can match neighboring toolbar controls;
- anchor the badge to the smallest stable wrapper for the actual button, not a parent that spans multiple toolbar controls.

## Data Flow

1. Editor loads.
2. Pattern recommender initializes capability/backend/readiness state.
3. Optional warm-up runs without model ranking and without model-request activity-log linkage.
4. User opens the block inserter.
5. Client reads the live `getBlockInsertionPoint()`, derives `visiblePatternNames`, builds insertion context, and computes the insertion-target signature.
6. If scope is empty because the target accepts no patterns, render the no-patterns notice and do not request ranking.
7. If scope is non-empty, check the target cache.
8. Cache hit: render recommendations for that exact target and scope.
9. Cache miss: render loading, call `recommend-patterns`, persist normal diagnostics for the real inserter-intent request, and cache the result under the target key. If the pipeline ends before a model call, the diagnostic should carry an explicit no-model reason.
10. Before insertion, run the existing resolved-signature check and rollback guard.

## File-Level Change Plan

- `src/patterns/PatternRecommender.js`
  - Gate passive editor-load ranking behind inserter intent.
  - Keep or introduce warm-up logic that does not invoke model ranking.
  - Build real ranking requests from the live inserter target.
  - Add target-scoped cache lookup/storage.
  - Keep the existing no-patterns insertion-point guard and empty-ranking copy distinct.

- `src/store/index.js` and related pattern actions if needed
  - Add cache metadata or action state if the cache belongs in the store rather than component-local state.
  - Preserve existing request signature and diagnostics shapes for real rankings.

- `src/patterns/inserter-dom.js`
  - Replace broad toggle discovery with positive block-inserter matching and list-view/document-outline exclusions.

- `src/patterns/InserterBadge.js`
  - Use the corrected toggle helper and anchor to the correct stable wrapper.
  - Continue hiding cleanly when the actual inserter button is unavailable.

- `src/admin/activity-log.js` / activity normalization only if needed
  - If no-op warm-up diagnostics remain visible, distinguish "no model request was attempted" from "linked core request log unavailable."
  - Apply the same distinction to real inserter-intent requests that terminate before a model call for an explicit pipeline reason.
  - Prefer suppressing no-op warm-up rows over adding another ambiguous message.

- `docs/features/pattern-recommendations.md` and related docs if behavior changes
  - Update passive-fetch language to describe warm-up versus real ranking.
  - Document that real ranking is tied to inserter intent and insertion-target scope.

## Testing

- `src/patterns/__tests__/PatternRecommender.test.js`
  - Editor load performs warm-up/readiness work but does not dispatch real ranking before inserter intent.
  - Opening the inserter with non-empty `visiblePatternNames` dispatches the first real ranking for the current target.
  - Changing `rootClientId` or insertion index invalidates the current displayed result/cache key.
  - Reopening the inserter at the same target can reuse a cached result.
  - Empty current scope plus non-empty top-level scope renders the no-patterns notice and does not rank.

- `src/patterns/__tests__/inserter-dom.test.js`
  - Finds the actual block inserter `+` button.
  - Rejects list-view/document-outline/hierarchy buttons even when they sit in the same toolbar.
  - Returns null rather than guessing when only ambiguous toolbar controls exist.

- `src/patterns/__tests__/InserterBadge.test.js`
  - Badge anchors to the actual inserter button wrapper.
  - Badge does not attach to the list-view/document-outline button.
  - Badge remains click-through and cleans up anchor classes on remount.

- Activity/admin tests, if activity messaging changes
  - No-model/no-op pattern warm-up does not render the misleading linked-log-unavailable notice.
  - Real ranked requests with a linked core log still render the linked request-log panel.

## Verification

Minimum gates:

- targeted JS suites for `PatternRecommender`, `inserter-dom`, and `InserterBadge`;
- `node scripts/verify.js --skip-e2e`;
- `npm run check:docs` if docs are updated.

Browser evidence should be captured for the toolbar badge placement because this depends on live Gutenberg markup. The Playwright check should verify the badge is visually attached to the `+` inserter control, not the list-view/document-outline control, in both post editor and Site Editor if both surfaces load the badge.

## Acceptance Criteria

- Opening the editor alone does not start a model-backed pattern ranking.
- Opening the block inserter at a rankable insertion point starts the first real ranking for that exact target.
- The UI displays an honest loading state during the first real ranking.
- Moving to a different insertion point does not show stale recommendations from the previous target.
- Returning to the same insertion point can show cached recommendations without skipping apply-time freshness validation.
- No local Activity row implies a missing linked core AI request log when no model request was attempted.
- The badge displays on the `+` inserter button, not the list-view/document-outline button.
