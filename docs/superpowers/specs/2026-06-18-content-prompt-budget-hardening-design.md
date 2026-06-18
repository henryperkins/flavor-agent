# Content Prompt-Budget Hardening -- Design

- **Date:** 2026-06-18
- **Status:** Implemented; historical design context. The shipped implementation plan is archived at `docs/superpowers/plans/archive/2026-06-18-content-prompt-budget-hardening.md`, and current behavior is summarized in `docs/reference/current-open-work.md`.
- **Owner:** Henry Perkins

## Problem

`docs/reference/current-open-work.md` keeps "Content prompt-budget hardening" open because
content recommendations cap attribute-borne text but do not cap the rendered current draft
before prompt assembly. `WritingPrompt` marks the `## Existing draft` section as required, so
`PromptBudget` may drop optional voice samples while still sending an oversized draft section.

The goal is narrow: prevent a very large rendered draft from consuming the whole content prompt
budget before broader Layer 2/3 context is added later.

## Scope

- Add a reusable `PromptBudget` helper that trims a string to an estimated token cap.
- Use it only for the content surface's existing-draft section in `WritingPrompt`.
- Preserve required task, metadata, and user-instruction sections.
- Keep voice samples optional and lower priority than the current draft.
- Update the content recommendation docs and live open-work queue after implementation.

## Design

`PromptBudget` remains the shared token-estimation utility. It gains:

- `get_max_tokens()` so surface prompt builders can base section caps on the normalized budget.
- `trim_to_tokens( $text, $max_tokens, $marker )` to preserve deterministic head and tail
  context with a clear omission marker when text exceeds a section cap.

`WritingPrompt` computes an existing-draft cap from the active content budget:

- at most 60% of the normalized prompt budget;
- no less than 800 estimated tokens;
- no more than 8000 estimated tokens.

The cap is applied to `postContext.content` before adding the required `existing_draft`
section. This keeps short and normal drafts unchanged, caps very large drafts, and still leaves
room for task metadata plus the user instruction. The truncation preserves the beginning and
end of the draft so the model sees the opener and latest closing context; the middle carries a
plain omission marker.

## Non-Goals

- No provider, connector, REST, Abilities, or UI changes.
- No change to `PostVoiceSampleCollector` sample limits.
- No content mutation, preview, apply, or undo behavior.
- No broad prompt-budget rewrite across block/template/style surfaces.

## Testing

- `PromptBudgetTest`: prove the new trimmer caps by estimated tokens, preserves head/tail
  context, and leaves in-budget text untouched.
- `WritingPromptTest`: prove a very large existing draft is capped under a constrained content
  budget while required task and instruction sections remain.
- `PostContentRendererTest`: no behavioral change needed; existing attribute count/length tests
  remain the guard for renderer extraction.

## Docs

- Update `docs/features/content-recommendations.md` so the guardrails describe the cap instead
  of the previous gap.
- Update `docs/reference/current-open-work.md` to record the 2026-06-18 completion and remove
  the active implementation-candidate row.
- Archive the implementation plan after the tests and docs pass.
