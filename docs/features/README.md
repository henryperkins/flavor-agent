# Flavor Agent Feature Docs

This directory is the detailed, surface-level documentation set for Flavor Agent.

Use these docs when you need exact answers for:

- where a feature shows up in Gutenberg or wp-admin
- when the feature appears, hides, or degrades
- the end-to-end flow from UI trigger to backend response and back
- which functions, routes, and abilities own the behavior

## Reading Order

1. `docs/FEATURE_SURFACE_MATRIX.md` - fast scan of all surfaces, conditions, and apply/undo support
2. one of the surface docs in this directory - exact end-to-end behavior for that feature
3. `docs/reference/abilities-and-routes.md` - exact ability and REST contracts

## Surface Docs

- `docs/features/block-recommendations.md` - per-block Inspector recommendations, apply, and undo
- `docs/features/pattern-recommendations.md` - inserter recommendations, badge state, and pattern ranking pipeline
- `docs/features/navigation-recommendations.md` - advisory guidance for selected `core/navigation` blocks
- `docs/features/template-recommendations.md` - Site Editor template composition suggestions and validated apply flow
- `docs/features/template-part-recommendations.md` - Site Editor template-part suggestions, focus links, and bounded operations
- `docs/features/style-and-theme-intelligence.md` - Site Editor Global Styles recommendations, guarded operations, and scoped undo
- `docs/features/activity-and-audit.md` - inline activity history, ordered undo rules, and the admin audit page
- `docs/features/settings-backends-and-sync.md` - settings screen, backend gating, validation behavior, and pattern sync

## Required Sections

Every feature doc in this directory should answer the same questions:

1. exact user-facing surface
2. surfacing and gating conditions
3. end-to-end flow
4. capability contract
5. guardrails and failure modes
6. primary functions, routes, and abilities

## Update Rule

When a shipped surface changes, update:

1. the matching feature doc here
2. `docs/FEATURE_SURFACE_MATRIX.md`
3. `docs/reference/abilities-and-routes.md` when the contract changed
4. `STATUS.md` if verified behavior changed
