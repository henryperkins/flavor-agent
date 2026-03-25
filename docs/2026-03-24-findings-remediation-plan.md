# Flavor Agent Findings Remediation Plan

> Created: 2026-03-24
> Scope: step-by-step remediation plan for the still-open code findings confirmed against the current repository
> Inputs: `docs/2026-03-24-repository-progress-assessment.md`, direct code inspection in `flavor-agent.php`, `inc/`, `src/`, `tests/`, `STATUS.md`, and the checked-in Playwright configs
>
> Status note as of the latest docs refresh: Workstream 0 is complete. The assessment/status docs now reflect the active WP 7.0 browser story, and runtime Interactivity API usage is explicitly de-scoped for the current editor/admin-only plugin. Treat Workstream 0 below as completed execution history; the remaining open backlog starts with Workstream 1.

## Goal

Close the real repository gaps that remain after the 2026-03-24 assessment without regressing the currently working block, pattern, template, settings, and docs-grounding surfaces.

This plan is repository-backed in two senses:

1. every action is grounded in the current source tree rather than generic product advice
2. every workstream names the primary files, test targets, and delivery sequence needed to land the change safely

## Confirmed Open Findings

1. `recommend-navigation` is registered, tested, and server-backed, but it is still not exposed through a first-party plugin UI or plugin REST adapter.
2. AI activity history is still `sessionStorage`-backed, latest-action oriented, and not durable across sessions or users.
3. Template-part execution is still restricted to one `insert_pattern` operation at `start` or `end`.
4. WordPress 7.0 compatibility still depends on experimental pattern/theme-token fallbacks and DOM-based inserter discovery.
5. Browser verification is split between a default 6.9.4 Playground smoke path and a separate WP 7.0 harness; the default `npm run test:e2e` path does not exercise the WP 7.0 cases, and there is still no `wp_template_part` browser smoke.

## Delivery Principles

1. Keep execution deterministic. Do not introduce free-form AI mutations into templates or template parts.
2. Reuse the current Abilities, REST, `@wordpress/data`, and editor integration architecture.
3. Prefer stable core APIs whenever they exist; when a fallback is unavoidable, fail closed instead of silently widening scope.
4. Make browser verification match the declared support floor.
5. Land the work in small PRs with direct PHPUnit, JS unit, and Playwright coverage.

## Recommended Delivery Order

1. Ship the missing navigation surface so registered capability matches product reality.
2. Move activity history from session-only to server-backed audit storage and broaden undo eligibility safely.
3. Expand the template-part executor from one start/end insertion into a bounded, explicit composition DSL.
4. Harden the remaining WP 7.0 compatibility adapters.
5. Make default browser verification include the real WP 7.0 flows and add template-part smoke coverage.

The order matters. The documentation/scope lock is already complete. Durable activity and template-part execution change core product behavior and should stabilize before the final browser-verification pass.

## Workstream 0: Documentation And Scope Lock (Completed)

### Objective

Make the docs describe the actual backlog and explicitly de-scope non-problems before code work begins.

Status: complete in the current source tree. Keep the steps below as execution history for the doc-alignment pass that refreshed the assessment/status notes and explicitly de-scoped runtime Interactivity API usage for the current editor/admin-only plugin.

### Primary Files

- `docs/2026-03-24-repository-progress-assessment.md`
- `STATUS.md`
- `docs/SOURCE_OF_TRUTH.md`
- this document

### Steps

1. Update the assessment language that still says the WP 7.0 refresh/drift tests need to be unskipped.
   - The checked-in tests are already active in `tests/e2e/flavor-agent.smoke.spec.js`.
   - The real remaining issue is that the default Playwright command excludes the `@wp70-site-editor` project.
2. Update the assessment and any lingering historical verification notes in `STATUS.md` so the remaining browser gap is described precisely:
   - default Playground path on WordPress `6.9.4`
   - separate Docker-backed WP 7.0 harness
   - active checked-in WP 7.0 refresh/drift coverage
   - missing `wp_template_part` browser smoke
3. Resolve the Interactivity API ambiguity explicitly.
   - Recommended repository decision: de-scope it from the open-gap list because the plugin is editor/admin only and has no front-end runtime that benefits from `@wordpress/interactivity` today.
   - Keep Interactivity references only in future-facing docs or research docs, not in the current-gap summary.
4. Add a short “open backlog” section in `STATUS.md` pointing to the real remaining workstreams:
   - navigation UI/REST exposure
   - durable activity history
   - expanded template-part executor
   - WP 7.0 adapter hardening
   - unified browser verification

### Verification

- `rg -n "fixme|unskip|Interactivity|template-part" docs STATUS.md`

### Exit Criteria

1. No top-level doc still claims the WP 7.0 tests are unskipped or missing when they are already checked in.
2. Interactivity is either an explicit future idea or an explicit de-scope, not a dangling pseudo-gap.

## Workstream 1: Ship A First-Party Navigation Surface

### Objective

Make `recommend-navigation` a real product surface instead of an ability-only orphan.

### Repository-Backed Starting Point

- The ability exists in `inc/Abilities/Registration.php`.
- The prompt and handler exist in `inc/Abilities/NavigationAbilities.php` and `inc/LLM/NavigationPrompt.php`.
- There is no corresponding plugin route in `inc/REST/Agent_Controller.php`.
- There is no mounted UI in `src/index.js`.
- The current editor integration pattern is already established in `src/inspector/InspectorInjector.js`, `src/store/index.js`, and the template/pattern panels.

### Recommended Product Contract

Ship navigation recommendations as a dedicated inspector experience for selected `core/navigation` blocks instead of leaving them ability-only.

Recommended UX:

1. show a `Navigation Recommendations` section only when the selected block is `core/navigation`
2. reuse the current “prompt + suggestions + explanation” interaction pattern
3. keep the first shipped navigation surface advisory-only
4. do not mix navigation suggestions into the existing block-recommendation payload because they use a different provider path and different permissions

### Primary Files

- `flavor-agent.php`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/NavigationAbilities.php`
- `src/index.js`
- `src/inspector/InspectorInjector.js`
- `src/store/index.js`
- `tests/phpunit/AgentControllerTest.php`
- new JS tests under `src/inspector/__tests__/` or `src/store/__tests__/`

### Steps

1. Add a thin REST adapter for navigation recommendations.
   - Add `POST /flavor-agent/v1/recommend-navigation` to `inc/REST/Agent_Controller.php`.
   - Sanitize and validate:
     - `menuId`
     - `navigationMarkup`
     - optional `prompt`
   - Delegate directly to `NavigationAbilities::recommend_navigation()`.
2. Localize a first-party capability flag in `flavor-agent.php`.
   - Add `canRecommendNavigation` using the same provider-configuration checks that already gate template and template-part recommendation availability.
   - Also fold in the current-user `edit_theme_options` capability so the UI does not surface a dead action to users who can edit blocks but cannot call the navigation endpoint.
3. Add a navigation slice to the shared store.
   - request state
   - loading state
   - result payload
   - error state
   - selected suggestion state if the UI needs drill-in behavior
4. Extend the inspector integration.
   - In `src/inspector/InspectorInjector.js`, detect when the selected block is `core/navigation`.
   - Render a navigation-specific panel section using the current inspector shell instead of introducing a new editor side rail.
5. Serialize the selected navigation block for request context.
   - Reuse existing block-editor selectors and block serialization utilities already available in the editor runtime.
   - Send either `navigationMarkup`, `menuId`, or both, depending on what is actually available for the selected block.
6. Keep the first shipped surface advisory-only.
   - show grouped suggestions
   - show explanation
   - optionally add affordances that focus the current navigation block or open related settings
   - do not add apply/undo until the navigation operation model exists
7. Add controller and inspector/store tests.
   - REST validation and forwarding in PHPUnit
   - selected-`core/navigation` visibility and fetch behavior in JS tests

### Verification

- `vendor/bin/phpunit --filter Navigation`
- `vendor/bin/phpunit --filter AgentController`
- `npm run test:unit -- --runInBand src/store/__tests__ src/inspector`

### Exit Criteria

1. Selecting a `core/navigation` block exposes a first-party navigation recommendation UI.
2. The plugin REST layer exposes navigation in the same thin-adapter pattern used by the other plugin surfaces.
3. There is no longer a registered ability that is invisible from the shipped plugin UI.

## Workstream 2: Replace Session-Only Activity With A Durable Audit Log

### Objective

Make AI activity durable, queryable, and safer to undo across refreshes, sessions, and users with the correct permissions.

### Repository-Backed Starting Point

- Activity history currently lives in `src/store/activity-history.js`.
- Persistence is `sessionStorage` only.
- The UI only marks the latest activity entry as undoable in `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, and `src/components/AIActivitySection.js`.
- The current system already records structured before/after snapshots that can seed a durable audit layer.

### Recommended Architecture

Make the server-backed audit log the source of truth and keep `sessionStorage` only as an optional last-known-state cache for fast reloads.

Recommended storage shape:

- custom table: `${wpdb->prefix}flavor_agent_activity`
- one row per AI action
- JSON columns or longtext-encoded JSON for:
  - `target`
  - `before_state`
  - `after_state`
  - `undo`
  - `request`
  - `document`
- indexed columns for:
  - `surface`
  - `entity_type`
  - `entity_ref`
  - `user_id`
  - `created_at`

### Primary Files

- `flavor-agent.php`
- new PHP files under `inc/Activity/`
- `inc/REST/Agent_Controller.php`
- `src/store/activity-history.js`
- `src/store/index.js`
- `src/components/ActivitySessionBootstrap.js`
- `src/components/AIActivitySection.js`
- `tests/phpunit/*Activity*`
- `src/store/__tests__/activity-history*.test.js`

### Steps

1. Add a dedicated PHP activity service.
   - `inc/Activity/Repository.php` for schema and CRUD
   - `inc/Activity/Serializer.php` for stable JSON payload formatting
   - optional `inc/Activity/Permissions.php` if read permissions diverge by surface
2. Create and maintain the activity table on activation.
   - call a schema-install routine from `flavor-agent.php`
   - version the schema so upgrades are explicit
3. Add REST endpoints for activity lifecycle.
   - `GET /flavor-agent/v1/activity`
     - filters by current entity ref / surface
   - `POST /flavor-agent/v1/activity`
     - records a new action
   - `POST /flavor-agent/v1/activity/{id}/undo`
     - updates status after a successful undo
   - keep permissions aligned with the existing edit capabilities per surface
4. Refactor `ActivitySessionBootstrap` to hydrate from the server.
   - load recent actions for the current scope on editor bootstrap
   - keep `sessionStorage` only as a best-effort cache layer or remove it entirely once the server path is stable
5. Broaden undo eligibility safely.
   - Replace the current “latest only” rule with “latest valid tail of AI actions”.
   - A historical entry is undoable only when:
     - all newer AI actions for the same entity are already undone, or
     - there are no newer AI actions
   - This preserves deterministic rollback order without pretending arbitrary historical undo is safe.
6. Update the client activity UI.
   - Show durable activity entries from the server
   - Surface “Undo blocked by newer AI actions” distinctly from “Undo unavailable because content drifted”
   - Keep inline error text for failed undo states
7. Keep the current before/after snapshot model, but persist it server-side.
   - block attribute snapshots
   - template and template-part operation lists
   - inserted block snapshots and locators
8. Add PHPUnit coverage for:
   - table writes and reads
   - permission checks
   - status transitions
   - ordered undo eligibility
9. Add JS tests for:
   - hydration
   - client-server state sync
   - blocked undo labeling
   - successful undo status updates

### Verification

- `vendor/bin/phpunit --filter Activity`
- `vendor/bin/phpunit --filter AgentController`
- `npm run test:unit -- --runInBand src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js`

### Exit Criteria

1. Activity history survives browser refresh and new sessions.
2. Activity is queryable per document/template/template-part.
3. Undo eligibility is no longer hardcoded to one latest entry; it is computed from the newest valid AI action tail.

## Workstream 3: Expand The Template-Part Executor Into A Bounded Composition DSL

### Objective

Replace the current one-operation start/end insertion model with a broader but still deterministic template-part execution contract.

### Repository-Backed Starting Point

- `inc/LLM/TemplatePartPrompt.php` currently limits operations to one `insert_pattern`.
- `src/utils/template-operation-sequence.js` enforces the same constraint on the client.
- `src/template-parts/TemplatePartRecommender.js` only renders one executable operation type.
- `src/utils/template-actions.js` already contains the lower-level block-tree and undo utilities needed for a broader but explicit contract.

### Recommended Product Contract

Ship a bounded template-part composition DSL with explicit placements and explicit targets.

Recommended first complete contract:

1. `insert_pattern`
   - placements:
     - `start`
     - `end`
     - `before_block_path`
     - `after_block_path`
2. `replace_block_with_pattern`
   - requires:
     - `targetPath`
     - `expectedBlockName`
     - `patternName`
3. `remove_block`
   - requires:
     - `targetPath`
     - `expectedBlockName`

Guardrails:

1. max 3 operations per suggestion
2. no raw block markup in LLM output
3. every path must resolve against the collected `blockTree`
4. every pattern name must come from the filtered candidate set
5. undo must record exact snapshots for each mutation

### Primary Files

- `inc/LLM/TemplatePartPrompt.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Context/ServerCollector.php`
- `src/utils/template-operation-sequence.js`
- `src/utils/template-actions.js`
- `src/template-parts/TemplatePartRecommender.js`
- `tests/phpunit/TemplatePartPromptTest.php`
- `tests/phpunit/AgentControllerTest.php`
- `src/utils/__tests__/template-actions.test.js`
- `src/store/__tests__/store-actions.test.js`

### Steps

1. Expand the prompt contract.
   - Update `TemplatePartPrompt::build_system()` with the new bounded DSL.
   - Keep the advisory-first wording so unsupported suggestions still degrade to browse/focus hints.
2. Expand server-side parsing and validation.
   - allow multiple operations
   - validate placement allowlists
   - validate `targetPath`
   - validate `expectedBlockName`
   - reject unsupported or ambiguous operations instead of coercing them
3. Extend the request context if needed.
   - Ensure `ServerCollector::for_template_part()` exposes enough `blockTree` detail to validate the new anchored operations
   - Do not broaden it into raw tree replacement transport
4. Expand the client validator in `src/utils/template-operation-sequence.js`.
   - mirror the PHP contract exactly
   - keep errors specific and user-readable
5. Add execution helpers in `src/utils/template-actions.js`.
   - `insertPatternAtBlockPath`
   - `replaceBlockAtPathWithPattern`
   - `removeBlockAtPath`
   - reuse existing normalization, block lookup, and undo-snapshot helpers
6. Update the template-part panel UI.
   - show explicit operation summaries
   - show target block path or placement label
   - keep advisory suggestions visible beside executable ones
7. Record richer apply metadata for undo and audit.
   - target path
   - expected block name
   - inserted/replaced snapshots
   - resolved root locators
8. Add PHPUnit and JS coverage for:
   - parser validation
   - client validation parity
   - apply and undo for each supported operation type
   - mixed advisory + executable result sets

### Verification

- `vendor/bin/phpunit --filter TemplatePart`
- `vendor/bin/phpunit --filter AgentController`
- `npm run test:unit -- --runInBand src/utils/__tests__/template-actions.test.js src/store/__tests__/store-actions.test.js src/template-parts`

### Exit Criteria

1. Template-part execution is no longer limited to one start/end insertion.
2. The operation contract is still explicit, validated, and fully undoable.
3. Unsupported ideas still degrade to advisory-only suggestions instead of unsafe execution.

## Workstream 4: Harden The Remaining WP 7.0 Compatibility Adapters

### Objective

Remove the riskiest compatibility shortcuts without rewriting the whole editor integration.

### Repository-Backed Starting Point

- `src/patterns/pattern-settings.js` still falls back to experimental keys/selectors and to `all-patterns-fallback`.
- `src/patterns/inserter-dom.js` still discovers the inserter through DOM selectors.
- `src/context/theme-settings.js` still flips the whole token source to `__experimentalFeatures` when full parity is not proven.
- The pattern and theme-token adapters already have focused tests, which makes this hardening tractable.

### Recommended Strategy

Prefer stable data sources field-by-field, and fail closed when stable contextual pattern scoping is unavailable.

### Primary Files

- `src/patterns/pattern-settings.js`
- `src/patterns/compat.js`
- `src/patterns/inserter-dom.js`
- `src/patterns/PatternRecommender.js`
- `src/patterns/InserterBadge.js`
- `src/context/theme-settings.js`
- `src/context/theme-tokens.js`
- existing JS tests in `src/patterns/__tests__/` and `src/context/__tests__/`

### Steps

1. Remove `all-patterns-fallback` from recommendation eligibility.
   - If the editor cannot provide contextual allowed patterns, do not silently widen the candidate set to every registered pattern.
   - Keep diagnostics, but make the runtime fallback mode fail closed for recommendation requests.
2. Keep experimental pattern keys only as explicit compatibility branches.
   - stable key first
   - experimental key second
   - no broad “everything” fallback
3. Reduce DOM-coupling blast radius.
   - keep `src/patterns/inserter-dom.js` as the single DOM adapter
   - make missing DOM markup suppress active-search recommendation fetches cleanly instead of partially guessing
   - add regression coverage for “inserter DOM missing” behavior
4. Refactor theme-token source selection to be field-aware instead of all-or-nothing.
   - prefer stable `features` data when present
   - use `__experimentalFeatures` only for missing subtrees that are not yet available in stable data
   - expose a `sourceBreakdown` diagnostic object so tests can prove which fields still need the experimental branch
5. Extend tests around parity mismatches.
   - one test for stable-only
   - one for mixed stable/experimental field merge
   - one for complete parity
   - one for fail-closed pattern recommendation when contextual selectors are unavailable

### Verification

- `npm run test:unit -- --runInBand src/context/__tests__/theme-tokens.test.js src/patterns/__tests__/compat.test.js src/patterns/__tests__/PatternRecommender.test.js src/patterns/__tests__/InserterBadge.test.js src/patterns/__tests__/find-inserter-search-input.test.js`
- `npm run lint:js`

### Exit Criteria

1. Pattern recommendation never silently widens to all patterns when contextual scoping is unavailable.
2. Theme-token collection no longer flips the entire token source to `__experimentalFeatures` for one mismatched field.
3. Missing editor DOM does not create misleading partial behavior.

## Workstream 5: Make Browser Verification Match The Support Claim

### Objective

Ensure the default repository verification path exercises the declared WordPress 7.0 support contract and the new template-part surface.

### Repository-Backed Starting Point

- `package.json` still points `npm run test:e2e` at `test:e2e:playground`.
- `playwright.config.js` explicitly excludes `@wp70-site-editor`.
- `playwright.wp70.config.js` already runs the checked-in WP 7.0 site-editor tests.
- `tests/e2e/flavor-agent.smoke.spec.js` still lacks a `wp_template_part` smoke flow.

### Recommended Strategy

Make `npm run test:e2e` the real aggregate entry point and keep the harness-specific commands as focused subcommands.

### Primary Files

- `package.json`
- new optional wrapper under `scripts/`
- `playwright.config.js`
- `playwright.wp70.config.js`
- `tests/e2e/flavor-agent.smoke.spec.js`
- `STATUS.md`

### Steps

1. Add a repo-level aggregate e2e command.
   - Recommended script shape:
     - `test:e2e:playground`
     - `test:e2e:wp70`
     - `test:e2e` runs both in order
   - If Docker is a hard prerequisite for declared support, let the aggregate command fail clearly when Docker is unavailable rather than silently skipping WP 7.0.
2. Keep the harness-specific commands for iteration speed.
   - developers can still run Playground-only or WP70-only while iterating
3. Add a `wp_template_part` smoke flow.
   - fetch template-part recommendations
   - preview a bounded executable operation
   - confirm apply
   - verify inserted/replaced content
   - verify undo or blocked-undo behavior
4. Keep the current template refresh/drift tests in the WP70 project and update docs to call them active coverage, not planned work.
5. If the WP70 harness remains on a beta image, keep that explicit in `scripts/wp70-e2e.js` and `STATUS.md` until the repository intentionally upgrades.
6. Add an optional CI-friendly wrapper later if the project introduces GitHub Actions or another CI runner.

### Verification

- `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:playground -- --reporter=line`
- `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70 -- --reporter=line`
- `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e -- --reporter=line`

### Exit Criteria

1. The default e2e command covers both the default editor smoke path and the real WP 7.0 Site Editor path.
2. The repository has a checked-in browser smoke for `wp_template_part`.
3. The top-level docs no longer understate or overstate the checked-in coverage.

## Suggested PR Sequence

1. PR 1: docs cleanup and scope lock
2. PR 2: navigation REST exposure plus first-party inspector surface
3. PR 3: server-backed activity repository and hydration path
4. PR 4: undo-tail eligibility and audit UI refinements
5. PR 5: expanded template-part operation contract and executor
6. PR 6: WP 7.0 adapter hardening
7. PR 7: aggregate e2e command plus template-part browser smoke and doc cleanup

## Validation Matrix

Run this matrix cumulatively as the work lands:

1. `vendor/bin/phpunit`
2. `composer lint:php`
3. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run lint:js`
4. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand`
5. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:playground -- --reporter=line`
6. `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70 -- --reporter=line`

## Final End State

This remediation is complete when all of the following are true:

1. Navigation recommendations are available through a first-party plugin surface and REST adapter.
2. AI activity history is durable, queryable, and safely undoable beyond the current latest-only rule.
3. Template-part execution supports a bounded multi-operation DSL rather than one start/end insertion.
4. Pattern and theme-token compatibility paths fail closed and minimize experimental dependence.
5. The default browser verification command exercises both the Playground and WP 7.0 paths, including `wp_template_part`.
6. The docs describe exactly that state, with no stale “planned but not shipped” wording left behind.
