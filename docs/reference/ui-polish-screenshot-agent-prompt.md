# UI Polish Screenshot Agent Prompt

Use this prompt to hand off a screenshot-driven polish pass for the Flavor Agent editor, recommendation, and admin UI.

```md
You are working in `/home/dev/flavor-agent`.

Goal: polish the user-facing UI for Flavor Agent so the plugin feels like one coherent WordPress-native AI product, not a collection of feature panels. Focus on visual hierarchy, spacing, styling, responsive behavior, interaction states, empty/error/loading states, and the clarity of recommendation, review, apply, undo, and activity flows.

Do not change provider behavior, recommendation semantics, Abilities contracts, persistence contracts, permission checks, or settings ownership unless a confirmed UI bug requires a small adjacent helper fix.

Use available MCP tools when they help. Prefer the browser/screenshot MCP server for visual inspection and before/after evidence. Prefer the WPDS MCP server for WordPress Design System components, tokens, and patterns when it is available. Use Context7 only for general library/API documentation that is not already covered by repo docs or WPDS.

Assume all existing screenshot, page-state, console, trace, and Playwright MCP artifacts in the checkout are stale. Do not reuse old files under `.playwright-mcp/`, `output/`, or any previous screenshot directory as baseline or after evidence. They can be hints about routes or states to inspect, but every visual claim in this pass must come from fresh captures taken during the current run after confirming the local environment state.

Before editing, read these repo contracts:

- `docs/SOURCE_OF_TRUTH.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/reference/recommendation-ui-consistency.md`
- `docs/reference/release-surface-scope-review.md`
- `docs/reference/activity-state-machine.md`
- `docs/reference/cross-surface-validation-gates.md`
- `docs/features/block-recommendations.md`
- `docs/features/content-recommendations.md`
- `docs/features/pattern-recommendations.md`
- `docs/features/template-recommendations.md`
- `docs/features/template-part-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`
- `docs/features/activity-and-audit.md`
- `docs/features/settings-backends-and-sync.md`

Primary surfaces:

- Block editor AI panel and block style inspection:
  - `src/inspector/BlockRecommendationsPanel.js`
  - `src/inspector/SuggestionChips.js`
  - `src/inspector/group-by-panel.js`
  - `src/inspector/panel-delegation.js`
  - `src/context/block-inspector.js`
  - `src/editor.css`
- Shared recommendation UI:
  - `src/components/RecommendationLane.js`
  - `src/components/RecommendationHero.js`
  - `src/components/AIAdvisorySection.js`
  - `src/components/AIReviewSection.js`
  - `src/components/AIStatusNotice.js`
  - `src/components/SurfaceComposer.js`
  - `src/components/SurfaceScopeBar.js`
  - `src/components/SurfacePanelIntro.js`
  - `src/components/CapabilityNotice.js`
- Apply/undo workflow:
  - `src/components/InlineActionFeedback.js`
  - `src/components/UndoToast.js`
  - `src/components/ToastRegion.js`
  - `src/components/AIActivitySection.js`
  - `src/store/activity-history.js`
  - `src/store/activity-undo.js`
  - `src/store/activity-session.js`
- Pattern recommendation panels and inserter badge:
  - `src/patterns/PatternRecommender.js`
  - `src/patterns/InserterBadge.js`
  - `src/patterns/inserter-badge-state.js`
  - `src/patterns/recommendation-utils.js`
  - `src/patterns/pattern-settings.js`
- Template recommendations:
  - `src/templates/TemplateRecommender.js`
  - `src/templates/template-recommender-helpers.js`
  - `src/template-parts/TemplatePartRecommender.js`
  - `src/template-parts/template-part-recommender-helpers.js`
  - `src/utils/template-actions.js`
  - `src/utils/template-operation-sequence.js`
- Style apply workflow:
  - `src/global-styles/GlobalStylesRecommender.js`
  - `src/style-book/StyleBookRecommender.js`
  - `src/style-surfaces/presentation.js`
  - `src/style-surfaces/request-input.js`
  - `src/utils/style-operations.js`
  - `src/utils/style-validation.js`
- Content generation:
  - `src/content/ContentRecommender.js`
- Admin settings dashboard:
  - `src/admin/settings-page.js`
  - `src/admin/settings-page-controller.js`
  - `src/admin/settings.css`
  - `src/admin/brand.css`
- AI Activity admin screen:
  - `src/admin/activity-log.js`
  - `src/admin/activity-log-utils.js`
  - `src/admin/activity-log.css`

Product constraints:

- Keep the surface model intact:
  - Block recommendations are the only direct-apply exception, and only for safe local block updates.
  - Delegated block settings/style Inspector subpanels are passive mirrors. They do not own apply, refresh, or activity state.
  - Pattern recommendations are browse/rank-only inside the native inserter. Do not add Flavor Agent pattern apply, review, or undo.
  - Content generation is editorial-only. Do not add automatic post mutation, apply, or undo.
  - Template, template-part, Global Styles, and Style Book recommendations are review-first before apply.
  - AI Activity is provenance and undo support, not an observability, cost, provider-ranking, or analytics dashboard.
- Preserve the shared user-facing vocabulary: `Apply now`, `Review first`, and `Manual ideas`.
- Keep request-time diagnostics separate from apply/undo history in UI copy, filters, metadata, and activity rows.
- Keep `AI Model`, `Embedding Model`, `Patterns`, and `Developer Docs` visually and conceptually separate in settings. Settings should focus on controls, status, and next actions; longer explanations belong in contextual Help.
- Preserve WordPress-native behavior and styling. Prefer `@wordpress/components`, DataViews/DataForm patterns, Gutenberg panel conventions, accessible focus states, and compact admin layouts.
- Avoid nested cards, decorative gradient blobs, marketing-style hero layouts, oversized headings inside panels, and visible copy that explains obvious functionality.
- Text must fit on desktop and mobile widths. No overlap, clipped buttons, clipped badges, or layout shifts from dynamic labels.
- Do not rely on color alone to distinguish available, review, advisory, stale, blocked, failed, applied, undone, loading, or empty states.

Screenshot workflow:

1. Start the local WordPress environment using `docs/reference/local-environment-setup.md`. Confirm the plugin and required feature toggles are active before treating missing UI as a product bug.
2. Ignore pre-existing screenshot files for evidence. Capture a fresh baseline before editing, and record the capture time or run folder so it is clear which images belong to this pass. Use the same viewport sizes for after screenshots.
3. Capture these before/after states when available:
   - Block editor with a selected block and the main AI Recommendations panel open.
   - Native Inspector settings/style panels showing passive Flavor Agent chips near the matching core controls.
   - Content generation panel before request, during loading if practical, and with generated editorial output.
   - Pattern inserter with Flavor Agent recommendations, badge, loading, empty, unavailable, and error states where practical.
   - Template recommendation panel with `Review first` and `Manual ideas` lanes.
   - Template-part recommendation panel with the lower review-before-apply panel open.
   - Global Styles recommendation panel with a selected style review.
   - Style Book recommendation panel with review/apply and undo-available states.
   - Successful apply feedback, undo-available feedback, undo-blocked or undo-unavailable feedback if practical.
   - Settings > Flavor Agent on desktop and a narrow viewport.
   - Settings > AI Activity with list, filters, summary cards, pagination, and detail panel visible.
   - Any easy-to-reproduce loading, empty, unavailable, stale, blocked, failed, or error state.
4. Implement the smallest coherent polish pass that improves the visual system across the touched surfaces.
5. Capture matching after screenshots.
6. Compare before/after screenshots and fix any visible regressions before reporting completion.

Implementation standards:

- Keep edits scoped to `src/` UI, CSS, and focused UI helpers unless tests prove a small adjacent fix is required.
- Edit source files only. Do not hand-edit `build/` or `dist/`; run the build if generated assets need to be refreshed.
- Use existing tokens, CSS variables, spacing patterns, and shared components before adding new styling primitives.
- Prefer fewer, stronger shared UI treatments over surface-specific decorations.
- Make actionable controls visibly distinct from passive advisory content.
- Keep review panels, status notices, badges, toasts, activity rows, settings sections, recommendation lanes, and empty states visually consistent without flattening their product differences.
- Preserve semantic HTML, keyboard focus, labels, color contrast, reduced-motion behavior, and screen-reader names.
- Keep compact panel typography. Do not use hero-scale headings inside Inspector panels, DataViews screens, cards, notices, or sidebars.

Verification:

- Run the nearest targeted JS tests for every touched component or helper.
- Run targeted PHP tests only if a PHP-rendered admin surface, setting state, or REST/activity contract changes.
- Run `npm run build` when source assets changed.
- Run `npm run lint:js -- --quiet`.
- Run `npm run check:docs` if docs or prompt artifacts change.
- Run the relevant Playwright/E2E flow when the local environment supports it:
  - `tests/e2e/flavor-agent.smoke.spec.js`
  - `tests/e2e/flavor-agent.settings.spec.js`
  - `tests/e2e/flavor-agent.activity.spec.js`
  - WP 7.0 Site Editor coverage for template, template-part, Global Styles, and Style Book flows when those surfaces change.
- For changes touching multiple recommendation surfaces or shared apply/undo/activity contracts, follow `docs/reference/cross-surface-validation-gates.md` and run `node scripts/verify.js --skip-e2e` unless a narrower gate is explicitly justified.
- End with a concise report: changed files, before/after screenshot paths, validation commands and results, skipped checks with reasons, and remaining UI risks.
```
