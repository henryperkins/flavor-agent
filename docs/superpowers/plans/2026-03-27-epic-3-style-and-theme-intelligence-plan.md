# Epic 3 Style and Theme Intelligence Working Plan

> **For agentic workers:** This plan operationalizes only Epic 3 from `docs/2026-03-25-roadmap-aligned-execution-plan.md`. Keep the work inside Gutenberg-native styling and theme tooling. Do not expand scope into free-form CSS generation, a custom design application shell, navigation/tree generation, or higher-level site-agent behavior. Favor a contract-first flow: verify the exact Site Editor mount/scope semantics first, harden style context, add one bounded style ability, then ship one native site-level surface before considering broader Style Book expansion.

**Goal:** Complete the first bounded Epic 3 milestone so Flavor Agent can recommend and apply theme-safe style changes using native WordPress style systems, while satisfying the Epic 3 acceptance criteria and the repo-level completion gates from the roadmap.

**Roadmap anchors:**
1. Epic 3 scope and exit criteria: `docs/2026-03-25-roadmap-aligned-execution-plan.md` lines 339-426.
2. Repo-level completion gates: `docs/2026-03-25-roadmap-aligned-execution-plan.md` lines 593-606.
3. Current migration notes relevant to style scope:
   - `docs/wp7-migration-opportunities.md`
   - `docs/wordpress-7.0-gutenberg-22.8-reference.md`
4. Current surface docs:
   - `docs/features/block-recommendations.md`
   - `docs/reference/abilities-and-routes.md`

**Epic 3 acceptance criteria to satisfy:**
1. Flavor Agent can recommend and apply theme-safe style changes without leaving native WordPress style systems.
2. No generated recommendation proposes a value outside available presets or unsupported design tools.
3. Style intelligence is visibly stronger without falling back to arbitrary CSS.

**Architecture constraints that must remain true:**
1. The current block recommendation flow remains the source of truth for block-scoped settings and style suggestions until the dedicated style contract is ready.
2. New site-level style recommendations must reuse theme tokens and supported-style metadata rather than inventing parallel token parsing.
3. First-party JS remains on feature-specific stores and REST endpoints for v1; do not pivot this epic onto client-side `@wordpress/core-abilities`.
4. First-party surface readiness remains dual-pathed:
   - `check-status` is the diagnostic and external-agent contract,
   - localized editor surface capabilities in `flavorAgentData.capabilities.surfaces` remain the first-party render gate,
   - both paths must expose the same surface keys, reason codes, degraded-mode actions, and any UI-facing configuration metadata used to explain degraded mode,
   - Epic 3 must close the current parity gap instead of treating `globalStyles` as a one-off exception.
5. The first executable style milestone must stay narrow:
   - preset substitutions,
   - registered style variation selection,
   - supported style attributes only.
6. `customCSS` generation remains out of scope for v1 unless the product thesis changes.
7. Width/height preset transforms and pseudo-element-aware token extraction are explicit evaluation items, not silent scope creep for the first implementation slice.

**Current gap summary:**
1. The repo already has strong block-scoped style plumbing:
   - `src/context/theme-settings.js` merges stable and experimental theme settings safely,
   - `src/context/theme-tokens.js` exposes palette, typography, spacing, shadow, layout, element styles, and block pseudo-class styles,
   - `inc/Context/ServerCollector.php` maps current style-relevant block supports into inspector panels,
   - `inc/LLM/Prompt.php` already constrains block style suggestions to theme-safe values.
2. Current style intelligence is still block-inspector scoped. `src/inspector/StylesRecommendations.js` and `src/inspector/SettingsRecommendations.js` render suggestions for the selected block only; there is no site-level style surface yet.
3. There is no style-focused ability or prompt contract. Block, template, and navigation contracts exist, but site-level styling still has no dedicated PHP boundary.
4. The repo already models WP 7.0-adjacent style features such as `dimensions.width`, `dimensions.height`, `customCSS`, and pseudo-class token capture, but those are not yet translated into a bounded Epic 3 milestone.
5. The first site-level style milestone should not try to ship both Global Styles and a full Style Book flow at once. One native site-level recommender surface is enough for the first closeout.
6. Surface capability hydration, interaction contracts, activity history, undo resolution, and admin activity labeling are currently keyed to block/pattern/template/template-part/navigation surfaces. A new site-level style surface must extend those contracts explicitly instead of assuming the existing fallbacks are sufficient.
7. `check-status`, its registered schema, and localized editor surface bootstrap data do not yet share one fully documented readiness shape. Epic 3 must normalize that existing contract drift before `globalStyles` is considered done.
8. Theme-token source diagnostics already exist in the JS adapter/tests, but they are not yet propagated into a server-side style contract or a site-level style surface.

**Primary implementation area:** PHP contract work plus editor/site-editor JS. Epic 3 is not just a docs or prompt tweak; it needs one explicit style contract and one first-party site-level surface.

**Tech stack:** PHP, JavaScript, React via `@wordpress/element`, `@wordpress/components`, `@wordpress/data`, `@wordpress/edit-site`, Jest-style unit tests through `@wordpress/scripts`, Playwright, Markdown docs.

---

### Milestone choice: Global Styles First, Style Book Second

**Decision:** Treat `GlobalStylesRecommender` as the required first Epic 3 surface. Keep `StyleBookRecommender` as a follow-up only if the first contract lands cleanly and there is still clear value in a second UI shell.

**Why this is the right first slice:**
1. It stays closer to native Site Editor style tooling.
2. It gives Epic 3 one bounded executable surface instead of splitting effort across two adjacent UIs.
3. It aligns better with the existing Flavor Agent interaction model:
   - prompt,
   - suggestions,
   - review,
   - apply,
   - undo/activity where owned.
4. It keeps the repo from drifting into a parallel style browser before the underlying contract is stable.

---

### Task 1: Lock The Epic 3 Contract And Scope

**Why:** Epic 3 is broad in the roadmap. The working plan needs one explicit contract boundary before any code lands.

**Files:**
- Modify: `docs/2026-03-25-roadmap-aligned-execution-plan.md` only if Epic 3 scope or sequencing changes materially
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Add: `docs/features/style-and-theme-intelligence.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`
- Modify: `docs/reference/abilities-and-routes.md`

- [ ] **Step 1: Verify the Site Editor mount point and scope contract first**

Before the new style ability or REST route is treated as stable, verify on the WP 7.0 harness:
- the exact package/API/slot used to mount the first site-level style UI,
- the fallback mount point if that slot is missing or unstable,
- how `core/edit-site` reports the current Global Styles context,
- whether the surface resolves a standard edited entity (`postType`/`postId`) or needs an explicit theme/global-styles scope descriptor for activity, undo, and auditing.

Record that prerequisite in the new style feature doc so later tasks do not assume Global Styles behaves like template or template-part editing.

- [ ] **Step 2: Define the first Epic 3 executable surface**

Record that the first required UI is a Site Editor `GlobalStylesRecommender` surface, not a broad Style Book rollout.

The first surface should support only:
- preset substitutions,
- supported site-level style attributes already validated by theme settings and feature flags,
- registered block style variation selection only when the request carries a concrete target block or block-type scope and the apply path can map it deterministically to native registered-style behavior.

- [ ] **Step 3: Record explicit deferrals before implementation**

Document that the first Epic 3 milestone does **not** include:
- raw CSS generation,
- `customCSS` recommendations,
- width/height preset transforms unless a later step proves the preset data path is stable enough,
- pseudo-element-aware token extraction beyond evaluation,
- a second independent style shell if the first site-level surface is not stable yet.

- [ ] **Step 4: Add one style-surface vocabulary**

Reuse the shared Epic 2 review model rather than inventing style-only interaction vocabulary.

Use `global-styles` as the shared JS/activity surface identifier and `globalStyles` as the localized capability key so the store, activity log, and PHP capability payloads do not drift.

If the site-level style surface applies mutations owned by Flavor Agent, it must use:
- shared status notices,
- shared review framing,
- shared activity/undo behavior where feasible,
- aligned readiness labels across `check-status` and localized editor bootstrap data.

---

### Task 2: Harden Theme Token And Style Capability Inputs

**Why:** Epic 3 only works if style recommendations are bounded by stable token and support data.

**Files:**
- Modify: `src/context/theme-settings.js`
- Modify: `src/context/theme-tokens.js`
- Modify: `src/context/collector.js`
- Modify: `inc/Context/ServerCollector.php`
- Modify: `src/context/block-inspector.js`
- Modify: `src/context/__tests__/collector.test.js`
- Modify: `src/context/__tests__/theme-tokens.test.js`
- Modify: `tests/phpunit/ServerCollectorTest.php`
- Modify: `docs/wp7-migration-opportunities.md` only if a migration note becomes a committed implementation decision

- [ ] **Step 1: Make source-parity diagnostics explicit**

Do not rewrite the current stable-plus-experimental adapter speculatively. `theme-settings.js` already exposes source/settings-key/reason diagnostics and the JS tests already cover stable parity and fallback behavior.

For the first Epic 3 slice, propagate those existing diagnostics into the token payloads consumed by the new style contract and any upgraded block-style flows so the implementation can answer:
- which theme token source was used,
- whether the stable `features` path had parity,
- whether the contract is relying on experimental fallback for data the style surface needs.

If server-side style code needs the same visibility, mirror the existing source/reason contract through `ServerCollector::for_tokens()` or a style-specific context payload rather than inventing a second token-source adapter.

This should be observable in tests before any new UI consumes it.

- [ ] **Step 2: Expand style metadata only where the first UI needs it**

Extend the token manifest and/or server collector only for data required by the first site-level surface, for example:
- registered style variations relevant to the targeted style surface,
- token origin or preset metadata when it changes recommendation quality,
- clearer enabled/disabled style capability flags for review-time validation.

Do **not** widen token collection speculatively.

- [ ] **Step 3: Treat width/height and pseudo-elements as gated evaluations**

For this milestone, decide explicitly whether the available WP 7.0 data is good enough for:
- width/height preset-aware recommendations,
- pseudo-element-aware token extraction.

If the answer is "not yet", keep them documented as deferred and covered by tests proving they remain excluded from the first executable path.

- [ ] **Step 4: Keep `customCSS` out of the contract**

The server collector may continue to model `customCSS` support as inspector metadata, but Epic 3 must not generate or apply `style.css` mutations in the first slice.

Add regression coverage that makes this exclusion explicit if needed.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter '(ServerCollectorTest)'
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/context/__tests__/collector.test.js src/context/__tests__/theme-tokens.test.js
```

Expected: PASS after token/capability inputs are stable.

---

### Task 3: Add A Dedicated Style Ability And Prompt Contract

**Why:** Epic 3 should stop overloading the block prompt for site-level style work.

**Files:**
- Add: `inc/Abilities/StyleAbilities.php`
- Add: `inc/LLM/StylePrompt.php`
- Modify: `inc/Abilities/Registration.php`
- Modify: `inc/Abilities/InfraAbilities.php`
- Modify: `inc/OpenAI/Provider.php` only if provider selection logic needs explicit style-path reuse
- Modify: `inc/REST/Agent_Controller.php`
- Modify: `tests/phpunit/AgentControllerTest.php`
- Modify: `tests/phpunit/RegistrationTest.php`
- Add: `tests/phpunit/StyleAbilitiesTest.php`
- Add: `tests/phpunit/StylePromptTest.php`
- Modify: `tests/phpunit/InfraAbilitiesTest.php`
- Modify: `docs/reference/abilities-and-routes.md`

- [ ] **Step 1: Create one style-focused PHP contract**

Add a new style ability for site-level style intelligence instead of piggybacking on block recommendations.

The ability should accept only the minimum context needed for the first site-level surface, for example:
- the resolved Site Editor scope descriptor from Task 1,
- current site-editor style context,
- targeted block or style scope if applicable,
- active theme tokens and capability flags,
- optional user prompt.

- [ ] **Step 2: Add a dedicated style prompt**

Create `StylePrompt.php` with rules specific to site-level style work:
- use only known presets and supported style tools,
- never invent raw CSS when a preset-backed path exists,
- distinguish style-variation selection from attribute updates,
- preserve the "review before mutation" rule for site-level changes.

Do not copy the block prompt wholesale. Extract shared rules where helpful, but keep the contract style-specific.

- [ ] **Step 3: Expose the contract through existing v1 runtime boundaries**

Because first-party JS still uses feature-specific REST endpoints and stores, add the corresponding REST route for the site-level style surface rather than introducing client-side abilities runtime usage. Do that only after Task 1 has frozen the mount-point and scope semantics so the route schema does not guess at the wrong entity model.

Add schema and thin-route regression coverage in `RegistrationTest` and `AgentControllerTest` rather than relying only on new style-specific unit tests.

- [ ] **Step 4: Surface readiness through `check-status`**

If Epic 3 introduces a new surface, `check-status` should report whether it is actually ready for the editor/site editor to render.

That includes enough data to explain degraded mode without per-surface guesswork.

Close the existing mismatch between `check-status` and the first-party localized surface-capability map instead of extending only one side. The Site Editor still boots from `flavorAgentData.capabilities.surfaces`, so PHP bootstrap and `check-status` must expose the same surface keys, reason codes, degraded-mode actions, and any UI-facing configuration metadata used by the editor.

Do not treat runtime-only payload changes as sufficient. Update the registered `flavor-agent/check-status` output schema in `Registration.php` so the structured `surfaces` payload is part of the documented external contract for the existing editor/site-editor surfaces, then add `surfaces.globalStyles` through that same shared shape. Cover that schema in `RegistrationTest` alongside the runtime assertions in `InfraAbilitiesTest`.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter '(StyleAbilitiesTest|StylePromptTest|InfraAbilitiesTest|RegistrationTest|AgentControllerTest)'
```

Expected: PASS after the PHP contract, route schemas, and readiness diagnostics are stable.

---

### Task 4: Deepen Existing Block Style Intelligence Before Adding The New Surface

**Why:** The block inspector already ships style suggestions and is the safest place to validate stronger token-aware reasoning before adding a new site-level UI.

**Files:**
- Modify: `inc/LLM/Prompt.php`
- Modify: `src/inspector/StylesRecommendations.js`
- Modify: `src/inspector/SettingsRecommendations.js`
- Modify: `src/inspector/suggestion-keys.js`
- Modify: `src/inspector/__tests__/StylesRecommendations.test.js`
- Modify: `src/inspector/__tests__/SettingsRecommendations.test.js`
- Modify: `src/inspector/suggestion-keys.test.js`
- Modify: `docs/features/block-recommendations.md`

- [ ] **Step 1: Improve theme-safe recommendation quality**

Strengthen current block-scoped style suggestions using the richer token/capability data from Task 2.

Focus on:
- better preset usage,
- clearer style variation reasoning,
- stronger rejection of unsupported controls,
- cleaner grouping between settings and style suggestions.

- [ ] **Step 2: Keep current block surface bounded**

Do not turn block inspector recommendations into a pseudo-site-style surface.

This task is about recommendation quality and safety, not expanding the inspector into a second Epic 3 product surface.

- [ ] **Step 3: Add regressions for style-specific edge cases**

Cover cases such as:
- style variation suggestions rendered separately,
- preset-backed previews and CSS var labels,
- no unsupported recommendation when theme feature flags disable the control,
- aspect-ratio vs height exclusivity remains enforced.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/inspector/__tests__/StylesRecommendations.test.js src/inspector/__tests__/SettingsRecommendations.test.js src/inspector/suggestion-keys.test.js
```

Expected: PASS.

---

### Task 5: Implement The First Native Site-Level Style Surface

**Why:** Epic 3 is not complete until Flavor Agent has one first-party site-level style intelligence surface native to the Site Editor.

**Files:**
- Add: `src/global-styles/GlobalStylesRecommender.js`
- Add: `src/global-styles/__tests__/GlobalStylesRecommender.test.js`
- Add: `src/utils/style-operations.js`
- Add: `src/utils/__tests__/style-operations.test.js`
- Optional later add: `src/style-book/StyleBookRecommender.js`
- Optional later add: `src/style-book/__tests__/StyleBookRecommender.test.js`
- Modify: `package.json` only if the chosen mount point requires direct `@wordpress/edit-site` imports
- Modify: `flavor-agent.php`
- Modify: `src/index.js`
- Modify: `src/store/index.js`
- Modify: `src/store/activity-history.js`
- Modify: `src/store/__tests__/activity-history.test.js`
- Modify: `src/store/__tests__/activity-history-state.test.js`
- Modify: `src/store/__tests__/store-actions.test.js`
- Modify: `src/components/ActivitySessionBootstrap.js`
- Modify: `src/components/__tests__/ActivitySessionBootstrap.test.js`
- Modify: `src/components/AIStatusNotice.js` only if the shared status API needs non-breaking extension
- Modify: `src/components/AIReviewSection.js` only if the shared review API needs non-breaking extension
- Modify: `src/components/AIActivitySection.js` if a new style activity surface is introduced
- Modify: `src/components/__tests__/AIActivitySection.test.js`
- Modify: `src/utils/capability-flags.js`
- Modify: `src/utils/__tests__/capability-flags.test.js`
- Modify: `inc/Activity/Serializer.php`
- Modify: `inc/Activity/Permissions.php`
- Modify: `inc/Activity/Repository.php`
- Modify: `tests/phpunit/ActivityPermissionsTest.php`
- Modify: `tests/phpunit/ActivityRepositoryTest.php`
- Modify: `src/admin/activity-log.js` if the admin log icon/summary mapping needs a site-style surface branch
- Modify: `src/admin/activity-log-utils.js`
- Modify: `src/admin/__tests__/activity-log.test.js`
- Modify: `src/admin/__tests__/activity-log-utils.test.js`
- Modify: `src/editor.css`
- Modify: `tests/phpunit/EditorSurfaceCapabilitiesTest.php`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`
- Add/Modify: `docs/features/style-and-theme-intelligence.md`

- [ ] **Step 1: Attach the new UI to a native Site Editor location**

Implement against the exact WP 7.0-compatible Site Editor extension point verified in Task 1. Use the recorded fallback mount point if the preferred Global Styles-adjacent slot does not render reliably in the harness.

Use that chosen native Site Editor extension point for the first surface. It should feel adjacent to Global Styles rather than like a separate plugin shell.

If the chosen mount point requires direct imports from `@wordpress/edit-site`, add that dependency explicitly in `package.json` instead of relying on transitive availability.

Register the new surface in both `flavor_agent_get_editor_surface_capabilities()` and the shared JS capability reader so degraded mode behaves like the existing template and template-part panels.

If Global Styles does not expose a normal edited entity, define and test an explicit theme/global-styles scope/bootstrap path instead of forcing the surface through existing template/template-part assumptions.

The first UI should support:
- prompt input,
- style suggestions grouped by safe executable category,
- review state for executable changes,
- apply and undo if Flavor Agent owns the mutation.

- [ ] **Step 2: Keep the executable contract narrow**

The new surface may apply only:
- preset substitutions,
- supported style attribute mutations backed by the validated style contract,
- registered block style variation selection only when the suggestion carries a deterministic block target or block-type scope.

If a suggestion cannot be mapped deterministically, it should remain advisory.

- [ ] **Step 3: Reuse the Epic 2 interaction model**

The new style surface should use the same learned-once model already established for:
- loading,
- advisory-ready,
- preview-ready,
- applying,
- success,
- undoing,
- error.

Add `global-styles` to the shared surface interaction contract rather than letting the new panel invent component-local status semantics.

- [ ] **Step 4: Budget explicit style execution and undo plumbing**

Epic 3 exit criteria require applied style changes, so the first executable increment must include explicit style execution and undo plumbing instead of falling through existing block/template behavior.

Treat that as both client-side and server-backed activity-contract work. The first implementation must not log executable `global-styles` actions as generic block activity.

That may require:
- a dedicated style operation helper plus tests,
- a `global-styles` surface identifier in store/activity history,
- activity bootstrap and scope-resolution updates so `global-styles` actions load and persist against the correct theme/global-styles scope even when the Site Editor is not editing a standard `wp_*` entity,
- serializer/entity derivation updates so `global-styles` activity persists as its own theme-scoped surface,
- activity-permission updates so style actions inherit Site Editor/theme capability rules instead of post/block defaults,
- repository ordered-undo/entity-grouping updates so style actions block and resolve against the correct entity trail,
- undo resolution for style mutations,
- admin activity normalization, labeling, and icon updates.

If a proposed change cannot be executed and undone deterministically, keep that suggestion advisory and do not record it as executable activity.

- [ ] **Step 5: Do not force Style Book into the same PR by default**

Only add `StyleBookRecommender` if:
- the Global Styles recommender contract is already passing tests,
- the second surface does not duplicate logic,
- there is still distinct product value.

Otherwise, leave Style Book as the next bounded follow-up.

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter '(EditorSurfaceCapabilitiesTest|ActivityPermissionsTest|ActivityRepositoryTest)'
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/global-styles/__tests__/GlobalStylesRecommender.test.js src/utils/__tests__/style-operations.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js src/components/__tests__/ActivitySessionBootstrap.test.js src/utils/__tests__/capability-flags.test.js src/components/__tests__/AIActivitySection.test.js src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js
```

Expected: PASS after the first surface, capability gating, and executable undo path are stable.

---

### Task 6: Verification, Browser Coverage, And Docs Closeout

**Why:** Epic 3 is only complete when the contract, UI, tests, and docs all converge.

**Files:**
- Modify: `STATUS.md`
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`
- Modify: `docs/reference/abilities-and-routes.md`
- Add/Modify: `docs/features/style-and-theme-intelligence.md`
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`
- Modify: `scripts/wp70-e2e.js` only if the WP 7.0 harness changes are required for stable style coverage

- [ ] **Step 1: Add WP 7.0 browser coverage for one style flow**

The required browser test should prove one end-to-end style recommendation path in the Site Editor:
- open the new style surface,
- request a recommendation,
- preview/apply one bounded style change,
- verify the mutation landed as expected,
- verify the mounted slot and resolved activity scope match the contract locked earlier in the plan,
- verify undo/history if the surface owns execution.

- [ ] **Step 2: Rerun repo-level gates**

Epic 3 closeout should include:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
composer lint:php
vendor/bin/phpunit
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run lint:js
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70
```

Expected: PASS, with any host-level Docker limitation called out explicitly if it blocks the WP 7.0 browser run.

- [ ] **Step 3: Update canonical docs**

Before Epic 3 is considered complete, update:
- `STATUS.md`,
- `docs/SOURCE_OF_TRUTH.md`,
- `docs/FEATURE_SURFACE_MATRIX.md`,
- `docs/reference/abilities-and-routes.md`,
- the new style feature doc.

The docs should make clear:
- which style surface shipped,
- what is executable vs advisory,
- what remains deferred,
- which provider/runtime boundaries still apply.

---

### Recommended PR Breakdown

**PR 1: Token and contract hardening**
- Task 1
- Task 2
- Task 3

**PR 2: Block style quality improvements**
- Task 4

**PR 3: First site-level style surface**
- Task 5

**PR 4: Browser verification and docs closeout**
- Task 6

Do not mix the first site-level surface with speculative Style Book work unless the implementation is already clearly sharing the same contract and test scaffolding.

---

### Definition Of Done For Epic 3

Epic 3 can be marked complete when all of the following are true:

1. A dedicated style-focused PHP and REST contract exists and is covered by tests.
2. `check-status`, its registered output schema, and localized editor surface capabilities agree on surface readiness through one documented `surfaces` contract for existing and new editor/site-editor surfaces, including the same surface keys, reason codes, degraded-mode actions, and any exposed configuration metadata used by the UI.
3. Theme token and style-capability inputs are explicit enough that unsupported controls are filtered before the UI presents them as executable.
4. The block inspector style experience is stronger and still theme-safe.
5. One native Site Editor style surface is shipped, registered as the shared `global-styles` surface, and uses explicit execution/undo plumbing for every executable mutation across both the client state layer and the server-backed activity/audit contract.
6. Browser coverage proves one bounded style preview/apply path on the WP 7.0 harness.
7. Docs explicitly record what shipped and what remains deferred, including:
   - `customCSS`,
   - width/height preset transforms if still uncommitted,
   - pseudo-element-aware extraction if still uncommitted,
   - broader Style Book expansion if not included in the first slice.
