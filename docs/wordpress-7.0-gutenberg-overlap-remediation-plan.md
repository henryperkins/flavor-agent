# Flavor Agent Remediation Plan: WordPress 7.0 and Gutenberg Overlap

> Compiled: 2026-04-23
> Scope: active remediation plan for current repo findings where Flavor Agent duplicates Gutenberg or WordPress 7.0 functionality, or owns the right surface but in the wrong way
> Status: active plan, with workstreams 1 and 2 now implemented in the current repo state
> Supersedes: relevant forward-looking overlap items in `docs/wp7-migration-opportunities.md` where the two documents conflict

## Objective

Reduce plugin-owned duplication of Gutenberg and WordPress 7.0 features without deleting Flavor Agent's real value:

1. bounded recommendation flows
2. pattern indexing and retrieval
3. docs grounding
4. cross-surface activity and undo
5. WordPress-native admin tooling

The end state should feel more native, carry less compatibility debt, and move ownership to core where core now has a first-class system.

## Implementation Status

Completed on 2026-04-23:

- Workstream 1: pattern recommendations no longer patch Gutenberg's native registry; the inserter now uses a Flavor Agent-owned local shelf plus the existing badge
- Workstream 2: the main block panel is now the only executable block recommendation surface; delegated native Inspector sub-panels are passive mirrors only

Still pending:

- Workstream 3: Connectors and WordPress AI Client ownership cleanup
- Workstream 4: Guidelines bridge to core storage
- Workstream 5: `DataForm`-based settings screen rebuild

## Final Product Decisions

### 1. Pattern recommendations stop rewriting the native pattern registry

Flavor Agent will no longer patch pattern descriptions, keywords, or categories in the block inserter registry.

Target state:

- keep the inserter badge
- keep capability notices
- keep Qdrant retrieval, reranking, and visible-pattern scoping
- replace registry mutation with an inserter-local recommendation shelf rendered by Flavor Agent
- insert patterns through the native inserter action, not through registry rewriting

Result:

- no plugin ownership of Gutenberg pattern metadata
- no synthetic `Recommended` category slug to maintain
- less breakage risk as pattern APIs keep moving between Gutenberg plugin and core

### 2. The main block panel becomes the only executable block recommendation surface

`AI Recommendations` remains the single owner of:

- fetch
- stale refresh
- apply
- undo
- activity

Target state:

- remove the standalone `AI Settings` and `AI Style Suggestions` panels
- keep local context near native controls through passive inline hints only
- native subpanel chips become review shortcuts or read-only deltas, not second apply buttons
- style variations move into the main block surface if they stay executable

Result:

- one request lifecycle
- one apply path
- one undo path
- lower UI duplication inside Inspector tabs

### 3. Chat provider ownership moves to Connectors plus the WordPress AI Client

Flavor Agent should stop acting as a parallel chat-provider control plane.

Target state:

- chat recommendations use the WordPress AI Client through feature-specific REST routes
- Connectors own provider discovery and credential management for chat
- `Settings > Flavor Agent` no longer owns chat provider selection or direct chat credentials
- plugin settings stay focused on plugin-owned infrastructure:
  - embeddings backend for pattern search
  - Qdrant
  - docs grounding
  - ranking thresholds
  - migration and diagnostics

Result:

- less overlap with WordPress 7.0
- cleaner provider story for admins
- less fallback complexity in `Provider.php`

### 4. Guidelines become core-first through a bridge, not a parallel long-term store

Flavor Agent should stop treating its options-backed guideline store as the primary future architecture.

Target state:

- introduce a repository abstraction for guidelines reads and writes
- prefer the core Guidelines data model when the host exposes it
- keep the current options-backed format only as a compatibility source and migration bridge
- keep JSON import/export only as migration tooling, not as the primary editor

Result:

- no permanent parallel guidelines system
- safer transition across WordPress 7.0 core and Gutenberg plugin environments
- existing user data remains migratable

### 5. The settings screen stays custom in layout but moves onto WordPress admin primitives

The current settings page carries too much bespoke form state and UI orchestration.

Target state:

- rebuild the settings app around `DataForm`
- use free composition only for layout shells, status cards, and action panels
- keep `DataViews` for the activity page
- keep custom JS only where WordPress primitives do not yet cover the flow

Result:

- smaller custom controller surface
- better alignment with current admin direction
- easier long-term maintenance

## Workstreams

## Workstream A: Pattern Surface Reset

### Scope

- `src/patterns/PatternRecommender.js`
- `src/patterns/recommendation-utils.js`
- `src/patterns/pattern-settings.js`
- `src/patterns/InserterBadge.js`
- `docs/features/pattern-recommendations.md`
- related unit and Playwright coverage

### Changes

1. Remove runtime use of:
   - `patchPatternMetadata()`
   - `patchPatternCategoryRegistry()`
   - `setBlockPatterns()`
   - `setBlockPatternCategories()`
2. Add a Flavor Agent-owned inserter shelf mounted inside the inserter container.
3. Render:
   - title
   - recommendation count
   - ranked pattern cards
   - brief reason text
   - loading, empty, and error states
4. Insert through the native block inserter action for the selected pattern.
5. Keep visible-pattern scoping so the shelf never suggests patterns the current insertion point cannot use.
6. Retire docs that describe a patched `Recommended` category.

### Migration notes

- no stored user data migration needed
- keep the badge and availability messaging stable so the surface does not disappear during the change

### Exit criteria

- no native registry writes on the pattern surface
- no docs that claim Flavor Agent rewrites pattern metadata or categories
- pattern insert still works from the Flavor Agent shelf

## Workstream B: Block Inspector Ownership Reset

### Scope

- `src/inspector/InspectorInjector.js`
- `src/inspector/BlockRecommendationsPanel.js`
- `src/inspector/SettingsRecommendations.js`
- `src/inspector/StylesRecommendations.js`
- `src/inspector/SuggestionChips.js`
- `docs/features/block-recommendations.md`
- `docs/reference/recommendation-ui-consistency.md`

### Changes

1. Remove `SettingsRecommendations` and `StylesRecommendations` as standalone panels.
2. Keep `BlockRecommendationsPanel` as the only executable block recommendation surface.
3. Convert delegated subpanel chips into passive helpers:
   - suggested value preview
   - stale marker if needed
   - `Review in AI Recommendations` action
4. Move any executable style-variation actions into the main block panel.
5. Stop rebuilding request signatures and inputs in the settings/styles projection components.
6. Keep the current content-only restrictions and stale-state protections.

### Migration notes

- no data migration needed
- user-facing copy should explain that local hints mirror the main result and do not apply directly

### Exit criteria

- one block request lifecycle
- one apply path
- one undo path
- no duplicate executable panels in Inspector tabs

## Workstream C: Provider Ownership Migration

### Scope

- `inc/OpenAI/Provider.php`
- `inc/LLM/ChatClient.php`
- `inc/LLM/WordPressAIClient.php`
- `inc/Admin/Settings/Page.php`
- `inc/Admin/Settings/Registrar.php`
- `docs/features/settings-backends-and-sync.md`
- `docs/reference/provider-precedence.md`

### Changes

1. Split provider concerns into:
   - chat runtime: core AI Client plus Connectors
   - embedding runtime: plugin-owned Azure/OpenAI Native config for pattern search
2. Remove chat provider selection and direct chat credential fields from the long-term settings model.
3. Change the settings page chat section into a Connectors status and guidance section.
4. Simplify `ChatClient` so chat surfaces use the WordPress AI Client path only.
5. Keep plugin-owned embedding selection only where Connectors does not yet provide embeddings.
6. Replace provider fallback chains that mix plugin chat config and AI Client fallback with a Connectors-first runtime.

### Migration notes

Use a two-step migration:

1. Compatibility release
   - existing chat settings become deprecated
   - settings screen marks them legacy and points admins to Connectors
   - runtime still reads them only as a temporary fallback
2. Removal release
   - delete legacy chat fields
   - delete plugin chat fallback logic

### Exit criteria

- chat credentials are no longer primarily managed in Flavor Agent settings
- provider precedence docs are materially shorter and Connectors-first
- embeddings remain plugin-owned and explicit

## Workstream D: Guidelines Bridge and Migration

### Scope

- `inc/Guidelines.php`
- `inc/Admin/Settings/Page.php`
- `src/admin/settings-page-controller.js`
- prompt/context callers that read guidelines
- guideline-related docs

### Changes

1. Introduce a guidelines repository abstraction.
2. Add:
   - `CoreGuidelinesRepository` for hosts exposing the core Guidelines model
   - `LegacyGuidelinesRepository` for current plugin options
3. Resolve the active repository through feature detection.
4. Add a one-time migration path from legacy options into the core store when the core model is available.
5. Downgrade JSON import/export and block-guideline editing from primary UI to migration/admin tooling.
6. Update all prompt and context consumers to read from the repository abstraction instead of direct option helpers.

### Migration notes

- preserve current options until migration succeeds
- record migration status so repeated imports are avoided
- keep rollback possible while the core Guidelines surface remains partially deployed across environments

### Exit criteria

- guidelines reads are storage-agnostic
- the plugin no longer assumes its option store is the permanent system of record
- migration tooling exists for existing installs

## Workstream E: Settings Screen Modernization

### Scope

- `src/admin/settings-page.js`
- `src/admin/settings-page-controller.js`
- `inc/Admin/Settings/Page.php`
- `inc/Admin/Settings/Registrar.php`
- `src/admin/activity-log.js` only where shared helpers can be reused

### Changes

1. Replace bespoke field orchestration with a `DataForm`-based settings app.
2. Keep free composition for:
   - hero
   - status cards
   - migration notices
   - pattern sync actions
3. Move form state, validation messages, and dirty tracking into the DataForm-driven app shell.
4. Reduce PHP-rendered table markup to bootstrapping and capability checks.
5. Keep the activity page on `DataViews` and `DataForm`; it is already close to the right architecture.

### Migration notes

- do this after Workstreams C and D, otherwise the form schema will be rewritten twice

### Exit criteria

- settings-page controller code shrinks materially
- the main settings UI is config-driven instead of hand-wired DOM management
- plugin-specific actions are isolated from ordinary settings fields

## Recommended Execution Order

### Phase 1: Remove UI duplication without changing backend ownership

1. Workstream A
2. Workstream B

Why first:

- highest user-facing duplication
- lowest settings/data migration risk
- fastest reduction in Gutenberg-surface overlap

### Phase 2: Move chat ownership to core

1. Workstream C

Why second:

- this is the most important WordPress 7.0 alignment change
- it simplifies later settings work

### Phase 3: Migrate guidelines to a bridge model

1. Workstream D

Why third:

- it depends on a clear settings and ownership story
- it benefits from the Connectors-first cleanup already being done

### Phase 4: Rebuild the settings UI around the new ownership model

1. Workstream E

Why fourth:

- doing it earlier would just preserve the wrong information architecture

## Verification Plan

For each workstream, run the closest targeted suites plus the shared repo gates:

1. `npm run test:unit`
2. `composer run test:php`
3. `node scripts/verify.js --skip-e2e`
4. `npm run check:docs`

Additional targeted coverage:

- Pattern surface:
  - unit tests for inserter shelf state
  - Playwright checks for insert from the shelf
- Block surface:
  - unit tests for passive subpanel hints
  - block recommendation apply/undo smoke
- Provider migration:
  - PHPUnit around provider precedence and deprecated-setting migration
  - settings-page integration tests
- Guidelines bridge:
  - PHPUnit for repository selection and migration
  - admin UI tests for migration tool states
- Settings modernization:
  - JS unit tests for the new DataForm schema and action panels

When shared contracts change across multiple surfaces or subsystems, apply `docs/reference/cross-surface-validation-gates.md`.

## Docs That Must Change With This Plan

When implementation starts, update these docs together:

- `docs/features/pattern-recommendations.md`
- `docs/features/block-recommendations.md`
- `docs/features/settings-backends-and-sync.md`
- `docs/features/activity-and-audit.md` if settings/admin actions change
- `docs/reference/recommendation-ui-consistency.md`
- `docs/reference/provider-precedence.md`
- `docs/reference/shared-internals.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `STATUS.md`

## What Stays Plugin-Owned

This plan does not remove the plugin's real product value:

- Qdrant-backed pattern indexing and retrieval
- docs grounding through trusted WordPress sources
- bounded template/template-part/style recommendation contracts
- shared activity and undo for plugin-owned apply flows
- feature-specific REST and Abilities contracts

## Definition of Done

The overlap remediation is complete when all of the following are true:

1. Flavor Agent no longer rewrites Gutenberg pattern registry data.
2. Block recommendations have one executable surface, not several mirrored ones.
3. Chat provider setup is Connectors-first, with plugin chat config removed.
4. Guidelines read through a core-first bridge instead of a permanent plugin-only store.
5. The settings screen uses WordPress admin primitives for forms rather than a large bespoke controller.
6. Docs and tests describe the new ownership model consistently.
