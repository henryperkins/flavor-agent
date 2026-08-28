# Template Operation Vocabulary Reference

This document is the contract reference for the operation types that Flavor Agent's LLM prompts produce and the server validates.

Use it when you need to answer:

- which operation types are valid for each surface
- what fields each operation type requires
- what placements are valid and when `targetPath` applies
- how the server validates and enriches operations before the client re-validates them through the shared execution layer

## Template Operations

Defined in `inc/LLM/TemplatePrompt.php`. These are the only valid operation types for template recommendations.

The server-side prompt parser is the first validation boundary, and `src/utils/template-operation-sequence.js` is the shared client-side validator that every executable template or template-part surface must pass before apply.

### Operation Types

| Type | Purpose | Required fields |
|---|---|---|
| `assign_template_part` | Assign a template part to a slot | `slug`, `area` |
| `replace_template_part` | Replace one template part with another | `currentSlug`, `slug`, `area` |
| `insert_pattern` | Insert a registered pattern into the template | `patternName`, `placement` |

Each template suggestion may contain at most one `insert_pattern` operation.

### Placements

All `insert_pattern` operations require a `placement` value:

| Placement | Meaning | Requires `targetPath` |
|---|---|---|
| `start` | Insert at the beginning of the template | No |
| `end` | Insert at the end of the template | No |
| `before_block_path` | Insert before the block at the given path | Yes |
| `after_block_path` | Insert after the block at the given path | Yes |

Implicit template insertions are invalid. If `placement` is omitted, the suggestion stays non-executable and the client does not fall back to the editor's current insertion point.

For executable suggestions, `patternSuggestions` is derived from validated `insert_pattern` operations. Advisory-only suggestions may still keep validated pattern names in `patternSuggestions` when the model can name a relevant registered pattern but cannot express a safe deterministic insertion anchor.

### Anchor Validation

Template insertions are only executable when the requested placement is present in the current editor-collected top-level anchor inventory.

- `start` requires a live `start` insertion anchor
- `end` requires a live `end` insertion anchor
- `before_block_path` and `after_block_path` require a live anchored path from `editorStructure.topLevelBlockTree`

When `placement` is `before_block_path` or `after_block_path`, the server:

1. Resolves `targetPath` against the `editorStructure.topLevelBlockTree`
2. Attaches `expectedTarget` metadata to the validated operation:

```json
{
  "expectedTarget": {
    "name": "core/group",
    "label": "Content",
    "attributes": {},
    "childCount": 2,
    "slot": { "slug": "header", "area": "header", "isEmpty": false }
  }
}
```

The client uses `expectedTarget` to verify the anchor still matches before applying.

## Template-Part Operations

Defined in `inc/LLM/TemplatePartPrompt.php`. These operations target the inner block tree of a single template part.

### Operation Types

| Type | Purpose | Required fields |
|---|---|---|
| `insert_pattern` | Insert a registered pattern | `patternName`, `placement` |
| `replace_block_with_pattern` | Replace a specific block with a pattern | `targetPath`, `expectedBlockName`, `patternName` |
| `remove_block` | Remove a specific block | `targetPath`, `expectedBlockName` |

### Placements

Same four placements as template operations: `start`, `end`, `before_block_path`, `after_block_path`.

### Validation Constraints

- Template-part suggestions can apply at most three executable operations automatically
- `insert_pattern` with `placement = start` requires a live `start` insertion anchor
- `insert_pattern` with `placement = end` requires a live `end` insertion anchor
- `replace_block_with_pattern` requires that the target block at `targetPath` matches `expectedBlockName` and that `replace_block_with_pattern` is in the target's `allowedOperations`
- `remove_block` requires the same target match and `remove_block` in `allowedOperations`
- The server builds executable targets with per-block `allowedOperations` lists from the template-part's block tree before validation

## Style Operations

Defined in `inc/LLM/StylePrompt.php`. These operations target Global Styles or Style Book block styles.

### Operation Types

| Type | Surface | Purpose | Required fields |
|---|---|---|---|
| `set_styles` | `global-styles` only | Set a value at a Global Styles path | `path`, `value` |
| `set_block_styles` | `style-book` only | Set a value at a block-scoped style path | `path`, `value`, `blockName` |
| `set_theme_variation` | `global-styles` only | Apply a theme style variation | `variationIndex`, `variationTitle` |

### Constraints

- `set_styles` is rejected on the `style-book` surface
- `set_theme_variation` is rejected on the `style-book` surface
- `set_block_styles.blockName` must exactly match the target block in the request scope
- `path` values must match the supported style paths enumerated in the prompt
- Preset-backed paths must use preset values (slug + CSS variable), not raw values
- At most one `set_theme_variation` per suggestion, and it must appear before any `set_styles` or `set_block_styles` overrides
- Executable `set_theme_variation` operations may be followed by non-readable style overrides such as typography, but must not be combined with readable `color.text` or `color.background` overrides. The server downgrades that mixed shape to advisory; the client apply path rejects it before mutating Global Styles.
- `variationIndex` must reference an available variation by index; `variationTitle` must match

### Enrichment

Validated `set_styles` and `set_block_styles` operations are enriched with:

- `valueType` — `preset` or `freeform`
- `presetType`, `presetSlug`, `cssVar` — when the value resolves to a theme preset

## First-materialization slugs

When a theme-file template or template part is materialized into a `wp_template` / `wp_template_part` post, the slug is normalized through core's own `post_name` normalizer — `sanitize_title( sanitize_key( $slug ) )` in `TemplateApplyExecutor::materialize_template()` and `TemplatePartApplyExecutor::materialize_template_part()` — so the value written is byte-identical to what `wp_insert_post()` will store. `sanitize_key()` alone keeps `--` and edge dashes that `sanitize_title()` collapses and trims; without the outer `sanitize_title()` the post-insert read-back (`$slug !== $inserted->post_name`) would false-fire and return `flavor_agent_apply_recovery_required` (409, via `ExistingPostContentCompensator::recovery_required()`), and `reconcile_existing_row()` would miss the row because it probes `slug__in => [ 'page--wide' ]` while core stored `page-wide`. That read-back and that probe are still live checks; normalization is what keeps them from firing on a renormalizable slug.

Because the normalized slug is then compared against the slug half of the caller's `templateRef`, a canonical ref whose slug is not already `sanitize_title()`-stable fails earlier and differently: the pre-write guard in `materialize_template()` returns `flavor_agent_apply_target_mismatch` (409) before any insert. When debugging a 409 on first materialization, check which code came back — `target_mismatch` means the ref itself is non-canonical, `recovery_required` means the write landed but its stored row could not be confirmed.

## Primary Source Files

- `inc/LLM/TemplatePrompt.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/LLM/StylePrompt.php`
- `src/utils/template-operation-sequence.js` (client-side validation)
- `src/utils/template-actions.js` (client-side execution)
