# Provider-managed numeric schema compatibility

Date: 2026-09-28. Local patch against `f16bda5`; no production deployment.

## Report and fix

The hperkins.blog activity diagnostic for `flavor-agent/recommend-block` on page
433 reported `output_format.schema: For 'number' type, properties maximum,
minimum are not supported`. A read-only plugin inventory confirmed Flavor Agent
0.1.0, AI 1.3.0, and AI Provider for Anthropic 1.0.4 active. The diagnostic's
provider-managed selection does not identify the connector that handled it.

The existing numeric-constraint normalization runs only for an explicitly resolved
Anthropic provider. Provider-managed requests preserve those constraints. The
patch adds one retry after the provider explicitly rejects numeric schema
constraints, retaining the remaining schema and request settings. Initial
provider-specific guards and server-side score normalization remain in place.
See [provider precedence](../reference/provider-precedence.md).

Regression tests reproduced the reported rejection before the fix. They now
cover all seven bounded recommendation schemas, nested definitions, preserved
enums and nullable types, fresh prompt construction, diagnostics, retry limits,
unrelated errors, and requests with no numeric constraints or output schema.

## Verification

| Check | Result |
| --- | --- |
| Final focused numeric-rejection regression cases | 16 passed |
| Targeted JS request/store suites | 68 passed |
| Aggregate `node scripts/verify.js --strict --skip-e2e --json` | 7 steps passed: build, JS lint, Plugin Check, JS unit, PHP lint, docs, PHP unit |
| Full PHP suite with isolated `date.timezone=UTC` | 2,462 tests, 11,605 assertions passed |
| Full JS suite | 118 suites, 2,083 tests passed |
| Playground targeted browser run | 3 passed: unavailable-provider UI, navigation recommendations, pattern recommendations |
| Docker Site Editor targeted browser run | 5 passed including authentication; 1 failed |

The aggregate summary and per-step logs are in `output/verify/summary.json` and
`output/verify/`. The build emitted three webpack performance warnings. The
initial host-timezone run failed three existing `ActivitySerializerTest` cases:
`test_hydrate_row_decodes_json_and_adds_server_persistence_metadata`,
`test_hydrate_row_marks_recommendation_outcome_as_diagnostic`, and
`test_normalize_attestation_artifact_exposes_reverted_apply_reference`.
Those results are retained in `output/provider-schema-initial-verification/`.
The final run used an isolated UTC INI file, leaving host PHP configuration intact.

## Browser blocker and live limits

The Site Editor run passed Block Inspector apply/persist/undo, Global Styles
preview/apply/undo, template preview/apply, and template-part preview/apply/undo.
`style book applies and undoes paragraph colors with default block support`
timed out at `tests/e2e/flavor-agent.smoke.spec.js:5282`: the success toast
intercepted pointer events on the activity-row Undo button. That test mocks its
recommendation response and did not exercise the provider transport. This is a
recorded browser blocker, not a complete browser release sign-off.

The screenshot, error context, and trace are retained under
`output/playwright-wp70/flavor-agent.smoke--wp70-s-d09b4--with-default-block-support-wp70-site-editor/`.
The local Docker harness used WordPress 7.1.0, Gutenberg 23.9.0, AI 1.3.0, and
Plugin Check 2.1.0 with the documented `mariadb:11.4` fallback. Browser tests used
mock recommendations; no live provider request or production update was made on
hperkins.blog. The fix still needs deployment and a live request check there.
