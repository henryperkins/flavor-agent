# Block Panel Shell Alignment Plan

> Scope: bring the main block recommendation panel into the same shell hierarchy as the richer Site Editor recommendation surfaces.

## Goal

Make the block inspector panel feel like part of the same recommendation family as Template, Template-Part, Style Book, and Global Styles without removing the block-specific one-click apply model.

## Recommended Decision

Promote the main block panel to the full panel skeleton by adding `SurfacePanelIntro` and keeping this order:

1. `SurfacePanelIntro`
2. `SurfaceScopeBar`
3. `SurfaceComposer`
4. `AIStatusNotice`
5. `RecommendationHero`
6. `Apply now`
7. `Manual ideas`
8. delegated settings, styles, and navigation sections
9. `AIActivitySection`

Keep the styles subpanel lightweight. It does not need to become a full top-level recommendation panel in this slice.
Within the main block panel, this specifically means moving the embedded `NavigationRecommendations` section below the block lanes so delegated guidance stays subordinate to the featured block result and the `Apply now` / `Manual ideas` grouping.

## Why This Choice

1. The block panel is the most visible recommendation surface and currently lacks the same top-of-panel framing as the Site Editor surfaces.
2. Adding the intro shell is low-risk compared with changing apply behavior.
3. It improves recognition without forcing the delegated style/settings subpanels into a heavier model.

## Files In Scope

- `src/inspector/BlockRecommendationsPanel.js`
- `src/components/SurfacePanelIntro.js`
- `src/editor.css`
- `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
- `docs/reference/recommendation-ui-consistency.md`
- `docs/features/block-recommendations.md`

## Implementation Plan

1. Add `SurfacePanelIntro` to the main block panel and keep it parent-owned above the scope bar.
2. Split the current top copy contract explicitly:
   - `introCopy` becomes the `SurfacePanelIntro` body copy.
   - `SurfaceComposer` keeps a shorter helper that explains the one-click apply contract instead of repeating the full intro paragraph.
   - the document fallback panel keeps its `Last Selected Block` intro copy so remembered-selection behavior still reads clearly after selection clears.
3. Preserve the current one-click apply and stale handling semantics. This slice is shell alignment only, not a behavior-model change.
4. Keep delegated sections subordinate without wrapping them in duplicate top-level shells:
   - embedded navigation stays inside the main block panel
   - embedded navigation moves below the `Apply now` and `Manual ideas` lanes
   - delegated settings and styles remain in their existing secondary inspector surfaces
5. Re-check ordering for the full fresh-result stack so the panel reads: intro, scope, composer, status, hero, block lanes, embedded navigation, activity/history.

## Non-Goals

1. Do not convert block apply into review-before-apply.
2. Do not redesign delegated style/settings panels in this slice.
3. Do not merge navigation request state into block request state.

## Verification

1. Update the block panel unit tests for the new intro shell, the shortened composer helper, and the document fallback intro copy.
2. Stop mocking embedded navigation to `null` in every relevant assertion path. Add at least one rendered ordering check that proves the navigation section appears below the block lanes when present.
3. Re-check stale and success messaging order in the rendered output.
4. Manually verify that the panel still reads cleanly when:
   - there are no results
   - there are fresh results
   - results are stale
   - the nested navigation section is present
