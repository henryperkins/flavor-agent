# Extending The Block Operation Recommendation Pipeline

The best extension path is to keep the current safety model, but widen the operation catalog and scopes in deliberate tiers.

Right now the block surface is centered on one selected block. It collects selected-block context plus siblings, parent, structural ancestors, branch, theme tokens, and allowed patterns in `src/context/collector.js`. The only executable structural operations are selected-block `insert_pattern` and `replace_block_with_pattern`, defined in `src/utils/block-operation-catalog.js` and enforced server-side by `inc/Context/BlockOperationValidator.php`. Apply then goes through the transactional structural executor in `src/utils/block-structural-actions.js`.

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

For page building, tier 3 would be small ordered plans:

```json
{
  "operations": [
    { "type": "replace_block_with_pattern", "targetPath": [0], "patternName": "theme/hero" },
    { "type": "insert_pattern", "placement": "after_block_path", "targetPath": [0], "patternName": "theme/features" }
  ]
}
```

The template-part surface already points in this direction: it allows up to 3 operations, path-addressed targets, `remove_block`, and overlap checks in `inc/LLM/TemplatePartPrompt.php`. That is the model to borrow for broader page edits.

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

That is what makes extension viable without making the editor fragile.

## Concrete Recommendation

First add a section-scoped operation plan surface that borrows the template-part operation model, allows up to 3 ordered operations, and uses path plus expected-target validation. Keep the current block inspector's "one structural operation" rule for selected-block cards until the richer review UI exists.
