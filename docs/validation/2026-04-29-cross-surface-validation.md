# 2026-04-29 Cross-Surface Validation Artifact

## Change Summary

Staged cross-surface alignment pass for Flavor Agent's current WordPress 7.0-facing contract. The changes align capability/readiness messaging, content surface documentation, pattern availability copy, provider ownership wording, contributor runbooks, validation guidance, and personal planning/portfolio artifacts around the current model:

- `Settings > Connectors` owns chat/text generation through the WordPress AI Client.
- `Settings > Flavor Agent` retains plugin-owned embedding, Qdrant, Cloudflare docs grounding, and pattern sync controls.
- Eight first-party recommendation surfaces are documented: block, pattern, content, template, template-part, navigation, Global Styles, and Style Book.
- Cross-surface release validation is explicitly governed by `docs/reference/cross-surface-validation-gates.md`.

## Surfaces Touched

- Capability/readiness contracts: `inc/Abilities/SurfaceCapabilities.php`, `src/utils/capability-flags.js`, `EditorSurfaceCapabilitiesTest`, `capability-flags.test.js`.
- Ability and REST-facing contracts: `inc/Abilities/PatternAbilities.php`, `inc/Abilities/Registration.php`, `docs/reference/abilities-and-routes.md`.
- Settings/operator copy: `inc/Admin/Settings/Page.php`, `inc/Admin/Settings/State.php`, `STATUS.md`, `readme.txt`, `docs/flavor-agent-readme.md`.
- Recommendation docs: content, pattern, block, navigation, template, template-part, activity/audit, feature matrix, source-of-truth, shared internals.
- E2E smoke surface: `tests/e2e/flavor-agent.smoke.spec.js`.
- Planning/portfolio/upstream artifacts: `productivity-plan.md`, `portfolio.md`, `upstream-log.md`.

## Formal Gates Triggered

| Gate | Triggered | Evidence needed |
| --- | --- | --- |
| 1. REST and shared contracts | Yes | Targeted PHPUnit/JS for ability contracts, surface capabilities, and capability flags. |
| 2. Provider and backend routing | Yes | Provider ownership copy and capability actions must match Connectors chat plus plugin-owned embeddings/Qdrant. |
| 5. Shared UI taxonomy and mode | Yes | Capability notice/action taxonomy must preserve each surface mode: direct apply, review first, advisory, or ranking-only. |
| 6. Operator and admin paths | Yes | Settings copy, docs, and admin/operator status must accurately point chat to Connectors and embeddings/pattern sync to Flavor Agent. |
| 7. Multi-surface release matrix | Yes | Non-browser verifier, docs check, targeted tests, and browser evidence or recorded blockers. |

## Tests Run

Pending.

## Dead-Code Sweep

Pending.

## Provider / Backend Check

Pending.

## Upstream Check

Pending.

## Browser Evidence

Pending.

## Decision

Hold until the targeted tests, non-browser verifier, docs check, and browser evidence/blocker notes are recorded.
