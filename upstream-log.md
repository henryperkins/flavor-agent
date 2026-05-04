# Flavor Agent Upstream Log

Use this as the lightweight recurring record for WordPress AI, Gutenberg, and WordPress Design System changes that may affect Flavor Agent. Keep entries short: date, upstream change or scan target, Flavor Agent response, and next action if needed.

The deeper upstream map lives in `docs/reference/wordpress-ai-roadmap-tracking.md`; this file is the operational proof that the scan happened.

## Entries

### 2026-05-03

- **Scanned:** `WordPress/ai` milestones [17 (`0.9.0`)](https://github.com/WordPress/ai/milestone/17) and [7 (`1.0.0`)](https://github.com/WordPress/ai/milestone/7) via the public GitHub API.
- **Impact:** The core AI plugin release train now makes `0.9.0` the version immediately before `1.0.0`, with `1.0.0` described upstream as the WordPress 7.0 Stable Release Candidate target. Near-term overlap shifts from the older bundled `#419` PR to split milestone items such as AI Request Logging (`#437`), Ability schema sanitization (`#481`), provider permission/settings work (`#343`, `#486`), and client-side Abilities API usage (`#482`).
- **Response:** Updated `docs/reference/wordpress-ai-roadmap-tracking.md` with a release-milestone overlay, current watch-first items, and revised activity/provider/abilities collision tables.
- **Next action:** Watch the `0.9.0` logging and schema PRs first; when they merge, decide whether Flavor Agent forwards activity writes into the core AI plugin logging schema or retires parts of `Settings > AI Activity`.

### 2026-04-30

- **Scanned:** `productivity-plan.md`, `docs/reference/wordpress-ai-roadmap-tracking.md`, `docs/reference/gutenberg-feature-tracking.md`, and the current recommendation-actionability docs after the M4/M1A completion pass.
- **Impact:** No new upstream conflict was introduced by block structural actions because the feature remains editor-bound, validator-approved, and default-off behind `FLAVOR_AGENT_ENABLE_BLOCK_STRUCTURAL_ACTIONS`. The next upstream-alignment pressure is still ability metadata annotations for readonly/destructive/idempotent behavior.
- **Response:** Reconciled `docs/reference/recommendation-actionability-implementation-plan.md` so the canonical plan now records M0-M4/M1A as complete and treats the structural-actions flag as rollout control rather than missing implementation.
- **Next action:** Before any beta exposure of structural actions, run the flag-enabled WP 7.0 manual QA pass and prioritize ability annotation cleanup if upstream/MCP alignment is the next PR.

### 2026-04-29

- **Scanned:** Current repo contract against the WordPress AI roadmap tracking doc and live provider/surface code.
- **Impact:** Content recommendations are now a first-party post/page editor panel, not only a future programmatic lane. Chat ownership is Connectors-first through the WordPress AI Client; Azure OpenAI and OpenAI Native remain plugin-owned for embeddings only.
- **Response:** Updated source capability copy, docs, tests, and validation wording to reflect the eight-surface inventory and Connectors-owned chat boundary.
- **Next action:** On the next upstream scan, check whether core exposes an embeddings provider path or changes the Connectors/Abilities/Guidelines contracts enough to retire more plugin-owned settings.
