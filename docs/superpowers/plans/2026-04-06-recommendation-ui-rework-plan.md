# Recommendation UI Rework Plan

> Scope: this plan turns the recommendation-interface review into an implementation-ready solution across the block inspector, delegated style/settings panels, navigation guidance, template/template-part recommenders, and Site Editor style surfaces. The goal is to improve clarity, actionability, and consistency without weakening any existing safety or undo contracts.

## Goal

Make every recommendation surface answer the same three questions immediately:

1. What is Flavor Agent looking at right now?
2. What is the best next action?
3. What can be applied safely versus reviewed manually?

The finished UI should feel like one product family, not a collection of adjacent cards and notices.

## Current Problems To Solve

1. Primary actions compete visually with intro copy, status notices, history, and secondary suggestion groups.
2. Prompting is functional but too blank and too repetitive across surfaces.
3. Success, error, and undo feedback are not consistently placed near the interaction that caused them.
4. History appears too early in some surfaces and steals attention from fresh results.
5. Stale results are detectable but not actionable enough.
6. Safety guidance is repeated as explanatory prose instead of encoded in clearer affordances.
7. Shared shells use similar card styling for intro, composer, and results, which flattens hierarchy.

## Non-Negotiable Constraints

1. Preserve all existing surface interaction contracts in `src/store/index.js`.
2. Keep one-click apply for safe local block/style inspector changes.
3. Keep review-before-apply for template, template-part, Global Styles, and Style Book executable operations.
4. Preserve stale-result invalidation driven by context signatures.
5. Preserve current undo eligibility rules and activity persistence behavior.
6. Do not widen backend execution scope just to improve presentation.
7. Do not introduce new per-suggestion store state for inspector apply failures or undo in this phase.

## Target UI Model

Every recommendation surface should render in this order:

1. Scope and freshness
2. Prompt section
3. Primary recommendation or review state
4. Secondary executable recommendations
5. Advisory or manual ideas
6. Activity history

This does not require every surface to support every step. Advisory-only surfaces stop before review/apply, but they should still use the same visual grammar.

## Complete Solution

### Shared Presentation Contract

Introduce a shared recommendation presentation layer in `src/components` that standardizes:

1. A visible prompt section with title, label, optional helper text, starter prompts, and submit affordances.
2. A scope bar that can say both what changed and, on surfaces that retain invalidated client-side results, what to do next when results are stale.
3. A primary recommendation state that highlights the strongest next action.
4. Shared recommendation lanes for:
   - `Apply now`
   - `Review first`
   - `Manual ideas`
5. Inline action feedback that sits inside the card, chip cluster, or review shell that produced it.
6. A lower-priority, optionally collapsed activity section.

Freshness handling remains surface-specific in this phase:

1. Block recommendations can retain the last fetched result client-side and expose an explicit refresh affordance when the selected block context changes.
2. Navigation, template, template-part, Global Styles, and Style Book keep their current clear-on-context-change behavior until their request state is intentionally redesigned.

### Target Surface Behavior

#### Block Inspector

1. Keep the current panel and fallback panel structure.
2. Replace the current "intro -> scope -> composer -> status -> history -> suggestions" stack with:
   - scope/freshness
   - prompt section
   - primary recommendation hero when results exist
   - executable block recommendations
   - advisory recommendations
   - delegated settings/styles panels
   - recent actions
3. Put navigation guidance after block recommendations and before history, but only for `core/navigation`.
4. Keep navigation requests and status separate from block recommendation requests; the nested presentation should remove duplicate shells, not merge the two fetch flows.

#### Settings And Styles Panels

1. Keep grouped placement beside native controls.
2. Replace transient button-only confirmation with inline feedback near the clicked row or chip group.
3. Show concise tone labels such as `Apply now` or `Suggested`, not only counts and confidence.

#### Navigation

1. Keep advisory-only behavior.
2. Keep its separate request/store lifecycle even when it is visually embedded under the block inspector.
3. Reframe the surface as `Recommended next changes` rather than a list of equal cards.
4. Group navigation suggestions by category and surface one featured recommendation first inside the navigation subsection itself.

#### Template And Template Part

1. Use the same primary recommendation and lane structure as block recommendations.
2. Keep review-before-apply, but visually surface one recommended executable suggestion above the rest when available.
3. Move older activity below advisory guidance.

#### Global Styles And Style Book

1. Keep review-before-apply.
2. Reuse the same prompt section and primary recommendation treatment.
3. Move inline status and undo emphasis into the active review shell or selected suggestion card.
4. Keep activity below the current review/result state.

## Workstream 1: Shared Primitives And Information Architecture

### Files

- Modify: `src/components/SurfaceComposer.js`
- Modify: `src/components/SurfaceScopeBar.js`
- Modify: `src/components/AIStatusNotice.js`
- Modify: `src/components/AIActivitySection.js`
- Modify: `src/components/AIReviewSection.js`
- Modify: `src/editor.css`
- Add: `src/components/RecommendationHero.js`
- Add: `src/components/RecommendationLane.js`
- Add: `src/components/InlineActionFeedback.js`

### Steps

1. Evolve `SurfaceComposer` into a full prompt section.
   - Render the label visibly by default.
   - Support optional title/eyebrow content inside the composer itself when a surface does not need a separate intro card.
   - Add starter prompt chips such as "Make this feel more editorial" or "Improve clarity and spacing".
   - Support keyboard submission with explicit modifiers where appropriate.
   - Allow helper text to appear below the prompt without repeating long safety copy everywhere.

2. Extend `SurfaceScopeBar`.
   - Add optional `onRefresh`.
   - Add optional `staleReason` and `refreshLabel`.
   - Only show a direct `Refresh` action on surfaces that intentionally retain stale client-side results.
   - In this phase, wire the refresh affordance for block recommendations only.

3. Add a shared recommendation hero component.
   - Input: label, description, tone, why this is first, primary action, secondary meta.
   - Use it only when a surface has a clear best next action.

4. Add shared recommendation lanes.
   - Each lane owns a title, badge, short help text, and body slot.
   - Lanes should be reusable for block suggestions, style groups, advisory sections, and template/template-part result grouping.

5. Add shared inline action feedback.
   - Success confirmation should sit next to the interaction source.
   - Inspector apply errors and undo state stay on the existing block-level status/history rails unless a later store change introduces per-suggestion correlation.
   - This replaces most "button turned green for a moment" feedback.

6. Rework `AIActivitySection`.
   - Add `initialOpen`, `maxVisible`, and `showMore` support.
   - Default block/template/style histories to collapsed or placed last when fresh results are present.
   - Preserve the current undo rows and status labeling.

## Workstream 2: Shared Visual Hierarchy And Copy System

### Files

- Modify: `src/editor.css`
- Modify: `src/tokens.css` only if additional tokens are needed
- Modify: `src/components/SurfacePanelIntro.js`

### Steps

1. Reduce equal-weight shell styling.
   - Make prompt and active review states visually stronger than intro and passive groups.
   - Tone down the decorative intro shell so it does not compete with the active recommendation state.

2. Create stronger distinction between:
   - prompt sections
   - active recommendation hero/review state
   - ordinary grouped results
   - low-priority history

3. Standardize tone badges.
   - Replace inconsistent labels with a short shared vocabulary:
     - `Apply now`
     - `Review first`
     - `Manual`
     - `Current`
     - `Stale`

4. Reduce repeated guidance copy.
   - Keep one short sentence per section.
   - Move long safety reminders into helper text, tooltips, or review hints only where needed.

## Workstream 3: Block Recommendation Surface Rebuild

### Files

- Modify: `src/inspector/BlockRecommendationsPanel.js`
- Modify: `src/inspector/NavigationRecommendations.js`
- Modify: `src/components/SurfaceComposer.js`
- Modify: `src/components/SurfaceScopeBar.js`
- Add tests in: `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
- Modify: `src/inspector/__tests__/NavigationRecommendations.test.js`

### Steps

1. Move the prompt section ahead of explanatory prose.
2. Compute a featured recommendation.
   - Prefer the first executable block suggestion.
   - Fallback to the first advisory suggestion.

3. Render a recommendation hero above grouped results.
   - Show why it is the suggested next step.
   - Show whether it is safe to apply now or requires manual follow-through.

4. Split the remaining results into lanes.
   - `Apply now`: executable block suggestions
   - `Manual ideas`: advisory block suggestions
   - nested `Navigation` subsection only when applicable
   - the nested navigation subsection keeps its own prompt and request status instead of sharing the block composer

5. Move `AIActivitySection` below all fresh block results and below the nested navigation subsection when present.
6. For block recommendations only, keep the last fetched suggestions visible when the live context signature no longer matches, visually demote executable actions, and expose `Refresh` through `SurfaceScopeBar`.

## Workstream 4: Settings And Style Inspector Normalization

### Files

- Modify: `src/inspector/SettingsRecommendations.js`
- Modify: `src/inspector/StylesRecommendations.js`
- Modify: `src/inspector/SuggestionChips.js`
- Modify: `src/inspector/InspectorInjector.js`
- Modify if needed: `src/inspector/suggestion-keys.js`
- Modify: `src/inspector/panel-delegation.js`
- Modify tests in:
  - `src/inspector/__tests__/SettingsRecommendations.test.js`
  - `src/inspector/__tests__/StylesRecommendations.test.js`
  - `src/inspector/__tests__/SuggestionChips.test.js`
  - `src/inspector/__tests__/InspectorInjector.test.js`

### Steps

1. Add lane framing to settings and style groups.
   - Do not render them as undifferentiated stacks of cards.

2. Normalize feedback.
   - Settings cards should use inline success feedback rather than only toggling button state.
   - Chips should keep feedback in the same grid position and persist long enough to be noticed.
   - Keep inspector apply errors on the existing block-level request notice until there is a store-backed way to associate failures with a specific row or chip.

3. Improve row hierarchy.
   - Elevate label and preview first.
   - Show confidence and token detail as secondary metadata.
   - Keep one clear primary action per row.

4. Harden suggestion identity.
   - Start by adding regression coverage around delegated panel ownership versus main-panel ownership.
   - Only expand `getSuggestionKey()` if a concrete collision is reproduced; the current key already fingerprints operations and value payloads, so duplication may be a delegation/rendering bug instead.

5. Keep one-click apply intact.
   - No mandatory preview for safe inspector style/settings operations.

## Workstream 5: Navigation Advisory Experience

### Files

- Modify: `src/inspector/NavigationRecommendations.js`
- Modify: `src/components/AIAdvisorySection.js`
- Modify: `docs/features/navigation-recommendations.md`

### Steps

1. Replace equal-weight card rendering with:
   - featured navigation recommendation inside the navigation subsection
   - grouped supporting changes
   - manual follow-through guidance

2. Keep navigation prompt, fetch action, stale invalidation, and request status separate from block recommendations even when embedded under the block panel.

3. When embedded inside `BlockRecommendationsPanel`, suppress duplicate intro/scope shells so the user does not see two top-level prompt stacks competing for attention.

4. Add clearer category framing.
   - Group structure, overlay, and accessibility changes separately when present.

5. Make the advisory nature visible without over-explaining it.
   - Use a `Manual` lane and short helper copy.
   - Remove repeated prose that currently says the same thing in multiple places.

## Workstream 6: Template And Template-Part Harmonization

### Files

- Modify: `src/templates/TemplateRecommender.js`
- Modify: `src/template-parts/TemplatePartRecommender.js`
- Modify tests in:
  - `src/templates/__tests__/TemplateRecommender.test.js`
  - `src/template-parts/__tests__/TemplatePartRecommender.test.js`

### Steps

1. Add the same recommendation hero treatment used in the block surface.
2. Keep executable preview cards below the hero and above advisory cards.
3. Move activity below active review and advisory sections.
4. Keep entity links, pattern browse actions, and review-before-apply behavior unchanged.
5. Keep the existing clear-on-context-change behavior for stale template and template-part results in this phase; do not add refresh affordances yet.

## Workstream 7: Global Styles And Style Book Harmonization

### Files

- Modify: `src/global-styles/GlobalStylesRecommender.js`
- Modify: `src/style-book/StyleBookRecommender.js`
- Modify tests in:
  - `src/global-styles/__tests__/GlobalStylesRecommender.test.js`
  - `src/style-book/__tests__/StyleBookRecommender.test.js`

### Steps

1. Replace the custom textarea/button group with the shared prompt section.
2. Surface one featured suggestion when present.
3. Keep suggestion cards grouped into:
   - `Review first`
   - `Manual`

4. Move inline notice placement fully inside:
   - the selected suggestion card, or
   - the active `AIReviewSection`

5. Keep activity below current review/results.
6. Preserve all current executor, undo, and scope-signature logic.
7. Keep the existing clear-on-context-change behavior for stale results in this phase; do not add refresh affordances yet.

## Workstream 8: Accessibility, Interaction, And Copy Polish

### Files

- Modify touched components and CSS from previous workstreams
- Update tests where needed

### Steps

1. Ensure visible labels remain attached to textareas and grouped controls.
2. Add keyboard handling for starter prompts and refresh actions.
3. Ensure async feedback uses existing `aria-live` behavior and remains near the triggering control.
4. Use consistent copy:
   - `Thinking...` -> `Thinking...` is acceptable internally, but prefer `Thinking...` only if typography standards are already established elsewhere; otherwise standardize on ellipsis usage consistently across the plugin.
   - Use Title Case for group headings and button labels where they are user-facing.
5. Check truncation and wrapping for long suggestion labels and long JSON-like values.

## Workstream 9: Documentation And Feature Matrix Alignment

### Files

- Modify: `docs/features/block-recommendations.md`
- Modify: `docs/features/navigation-recommendations.md`
- Modify: `docs/features/style-and-theme-intelligence.md`
- Modify: `docs/features/template-recommendations.md`
- Modify: `docs/features/template-part-recommendations.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`
- Optional: `STATUS.md`

### Steps

1. Update each feature doc only after the UI contract is settled.
2. Document the new shared recommendation flow vocabulary.
3. Call out that block recommendations now support explicit refresh when stale, while other surfaces still clear invalidated results.
4. Keep behavior descriptions aligned with real capabilities; do not imply new execution paths.

## Test Plan

### Unit Tests

Add or update tests for:

1. `SurfaceComposer`
   - visible label rendering
   - starter prompt chips
   - keyboard submit
   - disabled/loading states

2. `SurfaceScopeBar`
   - fresh state
   - stale state with refresh action on block surfaces that retain stale results
   - stale reason rendering

3. `AIActivitySection`
   - collapsed state
   - show more behavior
   - latest undo row still works

4. Recommendation hero and lane components
   - correct tone badges
   - action button placement
   - fallback behavior when no hero exists

5. Block inspector
   - hero picks the correct recommendation
   - history moves below results
   - stale state demotes executable actions

6. Settings/styles/chips
   - inline success feedback placement
   - inspector apply errors remain on the existing block-level notice
   - no duplicate rendering between main panel and delegated panels
   - collision-safe suggestion keys

7. Template/template-part/global styles/style book
   - review-before-apply still gates execution
   - inline notices render inside the active review or selected card

### Commands

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
npm run test:unit -- --runInBand src/components/__tests__/SurfaceComposer.test.js src/components/__tests__/AIActivitySection.test.js
npm run test:unit -- --runInBand src/components/__tests__/SurfaceScopeBar.test.js src/components/__tests__/AIStatusNotice.test.js src/components/__tests__/AIReviewSection.test.js src/components/__tests__/AIAdvisorySection.test.js src/components/__tests__/SurfacePanelIntro.test.js
npm run test:unit -- --runInBand src/inspector/__tests__/BlockRecommendationsPanel.test.js src/inspector/__tests__/SettingsRecommendations.test.js src/inspector/__tests__/StylesRecommendations.test.js src/inspector/__tests__/SuggestionChips.test.js src/inspector/__tests__/NavigationRecommendations.test.js src/inspector/__tests__/InspectorInjector.test.js src/inspector/__tests__/panel-delegation.test.js
npm run test:unit -- --runInBand src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/global-styles/__tests__/GlobalStylesRecommender.test.js src/style-book/__tests__/StyleBookRecommender.test.js
npm run lint:js
npm run build
vendor/bin/phpunit
```

### Manual Verification

1. Block editor
   - fetch recommendations for a standard block
   - verify the featured recommendation appears first
   - apply a safe suggestion
   - confirm feedback appears next to the clicked control
   - change block context and confirm stale refresh is obvious for block recommendations

2. Navigation block
   - fetch advisory recommendations
   - verify the embedded navigation subsection keeps its own prompt/status flow without duplicating top-level intro/scope shells
   - verify grouped categories and featured recommendation behavior
   - confirm no apply or activity path appears

3. Template and template part
   - fetch recommendations
   - open review
   - apply a deterministic suggestion
   - confirm history appears below the active result state
   - change structure context and confirm outdated results clear instead of presenting a stale refresh action

4. Site Editor styles
   - verify Global Styles and Style Book both use the shared prompt section
   - open review and apply an executable suggestion
   - confirm inline notice placement and unchanged undo behavior
   - change style context and confirm outdated results clear instead of presenting a stale refresh action

5. Delegated style/settings panels
   - apply chips and row actions
   - confirm feedback placement is local and no duplicates appear

## Delivery Order

1. Shared primitives and CSS hierarchy
2. Block surface and scope refresh behavior
3. Settings/styles/chips normalization
4. Navigation advisory restructuring
5. Template and template-part harmonization
6. Global Styles and Style Book harmonization
7. Docs and cleanup

## Exit Criteria

1. Every recommendation surface clearly communicates scope, freshness, and next action.
2. Fresh results place the strongest next action before history and secondary suggestions.
3. Inline apply/review/manual distinctions are visually obvious without repeated prose.
4. Success feedback and review-surface notices appear near the active interaction without changing existing inspector error/undo contracts.
5. Block recommendations provide an explicit stale refresh path, while other surfaces continue clearing invalidated results.
6. Shared tests cover the new layout and interaction contracts.
7. No backend execution scope or undo semantics are loosened during the redesign.

## Out Of Scope

1. New backend recommendation types
2. New mutation capabilities
3. Raw CSS or custom CSS execution
4. Activity schema redesign
5. Replacing Gutenberg-native panels with custom sidebars
