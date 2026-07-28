# Bug: Anthropic rejects `recommend-block` with "compiled grammar is too large"

- **Date found:** 2026-07-28 (live activity row on `hperkins.blog`, record `13dea3b4…`, Group block on page #36)
- **Severity:** High — the recorded run shows 100 failed/unavailable of 334 actions, with `claude-opus-5` at 0% apply against `gpt-5.5` at 45.5%.
- **Status:** Partially fixed 2026-07-28 (see **Resolution**). The binding constraint is **not** identified; see **What is still unexplained**.
- **Component:** `inc/LLM/WordPressAIClient.php`, `inc/LLM/ResponseSchema.php`, `inc/Abilities/Registration.php`

## Summary

`flavor-agent/recommend-block` fails against the Anthropic connector with:

```
Bad Request (400) - The compiled grammar is too large, which would cause
performance issues. Simplify your tool schemas or reduce the number of
strict tools.
```

Duration 1485 ms, 0 tokens — the request never reached the model.

The failure is **not** caused by the size of the request context. It is a constrained-decoding
grammar limit, and the grammar is compiled from *schemas*, not from message content.

## Reproduce

1. Configure an Anthropic connector in `Settings > Connectors` (observed on `claude-opus-5`).
2. Select a `core/group` block in the post editor on a site with a pattern-rich theme.
3. Open the **AI Recommendations** Inspector panel and request block recommendations.
4. The request fails at the provider with the 400 above; the activity row records
   `flavor-agent/recommend-block`, 0 tokens, and a 30,203-byte payload.

## What the incident report got wrong

The original analysis attributed the failure to request-context size — 248 `allowedPatterns`
entries, doubly-encoded `themeTokens`, a recursive `structuralBranch`. Measured against the code,
none of that can reach the compiled grammar:

- `ResponseSchema::get()` (`inc/LLM/ResponseSchema.php:25`) takes only a **surface string**. No
  runtime context is an input, so context cannot enter the response schema.
- `block_operation_schema()` (`:349-364`) declares `patternName` as a plain `{"type":"string"}`
  with no `enum`. Pattern names never become grammar alternatives.
- `allowedPatterns` is already capped at 20 **twice** server-side —
  `BlockAbilities::BLOCK_OPERATION_CONTEXT_MAX_PATTERNS` and
  `Prompt::BLOCK_OPERATION_PROMPT_MAX_PATTERNS`. At most 20 ever reach the prompt.
- `themeTokens` is duplicated on the wire, but `Prompt::format_theme_token_family()`
  (`inc/LLM/Prompt.php:703-732`) renders each family exactly **once**, preferring the preset array.

Context size is a real cost in tokens and latency. It is not this bug.

## Evidence: the failing request carried no output schema

`build_request_diagnostics()` (`inc/LLM/WordPressAIClient.php`) includes the schema in
`bodyBytes` under `text.format.schema` whenever the schema is non-null. `wp_json_encode` can only
*add* bytes to `instructionsChars` + `inputChars`, so those two counts are a hard floor.

| Payload variant | Floor (bytes) |
|---|---|
| instructions + input, no schema, no reasoning | 28,401 |
| … + `reasoning.effort` | 28,433 |
| … + the 3,427-byte Anthropic-prepared block schema | **31,888** |
| **Recorded on the failing row** | **30,203** |

30,203 sits *below* the with-schema floor. The schema was therefore absent from the request that
produced this diagnostic — yet Anthropic still returned a grammar error naming *tool schemas* and
*the number of strict tools*.

**The grammar did not come from Flavor Agent's response schema.**

## Measured: block is the only surface near the limit

Every static response schema, encoded, against the two constants this client enforces
(`SCHEMA_UNION_LIMIT = 16`, `ANTHROPIC_SCHEMA_BYTE_LIMIT = 4096`):

| Surface | Bytes | Unions | Over? |
|---|---|---|---|
| **block** | **4,760** | **18** | **both** |
| style | 1,817 | 16 | at the union limit |
| template | 1,776 | 13 | no |
| template_part | 1,767 | 11 | no |
| navigation | 1,373 | 8 | no |
| content | 546 | 0 | no |
| pattern | 336 | 0 | no |

All 18 block unions come from `nullable_ranking_schema` being inlined three times, via
`block_display_metadata_schema()` appearing in the `settings`, `styles`, and `block` item shapes.

## Leading hypothesis for the actual cause

The error names *tool schemas*, plural *strict tools*. Flavor Agent never attaches tools to its
own outbound chat request — there is no tool wiring in `inc/`. But it registers 15 abilities whose
**input** schemas are large, and exposes them as MCP tools via
`inc/MCP/ServerBootstrap.php:34-51`:

| Ability | Input schema bytes |
|---|---|
| `flavor-agent/recommend-block` | **8,342** |
| `flavor-agent/recommend-template-part` | 3,218 |
| `flavor-agent/recommend-template` | 3,195 |
| `flavor-agent/recommend-style` | 2,884 |
| `flavor-agent/recommend-patterns` | 2,127 |
| … 10 more | … |
| **Total across 15 tools** | **26,956** |

`recommend-block`'s input schema is itself dominated by `selectedBlock` (6,959 of 8,342 bytes),
which repeats near-identical node shapes across `structuralBranch`, `parentContext`,
`siblingSummariesBefore` (973 B), `siblingSummariesAfter` (972 B), and `structuralAncestors`.

If the AI plugin or MCP adapter attaches registered abilities as strict tools to chat requests,
this is the grammar the error is describing. **This is unverified** — the bridge lives in the WP AI
plugin, not in this repository.

## Resolution (2026-07-28)

Three fixes landed. None of them is proven to clear the 400, because the binding constraint is
still unidentified; each fixes a defect confirmed by measurement.

1. **`Keep Anthropic block enums by sharing repeated subschemas`** — the block schema previously
   fit only because `prepare_anthropic_output_schema()` stripped **every** enum, silently removing
   the `panel` constraint that routes a suggestion into the correct Inspector sub-panel. Anthropic
   sessions got structurally valid but mis-routed suggestions with no error, while OpenAI kept its
   enums. Repeated subschemas are now hoisted into `$defs`, bringing the schema to 3,330 bytes with
   all six enums intact. Enum stripping remains the next fallback; dropping the schema the last.
2. **`Stop React elements leaking into the block recommendation context`** — core permits a block
   variation's `title`/`description` to be a React element, and `core/group` ships one. The element
   reached the provider with `_owner`, `ref`, and a `props` subtree carrying an unrelated
   `postId: 339584`. Now coerced at the collector and narrowed at the ability boundary.
3. **`Label failed AI requests as not undoable`** — a request that never applied anything read
   "Undo unavailable" next to the provider error, which is what suggested undo re-issues AI calls.
   It does not: `activity/{id}/undo` is a pure DB transition plus a client-side snapshot replay,
   and `inc/Apply/`, `inc/Activity/`, `inc/REST/` contain no provider references at all.

## What is still unexplained

The retry at `WordPressAIClient.php:217-252` drops the schema and re-issues once. The byte
arithmetic above indicates the recorded failure is that **second, schema-free** request — meaning
the grammar survived removal of our schema entirely. Until the tool-attachment path is identified,
the 400 should be expected to recur.

## Known-weak guards found during the investigation (not fixed)

- **The union guard is an accounting artifact.** `compact_schema_for_union_limit()` takes the block
  schema from 18 unions to 6 purely by `$ref`-ing three identical subschemas into one `$defs` entry;
  `count_schema_unions()` then counts it once. A compiler that inlines `$ref` sees 18 either way.
  The same caveat applies to fix 1 above: it reduces serialized bytes and duplicated branches, which
  is what the local heuristic measures, but whether it reduces the *compiled* grammar depends on
  whether the provider shares or inlines `$def` rules.
- **Enum stripping is all-or-nothing.** It fired 55 bytes over budget and deleted all 8 enum
  keywords, including 2- and 3-member enums that cost almost nothing.
- **`compact_nullable_schema_unions()` narrows the contract.** It collapses `["X","null"]` to `"X"`
  while the property stays in `required`, forbidding the null that `nullable_confidence` documents
  as "return null to defer to deterministic ranking".
- **Anthropic fallbacks are keyed on the exact slug `anthropic`.** An Anthropic-backed connector
  registered under another id (Bedrock, Vertex) gets none of these protections.
- **`allowedPatterns` is uncapped client-side.** All 248 entries are POSTed and stable-serialized
  into the per-keystroke context signature; the server's cap is positional head-truncation with no
  relevance ranking, so the 20 the model sees are just the first 20 in registration order.
  `src/utils/block-recommendation-context.js:50-58` caps `blockInterior` with an explicit comment
  about signature divergence, then hashes `blockOperationContext` uncapped on the next line.
- **`themeTokens` ships a ~10 KB mirror** (flat arrays alongside `*Presets`) with no first-party
  reader on the request path.
