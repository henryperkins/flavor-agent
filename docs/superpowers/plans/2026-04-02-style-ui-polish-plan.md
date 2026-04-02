## Style UI Polish Plan

Polish the style experience across the block inspector and Site Editor style surfaces without changing the underlying safety model: keep one-click apply for block-level style suggestions, keep review-before-apply for Global Styles and Style Book, and focus the work on clearer hierarchy, more native Gutenberg/WPDS-feeling presentation, and feedback/undo that sits closer to where the user clicks.

### Steps

1. **Phase 1 — Lock the UI contract before touching presentation.** Use the current store/runtime rules as hard guardrails: inspector `applySuggestion()` stays inline, `global-styles` and `style-book` keep preview-before-apply via `SURFACE_INTERACTION_CONTRACT`, stale-result invalidation stays driven by context signatures, and raw CSS/custom CSS remain out of scope. Decide exactly which feedback belongs inline with the style action versus in shared history/notice shells. This blocks later UI work.
2. **Phase 1 — Define a shared style-surface presentation layer** that can be reused across inspector rows/chips and Site Editor suggestion cards. Reuse `AIStatusNotice`, `AIActivitySection`, `AIReviewSection`, and the existing editor token system in `src/editor.css`; add only the missing presentational primitives needed for style-specific badges, operation lists, and inline feedback slots. Depends on step 1.
3. **Phase 2 — Rework `StylesRecommendations()` for the inspector Styles tab.** Improve section framing, count pills, variation-button emphasis, preview/token display, description hierarchy, and empty/cross-panel guidance so the surface feels native and scannable. Replace the current purely transient `appliedKey` feel-good flash with feedback that is still lightweight but easier to notice near the relevant style section. Depends on step 2.
4. **Phase 2 — Rework `SuggestionChips()` and the injection layout in `InspectorInjector.js`** so delegated style panels (Color, Typography, Dimensions, Border, Filter, Background) show action feedback closer to the chip group. Preserve one-click apply, fit within the ToolsPanel grid, and avoid duplicating suggestions between the main Styles panel and delegated sub-panels. Depends on step 2 and should be coordinated with step 3.
5. **Phase 2 — Harden the inspector helper seams while polishing the UI.** Expand `getSuggestionKey()` beyond the current `panel-label` strategy to avoid collisions, and consolidate or centralize delegated-panel mapping so `panel-delegation.js` and `InspectorInjector.js` cannot drift. This can run in parallel with steps 3 and 4 once the shared UI contract is set.
6. **Phase 3 — Harmonize `GlobalStylesRecommender()` and `StyleBookRecommender()` with the inspector polish.** Keep their current review-before-apply behavior, but upgrade visual hierarchy, scope/context badges, suggestion card structure, and operation preview styling so they feel like the same family as the inspector style surfaces. Depends on step 2.
7. **Phase 3 — Move success/error/undo emphasis closer to the active suggestion/review state** inside Global Styles and Style Book while preserving `AIStatusNotice`, `AIActivitySection`, and the existing activity/undo semantics. Do not change `buildGlobalStylesRecommendationContextSignature()`, `applyGlobalStylesSuggestion()`, `applyStyleBookSuggestion()`, or undo eligibility logic. Depends on step 6.
8. **Phase 4 — Add and update tests for polished interaction behavior.** Cover inspector style rows/chips, applied/feedback placement, delegated-panel rendering, Global Styles and Style Book visual/review states, and any helper changes around suggestion keys or feedback placement. Depends on steps 3 through 7.
9. **Phase 4 — Update docs only where visible UX changed.** Refresh the style surface docs and feature matrix, and note admin audit polish as a separate future concern unless implementation unexpectedly requires activity-schema changes. Depends on final UI decisions.

### Relevant files

- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/inspector/InspectorInjector.js` — current mount points for style-tab content and delegated sub-panel chips.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/inspector/StylesRecommendations.js` — main inspector style list, style-variation pills, grouped rows, and local apply feedback.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/inspector/SuggestionChips.js` — compact delegated-panel chip UI with one-click apply.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/inspector/suggestion-keys.js` — current applied-state/keying logic (`panel-label`) that should be made collision-safe.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/inspector/panel-delegation.js` — delegated panel contract that must stay aligned with the injector.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/global-styles/GlobalStylesRecommender.js` — Site Editor Global Styles shell, request/review/apply/undo flow, and notice/history layout.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/style-book/StyleBookRecommender.js` — block-scoped Style Book shell that should visually align with Global Styles and inspector polish.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/components/AIStatusNotice.js` — shared notice component to reuse rather than invent a second status system.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/components/AIActivitySection.js` — shared recent-actions/undo section; likely needs layout-only polish, not behavior changes.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/components/AIReviewSection.js` — shared preview-before-apply shell for Global Styles and Style Book.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/editor.css` — shared visual system, spacing, chips, cards, notices, preview shells, and missing style-surface polish hooks.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/store/index.js` — preserve `SURFACE_INTERACTION_CONTRACT`, `applySuggestion()`, `applyGlobalStylesSuggestion()`, `applyStyleBookSuggestion()`, and existing surface notice selectors/actions.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/docs/features/style-and-theme-intelligence.md` — update only if user-visible interaction/placement changes warrant documentation.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/docs/FEATURE_SURFACE_MATRIX.md` — keep the feature inventory aligned if surface behavior or fallback presentation changes.
- `/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/STATUS.md` — optional verification/status note if the work materially changes the shipped style UX.

### Verification

1. Run targeted JS unit tests for the touched surfaces and shared shells: `src/inspector/__tests__/StylesRecommendations.test.js`, any `SuggestionChips` or helper tests added, `src/global-styles/__tests__/GlobalStylesRecommender.test.js`, `src/style-book/__tests__/StyleBookRecommender.test.js`, and `src/components/__tests__/AIActivitySection.test.js` / `AIReviewSection.test.js` if their layout contracts change.
2. Run the full JS unit suite and build so CSS and shared component changes do not regress sibling recommendation surfaces.
3. Manually verify the block editor: fetch style recommendations for a block, confirm no duplicate rendering between the Styles tab and delegated sub-panels, confirm one-click apply still works, and confirm success/error/undo cues are visible near the interaction point.
4. Manually verify the Site Editor Styles sidebar: Global Styles remains hidden when Style Book is active, Style Book only appears with a valid target block, review-before-apply still gates executable changes, and status/activity/undo remain understandable after the visual polish.
5. Regression-check stale-result safety by changing scope or style context after fetching suggestions and ensuring the UI clears or re-scopes results instead of showing outdated recommendations.

### Decisions

- Included scope: block inspector style recommendations plus Site Editor Global Styles and Style Book surfaces.
- Interaction choice: keep one-click apply for block-level style suggestions; do not convert inspector style changes into mandatory preview/review.
- Polish priorities: stronger visual hierarchy, more native Gutenberg/WPDS feel, and status/undo feedback closer to the action.
- Explicitly excluded unless blocked by implementation: backend prompt/REST contract changes, widening style operations beyond theme-safe guardrails, raw CSS/custom CSS support, and an admin audit redesign.
- Activity persistence, ordered undo, and context-signature invalidation are treated as non-negotiable behavior contracts during the UI work.

### Further considerations

1. If implementation starts to sprawl, split delivery into two PRs: first inspector style polish, then Global Styles/Style Book harmonization using the shared visual primitives from phase 1.
2. Prefer a frontend-only polish first. Only add new backend metadata if the existing suggestion objects cannot support the desired badges/explanations after prototyping.
