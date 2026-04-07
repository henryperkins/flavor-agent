# Review Placement Alignment Plan

> Scope: unify where preview-before-apply review renders for executable structural and style surfaces.

## Goal

Make `Review first` feel like one learned step across:

- template
- template-part
- style book
- global styles

The user should browse suggestions in one place and review the selected suggestion in one predictable place.

## Recommended Decision

Standardize on a dedicated review panel below the recommendation lanes.

Keep suggestion cards responsible for selection, not for rendering the full review shell. The active review panel should render once per surface, below the executable and advisory lanes, using the shared `AIReviewSection`.

## Why This Choice

1. Style Book and Global Styles already show the cleaner pattern.
2. It separates browsing from decision-making.
3. It prevents selected cards from expanding unevenly and shifting surrounding content.
4. It makes the preview step feel more like a stable second stage of the workflow.

## Files In Scope

- `src/components/AIReviewSection.js`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/editor.css`
- surface-specific tests under `src/**/__tests__/`
- `docs/reference/recommendation-ui-consistency.md`
- affected surface docs in `docs/features/`

## Implementation Plan

### Phase 1: Lock the shared review contract

1. Define one parent-owned selected-suggestion model for preview-first surfaces.
2. Resolve the active review suggestion in the parent from `selectedSuggestionKey` plus the current executable suggestion list or view model.
3. Keep card buttons limited to:
   - open review
   - switch review target
4. Keep cancel review inside the shared lower `AIReviewSection`, not inside the cards.
5. Keep `AIReviewSection` as the only place that renders:
   - operation list
   - confirm apply
   - cancel review
   - active review status copy
6. Make same-surface loading preserve the selected review target while the previous result stays visible, so the review step remains stable during refresh.

### Phase 2: Migrate Template and Template-Part

1. Remove inline review rendering from selected cards.
2. Lift selected-suggestion lookup and operation-row rendering into the parent surface component.
3. Add one lower review section per surface, below executable and advisory lanes.
4. Preserve current apply, stale gating, and undo behavior.
5. Keep advisory cards non-reviewable.

### Phase 3: Normalize copy and active-state styling

1. Keep the selected card visibly active while review is open.
2. Use the same selected-state cues on all preview-first surfaces:
   - `Review open` badge on the active card
   - a review button label that reflects the active review target
3. Keep `Review first`, `Applied`, and related state labels consistent with the current normalized vocabulary.

### Phase 4: Review lifecycle and refresh behavior

1. Preserve selected review state through same-surface loading so a stale refresh does not collapse the lower panel immediately.
2. Continue clearing selected review state on a successful new result set or on hard scope clears.
3. Keep review apply wiring parent-owned so stale-safe apply still receives the live context signature where needed.

## Explicit Non-Goals

1. Do not change block inspector one-click apply.
2. Do not widen backend execution contracts.
3. Do not redesign activity history as part of this slice.

## Open Questions

1. Should opening review auto-scroll on long surfaces once the lower panel ships everywhere, or is persistent active-card styling enough?

## Verification

1. Add targeted rendering tests for Template and Template-Part showing:
   - lanes visible
   - one selected suggestion
   - one shared review panel below the lanes
   - no inline `AIReviewSection` nested inside cards
2. Add reducer coverage for preview-first surfaces preserving selected review state through loading while still clearing transient apply feedback.
3. Strengthen Style Book and Global Styles tests so they verify the shared review panel contract instead of mocking it away completely.
4. Manually verify keyboard and click flows for selecting, switching, canceling, and applying review targets.
5. Manually verify stale refresh while a review target is open.
