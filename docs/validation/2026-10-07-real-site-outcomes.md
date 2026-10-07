# October 7 bounded real-site outcome collection

The user approved the October follow-up work and selected a real production site over its last 30 days. The actual collection used the site's authenticated Flavor Agent AI Activity view and read-only GET responses. It did not use Jetpack activity, local fixtures, new recommendation requests, or production mutations.

## Observed cohort

The visible Date filter and every observed request used `dayOperator=inThePast`, `dayRelativeValue=30`, and `dayRelativeUnit=days`, with global administrator reads, reports enabled, newest timestamp first, and 20 rows per page. All three requests returned HTTP 200. Page sizes were 20, 20, and 1; `totalItems` remained 41, with no observed duplicates. The independent learning report used `governance-learning-report-v1`, sampled 41 rows against its 500-row cap, and reported `truncated=false`.

The retained local projection contains nine supported outcome/apply rows: five `shown`, two `adaptation_blocked`, and two block applies. Thirty-two other feed rows were excluded. Two non-allowlisted reasons were omitted. Row/set/suggestion identities were replaced with export-local aliases; raw responses, the alias mapping, and free text were discarded. No credentials, prompts, content, attributes, operations, user/entity/request IDs, raw signatures, individual timestamps, or provider bodies were retained in the projection.

| Feed-derived metric | Observed numerator | Observed denominator |
| --- | ---: | ---: |
| Review selection | 0 selected surface/set identities | 5 shown surface/set identities |
| Apply conversion | 2 linked applied sets intersecting shown sets | 5 shown surface/set identities |
| Undo | 0 recorded undone apply rows | 2 apply rows |

The server report separately reported rates 0, 0.4, and 0 for these metrics. Its response omitted the underlying denominators; the local counts above come from explicit feed joins, not reverse calculation from rounded rates. Two adaptation-blocked events are distinct from the server report's zero validation-blocked rate. Zero reported save-attempt counts establish no saved-state assurance. No dismissal coverage can be inferred from absent events.

## Provenance and limits

The named site, exact collection interval, operator custody, observed runtime versions, query evidence, projected JSON, and provenance gaps are retained in the restricted local record under ignored `output/validation/2026-10-07/approved-followups/` in the primary checkout. The candidate and custody record have not been shared or uploaded. Only this redacted aggregate record is included in the development branch.

The hosted implementation SHA/package digest and non-secret backend configuration remain unverified. Public plugin version labels and the local master SHA are not substitutes. The deployed response shape and report version were observed directly. The collection used a server-relative lower cutoff with no frozen upper bound; independent pagination requests are not a transactional snapshot. Stable counts do not rule out concurrent changes or establish population coverage beyond the returned bounded feed.

This is actual bounded production collection, not a qualified `recommendation-fixture-export-v1` fixture or a shipped exporter. Deployed-code/config provenance, exporter implementation, future schema/privacy review, and broader event/evidence projection remain open under the [proposed contract](../reference/recommendation-outcome-followups.md). The cohort does not establish site-wide quality, causal effect, or suitability for adaptive ranking. Ranking remains static.
