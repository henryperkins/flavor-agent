# Shared Internals

Cross-cutting store utilities, shared UI components, and context helpers used by multiple recommendation surfaces. Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view, `docs/reference/abilities-and-routes.md` for the exact contract, and `docs/reference/recommendation-ui-consistency.md` for the cross-surface UI comparison.

## Store Utilities

### `src/store/executable-surface-runtime.js`

Shared store-runtime helpers for the executable review/apply surfaces (template, template-part, Global Styles, and Style Book) plus the shared review-freshness path used by advisory navigation. This module keeps the fetch/review/apply lifecycle generic while leaving request shape, selector wiring, activity metadata, and the actual apply executors in `src/store/index.js`.

**Key exports:**

| Export                                           | Role                                                                                                                         |
| ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| `createExecutableSurfaceFetchConfig()`           | Normalizes per-surface fetch config into loading/error handlers plus route/request-token wiring                              |
| `buildExecutableSurfaceFetchThunk()`             | Binds the shared fetch runtime to store-owned transport dependencies such as abortable requests and request-meta decoration  |
| `createExecutableSurfaceReviewFreshnessConfig()` | Normalizes per-surface review-freshness config into route/request-token wiring and stale/fresh state transitions             |
| `buildExecutableSurfaceReviewFreshnessThunk()`   | Binds the shared review-freshness runtime to the store-owned signature extractors and request transport                      |
| `createExecutableSurfaceApplyConfig()`           | Normalizes per-surface apply config into shared apply-state transitions while leaving the real executor surface-owned        |
| `buildExecutableSurfaceApplyThunk()`             | Binds the shared apply runtime to stale guards, server freshness revalidation, activity recording, and activity-session sync |

**Consumers:** `src/store/index.js`

### `src/store/activity-history.js`

Session-scoped AI activity schema, scope resolution, `sessionStorage` cache/fallback layer, and undo-state resolution. This is the most heavily imported store module (10+ callers).

**Key exports:**

| Export                                                     | Role                                                                                                                                                |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `resolveActivityScope(postType, entityId)`                 | Builds a normalized scope object `{ key, hint, postType, entityId }` to partition activity logs per edited entity                                   |
| `resolveGlobalStylesScope(globalStylesId, opts)`           | Scope object keyed to a Global Styles entity                                                                                                        |
| `resolveStyleBookScope(globalStylesId, blockName, opts)`   | Scope object keyed to a specific block within the Style Book UI                                                                                     |
| `getCurrentActivityScope(registry)`                        | Inspects the data registry to determine the active editing scope (post, global styles, or Style Book block)                                         |
| `readPersistedActivityLog(scopeKey)`                       | Reads the `sessionStorage` cache for a given scope key; handles schema version migration and legacy detection                                       |
| `writePersistedActivityLog(scopeKey, entries)`             | Writes the trimmed activity log to `sessionStorage` as a versioned JSON blob                                                                        |
| `createActivityEntry({...})`                               | Factory for new activity entries with monotonic IDs, timestamps, initial undo state, and persistence tracking                                       |
| `getBlockActivityUndoState(entry, blockEditorSelect)`      | Resolves live undo state for a block-surface entry by checking whether the target block still exists and its attributes match the expected snapshot |
| `getResolvedActivityEntries(entries, runtimeUndoResolver)` | Batch-resolves undo states for all entries, applying ordered-undo rules across the full log                                                         |
| `getLatestUndoableActivity(entries, runtimeUndoResolver)`  | Returns the most recent tail entry that is both `canUndo: true` and `status: 'available'` after full resolution                                     |
| `ORDERED_UNDO_BLOCKED_ERROR`                               | Error message constant displayed when undo is blocked because newer AI actions exist                                                                |
| `sortActivityEntries(entries)`                             | Sorts activity entries by timestamp for chronological display                                                                                       |
| `limitActivityLog(entries)`                                | Trims the activity log to the maximum history size                                                                                                  |
| `isLocalActivityEntry(entry)`                              | Returns `true` when the entry has local-only persistence status (`local` or `create`), used to identify entries needing server sync                 |
| `getPendingActivitySyncType(entry)`                        | Returns the pending sync operation type (`undo` or `create`) for an unsynchronized entry                                                            |
| `getActivityEntityKey(entry)`                              | Generates a stable entity key from an activity entry for deduplication and lookup                                                                   |
| `getResolvedActivityUndoState(entry, entries, resolver)`   | Resolves undo state for a single entry considering ordered-undo rules across the full log                                                           |
| `getLatestAppliedActivity(entries)`                        | Returns the most recent entry that is still in applied (non-undone) state                                                                           |

**Session cache contract:** The server-backed activity repository is the source of truth. `sessionStorage` acts only as a cache/fallback so the current editor session can display activity entries before server hydration completes and handle transient offline periods. On entity change, `loadActivitySession()` merges server entries with pending local entries and writes the merged set back to `sessionStorage`. Entries that have been persisted to the server carry `persistence.status === 'server'`; local-only entries carry `'local'` or `'create'` status and are synced on the next opportunity.

**Consumers:** `src/store/index.js`, `src/inspector/BlockRecommendationsPanel.js`, `src/content/ContentRecommender.js`, `src/global-styles/GlobalStylesRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `src/templates/TemplateRecommender.js`, `src/style-book/StyleBookRecommender.js`, `src/components/ActivitySessionBootstrap.js`, `src/admin/activity-log-utils.js`

### `src/store/update-helpers.js`

Pure-function utility with zero internal dependencies. Contains the core of the block apply/undo path: safe attribute merging, undo patch construction, snapshot comparison, content-only filtering, execution-contract allowlisting, and full suggestion sanitization.

**Key exports:**

| Export                                                             | Role                                                                                                                                                                       |
| ------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `buildSafeAttributeUpdates(currentAttributes, suggestedUpdates)`   | Produces a safe attribute patch by filtering out raw CSS injection, stripping unsafe theme payloads, and deep-merging nested keys so existing sub-properties are preserved |
| `buildUndoAttributeUpdates(previousAttributes, nextAttributes)`    | Builds a restoration patch that reverts an applied attribute change; keys present only in `next` are set to `undefined`                                                    |
| `attributeSnapshotsMatch(previousSnapshot, currentSnapshot)`       | Deep structural equality check between two attribute snapshots, used by undo logic to determine whether a block's attributes still match the expected state                |
| `sanitizeRecommendationsForContext(recommendations, blockContext)` | Main sanitization pipeline: normalizes suggestion groups, strips theme-unsafe CSS, restricts executable updates to declared/supported attributes, filters binding-unsafe metadata, and enforces editing-mode restrictions |
| `getBlockSuggestionExecutionInfo(suggestion, blockContext)`        | Returns `{ allowedUpdates, isAdvisory, isAdvisoryOnly, isExecutable }` describing whether a suggestion is purely informational or carries executable attribute changes     |
| `filterAttributeUpdatesForContentOnly(updates, blockContext)`      | Filters attribute updates to only content attributes when the block is in content-only editing mode                                                                        |
| `buildBlockRecommendationDiagnostics(suggestion, context)`         | Builds diagnostic metadata for a block recommendation (advisory status, allowed updates, execution info)                                                                   |
| `getSuggestionAttributeUpdates(suggestion)`                        | Extracts the raw attribute update object from a suggestion payload                                                                                                         |

**Consumers:** `src/store/index.js` (uses all apply/undo helpers), `src/store/activity-history.js` (uses `attributeSnapshotsMatch`), `src/inspector/BlockRecommendationsPanel.js` (uses `getBlockSuggestionExecutionInfo`)

### `src/store/block-targeting.js`

Minimal module that resolves activity entry targets to live editor blocks and compares recorded paths with the current block tree. This is the bridge between persisted activity entries and the live block editor state.

**Key exports:**

| Export                                                   | Role                                                                                                                 |
| -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `getBlockByPath(blocks, path)`                           | Traverses a block tree using an array of numeric indices and returns the block at that location                      |
| `getBlockPathByClientId(blocks, clientId)`               | Walks the current block tree and returns the live path for a block client ID                                        |
| `resolveActivityBlockTarget(blockEditorSelect, target)`  | Resolves an activity target plus its current path: tries `target.clientId` first, then falls back to `target.blockPath` via tree traversal |
| `hasResolvedActivityBlockMoved(resolvedTarget, target)`  | Returns true when a clientId-resolved block no longer matches the recorded `target.blockPath`; path drift is diagnostic only because undo safety is enforced by block name and attribute snapshot checks |
| `resolveActivityBlock(blockEditorSelect, target)`        | Backward-compatible block-only resolver used by older callers                                                       |

**Consumers:** `src/store/activity-history.js`, `src/store/index.js`

### `src/utils/recommendation-request-signature.js`

Local request-signature builder shared by the executable recommendation surfaces plus advisory navigation review freshness. It normalizes the surface id, scoped entity ref, composer prompt, and surface-owned context signature into a comparable string that the UI uses for immediate stale-state rendering and the first apply guard. These signatures intentionally stay client-local; PHP still receives the underlying context payload plus the server-facing context fields.

**Key exports:**

| Export                                              | Role                                                                               |
| --------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `buildRecommendationRequestSignature()`             | Shared normalizer for `{ surface, prompt, contextSignature, scopeKey, entityRef }` |
| `buildBlockRecommendationRequestSignature()`        | Freshness/apply guard for direct block apply results                               |
| `buildTemplateRecommendationRequestSignature()`     | Freshness/apply guard for template review/apply results                            |
| `buildNavigationRecommendationRequestSignature()`   | Freshness guard for advisory navigation review results                             |
| `buildTemplatePartRecommendationRequestSignature()` | Freshness/apply guard for template-part review/apply results                       |
| `buildGlobalStylesRecommendationRequestSignature()` | Freshness/apply guard for Global Styles review/apply results                       |
| `buildStyleBookRecommendationRequestSignature()`    | Freshness/apply guard for Style Book review/apply results                          |

**Consumers:** `src/store/index.js`, `src/inspector/BlockRecommendationsPanel.js`, `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `src/global-styles/GlobalStylesRecommender.js`, `src/style-book/StyleBookRecommender.js`, `src/inspector/SuggestionChips.js`

`src/store/index.js` now pairs these local request signatures with server freshness signatures. Block stores `resolvedContextSignature` for apply safety and background server freshness demotion, template/template-part/Global Styles/Style Book also store a docs-free `reviewContextSignature` for background review revalidation, and navigation stores a docs-free `reviewContextSignature` without an apply hash. Apply actions keep the existing local stale check first, then re-post the same request with `resolveSignatureOnly: true` and compare the returned server apply-context signature before any deterministic mutation runs.

### `inc/Support/RecommendationResolvedSignature.php`

Server-side apply-freshness helper shared by the executable recommendation abilities. `RecommendationResolvedSignature::from_payload()` hashes a stable normalized payload containing `{ surface, payload }`, where `payload` is the server-normalized apply context plus the sanitized prompt. Associative keys are sorted deterministically, list order is preserved, and docs guidance text is intentionally excluded so cache churn does not invalidate otherwise unchanged results.

**Consumers:** `inc/Abilities/BlockAbilities.php`, `inc/Abilities/TemplateAbilities.php`, `inc/Abilities/StyleAbilities.php`

### `inc/Support/RecommendationReviewSignature.php`

Server-side review-freshness helper shared by template, template-part, style, and navigation recommendation abilities. `RecommendationReviewSignature::from_payload()` hashes a stable normalized `{ surface, payload }` structure with deterministic key ordering for associative arrays and preserved order for lists.

Review payloads are intentionally limited to docs-free server review context so freshness tracks real server-owned drift instead of docs guidance or grounded prompt text churn, while apply-time mutation safety continues to rely on `resolvedContextSignature`.

**Consumers:** `inc/Abilities/TemplateAbilities.php`, `inc/Abilities/StyleAbilities.php`, `inc/Abilities/NavigationAbilities.php`

## Shared UI Components

Several shared components form the current recommendation-surface shell. `CapabilityNotice`, `AIStatusNotice`, `AIAdvisorySection`, and `AIReviewSection` carry the core capability/status/review contract; `SurfacePanelIntro`, `SurfaceScopeBar`, `SurfaceComposer`, `RecommendationHero`, `RecommendationLane`, and `AIActivitySection` provide the rest of the reusable full-panel framing. Use `docs/reference/recommendation-ui-consistency.md` when you need the exact per-surface composition rules and intentional exceptions.

### `src/components/CapabilityNotice.js`

Renders a non-dismissible `<Notice>` with optional action links when the backend capability for a given surface is unavailable. Delegates content to `getCapabilityNotice(surface, data)` from `src/utils/capability-flags.js`. Returns `null` when the capability is satisfied.

**Props:** `surface` (string key: `'block'`, `'pattern'`, `'content'`, `'template'`, `'templatePart'`, `'navigation'`, `'globalStyles'`, `'styleBook'`), `data` (optional override for `flavorAgentData`)

**Consumers:** Block Inspector, Pattern Inserter, Content, Template, Template-Part, Navigation, Global Styles, Style Book (8 surfaces)

### `src/components/AIStatusNotice.js`

Renders a contextual `<Notice>` with a configurable tone (`info`, `warning`, `error`, `success`), message, optional action button, and optional dismiss handler. Returns `null` when `notice?.message` is falsy. This is the unified feedback bar used by every recommendation surface for transient status (loading, errors, rate limits, post-apply/post-undo feedback).

**Props:** `notice` (object: `{ message, tone?, actionLabel?, actionDisabled?, isDismissible? }`), `onAction`, `onDismiss`, `className`

**Consumers:** Block Inspector, Content, Navigation, Template, Template-Part, Global Styles, Style Book (7 surfaces)

### `src/components/AIAdvisorySection.js`

Renders a styled section container for non-executable, advisory-only AI suggestions (e.g. structural recommendations, pattern replacement ideas). Displays a title, an optional advisory-status pill, a formatted count pill, optional description, optional meta slot, and a body slot for child content. This is now the standard advisory shell for the main block, template, template-part, Style Book, and Global Styles surfaces.

**Props:** `title`, `advisoryLabel`, `count`, `countLabel`, `countNoun`, `description`, `meta`, `children`, `className`

**Consumers:** Block Inspector, Content, Template, Template-Part, Global Styles, Style Book (6 surfaces)

### `src/components/AIReviewSection.js`

Renders a review-before-apply confirmation panel for executable AI operations. Displays a title, configurable status pill, operation count pill, optional summary, a child content slot (typically an operation list), an optional hint, and Confirm/Cancel action buttons.

**Props:** `title`, `statusLabel`, `count`, `countLabel`, `countNoun`, `summary`, `children`, `hint`, `confirmLabel`, `cancelLabel`, `onConfirm`, `onCancel`, `confirmDisabled`, `className`

**Consumers:** Template, Template-Part, Global Styles, Style Book (4 surfaces)

### `src/components/SurfacePanelIntro.js`, `SurfaceScopeBar.js`, and `SurfaceComposer.js`

These components provide the reusable top-of-panel shell for the full recommendation surfaces.

- `SurfacePanelIntro.js` renders the short surface-specific intro copy block.
- `SurfaceScopeBar.js` renders current/stale scope state plus the refresh affordance when a result exists. On executable surfaces, freshness still starts with local request-signature comparison, then hybrid review surfaces (template/template-part/Global Styles/Style Book) can layer server review staleness from `reviewContextSignature` revalidation while block layers background server staleness from the wrapped REST `payload.resolvedContextSignature`. Store apply actions continue using server `resolvedContextSignature` revalidation before mutation. Stale-state messaging is intentionally surface-owned; the shared status notice does not render stale notices.
- `SurfaceComposer.js` wraps the prompt field, starter prompts, submit action, helper text, and keyboard submission handling. Executable surfaces hydrate the composer prompt from the stored ready-result prompt once per result token so preloaded results start in a fresh state and only become stale after the user edits the prompt or the live context signature changes.
- Keyboard-only verification notes for `SurfaceComposer`: (1) tab order remains prompt textarea -> starter prompts (if present) -> submit action, (2) prompt focus ring stays clearly visible in default and high-contrast admin themes, and (3) visible labels/helper copy continue to communicate purpose even when placeholder text is absent.

**Consumers:** Block Inspector, Content, Template, Template-Part, Global Styles, Style Book (6 surfaces). Delegated block Inspector subpanels render passive `SuggestionChips` only; they do not reuse the full panel shell.

### `src/components/RecommendationHero.js` and `RecommendationLane.js`

These components provide the shared suggestion presentation layers used before review/apply or advisory follow-through:

- `RecommendationHero.js` renders the featured next recommendation at the top of a fresh result set.
- `RecommendationLane.js` renders grouped executable or lightweight embedded suggestions below the hero.

**Consumers:** Block Inspector, Content, Navigation, Template, Template-Part, Global Styles, Style Book (7 surfaces)

### `src/components/AIActivitySection.js`

Renders the shared recent-actions list for executable surfaces, including ordered undo state, provider/runtime metadata, and inline undo availability. Content uses the same component for read-only request diagnostics.

**Consumers:** Block Inspector, Content, Template, Template-Part, Global Styles, Style Book, admin-adjacent activity helpers (6 editor surfaces plus shared activity utilities)

## Context Helpers

### `src/utils/live-structure-snapshots.js`

Small shared helpers for live template and template-part snapshot assembly. These helpers intentionally stop at low-level normalization: they dedupe visible pattern names, summarize allowed scalar block attributes, and collect nested block counts/depth without flattening template slot semantics or template-part path-target semantics into one generic contract.

**Key exports:**

| Export                                               | Role                                                                                                    |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `getInnerBlocks(block)`                              | Safe child-block accessor for nested editor snapshots                                                   |
| `summarizeBlockAttributes(attributes, fields)`       | Returns only scalar values from an allowed field list for prompt-safe structural snapshots              |
| `collectNestedBlockStats(blocks, getBlockChildren?)` | Shared recursive block-count and depth collector used by template and template-part structure snapshots |
| `normalizeVisiblePatternNames(visiblePatternNames)`  | Deduplicates and filters inserter-visible pattern names before request and signature assembly           |

**Consumers:** `src/templates/template-recommender-helpers.js`, `src/template-parts/template-part-recommender-helpers.js`

### `src/context/collector.js`

Core composition point for block recommendation context. Combines block introspection, theme token summaries, sibling context, and structural context into a single payload for the `/flavor-agent/v1/recommend-block` route. Maintains a memoized annotated tree cache to avoid redundant structural analysis.

**Key exports:**

| Export                                   | Role                                                               |
| ---------------------------------------- | ------------------------------------------------------------------ |
| `collectBlockContext(clientId, options)` | Assembles the full block recommendation context for a single block |
| `getAnnotatedBlockTree(rootClientId)`    | Returns the cached (or freshly computed) annotated structural tree |
| `invalidateAnnotatedTreeCache()`         | Clears the memoized tree so the next call recomputes               |
| `getLiveBlockContextSignature(clientId)` | Returns a comparable signature for the current block context state |

**Consumers:** `src/store/index.js`

### `src/context/block-inspector.js`

Client-side block introspection. Queries the `@wordpress/blocks` and `@wordpress/block-editor` stores to build comprehensive block manifests including supports, inspector panels, bindable/content/config attribute splits, styles, variations, editing mode, and content-only status.

**Key exports:**

| Export                                        | Role                                                                                                                                               |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `introspectBlockInstance(clientId)`           | Full manifest for a live block: type metadata + current attributes, editing mode, parent chain, child count, content-only status, block visibility |
| `introspectBlockTree(rootClientId, maxDepth)` | Recursively introspects child blocks from a root, returning a nested tree of instance manifests                                                    |
| `summarizeTree(tree, options, depth)`         | Compresses an introspected tree into a token-budget-friendly summary for LLM prompts                                                               |
| `buildCapabilityIndex(tree)`                  | Deduplicated map of unique block types to their capability summaries                                                                               |
| `resolveInspectorPanels(supports)`            | Maps block `supports` declarations to Inspector panel names (must stay synchronized with `ServerCollector::SUPPORT_TO_PANEL`)                      |
| `introspectBlockType(blockName)`              | Returns type-level metadata for a block name (supports, attributes, styles, variations) without requiring a live instance                          |

**Consumers:** `src/context/collector.js`

### `src/context/theme-tokens.js`

Design token extraction from `theme.json` and global styles. Produces a full token manifest (color palette, gradients, duotone, typography, spacing, layout, shadow, border, background, element styles, block pseudo-styles, diagnostics) and compresses it into LLM-prompt-friendly summaries.

**Key exports:**

| Export                                                         | Role                                                                                                    |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `collectThemeTokens()`                                         | Entry point: reads current editor settings and returns the full design-token manifest                   |
| `summarizeTokens(tokens)`                                      | Compresses the manifest into a compact, prompt-friendly summary                                         |
| `buildGlobalStylesExecutionContract(tokens)`                   | Combines supported style paths with sorted preset slug maps for the LLM to validate style writes        |
| `buildBlockStyleExecutionContract(tokens, blockType)`          | Same as above but scoped to a specific block type's supports                                            |
| `collectThemeTokenDiagnosticsFromSettings(settings)`           | Collects diagnostic data (coverage gaps, missing presets) from provided editor settings                 |
| `collectThemeTokensFromSettings(settings)`                     | Token extraction from an explicit settings object (used when editor store is not available)             |
| `getGlobalStylesSupportedStylePathsFromTokens(tokens)`         | Returns the set of style paths (e.g. `color.text`, `typography.fontSize`) supported at the global scope |
| `getBlockStyleSupportedStylePathsFromTokens(tokens, type)`     | Same as above but scoped to a specific block type's style supports                                      |
| `buildGlobalStylesExecutionContractFromSettings(settings)`     | Builds the execution contract directly from settings without calling `collectThemeTokens()`             |
| `buildBlockStyleExecutionContractFromSettings(settings, type)` | Same as above but scoped to a specific block type                                                       |

**Consumers:** `src/context/collector.js`, `src/utils/style-operations.js`, `src/global-styles/GlobalStylesRecommender.js`, `src/style-book/StyleBookRecommender.js`

### `src/context/theme-settings.js`

Theme editor settings adapter. Resolves the active theme features key (stable `features` vs. experimental `__experimentalFeatures`) and provides access to the raw theme token source from the `core/block-editor` store.

**Key exports:**

| Export                            | Role                                                                           |
| --------------------------------- | ------------------------------------------------------------------------------ |
| `STABLE_THEME_FEATURES_KEY`       | Constant: `'features'`                                                         |
| `EXPERIMENTAL_THEME_FEATURES_KEY` | Constant: `'__experimentalFeatures'`                                           |
| `getThemeEditorSettings()`        | Reads the full editor settings object from `core/block-editor`                 |
| `getThemeTokenSource()`           | Returns which features key is active (`'stable'`, `'experimental'`, or `null`) |
| `getThemeTokenSourceDetails()`    | Returns detailed parity information between stable and experimental features   |
| `getThemeTokenFeatures()`         | Returns the resolved theme features object                                     |

**Consumers:** `src/context/theme-tokens.js`

### `src/utils/editor-context-metadata.js`

Walks block trees to collect pattern override summaries and viewport visibility summaries for LLM context enrichment.

**Key exports:**

| Export                                                        | Role                                                                         |
| ------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `describeEditorBlockLabel(blockName, attributes, areaLookup)` | Human-readable label for a block (includes slug and area for template parts) |
| `collectPatternOverrideSummary(blocks, areaLookup)`           | Summary of all blocks with `core/pattern-overrides` bindings                 |
| `collectViewportVisibilitySummary(blocks, areaLookup)`        | Summary of all blocks with viewport-based block visibility rules             |

**Consumers:** `src/global-styles/GlobalStylesRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `src/templates/template-recommender-helpers.js`

### `src/utils/structural-identity.js`

Block structural role inference. Recursively annotates a block tree with semantic roles (e.g. `primary-navigation`, `header-slot`, `main-content`), human-readable labels, job descriptions, location (header/footer/sidebar/content), template-part areas, position metrics, and evidence trails.

**Key exports:**

| Export                                                    | Role                                                                                                 |
| --------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `annotateStructuralIdentity(tree, options)`               | Recursive tree walker that attaches `structuralIdentity` objects to every node                       |
| `buildStructuralContext(tree, selectedClientId, options)` | Orchestrates annotation + path finding to produce a complete structural context for a selected block |
| `toStructuralSummary(node)`                               | Extracts a compact summary for LLM prompt use                                                        |
| `findNodePath(tree, predicate)`                           | Finds the path (array of tree indices) to the first node matching a predicate                        |
| `findBranchRoot(tree, path)`                              | Finds the nearest template-part root ancestor in a node path                                         |

**Consumers:** `src/context/collector.js`

## Entity And Capability Utilities

### `src/utils/editor-entity-contracts.js`

Canonical abstraction layer between WordPress's dual editor stores (`core/editor` for post editing, `core/edit-site` for site editing) and the plugin's feature surfaces. Provides entity resolution, post-type field definitions, view/layout normalization, and a memoized React hook that composes everything into a single contract object.

**Key exports:**

| Export                                                   | Role                                                                                                                    |
| -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `getEditedPostTypeEntity(select, expectedPostType?)`     | Resolves the currently edited entity from `core/editor` or `core/edit-site`                                             |
| `getPostTypeFieldDefinitions(postType)`                  | Returns frozen `{ id, label }` field descriptors appropriate for each post type                                         |
| `usePostTypeEntityContract(postType)`                    | React hook: composes entity fields, view/layout defaults, area options, and recommended category into a single contract |
| `normalizeEditedEntityRef(ref)`                          | Normalizes an entity reference to a consistent string or integer form                                                   |
| `getPostTypeFieldMap(postType)`                          | Returns a frozen map of field descriptors keyed by field ID                                                             |
| `normalizeViewConfigContract(view, fields)`              | Normalizes a DataViews view configuration to a canonical form                                                           |
| `getLockedViewFilterValue(view, fieldId)`                | Extracts a locked filter value from a view configuration                                                                |
| `getLockedViewOptions(views, fieldId)`                   | Returns the set of locked filter options across multiple views                                                          |
| `buildOptionLabelMap(options)`                           | Builds a value-to-label lookup from an options array                                                                    |
| `getRecommendedPatternCategorySlug(postType)`            | Returns the recommended pattern category slug for a given post type                                                     |
| `buildPostTypeEntityContract(postType, entity, ...args)` | Non-hook equivalent of `usePostTypeEntityContract` for imperative contexts                                              |

**Consumers:** `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js`

### `src/utils/capability-flags.js`

Derives surface capability flags from the server-localized `flavorAgentData` bootstrap object. Produces the notice content and action links for `CapabilityNotice`.

**Consumers:** `src/components/CapabilityNotice.js`

### `src/utils/format-count.js`

Micro-utility providing `formatCount()` (count formatting with noun pluralization), `humanizeString()` (string humanization), and `joinClassNames()` (CSS class-name concatenation).

**Consumers:** `src/components/AIAdvisorySection.js`, `src/components/AIReviewSection.js`, `src/components/AIStatusNotice.js`

## Style Utilities

### `src/utils/style-operations.js`

Shared helpers for Global Styles and Style Book apply/undo execution. Provides configuration normalization, operation application, and undo-state resolution for style recommendation results.

**Key exports:**

| Export                                                         | Role                                                                       |
| -------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `getComparableGlobalStylesConfig(config)`                      | Normalizes a global styles config object for stable comparison             |
| `buildGlobalStylesRecommendationContextSignature(config, ...)` | Builds a context signature for global styles recommendation freshness      |
| `getGlobalStylesUserConfig()`                                  | Reads the user's current global styles configuration from the editor store |
| `applyGlobalStyleSuggestionOperations(operations, contract)`   | Applies style operations to the global styles entity                       |
| `undoGlobalStyleSuggestionOperations(snapshot)`                | Reverts style operations using a stored pre-apply snapshot                 |
| `getGlobalStylesActivityUndoState(entry, select)`              | Resolves live undo state for a style-surface activity entry                |

**Consumers:** `src/store/index.js`, `src/global-styles/GlobalStylesRecommender.js`, `src/style-book/StyleBookRecommender.js`

### `src/utils/style-design-semantics.js`

Builds semantic design metadata (intent labels, hierarchy annotations, accessibility notes) for style recommendation prompts.

**Key exports:**

| Export                                                     | Role                                                           |
| ---------------------------------------------------------- | -------------------------------------------------------------- |
| `buildGlobalStyleDesignSemantics(tokens, config)`          | Builds semantic metadata for global styles recommendations     |
| `buildStyleBookDesignSemantics(tokens, config, blockName)` | Builds semantic metadata scoped to a specific Style Book block |

**Consumers:** `src/global-styles/GlobalStylesRecommender.js`, `src/style-book/StyleBookRecommender.js`

### `src/utils/style-validation.js`

Style value validation and normalization helpers used by the style operation pipeline.

**Key exports:**

| Export                                          | Role                                                                    |
| ----------------------------------------------- | ----------------------------------------------------------------------- |
| `sanitizeStyleKey(key)`                         | Sanitizes a style key to lowercase alphanumeric-dash-underscore         |
| `normalizePresetType(type)`                     | Normalizes a preset type string by removing dashes                      |
| `displayPresetType(type)`                       | Returns a display-friendly name for a preset type                       |
| `validateFreeformStyleValueByKind(value, kind)` | Validates a freeform style value against its kind (color, length, etc.) |
| `FREEFORM_STYLE_VALIDATORS`                     | Constant: map of validator types for freeform style values              |
| `CSS_LENGTH_UNITS`                              | Constant: array of valid CSS length unit strings                        |

**Consumers:** `src/utils/style-operations.js`, `inc/LLM/StylePrompt.php` (contract parity)

### `src/utils/context-signature.js`

Fundamental utility that produces stable, comparable signature strings from arbitrary context data objects. Used by all surfaces that need freshness detection.

**Key exports:**

| Export                        | Role                                                               |
| ----------------------------- | ------------------------------------------------------------------ |
| `buildContextSignature(data)` | Normalizes and hashes context data into a stable comparable string |

**Consumers:** `src/utils/block-recommendation-context.js`, `src/utils/style-operations.js`, `src/utils/recommendation-request-signature.js`

### `src/utils/structural-equality.js`

Deep and shallow structural equality helpers used for comparing block attributes, style configs, and context objects without reference identity.

**Key exports:**

| Export                            | Role                                                                                  |
| --------------------------------- | ------------------------------------------------------------------------------------- |
| `normalizeComparableValue(value)` | Recursively normalizes a value (strips `undefined`, sorts keys) for stable comparison |
| `stableSerialize(value)`          | Serializes a value to JSON with deterministic key ordering                            |
| `shallowStructuralEqual(a, b)`    | Shallow equality check for objects and arrays                                         |
| `deepStructuralEqual(a, b)`       | Deep recursive equality check for objects and arrays                                  |

**Consumers:** `src/store/update-helpers.js`, `src/utils/style-operations.js`, `src/utils/context-signature.js`

### `src/utils/block-operation-catalog.js`

Versioned validator for future block structural operations. It currently defines the v1 selected-block pattern operation vocabulary (`insert_pattern` and `replace_block_with_pattern`), normalizes allowed pattern context, and rejects proposed structural operations unless the localized `flavorAgentData.enableBlockStructuralActions` rollout flag is explicitly enabled.

**Consumers:** Block recommendation actionability tests today; future block review/apply state will use this before exposing structural operations.

## Pattern Internals

### `src/patterns/compat.js`

Re-export facade that centralizes `pattern-settings.js` and `inserter-dom.js` into a single public API surface. Feature surfaces that only need pattern reads/writes or inserter DOM access import through this barrel.

### `src/patterns/pattern-settings.js`

Three-tier pattern API adapter: resolves stable settings keys first (`blockPatterns`), then `__experimentalAdditionalBlockPatterns`, then `__experimentalBlockPatterns`. Provides `getBlockPatterns()`, `setBlockPatterns()`, `getAllowedPatterns()`, and runtime diagnostics.

### `src/patterns/inserter-dom.js`

CSS selector constants and DOM finders for the inserter container, search input, and toggle button. Used by `InserterBadge` and `PatternRecommender` to mount into the native inserter UI.

### `src/patterns/inserter-badge-state.js`

Pure state-machine function `getInserterBadgeState()` that maps pattern recommendation status (`loading`, `error`, `ready`) into a badge view-model with `status`, `count`, `content`, `tooltip`, `ariaLabel`, and `className`. `InserterBadge` passes only renderable recommendations for the current allowed-pattern scope, so the ready count and tooltip do not use a raw store-level badge cache.

**Consumers:** `src/patterns/InserterBadge.js`

### `src/utils/visible-patterns.js`

Returns the set of editor-visible pattern names for the current inserter context. Used by pattern recommendations to scope similarity search to patterns the user can actually see.

**Consumers:** `src/patterns/PatternRecommender.js`, `src/store/index.js`

### `src/utils/pattern-names.js`

Single export `extractPatternNames()` that extracts distinct pattern names from a pattern collection. Used by pattern recommendation context assembly.

**Consumers:** `src/patterns/PatternRecommender.js`

### `src/patterns/recommendation-utils.js`

Small utility module for renderable pattern recommendation matching and badge reason extraction. `buildRecommendedPatterns()` intersects recommendations with the current inserter allowed-pattern list, and `getPatternBadgeReason()` returns the reason from the highest-confidence renderable recommendation for badge tooltip use.

**Consumers:** `src/patterns/InserterBadge.js`, `src/patterns/PatternRecommender.js`

## Template And Template-Part Utilities

### `src/utils/template-part-areas.js`

Template-part area resolution from attributes, slug, or the server-localized area registry (`window.flavorAgentData.templatePartAreas`). Uses a four-tier resolution: explicit `area` attribute, slug lookup, well-known slug names (`header`/`footer`/`sidebar`), and HTML tag name fallback.

**Key exports:**

| Export                                             | Role                                                              |
| -------------------------------------------------- | ----------------------------------------------------------------- |
| `getTemplatePartAreaLookup()`                      | Returns the canonical slug-to-area mapping from `flavorAgentData` |
| `inferTemplatePartArea(attributes, areaLookup)`    | Convenience: returns the resolved area string                     |
| `matchesTemplatePartArea(block, area, areaLookup)` | Predicate: does the block's inferred area match the given area?   |

**Consumers:** `src/utils/structural-identity.js`, `src/utils/editor-context-metadata.js`, `src/utils/template-actions.js`, `src/templates/template-recommender-helpers.js`, `src/template-parts/TemplatePartRecommender.js`

### `src/utils/template-operation-sequence.js`

Validates and normalizes LLM-proposed template and template-part operation sequences. Ensures operations use known types, valid placements, and well-formed parameters before the deterministic executor runs.

**Key exports:**

| Export                                              | Role                                                   |
| --------------------------------------------------- | ------------------------------------------------------ |
| `validateTemplateOperationSequence(operations)`     | Validates and normalizes a template operation sequence |
| `validateTemplatePartOperationSequence(operations)` | Same for template-part operations                      |
| `TEMPLATE_OPERATION_ASSIGN`                         | Constant: `'assign_template_part'`                     |
| `TEMPLATE_OPERATION_INSERT_PATTERN`                 | Constant: `'insert_pattern'`                           |
| `TEMPLATE_OPERATION_REMOVE_BLOCK`                   | Constant: `'remove_block'`                             |
| `TEMPLATE_OPERATION_REPLACE`                        | Constant: `'replace_template_part'`                    |
| `TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN`     | Constant: `'replace_block_with_pattern'`               |

**Consumers:** `src/store/index.js`, `src/templates/template-recommender-helpers.js`, `src/template-parts/template-part-recommender-helpers.js`

### `src/utils/template-types.js`

Template slug normalization. Maps template slugs (which may include `theme//` prefixes or compound suffixes like `single-post`) to canonical template types from the `KNOWN_TEMPLATE_TYPES` set (14 types matching the pattern `templateTypes` vocabulary).

**Consumers:** `src/patterns/PatternRecommender.js`, `src/templates/TemplateRecommender.js`, `src/global-styles/GlobalStylesRecommender.js`, `src/style-book/StyleBookRecommender.js`

## Dependency Graph

```text
template-part-areas.js  (leaf -- reads flavorAgentData)
        |
        +-- structural-identity.js  (annotates block trees)
        |           |
        |           +-- collector.js
        |
        +-- editor-context-metadata.js  (override + visibility summaries)
        |           |
        |           +-- template-recommender-helpers.js
        |           +-- TemplatePartRecommender.js
        |           +-- GlobalStylesRecommender.js
        |
        +-- template-actions.js

theme-tokens.js  (full design token manifest)
        |
        +-- collector.js
        +-- style-operations.js
        +-- GlobalStylesRecommender.js / StyleBookRecommender.js

block-inspector.js  (block introspection + tree summary)
        |
        +-- collector.js

pattern-settings.js + inserter-dom.js  (pattern + inserter adapters)
        |
        +-- compat.js  (re-export facade)
        +-- PatternRecommender.js (direct)
        +-- InserterBadge.js (direct)
        +-- template-actions.js (direct)
        +-- visible-patterns.js (direct)

block-targeting.js -> activity-history.js -> store/index.js
update-helpers.js -> store/index.js + activity-history.js

context-signature.js  (leaf -- pure hashing)
        |
        +-- block-recommendation-context.js
        +-- style-operations.js
        +-- recommendation-request-signature.js

style-validation.js  (leaf -- pure validation)
        |
        +-- style-operations.js

structural-equality.js  (leaf -- pure comparison)
        |
        +-- update-helpers.js
        +-- style-operations.js
        +-- context-signature.js

style-operations.js -> store/index.js + GlobalStylesRecommender + StyleBookRecommender
style-design-semantics.js -> GlobalStylesRecommender + StyleBookRecommender
template-operation-sequence.js -> store/index.js + template-recommender-helpers + template-part-recommender-helpers
```

## Primary Source Files

- `src/store/activity-history.js`
- `src/store/update-helpers.js`
- `src/store/block-targeting.js`
- `src/components/CapabilityNotice.js`
- `src/components/AIStatusNotice.js`
- `src/components/AIAdvisorySection.js`
- `src/components/AIReviewSection.js`
- `src/context/block-inspector.js`
- `src/context/theme-tokens.js`
- `src/utils/editor-context-metadata.js`
- `src/utils/structural-identity.js`
- `src/utils/editor-entity-contracts.js`
- `src/utils/capability-flags.js`
- `src/utils/format-count.js`
- `src/utils/template-part-areas.js`
- `src/utils/template-types.js`
- `src/patterns/compat.js`
- `src/patterns/pattern-settings.js`
- `src/patterns/inserter-dom.js`
- `src/patterns/inserter-badge-state.js`
- `src/patterns/recommendation-utils.js`
- `src/context/collector.js`
- `src/context/theme-settings.js`
- `src/utils/context-signature.js`
- `src/utils/structural-equality.js`
- `src/utils/style-operations.js`
- `src/utils/style-design-semantics.js`
- `src/utils/style-validation.js`
- `src/utils/visible-patterns.js`
- `src/utils/pattern-names.js`
- `src/utils/template-operation-sequence.js`
- `src/utils/block-recommendation-context.js`
- `src/utils/recommendation-request-signature.js`
- `src/store/executable-surface-runtime.js`
- `inc/Support/RecommendationResolvedSignature.php`
- `inc/Support/RecommendationReviewSignature.php`
