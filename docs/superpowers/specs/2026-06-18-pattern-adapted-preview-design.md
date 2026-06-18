# Pattern Adapted Preview - Design

- **Date:** 2026-06-18
- **Status:** Proposed; awaiting implementation plan.
- **Owner:** Henry Perkins
- **Sources:** `docs/reference/current-open-work.md`, `docs/features/pattern-recommendations-adapted-preview.md`, `docs/features/pattern-recommendations.md`, `docs/reference/block-operation-pipeline-extension-notes.md`

## Thesis

Pattern adapted preview v1 adds an explicit preview-first path to the existing pattern
inserter shelf. For non-synced, already-insertable pattern recommendations, Flavor Agent
builds a detached cloned block tree, applies only deterministic cosmetic mutations, renders
that exact cloned tree in Gutenberg `BlockPreview`, and inserts those same block instances
only after the existing insertion-target and server `resolvedContextSignature` checks still
pass. The existing direct `Insert` path remains available and unchanged, synced patterns stay
unchanged `core/block` references, and this does not change ranking, retrieval, pattern
indexing, ability schemas, external apply/undo, or server-side pattern payloads.

## Problem

The current pattern surface helps users find structurally relevant patterns in the native
Gutenberg inserter, but insertion is verbatim: `PatternRecommender` resolves the matched
allowed pattern, revalidates freshness, clones the blocks, calls `insertBlocks()`, and verifies
that Gutenberg inserted the clone at the requested target. That keeps the surface safe, but it
does not let a recommended pattern visually align with the local page context before insertion.

The forward-looking adapted-preview feature document already defines the right product shape:
recommend structural fit first, clone the block tree, apply bounded cosmetic adaptation to the
clone, preview the exact adapted clone, and insert that same clone only after confirmation. This
spec locks the first implementation slice so code work does not drift into content generation,
synced-pattern detachment, block-operation expansion, or a new apply/undo contract.

## Scope

### In Scope

- Add a `Preview adapted` action for supported non-synced pattern recommendations in the
  existing Flavor Agent inserter shelf.
- Add a compact adapted-preview panel rendered inside the existing inserter portal, using
  Gutenberg `BlockPreview` from `@wordpress/block-editor`.
- Build one detached cloned block tree per preview target and insert that same tree from the
  preview when the user confirms.
- Apply only deterministic, attribute-level cosmetic changes:
  - heading level adjustment for `core/heading`;
  - supported `align` values for blocks whose registered supports allow alignment;
  - theme color preset slugs for supported text/background/link/button color attributes;
  - theme spacing preset values for supported spacing paths;
  - registered style variation names for `core/button`.
- Reuse the direct insert freshness and safety gates: insertion-target signature, server
  `resolveSignatureOnly`, allowed-block checks, `insertBlocks()`, post-dispatch verification,
  and wrong-target rollback.
- Record adapted-preview and adapted-insert diagnostic outcomes in the same
  `recommendation_outcome` pipeline as existing pattern events.
- Keep original insertion available as `Insert original`.

### Out Of Scope

- No content text adaptation.
- No synced-pattern detachment or per-instance Pattern Overrides work.
- No model-generated adaptation plan.
- No generated block HTML.
- No changes to `recommend-patterns` ability input/output schemas.
- No changes to pattern retrieval backends, pattern indexing, ranking, or reranker prompts.
- No Flavor Agent-owned apply/undo/activity contract for pattern insertion.
- No broader block-operation or section/page plan surface.
- No original/adapted side-by-side diff viewer in v1.

## Current Baseline

- `src/patterns/PatternRecommender.js` owns the inserter portal, shelf rendering, direct insert
  click handler, freshness checks, `insertBlocks()` dispatch, post-dispatch verification, and
  wrong-target rollback.
- `src/patterns/pattern-insertability.js` resolves insertable pattern blocks from synced
  references, parsed block arrays, or serialized content. It also rejects unsafe bindings and
  disallowed block types.
- `src/context/theme-tokens.js` already normalizes theme tokens from block-editor settings for
  client-side code. Adapted preview should reuse this instead of creating a second token parser.
- `src/store/recommendation-outcomes.js` and `inc/Activity/RecommendationOutcome.php` both
  hard-gate supported outcome events. New adapted events must be added to both sides.
- The existing E2E pattern-insert failure harness lives in `PatternRecommender.js` and
  `tests/e2e/flavor-agent.smoke.spec.js`; adapted insertion should extend that harness rather
  than introduce a second failure mechanism.

## Runtime Design

### Shelf Actions

For each insertable recommendation:

- non-synced patterns show `Preview adapted` and `Insert original`;
- synced patterns show only the unchanged original insert action;
- patterns that fail the existing unsafe-binding or allowed-block filters do not reach the
  shelf and continue to record `validation_blocked` as they do today.

`Preview adapted` is secondary to direct insertion in v1. The user explicitly asks to inspect
the adapted clone before any adapted insertion can happen.

### Adaptation Module

Create `src/patterns/pattern-adaptation.js` as the deterministic mutation boundary. It owns:

- resolving whether a pattern supports adaptation;
- cloning source blocks exactly once for a preview target;
- building an `adaptationPlan`;
- applying supported mutations to the cloned block tree;
- returning blocked reasons when adaptation cannot be built safely;
- producing a bounded `adaptationSignature` from only the inputs adaptation reads.

The module does not know about React, notices, `insertBlocks()`, or activity persistence. Its
public shape should be small:

```js
buildPatternAdaptationPreview( {
	pattern,
	sourceBlocks,
	insertionContext,
	insertionTargetSignature,
	resolvedContextSignature,
	themeTokens,
	blockEditorSettings,
	blockRegistry,
} )
```

The return shape:

```js
{
	status: 'ready',
	blocks,
	plan: {
		version: 'pattern-adaptation-v1',
		sourcePatternName: 'theme/example',
		targetSignature: 'hash',
		changes: [
			{
				path: [ 0, 'innerBlocks', 1 ],
				blockName: 'core/heading',
				attribute: 'level',
				from: 2,
				to: 3,
				reason: 'nearby_heading_hierarchy',
			},
		],
	},
	adaptationSignature: 'hash',
}
```

Blocked shape:

```js
{
	status: 'blocked',
	reason: 'unsupported_synced_reference',
	blocks: [],
	plan: null,
	adaptationSignature: '',
}
```

The adapted `blocks` array is the insertion source of truth. The plan is explanation,
diagnostics, stale checking, and test evidence only.

### Safe Mutation Rules

The first slice should keep the mutation engine deliberately conservative:

- Do not change block names.
- Do not add or remove blocks.
- Do not mutate `metadata.bindings`.
- Do not mutate dynamic block query parameters.
- Do not write arbitrary custom colors, raw spacing values, or generated HTML.
- Write only registered attributes or style paths that the registered block type supports.
- Use theme preset slugs or preset values only when those presets exist in the current
  normalized theme token manifest.
- If no safe cosmetic change is available, return `adaptation_blocked` with
  `unsupported_block_support` or `missing_theme_tokens` and leave `Insert original` available.

Heading adaptation is limited to `core/heading` `level`. The value should follow nearby heading
hierarchy when the insertion context provides enough signal, otherwise it should leave the
heading unchanged rather than guessing.

Alignment adaptation is limited to supported `align` values already accepted by the block type.
It should prefer matching the insertion root or nearby sibling alignment, not invent a wider
layout.

Color and spacing adaptation must use theme presets from `collectThemeTokensFromSettings()`.
The implementation should translate between WordPress preset conventions only where the
target block already uses those conventions, for example `backgroundColor` / `textColor` slugs
or `var:preset|spacing|slug` style values. It must not set raw values because this slice is
about theme alignment, not freeform design changes.

Button style adaptation is limited to `core/button` registered style variations returned by the
block registry. If the desired style is not registered, no mutation is applied.

### Preview UI

Create `src/patterns/PatternAdaptationPreview.js`. It renders:

- the pattern title;
- a short adapted/blocked status;
- a compact list of applied change reasons;
- `BlockPreview` for the exact adapted `blocks`;
- `Insert adapted`;
- `Insert original`;
- `Close`.

The preview panel lives in the same inserter portal as the shelf. It should not create a
separate modal or route. It should use existing editor styles and add only scoped CSS under
the `flavor-agent-pattern-*` namespace.

The preview is keyed by:

- source pattern name;
- current insertion-target signature;
- stored server `resolvedContextSignature`;
- adaptation signature.

If any of those inputs change, the preview becomes stale. A stale adapted preview blocks
`Insert adapted`, records `stale_blocked` with `adapted_preview_stale`, and refreshes pattern
recommendations for the current target using the same direct insert refresh path.

### Insert Adapted

`PatternRecommender` should split the current direct insert handler into a shared insertion
helper so both original and adapted insertions use one safety path:

1. Confirm the source blocks are non-empty.
2. Confirm the insertion-target signature still matches the stored recommendation target.
3. Re-run `resolvePatternRecommendationSignature()` with `resolveSignatureOnly`.
4. Confirm the current server `resolvedContextSignature` still matches.
5. Re-run allowed-block validation against the live editor immediately before dispatch.
6. Dispatch `insertBlocks( previewedBlocks, index, rootClientId, true )`.
7. Verify the inserted blocks appear at the requested target.
8. Roll back wrong-target inserts with `removeBlocks( insertedClientIds, false )`.
9. Record success or failure outcome.

The direct path continues to clone immediately before dispatch and records
`pattern_inserted_from_shelf`. The adapted path uses the already-previewed `blocks` array and
records `adapted_inserted_from_preview`. Both paths share failure behavior, but adapted
failures record `adapted_insert_failed` when the failure happens after adapted insertion starts.

## Synced Pattern Handling

Synced/user patterns remain unchanged references in v1. `resolvePatternBlocks()` currently
returns a single `core/block` reference for synced patterns, so cosmetic adaptation would
require detaching the pattern into a normal block tree. That product decision is intentionally
out of scope.

The UI therefore never offers `Preview adapted` for synced references. It should also never
offer an adapted action that silently equals the original. Users can still insert the original
synced reference through the existing path.

## Diagnostics

Add these outcome events on both the JS and PHP allowlists:

- `adapted_preview_shown` - the preview panel rendered a ready adapted clone.
- `adapted_inserted_from_preview` - the previewed adapted clone inserted and verified.
- `adaptation_blocked` - adaptation could not produce a safe preview.
- `adapted_insert_failed` - adapted insertion started but failed, no-oped, or landed at the
  wrong target.

Keep the existing events unchanged:

- `shown`
- `selected_for_review`
- `stale_blocked`
- `validation_blocked`
- `insert_failed`
- `pattern_inserted_from_shelf`

New reason codes:

- `unsupported_synced_reference`
- `missing_theme_tokens`
- `unsupported_block_attribute`
- `unsupported_block_support`
- `adapted_blocks_not_insertable`
- `adapted_preview_stale`
- `resolved_context_changed`
- `insert_blocks_exception`
- `insert_blocks_noop`
- `insert_blocks_wrong_target`

Diagnostics must not store full block content, provider payloads, or broad editor context.
Store plan metadata, change reasons, source pattern name, target signature, and bounded counts.

## Files And Responsibilities

- `src/patterns/PatternRecommender.js`
  - add adapted preview state;
  - pass `Preview adapted` and `Insert original` actions to the shelf;
  - create/adapt preview payloads lazily;
  - split direct insertion into a shared guarded insert helper;
  - record adapted outcomes;
  - extend the existing E2E failure-mode hook to adapted insertion.

- `src/patterns/PatternAdaptationPreview.js`
  - render `BlockPreview`;
  - show applied change summaries and blocked/stale states;
  - expose `Insert adapted`, `Insert original`, and `Close` controls.

- `src/patterns/pattern-adaptation.js`
  - implement support detection, block cloning, sub-block path writes, plan construction,
    preset validation, and adaptation signatures.

- `src/patterns/pattern-insertability.js`
  - add helpers that validate already-resolved block arrays so adapted blocks do not need to
    be wrapped back into fake pattern objects.

- `src/context/theme-tokens.js`
  - reuse `collectThemeTokensFromSettings()` for preset validation. Add a small exported helper
    only if the adaptation module needs a narrower preset-slug projection.

- `src/store/recommendation-outcomes.js`
  - add the adapted events and labels to the client allowlist.

- `inc/Activity/RecommendationOutcome.php`
  - add the same adapted events and labels to server normalization.

- `src/editor.css`
  - add scoped styles for the preview panel and BlockPreview container.

- `src/patterns/__tests__/pattern-adaptation.test.js`
  - cover plan creation, supported mutations, blocked reasons, synced refusal, and stale
    signature inputs.

- `src/patterns/__tests__/PatternRecommender.test.js`
  - cover shelf actions, preview rendering, exact previewed block insertion, original insert
    preservation, stale preview blocking, and adapted failure outcomes.

- `src/patterns/__tests__/pattern-insertability.test.js`
  - cover resolved block-array insertability checks.

- `src/store/__tests__/recommendation-outcomes.test.js`
  - cover new event allowlist and labels.

- `tests/phpunit/RecommendationOutcomeTest.php`
  - cover server acceptance and normalization of adapted outcome events.

- `tests/e2e/flavor-agent.smoke.spec.js`
  - extend pattern insertion smoke/failure coverage to adapted insertion using the existing
    failure harness.

## Testing Strategy

Run the work test-first by layer:

1. `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation.test.js`
2. `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-insertability.test.js`
3. `npm run test:unit -- --runInBand src/patterns/__tests__/PatternRecommender.test.js`
4. `npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js`
5. `composer run test:php -- --filter RecommendationOutcomeTest`
6. `npm run build`
7. `npm run lint:js`
8. `composer run lint:php`
9. `npm run check:docs`
10. `git diff --check`

Browser proof should extend the existing pattern inserter smoke in the Playground suite if the
BlockPreview path is stable there. If `BlockPreview` or iframe rendering requires the
Docker-backed WordPress 7.0 harness for reliable proof, run the focused WP70 spec and record
the reason in the implementation closeout.

## Documentation Updates After Implementation

- `docs/features/pattern-recommendations.md`: update the current surface contract to include
  adapted preview, unchanged original insertion, and unchanged synced-pattern behavior.
- `docs/features/pattern-recommendations-adapted-preview.md`: mark the v1 slice as shipped
  and leave deferred content adaptation / synced detachment decisions explicit.
- `docs/reference/current-open-work.md`: move "Pattern adapted preview" out of Current
  Implementation Candidates after tests and docs pass.
- `docs/reference/block-operation-pipeline-extension-notes.md`: if the shared mutation engine
  is generic enough for future block-operation parameterization, add a short pointer to the
  new module. If it is pattern-only in v1, leave this doc unchanged.

## Rollout

No migration and no feature flag are required. The adapted action is additive and only appears
for patterns that already pass the current renderable and insertable shelf filters. If
adaptation blocks or becomes stale, original insertion remains available. If `BlockPreview`
cannot render safely in a supported editor context, the preview path fails closed and records a
diagnostic outcome rather than falling back to approximate markup.
