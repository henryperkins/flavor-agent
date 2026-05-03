# Agentic Plan Implementation Guide

## Execution Protocol

Use this protocol for all docs under `docs/superpowers/**` and implementation plans that carry structured task steps.

- Follow a worker-oriented execution loop when implementing a plan.
- Use one of:
  - `superpowers:subagent-driven-development` (recommended)
  - `superpowers:executing-plans`
- Keep plan steps as checkbox tasks and preserve completion state.
- Prefer incremental tasks and explicit sequencing when dependencies exist.
- Keep drift-prone sections (scope, prerequisites, verification) current when context changes.

## Standard Step Format

Each execution plan should use task blocks in this style:

```md
- [ ] **Step N:** short action

  _Why_: the reason this step exists

  _Validation_: command or check name
```

## Tracking Notes

- Checkbox style is `- [ ]` for TODO and `- [x]` for completed.
- Keep `Step X` naming stable once tasks are executed; if scope changes, append a version note.
- For cross-surface or shared-subsystem work, group verification commands by layer before moving to the next step.
