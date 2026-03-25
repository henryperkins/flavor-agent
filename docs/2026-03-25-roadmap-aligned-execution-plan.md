# Flavor Agent Roadmap-Aligned Execution Plan

> Created: 2026-03-25
> Scope: repo-specific execution plan for the next 5 milestones
> External alignment snapshot verified: 2026-03-25
> Support target: WordPress 7.0 RC -> stable, PHP 8.0+, Node 20 / npm 10
> Baseline: current repo already ships block, pattern, template, template-part, navigation, and activity surfaces

## Goal

Evolve Flavor Agent so it feels like Gutenberg and wp-admin became smarter, not like a second product was bolted onto WordPress.

This plan intentionally aligns with:

1. WordPress 7.0 release work and APIs already on the roadmap.
2. Gutenberg editor directions already visible in the 7.0 cycle.
3. The official WordPress AI plugin and AI team "building blocks" direction.

It intentionally does **not** align with an Elementor-style AI code generator or sandbox-builder product.

## Alignment Snapshot

These are the external constraints this repo should treat as current on 2026-03-25:

1. WordPress 7.0 is still in release-candidate phase and is scheduled for general release on **April 9, 2026**. Build for the 7.0 API surface now, but keep experimental integrations adapter-backed until after the stable release.
2. The WordPress 7.0 planning post explicitly highlights:
   - Abilities and Workflows API
   - WP client AI API
   - DataViews and DataForms iterations
   - design-system token work
   - revisions with visual diffs and better undo/review affordances
   - server-side creation of blocks and patterns
   - navigation overlay work as a template-part-aware flow
3. Gutenberg 22.7 introduced or highlighted:
   - the Connectors screen and API
   - style variation transform previews
   - Content Guidelines experiment work
4. The official `AI` plugin now positions itself as the reference implementation for:
   - inline/editor-native AI features
   - Connectors-based provider setup
   - opt-in feature toggles
   - review-first UX
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

That means the next milestones should build forward from those foundations instead of re-planning them from scratch.

## Ordered Execution

1. Epic 1: Core AI Convergence and Capability Gating
2. Epic 2: Unified Inline Review Model
3. Epic 3: Style and Theme Intelligence
4. Epic 4: Structural Site-Building Intelligence
5. Epic 5: Durable Audit, Observability, and Narrow Site Agent

The order matters.

Epic 1 reduces architectural drift from WordPress core and the official AI plugin.
Epic 2 makes the existing surfaces feel like one product.
Epic 3 pushes deeper into native theme and style tooling.
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

### In Scope

1. Normalize capability gating and empty-state UX across all first-party surfaces.
2. Make it obvious which surfaces rely on:
   - core Connectors / AI Client
   - plugin-owned retrieval or ranking backends
3. Tighten `check-status` and per-surface availability reporting.
4. Reduce duplicate provider messaging in UI copy and settings.
5. Keep the current connector fallback behavior, but make the responsibility boundaries easier to reason about.

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

## Epic 2: Unified Inline Review Model

### Objective

Make Flavor Agent feel like one coherent Gutenberg-native review system instead of several adjacent AI surfaces with slightly different rules.

### Why This Comes Second

The repo already has the right pieces:

1. inline block apply
2. advisory navigation guidance
3. preview-confirm-apply template and template-part flows
4. activity and undo

What is missing is one clear interaction model that users can learn once and trust across surfaces.

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

## Epic 3: Style and Theme Intelligence

### Objective

Push Flavor Agent deeper into Gutenberg-native styling and theme tooling by expanding design-aware assistance around `theme.json`, presets, block supports, style variations, and Style Book / Global Styles-adjacent flows.

### Why This Comes Third

This is the clearest way to push beyond current WordPress.com-style integration while still respecting Gutenberg instead of bypassing it.

### In Scope

1. Improve current block style recommendations using richer token and support awareness.
2. Add style-variation and preset-transform suggestions where WordPress supports them.
3. Add a first-party Flavor Agent surface for site-level style intelligence that stays native to site-editor and style tooling.
4. Keep all output grounded in theme tokens, supported design tools, and existing WordPress style semantics.

### Non-Goals

1. Raw CSS generation as a default path.
2. A custom visual design tool outside the Site Editor.
3. A parallel design system.

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

Likely new files:

- `inc/Abilities/StyleAbilities.php`
- `inc/LLM/StylePrompt.php`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/style-book/__tests__/StyleBookRecommender.test.js`

### Implementation Slices

1. Harden `theme-settings.js` and `theme-tokens.js` so the plugin can consume stable sources whenever possible and isolate experimental fallbacks.
2. Extend the server collector with any style metadata needed for style-book or global-style recommendations.
3. Add a style-focused ability and prompt contract instead of overloading block/template prompts.
4. Implement a native site-editor surface for style intelligence:
   - previewable preset swaps
   - block style-variation suggestions
   - theme-token-safe transformations
5. Keep the first executable contract narrow:
   - preset substitutions
   - style variation selection
   - supported block style attributes only

### Acceptance Tests

PHP:

- new PHPUnit coverage for `StyleAbilities` and `StylePrompt`
- existing `InfraAbilitiesTest` and `ServerCollectorTest`

JS:

- `npm run test:unit -- --runInBand src/context/__tests__/theme-tokens.test.js src/inspector/suggestion-keys.test.js src/style-book/__tests__/StyleBookRecommender.test.js`

Browser:

- WP 7.0 harness test covering one style transformation preview and apply path

### Exit Criteria

1. Flavor Agent can recommend and apply theme-safe style changes without leaving native WordPress style systems.
2. No generated recommendation proposes a value outside available presets or unsupported design tools.
3. Style intelligence is visibly stronger without falling back to arbitrary CSS.

## Epic 4: Structural Site-Building Intelligence

### Objective

Expand deterministic site-building help for templates, template parts, navigation, and nearby site-editor structure without drifting into free-form tree generation.

### Why This Comes Fourth

The repo already has validated composition primitives. The next step is to increase their range while preserving structural safety.

### In Scope

1. Expand template and template-part executable operations in bounded ways.
2. Improve navigation guidance and, where safe, add validated navigation actions later.
3. Improve structural context in prompts and collectors.
4. Explore list-view or site-structure affordances only where they can stay native and low-risk.

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

Likely new files:

- `src/site-structure/ListViewRecommendations.js`
- `src/site-structure/__tests__/ListViewRecommendations.test.js`

### Implementation Slices

1. Extend template operations carefully beyond the current contract:
   - bounded placement targeting
   - safe replacement flows
   - limited move operations only when validation is deterministic
2. Expand template-part operations beyond start/end insertion only after snapshot-based undo coverage is ready.
3. Deepen navigation context:
   - overlay/template-part awareness
   - block focus cues
   - stronger bound page and structure reasoning
4. Add site-structure cues that integrate with existing editor affordances before inventing new surfaces.
5. Keep all new structural operations passing through `template-operation-sequence.js` and executor validation.

### Acceptance Tests

PHP:

- `vendor/bin/phpunit --filter '(Template|Navigation|AgentController|ServerCollector)'`

JS:

- `npm run test:unit -- --runInBand src/utils/__tests__/template-actions.test.js src/templates/__tests__/TemplateRecommender.test.js src/inspector/__tests__/NavigationRecommendations.test.js src/site-structure/__tests__/ListViewRecommendations.test.js`

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

### In Scope

Stage A: audit and observability

1. Promote the existing activity foundation into a durable admin-visible audit surface.
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
- `inc/REST/Agent_Controller.php`
- `inc/Settings.php`
- `src/components/ActivitySessionBootstrap.js`
- `src/components/AIActivitySection.js`
- `src/store/index.js`
- `flavor-agent.php`
- `webpack.config.js`

Likely new files:

- `inc/Admin/ActivityPage.php`
- `src/admin/activity-log.js`
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
2. Add an admin-visible activity page instead of relying on editor panels alone.
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
   - `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run lint:js`
   - `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand`
3. Browser:
   - `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e`
   - `source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70`
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

1. WordPress 7.0 release schedule (scheduled release: April 9, 2026)
2. Planning for WordPress 7.0
3. Gutenberg 22.7 release notes
4. AI team weekly summary, 2026-03-11
5. Official `AI` plugin page and roadmap
6. WordPress AI GitHub repository overview
7. Abilities API documentation

Keep this document focused on execution. If the external roadmap moves, update the alignment snapshot first, then revise epic order only if the change materially affects Flavor Agent's architecture.
