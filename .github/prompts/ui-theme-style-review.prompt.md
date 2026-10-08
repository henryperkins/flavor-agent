---
description: "Audit Flavor Agent editor/admin UI for confirmed theme, style, accessibility, and stale-state/apply bugs"
name: "Flavor Agent UI / Theme / Style Review"
argument-hint: "Named UI surface, changed files, or the whole editor/admin UI"
agent: "agent"
---

Review the requested Flavor Agent UI using the repository-root-relative instructions in `.codex/skills/ui-theme-style-review/SKILL.md` and the shared contract at `docs/reference/ui-theme-style-review.md`. Follow `docs/reference/review-response-protocol.md` for reporting. These are review instructions, not permission to implement fixes.

Use the invocation arguments to scope the review. With no narrower scope, cover the mounted editor surfaces, Settings, AI Activity, and shared UI across theme/style, accessibility, and stale-state behavior. Record unavailable evidence; do not stop after finding the first defect.

Open source and prove the active path before reporting. Styling literals, brand tokens, fallbacks, experimental APIs, layout differences, and missing tests are leads rather than confirmed bugs. Use rendered or isolated test evidence when the cascade, focus, portal/iframe, contrast, or async behavior cannot be resolved from source.

Keep site data unchanged. Pending Activity details can acquire review claims on open; use isolated fixtures for claim/decision/apply/undo and other mutating UI interactions unless live actions are authorized.

Lead with confirmed `P0`–`P3` findings with exact source lines, trigger, impact, evidence, and minimal fixes. Separate open questions and end with concise coverage and verification actually reviewed, including material checks not run.
