# Public corpus scheduler exercise - 2026-10-07

Repository identity: `1cae8e14d6a90bc9f6ff81a7afccb8e7d7689270` (`master`).
Evidence snapshot: `2026-10-07T09:30:52.073Z`. Each observation below retains its
own timestamp; later checks do not replace the completed exercise result.

## Result

**Needs attention; completed with exit code `1`.** The registered local task
finished at `2026-10-07T09:13:23.442Z`. The updater uploaded 975 documents without
discovery, build or upload errors, but three desired keys remained pending.
Public validation returned release-cycle evidence and no stable Developer Docs
chunk after all five attempts. Both deletion paths performed no deletions.

This exercise proves that the local scheduler ran and recorded a failure. It
does not prove successful desired-key settlement, mixed-source freshness or a
completed weekly pruning pass. Later read-only observations may be appended as
follow-up evidence; they do not replace the original failure recorded here.

The existing [public corpus runbook](../reference/developer-docs-public-corpus-runbook.md)
owns the refresh cadence, authorization and safety rules. This exercise and
record do not alter them.

## Scheduler registration observed

Task: `Flavor Agent docs corpus update`.

| Property | Observed value |
| --- | --- |
| Schedule | Daily at `05:17` local time; observed offset `-05:00` |
| Trigger count / interval | One trigger, one-day interval |
| Missed-run handling | `StartWhenAvailable: true` |
| Network requirement | Enabled |
| Overlap handling | `IgnoreNew` |
| Launch | Hidden PowerShell wrapper |
| Principal mode | Interactive logon, limited run level |
| Execution time limit | Two hours |
| State observed during execution | Running at the earlier `08:55:50Z` snapshot |
| State after completion | Ready at the `09:16:13Z` snapshot |
| Last Task Result | `1`, matching the wrapper's failure exit code |

Credentials are supplied outside the repository. Credential values, account
identifiers, principal identity and private configuration paths are omitted.

## Completed exercise identity

Log: `output/docs-ai-search/logs/scheduled-2026-10-07T08-24-24-013Z.log`.

- Started: `2026-10-07T08:24:24.013Z`.
- Updater summary started: `2026-10-07T08:24:24.222Z`.
- Updater summary finished: `2026-10-07T09:13:23.375Z`.
- Wrapper finished: `2026-10-07T09:13:23.442Z`, exit code `1`.
- Logged checkout: `1cae8e14d6a90bc9f6ff81a7afccb8e7d7689270` (`master`).
- Logged updater arguments: `--poll-seconds=600`.
- Release profile: `7-1`; instance: `wp-dev-docs`.
- `dryRun: false`; instance configuration skipped.
- Discovered source URLs: `13,479`.
- Prepared Markdown documents: `13,478`.
- Reused unchanged through sitemap `lastmod`: `3,175`.
- Final updater status: `needs-attention`.

October 7 is a Wednesday. This run's logged arguments contain no
`--delete-stale` flag; the scheduler adds that flag on Mondays. Do not describe
this Wednesday exercise as a completed weekly bulk-pruning pass.

The aggregate instance observation `completed: 15,243`, `queued: 0`, `error: 1`
is only a progress observation. Aggregate counters neither prove that every
desired key is present nor identify whether the errored item belongs to this
run's desired corpus.

## Final settlement and processing evidence

The final `DOCS_AI_SEARCH_RESULT` belongs to the exercise above. Its summary
distinguishes item settlement, public retrieval freshness and destructive
cleanup.

| Evidence | Observed final value |
| --- | --- |
| Upload / skip counts | `975` uploaded; `12,504` skipped |
| Error counts | Discovery `0`, build `0`, upload `0`, desired-item errors `0` |
| Duplicate sources | `1` reported |
| Desired-key settlement | Not skipped; `3` pending desired keys, so settlement failed |
| Settlement polls / elapsed time | `253` polls; `1,509,510` ms |
| Initial / best / final active | `901 / 3 / 3` |
| Last-progress age / extension | `270,359` ms; `extended: true` |
| Regression baseline | Cached manifest `13,478`; remote completed source URLs `13,555`; applied baseline `13,555`; prepared count `13,478` |
| Superseded same-source deletion | `performed: false`, `deleted: 0`, reason `replacement-not-settled` |
| Bulk stale deletion | `performed: false`, reason `disabled` |
| Total deleted | `0`, including `0` same-source deletions |

The settlement implementation checks the desired item listing after the
aggregate queued/running/outdated counters clear. Missing and pending desired
keys remain incomplete; desired `error` or `skipped` items remain failures.
The aggregate `error: 1` alone cannot establish this run's outcome. Here the
final desired-key sweep, rather than that aggregate observation, established
the three unresolved keys and zero desired-item errors. The trajectory shows
progress from 901 active keys followed by a plateau at three; additional wait
time did not convert those pending keys into a pass.

## Public-query freshness evidence

Query: `WordPress 7.1 block.json metadata reference Gutenberg 23.8`.

- Final validation checked at: `2026-10-07T09:13:22.749Z`.
- HTTP status: `200`; chunk count: `8`; attempts: `5`.
- `validation.ok: false`.
- `freshness.developerDocs: false`.
- `freshness.releaseCycle: true`.
- Observed and current source types: `developer-blog`, `make-core`.

All eight chunks have release-cycle source types. Five chunks qualify as
current; three are stale. There is no stable `developer-docs` chunk. HTTP 200
and current release-cycle evidence therefore did not satisfy the mixed-source
validation contract.

| Rank | Source type | Public source URL | Published at | Retrieved at | Currentness basis |
| ---: | --- | --- | --- | --- | --- |
| 1 | `developer-blog` | <https://developer.wordpress.org/news/2026/09/whats-new-for-developers-september-2026/> | `2026-09-10T22:18:51Z` | `2026-10-07T02:36:39.891Z` | Current, `published-at` |
| 2 | `make-core` | <https://make.wordpress.org/core/2026/03/15/block-visibility-in-wordpress-7-0/> | `2026-03-15T22:35:08Z` | `2026-06-17T04:25:41.173Z` | Stale, `stale-published-at` |
| 3 | `make-core` | <https://make.wordpress.org/core/2026/08/04/miscellaneous-block-editor-changes-in-wordpress-7-1/> | `2026-08-04T11:10:09Z` | `2026-08-06T06:12:16.806Z` | Stale, `stale-published-at` |
| 4 | `make-core` | <https://make.wordpress.org/core/2026/08/19/whats-new-in-gutenberg-23-8-19-august/> | `2026-08-19T12:03:42Z` | `2026-08-26T06:44:39.897Z` | Current, `release-floor` |
| 5 | `developer-blog` | <https://developer.wordpress.org/news/2026/09/whats-new-for-developers-september-2026/> | `2026-09-10T22:18:51Z` | `2026-10-07T02:36:39.891Z` | Current, `published-at` |
| 6 | `developer-blog` | <https://developer.wordpress.org/news/2026/09/whats-new-for-developers-september-2026/> | `2026-09-10T22:18:51Z` | `2026-10-07T02:36:39.891Z` | Current, `published-at` |
| 7 | `make-core` | <https://make.wordpress.org/core/2026/09/02/whats-new-in-gutenberg-23-9-2-september/> | `2026-09-02T15:25:50Z` | `2026-10-07T02:49:30.799Z` | Current, `release-floor` |
| 8 | `make-core` | <https://make.wordpress.org/core/2026/06/23/hiding-the-classic-block-from-the-inserter-in-wordpress-7-1/> | `2026-06-23T08:34:13Z` | `2026-07-13T06:28:16.330Z` | Stale, `stale-published-at` |

Repeated Developer Blog URLs are separate returned chunks and remain in the
ranked evidence table. Raw diagnostic response bodies and corpus item
identifiers are omitted.

Public validation requires a current stable `developer-docs` chunk and a
current `make-core` or `developer-blog` chunk in the same bounded response.
Stable docs use a non-future retrieval timestamp within 90 days. Release-cycle
posts use a non-future publication timestamp within the applicable 21/45-day
window or on/after the WordPress 7.1 release floor, `2026-08-19`.

Expected source domains without qualifying timestamps are insufficient.

## Deletion evidence boundaries

The existing updater can remove superseded same-source generations on eligible
full runs independently of `--delete-stale`. Its result is therefore recorded
separately from the Monday bulk-pruning pass. In this exercise the unsettled
replacement guard blocked same-source cleanup; bulk pruning was disabled.
Both deletion counts were zero.

For bulk pruning, `staleDeletion.performed: true` records that the safety guard
authorized the pass; the actual deleted count may still be zero. A
`validation-warning` permits cleanup when some chunks are retrievable but
keeps the run in `needs-attention`. Zero-chunk retrieval blocks both destructive
paths with `validation-unavailable`.

No source, instance-configuration or pruning remediation was performed as part
of documenting this result. The original run remains failed. A later read-only
settlement or retrieval observation must carry its own timestamp and evidence,
and must not retroactively turn this exercise into a pass.

## Final local artifact identities

The log, summary and manifest below are the completed exercise outputs. Raw
artifacts remain local; this record contains only safe extracted evidence and
their SHA-256 identities.

| Artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `output/docs-ai-search/logs/scheduled-2026-10-07T08-24-24-013Z.log` | 9,496 | `5539f46bb7d173e33012bf64458a80e6d2bb860a9522a1297ed1157a053784bd` |
| `output/docs-ai-search/summary.json` | 6,448 | `2c9283a0160b59e93e61cff45336325925053877bbb69a54fbf3e87c7076e866` |
| `output/docs-ai-search/manifest.json` | 6,801,787 | `9d057a0f096607e595754063b202bf7cd31f49d935fa5584f2faf7e779725d21` |

## Separate read-only post-run check

The item sweep checked at `2026-10-07T09:20:25.971Z` found all `13,478`
desired items among `16,218` listed items. Settlement remained incomplete:
missing `0`, pending `3`, desired-item errors `0`.

| Public source URL | Observed item status |
| --- | --- |
| <https://developer.wordpress.org/reference/hooks/wp_pre_insert_user_data/> | `queued` |
| <https://developer.wordpress.org/reference/hooks/pre_wp_list_authors_post_counts_query/> | `running` |
| <https://developer.wordpress.org/reference/hooks/get_image_tag/> | `running` |

The same default mixed query was checked separately at
`2026-10-07T09:20:29.235Z`: HTTP `200`, eight chunks, one attempt,
`validation.ok: false`, `developerDocs: false`, `releaseCycle: true`. The
ranked public URLs, publication/retrieval timestamps and currentness evidence
matched the original eight-row public-query table above. This check performed
no mutations and did not retry the scheduler or delete items.

| Separate safe JSON artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `output/docs-ai-search/post-run-check-2026-10-07.json` | 3,774 | `df5d18e23bd4fac264b40d36e425b635c28eacae3b76abf9dcf8dd9a912b07eb` |

## Independent targeted retrieval checks

Exactly two additional public retrieval requests were made to distinguish
source availability from the default mixed query's bounded coverage. Both
used the current updater's POST schema: one user message and
`ai_search_options.retrieval` with `retrieval_type: hybrid`,
`max_num_results: 8`, `match_threshold: 0.2`, `context_expansion: 1`,
`fusion_method: rrf` and `return_on_failure: true`. Currentness was evaluated
with the updater's existing source/timestamp policy. No raw chunk text or
chunk/item identifiers are included here.

The two tables below come from timestamped, sanitized tool stdout; no separate
JSON artifact was retained for these supporting observations.

These requests are independent observations. They do not replace the
existing mixed-source validation gate, test settlement or authorize cleanup.
They performed no corpus, instance-configuration or scheduler mutations.

### Stable block metadata query

Query: `block.json metadata reference apiVersion name title category supports attributes`.

Started at `2026-10-07T09:30:37.991Z`; checked at
`2026-10-07T09:30:52.073Z`. HTTP `200`, eight chunks, source types and current
source types `developer-docs`; `developerDocs: true`, `releaseCycle: false`.
Six chunks qualify as current and two are stale. Repeated ranks are grouped
only where their public URL and timestamps are identical.

| Rank(s) | Public source URL | Published at | Retrieved at | Currentness basis |
| --- | --- | --- | --- | --- |
| 1, 4 | <https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/> | `2021-04-21T15:39:48Z` | `2026-10-07T02:36:01.886Z` | Current, `retrieved-at` |
| 2, 5 | <https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/> | `2021-04-21T15:39:48Z` | `2026-07-18T05:58:54.318Z` | Current, `retrieved-at` |
| 3 | <https://developer.wordpress.org/block-editor/getting-started/fundamentals/block-json/> | `2023-11-29T08:25:01Z` | `2026-06-17T04:04:32.441Z` | Stale, `stale-retrieved-at` |
| 6 | <https://developer.wordpress.org/rest-api/reference/block-types/> | `2020-08-09T03:34:43Z` | `2026-06-17T04:25:15.564Z` | Stale, `stale-retrieved-at` |
| 7 | <https://developer.wordpress.org/block-editor/reference-guides/core-blocks/core-blocks-text/core-block-heading/> | `2026-06-07T06:30:56Z` | `2026-10-07T02:36:16.774Z` | Current, `retrieved-at` |
| 8 | <https://developer.wordpress.org/block-editor/reference-guides/core-blocks/core-blocks-theme/core-block-post-title/> | `2026-06-07T06:30:57Z` | `2026-07-18T05:59:14.713Z` | Current, `retrieved-at` |

Stable Developer Docs are present and retrievable with this query. Two
retrieval dates for the block metadata URL demonstrate that distinct crawl
generations appeared in this response; this observation does not establish
which index items could safely be deleted.

### Current release-cycle query

Query: `WordPress 7.1 Gutenberg 23.9 release developer changes`.

Started at `2026-10-07T09:30:38.014Z`; checked at
`2026-10-07T09:30:51.791Z`. HTTP `200`, eight chunks, source types and current
source types `developer-blog`, `make-core`; `developerDocs: false`,
`releaseCycle: true`. Four chunks qualify as current and four are stale.

| Rank | Source type | Public source URL | Published at | Retrieved at | Currentness basis |
| ---: | --- | --- | --- | --- | --- |
| 1 | `developer-blog` | <https://developer.wordpress.org/news/2026/09/whats-new-for-developers-september-2026/> | `2026-09-10T22:18:51Z` | `2026-10-07T02:36:39.891Z` | Current, `published-at` |
| 2 | `make-core` | <https://make.wordpress.org/core/2026/09/02/whats-new-in-gutenberg-23-9-2-september/> | `2026-09-02T15:25:50Z` | `2026-10-07T02:49:30.799Z` | Current, `release-floor` |
| 3 | `make-core` | <https://make.wordpress.org/core/2026/06/23/hiding-the-classic-block-from-the-inserter-in-wordpress-7-1/> | `2026-06-23T08:34:13Z` | `2026-07-13T06:28:16.330Z` | Stale, `stale-published-at` |
| 4 | `make-core` | <https://make.wordpress.org/core/2026/05/27/summary-dev-chat-may-27-2026/> | `2026-05-27T20:38:51Z` | `2026-06-17T04:25:51.635Z` | Stale, `stale-published-at` |
| 5 | `make-core` | <https://make.wordpress.org/core/2026/09/10/summary-dev-chat-september-9-2026/> | `2026-09-10T20:22:44Z` | `2026-10-07T02:49:31.866Z` | Current, `release-floor` |
| 6 | `make-core` | <https://make.wordpress.org/core/2026/06/30/dev-chat-agenda-july-1-2026/> | `2026-06-30T21:24:32Z` | `2026-07-06T06:50:06.211Z` | Stale, `stale-published-at` |
| 7 | `make-core` | <https://make.wordpress.org/core/2026/09/02/wordpress-7-1-1-release-schedule/> | `2026-09-02T05:00:41Z` | `2026-10-07T02:49:30.412Z` | Current, `release-floor` |
| 8 | `make-core` | <https://make.wordpress.org/core/2026/07/28/dev-chat-agenda-july-28-2026/> | `2026-07-28T01:41:45Z` | `2026-07-28T07:48:39.751Z` | Stale, `stale-published-at` |

Both source families were independently retrievable with current qualifying
chunks. The original and post-run mixed queries still lacked a current
stable-docs chunk within their eight returned chunks, and the latest item
sweep still had three pending desired items. The scheduler's original
`needs-attention` result remains unchanged. These observations do not prove
complete corpus freshness, successful pruning or release/deployment readiness.
