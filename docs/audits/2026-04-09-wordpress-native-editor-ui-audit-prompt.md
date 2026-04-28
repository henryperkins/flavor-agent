# WordPress-Native Editor UI Audit Checklist

Date: 2026-04-09

Purpose: Turn the 2026-04-09 WordPress-native editor UI audit into a concrete implementation and verification checklist that records what was resolved and keeps only open verification gaps.

Current status (as of 2026-04-27): Findings 1, 2, and 3 are implemented in code and no longer require behavior changes.

## Preserve These Contracts

- Keep the block `AI Recommendations` panel as the request-owning surface for block recommendations.
- Keep block Settings and Styles as projection-only consumers of the main block request. Do not add a second composer, fetch path, or refresh lifecycle there.
- Keep navigation advisory-only.
- Keep pattern recommendations ranking and browse-only.
- Keep template, template-part, Global Styles, and Style Book review-before-apply with validation and undo.
- Keep embedded navigation lighter than standalone or fallback navigation.
- Keep Global Styles and Style Book portal-first with document-panel fallback.
- Do not widen execution scope or introduce a parallel design system while addressing these findings.

## Remaining Gaps: Complete Remediation Plan (as of 2026-04-27)

The implementation gaps are now verification-only. No remaining source-level code changes are pending. Close all items below in this order before considering the audit complete.

### Phase 1: High-Risk Visual and Ownership Verification (owner: UI QA)
1. Perform live narrow-sidebar checks in block editor and Site Editor to verify `.flavor-agent-template-preview__actions` wraps and preserves button hierarchy at realistic constrained widths.
2. Validate stale guidance messaging for all affected surfaces:
   - Block `AI Recommendations` (owner refresh)
   - Settings / Styles / delegated chips (projection stale guidance only)
   - Navigation (advisory-only refresh behavior remains localized)
3. Confirm disabled/processing/stale visual states remain semantically clear (color + text + icon/aria), and disabled actions remain blocked.

### Phase 2: Cross-Surface Surface-Contract Verification (owner: product QA + engineering)
1. Confirm no drift in request ownership between request-owning surfaces and projections:
   - Block request remains canonical owner of block recommendation fetch.
   - Settings and Styles stay projection-only.
   - Navigation stays advisory-only.
2. Confirm review-before-apply surfaces remain review/undo-safe:
   - template, template-part, Global Styles, Style Book.
3. Confirm no token, lifecycle, or component drift across:
   - inspector panel
   - document panel
   - portal-mounted preview surfaces.

### Phase 3: Closeout Evidence (owner: maintainer)
1. Update this document by checking each item in this section as [done] with date + evidence notes.
2. If any manual visual assertion cannot be executed in the available environment, record blocker reason explicitly in this file and keep status open.
3. Re-run lightweight unit scope if any surface changed:
   - `npm run test:unit -- --runInBand src/inspector/__tests__/InspectorInjector.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js src/inspector/__tests__/SuggestionChips.test.js src/inspector/__tests__/NavigationRecommendations.test.js`
   - `npm run test:unit -- --runInBand src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/global-styles/__tests__/GlobalStylesRecommender.test.js src/style-book/__tests__/StyleBookRecommender.test.js`
   - `npm run check:docs`
4. Record final manual verification evidence and complete closeout.

## Finding 1: Projected Block Settings And Styles Miss Prompt Drift (Resolved)

Problem (historical):
Projected block Settings, Styles, and delegated suggestion chips only went stale when the live block context signature changed. They did not always go stale when the stored result prompt changed in the main `AI Recommendations` panel.

Current implementation boundary:

- `src/inspector/BlockRecommendationsPanel.js` computes prompt-aware request freshness for the request-owning block panel.
- `src/inspector/InspectorInjector.js` now drives stale projection with `getBlockRecommendationFreshness()` (prompt + context aware) and passes request signatures/inputs to chips.
- `src/inspector/SuggestionChips.js` uses `buildBlockRecommendationRequestData()` fallback/override request signing so delegated surfaces remain aligned.

### Checklist

- [x] `src/inspector/InspectorInjector.js` now uses request-signature freshness (prompt + context) for projections.
- [x] `src/inspector/BlockRecommendationsPanel.js` and `src/inspector/InspectorInjector.js` share request-signature contract for stale detection.
- [x] Existing block request helper `buildBlockRecommendationRequestData()` is used for projection context/signature.
- [x] Prompt-only drift in the main block panel marks projected Settings results stale.
- [x] Prompt-only drift in the main block panel marks projected Styles results stale.
- [x] Prompt-only drift in delegated `SuggestionChips` sub-panels marks those results stale.
- [x] Stale projected results remain visible for reference.
- [x] Stale projected apply actions remain disabled.
- [x] Stale projected refresh guidance remains delegated back to the main block `AI Recommendations` panel.
- [x] No local fetch or refresh actions were added to Settings, Styles, or delegated chips.

### Acceptance Criteria

- [x] Editing only the main block prompt, with no block-context change, marks projected Settings, Styles, and delegated chips stale.
- [x] Changing block context still marks projected Settings, Styles, and delegated chips stale.
- [x] Refreshing the main block panel clears the stale state for fresh matching results.
- [x] Fresh projected surfaces still apply safe local block changes exactly as before.

### Regression Coverage

- [x] `src/inspector/__tests__/InspectorInjector.test.js` includes prompt-only stale projection coverage.
- [x] `src/inspector/__tests__/BlockRecommendationsPanel.test.js` includes prompt-only stale and stale-reset coverage.
- [x] `src/inspector/__tests__/SuggestionChips.test.js` covers stale delegated chip behavior.

## Finding 2: Navigation Misses Prompt Drift (Resolved)

Problem (historical):
Navigation recommendations currently include the prompt in fetch input but drop it from the freshness signature. That means navigation results only go stale on context drift, not on prompt drift, even though navigation owns its own request and refresh lifecycle.

Current implementation boundary:

- `src/inspector/NavigationRecommendations.js::buildNavigationFetchInput()` includes the trimmed prompt in the request payload.
- `src/inspector/NavigationRecommendations.js::buildNavigationContextSignature()` reuses `buildNavigationFetchInput()` and now includes the trimmed prompt in signature hashing.

### Checklist

- [x] `src/inspector/NavigationRecommendations.js` now includes prompt in stale detection via shared fetch-input-based signature logic.
- [x] Trimmed prompt normalization is the same path used for request payload and freshness.
- [x] Navigation request ownership remains on the navigation surface itself.
- [x] Navigation stays advisory-only (no apply/undo behavior added in this fix).
- [x] Stale navigation results remain visible with refresh guidance from the navigation surface.
- [x] Embedded and standalone/fallback navigation paths share the same prompt-aware signature behavior where the component is shared.

### Acceptance Criteria

- [x] Editing only the navigation prompt, with no navigation markup or context change, marks the current navigation result stale.
- [x] Matching prompt plus matching navigation context remains fresh.
- [x] Whitespace-only prompt edits do not create false stale states if trimmed prompt is unchanged.
- [x] The refresh action continues to live on the navigation surface, not elsewhere.

### Regression Coverage

- [x] Unit coverage for prompt-only navigation drift, trimmed-prompt normalization, and advisory-only behavior exists in `src/inspector/__tests__/NavigationRecommendations.test.js`.

## Finding 3: Review Action Bars Need Explicit Spacing And Wrap Behavior (Resolved)

Problem (historical):
Review-first surfaces needed explicit cancel/confirm spacing and wrap behavior in narrow editor sidebars.

Current implementation boundary:

- `src/components/AIReviewSection.js`
- `src/editor.css`
- Review-first consumers:
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`

### Checklist

- [x] `.flavor-agent-template-preview__actions` now uses CSS `display: flex`, `flex-wrap: wrap`, and `gap` in `src/editor.css`.
- [x] Wrap/stack-safe behavior is present for narrow sidebar widths.
- [x] Review surfaces retain button hierarchy and order.
- [x] Keyboard and focus order remain the DOM order.
- [x] No additional markup change in `src/components/AIReviewSection.js` was needed.
- [x] The behavior covers template, template-part, Global Styles, and Style Book surfaces.
- [x] The behavior applies in both portal-mounted and fallback document-panel contexts.

### Acceptance Criteria

- [x] Review action buttons do not visually collapse together at realistic sidebar widths.
- [x] Wrapped buttons remain readable, distinct, and aligned with the intended primary-secondary hierarchy.
- [x] No review-first surface loses a working confirm or cancel action after layout updates.
- [x] The change does not alter the review-before-apply contract on any surface.

### Verification Gap To Close

- [ ] Perform live browser verification for review action bars at narrow editor widths, because the original audit did not have browser confirmation for this issue.

## Cross-Surface Verification

- [ ] Confirm stale copy still points users to the correct refresh-owning surface.
- [ ] Confirm projected block surfaces still behave as projections, not as request owners.
- [ ] Confirm navigation still reads as advisory-only after freshness changes.
- [ ] Confirm template, template-part, Global Styles, and Style Book still read as review-before-apply after action-bar changes.
- [ ] Confirm no token or component changes introduce drift between inspector panels, document panels, and portal-mounted style surfaces.
- [ ] Confirm disabled states, stale pills, and refresh affordances remain visually and semantically consistent across the affected surfaces.

## Suggested Verification Commands

- [ ] Run:
`npm run test:unit -- --runInBand src/inspector/__tests__/InspectorInjector.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js src/inspector/__tests__/SuggestionChips.test.js src/inspector/__tests__/NavigationRecommendations.test.js`
- [ ] If `AIReviewSection` or shared review styling changes, also run:
`npm run test:unit -- --runInBand src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/global-styles/__tests__/GlobalStylesRecommender.test.js src/style-book/__tests__/StyleBookRecommender.test.js`
- [ ] Run:
`npm run check:docs`
- [ ] Do a manual narrow-sidebar pass in the block editor and Site Editor because the review-action spacing issue is visual and layout-specific.
