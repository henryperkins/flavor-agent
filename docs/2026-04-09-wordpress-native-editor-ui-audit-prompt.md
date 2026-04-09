# WordPress-Native Editor UI Audit Checklist

Date: 2026-04-09

Purpose: Turn the 2026-04-09 WordPress-native editor UI audit into a concrete implementation and verification checklist that resolves the confirmed issues without flattening intentional surface differences.

## Preserve These Contracts

- [ ] Keep the block `AI Recommendations` panel as the request-owning surface for block recommendations.
- [ ] Keep block Settings and Styles as projection-only consumers of the main block request. Do not add a second composer, fetch path, or refresh lifecycle there.
- [ ] Keep navigation advisory-only.
- [ ] Keep pattern recommendations ranking and browse-only.
- [ ] Keep template, template-part, Global Styles, and Style Book review-before-apply with validation and undo.
- [ ] Keep embedded navigation lighter than standalone or fallback navigation.
- [ ] Keep Global Styles and Style Book portal-first with document-panel fallback.
- [ ] Do not widen execution scope or introduce a parallel design system while addressing these findings.

## Finding 1: Projected Block Settings And Styles Miss Prompt Drift

Problem:
Projected block Settings, Styles, and delegated suggestion chips only go stale when the live block context signature changes. They do not go stale when the stored result prompt no longer matches the live prompt in the main `AI Recommendations` panel.

Current implementation boundary:

- `src/inspector/BlockRecommendationsPanel.js` already computes prompt-aware request freshness for the request-owning block panel.
- `src/inspector/InspectorInjector.js` currently derives projected stale state from `storedContextSignature !== liveContextSignature`.
- `src/inspector/SettingsRecommendations.js`
- `src/inspector/StylesRecommendations.js`
- `src/inspector/SuggestionChips.js`

### Checklist

- [ ] In `src/inspector/InspectorInjector.js`, stop treating block projections as fresh solely because the stored and live context signatures match.
- [ ] Derive projected stale state from the same request-signature freshness contract the main block panel already uses in `src/inspector/BlockRecommendationsPanel.js`.
- [ ] Reuse existing block request helpers such as `buildBlockRecommendationRequestData()` instead of duplicating prompt-plus-context signature logic.
- [ ] Ensure prompt-only drift in the main block panel marks projected Settings results stale.
- [ ] Ensure prompt-only drift in the main block panel marks projected Styles results stale.
- [ ] Ensure prompt-only drift in delegated `SuggestionChips` sub-panels marks those results stale.
- [ ] Keep stale projected results visible for reference.
- [ ] Keep stale projected apply actions disabled.
- [ ] Keep stale projected refresh guidance delegated back to the main block `AI Recommendations` panel.
- [ ] Do not add local fetch or refresh actions to Settings, Styles, or delegated chips.

### Acceptance Criteria

- [ ] Editing only the main block prompt, with no block-context change, marks projected Settings, Styles, and delegated chips stale.
- [ ] Changing block context still marks projected Settings, Styles, and delegated chips stale.
- [ ] Refreshing the main block panel clears the stale state for fresh matching results.
- [ ] Fresh projected surfaces still apply safe local block changes exactly as they do today.

### Regression Coverage

- [ ] Add or update unit coverage in `src/inspector/__tests__/InspectorInjector.test.js` for prompt-only stale projection behavior.
- [ ] Add or update unit coverage in `src/inspector/__tests__/SettingsRecommendations.test.js` for stale projected Settings behavior.
- [ ] Add or update unit coverage in `src/inspector/__tests__/StylesRecommendations.test.js` for stale projected Styles behavior.
- [ ] Add or update unit coverage in `src/inspector/__tests__/SuggestionChips.test.js` for stale delegated chip behavior.

## Finding 2: Navigation Misses Prompt Drift

Problem:
Navigation recommendations currently include the prompt in fetch input but drop it from the freshness signature. That means navigation results only go stale on context drift, not on prompt drift, even though navigation owns its own request and refresh lifecycle.

Current implementation boundary:

- `src/inspector/NavigationRecommendations.js::buildNavigationFetchInput()` includes the trimmed prompt in the request payload.
- `src/inspector/NavigationRecommendations.js::buildNavigationContextSignature()` currently builds freshness with `prompt: ''`.

### Checklist

- [ ] Update navigation freshness logic in `src/inspector/NavigationRecommendations.js` so the prompt participates in stale detection.
- [ ] Use the same trimmed prompt normalization for freshness that the fetch path already uses.
- [ ] Keep navigation request ownership in the navigation surface itself.
- [ ] Keep navigation advisory-only. Do not add apply or undo behavior while fixing freshness.
- [ ] Keep stale navigation results visible with refresh guidance from the same navigation surface that owns the request.
- [ ] Ensure embedded and standalone or fallback navigation use the same prompt-aware freshness behavior if they share this component.

### Acceptance Criteria

- [ ] Editing only the navigation prompt, with no navigation markup or context change, marks the current navigation result stale.
- [ ] Matching prompt plus matching navigation context remains fresh.
- [ ] Whitespace-only prompt edits do not create false stale states if the trimmed prompt is unchanged.
- [ ] The refresh action continues to live on the navigation surface, not elsewhere.

### Regression Coverage

- [ ] Add or update unit coverage in `src/inspector/__tests__/NavigationRecommendations.test.js` for prompt-only navigation drift.
- [ ] Add or update unit coverage in `src/inspector/__tests__/NavigationRecommendations.test.js` for trimmed-prompt normalization.
- [ ] Add or update unit coverage in `src/inspector/__tests__/NavigationRecommendations.test.js` to confirm navigation remains advisory-only after the freshness fix.

## Finding 3: Review Action Bars Need Explicit Spacing And Wrap Behavior

Problem:
Review-first surfaces render cancel and confirm actions inside a shared action bar, but the current action container only right-aligns the buttons. In narrow editor sidebars, that leaves spacing and hierarchy too implicit.

Current implementation boundary:

- `src/components/AIReviewSection.js`
- `src/editor.css`
- Review-first consumers:
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`

### Checklist

- [ ] Update `.flavor-agent-template-preview__actions` in `src/editor.css` to add explicit spacing between the cancel and confirm buttons.
- [ ] Add wrap or stack-safe behavior for narrow sidebar widths.
- [ ] Keep the primary confirm action visually dominant.
- [ ] Keep the cancel action clearly secondary but still easy to find.
- [ ] Preserve keyboard order and focus order when the buttons wrap.
- [ ] If CSS alone is not sufficient, make the smallest necessary markup or class adjustment in `src/components/AIReviewSection.js`.
- [ ] Verify the action-bar update across template, template-part, Global Styles, and Style Book surfaces.
- [ ] Verify the action-bar update in both portal-mounted and fallback document-panel contexts.

### Acceptance Criteria

- [ ] Review action buttons do not visually collapse together at realistic sidebar widths.
- [ ] Wrapped buttons remain readable, distinct, and aligned with the intended primary-secondary hierarchy.
- [ ] No review-first surface loses a working confirm or cancel action after the layout change.
- [ ] The change does not alter the review-before-apply contract on any surface.

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
`npm run test:unit -- --runInBand src/inspector/__tests__/InspectorInjector.test.js src/inspector/__tests__/SettingsRecommendations.test.js src/inspector/__tests__/StylesRecommendations.test.js src/inspector/__tests__/SuggestionChips.test.js src/inspector/__tests__/NavigationRecommendations.test.js`
- [ ] If `AIReviewSection` or shared review styling changes, also run:
`npm run test:unit -- --runInBand src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/global-styles/__tests__/GlobalStylesRecommender.test.js src/style-book/__tests__/StyleBookRecommender.test.js`
- [ ] Run:
`npm run check:docs`
- [ ] Do a manual narrow-sidebar pass in the block editor and Site Editor because the review-action spacing issue is visual and layout-specific.
