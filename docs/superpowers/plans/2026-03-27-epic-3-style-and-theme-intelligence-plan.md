# Epic 3 Style and Theme Intelligence Closure Record

> **Historical note:** The first bounded Epic 3 slice is already implemented in the current tree. Epic 3 closeout was verified on 2026-04-03, and this document now serves as the closure record plus bounded follow-up list.

**Goal:** Record the evidence that closed Epic 3 and keep the remaining style backlog explicitly bounded.

## Delivered Epic 3 Slice

The current repo already ships the first bounded Epic 3 milestone.

1. **Dedicated style contract**
   - `inc/Abilities/StyleAbilities.php`
   - `inc/LLM/StylePrompt.php`
   - `POST /flavor-agent/v1/recommend-style`
   - `flavor-agent/recommend-style`
2. **Native style surfaces**
   - `src/global-styles/GlobalStylesRecommender.js`
   - `src/style-book/StyleBookRecommender.js`
   - Global Styles and Style Book sidebar mounts with document-panel fallbacks
   - Shared review/status/history model reused from Epic 2
3. **Deterministic style execution and undo**
   - `src/utils/style-operations.js`
   - store-backed apply/undo flow in `src/store/index.js`
   - `global-styles` and `style-book` activity scopes in both client and server activity layers
4. **Shared readiness and capability parity**
   - `inc/Abilities/SurfaceCapabilities.php`
   - localized `globalStyles` and `styleBook` capability keys
   - `flavor-agent/check-status` parity with first-party surface bootstrapping
5. **Theme-safe style constraints**
   - preset-backed and supported-path validation
   - registered theme variation selection
   - explicit rejection of raw CSS and unsupported controls
6. **Documentation and test coverage already in tree**
   - `docs/features/style-and-theme-intelligence.md`
   - `docs/reference/abilities-and-routes.md`
   - PHPUnit coverage for style contracts, readiness, and activity wiring
   - JS coverage for token diagnostics, Global Styles and Style Book UI behavior, style operations, and activity history
   - WP 7.0 smoke coverage for the executable Global Styles preview/apply/undo path

## What This Document Tracks Now

This file is now for:

1. the closure evidence that made Epic 3 fully closed on 2026-04-03
2. explicitly deferred follow-ups that should not be mistaken for missing shipped work
3. verification expectations for future refresh passes

## Closure Evidence

- [x] **Milestone freshness docs aligned**
  - `STATUS.md`, `docs/2026-03-25-roadmap-aligned-execution-plan.md`, and the related architecture/feature docs now describe the shipped Global Styles and Style Book surfaces as current baseline.
- [x] **Fresh WP 7.0 browser proof recorded**
  - `npm run test:e2e:wp70 -- --reporter=line -g "global styles surface previews, applies, and undoes executable recommendations"` passed on 2026-04-03 and is recorded in `STATUS.md`.
- [x] **Deferred work kept bounded**
  - Deferred style follow-ups remain documented as later bounded work rather than being folded back into the shipped Epic 3 slice.

## Remaining Refresh Tasks

- [ ] **Swap the WP 7.0 harness to the stable image once available**
  - Replace the beta-tagged Docker image with the official stable WordPress 7.0 image.
  - Re-audit the Global Styles surface against the stable runtime after that image swap.

## Explicit Deferrals

These items are **not** missing pieces of the shipped Epic 3 slice:

1. `customCSS` recommendation generation
2. width/height preset transforms
3. pseudo-element-aware token extraction beyond the current diagnostics/evaluation boundary
4. deeper second-stage Style Book expansion beyond the shipped bounded per-block surface
5. first-party client adoption of `@wordpress/core-abilities`

Any of those should be tracked as later bounded follow-up work, not folded back into the already-shipped style milestone by implication.

## Evidence In Tree

### Contract and UI

- `inc/Abilities/StyleAbilities.php`
- `inc/LLM/StylePrompt.php`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/Registration.php`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/style-book/dom.js`
- `src/utils/style-operations.js`

### Readiness, Activity, and Undo

- `inc/Abilities/SurfaceCapabilities.php`
- `src/utils/capability-flags.js`
- `src/store/index.js`
- `src/store/activity-history.js`
- `src/components/AIActivitySection.js`
- `src/components/ActivitySessionBootstrap.js`
- `inc/Activity/Serializer.php`
- `inc/Activity/Permissions.php`
- `inc/Activity/Repository.php`
- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`

### Current Supporting Docs

- `docs/features/style-and-theme-intelligence.md`
- `docs/reference/abilities-and-routes.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/SOURCE_OF_TRUTH.md`

### Tests Covering The Shipped Slice

PHP:

- `tests/phpunit/StyleAbilitiesTest.php`
- `tests/phpunit/StylePromptTest.php`
- `tests/phpunit/InfraAbilitiesTest.php`
- `tests/phpunit/RegistrationTest.php`
- `tests/phpunit/AgentControllerTest.php`
- `tests/phpunit/ServerCollectorTest.php`
- `tests/phpunit/EditorSurfaceCapabilitiesTest.php`
- `tests/phpunit/ActivityPermissionsTest.php`
- `tests/phpunit/ActivityRepositoryTest.php`

JS:

- `src/context/__tests__/collector.test.js`
- `src/context/__tests__/theme-tokens.test.js`
- `src/inspector/__tests__/StylesRecommendations.test.js`
- `src/inspector/__tests__/SettingsRecommendations.test.js`
- `src/global-styles/__tests__/GlobalStylesRecommender.test.js`
- `src/style-book/__tests__/StyleBookRecommender.test.js`
- `src/utils/__tests__/style-operations.test.js`
- `src/store/__tests__/activity-history.test.js`
- `src/store/__tests__/activity-history-state.test.js`
- `src/store/__tests__/store-actions.test.js`
- `src/components/__tests__/ActivitySessionBootstrap.test.js`
- `src/components/__tests__/AIActivitySection.test.js`
- `src/admin/__tests__/activity-log.test.js`
- `src/admin/__tests__/activity-log-utils.test.js`

Browser:

- `tests/e2e/flavor-agent.smoke.spec.js` includes `@wp70-site-editor global styles surface previews, applies, and undoes executable recommendations`

## Remaining Follow-Up Backlog After Closeout

1. Explore deeper second-stage Style Book expansion only if it can share the current style contract and avoid duplicating the shipped style-surface UI logic.
2. Revisit width/height preset transforms only when stable preset data and review semantics are good enough to keep the executable contract deterministic.
3. Revisit pseudo-element-aware extraction only when it materially improves recommendation quality without widening the shipped apply path.
4. Keep block-side style improvements bounded to supported tools and theme-safe values rather than letting them drift into a second site-level style product surface.

## Definition Of Done For Epic 3 Closeout

Epic 3 was treated as fully closed on 2026-04-03 because all of the following are now true:

1. Planning docs and `STATUS.md` no longer describe shipped style work as pending implementation.
2. The focused WP 7.0 Global Styles smoke was rerun on a Docker-capable host and recorded where milestone verification is tracked.
3. Deferred style follow-ups remain documented as deferred rather than implied shipping gaps.
4. Future planning work starts from Epic 4 / Epic 5 and later bounded style follow-ups, not from re-implementing the already-shipped Global Styles and Style Book contract.
