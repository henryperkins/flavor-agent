# Adapted Pattern Recommendation Preview

This records the shipped adapted-preview surface for the existing Pattern Recommendations flow: the 2026-06-19 deterministic preview v1 plus the 2026-06-24 comparison follow-up. It builds on `docs/features/pattern-recommendations.md`, where Flavor Agent ranks patterns, displays them in the native Gutenberg inserter, and inserts the selected pattern as Gutenberg exposes it.

## Current Implementation State

As of 2026-06-24, adapted preview comparison is implemented for non-synced recommended patterns. The live pattern surface remains an inserter ranking assist surface, but non-synced shelf items now expose `Preview adapted`, `Insert adapted`, and `Insert original` through a labeled original/adapted compare panel; synced/user `core/block` references keep a single unchanged `Insert` action and are not detached.

The shipped v1 seams are:

- `src/patterns/PatternRecommender.js` owns the shelf action split, the preview state, the adaptation-signature recheck, and the shared guarded insert path for both original and adapted insertion.
- `src/patterns/pattern-insertability.js` owns reusable block resolution and resolved-block insertability helpers through `resolvePatternBlocks()`, `getRejectedResolvedBlockNames()`, and `isSyncedPatternReference()`.
- `src/patterns/pattern-adaptation-context.js` reads the client-only nearby heading and alignment context from the live editor at preview time.
- `src/patterns/pattern-adaptation.js` clones non-synced pattern blocks, applies the deterministic cosmetic rules, emits `pattern-adaptation-v1` plan changes, and returns a client-side `adaptationSignature`.
- `src/patterns/PatternAdaptationPreview.js` renders labeled original/adapted `BlockPreview` sections plus deterministic per-change summary rows, and exposes `Insert adapted`, `Insert original`, and `Close`.
- `src/store/recommendation-outcomes.js` and `inc/Activity/RecommendationOutcome.php` allow the adapted outcome vocabulary: `adapted_preview_shown`, `adapted_inserted_from_preview`, `adaptation_blocked`, and `adapted_insert_failed`.

The implemented rule set is intentionally cosmetic and deterministic: nearby heading-level alignment, supported alignment matching, theme color preset remapping, theme spacing preset remapping, and registered `core/button` style variation selection. The implementation does not change block names, add/remove top-level blocks, rewrite arbitrary HTML, generate content text, detach synced patterns, or use the model to author an adaptation plan.

The WordPress 7.0 Pattern Overrides premise remains current for custom blocks: attributes that opt into Block Bindings can participate in overrides, but that is still a per-instance content override path rather than an arbitrary cosmetic mutation path for a synced `core/block` reference.

## Goal

Let users insert patterns that both fit the current insertion point and visually align with the surrounding page. The feature should preserve the existing structural recommendation contract, add a bounded cosmetic adaptation step, and show a visual comparison of the exact original and adapted block trees before insertion.

The core principle is:

1. Recommend a structurally appropriate pattern first.
2. Clone the pattern's blocks.
3. Apply safe cosmetic adaptation to the clone.
4. Preview the adapted clone.
5. Re-clone the previewed adapted block tree immediately before confirmed insertion.

## Non-Goals

- Do not use cosmetic adaptation to rescue structurally wrong recommendations.
- Do not rewrite Gutenberg's pattern registry or native category metadata.
- Do not silently replace a synced pattern reference with a detached copy.
- Do not generate arbitrary block HTML when a structured block mutation is possible.
- Do not add a full Flavor Agent review/apply/undo contract in the first version.

## User Experience

The existing inserter shelf remains the entry point. Each non-synced recommended pattern exposes an adaptation-aware action set:

- `Preview adapted` opens a visual comparison of the original and adapted result.
- `Insert adapted` inserts the adapted block clone.
- `Insert original` remains available for trust and comparison.

Synced/user `core/block` references keep a single unchanged `Insert` action in v1.

The preview should make the adaptation explicit without making the surface feel like a separate workflow. The compare panel should label the two renders clearly and summarize each deterministic cosmetic change with block name, attribute path, and before/after values instead of a prose-only note.

The preview should render the exact block arrays involved in insertion: the untouched original tree for trust/comparison and the adapted tree for `Insert adapted`. It must not be an approximation, generated screenshot, or server-rendered substitute unless that substitute is explicitly labeled as a fallback.

## Current Baseline

Today, `PatternRecommender()` resolves a recommendation to the matching pattern that Gutenberg exposes for the current insertion root. It then uses `resolvePatternBlocks()` to get insertable blocks from one of three sources:

- a `core/block` reference for synced user patterns,
- the pattern object's existing `blocks` array,
- parsed blocks from the pattern's serialized `content`.

Before inserting, the current flow validates freshness with `resolveSignatureOnly`, checks allowed block types, dispatches `insertBlocks()`, and verifies that Gutenberg inserted the cloned blocks at the requested target.

The extension should preserve these guardrails and add adaptation between block resolution and `insertBlocks()`.

## Proposed Flow

1. `PatternRecommender()` receives ranked recommendations as it does today.
2. The shelf matches recommendations to allowed Gutenberg pattern objects for the current insertion root.
3. When the user opens preview, Flavor Agent resolves the pattern blocks and clones them.
4. A new adaptation module builds an `adaptationPlan` from:
   - insertion context,
   - nearby sibling blocks,
   - template part area,
   - current theme tokens,
   - block supports and registered attributes,
   - optional recommendation metadata such as `patternOverrides` and ranking hints.
5. The adaptation module applies the plan to the cloned block tree.
6. The preview UI renders the adapted block tree using Gutenberg's `BlockPreview` component from `@wordpress/block-editor`.
7. The user confirms insertion.
8. The insert handler revalidates the current insertion target and server-resolved context.
9. Flavor Agent re-checks that the adapted blocks remain insertable at the target.
10. `insertBlocks()` receives a fresh clone of the adapted block array shown in preview.
11. Existing post-dispatch verification records success, no-op, rejected insertion, or wrong-target rollback.

## Adaptation Scope

Adaptation starts with cosmetic changes that are low-risk and easy to validate:

- Shipped in v1: heading levels that follow nearby heading hierarchy.
- Shipped in v1: alignment values when supported by the block and suitable for the insertion root.
- Shipped in v1: theme color preset slugs for supported text/background paths.
- Shipped in v1: spacing preset slugs for supported padding, margin, and block-gap paths.
- Shipped in v1: button style variations that are registered for `core/button`.
- Deferred: typography preset slugs or size values.
- Deferred: image aspect ratio or size slug.
- Deferred: placeholder or content text adaptation.

V1 avoids:

- Changing block names or structural layout.
- Adding or removing top-level blocks.
- Rewriting arbitrary HTML.
- Mutating dynamic block query parameters without a dedicated validator.
- Inventing custom color values when a theme preset is available.

## Structural Fit Comes First

Recommendation ranking remains responsible for finding patterns that fit the current insertion point. Cosmetic adaptation can improve visual continuity, but it must not widen insertion scope or compensate for invalid structure.

Examples:

- A header pattern can be adapted for a header template part if its block tree is valid there.
- A narrow card row can be adapted inside a constrained group when its blocks are allowed.
- A large three-column pricing section should not be recommended for a narrow footer slot just because its colors can be changed.

This rule keeps the current `visiblePatternNames` and allowed-pattern selector contracts intact.

## Preview Contract

The preview must satisfy these rules:

- It renders the exact original and adapted block trees involved in insertion, but `Insert adapted` re-clones the adapted tree immediately before dispatch so Gutenberg receives fresh block instances and the preview state is never mutated by insertion.
- It is keyed to the current insertion target signature and server `resolvedContextSignature`.
- It invalidates when the insertion root, insertion index, server-resolved context, theme tokens, or source pattern changes.
- It clearly distinguishes adapted output from original pattern output with labeled compare sections and deterministic change-summary rows.
- It fails closed when adapted blocks cannot be safely built or rendered.

Two freshness inputs are not covered by today's signatures and need explicit handling:

- The insertion target signature (`buildPatternInsertionTargetSignature`) hashes post type, template type, inserter root, insertion index, and insertion context — it has no theme-token dimension, so theme-token invalidation is net-new work. It must be a bounded hash: folding broad context into a freshness key has already caused a false-positive stale rejection on every Insert click on this surface, so the adaptation stale key should add only what adaptation actually reads.
- The server `resolvedContextSignature` guards the docs-grounding fingerprint and pattern-catalog identity; it does not see client adaptation inputs such as sibling hierarchy or theme tokens. Adaptation staleness is a separate client-side dimension layered on top of the server signature, not a replacement for it.

The preview inherits the shelf's existing safety filters: only patterns that already pass the unsafe-binding-source filter (`getUnsafePatternBindingSourceNames`) and the allowed-block-type check reach a preview action. `BlockPreview` renders blocks the same way the editor does, so a pattern carrying a server-only binding source would hit the same renderer that crashes on it in the editor; adaptation must never add or alter bindings, and the preview must not bypass that filter. `BlockPreview` is not currently used anywhere in the codebase, so its behavior with adapted trees needs its own validation — and because [WordPress 7.0](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-api-versions/) renders the post editor in an iframe unconditionally (regardless of block `apiVersion`), that validation must confirm adapted trees and theme tokens resolve correctly inside the iframe, not just in a flat render.

If the preview becomes stale, the UI should refresh or ask the user to rerun the recommendation rather than inserting an old adapted block tree.

## Synced Pattern Handling

Synced patterns need an explicit product decision because the current insert path uses a `core/block` reference. Per-instance cosmetic adaptation conflicts with that by default.

Supported modes should be explicit:

- Insert synced reference unchanged.
- Insert an adapted detached copy, clearly labeled as no longer synced.
- Use supported Pattern Overrides or bindings where WordPress exposes a safe per-instance customization path.

V1 keeps synced references unchanged. A future version should detach only when the user explicitly chooses an adapted detached copy or the platform exposes a reliable per-instance override contract.

Because `resolvePatternBlocks()` returns a single `core/block` reference for a synced pattern, there is no cloned tree to cosmetically adapt without detaching. In v1 the `Preview adapted` and `Insert adapted` actions are therefore hidden or disabled for synced references, so the surface never offers an adapted action that silently equals the original — only `Insert original` (the unchanged reference) is shown.

[WordPress 7.0](https://make.wordpress.org/core/2026/03/16/pattern-overrides-in-wp-7-0-support-for-custom-blocks/) widened the per-instance override path the third mode depends on: Pattern Overrides now apply to any block attribute that opts into Block Bindings through the `block_bindings_supported_attributes` filter — custom blocks included — instead of the previous hardcoded core set (Paragraph, Heading, Image, Button). This is a per-instance *content* override contract, defined by what the pattern author marks overridable, not a cosmetic one, so it still does not let cosmetic adaptation mutate a synced `core/block` reference in place. It does make the third mode materially more viable for content edits, and it reopens the "prefer unchanged synced references" default: an opted-in synced pattern can now take per-instance content without detaching. Treat this as the same decision as the content-text adaptation item in Open Decisions, and resolve it against the broadened 7.0 contract rather than defaulting to detach-or-leave-unchanged.

## Data Model

The extension can introduce an `adaptationPlan` object on the client or in recommendation payloads. A minimal shape:

```json
{
  "version": "pattern-adaptation-v1",
  "sourcePatternName": "theme/example",
  "targetSignature": "hash",
  "changes": [
    {
      "path": [0, "innerBlocks", 1],
      "blockName": "core/heading",
      "attribute": "level",
      "from": 2,
      "to": 3,
      "reason": "nearby_heading_hierarchy"
    }
  ]
}
```

The inserted blocks should not depend on this plan at runtime. The plan is for preview explanation, telemetry, stale checks, and tests. The adapted block array is the source of truth for insertion.

## Validation

Adaptation requires both pre-insert and post-insert validation:

- Confirm every mutated block still has a registered block type.
- Confirm every changed attribute is registered or allowed by the block's supports path.
- Confirm theme preset slugs exist before assigning them.
- Confirm top-level block types are still allowed at the insertion root.
- Confirm adaptation did not introduce empty or invalid block trees.
- Confirm the live insertion target and server-resolved context still match.
- Confirm post-dispatch block presence at the requested target, reusing the existing wrong-target rollback behavior.

Most of these checks already exist on the direct-insert path, and the adapted path should route through them rather than re-implement them: the allowed-at-the-insertion-root checks (top-level and per-block registered type) are `getRejectedPatternBlockNames`, backed by `canInsertBlockType`, and the post-dispatch presence check plus wrong-target rollback are `didInsertBlocksAtTarget` and `removeBlocks()`. The adapted insert must run the same apply-time `rejectIfBlocksDisallowed` re-check the direct path uses in `PatternRecommender.js`, not a parallel one, so both paths see identical live-editor state immediately before dispatch. Only the adaptation-specific checks are net-new: registered/allowed-by-supports attribute writes, theme-preset existence, and confirming adaptation did not produce an empty or invalid tree.

Any validation failure should block adapted insertion and leave the original recommendation available when it remains safe.

## Telemetry And Diagnostics

Recommendation outcome diagnostics distinguish adapted interactions from current direct insertion:

- `adapted_preview_shown`
- `adapted_inserted_from_preview`
- `adaptation_blocked`
- `adapted_insert_failed`

These event names are in the frozen `OUTCOME_EVENTS` allowlist and the `OUTCOME_LABELS` map in `src/store/recommendation-outcomes.js`, and in the PHP-side activity allowlist in `inc/Activity/RecommendationOutcome.php`. The outcome builder hard-gates on `OUTCOME_EVENT_SET`, so future adapted events still need explicit registration before `recordRecommendationOutcome` can log them. They extend the existing surface vocabulary (`shown`, `pattern_inserted_from_shelf`, `validation_blocked`, `stale_blocked`, `insert_failed`) and, like those, surface in `Settings > AI Activity` as diagnostic-visibility rows.

Useful reason codes:

- `unsupported_synced_reference`
- `missing_theme_tokens`
- `unsupported_block_attribute`
- `unsupported_block_support`
- `adapted_blocks_not_insertable`
- `adapted_preview_stale`
- `resolved_context_changed`

Diagnostics should avoid storing full block content unless the existing Activity privacy model explicitly allows it.

## Implementation Seams

Shipped source seams:

- `src/patterns/PatternRecommender.js` for preview actions, stale checks, and insertion dispatch.
- `src/patterns/pattern-insertability.js` for shared block resolution and insertability checks.
- `src/patterns/pattern-adaptation-context.js` for the client-only adaptation signal.
- `src/patterns/pattern-adaptation.js` for plan creation, block-tree mutation, and validation.
- `src/patterns/PatternAdaptationPreview.js` for the compare UI around `BlockPreview`.
- `src/store/recommendation-outcomes.js` for adapted preview and insertion events.
- `inc/Activity/RecommendationOutcome.php` for server-side activity event normalization.
- `inc/Abilities/PatternAbilities.php` remains unchanged unless future adaptation metadata needs server participation in the recommendation payload.

V1 keeps adaptation client-side because the shipped rule set only needs live editor context, theme tokens, block supports, and registered block styles. Server signatures already guard the apply context before insertion.

## Testing Strategy

Unit tests cover:

- plan creation from insertion context and theme tokens,
- recursive block-tree adaptation,
- refusal to mutate unsupported attributes,
- synced pattern mode behavior,
- stale preview invalidation,
- insert handler using the exact previewed adapted blocks.

Integration or component tests cover:

- preview opens from the inserter shelf,
- `BlockPreview` receives both original and adapted blocks with stable compare labels,
- `Insert adapted` dispatches adapted blocks,
- `Insert original` dispatches untouched blocks,
- wrong-target rollback still works with adapted blocks.

E2E coverage now proves:

- the user can compare the original and adapted recommendation before insertion,
- the inserted result matches the previewed adaptation,
- wrong-target rollback still removes adapted clones when Gutenberg inserts outside the requested target.

The E2E suite extends the existing pattern-insert failure harness — `consumeE2EPatternInsertFailureMode` and the `window.flavorAgentData.e2ePatternInsertFailureHarness` hook (with `window.__flavorAgentPatternInsertFailures`) in `PatternRecommender.js` — for the adapted path instead of introducing a second harness. Unit coverage carries the stale-preview and disallowed-block branches; broader forced exception/no-op browser coverage can be added if those modes regress independently.

## Open Decisions

- Whether adapted insertion should ever become the default action instead of staying behind `Preview adapted`.
- Whether content text adaptation belongs in a future version or should remain cosmetic-only.
- Whether synced patterns can be detached in a future version, and what confirmation copy is required.
- Whether adaptation plans should be generated by local heuristics only or assisted by the recommendation model.
## Recommended First Slice

The shipped v1 is the smallest trustworthy version:

1. Add local cosmetic adaptation for a short allow-list: heading level, alignment, color preset, spacing preset, and button style.
2. Add a preview panel using `BlockPreview`.
3. Insert only the previewed adapted blocks after freshness revalidation.
4. Keep `Insert original` available.
5. Treat synced patterns as unchanged references in v1.

This slice proves the user value without changing the ranking backend, pattern index, or existing pattern recommendation contract.

## Related

`docs/reference/block-operation-pipeline-extension-notes.md` reaches the same "adapt a pattern instead of inserting it verbatim" idea from the block inspector's structural-operation surface. Its "Make Patterns Smarter And Parameterized" section is the content-adaptation counterpart to this doc's cosmetic adaptation, and the `parameters` capability it describes needs the same sub-block addressing primitive defined here (`changes[].path`) — which is drift-free in this surface only because the path targets a detached clone that is not yet in the editor.

The deterministic, validated, sub-block-addressed mutation engine — the executor maps the change, the model never authors blocks — should be built once and shared between the two surfaces rather than implemented twice (this doc's proposed `src/patterns/pattern-adaptation.js` and that doc's extension of `parseBlocksForOperation` want the same module). The open decision above on content-text adaptation is the same decision as that doc's `parameters` capability; resolve it for both surfaces together.
