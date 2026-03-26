# Flavor Agent Findings Remediation Plan

> Created: 2026-03-24
> Scope: step-by-step remediation plan captured on 2026-03-24 for the then-open code findings confirmed against the repository
> Inputs: `docs/2026-03-24-repository-progress-assessment.md`, direct code inspection in `flavor-agent.php`, `inc/`, `src/`, `tests/`, `STATUS.md`, and the checked-in Playwright configs
>
> Historical snapshot: this document records the 2026-03-24 remediation pass. Use `STATUS.md`, `docs/SOURCE_OF_TRUTH.md`, and `docs/2026-03-25-roadmap-aligned-execution-plan.md` for the current verified backlog.
>
> Status note as of 2026-03-25: Workstreams 0-2 now mix landed execution history with the narrower follow-up scope that still remains open. Keep this file for lineage and delivery context, not as the single source of live backlog truth.
>
> Current-truth note: several items below are preserved verbatim even where later docs passes landed or narrowed them. If this file conflicts with the newer docs, the newer docs win.

## Goal

Close the real repository gaps that remain after the 2026-03-24 assessment without regressing the currently working block, pattern, template, settings, and docs-grounding surfaces.

This plan is repository-backed in two senses:

1. every action is grounded in the current source tree rather than generic product advice
2. every workstream names the primary files, test targets, and delivery sequence needed to land the change safely

## Confirmed Open Findings (As Of 2026-03-24)

1. `recommend-navigation` is now registered, tested, exposed through the plugin REST controller, and mounted in the Inspector for selected `core/navigation` blocks, but the surface remains advisory-only and still lacks a validated apply contract plus browser smoke.
2. AI activity history is now server-backed and hydrated back into editor-scoped history, with `sessionStorage` retained only as a cache/fallback. The remaining gap is a mature admin/audit/observability surface outside the inline editor UX.
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

1. Tighten the shipped navigation surface by adding browser verification and deciding whether it should remain advisory-only or grow a bounded apply contract.
2. Promote the server-backed activity layer into an admin-visible audit and observability surface.
3. Expand the template-part executor from one start/end insertion into a bounded, explicit composition DSL.
4. Harden the remaining WP 7.0 compatibility adapters.
5. Make default browser verification include the real WP 7.0 flows and add template-part smoke coverage.

The order matters less now than it did on 2026-03-24 because the navigation surface and server-backed activity layer have already landed. The remaining work is follow-through: audit/admin UX and broader executable contracts should stabilize before the final browser-verification pass.

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
   - navigation apply contract and browser smoke
   - admin/audit activity UX on top of server-backed persistence
   - expanded template-part executor
   - WP 7.0 adapter hardening
   - unified browser verification

### Verification

- `rg -n "fixme|unskip|Interactivity|template-part" docs STATUS.md`

### Exit Criteria

1. No top-level doc still claims the WP 7.0 tests are unskipped or missing when they are already checked in.
2. Interactivity is either an explicit future idea or an explicit de-scope, not a dangling pseudo-gap.

## Workstream 1: Extend The Shipped Navigation Surface

### Objective

Keep the landed navigation Inspector experience accurate in docs, add browser verification, and only expand beyond advisory guidance if the plugin can support a bounded apply contract.

Status: the original "ship a first-party navigation surface" goal is complete in the current tree. `recommend-navigation` now has both a thin plugin REST adapter and a first-party Inspector UI for selected `core/navigation` blocks. The remaining gap is narrower: the surface is still advisory-only and there is still no checked-in browser smoke.

### Repository-Backed Starting Point

- `POST /flavor-agent/v1/recommend-navigation` already exists in `inc/REST/Agent_Controller.php`.
- `flavor-agent.php` already localizes `canRecommendNavigation`.
- `src/inspector/InspectorInjector.js` already mounts `src/inspector/NavigationRecommendations.js` for selected `core/navigation` blocks.
- The current UI is explicitly labeled advisory-only and there is still no navigation browser smoke in `tests/e2e/flavor-agent.smoke.spec.js`.

### Recommended Product Contract

Keep navigation recommendations as a dedicated Inspector experience for selected `core/navigation` blocks.

Recommended follow-up contract:

1. preserve the current advisory fetch/render flow as the default behavior
2. add browser smoke for the shipped advisory experience before broadening the scope
3. only add apply/undo later if the navigation operation model can stay explicit, deterministic, and recoverable
4. keep navigation separate from the existing block/template execution contracts because it uses different permissions and a different mutation surface

### Primary Files

- `flavor-agent.php`
- `inc/REST/Agent_Controller.php`
- `src/inspector/InspectorInjector.js`
- `src/inspector/NavigationRecommendations.js`
- `src/store/index.js`
- `tests/phpunit/AgentControllerTest.php`
- `src/inspector/__tests__/NavigationRecommendations.test.js`
- `tests/e2e/flavor-agent.smoke.spec.js`

### Steps

1. Keep the shipped REST adapter and Inspector panel documented as implemented behavior, not future work.
2. Add Playwright coverage for selecting a `core/navigation` block, requesting suggestions, and asserting the advisory results render.
3. Decide whether the next increment should remain advisory-only or add a bounded apply contract.
4. If an apply path is added later, define a narrow operation model and ship validation, undo, and browser coverage together.
5. Keep the permission model at `edit_theme_options` and continue isolating navigation requests from the generic block-suggestion pipeline.
6. Extend PHPUnit and JS unit coverage only as the contract broadens.

### Verification

- `vendor/bin/phpunit --filter Navigation`
- `vendor/bin/phpunit --filter AgentController`
- `npm run test:unit -- --runInBand src/inspector/__tests__/NavigationRecommendations.test.js src/store/__tests__`
- `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70 -- --reporter=line`

### Exit Criteria

1. No doc still describes navigation as missing plugin UI or REST wiring.
2. The shipped advisory surface has checked-in browser smoke, or a later apply path ships with equally bounded validation and coverage.
3. Navigation remains explicit about advisory-only versus executable behavior.

## Workstream 2: Broaden The Server-Backed Activity Layer Into An Audit Surface

### Objective

Promote the existing server-backed activity log into a clearer audit, review, and observability surface outside the inline editor panels.

Status: the original durability work is complete in the current tree. Activity entries now persist through `inc/Activity/Repository.php`, the plugin REST activity routes, and editor hydration in `src/store/index.js`. `sessionStorage` remains only as a cache/fallback, and ordered undo already blocks older AI actions when newer ones still apply.

### Repository-Backed Starting Point

- Activity services already exist under `inc/Activity/`.
- `inc/REST/Agent_Controller.php` already exposes `GET/POST /activity` plus `POST /activity/{id}/undo`.
- `src/store/index.js` persists server-backed entries, hydrates them on load, and syncs undo transitions.
- `src/store/activity-history.js` keeps `sessionStorage` only as a best-effort cache/fallback and resolves ordered undo eligibility.
- The shipped UX is still inline/editor-scoped in `src/components/AIActivitySection.js`, and `src/admin/` still lacks a dedicated audit/history screen.

### Recommended Architecture

Keep the server-backed activity repository as the source of truth and layer better admin visibility, filtering, and observability on top of it.

Recommended follow-up scope:

- read-oriented admin UI for recent AI actions
- filters by surface, entity, user, status, and time
- clearer labeling for blocked, drifted, failed, undone, and synced states
- continued use of `sessionStorage` only as a last-known-state cache for fast reloads or temporary server unavailability

### Primary Files

- `flavor-agent.php`
- `inc/Activity/`
- `inc/REST/Agent_Controller.php`
- `src/store/activity-history.js`
- `src/store/index.js`
- `src/components/ActivitySessionBootstrap.js`
- `src/components/AIActivitySection.js`
- `src/admin/`
- `tests/phpunit/*Activity*`
- `src/store/__tests__/activity-history*.test.js`

### Steps

1. Keep the current repository-backed write path and editor hydration flow as the source of truth.
2. Add an admin-visible audit surface that can query recent AI actions by surface, entity, user, and status.
3. Reuse the current ordered-undo model in the UI so `Undo blocked by newer AI actions.` stays distinct from content drift and sync failures.
4. Surface persistence state more clearly so users can tell whether an entry is local, server-backed, blocked, undone, or failed.
5. Preserve `sessionStorage` only as a cache/fallback and document it that way everywhere.
6. Add PHPUnit coverage for new query/filter or permission behavior and JS coverage for hydration plus state labeling.

### Verification

- `vendor/bin/phpunit --filter Activity`
- `vendor/bin/phpunit --filter AgentController`
- `npm run test:unit -- --runInBand src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js`

### Exit Criteria

1. Activity can be reviewed outside the active editor panel.
2. Server-backed entries remain the source of truth, with `sessionStorage` clearly treated as cache/fallback only.
3. The audit/admin UX makes blocked, drifted, undone, and synced states understandable without digging into raw storage or network logs.

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

1. PR 1: docs cleanup and historical-snapshot framing
2. PR 2: navigation browser smoke and apply-contract follow-through
3. PR 3: admin-visible activity/audit surface
4. PR 4: expanded template-part operation contract and executor
5. PR 5: WP 7.0 adapter hardening
6. PR 6: aggregate e2e command plus template-part browser smoke and doc cleanup

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

1. Navigation recommendations have a clear bounded contract: either advisory-only with browser smoke or executable with validated apply/undo coverage.
2. AI activity history is server-backed, reviewable outside the active editor panel, and surfaced through a clearer audit/observability UX.
3. Template-part execution supports a bounded multi-operation DSL rather than one start/end insertion.
4. Pattern and theme-token compatibility paths fail closed and minimize experimental dependence.
5. The default browser verification command exercises both the Playground and WP 7.0 paths, including `wp_template_part`.
6. The docs describe exactly that state, with no stale “planned but not shipped” wording left behind.
