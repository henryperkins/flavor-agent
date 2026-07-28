# Anthropic block-recommendation live failure — 2026-07-28

Status: **P0 — block recommendations are broken against Anthropic.**

Recorded from a live WordPress install, not a harness. This supersedes the
assumption that PR #70 (`Avoid oversized Anthropic response grammars`, merged
2026-07-27) closed the Anthropic grammar problem. It did not.

## Environment

| | |
| --- | --- |
| Site | `https://hperkins.blog` (WordPress.com **Atomic**, blog_id `253647414`) |
| Flavor Agent | `0.1.0`, active |
| WordPress AI plugin | `1.2.0`, active |
| Abilities | 106 total registered; **35** `flavor-agent/*` — matches the documented count |
| Provider | Anthropic via `Settings > Connectors` (`ai-provider-for-anthropic`) |
| Model | `claude-opus-5` |
| Auth | administrator (`hperkinsh`) via application password |

`flavor-agent/check-status` reported `configured: true` with every surface
`available: true, reason: "ready"` before the run.

## Result

`POST /wp-abilities/v1/abilities/flavor-agent/recommend-block/run` →
**HTTP 400**:

```
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

If registered abilities were being sent as strict tools, both would fail. Only
the large-response-schema surface fails, so the response schema is the cause.

Per-surface prepared sizes (Anthropic path) — block is the only outlier, which
is consistent with block being the only surface reported broken:

```
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

The harness also cannot detect retry contamination: the stub
`as_json_response()` returns `$this` and writes to the global static
`WordPressTestState::$last_ai_client_prompt` (`tests/phpunit/bootstrap.php:808`),
so a clone and its original are indistinguishable in tests.

## Required before release

- [ ] Lower `ANTHROPIC_SCHEMA_BYTE_LIMIT` to a value validated against the live
      API, or stop sending a response schema for the block surface on Anthropic
      and rely on prompt-level shape instructions plus the existing server-side
      validators.
- [ ] Determine why the grammar-limit retry does not rescue the request, and fix
      it. A working retry is the safety net for every future schema growth.
- [ ] Replace the `<= 4096` assertion with one anchored to a provider-verified
      limit, and add a regression test that can observe retry contamination
      (the current stub cannot).
- [ ] Re-run this exact check against the live site after the fix.

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
