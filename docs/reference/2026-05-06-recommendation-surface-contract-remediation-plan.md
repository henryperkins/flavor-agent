# Recommendation Surface Contract Remediation Closeout

**Status:** Implemented in the current uncommitted changes; final verifier evidence is recorded below.

**Goal:** Document the completed recommendation-surface contract cleanup so future readers can distinguish resolved implementation work from remaining verification gates.

**Architecture:** Pattern recommendations render only in Flavor Agent's local inserter shelf and do not register, reorder, or mutate native Gutenberg pattern categories. Block structural operations use the top-level target contract (`targetClientId`, `targetSignature`, `targetSurface`, `targetType`) across JavaScript validation, PHP validation, and LLM response sanitization.

**Tech Stack:** WordPress plugin PHP, PHPUnit, Gutenberg editor JavaScript, `@wordpress/scripts` Jest, Flavor Agent store/context utilities, repo-native build and verifier scripts.

---

## Closed Findings

- [x] Removed native `recommended` block pattern category registration and native pattern category reorder behavior from `flavor-agent.php`.
- [x] Added lifecycle coverage proving Flavor Agent does not register or reorder native Gutenberg pattern categories for pattern recommendations.
- [x] Canonicalized JS block operation validation to top-level target fields only.
- [x] Added JS, PHP validator, and LLM sanitizer coverage for nested target-only operation rejection.
- [x] Documented the local-shelf-only pattern recommendation contract in contributor-facing docs.
- [x] Kept the local-shelf-only contract out of persistent editor UI copy.

## File Responsibility Map

- `flavor-agent.php`: Owns plugin bootstrap hooks and no longer mutates native pattern categories.
- `tests/phpunit/PluginLifecycleTest.php`: Pins bootstrap behavior against native pattern category registration or reorder regressions.
- `src/utils/block-operation-catalog.js`: Owns client-side canonical block operation target normalization.
- `src/utils/__tests__/block-operation-catalog.test.js`: Pins nested target-only rejection and top-level target acceptance in JavaScript.
- `tests/phpunit/BlockOperationValidatorTest.php`: Pins server-side canonical target validation.
- `tests/phpunit/PromptRulesTest.php`: Pins LLM response normalization so nested `target` payloads do not leak into executable operation proposals.
- `src/patterns/PatternRecommender.js`: Renders the Flavor Agent local inserter shelf without exposing implementation details about Gutenberg registry mutation in persistent UI copy.
- `src/patterns/__tests__/PatternRecommender.test.js`: Pins that the local shelf still renders recommendations while avoiding persistent native-registry implementation language.
- `docs/reference/recommendation-ui-consistency.md`: Records the local-shelf-only pattern recommendation contract for contributors.

## Completed Work 1: Removed Native Pattern Category Mutation

Flavor Agent pattern recommendations now stay out of Gutenberg's native pattern category registry. The plugin no longer registers a native `recommended` block pattern category and no longer reorders native pattern category settings.

Lifecycle coverage pins both sides of the contract:

- `tests/phpunit/PluginLifecycleTest.php` asserts `recommended` is not registered as a native block pattern category on `init`.
- `tests/phpunit/PluginLifecycleTest.php` asserts `block_editor_settings_all` leaves native `blockPatternCategories` settings unchanged.
- `docs/reference/recommendation-ui-consistency.md` records that the pattern inserter affordance is an injected local shelf and does not register, reorder, or mutate Gutenberg native pattern categories.

## Completed Work 2: Canonicalized Block Operation Targets To Top-Level Fields

Client and server validation now share one block operation target shape. Executable block operations must provide target metadata through top-level fields:

- `targetClientId`
- `targetSignature`
- `targetSurface`
- `targetType`

Nested-only payloads such as `operation.target.clientId`, `operation.target.signature`, `operation.target.surface`, and `operation.target.type` are rejected instead of being repaired on the JavaScript side. This keeps JavaScript validation, PHP validation, and LLM response sanitization aligned.

Coverage pins the canonical shape:

- `src/utils/__tests__/block-operation-catalog.test.js` rejects nested target-only operations in the client validator.
- `tests/phpunit/BlockOperationValidatorTest.php` rejects the same canonical violation in PHP.
- `tests/phpunit/PromptRulesTest.php` verifies LLM response normalization drops nested `target` payloads instead of leaking them into executable operation proposals.

## Completed Work 3: Removed Persistent Shelf Implementation Copy

The local pattern shelf still labels the surface as `Flavor Agent`, shows the recommendation count, displays matched pattern cards, and uses core insertion APIs. It no longer renders persistent copy explaining that the shelf does not mutate Gutenberg's native pattern registry.

That implementation contract remains in contributor-facing docs instead of the editor UI:

- `docs/reference/recommendation-ui-consistency.md`
- `docs/FEATURE_SURFACE_MATRIX.md`

## Hidden-Test And Cross-Surface Risks

- Hidden tests may assert that Flavor Agent does not register `recommended` as a native pattern category, does not reorder native category settings, and keeps `PatternRecommender` as the only recommendation shelf insertion affordance.
- Hidden parity tests may feed the same nested-target operation payload to JS and PHP validators. The desired result is rejection when `targetClientId` is missing at the top level.
- Block structural actions must still accept the existing top-level schema used by `Prompt::block_operation_schema()`, `Prompt::sanitize_block_operation_proposals()`, and `BlockOperationValidator::validate_sequence()`.
- Pattern setting compatibility helpers should continue to read stable and experimental WordPress settings keys; only native category mutation is removed.
- Navigation recommendations are a separate advisory surface and should not be affected by the block operation target canonicalization.

## Closeout Evidence

- [x] `flavor-agent.php` contains no `register_block_pattern_category( 'recommended', ... )` call and no `block_editor_settings_all` category reorder filter for pattern categories.
- [x] Pattern recommendations still render through `src/patterns/PatternRecommender.js` and `src/patterns/InserterBadge.js`, using current `visiblePatternNames`, allowed patterns, insertability filtering, and core `insertBlocks()`.
- [x] `src/utils/block-operation-catalog.js` reads operation target metadata only from top-level fields.
- [x] JS and PHP tests cover nested target-only rejection and top-level operation acceptance.
- [x] The pattern shelf no longer exposes persistent `native pattern registry` or `Gutenberg` implementation copy.
- [x] Final local verification has passed and exact command outcomes are recorded below.

## Verification Commands

- [x] `npm run test:unit -- --runTestsByPath src/patterns/__tests__/PatternRecommender.test.js`
  - Result: PASS, 1 suite / 24 tests.
- [x] `npm run test:unit -- --runTestsByPath src/components/__tests__/InlineActionFeedback.test.js src/components/__tests__/StaleResultBanner.test.js src/style-book/__tests__/dom.test.js src/utils/__tests__/block-operation-catalog.test.js`
  - Result: PASS, 4 suites / 65 tests.
- [x] `composer run test:php -- tests/phpunit/PatternIndexTest.php tests/phpunit/InfraAbilitiesTest.php tests/phpunit/EditorSurfaceCapabilitiesTest.php tests/phpunit/PluginLifecycleTest.php tests/phpunit/PromptRulesTest.php tests/phpunit/BlockOperationValidatorTest.php tests/phpunit/SettingsTest.php`
  - Result: PASS, 36 tests / 255 assertions.
- [x] `npm run lint:js`
  - Result: PASS after applying scoped repo-native lint fixes to `src/patterns/__tests__/PatternRecommender.test.js`, `src/admin/__tests__/activity-log.test.js`, and `src/components/__tests__/AIActivitySection.test.js`.
- [x] `npm run test:unit -- --runTestsByPath src/patterns/__tests__/PatternRecommender.test.js src/admin/__tests__/activity-log.test.js src/components/__tests__/AIActivitySection.test.js`
  - Result: PASS after lint fixes, 3 suites / 57 tests.
- [x] `npm run build`
  - Result: PASS with existing webpack asset-size warnings.
- [x] `npm run check:docs`
  - Result: PASS after final verification evidence was recorded.
- [x] `npm run verify -- --skip-e2e`
  - Result: PASS, `VERIFY_RESULT={"status":"pass","summaryPath":"output/verify/summary.json","counts":{"total":9,"passed":6,"failed":0,"skipped":3}}`.
