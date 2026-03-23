# Flavor Agent Next Steps Plan

> Created: 2026-03-23
> Scope: detailed execution plan for the next-step backlog identified during the repository and Source of Truth review
> Baseline: current PHP and JS unit/lint checks are green
> Status note: the current source tree now includes the planned template apply flow, AI activity + undo, pattern compatibility adapter, docs prewarm, and deeper Playwright smoke coverage. Treat phases 0-5 below as execution history plus validation context; the remaining open backlog starts with Phase 6 and later generative/editor-transform work.

## Goal

Address the current post-v1 gaps in the right order:

1. Ship a real apply flow for template recommendations.
2. Add undoable AI actions and durable activity records.
3. Harden the pattern recommendation surface against Gutenberg churn.
4. Prewarm WordPress docs grounding so first-use recommendations are better informed.
5. Replace shallow browser smokes with deeper end-to-end verification.
6. Build the first larger post-v1 feature: block subtree transforms.

## Planning Assumptions

1. Keep the current v1.0 must-have surfaces intact while hardening them.
2. Favor deterministic, structured operations over free-form execution.
3. Do not block the editor on Cloudflare docs calls; warming must stay async and non-critical-path.
4. Reuse the existing `@wordpress/data` store and existing REST and Abilities architecture instead of introducing a new orchestration layer.
5. Treat WordPress 7.0 compatibility as required, but do not hold all feature work on the current Playground limitation.

## Suggested Execution Order

1. Phase 0: baseline lock and backlog setup
2. Phase 1: template apply flow
3. Phase 2: undoable AI actions and durable activity history
4. Phase 3: pattern surface hardening and compatibility adapter
5. Phase 4: docs grounding prewarm
6. Phase 5: deeper browser verification
7. Phase 6: block subtree transforms

The order matters. Template apply is the biggest missing user capability. Undo and history should follow immediately so applied AI changes become safer. The pattern surface and docs grounding are hardening work. Browser verification should then validate the hardened flows. Block subtree transforms should come last because they depend on the safety and preview patterns established earlier.

## Phase 0: Baseline Lock And Backlog Setup

### Objective

Freeze the current known-good baseline and turn this document into an executable backlog.

### Likely Files

- `STATUS.md`
- `docs/SOURCE_OF_TRUTH.md`
- `docs/NEXT_STEPS_PLAN.md`
- project tracker of choice

### Steps

1. Record the current green validation baseline:
   - `vendor/bin/phpunit`
   - `composer lint:php`
   - `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run lint:js`
   - `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand`
2. Split this plan into independently shippable work items:
   - template apply flow
   - undo and activity
   - pattern hardening
   - docs prewarm
   - browser verification
   - subtree transforms
3. Mark explicit dependencies between work items:
   - template apply depends on structured template operations
   - undo depends on consistent action metadata
   - subtree transforms depend on preview and undo patterns established earlier
4. Decide version targets:
   - phases 1 through 5 belong to `v1.x`
   - phase 6 is the first larger post-v1 feature unless scope is reduced enough for `v1.x`
5. Keep this plan current as work lands; it should not drift from `STATUS.md` or `docs/SOURCE_OF_TRUTH.md`.

### Exit Criteria

1. Each phase exists as a tracked work item with an owner and target milestone.
2. Current green baseline is captured in `STATUS.md`.

## Phase 1: Template Apply Flow

### Objective

Turn template recommendations from browse-only advice into a reviewable and executable workflow.

### Current Limitation

The current template panel can fetch recommendations and navigate the user to affected template parts or patterns, but it cannot apply the recommendation directly.

### Likely Files

- `inc/Abilities/TemplateAbilities.php`
- `inc/LLM/TemplatePrompt.php`
- `src/templates/TemplateRecommender.js`
- `src/templates/template-recommender-helpers.js`
- `src/utils/template-actions.js`
- `src/store/index.js`
- `tests/phpunit/*Template*`
- `tests/e2e/flavor-agent.smoke.spec.js`

### Steps

1. Define the supported template operations for the first executable version:
   - assign a template part to an empty or existing area
   - replace a template part assignment with another validated template part
   - insert a validated recommended pattern at a chosen insertion point
   - explicitly exclude free-form template tree rewrites from this phase
2. Extend the template recommendation output schema so each suggestion includes structured operations, not just prose:
   - add an `operations` array to the parsed suggestion payload
   - define operation types such as `assign_template_part`, `replace_template_part`, and `insert_pattern`
   - include only validated slugs, areas, and pattern names that exist in the collected template context
3. Tighten server-side validation in `TemplatePrompt::parse_response()`:
   - reject unknown template parts
   - reject unknown pattern names
   - reject unsupported operation types
   - keep free-form explanation text separate from executable data
4. Extend the client store to support template execution state:
   - selected suggestion for preview
   - apply in progress
   - apply success and apply error
   - last executed operation metadata
5. Build a preview and confirmation step in the template panel:
   - show exactly which area will change
   - show which pattern will be inserted and where
   - require an explicit confirm action before mutating the editor
6. Implement deterministic editor actions in `src/utils/template-actions.js`:
   - find template-part blocks by slug or area
   - update template-part block attributes for assignment changes
   - resolve recommended patterns from editor settings
   - parse and insert pattern blocks through block-editor dispatch APIs
   - ensure the action can target the active insertion root where possible
7. Wire template apply into the store:
   - add `applyTemplateSuggestion`
   - return structured success or failure results
   - log all applied operations through the shared activity system introduced in Phase 2
8. Add guardrails for partial execution:
   - fail the entire apply if validation fails before any mutation
   - if a multi-step operation is needed, record each substep in order
   - keep the execution surface narrow and deterministic
9. Add unit and integration coverage:
   - PHP tests for schema and parser validation
   - JS tests for view models, execution helpers, and store transitions
   - browser test that fetches a template recommendation, previews it, confirms it, and verifies the template changed
10. Update docs:
   - `STATUS.md`
   - `docs/SOURCE_OF_TRUTH.md`
   - add a short note describing the exact supported template operations and the review-confirm-apply flow

### Exit Criteria

1. A user can fetch a template recommendation, review it, and apply it without leaving the template panel.
2. All executable operations are validated against the collected template context before they run.
3. Browser coverage verifies a real apply path, not only browse links.

## Phase 2: Undoable AI Actions And Durable Activity History

### Objective

Make AI-applied changes safe to reverse and visible after the fact.

### Current Limitation

Block suggestion apply writes directly to block attributes and appends a transient in-memory activity record. There is no AI-specific undo path and no durable history.

### Likely Files

- `src/store/index.js`
- `src/store/update-helpers.js`
- `src/inspector/*`
- `src/templates/*`
- `src/utils/template-actions.js`
- `tests/js store and helper suites`

### Steps

1. Define one shared activity event schema for all AI actions:
   - surface: block, pattern, template, transform
   - target identifiers
   - suggestion label
   - before state
   - after state
   - timestamp
   - prompt or request reference
   - execution result
2. Capture pre-apply state before every mutation:
   - block suggestions: current block attributes
   - template actions: template-part attributes and inserted block ids
   - future subtree transforms: original subtree and replacement subtree
3. Add a persistence layer for activity records:
   - start with `sessionStorage` keyed by post or template reference so data survives refresh
   - keep the storage adapter isolated so a later REST-backed audit log can replace it without rewriting the store
4. Extend the store with undoable action stacks:
   - last action
   - action history list
   - undo in progress
   - undo error
5. Implement undo actions:
   - restore previous block attributes for block suggestions
   - restore previous template-part assignments
   - remove inserted pattern blocks when the action inserted them
6. Add user-facing undo affordances:
   - an inline success notice with `Undo`
   - a minimal recent AI actions section in the panel or a shared inspector area
   - clear messaging when an action cannot be reversed automatically
7. Add cleanup rules:
   - cap per-document activity history
   - clear stale session entries when the document or template changes
   - preserve enough data for the current editor session only until a server-backed audit UI exists
8. Expand tests:
   - reducer tests for record and restore behavior
   - helper tests for before and after snapshots
   - browser test that applies a block or template suggestion and then undoes it
9. Update docs to describe:
   - what is undoable
   - what is session-durable versus permanently logged
   - how this work sets up a later full audit UI

### Exit Criteria

1. Every AI apply action creates a structured activity record.
2. Block and template AI actions can be reversed from the UI.
3. Activity history survives a refresh within the same editing session.

## Phase 3: Pattern Surface Hardening And Compatibility Adapter

### Objective

Reduce the risk of breakage from Gutenberg internals and experimental APIs.

### Current Limitation

Pattern recommendation display currently depends on experimental pattern settings and DOM-based search input detection.

### Likely Files

- `src/patterns/PatternRecommender.js`
- `src/patterns/find-inserter-search-input.js`
- `src/patterns/recommendation-utils.js`
- `src/utils/visible-patterns.js`
- `flavor-agent.php`
- relevant JS tests

### Steps

1. Inventory all fragile pattern touchpoints:
   - reads from `__experimentalBlockPatterns`
   - writes to `__experimentalBlockPatterns`
   - category ordering through `__experimentalBlockPatternCategories`
   - DOM selectors used to find the inserter search field
2. Introduce a compatibility adapter layer:
   - one helper for reading pattern data
   - one helper for writing patched pattern data
   - one helper for reading or reordering pattern categories
   - one helper for binding to inserter search
3. Add feature detection to the adapter:
   - prefer stable APIs when available
   - fall back to experimental fields only when necessary
   - no-op cleanly when neither path exists
4. Encapsulate DOM search binding:
   - keep selector lists in one place
   - document why each selector exists
   - add version notes for selectors that are known to be Gutenberg-specific
   - make failure observable and non-fatal
5. Reduce mutation surface:
   - patch only the minimum pattern metadata needed for recommendations
   - ensure rollback to original metadata still works if patching fails or recommendations clear
6. Add compatibility tests:
   - unit tests for the adapter helpers
   - tests for missing settings and partial settings
   - browser coverage that verifies the `Recommended` category still appears after open-close-open flows
7. Write a migration note:
   - what to switch when stable pattern APIs land
   - what tests should fail if Gutenberg changes the inserter structure
8. Update `docs/SOURCE_OF_TRUTH.md` and `STATUS.md` to move this item from a known gap into an implemented compatibility strategy once done.

### Exit Criteria

1. Pattern recommendation UI goes through a single compatibility adapter instead of scattered experimental API calls.
2. Search detection failure degrades gracefully instead of silently breaking the feature.
3. Tests exist for both current experimental and future stable-path behavior.

## Phase 4: Docs Grounding Prewarm

### Objective

Eliminate the most obvious cold-start miss on recommendation-time docs grounding.

### Current Limitation

Block and template recommendation flows only consult cached docs guidance on the critical path. First-use requests for uncached entities can return without WordPress guidance.

### Likely Files

- `inc/Cloudflare/AISearchClient.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Settings.php`
- `flavor-agent.php`
- Cloudflare and cache tests

### Steps

1. Define the initial warm set:
   - highest-frequency block types used by the inspector surface
   - template entity keys such as `single`, `page`, `archive`, `home`, `404`
   - navigation-related entities if the navigation ability is actively used
2. Add a dedicated prewarm method to the AI Search client:
   - accept a list of queries and entity keys
   - seed the exact-query cache and entity cache using the same trust filters already used for runtime calls
   - return structured results and failures for logging
3. Trigger prewarm asynchronously:
   - on plugin activation when Cloudflare credentials are available
   - when Cloudflare credentials change successfully
   - optionally on a scheduled cron for stale cache refresh
4. Add throttling and backoff:
   - avoid repeated warm jobs when nothing changed
   - respect cache TTLs
   - avoid stampeding on repeated saves
5. Add observability:
   - last prewarm timestamp
   - last prewarm result summary
   - surfaced in the admin settings screen or in a lightweight diagnostics section
6. Keep runtime behavior unchanged:
   - recommendation-time docs lookup remains cache-only and non-blocking
   - no synchronous fetches should be added to block or template recommendation requests
7. Add tests:
   - cache seeding
   - unchanged config does not rewarm
   - changed config schedules or performs prewarm
   - failed prewarm does not break normal recommendation flows
8. Add a verification script or manual QA checklist to confirm that a warmed entity returns guidance on first recommendation after activation or settings save.

### Exit Criteria

1. Common blocks and template types have warmed docs guidance before first interactive use.
2. Recommendation requests remain non-blocking.
3. Admin-visible diagnostics exist for warm status.

## Phase 5: Deeper Browser Verification

### Objective

Move from mocked smoke coverage to realistic end-to-end verification of the important editor flows.

### Current Limitation

The current Playwright suite proves the three surfaces render and issue requests, but it uses mocked network responses and stays on a WordPress 6.9.4 Playground harness because the available 7.0 beta Playground runtime crashes.

### Likely Files

- `tests/e2e/flavor-agent.smoke.spec.js`
- `playwright.config.js`
- `tests/e2e/playground-mu-plugin/*`
- optional test fixtures in PHP or JS

### Steps

1. Split browser tests into lanes:
   - fast smoke lane
   - real-flow lane
   - WordPress 7.0 compatibility lane
2. Replace browser-level route mocking for core flows with deterministic local fixtures:
   - use a test-only plugin flag, fixture endpoint, or MU-plugin behavior so the request still passes through Flavor Agent code paths
   - keep the responses deterministic and fast
3. Add end-to-end tests for block recommendations:
   - fetch suggestions
   - apply a suggestion
   - verify block attributes changed
   - undo the AI action and verify the editor returned to the previous state
4. Add end-to-end tests for template recommendations:
   - fetch suggestion
   - preview it
   - apply it
   - verify assignment or insertion occurred
   - undo it
5. Add end-to-end tests for the pattern surface:
   - passive load request
   - search-driven request
   - recommended category visible
   - badge state transitions for loading, ready, and error
6. Add degraded-mode coverage:
   - missing pattern credentials
   - missing template credentials
   - block provider unavailable
7. Resolve WordPress 7.0 coverage strategy:
   - first try to stabilize the current Playground setup
   - if Playground remains unstable, add a second harness using Docker-based WordPress 7.0 for compatibility tests
   - keep 6.9.4 smoke coverage only as a fallback, not as the only browser environment
8. Capture better failure artifacts:
   - traces
   - screenshots
   - test-friendly console logging for plugin failures
9. Keep the suite efficient:
   - use deterministic fixtures
   - reuse setup where possible
   - avoid live external service dependencies in CI

### Exit Criteria

1. Browser tests verify real apply and undo paths for the important surfaces.
2. The suite runs against WordPress 7.0 in at least one supported harness.
3. Route interception is no longer the primary proof that the feature works.

## Phase 6: Block Subtree Transforms

### Objective

Add the first larger post-v1 capability: AI-proposed replacement trees for the selected block subtree.

### Why This Comes Last

This feature needs the preview, validation, execution, and undo patterns established in earlier phases. Shipping it first would add a larger mutation surface before those safety rails exist.

### Likely Files

- `inc/Abilities/BlockAbilities.php` or a new dedicated ability class
- `inc/LLM/Prompt.php` or a new transform prompt class
- `inc/REST/Agent_Controller.php`
- `src/context/collector.js`
- `src/store/index.js`
- new UI components for preview and apply
- PHP, JS, and browser tests

### Steps

1. Narrow the first transform scope:
   - selected single root block or selected container subtree only
   - supported block families documented explicitly
   - no raw HTML, script tags, or unsupported block names
2. Decide the API shape:
   - preferred path: new dedicated ability and REST route such as `transform-block-tree`
   - input: selected subtree context, prompt, and editing constraints
   - output: structured proposed block tree, explanation, confidence, and optional warnings
3. Extend context collection:
   - capture the selected subtree in a structured form
   - capture parent constraints, allowed blocks, template area, and content-only restrictions
   - include theme tokens and nearby structural context where it helps layout decisions
4. Build a dedicated prompt and parser:
   - require a structured tree output, not free-form prose
   - validate block names, attributes, and nesting
   - reject proposals that violate content-only or disabled-block rules
5. Build preview UI:
   - show the current subtree and proposed subtree
   - make the preview understandable before apply
   - include warnings when the transform changes structure significantly
6. Implement apply:
   - convert the validated proposal into editor blocks
   - replace the selected subtree through block-editor dispatch
   - log the operation through the shared activity system
   - support undo through the shared undo system
7. Add tests:
   - PHP tests for parsing and validation
   - JS tests for store and preview state
   - browser test that performs a real transform on a supported block family
8. Document the feature:
   - supported transform scope
   - unsupported cases
   - how to recover using undo

### Exit Criteria

1. A supported selected subtree can be transformed through a structured preview-confirm-apply workflow.
2. The returned tree is validated before execution.
3. The transform is undoable and covered by browser tests.

## Cross-Cutting Work After Each Phase

1. Update `STATUS.md` with what landed and what remains.
2. Update `docs/SOURCE_OF_TRUTH.md` so the feature inventory and known gaps remain accurate.
3. Add or expand tests before considering the phase complete.
4. Re-run the green baseline commands.
5. Record any new compatibility assumptions, especially around Gutenberg internals and WordPress 7.0 behavior.

## Explicit Non-Goals For This Plan

The following items remain out of scope until the phases above are complete:

1. Pattern generation from scratch
2. Pattern promotion to registered patterns
3. Interactivity API scaffolding
4. Dynamic block scaffolding
5. Full server-backed audit log UI
6. Multi-turn recommendation conversations
7. Batch recommendations across many blocks

## Definition Of Done For The Entire Plan

This plan is complete when:

1. Template recommendations have a safe apply path.
2. AI-applied changes are undoable and session-durable.
3. Pattern recommendations no longer depend on scattered experimental and DOM assumptions.
4. Common block and template entities have warmed docs guidance before first use.
5. Browser tests cover real apply and undo behavior on a WordPress 7.0-capable harness.
6. Block subtree transforms ship behind the same preview, validation, and undo guarantees as the earlier AI actions.
