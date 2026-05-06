# UI Polish Screenshot Agent Prompt

Use this prompt to hand off a screenshot-driven polish pass for the Flavor Agent editor and admin UI.

```md
You are working in `/home/dev/flavor-agent`.

Goal: polish the customer-facing UI for Flavor Agent so the plugin feels like a coherent WordPress-native AI product, not a collection of feature panels. Focus on visual hierarchy, spacing, styling, interaction states, empty/error/loading states, and the review/apply/undo experience. Do not change provider behavior, recommendation semantics, Abilities contracts, activity persistence, or settings ownership unless a UI bug requires it.

Use the available browser/screenshot MCP server throughout the work. Capture baseline screenshots before editing, then matching after screenshots for every surface you touch. Save or report screenshot paths clearly.

Primary surfaces:

- Block editor AI panel and block style inspection: `src/inspector/`, `src/context/block-inspector.js`, `src/editor.css`
- Shared recommendation UI: `src/components/`
- Apply/undo workflow: `src/components/InlineActionFeedback.js`, `src/components/UndoToast.js`, `src/store/activity-undo.js`
- Pattern recommendation panels and inserter badge: `src/patterns/`
- Admin settings dashboard: `src/admin/settings-page.js`, `src/admin/settings-page-controller.js`, `src/admin/settings.css`
- AI Activity admin screen: `src/admin/activity-log.js`, `src/admin/activity-log-utils.js`, `src/admin/activity-log.css`

Product constraints:

- Keep the surface model intact: block suggestions have a direct-apply exception; pattern recommendations are browse/rank-only; template/template-part/style surfaces are review-first; AI Activity is provenance and undo support, not a general analytics dashboard.
- Keep `AI Model`, `Embedding Model`, `Patterns`, and `Developer Docs` visually and conceptually separate in settings.
- Request-time diagnostics are separate from apply/undo history. Do not blur those concepts in AI Activity.
- Preserve WordPress-native behavior and styling. Prefer WP components, DataViews/DataForm patterns, Gutenberg panel conventions, accessible focus states, and compact admin layouts.
- Avoid nested cards, decorative gradient blobs, marketing-style hero layouts, oversized headings inside panels, and UI text that explains obvious functionality.
- Text must fit on desktop and mobile widths. No overlap, clipped buttons, or layout shifts from dynamic labels.

Screenshot workflow:

1. Start the local WordPress environment using the repo docs.
2. With the screenshot MCP server, capture before screenshots for:
   - Block editor with a selected block and the AI Recommendations panel open.
   - A block style/settings recommendation near native Inspector controls.
   - Pattern inserter with Flavor Agent recommendations/badge visible.
   - Settings > Flavor Agent.
   - Settings > AI Activity with list and detail panel visible.
   - A successful apply state and an undo-available state.
   - Any loading, empty, unavailable, or error state that is easy to reproduce.
3. Implement the smallest coherent UI polish pass.
4. Capture matching after screenshots at the same viewport sizes.
5. Compare before/after and fix any regressions visible in screenshots.

Implementation standards:

- Keep edits scoped to UI/styling unless tests prove a small helper change is needed.
- Use existing tokens and CSS conventions before adding new ones.
- Improve consistency across recommendation lanes, status notices, review sections, activity rows, settings groups, buttons, badges, and toasts.
- Make all actionable controls visibly distinct from passive advisory content.
- Preserve accessibility: semantic HTML, keyboard focus, labels, color contrast, reduced motion where relevant.

Verification:

- Run the nearest targeted JS tests for touched components.
- Run `npm run lint:js`.
- Run `npm run check:docs` if docs or screenshot references change.
- Run the relevant Playwright/E2E flow if the local environment supports it.
- End with a concise report: changed files, before/after screenshot paths, validation commands, and any remaining UI risks.
```
