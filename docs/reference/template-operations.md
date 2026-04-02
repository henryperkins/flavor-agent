# Template Operation Vocabulary Reference

This document is the contract reference for the operation types that Flavor Agent's LLM prompts produce and the server validates.

Use it when you need to answer:

- which operation types are valid for each surface
- what fields each operation type requires
- what placements are valid and when `targetPath` applies
- how the server validates and enriches operations before they reach the client

## Template Operations

Defined in `inc/LLM/TemplatePrompt.php`. These are the only valid operation types for template recommendations.

### Operation Types

| Type | Purpose | Required fields |
|---|---|---|
| `assign_template_part` | Assign a template part to a slot | `slug`, `area` |
| `replace_template_part` | Replace one template part with another | `currentSlug`, `slug`, `area` |
| `insert_pattern` | Insert a registered pattern into the template | `patternName`, `placement` |

### Placements

All `insert_pattern` operations require a `placement` value:

| Placement | Meaning | Requires `targetPath` |
|---|---|---|
| `start` | Insert at the beginning of the template | No |
| `end` | Insert at the end of the template | No |
| `before_block_path` | Insert before the block at the given path | Yes |
| `after_block_path` | Insert after the block at the given path | Yes |

### Anchor Validation

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
| `set_theme_variation` | Both | Apply a theme style variation | `variationIndex`, `variationTitle` |

### Constraints

- `set_styles` is rejected on the `style-book` surface
- `set_block_styles.blockName` must exactly match the target block in the request scope
- `path` values must match the supported style paths enumerated in the prompt
- Preset-backed paths must use preset values (slug + CSS variable), not raw values
- At most one `set_theme_variation` per suggestion, and it must appear before any `set_styles` or `set_block_styles` overrides
- `variationIndex` must reference an available variation by index; `variationTitle` must match

### Enrichment

Validated `set_styles` and `set_block_styles` operations are enriched with:

- `valueType` — `preset` or `literal`
- `presetType`, `presetSlug`, `cssVar` — when the value resolves to a theme preset

## Primary Source Files

- `inc/LLM/TemplatePrompt.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/LLM/StylePrompt.php`
- `src/utils/template-operation-sequence.js` (client-side validation)
- `src/utils/template-actions.js` (client-side execution)
