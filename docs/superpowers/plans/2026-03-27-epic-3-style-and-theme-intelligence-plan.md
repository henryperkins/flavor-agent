# Epic 3 Style and Theme Intelligence Closeout Plan

> **Historical note:** The first bounded Epic 3 slice is already implemented in the current tree. This document now tracks closeout and bounded follow-up work only; it no longer treats the shipped style contract, Global Styles surface, or `global-styles` activity wiring as pending implementation.

**Goal:** Close out Epic 3 by making the planning docs and verification history match the shipped code, while keeping the remaining style backlog explicitly bounded.

## Delivered Epic 3 Slice

The current repo already ships the first bounded Epic 3 milestone.

1. **Dedicated style contract**
   - `inc/Abilities/StyleAbilities.php`
   - `inc/LLM/StylePrompt.php`
   - `POST /flavor-agent/v1/recommend-style`
   - `flavor-agent/recommend-style`
2. **Native site-level style surface**
   - `src/global-styles/GlobalStylesRecommender.js`
   - Global Styles sidebar mount with document-panel fallback
   - Shared review/status/history model reused from Epic 2
3. **Deterministic style execution and undo**
   - `src/utils/style-operations.js`
   - store-backed apply/undo flow in `src/store/index.js`
   - `global-styles` activity scope in both client and server activity layers
4. **Shared readiness and capability parity**
   - `inc/Abilities/SurfaceCapabilities.php`
   - localized `globalStyles` capability key
   - `flavor-agent/check-status` parity with first-party surface bootstrapping
5. **Theme-safe style constraints**
   - preset-backed and supported-path validation
   - registered theme variation selection
   - explicit rejection of raw CSS and unsupported controls
6. **Documentation and test coverage already in tree**
   - `docs/features/style-and-theme-intelligence.md`
   - `docs/reference/abilities-and-routes.md`
   - PHPUnit coverage for style contracts, readiness, and activity wiring
   - JS coverage for token diagnostics, Global Styles UI behavior, style operations, and activity history
   - WP 7.0 smoke coverage for preview/apply/undo

## What This Document Tracks Now

This file is now for:

1. Epic 3 closeout tasks still needed to call the milestone fully closed
2. Explicitly deferred follow-ups that should not be mistaken for missing shipped work
3. Verification expectations for future refresh passes

## Closeout Tasks

- [ ] **Refresh milestone freshness docs**
  - Update `STATUS.md` so it lists Global Styles recommendations, `flavor-agent/recommend-style`, and the current Epic 3 verification slice.
  - Keep `docs/2026-03-25-roadmap-aligned-execution-plan.md` and this file aligned with the shipped state.

- [ ] **Refresh the WP 7.0 browser proof on a Docker-capable host**
  - Re-run the focused smoke:
    - `npm run test:e2e:wp70 -- --reporter=line -g "global styles surface previews, applies, and undoes executable recommendations"`
  - Record the pass/failure result or host limitation in `STATUS.md`.

- [ ] **Swap the WP 7.0 harness to the stable image once available**
  - Replace the beta-tagged Docker image with the official stable WordPress 7.0 image.
  - Re-audit the Global Styles surface against the stable runtime after that image swap.

## Explicit Deferrals

These items are **not** missing pieces of the shipped Epic 3 slice:

1. `customCSS` recommendation generation
2. width/height preset transforms
3. pseudo-element-aware token extraction beyond the current diagnostics/evaluation boundary
4. a second Style Book shell layered on top of the shipped Global Styles surface
5. first-party client adoption of `@wordpress/core-abilities`

Any of those should be tracked as later bounded follow-up work, not folded back into the already-shipped Global Styles milestone by implication.

## Evidence In Tree

### Contract and UI

- `inc/Abilities/StyleAbilities.php`
- `inc/LLM/StylePrompt.php`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/Registration.php`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/utils/style-operations.js`

### Readiness, Activity, and Undo

- `inc/Abilities/SurfaceCapabilities.php`
- `src/utils/capability-flags.js`
- `src/store/index.js`
- `src/store/activity-history.js`
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

1. Explore Style Book expansion only if it can share the current style contract and avoid duplicating Global Styles UI logic.
2. Revisit width/height preset transforms only when stable preset data and review semantics are good enough to keep the executable contract deterministic.
3. Revisit pseudo-element-aware extraction only when it materially improves recommendation quality without widening the shipped apply path.
4. Keep block-side style improvements bounded to supported tools and theme-safe values rather than letting them drift into a second site-level style product surface.

## Definition Of Done For Epic 3 Closeout

Epic 3 can be treated as fully closed when all of the following are true:

1. Planning docs and `STATUS.md` no longer describe shipped style work as pending implementation.
2. The focused WP 7.0 Global Styles smoke is rerun on a Docker-capable host, or the current host limitation is explicitly recorded where milestone verification is tracked.
3. Deferred style follow-ups remain documented as deferred rather than implied shipping gaps.
4. Future planning work starts from Epic 4 / Epic 5 and later bounded style follow-ups, not from re-implementing the already-shipped Global Styles contract.
