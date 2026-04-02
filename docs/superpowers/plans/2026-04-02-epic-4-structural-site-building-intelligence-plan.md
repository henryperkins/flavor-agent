# Epic 4 Structural Site-Building Intelligence Working Plan

> **For agentic workers:** This plan operationalizes only Epic 4 from `docs/2026-03-25-roadmap-aligned-execution-plan.md`. Keep the work inside bounded template, template-part, navigation, and nearby Site Editor structural flows. Do not expand scope into free-form tree rewrites, arbitrary code generation, a parallel site-structure product shell, or broader agentic site administration. Favor a contract-first flow: deepen structural context, extend bounded operation contracts, wire every executable path through deterministic validation/execution/undo, and only then broaden browser coverage and docs.

**Goal:** Complete the first bounded Epic 4 milestone so Flavor Agent can offer more useful structural site-building help for templates, template parts, and navigation while preserving deterministic validation, previewability where required, and refresh-safe undo/activity behavior.

**Roadmap anchors:**
1. Epic 4 scope and exit criteria: `docs/2026-03-25-roadmap-aligned-execution-plan.md` lines 428-512.
2. Repo-level completion gates: `docs/2026-03-25-roadmap-aligned-execution-plan.md` lines 620-634.
3. Current shipped surface docs:
   - `docs/features/template-recommendations.md`
   - `docs/features/template-part-recommendations.md`
   - `docs/features/navigation-recommendations.md`
   - `docs/features/activity-and-audit.md`
4. Programmatic contract reference:
   - `docs/reference/abilities-and-routes.md`

**Epic 4 acceptance criteria to satisfy:**
1. Structural recommendations are more useful than today without losing deterministic validation.
2. Template-part and navigation flows remain clearly bounded and undoable where Flavor Agent owns execution.
3. New operations do not bypass existing validation and history infrastructure.

**Architecture constraints that must remain true:**
1. `inc/REST/Agent_Controller.php` stays thin; new behavior belongs in abilities, collectors, prompt contracts, and shared executors.
2. First-party JS remains on feature-specific stores and REST endpoints for v1; do not pivot Epic 4 onto client-side `@wordpress/core-abilities`.
3. Every executable structural operation must pass through `src/utils/template-operation-sequence.js` and deterministic executor code in `src/utils/template-actions.js`.
4. Navigation remains advisory-only unless a tiny, explicitly bounded, previewable, undoable executor is proven safe enough to earn its own slice.
5. Any new executable structural path must plug into the shared activity/undo model immediately instead of creating a side-channel mutation flow.
6. Pattern Overrides support, if touched, stays recommendation-oriented first; do not introduce new structural writes on the back of override metadata alone.
7. Expanded `contentOnly`, unsynced pattern defaults, and parent/child insertion constraints must be modeled before suggestions are shown as executable.
8. No free-form block tree rewrites, arbitrary multi-step mutation pipelines, or code-generation contracts belong in this epic.

**Current gap summary:**
1. Template and template-part execution rails already exist, but their executable vocabularies are still intentionally narrow.
2. `inc/Context/ServerCollector.php` already gathers strong structural context, yet it can still deepen slot, insertion-anchor, overlay, and constraint awareness before wider mutations are safe.
3. Navigation has a first-party advisory surface and backend contract, but it still has no validator/executor/activity path.
4. The shared review/activity model from Epic 2 and the Global Styles implementation from Epic 3 provide the correct pattern for any new bounded structural surface or operation.
5. Browser and doc coverage already exist for the current structural surfaces, but Epic 4 needs new targeted cases and updated milestone documentation once the bounded expansion lands.

**Primary implementation area:** PHP structural context and prompt contracts plus editor-side JS executor/store/UI work. Epic 4 is not a docs-only milestone; it must extend structural reasoning and bounded operations without weakening the current trust model.

**Tech stack:** PHP, JavaScript, React via `@wordpress/element`, `@wordpress/components`, `@wordpress/data`, Site Editor stores, Jest-style unit tests through `@wordpress/scripts`, Playwright, Markdown docs.

---

### Milestone choice: Template And Template-Part First, Navigation Apply Only If Proven

**Decision:** Treat template and template-part bounded operation expansion as the required first Epic 4 slice. Keep richer navigation advisory output in scope for the same milestone, but treat navigation execution as an optional follow-up only if a tiny deterministic contract proves viable.

**Why this is the right first slice:**
1. Template and template-part already have validators, executors, undo semantics, and activity plumbing.
2. Navigation is currently advisory-only, so making it executable is the riskiest structural change in this repo.
3. The roadmap explicitly allows navigation actions later rather than requiring them for the first useful Epic 4 increment.
4. This keeps Epic 4 aligned with the product thesis: more capable structural help inside Gutenberg semantics, not a generic tree-manipulation engine.

---

### Task 1: Lock The Epic 4 Contract And Scope

**Why:** Epic 4 can easily sprawl. The working plan needs one explicit contract boundary before structural operations widen.

**Files:**
- Modify: `docs/2026-03-25-roadmap-aligned-execution-plan.md` only if Epic 4 scope or sequencing changes materially
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`
- Modify: `docs/reference/abilities-and-routes.md`
- Modify: existing surface docs in `docs/features/` rather than adding a second aggregate feature doc

- [ ] **Step 1: Define the first Epic 4 executable boundary**

Record that the first required Epic 4 deliverable is:
- deeper structural context,
- broader bounded template/template-part operations,
- better navigation advisory reasoning,
- and no required navigation apply contract unless a later step proves it is deterministic enough.

- [ ] **Step 2: Record the rails every new structural operation must follow**

Any new executable structural operation must:
1. be represented in ability/prompt contracts,
2. be validated in `src/utils/template-operation-sequence.js`,
3. execute through deterministic helpers in `src/utils/template-actions.js`,
4. persist activity metadata sufficient for refresh-safe undo,
5. be presented through the existing shared review/status/history model,
6. ship with its minimal activity/admin normalization in the same PR as the new executable path,
7. be non-mergeable until its focused preview/apply/undo/browser proof is in place for that operation family in the same PR.

- [ ] **Step 3: Record explicit deferrals before implementation**

Document that the first Epic 4 milestone does **not** include:
- free-form block tree rewrites,
- arbitrary multi-step mutation plans,
- generic code generation,
- a dedicated site-structure shell,
- default-on navigation execution,
- mutation-oriented Pattern Overrides work.

- [ ] **Step 4: Add one structural-surface vocabulary**

Keep the existing shared Epic 2 interaction model and surface semantics across the existing surface docs and matrix instead of creating a second aggregate feature source of truth:
- template and template-part remain preview-first for structural mutations,
- navigation remains advisory-only unless a later bounded contract changes that,
- activity and undo stay shared rather than surface-local.

---

### Task 2: Deepen Structural Context And Constraint Inputs

**Why:** Epic 4 only works if structural recommendations understand live slots, placement anchors, and editing constraints before any apply path is widened.

**Files:**
- Modify: `inc/Context/ServerCollector.php`
- Modify: `inc/Abilities/TemplateAbilities.php`
- Modify: `inc/Abilities/NavigationAbilities.php`
- Modify: `inc/REST/Agent_Controller.php`
- Modify: `tests/phpunit/ServerCollectorTest.php`
- Modify: `tests/phpunit/AgentControllerTest.php`
- Modify: `docs/wp7-migration-opportunities.md` only if a migration note becomes a committed implementation decision

- [ ] **Step 1: Deepen template context only where bounded operations need it**

Extend `ServerCollector::for_template()` with any live metadata needed for the first Epic 4 slice, for example:
- clearer top-level insertion anchors,
- stronger template-part slot occupancy and area reasoning,
- safer pattern insertion-root context,
- explicit live constraints that affect executability.

Do **not** widen template payloads speculatively.

- [ ] **Step 2: Deepen template-part context only where bounded operations need it**

Extend `ServerCollector::for_template_part()` for data such as:
- stable targetable block-path candidates,
- stronger root-vs-nested placement reasoning,
- replacement/removal target metadata,
- parent/child insertion constraints,
- expanded `contentOnly` / `contentRole`-style structural constraints before the UI offers apply.

- [ ] **Step 3: Deepen navigation advisory context first**

Extend `ServerCollector::for_navigation()` with richer advisory context such as:
- overlay/template-part awareness,
- clearer inferred location metadata,
- stronger hierarchy/depth cues,
- page-structure reasoning that can improve grouped suggestions without yet enabling mutation.

- [ ] **Step 4: Keep Pattern Overrides recommendation-oriented if adopted**

If Pattern Overrides-aware metadata is added, keep the first increment explanatory/ranking-oriented:
- collect metadata,
- feed it into ranking or explanations,
- avoid introducing new structural write types until the value is proven.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter '(Template|Navigation|AgentController|ServerCollector)'
```

Expected: PASS after structural context and route normalization are stable.

---

### Task 3: Expand The Template Operation Contract

**Why:** Template recommendations are already executable, so Epic 4 should widen them through the existing validator/executor rails instead of inventing a new structural surface.

**Files:**
- Modify: `inc/LLM/TemplatePrompt.php`
- Modify: `src/utils/template-operation-sequence.js`
- Modify: `src/utils/template-actions.js`
- Modify: `src/templates/template-recommender-helpers.js`
- Modify: `src/templates/TemplateRecommender.js`
- Modify: `src/store/index.js`
- Modify: `src/utils/__tests__/template-actions.test.js`
- Modify: `src/templates/__tests__/TemplateRecommender.test.js`
- Modify: `docs/features/template-recommendations.md`

- [ ] **Step 1: Add one bounded template-operation increment at a time**

Start with the safest useful expansion, such as:
- bounded placement targeting for `insert_pattern`,
- safer template-part replacement targeting,
- one limited move/reposition contract only if validation is fully deterministic.

Do not mix multiple unrelated structural op families in the first patch.

- [ ] **Step 2: Extend the allowlist before the UI can render new executable states**

Add any new operation types or placement modes to `src/utils/template-operation-sequence.js` first. If an operation cannot be described and validated cleanly there, keep it advisory-only.

- [ ] **Step 3: Add deterministic prepare/apply/undo support together**

Every new template op must land with:
- prepare-time validation,
- apply-time execution,
- undo preparation,
- refresh-safe undo checks.

No operation should become executable with only a forward path.

- [ ] **Step 4: Keep the template UI preview-first**

Use the existing shared review model in `TemplateRecommender()`; do not introduce an inline-apply exception for template-level structural changes.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter 'TemplatePromptTest'
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/utils/__tests__/template-actions.test.js src/templates/__tests__/TemplateRecommender.test.js
```

Expected: PASS after the widened template prompt contract, executor, and preview behavior are stable.

---

### Task 4: Expand The Template-Part Operation Contract

**Why:** Template parts are the safest place to broaden local structural mutations without drifting into free-form composition.

**Files:**
- Modify: `inc/LLM/TemplatePartPrompt.php`
- Modify: `src/utils/template-operation-sequence.js`
- Modify: `src/utils/template-actions.js`
- Modify: `src/template-parts/TemplatePartRecommender.js`
- Modify: `src/store/index.js`
- Modify: `src/utils/template-part-areas.js`
- Modify: `src/utils/__tests__/template-actions.test.js`
- Modify: `src/template-parts/__tests__/TemplatePartRecommender.test.js`
- Modify: `docs/features/template-part-recommendations.md`

- [ ] **Step 1: Harden the existing bounded operations first**

Before adding new op families, strengthen live validation for the current ones:
- `insert_pattern`,
- `replace_block_with_pattern`,
- `remove_block`.

That includes stronger target-path validation, insertion-root checks, and drift-safe undo metadata.

- [ ] **Step 2: Add one targeted expansion at a time**

Good first candidates are:
- more precise before/after-target insertion cases,
- one limited within-root move/reposition contract if it can be made deterministic,
- stronger targeted replacement flows for clearly identified block anchors.

- [ ] **Step 3: Preserve advisory visibility for suggestions that fail deterministic validation**

If a suggestion remains useful but cannot be executed safely, keep it visible through the shared advisory shell instead of dropping it entirely.

- [ ] **Step 4: Keep all template-part writes path-anchored and undoable**

No template-part operation should become executable unless:
- the target path or anchor is stable,
- the write can be replayed deterministically,
- the undo path can verify live state again before exposing `Undo`.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter 'TemplatePartPromptTest'
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/utils/__tests__/template-actions.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js
```

Expected: PASS after the widened template-part prompt contract and UI behavior are stable.

---

### Task 5: Deepen Navigation Advisory Output And Gate Any Executor Spike

**Why:** Navigation is the biggest structural seam without an executor. Epic 4 should improve its advisory output first and only consider execution if a tiny safe contract emerges.

**Files:**
- Modify: `inc/LLM/NavigationPrompt.php`
- Modify: `inc/Abilities/NavigationAbilities.php`
- Modify: `inc/Context/ServerCollector.php`
- Modify: `src/inspector/NavigationRecommendations.js`
- Modify: `src/store/index.js`
- Modify: `src/utils/template-operation-sequence.js` if and only if a navigation executor is adopted
- Modify: `src/utils/template-actions.js` if and only if a navigation executor is adopted
- Modify: `src/store/activity-history.js` if and only if a navigation executor is adopted
- Modify: `src/components/ActivitySessionBootstrap.js` if and only if a navigation executor is adopted
- Modify: `src/components/AIActivitySection.js` if and only if a navigation executor is adopted
- Modify: `inc/Activity/Serializer.php` if and only if a navigation executor is adopted
- Modify: `inc/Activity/Permissions.php` if and only if a navigation executor is adopted
- Modify: `inc/Activity/Repository.php` if and only if a navigation executor is adopted
- Modify: `src/admin/activity-log.js` if and only if a navigation executor is adopted
- Modify: `src/admin/activity-log-utils.js` if and only if a navigation executor is adopted
- Modify: `tests/phpunit/NavigationAbilitiesTest.php`
- Modify: `src/inspector/__tests__/NavigationRecommendations.test.js`
- Modify: `src/utils/__tests__/template-actions.test.js` if and only if a navigation executor is adopted
- Modify: `src/store/__tests__/activity-history.test.js` if and only if a navigation executor is adopted
- Modify: `src/store/__tests__/activity-history-state.test.js` if and only if a navigation executor is adopted
- Modify: `src/store/__tests__/store-actions.test.js` if and only if a navigation executor is adopted
- Modify: `src/components/__tests__/AIActivitySection.test.js` if and only if a navigation executor is adopted
- Modify: `src/admin/__tests__/activity-log.test.js` if and only if a navigation executor is adopted
- Modify: `src/admin/__tests__/activity-log-utils.test.js` if and only if a navigation executor is adopted
- Modify: `docs/features/navigation-recommendations.md`
- Modify: `docs/features/activity-and-audit.md` if and only if a navigation executor is adopted

- [ ] **Step 1: Improve navigation advisory reasoning without mutation**

Deepen grouped navigation suggestions around:
- hierarchy flattening,
- overlay behavior,
- structural cues,
- accessibility-oriented organization,
- stronger page/location reasoning.

Keep the UI advisory-only while these richer contracts settle.

- [ ] **Step 2: Explicitly decide whether a first navigation executor is viable**

Only consider execution if a tiny operation vocabulary can satisfy all of these:
- deterministic validation,
- previewable operation summaries,
- stable locators,
- refresh-safe undo,
- activity persistence.

If not, record that navigation remains advisory-only for this Epic 4 slice and move on.

- [ ] **Step 3: If a navigation executor is proven safe, model it after template actions**

Do **not** bolt navigation writes directly onto the inspector component.
If adopted, they should mirror the existing structural rails:
- explicit operation vocabulary,
- validator,
- executor,
- activity/undo,
- shared review shell.

The optional navigation executor spike is only viable if all of the above land together in the same PR, including shared activity/admin normalization and dedicated browser proof for navigation preview/apply/undo.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter '(Navigation|AgentController|ServerCollector)'
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/inspector/__tests__/NavigationRecommendations.test.js
```

Expected: PASS for advisory improvements; add executor-specific tests only if the executor slice is actually adopted.

---

### Task 6: Wire Any New Executable Structural Path Into Activity And Undo Immediately

**Why:** Epic 4 exit criteria require that new operations do not bypass existing validation and history infrastructure.

**Files:**
- Modify: `src/store/activity-history.js`
- Modify: `src/store/index.js`
- Modify: `src/components/ActivitySessionBootstrap.js`
- Modify: `src/components/AIActivitySection.js`
- Modify: `inc/Activity/Serializer.php`
- Modify: `inc/Activity/Permissions.php`
- Modify: `inc/Activity/Repository.php`
- Modify: `src/admin/activity-log.js`
- Modify: `src/admin/activity-log-utils.js`
- Modify: `tests/phpunit/ActivityRepositoryTest.php`
- Modify: `tests/phpunit/ActivityPermissionsTest.php`
- Modify: `src/store/__tests__/activity-history.test.js`
- Modify: `src/store/__tests__/activity-history-state.test.js`
- Modify: `src/store/__tests__/store-actions.test.js`
- Modify: `src/components/__tests__/AIActivitySection.test.js`
- Modify: `src/admin/__tests__/activity-log.test.js`
- Modify: `src/admin/__tests__/activity-log-utils.test.js`

- [ ] **Step 1: Extend activity metadata only as new structural ops require**

Add the minimum metadata needed for:
- stable undo locators,
- operation summaries,
- ordered-tail enforcement,
- refresh-safe live-state verification.

Do not widen the activity schema speculatively.

No PR that introduces a new executable template, template-part, or navigation operation should wait for a later closeout PR to add this minimum metadata.

- [ ] **Step 2: Keep server-backed persistence as the source of truth**

Any new executable structural surface or op should persist through the existing activity repository and keep undo eligibility aligned with server-backed ordering rules.

This wiring belongs in the same implementation slice as the executable operation, not as a deferred follow-up.

- [ ] **Step 3: Keep admin activity normalization current**

If new operation families or surface semantics appear in Epic 4, update the admin log summary/icon/detail mapping so structural actions remain understandable outside the editor.

Where the admin mapping changes only because of one new executable op family, ship that mapping in the same PR as the op family.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter '(ActivityRepositoryTest|ActivityPermissionsTest|AgentControllerTest)'
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js src/components/__tests__/AIActivitySection.test.js src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js
```

Expected: PASS after activity/undo/admin normalization stays aligned with any new executable structural work.

---

### Task 7: Verification, Browser Coverage, And Docs Closeout

**Why:** Epic 4 is only complete when the widened structural contracts, UI, tests, and docs converge.

**Files:**
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`
- Modify: `STATUS.md`
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`
- Modify: `docs/reference/abilities-and-routes.md`
- Modify: `docs/features/template-recommendations.md`
- Modify: `docs/features/template-part-recommendations.md`
- Modify: `docs/features/navigation-recommendations.md`
- Modify: `docs/features/activity-and-audit.md` whenever a new executable structural path changes activity/undo semantics

- [ ] **Step 1: Add dedicated browser coverage for at least one new bounded structural operation**

The required WP 7.0 browser coverage should prove:
- one widened template or template-part executable flow,
- one navigation recommendation flow with stronger advisory context,
- undo/history behavior where the new structural path is executable.

The first focused browser proof for a new executable template or template-part operation family must ship in the same PR that introduces that operation family.
Task 7 reruns and broadens milestone coverage; it is not the place to add the first browser proof for a new executable structural path.

If a navigation executor ships in this milestone, browser coverage must also include a dedicated navigation preview/apply/undo case. A template or template-part executable case does **not** satisfy navigation executor proof.

- [ ] **Step 2: Run the Epic 4 acceptance commands from the roadmap**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter '(Template|Navigation|AgentController|ServerCollector)'
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/utils/__tests__/template-actions.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/inspector/__tests__/NavigationRecommendations.test.js src/context/__tests__/block-inspector.test.js
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70 -- --reporter=line
```

Expected: PASS, with any host-level Docker limitation called out explicitly if it blocks the WP 7.0 browser run.

If a navigation executor is adopted, add its focused JS and browser tests to this step rather than relying on the generic suite alone.

- [ ] **Step 3: Rerun the repo-level completion gates before marking Epic 4 complete**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
composer lint:php
vendor/bin/phpunit
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run lint:js
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70
```

Expected: PASS, with the same Docker note if the host cannot run the WP 7.0 browser harness.

- [ ] **Step 4: Update the docs backbone**

Before Epic 4 is considered complete, update:
- `STATUS.md`,
- `docs/SOURCE_OF_TRUTH.md`,
- `docs/FEATURE_SURFACE_MATRIX.md`,
- `docs/reference/abilities-and-routes.md`,
- the existing template/template-part/navigation feature docs,
- `docs/features/activity-and-audit.md` for any new executable structural path.

Do not add a second aggregate `docs/features/structural-site-building-intelligence.md` doc for this milestone unless Epic 4 introduces a genuinely new first-party surface that cannot be described by the existing per-surface docs.

The docs should make clear:
- which new structural ops shipped,
- what remains advisory-only,
- what remains deferred,
- and how activity/undo behaves for the widened structural paths.

---

### Recommended PR Breakdown

**PR 1: Structural context hardening**
- Task 1
- Task 2

**PR 2: Template operation expansion**
- Task 3
- the minimum Task 6 activity/admin changes required by the new template operations
- the focused browser proof for the new executable template operation family in the same PR

**PR 3: Template-part operation expansion**
- Task 4
- the minimum Task 6 activity/admin changes required by the new template-part operations
- the focused browser proof for the new executable template-part operation family in the same PR

**PR 4: Navigation advisory deepening**
- Task 5 advisory work only

**PR 5: Optional navigation executor spike**
- Task 5 executor work only if the contract is clean and deterministic
- all required Task 6 activity/admin changes
- dedicated navigation browser proof in the same PR

**PR 6: Shared activity/admin cleanup plus docs/browser closeout**
- any remaining shared Task 6 cleanup that was not specific to one executable op family
- Task 7
- rerun milestone browser coverage and docs closeout, but do not rely on PR 6 for the first browser proof of a new executable op family

Do not mix widened executable template/template-part contracts with speculative navigation execution unless the dependency is unavoidable.
Do not defer the minimum activity/undo/admin wiring for a new executable path to PR 6.

---

### Final Verification Checklist

- [ ] Structural context is richer before any new structural operation is exposed as executable.
- [ ] Every new executable structural op is represented in prompt/schema contracts, validator rules, executor code, and undo metadata.
- [ ] Template and template-part recommendations are more useful than today without becoming free-form mutation flows.
- [ ] Navigation guidance is stronger, and navigation execution only ships if it is fully bounded, previewable, and undoable.
- [ ] If navigation execution ships, it has its own dedicated WP 7.0 preview/apply/undo browser proof.
- [ ] No new operation bypasses `template-operation-sequence.js`, deterministic executor code, or shared activity/history infrastructure.
- [ ] No executable PR relied on a later closeout PR for its minimum activity/undo/admin normalization.
- [ ] Epic 4 acceptance tests are recorded in `STATUS.md`.
- [ ] Repo-level completion gates are recorded in `STATUS.md`.
- [ ] Existing surface docs remain the canonical feature docs; no duplicate aggregate feature doc was introduced for already-covered surfaces.
- [ ] Epic 4 is only marked complete in the roadmap after the above are true.
