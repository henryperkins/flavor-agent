# Upstream review — `ai-provider-for-anthropic` v1.0.3

Source review of the provider plugin Flavor Agent routes Anthropic traffic
through, prompted by the 2026-07-28 grammar-limit failure. Reviewed against
`WordPress/ai-provider-for-anthropic` at v1.0.3 — the exact version deployed on
`hperkins.blog`.

**Headline: the provider plugin is not the cause of the grammar ceiling.** It is
worth stating plainly because the error text is misleading. Anthropic returns

> Simplify your tool schemas or reduce the number of strict tools.

which reads as a tool-schema problem, and this site has 106 registered abilities.
It is not that. The plugin sends **no tools** unless function declarations or web
search are configured, and Anthropic's own docs confirm grammar compilation
applies to structured outputs generally ("the first time you use a specific
schema, there is additional latency while the grammar compiles"). The tool
wording is generic compiler error text. The Flavor Agent-side schema-size fix
stands.

The review did surface four real gaps.

## 1. No JSON Schema normalization for structured outputs

`AnthropicTextGenerationModel::prepareGenerateTextParams()` passes the caller's
schema through verbatim:

```php
$params['output_format'] = [
    'type'   => 'json_schema',
    'schema' => $outputSchema,
];
```

Anthropic's structured outputs does **not** support `minimum`, `maximum`,
`multipleOf`, `minLength`, `maxLength`, complex array constraints, recursive
schemas, or `additionalProperties` set to anything but `false`. The plugin
strips none of them, so every consumer has to know Anthropic's constraint list
and normalize before calling.

This is the gap that produced Flavor Agent's own provider-specific branch
(`WordPressAIClient::normalize_output_schema_for_provider()`), and that branch
only runs when Flavor Agent can resolve the provider string to `anthropic`. A
caller that lets the AI Client pick a default provider does not know which
provider it got and therefore cannot normalize — the normalization is only
correctly placeable in the provider plugin, which always knows.

**Suggested fix:** normalize in the provider, where provider identity is known.

## 2. `thinking` is never sent, and `max_tokens` defaults to 4096

The complete parameter set the plugin sends is `system`, `max_tokens`,
`temperature`, `top_p`, `top_k`, `stop_sequences`, `output_format`, `tools`,
plus custom options. There is no `thinking` parameter anywhere in request
construction — the plugin only *parses* `thinking` and `redacted_thinking`
blocks out of responses.

On Claude Opus 5 that matters, because omitting `thinking` does not mean
thinking is off: **Opus 5 runs adaptive thinking by default**, and `max_tokens`
is a hard cap on thinking *plus* response text. Combined with:

```php
// The 'max_tokens' parameter is required in the Anthropic API, so we need a default.
$params['max_tokens'] = 4096;
```

a caller that does not set `max_tokens` gets adaptive thinking sharing a
4,096-token budget with the answer, with truncation risk on longer responses.
This applies to `hperkins.blog` today, which runs `claude-opus-5`.

## 3. No `output_config.effort` support

Effort is GA and is the primary quality/latency/cost dial on Opus 5 and
Sonnet 5. The plugin does not implement it.

The practical consequence for Flavor Agent: `reasoning_effort` is mapped to
provider-specific custom options in
`WordPressAIClient::reasoning_effort_custom_options()`, which returns a mapping
for `codex` and `openai` and `null` for everything else — including Anthropic.
So effort never reaches Anthropic from either side, while Flavor Agent's
`requestMeta.requestSummary` still reports `"reasoningEffort": "medium"`. The
live 2026-07-28 capture shows exactly that. The diagnostic is reporting a knob
that is not connected.

Worth fixing on both sides: the provider should support `output_config.effort`,
and Flavor Agent should stop reporting an effort value it did not send.

## 4. Legacy `output_format` and a pinned superseded beta header

The plugin sends the older top-level `output_format` and sets:

```php
$headers['anthropic-beta'] = 'structured-outputs-2025-11-13';
```

Per Anthropic's current docs, structured outputs are GA on Claude 4.5 and later,
the parameter has moved to `output_config.format`, and the beta header is no
longer required. The old parameter and header "will continue working for a
transition period" — so this is not broken today, but it is on a clock, and when
that transition ends every structured-output request from this plugin fails at
once.

## What was checked and found correct

- **Sampling-parameter gating.** `modelRejectsSamplingParameters()` was traced
  against every model on the live site: `claude-opus-5`, `claude-fable-5`,
  `claude-sonnet-5`, and `claude-opus-4-8` are correctly reported as rejecting
  `temperature`/`top_p`/`top_k`, while `claude-opus-4-6`, `claude-sonnet-4-6`,
  and `claude-haiku-4-5` correctly still allow them. The `(?!\d)` lookahead
  correctly prevents a date suffix (`claude-opus-4-20250514`) being read as a
  minor version.
- **`refusal` handling.** `stop_reason: "refusal"` maps to
  `FinishReasonEnum::contentFilter()` rather than being treated as an unknown
  stop reason.
- **Custom options escape hatch.** Custom options are merged into the request
  and throw on collision with an existing key, so a consumer can inject
  parameters the plugin does not model — including `output_config` and
  `thinking`, since neither is set natively. That is the available workaround for
  gaps 2 and 3 without waiting on upstream.

## Bearing on Flavor Agent

Gap 1 explains why Flavor Agent carries provider-specific schema normalization
at all, and why that normalization has a hole on the default-provider path.
Gaps 2 and 3 are worth an issue upstream; gap 3 additionally needs a Flavor
Agent-side correction so diagnostics stop claiming an effort setting that is
never transmitted.
