# Public corpus remediation - 2026-10-07

Both desired-key settlement and the unchanged default mixed-source freshness
probe passed after a targeted repair. This separate observation preserves the
[original scheduler failures](2026-10-07-public-corpus-scheduler.md), their
exit codes, guarded zero deletions and artifact digests. All times below are UTC.

The live repair used source commit `d8288931a317fa3ac4ae704336503d9fccfebbdc`.
Final source commit `1af8f461fba775e46574ae9d1a11fc8fa96428a6`, based on
`1cae8e14d6a90bc9f6ff81a7afccb8e7d7689270`, also fixes repeated refreshes
when a future-dated generation is retained. Independent source/privacy
re-review of that exact final SHA found no remaining P0–P2 issues and passed
12 additional candidate-selection cases plus the 98 focused tests. Changes
are limited to the updater, closest tests and
[runbook](../reference/developer-docs-public-corpus-runbook.md); no code was pushed.

## Original failure and late settlement

The automatic run started at `2026-10-07T10:17:02.157Z` and finished at
`2026-10-07T10:49:45.949Z`: `needs-attention`, exit 1, four pending desired
keys, zero desired-item errors, failed mixed-source freshness and zero deletions.
The earlier scheduler exercise also remains failed.

Read-only logs for all 284 uploaded documents identify these four late successful
indexing events; every other upload completed by `2026-10-07T10:42:20.521Z`.
These are item-log event times and do not establish individual page timing in
the original final metadata sweep.

| Public source | Successful indexing event |
| --- | --- |
| [WP_Scripts::set_group()](https://developer.wordpress.org/reference/classes/wp_scripts/set_group/) | `2026-10-07T10:48:30.760Z` |
| [RequestOptions::setConnectTimeout()](https://developer.wordpress.org/reference/classes/wordpress-aiclient-providers-http-dto-requestoptions/setconnecttimeout/) | `2026-10-07T10:52:18.210Z` |
| [TokenLimitReachedException::__construct()](https://developer.wordpress.org/reference/classes/wordpress-aiclient-common-exception-tokenlimitreachedexception/__construct/) | `2026-10-07T10:52:18.260Z` |
| [WP_Hook::has_filter()](https://developer.wordpress.org/reference/classes/wp_hook/has_filter/) | `2026-10-07T10:52:18.263Z` |

The read-only sweep `2026-10-07T11:25:59.583Z`–`2026-10-07T11:28:48.469Z`
found all 13,478 original desired keys among 16,502 listed items: missing 0,
pending 0, errors 0. No retry or reupload of these four was needed. Their logs
contain no error or duration explanation; the underlying service delay remains
unclassified.

## Freshness cause and authorized correction

The live instance's `rewrite_query: true` contradicted the required runbook
setting. Its rewritten default query returned only release posts. A read-only
probe with rewriting disabled exposed a stable reference chunk whose stored
`retrieved_at` was `2026-06-17T04:08:06.623Z`, outside the 90-day window.
Fetching it again produced unchanged source content. The updater skipped the
upload but recorded the new local fetch time in the manifest; stored metadata
and frontmatter remained stale. Sitemap reuse also lacked a crawl-age bound.

The fix bounds reuse to valid non-future crawl times within 90 days and records
actual stored metadata for unchanged skips. After a real fetch, stale, missing
or future crawl evidence creates a new immutable generation keyed from the
fetched Markdown body and its actual crawl time, retaining the source content
hash. A valid replacement outranks a retained future-dated generation on later
runs. Current pending generations remain desired and block settlement/cleanup.

The remote repair comprised one minimal `{"rewrite_query":false}` PUT, verified
at `2026-10-07T11:44:47.666Z`, and one newly fetched generation of
[WP_Block_Metadata_Registry::get_metadata()](https://developer.wordpress.org/reference/classes/wp_block_metadata_registry/get_metadata/).
It was fetched at `2026-10-07T11:44:48.248Z`, its upload confirmed at
`2026-10-07T11:44:49.712Z` and observed completed at
`2026-10-07T11:46:28.408Z`. Its content hash is unchanged; a download at
`2026-10-07T11:52:36.511Z` confirmed matching metadata/frontmatter and a body
SHA-256 matching the generation key.

The immediate configuration comparison changed only `rewrite_query` across
the four captured fields: it, `index_method`, `retrieval_options` and
`public_endpoint_id`. Comparing all 22 pre-captured fields with a fresh read
at `2026-10-07T12:04:06.857Z` found only `rewrite_query` and the operational
`last_activity` changed; the other 20 fields matched. The complete pre-update
configuration was not captured, so this is limited to those observed fields.
No deletion, manual resync, scheduler change, freshness-policy change or
cleanup-guard change was used. The old June generation remains completed.

## Final settlement and default request

The full sweep `2026-10-07T11:46:33.918Z`–`2026-10-07T11:49:33.716Z`
checked 13,478 desired source slots, replacing the repaired source's old key
with its new generation. Among 16,503 listed items, missing 0, pending 0 and
desired-item errors 0 established settlement; the old generation was separately
confirmed completed. This paginated sweep is not an atomic snapshot or a new
completed scheduled updater run.

Both freshness probes used the exact default request below, without per-request
rewriting, caching, filtering, boosting, query or result-cap overrides. Existing
freshness windows and the WordPress 7.1 release floor were unchanged.

```json
{
  "messages": [{"role": "user", "content": "WordPress 7.1 block.json metadata reference Gutenberg 23.8"}],
  "ai_search_options": {"retrieval": {
    "retrieval_type": "hybrid", "max_num_results": 8, "match_threshold": 0.2,
    "context_expansion": 1, "fusion_method": "rrf", "return_on_failure": true
  }}
}
```

| Observation | Result |
| --- | --- |
| `2026-10-07T11:46:33.910Z` and repeat `2026-10-07T11:52:39.094Z` | HTTP 200; 8 chunks; first attempt; `ok: true` |
| Both gates | Current Developer Docs: true; current release-cycle source: true |
| Returned evidence | 5 current / 3 stale chunks; both June and October stable generations retained |
| Stable reference classifications | June: `stale-retrieved-at`; October: `retrieved-at` |

Stale release posts also remain classified stale. No stale evidence was removed
to obtain the passing mixed-source result.

## Preserved original evidence

The failed 10:17 run's artifact digests remained unchanged after remediation:

| Original artifact | SHA-256 |
| --- | --- |
| `scheduled-2026-10-07T10-17-02-006Z.log` | `c5b2e0fabda98ac54f93299a118ed778cd8f8639809fcbf4f9ef7167076dcb07` |
| `summary.json` | `086e59dca67024e9cf5e0a729bc71265187d17a37f5f873a6bca711c9b08dcac` |
| `manifest.json` | `50235fc26a863a3d35f0ad94a244cbe20e4a5e85fbff2ac95617aabc0dd84e11` |

## Verification and limits

Four regressions failed against the original source before implementation; a
two-run future-date regression then failed against `d828893` before the review
fix. The closest updater/scheduler suites passed 98 tests. The final source
passed all 119 unit suites / 2,101 tests with
`npm run test:unit -- --runInBand --testPathIgnorePatterns=node_modules`.
The ignore override is necessary because the standard Jest configuration
excludes paths containing `.worktrees`; focused runs additionally used
`--runTestsByPath` for both updater and scheduler test files. The optional
whole-script ESLint check retains existing findings; its changed-line
comparison reports zero findings. This is not a clean whole-script lint claim.
`npm run check:docs` passed for the final source and this remediation record.

No persistent `outdated` item or automatic reindex progression was observed.
Local lifecycle probes show queued/running/outdated generations remain pending
and block both cleanup paths. Automatic progression for `outdated` remains an
operational limit, not evidence of settlement. This record does not attest to
a new scheduled pass, weekly pruning, whole-corpus currency, manual strict
release validation, publication or deployment. Future scheduled behavior
requires integrating the repair into its checkout. The existing corpus-validator
worktree and its unmerged changes were preserved.

## Later full refresh and scoped recovery

The [v0.1.1 preparation record](2026-10-07-v0.1.1-release-validation.md#corpus-and-production-boundaries) separately records the later full scheduled run, exact-source upload recovery and effective-manifest census. Both updater runs ended `needs-attention`; default public and managed MCP freshness passed, while the census still had 8,177 pending desired keys and five errors. These later results retain zero deletions and every cleanup guard. They do not rewrite the earlier bounded remediation or establish full settlement.
