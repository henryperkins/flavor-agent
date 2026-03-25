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
4. `docs/flavor-agent-readme.md`
   - Editor-flow and architecture walkthrough.
   - Use this for implementation-oriented product behavior and surface details.
5. `docs/2026-03-25-roadmap-aligned-execution-plan.md`
   - Current forward plan.
   - Use this for milestone order, file targets, acceptance tests, and roadmap alignment.
6. `docs/NEXT_STEPS_PLAN.md`
   - Historical execution context from the earlier post-v1 planning pass.
   - Keep for lineage, not as the main forward plan.
7. `docs/historical/`
   - Superseded design documents and early roadmap ideas.
   - Reference only when tracing earlier intent.

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
3. `docs/flavor-agent-readme.md` answers:
   - How do the user-facing editor flows work?
   - What data moves through each surface?
4. `docs/2026-03-25-roadmap-aligned-execution-plan.md` answers:
   - What should we build next?
   - In what order?
   - In which files?
   - With which tests?

## Update Contract

When changing the plugin:

1. Update `STATUS.md` if the verified current state, known issues, or documentation map changes.
2. Update `docs/SOURCE_OF_TRUTH.md` if the product definition, inventory, guardrails, or backlog framing changes.
3. Update `docs/flavor-agent-readme.md` if a shipped editor flow or surface behavior changes.
4. Update `docs/2026-03-25-roadmap-aligned-execution-plan.md` if milestone order, file targets, or acceptance gates materially change.
5. Move a document into `docs/historical/` instead of letting two forward-looking plans compete.

## Current Backbone

Right now the intended doc stack is:

1. Goal and guardrails: `docs/SOURCE_OF_TRUTH.md`
2. Current state: `STATUS.md`
3. Surface behavior: `docs/flavor-agent-readme.md`
4. Way forward: `docs/2026-03-25-roadmap-aligned-execution-plan.md`

If those four docs stay aligned, the repo has a solid documentation backbone.
