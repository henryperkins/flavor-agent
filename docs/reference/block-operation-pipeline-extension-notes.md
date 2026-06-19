# Extending The Block Operation Recommendation Pipeline

The best extension path is to keep the current safety model, but widen the operation catalog and scopes in deliberate tiers.

Right now the block surface is centered on one selected block. It collects selected-block context plus siblings, parent, structural ancestors, branch, theme tokens, and allowed patterns in `src/context/collector.js`. The only executable structural operations are selected-block `insert_pattern` and `replace_block_with_pattern`, defined in `src/utils/block-operation-catalog.js` and enforced server-side by `inc/Context/BlockOperationValidator.php`. Apply then goes through the transactional structural executor in `src/utils/block-structural-actions.js`.

Three properties of that pipeline are load-bearing for everything below, so name them before extending:

- **Addressing is by clientId.** Block operations target `targetClientId` plus a `targetSignature`, not a tree path. ClientIds are stable across a session; tree paths are not.
- **Every operation is a pattern operation.** The shared validator requires `patternName` and an allowed-pattern lookup for *all* operation types, so the catalog cannot currently express a patternless op.
- **Validation runs twice; apply is single-operation.** The sequence is validated at recommendation time and re-validated at apply time (`prepareBlockStructuralOperation`), and both the catalog and the executor reject sequences longer than one operation.

To better help users build pages, extend it like this:

## Add Explicit User Steering Fields

The prompt should not be the only steering mechanism. Add structured request fields such as:

```json
{
  "scope": "selected_block|parent_section|nearby_section|page",
  "preferredOperation": "insert|replace|remove|reorder|style",
  "preferredPatternName": "theme/hero",
  "placement": "before|after|inside_start|inside_end",
  "preserveContent": true,
  "operationBudget": 1
}
```

That lets the UI or an external client say "replace this block with a hero," "insert an FAQ after this section," or "update this whole section," without relying on prompt interpretation alone.

Two of these fields need care. `preferredOperation: "style"` is not a structural operation at all — it is attribute mutation, with a different apply/undo path closer to `src/store/update-helpers.js` — so it should not share the structural steering field or executor. And `operationBudget` should be advisory from the client but enforced in code server-side; the cap that protects the editor lives in `validate_operations` today (template-part hard-caps at three), not in a client-supplied number.

## Expand Operations In Tiers

Keep the current selected-block operations as tier 1:

```text
insert_pattern before/after selected block
replace selected block with pattern
```

Then add deterministic tier 2 operations:

```text
remove selected block
duplicate selected block
move selected block before/after sibling
insert pattern inside parent at start/end
replace parent section with pattern
wrap selected block or sibling range in group
unwrap selected group
```

Most of tier 2 is patternless — `remove`, `duplicate`, `move`, `wrap`, and `unwrap` carry no pattern. That collides with the "every operation is a pattern operation" property above: the shared validator would reject them at `missing_pattern_name`. So tier 2 is not just new operation types; it requires generalizing the catalog so `patternName` is conditional on the operation type. The template-part surface already demonstrates the target shape — its `remove_block` validates on `targetPath` + `expectedBlockName` with no pattern at all — so borrow that shape rather than inventing a new one.

For page building, tier 3 would be small ordered plans:

```json
{
  "operations": [
    { "type": "replace_block_with_pattern", "targetPath": [0], "expectedBlockName": "core/cover", "patternName": "theme/hero" },
    { "type": "insert_pattern", "placement": "after_block_path", "targetPath": [2], "patternName": "theme/features" }
  ]
}
```

The template-part surface already points in this direction: it allows up to 3 operations, path-addressed targets, `remove_block`, and overlap checks in `inc/LLM/TemplatePartPrompt.php`. That is the model to borrow for broader page edits — but borrowing it surfaces three problems the block surface has never had to solve, because it has only ever run one clientId-addressed operation at a time:

- **Addressing model shift.** Tier 3 moves the block surface from clientId-addressing to path-addressing. That is a migration, not a syntax change: paths are not stable the way clientIds are.
- **Path drift inside a plan.** Op 1 changes the tree op 2 addresses. If `theme/hero` replaces one block with a multi-block pattern, every later top-level path shifts, so even the `[2]` anchor above is only safe when op 1 is count-preserving. This is also why template-part's overlap check *rejects* two operations that touch the same path — a naive `replace [0]` then `insert after [0]` cannot be reasoned about statically. Either the plan targets anchors that provably do not move, or the executor re-resolves each later target against the tree produced by the prior op.
- **An executable-target allowlist is required.** Path-addressed operations are only safe because the server publishes the valid paths. Template-part emits `operationTargets` and `insertionAnchors` carrying live block fingerprints (`expectedBlockName`, attributes, childCount) and validates every `targetPath` against them. The allowlist is the safety mechanism, not the path syntax — free-form paths are not acceptable.

Tier 3 also needs genuinely transactional multi-operation apply. Today's executor is single-op — it rejects sequences longer than one and rolls back only that one op — so all-or-nothing rollback across an ordered plan on partial failure is net-new work, the hard part of tier 3 rather than a property inherited from the current pipeline.

## Add Section Or Page Scope

The block inspector should probably stay focused. For larger changes, introduce a separate "Build section" or "Page plan" mode that uses root/page structure, path-based targets, and a plan review UI.

That avoids turning one selected paragraph recommendation into an unexpected page rewrite.

## Make Patterns Smarter And Parameterized

Today the structural operation mostly chooses a registered pattern. Better results would come from ranking and adapting patterns:

```text
insert pricing pattern after this intro
replace selected media/text section but keep the existing image
insert CTA pattern and set heading/button copy from prompt
```

That suggests extending `insert_pattern` / `replace_block_with_pattern` with safe parameters, for example:

```json
{
  "type": "replace_block_with_pattern",
  "patternName": "theme/cta",
  "targetClientId": "selected-id",
  "preserve": ["headingText", "image", "links"],
  "parameters": {
    "heading": "Book a consultation",
    "buttonText": "Get started"
  }
}
```

The executor, not the model, should map those parameters into parsed blocks.

Treat this as its own capability class, sequenced after the tiers above rather than folded into them. Today's executor parses a pattern and inserts it verbatim — `parseBlocksForOperation` parses, clones, and inserts, with no attribute-injection step. `parameters` needs a way to address a sub-block inside an arbitrary pattern ("the heading") and write to it; `preserve` is harder still, because "keep the existing image" means diffing the outgoing block tree against the incoming pattern and transplanting matched content. Both are new validation surfaces — which attributes are safe to set, how a sub-block is identified — so they should not ride on `insert_pattern` / `replace_block_with_pattern` as optional fields.

## Add Review Diffs For Structural Plans

For page building, users need to see:

```text
Replace selected paragraph with CTA pattern
Insert Features section after it
Keep existing heading text
No locked/content-only blocks affected
```

The review UI should show before/after structural diff, affected targets, inserted pattern names, and whether undo is available.

## Keep The Hard Guardrails

Do not let the model emit raw block markup as the apply source of truth. The pipeline should continue to require:

```text
server approval
client mirror validation
freshness/signature checks
allowed pattern checks
locked/content-only checks
transactional apply
rollback
activity entry
undo signature
```

Two of these need a caveat as scope widens. `transactional apply` and `rollback` exist today only for a single operation; for tier 3 they must be rebuilt to cover an ordered plan atomically. And the surface's strongest guarantee is the *apply-time* re-validation — `prepareBlockStructuralOperation` re-runs the full sequence validation against the live tree, not just the recommendation-time snapshot. Template-part validates at response time only, so when borrowing its operation model, keep the block surface's apply-time gate rather than copying template-part's single-pass approach.

That is what makes extension viable without making the editor fragile.

## Concrete Recommendation

First add a section-scoped operation plan surface that borrows the template-part operation model, allows up to 3 ordered operations, and uses path plus expected-target validation. Three prerequisites travel with that surface: a server-published executable-target allowlist (`operationTargets` / `insertionAnchors`-style), the patternless operation shape from template-part's `remove_block`, and genuinely atomic multi-operation apply with all-or-nothing rollback. Keep the current block inspector's "one structural operation," clientId-addressed rule for selected-block cards until that richer plan surface and its review UI exist. Defer parameterized patterns (`preserve` / `parameters`) to a later capability class — it is the largest new validation surface and the least constrained by what exists today.

## Related

`docs/features/pattern-recommendations-adapted-preview.md` reaches the same "adapt a pattern instead of inserting it verbatim" idea from the inserter shelf. Its cosmetic adaptation is the counterpart to this doc's "Make Patterns Smarter And Parameterized" section, and it already specifies the sub-block addressing primitive that `parameters` needs here: `changes[].path` into a detached clone, drift-free precisely because the tree is not yet in the editor.

As of 2026-06-19, the inserter-shelf v1 implements its cosmetic clone mutation in `src/patterns/pattern-adaptation.js`. That module is intentionally scoped to detached non-synced pattern clones and does not solve content parameterization, outgoing-block diffing, or `preserve` transplants. Future block-operation work should either reuse/extract the generic path-addressed validation pieces from that module or explicitly document why a separate executor is required.

The validated, sub-block-addressed mutation engine — "the executor, not the model" — should be shared between the two surfaces rather than built twice. One boundary differs: the inserter-shelf surface inserts a fresh clone, so it has no outgoing block and no analog to `preserve`. Content transplant on replace (`preserve`) stays unique to this surface.
