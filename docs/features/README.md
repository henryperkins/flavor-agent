# Flavor Agent Feature Docs

This directory is the detailed feature documentation set for Flavor Agent's first-party UI surfaces, programmatic surfaces, and shared operator paths.

Use these docs when you need exact answers for:

- where a feature shows up in Gutenberg or wp-admin, or whether it is programmatic-only
- when the feature appears, hides, or degrades
- the end-to-end flow from UI trigger to backend response and back
- which functions, routes, and abilities own the behavior
- which adjacent docs must stay in sync when the feature moves

## Reading Order

1. `docs/SOURCE_OF_TRUTH.md` - repo-wide product map, feature inventory, and current contract snapshot
2. `docs/FEATURE_SURFACE_MATRIX.md` - fast scan of first-party editor/admin surfaces plus the programmatic surface map
3. one of the feature docs in this directory - exact end-to-end behavior for that feature or contract area
4. `docs/reference/abilities-and-routes.md` - exact ability and REST contracts
5. `docs/reference/shared-internals.md` - cross-cutting store utilities, shared UI components, and context helpers referenced by multiple surfaces
6. `docs/reference/recommendation-ui-consistency.md` - shared surface taxonomy, interaction-model split, and intentional UI exceptions
7. `docs/reference/cross-surface-validation-gates.md` - additive release gates and required evidence for multi-surface or shared-subsystem changes
8. `docs/reference/pattern-recommendation-debugging.md` - operator runbook for sync, Qdrant collection health, raw retrieval, and reranking triage

## First-Party Editor And Admin Surfaces

- `docs/features/block-recommendations.md` - per-block Inspector recommendations, projection tabs, apply, and undo
- `docs/features/pattern-recommendations.md` - inserter recommendations, badge state, and pattern ranking pipeline
- `docs/features/content-recommendations.md` - post/page document panel for drafting, editing, and critique; also exposed as REST + Abilities
- `docs/features/navigation-recommendations.md` - advisory guidance for selected `core/navigation` blocks, including embedded and fallback shells
- `docs/features/template-recommendations.md` - Site Editor template composition suggestions and validated apply flow
- `docs/features/template-part-recommendations.md` - Site Editor template-part suggestions, focus links, and bounded operations
- `docs/features/style-and-theme-intelligence.md` - Site Editor Global Styles and Style Book recommendations, guarded operations, and scoped undo
- `docs/features/activity-and-audit.md` - inline activity history, ordered undo rules, scoped audit rows, and the admin audit page
- `docs/features/settings-backends-and-sync.md` - settings screen, backend gating, validation behavior, and pattern sync

## Programmatic And Shared-Contract Features

- `docs/features/helper-abilities.md` - helper abilities, diagnostics, and trusted WordPress docs search for external agents and admin tooling

## Required Sections

Every feature doc in this directory should answer the same core questions for its own surface or caller:

1. exact user-facing surface or calling context
2. surfacing and gating conditions
3. end-to-end flow
4. capability contract
5. guardrails and failure modes
6. primary functions, routes, and abilities

## Update Rule

When a shipped surface changes, update:

1. the matching feature doc here
2. `docs/FEATURE_SURFACE_MATRIX.md`
3. `docs/SOURCE_OF_TRUTH.md`
4. `docs/reference/abilities-and-routes.md` when the contract changed
5. `docs/reference/recommendation-ui-consistency.md` when the interaction model, shared taxonomy, or intentional exceptions change
6. `docs/reference/cross-surface-validation-gates.md` when release evidence expectations or hard-stop validation rules change
7. `STATUS.md` if verified behavior changed
