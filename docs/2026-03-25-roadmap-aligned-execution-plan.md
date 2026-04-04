# Flavor Agent Roadmap-Aligned Execution Plan

> Created: 2026-03-25
> Scope: repo-specific execution plan for the current 5-epic roadmap
> External alignment snapshot verified: 2026-04-03
> Support target: WordPress 7.0 RC -> stable, PHP 8.0+, Node 24 / npm 11 default with Node 20 / npm 10 compatibility
> Scope anchor: Gutenberg 22.9 RC line (RC date 2026-04-01; planned stable date 2026-04-08 per the release checklist). Last stable plugin baseline: `22.8.1` (released 2026-03-26)
> Baseline: current repo already ships block, pattern, template, template-part, navigation, global styles, style book, and activity surfaces
> Supplemental execution compression: `docs/2026-04-03-three-phase-roadmap.md`

## Goal

Evolve Flavor Agent so it feels like Gutenberg and wp-admin became smarter, not like a second product was bolted onto WordPress.

This plan intentionally aligns with:

1. WordPress 7.0 release work and APIs already on the roadmap.
2. Gutenberg editor directions already visible in the 7.0 cycle.
3. The official WordPress AI plugin and AI team "building blocks" direction.

It intentionally does **not** align with an Elementor-style AI code generator or sandbox-builder product.

## Alignment Snapshot

These are the external constraints this repo should treat as current on 2026-04-03:

1. WordPress 7.0 is still in release-candidate phase, but the cycle was formally extended on **March 31, 2026** and the final release date is now pending an updated Core timeline. Build for the 7.0 API surface now, but keep experimental integrations adapter-backed until after the stable release and avoid coupling new work to unresolved collaboration internals.
2. The WordPress 7.0 planning thread highlighted and later refined:
   - Abilities and Workflows API, with the client-side Abilities work landing more concretely than the broader Workflows ambition during the 7.0 cycle
   - WP client AI API
   - DataViews and DataForms iterations
   - revisions with visual diffs and better undo/review affordances
   - server-side creation of blocks and patterns
   - navigation overlay work as a template-part-aware flow
   - by the late-cycle planning update, Design System work was explicitly canceled for 7.0, so treat that as adjacent ecosystem direction rather than a 7.0 dependency for this plugin
3. Gutenberg 22.9 entered release-candidate phase on 2026-04-01, with the release checklist targeting 2026-04-08 for the stable plugin release. Treat the 22.9 RC line as the current pre-release alignment signal, while keeping `22.8.1` as the last stable plugin baseline:
   - `22.9` is the correct late-cycle compatibility probe for editor/admin contract drift during the extended WordPress 7.0 cycle
   - `22.8.0` (2026-03-25) expanded Connectors with registry extensibility, unregister/upsert support, and better empty-state polish
   - `22.8.0` also advanced editor-adjacent surfaces relevant to this plugin, including Global Styles pseudo-selector state UI, pattern-editing/block-fields selection highlighting, and navigation/site-design polish
   - `22.8.1` (2026-03-26) remains the last stable plugin release in the line and should be treated as the stable bugfix baseline until 22.9 ships
4. The official `AI` plugin now positions itself as the reference implementation for:
   - inline/editor-native AI features
   - Connectors-based provider setup
   - opt-in feature toggles
   - review-oriented UX such as Review Notes
   - roadmap items like contextual tagging, comment moderation, logging/observability, content assistant, and site agent
5. The AI team is still treating the official plugin as a features-as-plugins lab, not a signal to duplicate provider setup or invent parallel abstractions.

## Planning Assumptions

1. `Settings > Connectors` remains the primary provider setup for anything core AI already covers.
2. Flavor Agent continues to own non-core infrastructure where needed, especially retrieval, ranking, grounding, and diagnostics.
3. Abilities remain the public contract boundary. External-agent or MCP exposure is secondary to the first-party editor/admin UX.
4. Suggestions should stay tightly coupled to WordPress entities and nouns:
   - blocks
   - patterns
   - template parts
   - navigation structures
   - style presets
   - theme tokens
   - post or template entities
5. Cross-entity or structural writes must remain previewable, validated, and undoable.
6. New features should prefer native editor surfaces before new admin pages, and new admin pages before custom application shells.

## Current-Reality Note

This plan uses the current source tree as the baseline, not older high-level docs that still undersell parts of the implementation.

In current code:

1. Navigation recommendations already have:
   - a registered ability
   - a plugin REST route
   - an inline inspector surface for `core/navigation`
2. Activity is already persisted server-side through the activity repository and REST endpoints, even though the UI still behaves like a lightweight editor-scoped history surface.
3. A sitewide admin audit slice already exists under `Settings > AI Activity`, backed by `DataViews` / `DataForm` over the same server-backed activity repository.
4. The Gutenberg trunk-alignment pass is now part of the baseline:
   - newer typography supports (`typography.fitText`, `typography.textIndent`) are modeled in both support collectors
   - `listView` is modeled as the dedicated `list` inspector surface, not generic settings
   - bindings-aware prompt routing is aligned with the dedicated bindings inspector slot, with bindable attributes collected into block context
   - pattern API docs/tests now treat `__experimental*` pattern settings and selectors as the current upstream baseline, with stable keys treated as future-facing probes
5. The first-party JS still does not consume `@wordpress/core-abilities`, so client-side Abilities runtime usage remains a future admin-side integration opportunity rather than current baseline behavior.
6. Several WP 7.0 migration opportunities remain intentionally uncommitted until they are translated into bounded milestone work, especially client-side Abilities usage, Pattern Overrides-aware recommendations, and expanded `contentOnly` structural constraints.

That means the next milestones should build forward from those foundations instead of re-planning them from scratch, especially in Epic 5 where the first admin audit slice is already shipped.

## Immediate Gap-Closure Queue

Before expanding the roadmap into broader new surfaces, the clearest next steps are:

1. **Finish Epic 1 shared capability UX.** (Completed 2026-03-27)
   - Shared capability/why-unavailable UI now covers block, pattern, navigation, template, and template-part surfaces.
   - `check-status` now exposes the exact surface-ready flags the editor needs instead of requiring each surface to infer degraded-mode behavior independently.
2. **Finish Epic 2 unified inline review model.** (Completed 2026-03-27)
   - Block, navigation, template, and template-part surfaces now share normalized interaction states and shared advisory/review/status components.
   - Block keeps explicit inline apply only for safe local attribute updates, while template and template-part stay preview-first and navigation stays advisory-only.
   - A thin `src/review/notes-adapter.js` shape adapter now exists for future Notes/comment projection without taking a runtime dependency on unstable APIs.
3. **Finish Epic 3 style/theme closeout.** (Completed 2026-04-03)
   - Planning docs and `STATUS.md` now treat the shipped Global Styles and Style Book surfaces as current baseline rather than future implementation.
   - The focused WP 7.0 Global Styles smoke now has a fresh passing result recorded in `STATUS.md`.
4. **Deepen the shipped audit slice before adding broader agent behavior.**
   - Expand `Settings > AI Activity` with before/after inspection, request/provider diagnostics, and clearer ordered-undo visibility.
   - Keep the server-backed repository as the source of truth for undo eligibility and diagnostics.
5. **Record the navigation contract explicitly.**
   - Navigation stays advisory-only through v1.0; do not add an apply contract in the current milestone.
   - Revisit only if a bounded previewable/undoable navigation executor becomes its own tracked follow-up.
6. **Refresh live provider-backed verification.** (Completed 2026-04-04)
   - A fresh Azure-backed end-to-end recommendation execution was captured in `STATUS.md`.
   - That run confirmed the current provider boundary and real request provenance path under live credentials.
7. **Switch from RC/beta assumptions to stable WordPress 7.0 as soon as available.**

   - Update the WP 7.0 Docker/browser harness to the stable image tag.
   - Re-audit experimental adapters (`pattern-settings.js`, theme settings sources, and any remaining trunk-sensitive inspector modeling) against final 7.0 core.

8. **Record the remaining WP 7.0 feature-surface decisions that were previously only noted in migration analysis.**
   - Client-side `@wordpress/core-abilities` consumption stays deferred for v1; first-party JS remains on feature-specific stores and REST endpoints until a narrow admin/runtime integration is separately scoped.
   - Pattern Overrides support for custom blocks stays deferred until there is a bounded metadata contract worth feeding into ranking and review UI.
   - Expanded `contentOnly` semantics are a tracked structural-constraint update for the later structural milestone, not an implicit current-surface expansion.
   - The first Style milestone does not include width/height preset transforms or pseudo-element-aware token extraction; both stay deferred until style intelligence has its own bounded slice.
   - `customCSS` recommendation generation is explicitly out of scope for v1 unless the product thesis changes.

Only after those eight items land should the roadmap move aggressively into broader structural or higher-level site-agent surface work.

## Ordered Execution

1. Epic 1: Core AI Convergence and Capability Gating (Completed 2026-03-27)
2. Epic 2: Unified Inline Review Model (Completed 2026-03-27)
3. Epic 3: Style and Theme Intelligence (Closed 2026-04-03)
4. Epic 4: Structural Site-Building Intelligence
5. Epic 5: Durable Audit, Observability, and Narrow Site Agent

The order matters.

Epic 1 reduced architectural drift from WordPress core and the official AI plugin.
Epic 2 made the existing surfaces feel like one product.
Epic 3 pushed deeper into native theme and style tooling and is now closed as shipped baseline.
Epic 4 expands structural power without leaving Gutenberg semantics.
Epic 5 adds the durable trust and narrow admin action layer needed before broader agentic behavior.

## Epic 1: Core AI Convergence and Capability Gating

### Objective

Align Flavor Agent's runtime boundaries with the WordPress 7.0 AI stack so the plugin layers on top of core AI primitives rather than competing with them.

### Why This Comes First

This repo already mixes:

1. core AI Client usage for block recommendations
2. plugin-managed provider selection for pattern, template, template-part, and navigation ranking
3. plugin-managed retrieval and grounding infrastructure

That split is sensible today, but the UX and settings boundaries need to be made more explicit and more stable before adding new features.

### Progress In Tree

Some of Epic 1's settings-boundary work is now already in the tree:

1. `Provider.php` now models OpenAI Native credential precedence, connector registration, and connector key-source metadata instead of treating the core connector as only a raw option fallback.
2. `InfraAbilities::check_status()` now reports `credentialSource`, `connectorRegistered`, `connectorConfigured`, and `connectorKeySource` for the `openai_native` backend.
3. `Settings.php` now tells the user which OpenAI Native credential source is currently effective, whether the core OpenAI connector is registered/configured, and which backend ownership boundary belongs to `Settings > Connectors` vs `Settings > Flavor Agent`.
4. Shared capability notices now exist across block, pattern, navigation, template, and template-part surfaces, and `check-status` reports surface-ready states instead of only backend fragments.
5. The Epic 1 acceptance suite now has current verification entries in `STATUS.md` for the roadmap PHP filter, the JS store filter, and disabled-state browser smokes.

### In Scope

1. Normalize capability gating and empty-state UX across all first-party surfaces.
2. Make it obvious which surfaces rely on:
   - core Connectors / AI Client
   - plugin-owned retrieval or ranking backends
3. Tighten `check-status` and per-surface availability reporting.
4. Reduce duplicate provider messaging in UI copy and settings.
5. Keep the current connector fallback behavior, but make the responsibility boundaries easier to reason about.
6. Make an explicit decision about client-side Abilities runtime usage:
   - either keep first-party JS on feature-specific stores/endpoints for v1
   - or add a narrow admin/runtime integration plan for `@wordpress/core-abilities`
   - but do not leave this as an untracked future-facing note.

### Non-Goals

1. Moving embeddings, Qdrant, or Cloudflare AI Search into core.
2. Replacing provider-selected ranking for pattern/template/navigation surfaces.
3. Shipping new end-user features.

### File Targets

Existing files:

- `flavor-agent.php`
- `inc/OpenAI/Provider.php`
- `inc/LLM/WordPressAIClient.php`
- `inc/Abilities/InfraAbilities.php`
- `inc/Settings.php`
- `inc/REST/Agent_Controller.php`
- `src/store/index.js`
- `src/inspector/InspectorInjector.js`
- `src/inspector/NavigationRecommendations.js`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`

Likely new files only if extraction becomes necessary:

- `src/components/CapabilityNotice.js`
- `src/utils/capability-flags.js`

### Implementation Slices

1. Standardize localized capability flags in `flavor-agent.php` so every UI surface reads from one coherent model.
2. Expand `InfraAbilities::check_status()` so it reports surface-ready capabilities at the same granularity the editor needs.
3. Refactor first-party surfaces to use shared capability and "why unavailable" messaging instead of ad hoc copy.
4. Update `Settings.php` to make "core-managed vs plugin-managed" backend ownership explicit.
5. Keep connector fallback support in `Provider.php`, but document and test the precedence clearly.
6. Where core AI provider availability is being inferred from custom option checks, evaluate replacing or supplementing those checks with connector-registry-aware lookups (`wp_get_connector()`, `wp_is_connector_registered()`) when that reduces ambiguity without weakening support for fallback credential sources.
7. Record the v1 stance on client-side Abilities consumption in docs and tests:
   - if deferred, state the boundary clearly
   - if adopted, keep the first integration narrow and admin-scoped rather than broad UI rewiring.

### Acceptance Tests

PHP:

- `vendor/bin/phpunit --filter '(InfraAbilitiesTest|SettingsTest|WordPressAIClientTest|RegistrationTest)'`

JS:

- `npm run test:unit -- --runInBand src/store/__tests__/pattern-status.test.js src/store/__tests__/navigation-request-state.test.js src/store/__tests__/store-actions.test.js`

Browser:

- add or update one editor smoke proving correct disabled-state messaging for at least:
  - block recommendations without Connectors
  - pattern/template surfaces without plugin-owned ranking backends

### Exit Criteria

1. A user can tell, from native UI alone, what backend is missing and where to configure it.
2. All first-party surfaces use the same capability vocabulary and degraded-mode behavior.
3. No new feature work depends on undocumented provider assumptions.

## Epic 2: Unified Inline Review Model (Completed 2026-03-27)

### Objective

Make Flavor Agent feel like one coherent Gutenberg-native review system instead of several adjacent AI surfaces with slightly different rules.

### Why This Comes Second

The repo already has the right pieces:

1. inline block apply
2. advisory navigation guidance
3. preview-confirm-apply template and template-part flows
4. activity and undo

What is missing is one clear interaction model that users can learn once and trust across surfaces.

### Progress In Tree

1. `src/store/index.js` now exposes normalized interaction selectors and one shared state vocabulary for block, navigation, template, and template-part surfaces.
2. `AIStatusNotice`, `AIAdvisorySection`, and `AIReviewSection` now provide the shared review/status shells across the existing editor surfaces.
3. Block now explicitly explains why safe local attribute updates may apply inline, while template and template-part remain preview-first and navigation remains advisory-only.
4. `AIActivitySection` remains the shared history block, so activity and undo presentation is now aligned wherever Flavor Agent owns an executable path.
5. `src/review/notes-adapter.js` adds an optional shape-only adapter for future Notes/comment projection without wiring unstable runtime integrations into Epic 2.

### In Scope

1. Establish one shared mental model:
   - prompt
   - suggestions
   - explanation
   - review where needed
   - apply where allowed
   - undo and history
2. Normalize success, error, undo, and "advisory only" states.
3. Keep single-block attribute updates lightweight while preserving review-first behavior for structural changes.
4. Prepare for future Notes-style suggestions without taking a hard dependency on unstable APIs.

### Non-Goals

1. A floating assistant/chat workspace.
2. Full Notes integration if the Notes API surface is not stable enough.
3. Cross-document approval queues.

### File Targets

Existing files:

- `src/components/AIActivitySection.js`
- `src/components/ActivitySessionBootstrap.js`
- `src/store/index.js`
- `src/store/activity-history.js`
- `src/inspector/InspectorInjector.js`
- `src/inspector/NavigationRecommendations.js`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/editor.css`

Likely new files:

- `src/components/AIReviewSection.js`
- `src/components/AIStatusNotice.js`
- `src/components/AIAdvisorySection.js`
- `src/review/notes-adapter.js` (adapter-backed, optional, do not hard-wire early)

### Implementation Slices

1. Extract shared review and status components from the existing block, navigation, template, and template-part panels.
2. Normalize action state in the store:
   - idle
   - loading
   - advisory-ready
   - preview-ready
   - applying
   - success
   - undoing
   - error
3. Unify the copy and affordances for:
   - advisory-only suggestions
   - executable suggestions
   - preview-before-apply flows
4. Keep block-level inline apply for safe local changes, but make the reasoning for "why this one applies inline" explicit and consistent.
5. Add a thin adapter layer for future Notes projection so recommendation evidence can later surface in Notes/comments without rewiring each panel.

### Acceptance Tests

JS:

- `npm run test:unit -- --runInBand src/components/__tests__/AIActivitySection.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js src/inspector/__tests__/NavigationRecommendations.test.js src/templates/__tests__/TemplateRecommender.test.js`

Browser:

- extend `tests/e2e/flavor-agent.smoke.spec.js` to verify:
  - block inline apply + undo
  - navigation remains advisory-only
  - template preview-confirm-apply
  - template-part preview-confirm-apply

### Exit Criteria

1. The same user can move between all Flavor Agent surfaces without relearning the interaction model.
2. Structural actions are always reviewable before mutation.
3. Activity and undo presentation are consistent across all surfaces.

## Epic 3: Style and Theme Intelligence (Closed 2026-04-03)

### Objective

Push Flavor Agent deeper into Gutenberg-native styling and theme tooling by expanding design-aware assistance around `theme.json`, presets, block supports, style variations, and Style Book / Global Styles-adjacent flows.

### Why This Comes Third

This is the clearest way to push beyond current WordPress.com-style integration while still respecting Gutenberg instead of bypassing it.

### Progress In Tree

The first bounded Epic 3 slice is already implemented in current code:

1. `inc/Abilities/StyleAbilities.php`, `inc/LLM/StylePrompt.php`, `inc/REST/Agent_Controller.php`, and `inc/Abilities/Registration.php` now provide the shared style ability, prompt contract, REST route, and schema coverage for both `global-styles` and `style-book` scopes.
2. `src/global-styles/GlobalStylesRecommender.js` and `src/style-book/StyleBookRecommender.js` now ship the first-party Site Editor style surfaces, with native sidebar/portal integration instead of a custom shell.
3. `src/utils/style-operations.js` plus `src/store/index.js` now provide deterministic Global Styles and Style Book apply/undo behavior rather than treating style work as advisory-only.
4. `inc/Abilities/SurfaceCapabilities.php`, localized surface bootstrapping, and `flavor-agent/check-status` now include the shared `globalStyles` and `styleBook` readiness contracts instead of treating style surfaces as one-off exceptions.
5. Global Styles and Style Book actions are now wired into shared activity persistence, editor hydration, undo validation, and admin audit handling through the existing client/server activity layers.
6. The current docs and tests already include the style feature doc, route/ability references, PHPUnit coverage for style contracts/readiness, JS coverage for Global Styles and Style Book behavior, and a WP 7.0 smoke for preview/apply/undo on the executable Global Styles path.

### Delivered Scope

1. Improve current block style recommendations using richer token and support awareness.
2. Add first-party Flavor Agent surfaces for site-level and per-block style intelligence that stay native to Site Editor style tooling.
3. Keep all output grounded in theme tokens, supported design tools, registered style variations, and existing WordPress style semantics.
4. Keep the first executable contract narrow:
   - validated `set_styles` operations for Global Styles scope
   - validated `set_block_styles` operations for Style Book scope
   - registered theme style variation selection
   - validated supported style paths only
5. Carry the style surfaces through the shared review, capability, activity, and undo model rather than inventing a separate interaction system.

### Non-Goals

1. Raw CSS generation as a default path.
2. A custom visual design tool outside the Site Editor.
3. A parallel design system.
4. Per-block `customCSS` recommendation generation, unless a later product decision explicitly expands the style contract beyond theme-token-safe and supported-tooling-safe transforms.

### File Targets

Existing files:

- `src/context/theme-settings.js`
- `src/context/theme-tokens.js`
- `inc/Context/ServerCollector.php`
- `inc/LLM/Prompt.php`
- `inc/Abilities/InfraAbilities.php`
- `src/inspector/StylesRecommendations.js`
- `src/inspector/SettingsRecommendations.js`
- `src/utils/visible-patterns.js`
- `src/utils/structural-identity.js`
- `inc/Abilities/StyleAbilities.php`
- `inc/LLM/StylePrompt.php`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/style-book/dom.js`
- `src/utils/style-operations.js`
- `src/store/index.js`
- `src/store/activity-history.js`
- `src/components/AIActivitySection.js`
- `src/components/ActivitySessionBootstrap.js`

### Closure Evidence

1. Planning docs and `STATUS.md` now describe the Global Styles and Style Book style contract and shipped surfaces as current baseline.
2. A fresh WP 7.0 browser result is now recorded for the focused Global Styles preview/apply/undo path in `STATUS.md`.
3. Deferred follow-ups remain explicitly deferred rather than being mistaken for missing shipped work.

### Remaining Follow-Up Slices After Closure

1. After WordPress 7.0 stable images exist and Core announces the final release timeline, swap the beta-tagged WP 7.0 harness image and re-audit the style surface against the stable runtime.
2. Keep width/height preset transforms, pseudo-element-aware extraction, and any deeper second-stage Style Book expansion as bounded follow-ups rather than treating them as missing pieces of the shipped slice.
3. Keep `customCSS` recommendation generation explicitly out of scope for v1 unless the product thesis changes.

### Acceptance And Verification

PHP:

- `vendor/bin/phpunit --filter '(StyleAbilitiesTest|StylePromptTest|InfraAbilitiesTest|RegistrationTest|AgentControllerTest|ServerCollectorTest|EditorSurfaceCapabilitiesTest|ActivityPermissionsTest|ActivityRepositoryTest)'`

JS:

- `npm run test:unit -- --runInBand src/context/__tests__/collector.test.js src/context/__tests__/theme-tokens.test.js src/inspector/__tests__/StylesRecommendations.test.js src/inspector/__tests__/SettingsRecommendations.test.js src/inspector/suggestion-keys.test.js src/global-styles/__tests__/GlobalStylesRecommender.test.js src/style-book/__tests__/StyleBookRecommender.test.js src/utils/__tests__/style-operations.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js src/components/__tests__/ActivitySessionBootstrap.test.js src/utils/__tests__/capability-flags.test.js src/components/__tests__/AIActivitySection.test.js src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js`

Browser:

- `npm run test:e2e:wp70 -- --reporter=line -g "global styles surface previews, applies, and undoes executable recommendations"`

## Epic 4: Structural Site-Building Intelligence

### Objective

Expand deterministic site-building help for templates, template parts, navigation, and nearby site-editor structure without drifting into free-form tree generation.

### Why This Comes Fourth

The repo already has validated composition primitives. The next step is to increase their range while preserving structural safety.

### In Scope

1. Expand template and template-part executable operations in bounded ways.
2. Improve navigation guidance and, where safe, add validated navigation actions later.
3. Improve structural context in prompts and collectors.
4. Build on the now-modeled `list` inspector surface only if a dedicated UI layer becomes necessary; do not invent a separate site-structure module by default.
5. Evaluate whether Pattern Overrides-aware metadata should influence recommendation quality for patterns used in custom-block-heavy flows, especially where WordPress 7.0 broadens override support via bindings-related infrastructure.
6. Re-audit structural recommendation safety against expanded `contentOnly` behavior in WordPress 7.0, especially for unsynced patterns, template parts, and parent/child insertion constraints.

### Non-Goals

1. Free-form block tree rewrites.
2. Arbitrary code generation.
3. Unvalidated multi-step mutation pipelines.

### File Targets

Existing files:

- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/Context/ServerCollector.php`
- `inc/LLM/TemplatePrompt.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/LLM/NavigationPrompt.php`
- `inc/REST/Agent_Controller.php`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/inspector/NavigationRecommendations.js`
- `src/utils/template-actions.js`
- `src/utils/template-operation-sequence.js`
- `src/utils/template-part-areas.js`

### Implementation Slices

1. Extend template operations carefully beyond the current contract:
   - bounded placement targeting
   - safe replacement flows
   - limited move operations only when validation is deterministic
2. Continue expanding template-part operations from the current bounded contract, with snapshot-based undo and stable-locator coverage remaining the prerequisite for any wider mutation set.
3. Deepen navigation context:
   - overlay/template-part awareness
   - block focus cues
   - stronger bound page and structure reasoning
4. Use the existing `list` inspector modeling and block-context collectors before introducing any dedicated site-structure surface.
5. Keep all new structural operations passing through `template-operation-sequence.js` and executor validation.
6. If Pattern Overrides support is adopted, keep the first increment recommendation-oriented rather than mutation-oriented:
   - collect override-relevant metadata
   - feed it into pattern recommendation/ranking or explanation
   - avoid introducing new structural writes until the value is proven.
7. Extend structural validation and recommendation filtering to account for newer `contentOnly` semantics:
   - unsynced pattern defaults where relevant
   - `"contentOnly": true` / `contentRole`-style modeling
   - parent/child insertion constraints before suggestions are presented as executable.

### Acceptance Tests

PHP:

- `vendor/bin/phpunit --filter '(Template|Navigation|AgentController|ServerCollector)'`

JS:

- `npm run test:unit -- --runInBand src/utils/__tests__/template-actions.test.js src/templates/__tests__/TemplateRecommender.test.js src/inspector/__tests__/NavigationRecommendations.test.js src/context/__tests__/block-inspector.test.js`

Browser:

- `npm run test:e2e:wp70 -- --reporter=line`
- add dedicated browser coverage for:
  - template-part executable flow
  - navigation recommendation flow
  - at least one new bounded structural operation

### Exit Criteria

1. Structural recommendations are more useful than today without losing deterministic validation.
2. Template-part and navigation flows remain clearly bounded and undoable.
3. New operations do not bypass existing validation and history infrastructure.

## Epic 5: Durable Audit, Observability, and Narrow Site Agent

### Objective

Turn activity into a durable trust layer first, then add a small, roadmap-aligned set of higher-level AI admin actions.

### Why This Comes Fifth

The official AI plugin roadmap explicitly points toward logging, observability, contextual tagging, comment moderation, content assistance, and a future site agent. Flavor Agent should not skip straight to broad agent actions without first shipping durable trust and review infrastructure.

### Progress In Tree

Some of Epic 5 Stage A is already in the tree:

1. `inc/Admin/ActivityPage.php` and `src/admin/activity-log.js` already ship a `Settings > AI Activity` admin screen.
2. That screen already uses WordPress `DataViews` and `DataForm` over the shared server-backed activity repository.
3. Editor-scoped activity and undo already round-trip through the repository and REST layer.

Remaining work is deeper inspection, broader observability, and any future narrow site-agent actions.

### In Scope

Stage A: audit and observability

1. Expand the existing activity foundation and admin-visible audit surface.
2. Add before/after inspection, ordered undo eligibility, and better diagnostics.
3. Add request logging and backend observability appropriate for plugin-owned AI calls.

Stage B: narrow site agent

4. Add a very small set of higher-level capabilities that align with the official roadmap:
   - contextual tagging suggestions
   - comment moderation recommendations
   - possibly other review-first editorial actions
5. Keep any new site actions opt-in, bounded, and review-first.

### Non-Goals

1. Broad autonomous site administration.
2. Default-on MCP exposure for mutating abilities.
3. Elementor-style "build any feature in code" workflows.

### File Targets

Existing files:

- `inc/Activity/Repository.php`
- `inc/Activity/Serializer.php`
- `inc/Activity/Permissions.php`
- `inc/Admin/ActivityPage.php`
- `inc/REST/Agent_Controller.php`
- `inc/Settings.php`
- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`
- `src/components/ActivitySessionBootstrap.js`
- `src/components/AIActivitySection.js`
- `src/store/index.js`
- `flavor-agent.php`
- `webpack.config.js`

Likely new files:

- `inc/Abilities/ContentAbilities.php`
- `inc/LLM/ContentPrompt.php`
- `src/post-editor/TaggingSuggestions.js`
- `src/comments/ModerationRecommendations.js`

### Implementation Slices

1. Expand the activity repository and serializers only as needed to support:
   - better filtering
   - request metadata
   - before/after diffs
   - backend/provider cost or timing diagnostics where available
2. Deepen the existing admin-visible activity page instead of relying on editor panels alone.
3. Keep ordered undo enforcement on the server as the source of truth.
4. Add opt-in settings for any higher-level AI admin actions.
5. Only after Stage A is stable, add a narrow content/admin ability set that mirrors the official roadmap rather than inventing a large custom agent surface.

### Acceptance Tests

PHP:

- `vendor/bin/phpunit --filter '(ActivityRepositoryTest|AgentControllerTest|SettingsTest)'`
- new PHPUnit coverage for `ContentAbilities` once Stage B starts

JS:

- `npm run test:unit -- --runInBand src/components/__tests__/AIActivitySection.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/store-actions.test.js`

Browser:

- new admin smoke for activity log visibility and filtering
- browser coverage for one narrow site-action recommendation flow once introduced

### Exit Criteria

1. Users can inspect durable AI activity outside the editor.
2. Ordered undo and audit behavior are trustworthy across refreshes and sessions.
3. Any new "site agent" behavior is narrow, opt-in, review-first, and visibly aligned with the official WordPress AI roadmap.

## Cross-Epic Acceptance Gates

Every epic should satisfy these repo-level gates before it is considered complete:

1. PHP:
   - `composer lint:php`
   - `vendor/bin/phpunit`
2. JS:
   - `source ~/.nvm/nvm.sh && nvm use >/dev/null && npm run lint:js`
   - `source ~/.nvm/nvm.sh && nvm use >/dev/null && npm run test:unit -- --runInBand`
3. Browser:
   - `source ~/.nvm/nvm.sh && nvm use >/dev/null && npm run test:e2e`
   - `source ~/.nvm/nvm.sh && nvm use >/dev/null && npm run test:e2e:wp70`
4. Docs:
   - update `STATUS.md`
   - update `docs/SOURCE_OF_TRUTH.md`
   - update this execution document if scope or sequencing materially changes

## Explicit Non-Goals

These should remain out of scope unless the product thesis changes:

1. A floating chat workspace or separate AI shell.
2. A general code-generation/sandbox product.
3. Provider setup that bypasses or competes with core Connectors for overlapping capabilities.
4. Free-form structural mutation without validation.
5. Default-on public mutation abilities for external agents.

## Suggested Tracking Breakdown

To keep implementation shippable, split each epic into:

1. PHP contract work
2. JS store and UI work
3. tests
4. docs

Do not mix more than one epic's contract expansion in a single PR unless the dependency is unavoidable.

## Primary Sources

The external alignment in this plan is based on these primary sources:

1. WordPress 7.0 release page and current milestone timeline
2. Extending the 7.0 cycle (2026-03-31)
3. Planning for WordPress 7.0
4. Gutenberg 22.9 release checklist plus the 22.8.0 and 22.8.1 release notes
5. AI team weekly summary, 2026-03-11
6. Official `AI` plugin page and roadmap
7. WordPress AI GitHub repository overview
8. Abilities API documentation

Keep this document focused on execution. If the external roadmap moves, update the alignment snapshot first, then revise epic order only if the change materially affects Flavor Agent's architecture.
