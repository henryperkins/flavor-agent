# Live Anthropic Block Inspector validation - 2026-10-07

Validated source: `1cae8e14d6a90bc9f6ff81a7afccb8e7d7689270` (`master`).
Redacted evidence captured at `2026-10-07T09:37:33.7887058Z`.

## Result and scope

One **Get Suggestions** action in the native post editor's Block Inspector
completed a real `flavor-agent/recommend-block` request through the WordPress
Abilities API, WordPress AI Client and Anthropic connector. The generation
request returned HTTP `200`, and the Inspector displayed the returned
recommendations. The temporary HTTP budget counter recorded exactly **one**
Anthropic generation POST before cleanup.

This fulfills issue #84's native Block Inspector live-provider evidence
requirement for this bounded local harness. No schema-free retry was needed
for this request: `outputSchemaFallback` was absent and the budget counter
observed one generation POST. It does not establish production
deployment, real-site recommendation quality, corpus availability, or other
surfaces' live-provider behavior. No recommendations were applied and no
content was published. Product source and recommendation contracts were not
changed.

The preceding deterministic checks are recorded separately in the
[current-master validation baseline](2026-10-07-current-master-gates.md),
including 40 passing tests with Gutenberg `23.9.0` and 40 passing tests with
the bundled editor. Those fixture results and this live request remain
distinct evidence.

## Runtime and interaction

The isolated Docker Desktop WordPress harness used host port `19404`, with a
direct bind mount of the clean primary checkout at the validated master SHA.
Its runtime was WordPress `7.1` with the bundled editor, Gutenberg inactive,
AI plugin `1.4.0`, Anthropic connector `1.0.5`, and AI Client SDK `1.3.1`.

The browser opened synthetic draft content, selected a paragraph, opened
**AI Recommendations**, and performed one **Get Suggestions** action. The
real generation POST used the WordPress Abilities transport. A subsequent
automatic POST with `resolveSignatureOnly=true` also returned HTTP `200`;
that request reviewed the context using a cache-only signature check and did
not generate a second model result.

The response contained one settings recommendation, three style
recommendations, and one advisory block recommendation. The final snapshot
and screenshot show the native Inspector's recommendation groups and the
docs-grounding warning. Selection checkboxes in the Inspector did not apply
changes. No Apply action was performed.

## Provider diagnostics

The redacted response evidence records these request and completion fields.
Absence is recorded explicitly and is not substituted with a guessed value.

| Field | Observed value |
| --- | --- |
| Ability | `flavor-agent/recommend-block` |
| Execution transport | `wp-abilities` |
| Requested and resolved provider | `anthropic` |
| Requested and resolved model | `claude-opus-5` |
| Model selection source | `ai_plugin_feature_developer` |
| Model resolution status | `model` |
| `usedFallback` | `false` |
| Maximum output tokens | `16384` |
| `modelResolutionError` | Absent |
| `outputSchemaFallback` | Absent |
| Provider request identifier | Absent |
| Finish reason | `stop` |
| Latency and processing time | `41449` ms each |
| Input tokens | `11007` |
| Output tokens | `2756` |
| Total tokens | `13763` |
| Recommendations returned | Settings `1`; styles `3`; advisory block `1` |
| Apply action | None performed |
| Published | `false` |

## Docs-grounding limitation

The main generation response reported docs grounding unavailable, with
zero results, reason `backend_unreachable`, and error code
`http_request_failed`. The automatic signature review subsequently reported
`signature_cache_miss`, explaining the visible warning that the review was
cache-only and no docs result had been cached. The successful Anthropic
response therefore provides no evidence that developer-docs grounding or the
corpus backend was available during this request.

## Credential handling and cleanup

The existing configured connector credential was used temporarily through an
environment bridge backed by container tmpfs. Credential values, private
locations, account identities and request tokens are omitted from this record.
The original offline Studio installation was never bootstrapped, overwritten
or deployed. Its database, configuration and all `785` hashed installed
Flavor Agent files were verified unchanged after the live operation.

Controlled cleanup results confirmed isolated option restoration,
`containerCredentialAbsent=true`, and `privateSnapshotAbsent=true`. A final check
confirmed the exact temporary credential and MU-plugin filenames were absent.
The native request had completed before cleanup. Local validation artifacts
remain local; they are not included with this Markdown record.

## Retained local artifact identities

Paths identify outputs retained in the primary checkout. The snapshot and
screenshot were inspected, and all three byte counts and SHA-256 values were
verified when preparing this record. Raw browser artifacts must be reviewed
before sharing; only this redacted record is intended for version control.

| Local artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `output/playwright/live-anthropic/live-inspector-anthropic-master-1cae8e1.safe.json` | `1112` | `a275710b6797aeecd89969c6b0ea67d3978a55b57ea9aff4e83c7ff1c33cee19` |
| `output/playwright/live-anthropic/live-inspector-final.snapshot.txt` | `14498` | `3555ccde367ae843718a902d5115a1f2612780ee926bb13dc86a1d8296e1b3ee` |
| `output/playwright/live-anthropic/live-inspector-anthropic-recommendations-master-1cae8e1.png` | `94080` | `d079b2471ea513d6d097804b1a42781bfe7d3a5765cf75fdb8e344457935c947` |
