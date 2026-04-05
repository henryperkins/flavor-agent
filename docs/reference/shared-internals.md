# Shared Internals

Cross-cutting store utilities, shared UI components, and context helpers used by multiple recommendation surfaces. Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Store Utilities

### `src/store/activity-history.js`

Session-scoped AI activity schema, scope resolution, `sessionStorage` cache/fallback layer, and undo-state resolution. This is the most heavily imported store module (10+ callers).

**Key exports:**

| Export | Role |
|---|---|
| `resolveActivityScope(postType, entityId)` | Builds a normalized scope object `{ key, hint, postType, entityId }` to partition activity logs per edited entity |
| `resolveGlobalStylesScope(globalStylesId, opts)` | Scope object keyed to a Global Styles entity |
| `resolveStyleBookScope(globalStylesId, blockName, opts)` | Scope object keyed to a specific block within the Style Book UI |
| `getCurrentActivityScope(registry)` | Inspects the data registry to determine the active editing scope (post, global styles, or Style Book block) |
| `readPersistedActivityLog(scopeKey)` | Reads the `sessionStorage` cache for a given scope key; handles schema version migration and legacy detection |
| `writePersistedActivityLog(scopeKey, entries)` | Writes the trimmed activity log to `sessionStorage` as a versioned JSON blob |
| `createActivityEntry({...})` | Factory for new activity entries with monotonic IDs, timestamps, initial undo state, and persistence tracking |
| `getBlockActivityUndoState(entry, blockEditorSelect)` | Resolves live undo state for a block-surface entry by checking whether the target block still exists and its attributes match the expected snapshot |
| `getResolvedActivityEntries(entries, runtimeUndoResolver)` | Batch-resolves undo states for all entries, applying ordered-undo rules across the full log |
| `getLatestUndoableActivity(entries, runtimeUndoResolver)` | Returns the most recent tail entry that is both `canUndo: true` and `status: 'available'` after full resolution |
| `ORDERED_UNDO_BLOCKED_ERROR` | Error message constant displayed when undo is blocked because newer AI actions exist |

**Session cache contract:** The server-backed activity repository is the source of truth. `sessionStorage` acts only as a cache/fallback so the current editor session can display activity entries before server hydration completes and handle transient offline periods. On entity change, `loadActivitySession()` merges server entries with pending local entries and writes the merged set back to `sessionStorage`. Entries that have been persisted to the server carry `persistence.status === 'server'`; local-only entries carry `'local'` or `'create'` status and are synced on the next opportunity.

**Consumers:** `src/store/index.js`, `src/inspector/BlockRecommendationsPanel.js`, `src/global-styles/GlobalStylesRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `src/templates/TemplateRecommender.js`, `src/style-book/StyleBookRecommender.js`, `src/components/ActivitySessionBootstrap.js`, `src/admin/activity-log-utils.js`

### `src/store/update-helpers.js`

Pure-function utility with zero internal dependencies. Contains the core of the block apply/undo path: safe attribute merging, undo patch construction, snapshot comparison, content-only filtering, and full suggestion sanitization.

**Key exports:**

| Export | Role |
|---|---|
| `buildSafeAttributeUpdates(currentAttributes, suggestedUpdates)` | Produces a safe attribute patch by filtering out raw CSS injection, stripping unsafe theme payloads, and deep-merging nested keys so existing sub-properties are preserved |
| `buildUndoAttributeUpdates(previousAttributes, nextAttributes)` | Builds a restoration patch that reverts an applied attribute change; keys present only in `next` are set to `undefined` |
| `attributeSnapshotsMatch(previousSnapshot, currentSnapshot)` | Deep structural equality check between two attribute snapshots, used by undo logic to determine whether a block's attributes still match the expected state |
| `sanitizeRecommendationsForContext(recommendations, blockContext)` | Main sanitization pipeline: normalizes suggestion groups, strips theme-unsafe CSS, filters binding-unsafe attributes, enforces editing-mode restrictions |
| `getBlockSuggestionExecutionInfo(suggestion, blockContext)` | Returns `{ allowedUpdates, isAdvisory, isAdvisoryOnly, isExecutable }` describing whether a suggestion is purely informational or carries executable attribute changes |

**Consumers:** `src/store/index.js` (uses all apply/undo helpers), `src/store/activity-history.js` (uses `attributeSnapshotsMatch`), `src/inspector/BlockRecommendationsPanel.js` (uses `getBlockSuggestionExecutionInfo`)

### `src/store/block-targeting.js`

Minimal module (2 exports) that resolves activity entry targets to live editor blocks. This is the bridge between persisted activity entries and the live block editor state.

**Key exports:**

| Export | Role |
|---|---|
| `getBlockByPath(blocks, path)` | Traverses a block tree using an array of numeric indices and returns the block at that location |
| `resolveActivityBlock(blockEditorSelect, target)` | Resolves an activity target: tries `target.clientId` first, then falls back to `target.blockPath` via tree traversal |

**Consumers:** `src/store/activity-history.js`, `src/store/index.js`

## Shared UI Components

Four shared components form a consistent UI contract across all recommendation surfaces. Every surface uses the same capability gating and status feedback pattern; executable surfaces share the same review-before-apply and advisory-only section components.

### `src/components/CapabilityNotice.js`

Renders a non-dismissible `<Notice>` with an optional action link when the backend capability for a given surface is unavailable. Delegates content to `getCapabilityNotice(surface, data)` from `src/utils/capability-flags.js`. Returns `null` when the capability is satisfied.

**Props:** `surface` (string key: `'block'`, `'pattern'`, `'template'`, `'templatePart'`, `'navigation'`, `'globalStyles'`, `'styleBook'`), `data` (optional override for `flavorAgentData`)

**Consumers:** Block Inspector, Pattern Inserter, Template, Template-Part, Navigation, Global Styles, Style Book (7 surfaces)

### `src/components/AIStatusNotice.js`

Renders a contextual `<Notice>` with a configurable tone (`info`, `warning`, `error`, `success`), message, optional action button, and optional dismiss handler. Returns `null` when `notice?.message` is falsy. This is the unified feedback bar used by every recommendation surface for transient status (loading, errors, rate limits, post-apply/post-undo feedback).

**Props:** `notice` (object: `{ message, tone?, actionLabel?, actionDisabled?, isDismissible? }`), `onAction`, `onDismiss`, `className`

**Consumers:** Block Inspector, Navigation, Template, Template-Part, Global Styles, Style Book (6 surfaces)

### `src/components/AIAdvisorySection.js`

Renders a styled section container for non-executable, advisory-only AI suggestions (e.g. structural recommendations, pattern replacement ideas). Displays a title, an "Advisory only" pill badge, a formatted count pill, optional description, optional meta slot, and a body slot for child content.

**Props:** `title`, `advisoryLabel`, `count`, `countLabel`, `countNoun`, `description`, `meta`, `children`, `className`

**Consumers:** Template, Template-Part, Navigation (3 surfaces)

### `src/components/AIReviewSection.js`

Renders a review-before-apply confirmation panel for executable AI operations. Displays a title, "Executable" status pill, operation count pill, optional summary, a child content slot (typically an operation list), an optional hint, and Confirm/Cancel action buttons.

**Props:** `title`, `statusLabel`, `count`, `countLabel`, `countNoun`, `summary`, `children`, `hint`, `confirmLabel`, `cancelLabel`, `onConfirm`, `onCancel`, `confirmDisabled`, `className`

**Consumers:** Template, Template-Part, Global Styles, Style Book (4 surfaces)

## Context Helpers

### `src/context/block-inspector.js`

Client-side block introspection. Queries the `@wordpress/blocks` and `@wordpress/block-editor` stores to build comprehensive block manifests including supports, inspector panels, bindable/content/config attribute splits, styles, variations, editing mode, and content-only status.

**Key exports:**

| Export | Role |
|---|---|
| `introspectBlockInstance(clientId)` | Full manifest for a live block: type metadata + current attributes, editing mode, parent chain, child count, content-only status, block visibility |
| `introspectBlockTree(rootClientId, maxDepth)` | Recursively introspects child blocks from a root, returning a nested tree of instance manifests |
| `summarizeTree(tree, options, depth)` | Compresses an introspected tree into a token-budget-friendly summary for LLM prompts |
| `buildCapabilityIndex(tree)` | Deduplicated map of unique block types to their capability summaries |

**Consumers:** `src/context/collector.js`

### `src/context/theme-tokens.js`

Design token extraction from `theme.json` and global styles. Produces a full token manifest (color palette, gradients, duotone, typography, spacing, layout, shadow, border, background, element styles, block pseudo-styles, diagnostics) and compresses it into LLM-prompt-friendly summaries.

**Key exports:**

| Export | Role |
|---|---|
| `collectThemeTokens()` | Entry point: reads current editor settings and returns the full design-token manifest |
| `summarizeTokens(tokens)` | Compresses the manifest into a compact, prompt-friendly summary |
| `buildGlobalStylesExecutionContract(tokens)` | Combines supported style paths with sorted preset slug maps for the LLM to validate style writes |
| `buildBlockStyleExecutionContract(tokens, blockType)` | Same as above but scoped to a specific block type's supports |

**Consumers:** `src/context/collector.js`, `src/utils/style-operations.js`, `src/global-styles/GlobalStylesRecommender.js`, `src/style-book/StyleBookRecommender.js`

### `src/utils/editor-context-metadata.js`

Walks block trees to collect pattern override summaries and viewport visibility summaries for LLM context enrichment.

**Key exports:**

| Export | Role |
|---|---|
| `describeEditorBlockLabel(blockName, attributes, areaLookup)` | Human-readable label for a block (includes slug and area for template parts) |
| `collectPatternOverrideSummary(blocks, areaLookup)` | Summary of all blocks with `core/pattern-overrides` bindings |
| `collectViewportVisibilitySummary(blocks, areaLookup)` | Summary of all blocks with viewport-based block visibility rules |

**Consumers:** `src/global-styles/GlobalStylesRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `src/templates/template-recommender-helpers.js`

### `src/utils/structural-identity.js`

Block structural role inference. Recursively annotates a block tree with semantic roles (e.g. `primary-navigation`, `header-slot`, `main-content`), human-readable labels, job descriptions, location (header/footer/sidebar/content), template-part areas, position metrics, and evidence trails.

**Key exports:**

| Export | Role |
|---|---|
| `annotateStructuralIdentity(tree, options)` | Recursive tree walker that attaches `structuralIdentity` objects to every node |
| `buildStructuralContext(tree, selectedClientId, options)` | Orchestrates annotation + path finding to produce a complete structural context for a selected block |
| `toStructuralSummary(node)` | Extracts a compact summary for LLM prompt use |

**Consumers:** `src/context/collector.js`

## Entity And Capability Utilities

### `src/utils/editor-entity-contracts.js`

Canonical abstraction layer between WordPress's dual editor stores (`core/editor` for post editing, `core/edit-site` for site editing) and the plugin's feature surfaces. Provides entity resolution, post-type field definitions, view/layout normalization, and a memoized React hook that composes everything into a single contract object.

**Key exports:**

| Export | Role |
|---|---|
| `getEditedPostTypeEntity(select, expectedPostType?)` | Resolves the currently edited entity from `core/editor` or `core/edit-site` |
| `getPostTypeFieldDefinitions(postType)` | Returns frozen `{ id, label }` field descriptors appropriate for each post type |
| `usePostTypeEntityContract(postType)` | React hook: composes entity fields, view/layout defaults, area options, and recommended category into a single contract |

**Consumers:** `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `src/patterns/PatternRecommender.js`

### `src/utils/capability-flags.js`

Derives surface capability flags from the server-localized `flavorAgentData` bootstrap object. Produces the notice content and action links for `CapabilityNotice`.

**Consumers:** `src/components/CapabilityNotice.js`

### `src/utils/format-count.js`

Micro-utility providing `formatCount()` (count formatting with noun pluralization), `humanizeString()` (string humanization), and `joinClassNames()` (CSS class-name concatenation).

**Consumers:** `src/components/AIAdvisorySection.js`, `src/components/AIReviewSection.js`, `src/components/AIStatusNotice.js`

## Pattern Internals

### `src/patterns/compat.js`

Re-export facade that centralizes `pattern-settings.js` and `inserter-dom.js` into a single public API surface. Feature surfaces that only need pattern reads/writes or inserter DOM access import through this barrel.

### `src/patterns/pattern-settings.js`

Three-tier pattern API adapter: resolves stable settings keys first (`blockPatterns`), then `__experimentalAdditionalBlockPatterns`, then `__experimentalBlockPatterns`. Provides `getBlockPatterns()`, `setBlockPatterns()`, `getAllowedPatterns()`, and runtime diagnostics.

### `src/patterns/inserter-dom.js`

CSS selector constants and DOM finders for the inserter container, search input, and toggle button. Used by `InserterBadge` and `PatternRecommender` to mount into the native inserter UI.

### `src/patterns/inserter-badge-state.js`

Pure state-machine function `getInserterBadgeState()` that maps pattern recommendation status (`loading`, `error`, `ready`) into a badge view-model with `status`, `count`, `content`, `tooltip`, `ariaLabel`, and `className`.

**Consumers:** `src/patterns/InserterBadge.js`

### `src/patterns/recommendation-utils.js`

Pure-function utilities for pattern metadata patching and badge reason extraction. `patchPatternMetadata()` rewrites the native pattern registry so recommended patterns appear in the `Recommended` category with contextual descriptions and keywords. `getPatternBadgeReason()` returns the reason from the highest-confidence recommendation for badge tooltip use.

**Consumers:** `src/patterns/PatternRecommender.js`, `src/store/index.js`

## Template And Template-Part Utilities

### `src/utils/template-part-areas.js`

Template-part area resolution from attributes, slug, or the server-localized area registry (`window.flavorAgentData.templatePartAreas`). Uses a four-tier resolution: explicit `area` attribute, slug lookup, well-known slug names (`header`/`footer`/`sidebar`), and HTML tag name fallback.

**Key exports:**

| Export | Role |
|---|---|
| `getTemplatePartAreaLookup()` | Returns the canonical slug-to-area mapping from `flavorAgentData` |
| `inferTemplatePartArea(attributes, areaLookup)` | Convenience: returns the resolved area string |
| `matchesTemplatePartArea(block, area, areaLookup)` | Predicate: does the block's inferred area match the given area? |

**Consumers:** `src/utils/structural-identity.js`, `src/utils/editor-context-metadata.js`, `src/utils/template-actions.js`, `src/templates/template-recommender-helpers.js`, `src/template-parts/TemplatePartRecommender.js`

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
