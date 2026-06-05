# Adapted Pattern Recommendation Preview

This is a forward-looking feature outline for extending the existing Pattern Recommendations surface. It builds on `docs/features/pattern-recommendations.md`, where Flavor Agent currently ranks patterns, displays them in the native Gutenberg inserter, and inserts the selected pattern as Gutenberg exposes it.

## Goal

Let users insert patterns that both fit the current insertion point and visually align with the surrounding page. The feature should preserve the existing structural recommendation contract, add a bounded cosmetic adaptation step, and show a visual preview of the exact adapted block tree before insertion.

The core principle is:

1. Recommend a structurally appropriate pattern first.
2. Clone the pattern's blocks.
3. Apply safe cosmetic adaptation to the clone.
4. Preview the adapted clone.
5. Insert the same adapted clone only after user confirmation.

## Non-Goals

- Do not use cosmetic adaptation to rescue structurally wrong recommendations.
- Do not rewrite Gutenberg's pattern registry or native category metadata.
- Do not silently replace a synced pattern reference with a detached copy.
- Do not generate arbitrary block HTML when a structured block mutation is possible.
- Do not add a full Flavor Agent review/apply/undo contract in the first version.

## User Experience

The existing inserter shelf remains the entry point. Each recommended pattern can expose an adaptation-aware action set:

- `Preview` opens a visual preview of the adapted result.
- `Insert adapted` inserts the adapted block clone.
- `Insert original` remains available when useful for trust and comparison.

The preview should make the adaptation explicit without making the surface feel like a separate workflow. A compact note can list the kinds of changes applied, such as "Adjusted spacing, heading level, button style, and color presets."

The preview should render the exact block array that would be inserted. It must not be an approximation, generated screenshot, or server-rendered substitute unless that substitute is explicitly labeled as a fallback.

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
10. `insertBlocks()` receives the same adapted block array shown in preview.
11. Existing post-dispatch verification records success, no-op, rejected insertion, or wrong-target rollback.

## Adaptation Scope

Adaptation should start with cosmetic changes that are low-risk and easy to validate:

- Heading levels that follow nearby heading hierarchy.
- Alignment values when supported by the block and suitable for the insertion root.
- Theme color preset slugs for text, background, links, and buttons.
- Typography preset slugs or size values only when supported and theme-compatible.
- Spacing preset slugs for padding, margin, and block gaps.
- Button style variations that are registered for `core/button`.
- Image aspect ratio or size slug when the image block supports the attribute.
- Placeholder text only when the feature explicitly includes content adaptation.

The first version should avoid:

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

- It renders the exact adapted block instances that `insertBlocks()` will receive, not a re-cloned copy. The adapted clone is built once, memoized on the source pattern, adaptation plan, and target signature, rendered in `BlockPreview`, and inserted as that same array — so the previewed clientIds flow into the existing post-dispatch verification and `BlockPreview` does not remount on every render. The current direct-insert path re-clones with `cloneBlock()` immediately before dispatch; the adapted path must instead hold its single clone in state.
- It is keyed to the current insertion target signature and server `resolvedContextSignature`.
- It invalidates when the insertion root, insertion index, server-resolved context, theme tokens, or source pattern changes.
- It clearly distinguishes adapted output from original pattern output.
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

The first implementation should prefer unchanged synced references unless the user explicitly chooses an adapted detached copy or the platform exposes a reliable per-instance override contract.

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

Recommendation outcome diagnostics should distinguish adapted interactions from current direct insertion:

- `adapted_preview_shown`
- `adapted_inserted_from_preview`
- `adaptation_blocked`
- `adapted_insert_failed`

These event names must be added to the frozen `OUTCOME_EVENTS` allowlist and the `OUTCOME_LABELS` map in `src/store/recommendation-outcomes.js`. The outcome builder hard-gates on `OUTCOME_EVENT_SET`, so an unregistered event is silently dropped by `recordRecommendationOutcome` rather than logged. They extend the existing surface vocabulary (`shown`, `pattern_inserted_from_shelf`, `validation_blocked`, `stale_blocked`, `insert_failed`) and, like those, surface in `Settings > AI Activity` as diagnostic-visibility rows.

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

Likely source seams:

- `src/patterns/PatternRecommender.js` for preview actions, stale checks, and insertion dispatch.
- `src/patterns/pattern-insertability.js` for shared block resolution and insertability checks.
- New `src/patterns/pattern-adaptation.js` for plan creation, block-tree mutation, and validation.
- New `src/patterns/PatternAdaptationPreview.js` for the preview UI around `BlockPreview`.
- `src/store/recommendation-outcomes.js` for adapted preview and insertion events.
- `inc/Abilities/PatternAbilities.php` only if adaptation metadata needs server participation in the recommendation payload.

The first version should keep adaptation client-side unless server-owned context is required. Server signatures already guard the apply context before insertion.

## Testing Strategy

Unit tests should cover:

- plan creation from insertion context and theme tokens,
- recursive block-tree adaptation,
- refusal to mutate unsupported attributes,
- synced pattern mode behavior,
- stale preview invalidation,
- insert handler using the exact previewed adapted blocks.

Integration or component tests should cover:

- preview opens from the inserter shelf,
- `BlockPreview` receives adapted blocks,
- `Insert adapted` dispatches adapted blocks,
- `Insert original` dispatches untouched blocks,
- wrong-target rollback still works with adapted blocks.

E2E coverage should prove:

- the user can preview an adapted recommendation before insertion,
- the inserted result matches the previewed adaptation,
- stale insertion target changes block adapted insertion and refresh recommendations.

The E2E suite should extend the existing pattern-insert failure harness — `consumeE2EPatternInsertFailureMode` and the `window.flavorAgentData.e2ePatternInsertFailureHarness` hook (with `window.__flavorAgentPatternInsertFailures`) in `PatternRecommender.js` — to cover the adapted path across its forced exception, no-op, and wrong-target modes, rather than introducing a second harness.

## Open Decisions

- Whether adapted insertion should be the default action or a secondary action behind `Preview`.
- Whether content text adaptation belongs in the first version or should remain cosmetic-only.
- Whether synced patterns can be detached in v1, and what confirmation copy is required.
- Whether adaptation plans should be generated by local heuristics only or assisted by the recommendation model.
- Whether original/adapted comparison is necessary in v1 or can wait until users ask for it.

## Recommended First Slice

Build the smallest trustworthy version:

1. Add local cosmetic adaptation for a short allow-list: heading level, alignment, color preset, spacing preset, and button style.
2. Add a preview panel using `BlockPreview`.
3. Insert only the previewed adapted blocks after freshness revalidation.
4. Keep `Insert original` available.
5. Treat synced patterns as unchanged references in v1.

This slice proves the user value without changing the ranking backend, pattern index, or existing pattern recommendation contract.

## Related

`docs/reference/block-operation-pipeline-extension-notes.md` reaches the same "adapt a pattern instead of inserting it verbatim" idea from the block inspector's structural-operation surface. Its "Make Patterns Smarter And Parameterized" section is the content-adaptation counterpart to this doc's cosmetic adaptation, and the `parameters` capability it describes needs the same sub-block addressing primitive defined here (`changes[].path`) — which is drift-free in this surface only because the path targets a detached clone that is not yet in the editor.

The deterministic, validated, sub-block-addressed mutation engine — the executor maps the change, the model never authors blocks — should be built once and shared between the two surfaces rather than implemented twice (this doc's proposed `src/patterns/pattern-adaptation.js` and that doc's extension of `parseBlocksForOperation` want the same module). The open decision above on content-text adaptation is the same decision as that doc's `parameters` capability; resolve it for both surfaces together.
