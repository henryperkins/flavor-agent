# Gutenberg 23.3 Nightly Validation - 2026-06-05

Run time: 2026-06-05T10:39:35-05:00

Scope: current Flavor Agent workspace against
`docs/reference/gutenberg-23-3-nightly-validation-checklist.md`, after
checking the Gutenberg 23.3 release notes and related GitHub release/PR context.

## Result

Status: blocked / incomplete. The checklist was updated for current 23.3 patch
evidence, and affected Flavor Agent code paths were inspected statically. Runtime
validation could not be executed because the local shell does not have a usable
Node/npm, Composer/PHP, Docker, or WordPress runtime, and `build/`,
`node_modules/`, and `vendor/` are missing.

No Flavor Agent release-risk bug was found by static inspection alone. This is
not release evidence for Gutenberg 23.3 runtime compatibility because the editor
and WordPress stack never became available.

## Sources Consulted

- Make/Core release post:
  https://make.wordpress.org/core/2026/06/03/whats-new-in-gutenberg-23-3-03-jun/
- Gutenberg 23.3.0 release:
  https://github.com/WordPress/gutenberg/releases/tag/v23.3.0
- Gutenberg 23.3.1 release:
  https://github.com/WordPress/gutenberg/releases/tag/v23.3.1
- Gutenberg 23.3.2 release:
  https://github.com/WordPress/gutenberg/releases/tag/v23.3.2
- React 19 revert:
  https://github.com/WordPress/gutenberg/pull/78940
- React 19 compatibility follow-ups:
  https://github.com/WordPress/gutenberg/pull/78899,
  https://github.com/WordPress/gutenberg/pull/78923,
  https://github.com/WordPress/gutenberg/pull/78917
- React 19 upgrade source from 23.3.0:
  https://github.com/WordPress/gutenberg/pull/61521
- Style-state and responsive block instance context:
  https://github.com/WordPress/gutenberg/pull/77513,
  https://github.com/WordPress/gutenberg/pull/78000,
  https://github.com/WordPress/gutenberg/pull/76491,
  https://github.com/WordPress/gutenberg/pull/78230,
  https://github.com/WordPress/gutenberg/pull/78280,
  https://github.com/WordPress/gutenberg/pull/78384
- Pattern inserter context:
  https://github.com/WordPress/gutenberg/pull/77698,
  https://github.com/WordPress/gutenberg/pull/77656,
  https://github.com/WordPress/gutenberg/pull/78568
- Abilities bridge and component/UI watch context:
  https://github.com/WordPress/gutenberg/pull/78316,
  https://github.com/WordPress/gutenberg/pull/78228,
  https://github.com/WordPress/gutenberg/pull/78470,
  https://github.com/WordPress/gutenberg/pull/78466,
  https://github.com/WordPress/gutenberg/pull/78411,
  https://github.com/WordPress/gutenberg/pull/75204

## Checklist Maintenance

Changed `docs/reference/gutenberg-23-3-nightly-validation-checklist.md` before
validation:

- Revised the checklist scope from a single React 19 nightly pass to the
  Gutenberg 23.3 release line.
- Changed the active Gutenberg precondition from fixed `23.3.0` to exact
  `23.3.x` patch-level recording.
- Added patch-aware React guidance: 23.3.0 shipped React 19, 23.3.1 added
  compatibility polyfills, and 23.3.2 reverted the React 19 upgrade.
- Clarified that `build/*` must be fresh for the current dependency and
  Gutenberg patch-level selection.
- Clarified the style-state experiment precondition because single-block
  pseudo-state support may not be experiment-gated in every 23.3 build.
- Normalized two non-ASCII dash artifacts in checklist prose while editing the
  same file.

No existing runtime check was removed. Items that may be unavailable in a given
23.3 patch level were kept as actionable checks with not-testable guidance.

`npm run check:docs` was required because the checklist changed. It was
attempted and failed immediately because `npm` is unavailable in this shell.

## Environment And Command Evidence

- `git status --short`: initially clean; after maintenance, only checklist and
  this validation artifact were changed.
- `Get-Command node -All`: only the Codex app embedded Node was found at
  `C:\Program Files\WindowsApps\OpenAI.CodexBeta_26.527.7698.0_x64__2p2nqsd0c76g0\app\resources\node.exe`.
- `node -v`: failed with `Access is denied`.
- `npm -v`: failed, `npm` is not recognized.
- `composer --version`: failed, `composer` is not recognized.
- `php --version`: failed, `php` is not recognized.
- `docker --version`: failed, `docker` is not recognized.
- `bash --version`: WSL has no installed distributions.
- `npm run wp:browser-url`: failed, `npm` is not recognized.
- `node scripts\docker-compose.js ps`: failed with `Access is denied`.
- `npm run check:docs`: failed, `npm` is not recognized.
- `git diff --check`: passed with exit code 0; Git also printed its usual CRLF
  warning for the edited checklist.
- `Test-Path node_modules`: false.
- `Test-Path vendor`: false.
- `Get-ChildItem build`: build directory is missing.

No browser flows were attempted because no local WordPress URL or runtime could
be resolved.

## Static Code Inspection

The following affected Flavor Agent paths were inspected for current coverage
and likely 23.3 impact:

- Runtime gate: `inc/AI/FeatureBootstrap.php`.
- Abilities bridge: `assets/abilities-bridge.js`,
  `src/store/abilities-client.js`,
  `src/store/__tests__/abilities-bridge.test.js`,
  `src/store/__tests__/abilities-client.test.js`.
- Pattern inserter DOM: `src/patterns/inserter-dom.js`,
  `src/patterns/InserterBadge.js`,
  `src/patterns/__tests__/inserter-dom.test.js`,
  `src/patterns/__tests__/compat.test.js`,
  `src/patterns/__tests__/InserterBadge.test.js`.
- Style Book and Global Styles DOM: `src/style-book/dom.js`,
  `src/style-book/StyleBookRecommender.js`,
  `src/global-styles/GlobalStylesRecommender.js`,
  `src/style-book/__tests__/dom.test.js`,
  `src/global-styles/__tests__/GlobalStylesRecommender.test.js`,
  `src/style-book/__tests__/StyleBookRecommender.test.js`.
- Connector and preflight behavior: `inc/Abilities/SurfaceCapabilities.php`,
  `inc/Abilities/InfraAbilities.php`, `inc/LLM/WordPressAIClient.php`,
  `src/store/request-error-details.js`,
  `tests/phpunit/WordPressAIClientTest.php`,
  `tests/phpunit/InfraAbilitiesTest.php`,
  `tests/phpunit/EditorSurfaceCapabilitiesTest.php`,
  `src/store/__tests__/request-error-details.test.js`.
- Activity, DataViews, and undo toast behavior:
  `src/admin/activity-log.js`, `src/admin/__tests__/activity-log.test.js`,
  `src/components/ToastRegion.js`,
  `src/components/__tests__/ToastRegion.test.js`,
  `src/store/activity-undo.js`, `src/store/index.js`.

Static inspection notes:

- The abilities bridge exposes `window.flavorAgentAbilities` and has a REST
  fallback path, with dedicated JS tests present.
- Pattern inserter selectors include panel content, menu, search input, and
  toggle discovery helpers, with selector-focused tests present.
- Style Book and Global Styles code has iframe/sidebar mount discovery helpers
  and component tests present.
- Connector preflight states include `block_backend_unconfigured` and
  `plugin_provider_unconfigured`; the narrower HTTP-layer connector approval
  fallback is handled by `request-error-details.js` and covered by JS tests.
- Activity log uses DataViews and has row-selection test coverage present.
- No static path was found that directly validates Gutenberg 23.3 state-scoped
  style preservation; the current apply/undo path appears to write block
  attributes and preserve snapshots, so the state-style section remains a
  required runtime check.

## Itemized Checklist Results

### Preconditions

- WordPress nightly/trunk is installed: not-testable. Docker, npm scripts, WP
  CLI, and local runtime URL are unavailable.
- Gutenberg `23.3.x` is active and exact version recorded: not-testable. No
  WordPress runtime is reachable.
- Required companion plugins are active: not-testable. No WordPress runtime is
  reachable.
- Fresh `build/*` after current dependency and Gutenberg patch-level selection:
  fail. `build/` is missing and `npm run build` cannot run because `npm` is
  unavailable.
- `FeatureBootstrap::editor_runtime_available()` is true: not-testable. The PHP
  runtime and WordPress function environment are unavailable.
- At least one text-generation Connector is approved: not-testable. No
  WordPress runtime is reachable.
- Style-state or responsive styles experiment/control availability: not-testable.
  No Gutenberg settings UI is reachable.
- DevTools console and Network panel open during flows: not-testable. No browser
  flow could start.

### A. Patch-Aware React Runtime Smoke

- Record active Gutenberg and React / ReactDOM versions: not-testable. No editor
  runtime is reachable.
- Open post editor and check React/Flavor Agent console errors: not-testable. No
  editor runtime is reachable.
- Select a block and confirm Flavor Agent panel renders: not-testable. No editor
  runtime is reachable.
- Type a prompt and fetch a suggestion: not-testable. No editor runtime or
  approved connector is reachable.
- Switch selected blocks and confirm prompt reset exactly once: not-testable. No
  editor runtime is reachable.
- Watch Network for duplicate ability/activity calls: not-testable. No browser
  flow could start.
- Apply a suggestion and confirm applied pill: not-testable. No editor runtime
  is reachable.
- Undo via toast and verify `mod+alt+shift+u`: not-testable. Static toast and
  shortcut code/tests exist, but the runtime shortcut could not be exercised.
- Cross-surface fetch/apply/undo/activity rows: not-testable. No executable
  surfaces could be opened.

### B. Style-State Inspector Interaction

- Select a state-capable block: not-testable. No editor runtime is reachable.
- Enter a pseudo-state or viewport state: not-testable. No editor runtime is
  reachable.
- Observe delegated chip groups: not-testable. No editor runtime is reachable.
- Confirm coherent chip rendering/hiding and no console/layout errors:
  not-testable. No editor runtime is reachable.
- Apply a style suggestion with non-base state selected: not-testable. No editor
  runtime or connector is reachable.
- Confirm base `attributes.style.*` write, state-scoped style preservation, and
  undo restore: not-testable. No editor runtime is reachable.
- Log state-applicable suggestions that silently target base styles:
  not-testable. No state-style runtime flow could be exercised.

### C. Abilities Bridge Defer

- Fresh editor load without opening command palette: not-testable. No editor
  runtime is reachable.
- Call `window.flavorAgentAbilities.executeAbility(...)` with a valid payload:
  not-testable. Static bridge/fallback code exists, but the browser global could
  not be exercised.
- Confirm call resolves without command palette: not-testable. No editor runtime
  is reachable.
- Run panel "Get Suggestion" and confirm store-to-REST path works:
  not-testable. No editor runtime or connector is reachable.

### D. Pattern Inserter

- Open inserter and switch to Patterns: not-testable. No editor runtime is
  reachable.
- Confirm `InserterBadge` mounts: not-testable. No editor runtime is reachable.
- Confirm `src/patterns/inserter-dom.js` selectors resolve panel content, menu,
  and sticky search input: not-testable. Static selectors/tests exist, but no
  live DOM is reachable.
- Confirm recommendations populate: not-testable. No editor runtime or connector
  is reachable.
- Insert recommended pattern and check target location/null-root orphan:
  not-testable. No editor runtime is reachable.

### E. Style Book And Global Styles DOM

- Open the Site Editor: not-testable. No WordPress runtime is reachable.
- Confirm `GlobalStylesRecommender` mounts: not-testable. No Site Editor runtime
  is reachable.
- Fetch, preview, apply, and undo Global Styles: not-testable. No Site Editor
  runtime or connector is reachable.
- Open Style Book: not-testable. No Site Editor runtime is reachable.
- Confirm `StyleBookRecommender` mounts through `src/style-book/dom.js`:
  not-testable. Static mount/iframe helpers and tests exist, but no live DOM is
  reachable.
- Confirm `.edit-site-global-styles-screen-style-book` and
  `.editor-style-book__iframe` locators resolve: not-testable. No live DOM is
  reachable.
- Admin-bar experiment mount/iframe interaction: not-testable. No Site Editor
  runtime or experiment settings are reachable.

### F. Connectors Graduation And Chat

- Open Settings > Connectors: not-testable. No WordPress runtime is reachable.
- Confirm chat-backed Flavor Agent surfaces return through approved Connector:
  not-testable. No WordPress runtime or approved connector is reachable.
- Revoke Connector approval: not-testable. No WordPress runtime is reachable.
- Confirm graceful degradation and preflight/error behavior: not-testable.
  Static preflight and error-normalization code/tests exist, but runtime
  connector state could not be exercised.

### G. Low-Risk UI Watch Items

- Template and Template-Part tooltip positioning/dismissal under overlay slot:
  not-testable. No editor runtime is reachable.
- Dimensions chip placement after Layout moves near Styles tab: not-testable. No
  editor runtime is reachable.
- Settings > AI Activity DataViews render and first-click row selection:
  not-testable. Static DataViews and row-selection test coverage exists, but no
  wp-admin runtime is reachable.

## Follow-Up Commands

When a representative local stack is available, run at minimum:

1. `npm ci`
2. `composer install`
3. `npm run build`
4. `npm run check:docs`
5. `npm run verify -- --skip-e2e`
6. `npm run wp:start`
7. Browser runtime pass through the checklist with Gutenberg `23.3.x` exact
   patch version recorded.
