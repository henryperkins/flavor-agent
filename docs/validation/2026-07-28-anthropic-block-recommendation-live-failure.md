# Anthropic block-recommendation live failure — 2026-07-28

Status: **P0 — block recommendations are broken against Anthropic.**

Recorded from a live WordPress install, not a harness. This supersedes the
assumption that PR #70 (`Avoid oversized Anthropic response grammars`, merged
2026-07-27) closed the Anthropic grammar problem. It did not.

## Environment

| | |
| --- | --- |
| Site | `https://hperkins.blog` (WordPress.com **Atomic**) — the maintainer's own public demo site |
| Flavor Agent | `0.1.0`, active |
| WordPress AI plugin | `1.2.0`, active |
| Abilities | 106 total registered; **35** `flavor-agent/*` — matches the documented count |
| Provider | Anthropic via `Settings > Connectors` (`ai-provider-for-anthropic`) |
| Model | `claude-opus-5` |
| Auth | an administrator account via a since-revoked application password |

`flavor-agent/check-status` reported `configured: true` with every surface
`available: true, reason: "ready"` before the run.

## Result

`POST /wp-abilities/v1/abilities/flavor-agent/recommend-block/run` →
**HTTP 400**:

```text
prompt_client_error
Bad Request (400) - The compiled grammar is too large, which would cause
performance issues. Simplify your tool schemas or reduce the number of
strict tools.
```

Captured `requestMeta.requestSummary`:

```json
{
  "bodyBytes": 21661,
  "instructionsChars": 11631,
  "inputChars": 9101,
  "reasoningEffort": "medium",
  "resolvedProvider": "anthropic",
  "resolvedModel": "claude-opus-5",
  "modelSelectionSource": "ai_plugin_feature_developer",
  "modelResolutionStatus": "model",
  "outputSchemaFallback": "grammar_limit"
}
```

Three things this establishes:

1. **The hardened Anthropic path ran.** `resolvedProvider` is `anthropic` and
   `modelSelectionSource` is `ai_plugin_feature_developer`, so the per-feature
   model selection is set and `prepare_output_schema()` took the Anthropic
   branch. This is *not* the empty-provider gap described in
   `WordPressAIClient::normalize_output_schema_for_provider()`.
2. **The hardened schema is still too large.** The block schema compacts from
   4,760 → **3,427 bytes** for Anthropic (enum + numeric bounds stripped,
   `$defs`/`$ref` dedup). That clears the plugin's own
   `ANTHROPIC_SCHEMA_BYTE_LIMIT` of 4,096 and still blows the grammar compiler.
   **The 4,096 threshold is set too high.**
3. **The grammar-limit retry fires but does not rescue the request.**
   `outputSchemaFallback: "grammar_limit"` proves
   `is_output_schema_grammar_limit_error()` matched and the retry path at
   `WordPressAIClient.php:245` executed. The final response is still the same
   grammar error.

## Controlled differential — it is response-schema size, not tool schemas

The error text blames "tool schemas … strict tools", which on a site with 106
registered abilities suggests the Abilities surface is the culprit. It is not.
Same site, same provider, same model, same session:

| Ability | Response schema | Result |
| --- | ---: | --- |
| `flavor-agent/recommend-content` | 546 B | **HTTP 200 — success** |
| `flavor-agent/recommend-block` | 3,427 B | **HTTP 400 — grammar too large** |

The differential alone does **not** settle this. A shared tool set could sit
inside one combined grammar budget, fitting alongside the small schema and
overflowing only when the large one is added — in which case both the tools and
the schema contribute and the comparison cannot separate them.

What settles it is the provider source. `ai-provider-for-anthropic` v1.0.3 —
the exact version deployed here — only ever populates `tools` when function
declarations or web search are configured, and Flavor Agent configures neither:

```php
$functionDeclarations = $config->getFunctionDeclarations();
$webSearch            = $config->getWebSearch();
if (is_array($functionDeclarations) || $webSearch) {
    $params['tools'] = $this->prepareToolsParam($functionDeclarations, $webSearch);
}
```

So no tools are transmitted on either request, registered abilities are not part
of the grammar, and the schema is the only varying input. Anthropic's docs
confirm grammar compilation applies to structured outputs generally, so the
"tool schemas / strict tools" wording is generic compiler error text rather than
a pointer at the Abilities surface. Full source review:
[`docs/reference/ai-provider-for-anthropic-review.md`](../reference/ai-provider-for-anthropic-review.md).

Per-surface prepared sizes (Anthropic path) — block is the only outlier, which
is consistent with block being the only surface reported broken:

```text
block 3427   style 1769   template 1728   template_part 1719
post_blocks 1719   navigation 1325   content 546   pattern 312
```

The failing/working boundary is bounded only as **(546 B, 3,427 B]**. Narrowing
it further needs live calls on a mid-size surface; `recommend-style` (1,769 B)
requires `scope` + `styleContext` input that was not worth constructing for a
nice-to-have bound.

## Open: why the retry does not rescue

Unresolved, and it matters because it changes the fix. `apply_output_schema()`
does clone before mutating (`WordPressAIClient.php:973`), so the obvious
object-aliasing explanation is ruled out — `$prompt_without_output_schema` is
not trivially contaminated.

The remaining hypothesis is that `clone_prompt_for_optional_feature()` uses PHP's
**shallow** `clone` (line 1398). If the AI Client prompt builder holds generation
config in a nested object, the clone shares it, `as_json_response()` mutates the
shared instance, and the "retry without schema" resends the original grammar.
This is consistent with every observation but is **not confirmed** — confirming
it requires reading the deployed `php-ai-client` prompt builder.

Note the diagnostics cannot settle this on their own:
`build_request_diagnostics()` reconstructs `requestSummary` from parameters, not
from the prompt object actually sent. `bodyBytes: 21661` therefore reports what
the retry *intended* to send, not what it sent.

## Test-coverage hole

`tests/phpunit/WordPressAIClientTest.php` passes (45 tests / 241 assertions) and
its 7 Anthropic-filtered cases all pass, including
`test_chat_keeps_large_anthropic_output_shape_without_grammar_heavy_value_constraints`,
which asserts the block schema ends `<= 4096` bytes. That assertion is satisfied
at 3,427 bytes — and the request still fails in production. **The suite asserts
the plugin's own threshold, not the provider's actual limit.**

The harness's blind spot is narrower than "clones are indistinguishable" — that
framing was wrong. `test_chat_retries_without_output_schema_after_unpinned_grammar_limit_error()`
captures the schema per attempt through an `http_request_args` filter and
asserts a schema on attempt one and `null` on the retry, so the harness does
separate the two attempts, and `WP_AI_Client_Prompt_Builder` copies its private
array state per clone.

The real gap is that the stub's state is a **flat array**, while the live
dependency holds nested configuration objects. A shallow `clone` copies a flat
array by value but shares nested objects by reference, so the aliasing that can
occur against the real `php-ai-client` builder cannot occur against the stub —
the retry path is exercised, just never under the conditions that break it.
That is why the suite stayed green while production failed.

## Measured boundary

Four surfaces were run live against the same site, provider, and model. Only the
response-schema size varies:

| Surface | Prepared schema | Request body | Result |
| --- | ---: | ---: | --- |
| `recommend-content` | 546 B | — | **200** |
| `recommend-navigation` | 1,325 B | 8,395 B | **200** |
| `recommend-template` | 1,728 B | 21,445 B | **200** |
| `recommend-style` | 1,769 B | — | **200** |
| `recommend-block` | 3,427 B | 21,661 B | **400** |

Template and block sent near-identical **body** sizes (21,445 vs 21,661) with
opposite outcomes, which rules out total request size and isolates the schema.
The failing boundary is therefore **(1,769 B, 3,427 B]**.

## Fix applied

- `ANTHROPIC_SCHEMA_BYTE_LIMIT` lowered `4096` → **`2048`**, inside the verified
  band. Every surface at or below 1,769 B keeps structured output; block exceeds
  the ceiling, has nothing left to strip after enums, and so
  `prepare_anthropic_output_schema()` returns `null` — block sends no schema and
  the request succeeds instead of 400-ing.
- The grammar-limit retry now **rebuilds** the prompt via
  `build_prompt_without_output_schema()` rather than reusing a copy captured
  before `apply_output_schema()`. The copy was unsafe because
  `clone_prompt_for_optional_feature()` is a shallow `clone`, so it can share
  mutated nested state with the schema-bearing prompt. Rebuilding also
  regenerates the system instruction with `hasSchema => false` (inert on a stock
  install, since `WordPressAIPolicy::system_instruction()` only passes through
  the `wpai_system_instruction` filter, but correct for anyone filtering it).

- `requestSummary.outputSchemaFallback` now records *why* structured output was
  degraded, not just that a retry ran. The live capture above shows
  `"grammar_limit"` because the deployed build sent the oversized schema and
  retried; after the fix block drops its schema before the request is built and
  reports `"schema_byte_limit"` instead. Full value set:

  | Value | Meaning |
  | --- | --- |
  | `schema_byte_limit` | Preparation dropped the schema before the request was built — it exceeds the Anthropic grammar byte ceiling with nothing left to strip. **Expected for `recommend-block` on Anthropic after this fix.** |
  | `schema_union_limit` | Preparation dropped the schema because it still exceeds the union limit after compaction. |
  | `grammar_limit` | A schema was sent, the provider rejected it as an oversized grammar, and the schema-free retry was built and sent. |
  | `grammar_limit_rebuild_failed` | Same, but the schema-free retry could not be constructed; the recorded request is the schema-bearing one and the recorded error is the rebuild failure. |

  Absence of the key means structured output was requested and sent, or never
  requested. Before this change a deliberately degraded request was
  indistinguishable from a surface that never asked for a schema.

All regression tests were confirmed to **fail when the fix is reverted**. Note
the obvious assertion does not work: checking `json_schema` after the retry
passes either way, because the stub's `sync_state()` pushes the reused object's
own schema-free state into the recorded prompt. The discriminating signal is the
system instruction, which is generated with `hasSchema`.

## Still open

- [ ] **Not yet re-verified live.** The fix is committed but hperkins.blog runs
      the deployed `0.1.0` build; confirming it needs the branch deployed there.
      On re-run, `recommend-block` should return HTTP 200 with
      `outputSchemaFallback: "schema_byte_limit"` — that marker is the signal the
      fixed build is the one answering.
- [ ] The exact provider ceiling is bounded, not pinned. If block's schema is
      ever shrunk below 2,048 it could regain structured output, but that needs a
      fresh live measurement — the suite can only check our own arithmetic.

## Reproduction

```bash
curl -sS -u '<user>:<application-password>' \
  -H 'Content-Type: application/json' \
  -X POST 'https://hperkins.blog/wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-block/run' \
  --data-binary '{"input":{"clientId":"00000000-0000-4000-8000-000000000001",
    "selectedBlock":{"blockName":"core/paragraph",
      "attributes":{"content":"Governed AI for WordPress."},
      "editingMode":"default","isInsideContentOnly":false},
    "prompt":"Improve contrast and spacing for accessibility."}}'
```

Add `"resolveSignatureOnly": true` for a free dry run that exercises context
collection, docs grounding, and signature resolution without calling the
provider; that path returns HTTP 200 on this site.
