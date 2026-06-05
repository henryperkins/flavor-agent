# Gutenberg 23.3 Nightly Runtime Validation Checklist

Use this checklist for the representative nightly runtime pass against the
Gutenberg 23.3 release line. Gutenberg 23.3.0 shipped the React 19 upgrade in
the 03 Jun Make/Core release post, 23.3.1 added React compatibility polyfills,
and 23.3.2 reverted the React 19 upgrade. Record the exact active patch level
and treat React-specific checks according to the installed runtime. The
non-browser build/test layer is covered by the local npm graph and aggregate
verifier; this checklist covers runtime behavior that unit tests and the
existing Playwright topology do not structurally exercise against nightly
Gutenberg and companion plugin versions.

Record every item as `pass`, `fail`, or `not-testable`. A missing harness,
unavailable experiment, or absent runtime prerequisite is an explicit waiver
with a reason, not a silent skip. Treat this as additive to
`docs/reference/cross-surface-validation-gates.md`.

## Preconditions

- WordPress nightly/trunk is installed.
- Gutenberg `23.3.x` is active, and the exact version is recorded. Use
  Gutenberg `23.3.0` or `23.3.1` when the goal is to exercise the React 19
  runtime itself; if the active runtime is `23.3.2` or later, mark React
  19-only assertions `not-testable` and run the patch-aware React compatibility
  smoke below instead. Sources: Make/Core 23.3 release post
  https://make.wordpress.org/core/2026/06/03/whats-new-in-gutenberg-23-3-03-jun/,
  GitHub 23.3.1 release https://github.com/WordPress/gutenberg/releases/tag/v23.3.1,
  GitHub 23.3.2 release https://github.com/WordPress/gutenberg/releases/tag/v23.3.2,
  React 19 revert PR https://github.com/WordPress/gutenberg/pull/78940.
- Required companion plugins are active: AI, provider connector plugins, MCP
  Adapter, Plugin Check, and Flavor Agent.
- `npm run build` has been run after the current dependency and Gutenberg
  patch-level selection, so `build/*` is fresh for the runtime under test.
- `FeatureBootstrap::editor_runtime_available()` is true; otherwise editor
  scripts do not enqueue and the runtime pass is invalid.
- At least one text-generation Connector is approved, so recommendation
  surfaces can return live results.
- For the style-state section, enable the Gutenberg style-states or responsive
  styles experiment if the installed build still gates those controls behind an
  experiment. If pseudo-state controls are already available without an
  experiment label, continue the section and record that condition; the
  single-block pseudo-state PR notes that block instance pseudo-state support is
  not necessarily experiment-gated. Source:
  https://github.com/WordPress/gutenberg/pull/76491.
- Keep DevTools console and the Network panel open while running editor flows.

## A. Patch-Aware React Runtime Smoke

- Record the active Gutenberg plugin version and the editor runtime's React /
  ReactDOM versions before starting. Do not infer React 19 coverage from the
  major Gutenberg version alone because `23.3.2` reverted the React 19 upgrade.
  Sources: https://github.com/WordPress/gutenberg/releases/tag/v23.3.1,
  https://github.com/WordPress/gutenberg/releases/tag/v23.3.2,
  https://github.com/WordPress/gutenberg/pull/78899,
  https://github.com/WordPress/gutenberg/pull/78923,
  https://github.com/WordPress/gutenberg/pull/78940.

- Open the post editor. Confirm there are no React 19 warnings about refs,
  removed lifecycles, ref cleanup, or Flavor Agent runtime errors when running a
  React 19 build. If the active build has reverted React 19, mark the React
  19-warning assertion `not-testable` with the active version and verify that
  the editor has no stale React-compatibility errors such as
  `ReactCurrentOwner`, missing `ReactDOM.render` / `hydrate` /
  `unmountComponentAtNode`, legacy/transitional React element mismatch, or
  Flavor Agent runtime errors.
- Select a block. Confirm the Flavor Agent panel renders in the Settings tab.
- Type a prompt and fetch a suggestion. Confirm results render.
- Switch selected blocks. Confirm the prompt resets exactly once.
- Watch Network while fetching. Confirm there are no duplicate
  `/wp-abilities/.../run` or activity calls from React 19 effect behavior.
- Apply a suggestion. Confirm the applied pill appears.
- Undo via the toast. Confirm the toast shortcut `mod+alt+shift+u` focuses the
  toast; press it promptly, as success toasts auto-dismiss after ~6s (an empty
  toast region after a pause is the dismiss window, not a regression; the inline
  `Undo` pill persists either way).
- For content, pattern, navigation, template, template-part, Global Styles, and
  Style Book surfaces, run fetch, apply, and undo once where the surface is
  executable. Confirm each executable run writes the expected AI Activity
  request diagnostic row.

## B. Style-State Inspector Interaction

**Provenance:** style states span two releases this codebase skipped
reviewing. The feature began in Gutenberg 23.2 (`#77513` Responsive Global
Block Styles with States, `#78000` Refactor Client-Side Style States to Use
Nodes) and continued in 23.3 (`#76491` pseudo states on single block
instances, `#78280` show only supported Inspector controls when a state is
selected, `#78384` responsive block instance styles, `#78230` hide Styles tab
in preview mode). This is the highest-priority runtime check in this pass.

- Select a state-capable block such as Button or Group.
- Enter a pseudo-state or viewport state.
- Observe Flavor Agent delegated chip groups for color, typography, dimensions,
  border, filter, and background.
- Confirm chips either render coherently or hide cleanly. There must be no
  orphaned groups, duplicated groups, console errors, or broken
  `grid-column: 1 / -1` ToolsPanel span.
- With a non-base state selected, apply a style suggestion.
- Confirm the apply path writes to base `attributes.style.*`, leaves
  state-scoped styles intact, and undo restores cleanly.
- Log any case where a suggestion appears state-applicable but silently targets
  base styles. That is a forward-compat follow-up, not necessarily a release
  blocker when state styles remain intact.

## C. Abilities Bridge Defer

- On a fresh editor load, do not open the command palette.
- Call `window.flavorAgentAbilities.executeAbility(...)` with a valid
  recommendation payload matching the panel request shape. Do not use an empty
  `{}` probe, because Gutenberg's strict schema validation can confound this
  test.
- Confirm the call resolves without requiring the command palette to be opened
  first.
- Run a normal "Get Suggestion" through the panel. Confirm the primary
  `store/abilities-client.js` to REST path still works.

## D. Pattern Inserter

**Provenance:** the inserter DOM evolved across both skipped releases: 23.2
`#77698` (inserter search input made sticky while scrolling) and 23.3 `#77656`
(pattern list-item titles migrated to `Text` from `@wordpress/ui`), `#78568`
(lazy-fetch user pattern categories). The container selectors are expected to
survive, but verify against the live DOM.

- Open the inserter and switch to Patterns.
- Confirm `InserterBadge` mounts.
- Confirm `src/patterns/inserter-dom.js` selectors still resolve the inserter
  panel content, content menu, and search input, including with the search
  input in its new sticky-while-scrolling position (`#77698`).
- Confirm recommendations populate.
- Insert a recommended pattern. Confirm insertion lands at the intended
  location and does not create a null-root orphan.

## E. Style Book And Global Styles DOM

- Open the Site Editor.
- In Styles, confirm `GlobalStylesRecommender` mounts.
- Fetch, preview, apply, and undo a Global Styles operation.
- Open Style Book.
- Confirm `StyleBookRecommender` mounts through `src/style-book/dom.js`.
- Confirm the `.edit-site-global-styles-screen-style-book` and
  `.editor-style-book__iframe` locators still resolve.
- If the admin-bar experiment is enabled, confirm it does not shift the mount
  node or break iframe detection.

## F. Connectors Graduation And Chat

- Open Settings > Connectors. Confirm the screen is present and functional in
  the WordPress 7.0 compatibility runtime.
- Confirm chat-backed Flavor Agent surfaces return live results through an
  approved Connector.
- Revoke Connector approval.
- Confirm affected surfaces degrade gracefully rather than crashing. With the
  connector fully revoked, FA preflight (`SurfaceCapabilities`) disables the
  surface first; expect `block_backend_unconfigured` /
  `plugin_provider_unconfigured` preflight states and a
  `missing_text_generation_provider` ability error, not
  `wpai_connector_not_approved`. That path is the HTTP-layer fallback for the
  narrower case where a call passes preflight but is blocked at the connector
  layer; its handler is unit-covered in
  `src/store/__tests__/request-error-details.test.js`, so it need not be forced
  through this runtime scenario.

## G. Low-Risk UI Watch Items

- Confirm Template and Template-Part recommender tooltips position and dismiss
  correctly under the current overlay slot behavior.
- Confirm Dimensions chip placement still reads sensibly after Layout moves
  near the styles tab.
- Open Settings > AI Activity. Confirm DataViews renders and first-click row
  selection works.
