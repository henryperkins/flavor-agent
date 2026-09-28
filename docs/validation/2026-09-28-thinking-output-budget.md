# Thinking output budget and AI Client diagnostics

Date: 2026-09-28. Follow-up to `cea20b9`; no live provider request made.

## Evidence and change

The hperkins.blog request log for page 36 identifies `anthropic:messages`, model
`claude-sonnet-5`, 13,756 input tokens, and exactly 4,096 output tokens. The
response preview contains thinking while Flavor Agent reports zero answer bytes.
Anthropic connector 1.0.4 defaults to `max_tokens: 4096` when the caller omits a
limit. Sonnet 5 enables thinking by default and shares that limit between
thinking and answer text. This evidence strongly supports budget exhaustion;
the supplied live log does not include the original `stop_reason`.

AI Client chat now supplies a 16,384-token ceiling unless the caller explicitly
sets `max_tokens`. Schema retries preserve that limit. Result metadata supplies
the actual provider/model, and the first candidate's finish reason is retained
in Activity diagnostics. A `length` finish produces `incomplete_response` with a
token-limit explanation, including when partial text exists. Empty results with
other finish reasons retain `empty_response`. Thinking is not exposed as answer
text or copied into diagnostics. Incomplete responses do not trigger retries.

See [provider precedence and output budget](../reference/provider-precedence.md)
for the source contracts and timeout behavior.

## Verification

Regression tests failed before the changes for the omitted budget, lost
provider/model metadata, generic empty-response error, accepted partial JSON,
and missing finish-reason display. Targeted verification includes:

| Check | Result |
| --- | --- |
| AI Client, chat, response client, policy, and embedding-backend PHPUnit suites | 150 passed, 631 assertions |
| Final aggregate `node scripts/verify.js --strict --skip-e2e --json` | 7 steps passed: build, JS lint, Plugin Check, JS unit, PHP lint, docs, PHP unit |
| Full PHPUnit suite | 2,467 passed, 11,656 assertions |
| Full JavaScript unit suite | 2,084 passed across 118 suites |
| Activity/admin component JavaScript suites | 106 passed |
| Request/error/state JavaScript suites | 68 passed |
| Real WordPress AI Client plus Anthropic connector 1.0.4 offline fixture | Passed; no network requests |
| Playground browser cases | 4 passed: token-limit diagnostics, unavailable-provider UI, navigation, and patterns |
| WordPress 7.1 Site Editor browser cases | 6 passed including authentication: block, Global Styles, Style Book, template, and template-part flows |

The offline fixture confirms the connector's original 4,096-token default, the
configured 16,384-token request payload through the real core prompt builder,
and `max_tokens` → SDK `length` conversion for a thinking-only response. Flavor
Agent preserves the actual model and 4,096 output-token count with zero answer
bytes. The script and result are in
`output/empty-response-investigation/connector-fixture.php` and
`output/empty-response-investigation/connector-fixture.log`.

The initial aggregate run is retained in
`output/empty-response-investigation/initial-verify/`. Plugin Check was
interrupted with exit 143 during concurrent Docker browser-harness startup. One
PHP test expected the mock builder to omit `customOptions` entirely; it now
asserts that no custom options are sent when the generic max-token configuration
creates an empty options array. The final aggregate ran after browser startup
had finished and passed all seven included steps. Its summary and per-step logs
are in `output/verify/summary.json` and `output/verify/`. PHP used the isolated UTC
INI from the preceding schema verification; host configuration was not changed.
The build retained three existing webpack performance warnings.

The browser runs use mock recommendation responses. The prior Style Book toast
interception did not reproduce in this run; no Style Book implementation was
changed. Browser logs are in `output/empty-response-investigation/playground.log`
and `output/empty-response-investigation/wp70.log`.

## Live limits

The new ceiling removes reliance on the connector's 4,096-token default. It
does not guarantee completion for every prompt: model reasoning, caller limits,
and the existing 90-second timeout still apply. Deployment and a fresh request
on hperkins.blog are required to confirm this workload succeeds. No production
settings or content were changed during verification.
