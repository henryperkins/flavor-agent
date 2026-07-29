# Bug: Anthropic rejects `recommend-block` with "compiled grammar is too large"

- **Date found:** 2026-07-28 (live activity row on `hperkins.blog`, record `13dea3b4…`, Group block on page #36)
- **Severity:** High — the recorded run shows 100 failed/unavailable of 334 actions, with `claude-opus-5` at 0% apply against `gpt-5.5` at 45.5%.
- **Status:** Mitigated in PR #74; a live Anthropic connector run is still required to confirm the incident is resolved.
- **Component:** `inc/LLM/WordPressAIClient.php`, `inc/LLM/ResponseSchema.php`, `inc/Abilities/Registration.php`

## Summary

`flavor-agent/recommend-block` fails against the Anthropic connector with:

```text
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

## Evidence limitation: the recorded row cannot prove which schema was sent

`build_request_diagnostics()` (`inc/LLM/WordPressAIClient.php`) includes the schema in
`bodyBytes` under `text.format.schema` whenever the schema is non-null. `wp_json_encode` can only
*add* bytes to `instructionsChars` + `inputChars`, so those two counts are a hard floor.

| Payload variant | Floor (bytes) |
|---|---|
| instructions + input, no schema, no reasoning | 28,401 |
| … + `reasoning.effort` | 28,433 |
| … + the then-measured Anthropic-prepared block schema | **31,888** |
| **Recorded on the failing row** | **30,203** |

30,203 sits below the reconstructed with-schema floor. That initially looked like proof that the
failing request carried no output schema, but `bodyBytes` is not a wire capture:
`build_request_diagnostics()` rebuilds it from the local `$schema` variable. The grammar-limit
fallback sets that variable to `null`, so the recorded row describes the fallback state even if an
earlier builder still held a schema.

The row therefore does **not** establish whether the provider received Flavor Agent's response
schema. A live request capture or a builder-level schema inspection is needed to settle that.

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

## Ruled out: ability and MCP tool schemas

The error names *tool schemas*, plural *strict tools*, which makes the registered abilities an
obvious suspect — `flavor-agent/recommend-block` alone has an **8,342-byte input schema**, the
largest of 15 abilities totalling 26,956 bytes, and 11 are MCP-public.

They are not on this request. A repo-wide search for outbound tool wiring
(`tools`, `tool_choice`, `function_declaration`, `using_tools`) returns **zero hits**. The only
outbound path is `ChatClient::chat` → `ResponsesClient::rank` → `WordPressAIClient::chat`, and the
only AI-client filters this plugin registers are `wpai_request_log_context`,
`wpai_system_instruction`, and `wpai_default_feature_classes` — none attach tools. The
Abilities/MCP surface in `inc/MCP/ServerBootstrap.php` is **inbound only**: it serves tools to
external MCP clients and never rides along on Flavor Agent's own chat request.

Within this repository, the response schema is the only known outbound grammar source. The AI
Client runtime or provider connector may add behavior that this repository cannot observe, so the
provider's references to "tool schemas" and "strict tools" cannot identify the source by
themselves.

## Reconciling the contradiction

One plausible explanation is that the failed request did carry the schema while the reconstructed
diagnostics described the schema-free fallback.

`build_request_diagnostics()` rebuilds `bodyBytes` from the local `$schema` variable, which the
retry sets to `null` — it never inspects the builder that is actually sent. Meanwhile
`apply_output_schema()` takes a **shallow** `clone`. If the AI Client's prompt builder keeps its
output schema on a shared sub-object (a `ModelConfig` DTO, say), then mutating the clone also
mutates the handle the retry was meant to fall back to, so attempt 2 re-sends the schema while
reporting a schema-free payload.

That would explain a schema-free-looking 30,203-byte record, a grammar error, and a retry that
changed nothing. It remains a hypothesis rather than proof.

Rebuilding the fallback prompt removes the dependency on that handle. Whether the shared-DTO
hazard is real in the shipped AI Client is **still unverified** — the test double holds its state
in a PHP array, which a shallow clone copies, so it cannot reproduce the hazard either way.

## The mitigations were unreachable on the path that fails

Before PR #74, every Anthropic-specific mitigation from PRs #68/#70 was gated on
`$resolved_provider === 'anthropic'`. That slug never arrives on the core
`Settings > Connectors` path, which is exactly how the incident site is configured:

1. `ChatClient::chat()` calls `ResponsesClient::rank( …, null, … )` — no provider argument.
2. `ResponsesClient::rank()` sets `$pinned_connector = Provider::is_connector( $config['provider'] ) ? … : null`.
3. `Provider::chat_configuration()` → `runtime_chat_configuration()` returns
   `wordpress_ai_client_configuration()`, whose `provider` is the sentinel `'wordpress_ai_client'`.
4. `Provider::is_connector( 'wordpress_ai_client' )` is **false** → `$pinned_connector = null`.
5. `resolve_provider_model_selection( null )` finds no explicit provider and no developer feature
   selection → `provider => ''`.

Verified by execution against the test doubles with an Anthropic connector registered:
`chat_configuration()['provider']` is `'wordpress_ai_client'` and `is_connector()` is `false`.

Before PR #74, the then-current implementation sent the block schema on this path at 4,151 bytes
with no provider-specific mitigation. Only the provider-agnostic runtime retry could catch the
resulting 400.

## Resolution (2026-07-28)

PR #74 carries bounded mitigations for every defect that could be reproduced locally. The 4,096
byte threshold remains a local heuristic, not a provider-published compiled-grammar limit.
[Anthropic's structured-output documentation](https://platform.claude.com/docs/en/build-with-claude/structured-outputs)
documents grammar-complexity limits and recommends simplifying deeply nested or highly optional
schemas, but does not promise that local references reduce the compiled grammar.

| Resolution path | Raw bytes | Before repeat sharing | Sent bytes | Enums | `$defs` |
|---|---:|---:|---:|---:|---:|
| Unresolved Settings > Connectors provider | 4,760 | 4,169 | 3,346 | 6 | 2 |
| Explicit Anthropic provider | 4,760 | 4,145 | 2,789 | 0 | 2 |

1. **Share repeated subschemas without requiring a provider slug.** Repeated object and enum
   branches are hoisted into local `$defs` when the normalized schema crosses the byte heuristic.
   This takes the unresolved-provider block schema to 3,346 serialized bytes while retaining all
   six enum keywords. The rewrite now traverses conditionals, dependent schemas, and legacy schema
   dependencies, and generated definition names cannot overwrite caller-owned `$defs`.
2. **Keep the conservative fallback for a known Anthropic provider.** `$defs` reduces serialized
   bytes, but the provider may inline references while compiling its grammar. If the pre-sharing
   schema crossed the heuristic, a known Anthropic request still removes enums and sends the
   2,789-byte schema. Small Anthropic schemas keep their enum constraints. An unresolved provider
   is not guessed; it keeps the lossless schema and relies on the runtime retry if the provider
   rejects it.
3. **Rebuild the prompt for the grammar-limit retry.** The retry constructs a fresh prompt builder
   before sending a schema-free request, so it does not depend on shallow-clone internals. A failure
   while rebuilding now follows the normal error path and closes the diagnostic trace instead of
   returning early with an active trace.
4. **Narrow block variations at both trust boundaries.** React-element labels are dropped instead
   of serialized. Malformed variations no longer consume the ten-item cap, and empty or duplicate
   scopes are removed after sanitization.
5. **Label failed AI requests as not undoable.** A request that never applied anything now reads
   "Undo not applicable" rather than implying that an undo action exists.

## What is still unexplained

The test double cannot establish whether the shipped AI Client shares schema state across shallow
clones, and serialized byte reduction does not establish how Anthropic compiles local `$ref`
targets. The retry is now correct under either clone model, but only a live Anthropic connector run
can confirm that the original 400 no longer reaches the user.

For live diagnosis, inspect the builder's own output-schema state immediately before each
`generate_text_result()` call. Do not infer it from `build_request_diagnostics()`, which
reconstructs `bodyBytes` from a local variable.

## Validation evidence

| Gate | Evidence | Result |
|---|---|---|
| Shared client and ability contracts | `WordPressAIClientTest.php`, `ChatClientTest.php`, `BlockAbilitiesTest.php` | 108 tests, 541 assertions passed |
| Editor collector and activity labels | Targeted `block-inspector.test.js` and `activity-log-utils.test.js` | 87 tests passed |
| Contributor documentation | `npm run check:docs` | Passed |
| Full aggregate | `npm run verify`; structured result in `output/verify/summary.json` | `status: pass`; build, JS lint, Plugin Check, 1,694 JS tests, PHP lint, 2,008 PHP tests, and both E2E suites passed |
| Post editor, Block Inspector, patterns, navigation, AI Activity | Playground Playwright harness | 17 tests passed |
| WordPress 7.0 Site Editor, settings, approvals, apply, activity, drift, undo | WP 7.0 Playwright harness | 29 tests passed |
| Live Anthropic connector | Reproduce the original Group-block request on the incident site | Not run; required before claiming the production incident is resolved |

## Known-weak guards found during the investigation (not fixed)

- **The union guard is an accounting artifact.** `compact_schema_for_union_limit()` takes the block
  schema from 18 unions to 6 purely by `$ref`-ing three identical subschemas into one `$defs` entry;
  `count_schema_unions()` then counts it once. A compiler that inlines `$ref` sees 18 either way.
  The same caveat applies to repeat sharing: it reduces serialized bytes and duplicated branches,
  which is what the local heuristic measures, but whether it reduces the *compiled* grammar
  depends on whether the provider shares or inlines `$defs` rules.
- **Enum stripping is all-or-nothing.** It deletes every enum keyword, including two- and
  three-member enums that cost almost nothing.
- **`compact_nullable_schema_unions()` narrows the contract.** It collapses `["X","null"]` to `"X"`
  while the property stays in `required`, forbidding the null that `nullable_confidence` documents
  as "return null to defer to deterministic ranking".
- **The remaining lossy fallbacks are still keyed on the exact slug `anthropic`.** Repeat sharing
  and the schema-free runtime retry are provider-agnostic, but numeric-bound removal, proactive
  enum stripping, and dropping an oversized schema still require that slug. An Anthropic-backed
  connector registered under another id (Bedrock, Vertex) cannot receive those proactive steps
  until the AI Client exposes which provider it actually resolved.
- **Diagnostics are reconstructed, not observed.** `build_request_diagnostics()` builds `bodyBytes`
  from the local `$schema` variable rather than from the prompt actually sent, so on the retry path
  it reports a schema-free payload whether or not one was sent. Rebuilding the prompt removes the
  specific retry hazard, but the reporting remains unable to falsify itself.
- **`allowedPatterns` is uncapped client-side.** All 248 entries are POSTed and stable-serialized
  into the per-keystroke context signature; the server's cap is positional head-truncation with no
  relevance ranking, so the 20 the model sees are just the first 20 in registration order.
  `src/utils/block-recommendation-context.js:50-58` caps `blockInterior` with an explicit comment
  about signature divergence, then hashes `blockOperationContext` uncapped on the next line.
- **`themeTokens` ships a ~10 KB mirror** (flat arrays alongside `*Presets`) with no first-party
  reader on the request path.
