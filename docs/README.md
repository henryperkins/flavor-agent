# Flavor Agent Docs Backbone

> Created: 2026-03-25
> Purpose: documentation entry point and maintenance contract for the repo

## Why This Exists

Flavor Agent now has enough shipped surface area that a single document is no longer enough.

This file defines:

1. which document answers which question
2. which document is authoritative for product goals, current state, and next steps
3. what must be updated together when the plugin changes

## Product Direction

Flavor Agent should feel like Gutenberg and wp-admin became smarter, not like a second AI application was bolted onto WordPress.

That means:

1. native editor and admin surfaces first
2. Gutenberg nouns and constraints first
3. `theme.json`, presets, patterns, template parts, navigation, and core entities before custom abstractions
4. bounded, validated, undoable actions before broad automation
5. Connectors and core AI building blocks first when they cover the capability

It does **not** mean:

1. a floating chat workspace
2. an Elementor-style code-generation or sandbox-builder product
3. a parallel design system that bypasses Gutenberg

## Reading Order

Read these documents in this order:

1. `docs/README.md`
   - Start here.
   - Explains doc ownership and reading order.
2. `docs/SOURCE_OF_TRUTH.md`
   - Canonical product definition, architecture, inventory, guardrails, and backlog framing.
   - If two docs conflict on what Flavor Agent is, this one wins.
3. `STATUS.md`
   - Current verified state, known issues, recent verification, and top-level documentation map.
   - This is the fastest way to answer "what is true in the tree today?"
4. `docs/FEATURE_SURFACE_MATRIX.md`
   - Fast scan of every shipped surface, where it appears, and when it is gated.
   - Start here when the question is "where does this feature actually show up?"
5. `docs/features/README.md`
   - Entry point for the per-surface deep dives.
   - Use this when you need exact end-to-end user flow details.
6. `docs/reference/`
   - `abilities-and-routes.md` — abilities, REST routes, permissions, and first-party callers.
   - `shared-internals.md` — cross-cutting store utilities, shared UI components, and context helpers.
   - `recommendation-ui-consistency.md` — current surface-model split, shared vocabulary, and intentional UI exceptions.
   - `provider-precedence.md` — AI backend selection, credential fallback chain, and surface-to-backend map.
   - `template-operations.md` — operation types, placements, and validation rules per surface.
   - `activity-state-machine.md` — undo states, transitions, ordered undo, and pruning.
7. `docs/flavor-agent-readme.md`
   - Editor-flow and architecture walkthrough.
   - Use this as the architecture-oriented companion to the feature docs.
8. `docs/2026-03-25-roadmap-aligned-execution-plan.md`
   - Current forward plan.
   - Use this for milestone order, file targets, acceptance tests, and roadmap alignment.

## Doc Ownership

Each top-level doc has one job:

1. `docs/SOURCE_OF_TRUTH.md` answers:
   - What is Flavor Agent?
   - What ships today?
   - What are the core architectural and product guardrails?
2. `STATUS.md` answers:
   - What is currently working?
   - What is known broken, partial, or unverified?
   - What checks most recently passed?
3. `docs/FEATURE_SURFACE_MATRIX.md` answers:
   - Where does each feature surface appear?
   - Under what conditions does it show, hide, or degrade?
   - Which surfaces support deterministic apply and inline undo?
4. `docs/features/` answers:
   - How do the user-facing editor flows work?
   - What can each surface do today?
   - Which UI, store, REST, and backend layers make it work?
5. `docs/reference/` answers:
   - `abilities-and-routes.md` — Which ability or route owns a contract? Which permissions and backend gates apply?
   - `shared-internals.md` — Which cross-cutting store utilities, shared UI components, and context helpers do the surfaces share?
   - `recommendation-ui-consistency.md` — Which interaction model does each surface use, and which differences are intentional exceptions?
   - `provider-precedence.md` — Which AI backend serves a request? What credential sources are checked and in what order?
   - `template-operations.md` — Which operation types are valid per surface? What fields and placements are required?
   - `activity-state-machine.md` — What undo states exist? Which transitions are valid? When is undo blocked?
6. `docs/flavor-agent-readme.md` answers:
   - How does the broader editor architecture fit together?
   - How do the surface docs fit into the repo-level implementation story?
7. `docs/2026-03-25-roadmap-aligned-execution-plan.md` answers:
   - What should we build next?
   - In what order?
   - In which files?
   - With which tests?

## Update Contract

When changing the plugin:

1. Update `STATUS.md` if the verified current state, known issues, or documentation map changes.
2. Update `docs/SOURCE_OF_TRUTH.md` if the product definition, inventory, guardrails, or backlog framing changes.
3. Update `docs/FEATURE_SURFACE_MATRIX.md` if a surface location, gating rule, or apply/undo contract changes.
4. Update the matching file in `docs/features/` if a shipped surface behavior changes.
5. Update the matching file in `docs/reference/` if an ability, route, permission, response contract, provider chain, operation vocabulary, or undo lifecycle changes.
6. Update `docs/flavor-agent-readme.md` if the architecture-level editor flow or repo walkthrough changes.
7. Update `docs/2026-03-25-roadmap-aligned-execution-plan.md` if milestone order, file targets, or acceptance gates materially change.
8. Delete or rewrite stale planning docs instead of letting two forward-looking plans compete.

## Current Backbone

Right now the intended doc stack is:

1. Goal and guardrails: `docs/SOURCE_OF_TRUTH.md`
2. Current state: `STATUS.md`
3. Surface matrix: `docs/FEATURE_SURFACE_MATRIX.md`
4. Per-surface deep dives: `docs/features/README.md`
5. Programmatic and UI contract docs: `docs/reference/` (abilities-and-routes, shared-internals, recommendation-ui-consistency, provider-precedence, template-operations, activity-state-machine)
6. Architecture companion: `docs/flavor-agent-readme.md`
7. Way forward: `docs/2026-03-25-roadmap-aligned-execution-plan.md`

If those seven docs stay aligned, the repo has a solid documentation backbone.
