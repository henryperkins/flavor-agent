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
   - `cross-surface-validation-gates.md` — additive hard-stop validation rules and release evidence for multi-surface or shared-subsystem changes.
   - `pattern-recommendation-debugging.md` — operator runbook for sync, Qdrant collection health, raw retrieval, and reranking triage.
   - `provider-precedence.md` — AI backend selection, credential fallback chain, and surface-to-backend map.
   - `template-operations.md` — operation types, placements, and validation rules per surface.
   - `activity-state-machine.md` — undo states, transitions, ordered undo, and pruning.
   - `wordpress-ai-roadmap-tracking.md` — snapshot of WordPress org project 240 and the active overlap between upstream AI work and Flavor Agent surfaces, plus a refresh procedure.
7. `docs/flavor-agent-readme.md`
   - Editor-flow and architecture walkthrough.
   - Use this as the architecture-oriented companion to the feature docs.
8. `docs/wordpress-7.0-developer-docs-index.md`, `docs/wordpress-7.0-gutenberg-23-impact-brief.md`, `docs/wp7-migration-opportunities.md`, and `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md`
   - The first three are release-cycle research snapshots for upstream WordPress changes that matter to Flavor Agent.
   - `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md` is the active remediation backlog for places where Flavor Agent should stop duplicating Gutenberg or should hand ownership back to core.
   - Use the snapshots for compatibility context; use the remediation plan for live overlap-reduction work.

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
   - `cross-surface-validation-gates.md` — Which release gates does a multi-surface or shared-subsystem change trigger, and what evidence is required before sign-off?
   - `pattern-recommendation-debugging.md` — How do you debug sync, Qdrant collection health, raw retrieval, and reranking failures?
   - `provider-precedence.md` — Which AI backend serves a request? What credential sources are checked and in what order?
   - `template-operations.md` — Which operation types are valid per surface? What fields and placements are required?
   - `activity-state-machine.md` — What undo states exist? Which transitions are valid? When is undo blocked?
6. `docs/flavor-agent-readme.md` answers:
   - How does the broader editor architecture fit together?
   - How do the surface docs fit into the repo-level implementation story?
7. `docs/wordpress-7.0-developer-docs-index.md`, `docs/wordpress-7.0-gutenberg-23-impact-brief.md`, `docs/wp7-migration-opportunities.md`, and `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md` answer:
   - Which upstream WordPress 7.0 and Gutenberg changes matter to Flavor Agent?
   - Which release-cycle notes are authoritative or merely supplemental?
   - Which migration or tooling opportunities are worth tracking without treating them as shipped repo behavior?
   - Which current overlap-remediation decisions are active and should guide implementation?

## Update Contract

When changing the plugin:

1. Update `STATUS.md` if the verified current state, known issues, or documentation map changes.
2. Update `docs/SOURCE_OF_TRUTH.md` if the product definition, inventory, guardrails, or backlog framing changes.
3. Update `docs/FEATURE_SURFACE_MATRIX.md` if a surface location, gating rule, or apply/undo contract changes.
4. Update the matching file in `docs/features/` if a shipped surface behavior changes.
5. Update the matching file in `docs/reference/` if an ability, route, permission, response contract, provider chain, operation vocabulary, undo lifecycle, or release-validation rule changes.
6. Update `docs/flavor-agent-readme.md` if the architecture-level editor flow or repo walkthrough changes.
7. Update the relevant WordPress reference doc (`docs/wordpress-7.0-developer-docs-index.md`, `docs/wordpress-7.0-gutenberg-23-impact-brief.md`, `docs/wp7-migration-opportunities.md`, or `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md`) when upstream WordPress release-cycle research, migration guidance, or overlap-remediation direction changes.
8. Delete or rewrite stale planning docs instead of letting two forward-looking plans compete.

## Current Backbone

Right now the intended doc stack is:

1. Goal and guardrails: `docs/SOURCE_OF_TRUTH.md`
2. Current state: `STATUS.md`
3. Surface matrix: `docs/FEATURE_SURFACE_MATRIX.md`
4. Per-surface deep dives: `docs/features/README.md`
5. Programmatic and UI contract docs: `docs/reference/` (abilities-and-routes, shared-internals, recommendation-ui-consistency, cross-surface-validation-gates, provider-precedence, template-operations, activity-state-machine)
6. Architecture companion: `docs/flavor-agent-readme.md`
7. WordPress compatibility, migration snapshots, and overlap remediation: `docs/wordpress-7.0-developer-docs-index.md`, `docs/wordpress-7.0-gutenberg-23-impact-brief.md`, `docs/wp7-migration-opportunities.md`, and `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md`

If those seven docs stay aligned, the repo has a solid documentation backbone.

## Audits and Research

Point-in-time artifacts that do not belong in the reading order but are preserved for context:

1. `docs/audits/` — completed or in-flight audit prompts and reports. These do not reflect live plugin state; treat them as historical analysis. Delete or supersede rather than silently updating.
2. `docs/research/` — external research snapshots (upstream WordPress announcements, design-trend writeups). Use as inspiration or compatibility context, not as the plugin's own spec.

If an audit or research doc becomes load-bearing for a decision, promote it into the backbone (`docs/reference/` or a per-surface feature doc) rather than leaving it in these directories.
