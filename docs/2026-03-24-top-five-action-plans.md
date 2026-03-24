# Flavor Agent Top Five Action Plans

> Created: 2026-03-24
> Scope: execution plans for the five highest-impact follow-up actions identified in the repository progress assessment
> Baseline: docs drift has been corrected; current repo assessment found passing PHP and JS unit suites, an advisory-only `wp_template_part` surface, and a remaining Playground-based Site Editor browser harness gap

## Planning Principles

1. Preserve the current working block, pattern, template, settings, and docs-grounding surfaces while hardening the weaker ones.
2. Favor deterministic execution and review-confirm-apply flows over open-ended AI mutations.
3. Reuse the current Abilities, REST, `@wordpress/data`, and editor integration architecture instead of introducing parallel orchestration paths.
4. Keep each plan shippable in small PRs with narrow test scopes.
5. Treat WordPress 7.0 verification as a real compatibility requirement, not a docs claim.

## Recommended Delivery Order

1. Resolve the `wp_template_part` surface posture first so the product contract is clear.
2. Add direct backend PHPUnit coverage for pattern recommendation and indexing while the behavior is still fresh from the assessment.
3. Add the missing template context-drift integration test to close the known invalidation gap.
4. Reduce remaining Gutenberg fragility in the hot paths that still depend on experimental or DOM fallbacks.
5. Restore true WordPress 7.0 Site Editor browser verification on top of the hardened runtime.

The browser-verification plan is intentionally last in this sequence because it should validate the hardened runtime, not mask it.

## Plan 1: Finish Or Explicitly De-Scope The Template-Part Surface

### Objective

Resolve the current mismatch between a real shipped `wp_template_part` panel and its advisory-only behavior. The repository should end this work in one of two clear states:

1. a first-class, narrow executable template-part surface with preview/apply/activity/undo, or
2. an explicitly advisory-only surface whose UI and docs no longer imply parity with the executable template compositor.

### Repo-Grounded Starting Point

- `src/template-parts/TemplatePartRecommender.js` is a real document panel, but it uses local `apiFetch` state and offers only focus-block and browse-pattern actions.
- `inc/Abilities/TemplateAbilities.php` and `inc/LLM/TemplatePartPrompt.php` already provide a dedicated ability, prompt, parser, and docs-grounded server path for `recommend_template_part()`.
- `src/store/index.js`, `src/store/activity-history.js`, and `src/utils/template-actions.js` already implement the shared request/apply/undo patterns for block and template flows.
- `docs/wp-template-part-recommendations-plan.md` already records the intended phased shape and can be treated as background design context for this execution plan.

### Decision Checkpoint

Make a deliberate product decision before changing code. The recommended default is to finish the surface, but only if the executor can stay narrow and deterministic.

Choose the executable path if all of these are true:

1. the operation schema can remain limited to validated pattern insertion or similarly safe part-scoped mutations,
2. placement can be anchored to explicit locations such as `start`, `end`, or a validated block path,
3. undo can be implemented without guessing after drift, and
4. the resulting UX is materially better than the current advisory-only panel.

Choose the de-scope path if any of these remain unresolved after a short spike.

### Track A: Finish As A Narrow Executable Surface

#### Workstream 1: Lock The Product And Data Contract

1. Keep the current advisory strengths: block-focus hints and pattern-browse suggestions still matter.
2. Add executable operations only where they are deterministic and fully described by the returned payload.
3. Start with one supported mutation type: safe pattern insertion inside the current template part.
4. Add a dedicated template-part executable contract instead of reusing the current template operation flow unchanged.
5. Do not promise free-form rewrites, subtree merges, destructive deletes, or implicit "edit whatever block is currently selected" behavior.
6. Do not resolve apply targets from whatever insertion point or selected block happens to be live when the user clicks Apply. The operation payload must carry the intended placement explicitly.

Recommended first executable operation shape:

```json
{
  "type": "insert_pattern",
  "patternName": "theme/header-utility-row",
  "placement": "start"
}
```

Required placement rules for the first executable contract:

- `placement` must be explicit even for the first version.
- `placement` starts with `start` and `end` only.
- Apply remains scoped to the current `templatePartRef`; do not infer a target from transient editor selection state.

Optional later-safe placement variants:

- `end`
- `after_block_path` plus an explicit validated `path`

#### Workstream 2: Extend The Server Contract

Primary files:

- `inc/Abilities/TemplateAbilities.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/Context/ServerCollector.php`
- `inc/REST/Agent_Controller.php`

Steps:

1. Extend the template-part REST and ability input contract so the request can carry the exact client context needed for safe execution:
   - `templatePartRef`
   - `prompt`
   - `visiblePatternNames`
   - optional advisory-only fields such as `selectedBlockPath` if they improve prompting later
2. Extend `TemplatePartPrompt::build_system()` and `TemplatePartPrompt::parse_response()` so the response can carry an optional `operations` array while remaining advisory-first.
3. Keep the first executable schema intentionally small:
   - `type: insert_pattern`
   - `patternName`
   - `placement`
   - optional later `path` only when `placement` requires it
4. Validate every executable operation against collected context:
   - pattern names must exist in the candidate pattern list actually available to the current template-part request
   - `placement` values must be from an explicit allowlist
   - any later `path` values must exist in `blockTree`
5. Keep unsupported or ambiguous suggestions advisory-only instead of trying to coerce them into executable operations.
6. Extend `ServerCollector::for_template_part()` only as needed to validate placement targets and pattern visibility; do not broaden it into a raw content transport.
7. Add direct PHPUnit coverage in `tests/phpunit/TemplatePartPromptTest.php` for executable-operation parsing, rejection, and truncation rules.

#### Workstream 3: Move The Client From Local State To The Shared Store

Primary files:

- `src/template-parts/TemplatePartRecommender.js`
- `src/store/index.js`
- `src/store/activity-history.js`

Steps:

1. Replace local request/result state in `TemplatePartRecommender.js` with store-backed request state, selected suggestion state, apply state, and result-reference state.
2. Add template-part-specific selectors and actions rather than overloading the template selectors blindly.
3. Add a dedicated template-part activity surface, or generalize the current template activity model so it can target both `wp_template` and `wp_template_part` cleanly.
4. Reuse the existing activity-session scoping model so `wp_template_part` gets its own session key and persisted recent actions.
5. Keep template and template-part state separate enough that one surface cannot clear or corrupt the other.

#### Workstream 4: Add Deterministic Apply And Undo

Primary files:

- `src/utils/template-actions.js`
- `src/store/index.js`
- `src/components/AIActivitySection.js`

Steps:

1. Introduce explicit helpers for template-part execution and rollback, for example:
   - `applyTemplatePartSuggestionOperations()`
   - `undoTemplatePartSuggestionOperations()`
2. Reuse existing block-tree utilities where possible instead of creating a parallel executor, but do not reuse the current template `insert_pattern` behavior unchanged because it resolves against the live insertion point at apply time.
3. Record enough before/after state to support:
   - inserted block ids
   - target `templatePartRef`
   - insertion root information
   - explicit placement
   - pattern name
   - pre-apply snapshots needed for undo validation
4. Keep the same "only the most recent AI action is auto-undoable" rule unless the broader activity model changes later.
5. Fail closed when drift makes the operation unsafe to reverse.

#### Workstream 5: Update The Panel UX

Primary files:

- `src/template-parts/TemplatePartRecommender.js`
- shared panel styles in `src/`

Steps:

1. Add preview and confirm affordances that mirror the executable template panel where it makes sense.
2. Visually distinguish advisory suggestions from executable suggestions within the same panel.
3. Show the exact target and placement for any executable operation.
4. Add inline success, failure, and undo affordances that match the shared activity UI.

#### Workstream 6: Add Test Coverage

Primary files:

- `tests/phpunit/TemplatePartPromptTest.php`
- `src/store/__tests__/`
- `src/utils/__tests__/template-actions.test.js`
- `tests/e2e/flavor-agent.smoke.spec.js`

Coverage goals:

1. PHP parser tests for executable-operation validation.
2. REST/controller tests for any new template-part input fields and sanitization.
3. JS helper tests for placement-aware apply and undo bookkeeping.
4. Store tests for request, preview, apply, activity, and undo transitions on the template-part surface.
5. A browser smoke flow for a real `wp_template_part` recommendation that previews and applies a safe pattern insertion.

#### Workstream 7: Update Documentation

Primary files:

- `STATUS.md`
- `docs/SOURCE_OF_TRUTH.md`
- `docs/wp-template-part-recommendations-plan.md`
- `docs/2026-03-24-repository-progress-assessment.md`

Update the repo docs so they describe the exact supported template-part operations, the review-confirm-apply model, and the remaining out-of-scope cases.

### Track B: Explicitly De-Scope To Advisory-Only

If the decision checkpoint concludes that a safe executor is not worth the complexity right now, finish the de-scope instead of leaving the surface ambiguous.

Primary files:

- `src/template-parts/TemplatePartRecommender.js`
- `STATUS.md`
- `docs/SOURCE_OF_TRUTH.md`
- `docs/wp-template-part-recommendations-plan.md`
- `docs/2026-03-24-repository-progress-assessment.md`

Steps:

1. Tighten the runtime copy so the panel explicitly says it does not apply changes automatically.
2. Rename any wording that implies parity with the template compositor if needed.
3. Keep focus-block and browse-pattern actions, but do not render preview/apply/undo affordances.
4. Update every top-level doc to describe the surface as advisory-only by design, not as temporarily incomplete parity.
5. Add a regression test proving the panel remains advisory-only and does not surface apply/undo controls.

### Suggested PR Sequence

1. PR 1: product decision plus template-part contract spike
2. PR 2: REST/ability/parser groundwork or de-scope copy/docs
3. PR 3: shared store plus activity-surface refactor if the executable path wins
4. PR 4: executor, undo, and placement-aware coverage
5. PR 5: browser coverage and doc cleanup

### Exit Criteria

Exactly one of these must be true:

1. `wp_template_part` recommendations support preview/apply/activity/undo for a narrow validated operation set, with tests and docs updated.
2. The UI and docs make it unambiguous that `wp_template_part` recommendations are advisory-only, and no top-level document still treats them as a peer to executable template composition.

## Plan 2: Add Direct Backend PHPUnit Coverage For Pattern Recommendation And Indexing

> Status: implemented on 2026-03-24 via `tests/phpunit/PatternAbilitiesTest.php`, `tests/phpunit/PatternIndexTest.php`, and the supporting bootstrap shims in `tests/phpunit/bootstrap.php`. Validation: `vendor/bin/phpunit --filter Pattern` passed (`28` tests, `170` assertions) and `vendor/bin/phpunit` passed (`171` tests, `872` assertions).

### Objective

Bring the pattern recommendation backend up to the same level of direct backend isolation already present for settings, block recommendations, docs grounding, and infra status.

### Repo-Grounded Starting Point

- `inc/Abilities/PatternAbilities.php` is a core runtime path that handles backend-config gating, pattern-index staleness behavior, two-pass Qdrant retrieval, LLM reranking, parsing, and score filtering.
- `inc/Patterns/PatternIndex.php` is the core indexing engine that owns fingerprints, diffing, batching, sync locks, cooldown, and ready/error state transitions.
- `tests/phpunit/` now includes dedicated `PatternAbilitiesTest.php` and `PatternIndexTest.php`; this section remains as the implementation record for the gap that existed before the 2026-03-24 test pass.
- The existing PHPUnit harness already has `WordPressTestState` in `tests/phpunit/bootstrap.php`, plus good examples in `BlockAbilitiesTest.php`, `SettingsTest.php`, and `InfraAbilitiesTest.php`.

### Workstream 1: Harden The PHPUnit Harness For Pattern Tests

Primary files:

- `tests/phpunit/bootstrap.php`
- `tests/phpunit/PatternAbilitiesTest.php`
- `tests/phpunit/PatternIndexTest.php`

Steps:

1. Reuse `WordPressTestState` as the central fake state store for options, scheduled events, transients, and remote responses.
2. Add the missing core shims that `PatternIndex` and `QdrantClient` rely on before writing the new tests:
   - `wp_parse_args`
   - `home_url`
   - `untrailingslashit`
   - `get_current_blog_id`
   - `wp_get_environment_type`
3. Reuse the existing remote GET/POST capture in `WordPressTestState` for Qdrant and OpenAI request assertions instead of adding a parallel fake transport immediately.
4. Extend the harness only where pattern tests truly need it:
   - deterministic provider configuration overrides
   - compact helper fixtures for Qdrant search, scroll, upsert, and delete responses
   - compact helper fixtures for embedding and ranking responses
5. Keep the stubs deterministic and data-oriented so test failures explain behavior, not mock choreography.

### Workstream 2: Add `PatternAbilitiesTest.php`

Primary file:

- `tests/phpunit/PatternAbilitiesTest.php`

Recommended test matrix:

1. `list_patterns()` normalizes object input and forwards category/block/template filters to `ServerCollector::for_patterns()`.
2. `recommend_patterns()` returns `missing_credentials` when provider/Qdrant backends are not configured.
3. An explicitly empty `visiblePatternNames` array short-circuits to an empty recommendations list without hitting embeddings or Qdrant.
4. Index-state handling is correct for each runtime state:
   - `uninitialized` with no usable index returns `index_warming`
   - `indexing` with no usable index returns `index_warming`
   - `stale` with a usable index proceeds and schedules sync for admins
   - `error` with no usable index returns `index_unavailable`
5. Query-string construction includes `postType`, nearby `blockContext`, `templateType`, and trimmed prompt text.
6. Pass A and pass B candidates are merged by `payload.name`, deduped by best score, and filtered by `visiblePatternNames`.
7. Malformed ranking JSON returns `parse_error`.
8. Scores below `0.3` are dropped, recommendations are capped, and results are sorted by score descending.

### Workstream 3: Add `PatternIndexTest.php`

Primary file:

- `tests/phpunit/PatternIndexTest.php`

Recommended test matrix:

1. `compute_fingerprint()` is stable across pattern ordering and changes when metadata or content changes.
2. `pattern_uuid()` is deterministic for a pattern name.
3. `build_embedding_text()` includes title, description, categories, block types, template types, and truncated content in the expected order.
4. `mark_dirty()` correctly chooses `stale` versus `uninitialized` depending on whether a usable index exists.
5. `schedule_sync()` respects:
   - missing backend configuration
   - existing scheduled events
   - cooldown
   - forced scheduling
6. `sync()` no-ops cleanly when the stored fingerprint and backend configuration already match.
7. `sync()` performs a full reindex when there is no usable prior index.
8. `sync()` performs incremental reindexing when only some pattern fingerprints changed.
9. `sync()` deletes removed pattern UUIDs.
10. Remote failures from collection creation, scroll, embedding, upsert, or delete persist the correct error state.
11. Lock contention returns `sync_locked` without doing remote work.

### Workstream 4: Keep The Tests Behavioral, Not Implementation-Fragile

Guidelines:

1. Prefer public methods and state transitions over testing private internals.
2. Assert `WP_Error` codes, persisted state, scheduled hooks, and payload shape rather than incidental intermediate values.
3. Keep fixture patterns small and explicit so fingerprint and diff behavior stays easy to reason about.

### Workstream 5: Tie The Coverage Back To Runtime Risk

Primary files:

- `STATUS.md`
- `docs/2026-03-24-repository-progress-assessment.md`

After the tests land, update the relevant docs so the repo no longer describes pattern backends as comparatively under-isolated.

### Validation

1. `vendor/bin/phpunit --filter Pattern`
2. `vendor/bin/phpunit`

### Exit Criteria

1. `tests/phpunit/PatternAbilitiesTest.php` exists and covers the main success and failure paths in `PatternAbilities`.
2. `tests/phpunit/PatternIndexTest.php` exists and covers fingerprinting, scheduling, diffing, sync, and error handling in `PatternIndex`.
3. The assessment can stop calling pattern backends comparatively under-isolated.

## Plan 3: Add A Panel-Level Component Test For Template Context Drift

### Objective

Add the missing direct panel/component test that proves stale template recommendations are cleared when the live template recommendation context changes, especially when insertion-root scope changes.

### Repo-Grounded Starting Point

- Runtime invalidation is implemented in `src/templates/TemplateRecommender.js` via a `useEffect()` keyed to `templateRef` and `recommendationContextSignature`.
- The signature currently includes `editorSlots` and normalized `visiblePatternNames`.
- `src/templates/__tests__/template-recommender-helpers.test.js` proves normalization and signature stability at the helper level.
- `clearTemplateRecommendations()` resets store-backed request/result/apply state, while the panel keeps the textarea prompt in local component state unless `templateRef` changes.
- There is still no component or store integration test that shows the live panel actually clears stale recommendations when the insertion root changes.

### Recommended Test Shape

The best fit is a component integration test in `src/templates/__tests__/TemplateRecommender.test.js` that mounts the real panel in jsdom with a lightweight registry-backed data harness and drives the relevant selector changes.

Why this shape is preferable:

1. the invalidation logic lives in the panel component, not only in store reducers,
2. the missing behavior depends on `useSelect()` values from `core/edit-site` and `core/block-editor`, and
3. the test needs to prove same-template context drift clears stale recommendations without wiping the local prompt, which a store-only test would not prove.

### Workstream 1: Build A Minimal Rendering Harness

Primary files:

- `src/templates/__tests__/TemplateRecommender.test.js`
- existing test helpers under `src/` if needed

Steps:

1. Mount `TemplateRecommender` in jsdom with mocked `useSelect()` and `useDispatch()` wiring, or a lightweight registry-backed harness if that is cleaner.
2. Prefer the lightweight registry-backed harness as the default so selector changes can be driven through test state, and only fall back to hook mocks if the registry path proves impractical.
3. Stub `PluginDocumentSettingPanel` and other editor components only where necessary to keep the test focused on state transitions.
4. Seed the component with:
   - a stable `templateRef`
   - non-empty `templateRecommendations`
   - a matching `resultRef`
   - a known insertion root and visible-pattern set
   - a pre-filled prompt value so same-template drift can prove prompt preservation

### Workstream 2: Prove Recommendations Clear On Insertion-Root Drift

Core scenario:

1. Render the panel with recommendations already present.
2. Change `getBlockInsertionPoint().rootClientId`.
3. Return a different `visiblePatternNames` set for the new root.
4. Assert that `clearTemplateRecommendations()` is dispatched, the stale recommendation cards disappear, and the pre-filled textarea value stays unchanged.

This is the highest-value missing case because it is the exact gap called out in the assessment.

### Workstream 3: Cover The Important Adjacent Cases

Recommended adjacent assertions:

1. Reordering the same `visiblePatternNames` does not clear recommendations.
2. A `templateRef` change clears recommendations and resets the prompt.
3. A same-template context drift clears recommendations, clears preview/apply state, and does not reset the local prompt.
4. An in-flight request is aborted and cleared when the context changes while loading.

### Workstream 4: Keep The Test Focused On Behavior

Guidelines:

1. Assert user-visible or dispatch-visible behavior, including card disappearance, clear dispatch, and textarea value, not hook implementation details.
2. Keep fixtures tiny: one template ref, a couple of template slots, one insertion-root change.
3. Reuse `buildTemplateRecommendationContextSignature()` fixtures where that reduces noise, but do not stop at helper assertions.

### Workstream 5: Update Assessment And Follow-Up Docs

Primary files:

- `docs/2026-03-24-review-follow-up-plan.md`
- `docs/2026-03-24-repository-progress-assessment.md`

Once the test exists, update the docs that currently call out this exact remaining coverage gap.

### Validation

1. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- TemplateRecommender`
2. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand`

### Exit Criteria

1. A checked-in component test proves the live template panel clears stale recommendations when insertion-root scope changes.
2. The test also proves normalized reordering alone does not trigger a false clear.
3. The assessment can stop describing template context drift coverage as helper-only.

## Plan 4: Restore True WordPress 7.0 Browser Verification For Site Editor Flows

> Status: implemented in code on 2026-03-24 via `playwright.wp70.config.js`, `scripts/wp70-e2e.js`, the repo-local `tests/e2e/wp70-theme/` fixture, and the now-enabled `@wp70-site-editor` refresh/drift tests. Validation still requires `npm run test:e2e:wp70` on a Docker-capable host.

### Objective

Replace the current "credible in code shape but weaker in browser verification" posture with a real WordPress 7.0 Site Editor end-to-end harness that can run the currently skipped refresh and drift undo cases.

### Repo-Grounded Starting Point

- `playwright.config.js` currently launches `@wp-playground/cli` with `--wp=6.9.4`.
- The checked-in comment explicitly says the Playground 7.0 beta editor runtime was breaking before plugin bootstrap.
- `tests/e2e/flavor-agent.smoke.spec.js` still has `test.fixme()` cases for:
  - template undo surviving a Site Editor refresh without drift
  - template undo being disabled after inserted pattern content changes
- The repo already ships a real local Docker WordPress stack via `docker-compose.yml`, `docker/wordpress/Dockerfile`, `.env.example`, and `docs/local-wordpress-ide.md`.
- `package.json` currently exposes a single `test:e2e` entry point for the Playground harness plus raw Docker lifecycle scripts; there is no checked-in `test:e2e:wp70` target yet.
- `scripts/local-wordpress.ps1` installs WordPress, activates the plugin, and sets permalinks, but it does not currently provide Playwright authentication, a pinned block theme, or deterministic Site Editor seed data for browser tests.

### Recommended Harness Strategy

Keep fast Playground smoke coverage for lightweight editor checks if it still provides value, but add a second, non-Playground WordPress 7.0 Site Editor harness for the flows that actually need browser confidence.

This avoids turning one flaky harness into the only truth source.

### Workstream 1: Provision A Pinned WordPress 7.0 Browser Test Stack

Primary files:

- `docker-compose.yml`
- `docker/wordpress/Dockerfile`
- `.env.example`
- bootstrap scripts under `scripts/`

Steps:

1. Introduce a pinned WordPress 7.0 image for browser verification through `WORDPRESS_BASE_IMAGE` or a dedicated e2e override.
2. Add an automated bootstrap path that:
   - starts the stack
   - installs WordPress if needed
   - activates the plugin
   - activates a known block theme for the Site Editor flow
   - seeds deterministic content/template state so the target template is present
3. Add an explicit non-Playground authentication path for Playwright, for example:
   - a setup project that logs in through `/wp-login.php` and writes `storageState`, or
   - an equivalent repo-local bootstrap that produces deterministic authenticated state
4. Prefer a cross-platform Node or shell bootstrap entry point for test automation rather than relying only on the PowerShell helper.
5. Keep the bootstrap reproducible from the repo alone rather than relying on manual dashboard setup.
6. If the official Docker tag lags, pin to a reproducible fallback image or release artifact rather than using an untracked local install.

### Workstream 2: Split Playwright Into Explicit Harnesses

Primary files:

- `playwright.config.js`
- optional additional config such as `playwright.wp70.config.js`
- `package.json`

Recommended shape:

1. Keep a fast Playground project for lightweight smoke coverage if desired.
2. Add a `wp70-site-editor` project or standalone config that targets the Docker-backed WordPress 7.0 environment.
3. Add explicit scripts such as:
   - `npm run test:e2e:playground`
   - `npm run test:e2e:wp70`
4. Make the target harness obvious in docs and CI output.

### Workstream 3: Stabilize Site Editor Test Data And Setup

Primary files:

- `tests/e2e/flavor-agent.smoke.spec.js`
- helper files under `tests/e2e/`
- bootstrap helpers in `scripts/`

Steps:

1. Ensure the tests select a deterministic template and theme state rather than relying on whichever default theme or template the container boots with.
2. Keep welcome-guide dismissal, sidebar opening, and pattern registration helpers centralized.
3. Reset or isolate database state between runs so refresh and drift tests are reproducible.
4. Reuse route mocking for AI responses where that keeps the flow deterministic without weakening the editor mutation coverage.

### Workstream 4: Unskip And Repair The Two `fixme` Cases

Primary file:

- `tests/e2e/flavor-agent.smoke.spec.js`

Steps:

1. Convert the refresh-without-drift case from `test.fixme()` to a real passing test in the WP 7.0 harness.
2. Convert the drifted-content case from `test.fixme()` to a real passing test.
3. It is acceptable for those cases to remain Playground-only `fixme` or project-skipped if Playground stays a quick-smoke harness rather than the source of Site Editor confidence.
4. Keep any harness-specific retries or waits narrow and justified by real editor behavior, not by broad sleeps.

### Workstream 5: Decide The Ongoing Verification Contract

Decide and document which harness proves what:

1. Playground can remain the quick smoke harness if it stays materially faster.
2. The Docker-backed WP 7.0 harness should own Site Editor refresh/drift confidence plus the authenticated Site Editor bootstrap path.
3. If CI coverage is added later, start with the WP 7.0 Site Editor subset rather than the whole browser matrix.

### Workstream 6: Update Docs

Primary files:

- `STATUS.md`
- `docs/SOURCE_OF_TRUTH.md`
- `docs/local-wordpress-ide.md`
- `docs/2026-03-24-repository-progress-assessment.md`

Document the exact harness split, the pinned WordPress version, and the now-passing refresh/drift cases.

### Validation

Target commands after the harness split lands:

1. `npm run wp:start`
2. run the automated WP 7.0 bootstrap/login setup path
3. `npm run test:e2e:wp70`
4. `npm run test:e2e:playground`

### Exit Criteria

1. A checked-in browser harness runs against real WordPress 7.0 for Site Editor flows.
2. The two current `fixme` cases pass in that harness.
3. `STATUS.md` no longer needs to explain away the main WP 7.0 browser-confidence gap.

## Plan 5: Reduce Gutenberg Fragility In The Remaining Runtime Hot Spots

### Objective

Harden the remaining runtime areas that still rely on experimental Gutenberg settings or DOM fallbacks, with the immediate focus on theme-token collection and pattern compat/inserter discovery.

### Repo-Grounded Starting Point

- `src/context/theme-tokens.js` still reads `getSettings().__experimentalFeatures` directly.
- `src/patterns/compat.js` already centralizes pattern access, but still depends on experimental settings keys and DOM selectors when stable APIs are not available.
- `src/patterns/__tests__/compat.test.js` gives a good unit baseline for the compat adapter.
- `src/context/__tests__/theme-tokens.test.js` currently covers token summarization, but not a stable-first token collection strategy.
- Current repo docs still state that `__experimentalFeatures` has no stable replacement in this codebase yet, so the immediate hardening target is isolation and proof of parity, not assuming a drop-in stable source already exists.
- The current allowed-pattern fallback broadens to all block patterns when no contextual selector exists, which is convenient but may overstate what is truly visible in nested insertion contexts.

### Workstream 1: Isolate Theme Token Reading Behind A Source Adapter

Primary files:

- `src/context/theme-tokens.js`
- optional new helper such as `src/context/theme-settings.js`
- `src/context/__tests__/theme-tokens.test.js`

Steps:

1. Split raw editor-settings reads from token normalization.
2. Introduce an explicit source adapter that can report which theme-token source is active.
3. First verify whether any stable settings path can reproduce the current token manifest with parity for:
   - origin-separated presets
   - layout values
   - element styles
   - block pseudo styles
4. If parity is not available, keep `__experimentalFeatures` as the primary runtime source for now and treat the hardening work as adapter isolation plus diagnostics rather than source replacement.
5. Preserve the existing token manifest contract so prompt-building code and server/client parity do not break.
6. Expand tests to cover:
   - stable-only settings
   - experimental-only settings
   - mixed origin maps
   - missing settings
   - the explicit "no stable parity available, continue using experimental source" path if that remains the repo reality

### Workstream 2: Separate Pattern Settings Compatibility From DOM Inserter Discovery

Primary files:

- `src/patterns/compat.js`
- optional split modules such as `src/patterns/pattern-settings.js` and `src/patterns/inserter-dom.js`
- `src/patterns/__tests__/compat.test.js`

Steps:

1. Split the compat layer into:
   - settings and selector compatibility
   - DOM-based inserter discovery
2. Keep stable selector and settings resolution isolated from DOM fallbacks so future cleanup is surgical.
3. Retain the existing stable-first behavior for:
   - block patterns
   - pattern categories
   - allowed patterns
4. Add an explicit decision on contextual allowed-pattern fallback behavior:
   - keep broad fallback-to-all-patterns intentionally and document it, or
   - fail closed for scoped visibility when no contextual selector exists
5. Make DOM fallbacks fail closed and return `null` instead of guessing beyond the known selector matrix.

### Workstream 3: Reduce DOM Coupling In Inserter Helpers

Primary files:

- `src/patterns/compat.js`
- any callers that assume the inserter search input or toggle always exists

Steps:

1. Audit every caller of `findInserterSearchInput()` and `findInserterToggle()`.
2. Ensure callers handle a missing search input or toggle as a normal degraded path.
3. Avoid broad document-level selectors that could capture unrelated search boxes.
4. Keep selector lists centralized and version-documented so future Gutenberg changes touch one place.
5. Confirm the degraded-path contract at each caller:
   - `PatternRecommender` should safely skip active search binding when the input cannot be found
   - `InserterBadge` should stay hidden when no toggle anchor is available

### Workstream 4: Add Diagnostics For Which Runtime Path Is Active

Primary files:

- `src/patterns/compat.js`
- `inc/Abilities/InfraAbilities.php` or settings diagnostics if surfaced to admins

Recommended scope:

1. Reuse `getPatternAPIPath()` and consider adding an equivalent theme-token-source diagnostic.
2. Surface diagnostics only where they help troubleshooting:
   - unit tests
   - admin diagnostics
   - explicit debug output
3. Do not add noisy runtime logging in normal editor use.
4. If the allowed-pattern fallback remains intentionally broad, expose that mode clearly in diagnostics so debugging can distinguish "context-aware selector" from "all patterns fallback".

### Workstream 5: Add Regression Coverage Around The Hardened Paths

Primary files:

- `src/context/__tests__/theme-tokens.test.js`
- `src/patterns/__tests__/compat.test.js`
- add direct tests for `src/patterns/PatternRecommender.js`
- add direct tests for `src/patterns/InserterBadge.js`
- optional smoke tests if behavior changes materially

Coverage goals:

1. Theme token collection works with any new stable source only if parity with the current manifest is proven; otherwise the adapter explicitly reports experimental-source operation and still degrades safely.
2. Pattern compat keeps preferring stable APIs when present.
3. The allowed-pattern fallback behavior is directly tested for whichever contract the repo chooses: broad fallback or fail-closed scoping.
4. DOM inserter helpers return `null` cleanly when the expected editor structure is absent.
5. `PatternRecommender` does not crash or leak listeners when the inserter search input never appears.
6. `InserterBadge` stays hidden cleanly when no toggle anchor is available.

### Workstream 6: Update Compatibility Docs

Primary files:

- `STATUS.md`
- `docs/SOURCE_OF_TRUTH.md`
- `docs/2026-03-24-repository-progress-assessment.md`

Once these hardening changes land, update the docs so the remaining compatibility risks are narrower and more concrete.

### Validation

1. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- compat`
2. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- theme-tokens`
3. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- PatternRecommender`
4. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- InserterBadge`
5. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand`

### Exit Criteria

1. Theme-token source resolution is isolated behind an adapter, and the repo has an explicit documented answer on whether any stable source achieves parity with the current manifest.
2. Pattern settings compatibility, allowed-pattern fallback semantics, and DOM inserter fallbacks are isolated enough that future cleanup is surgical.
3. The remaining Gutenberg-compatibility risks are clearly bounded and covered by direct tests, including caller-level pattern UI behavior.
