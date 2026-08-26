# Public Developer Docs Corpus Validation - 2026-08-26

Validation time: 2026-08-26T07:15:24.868Z.

Repository base identity: `4831b4e000b1f004e00baccdfdfaacffc4bfa861`
(`master`). The run used working-tree changes that move the active updater
profile and validation contract to WordPress 7.1; those changes still require a
candidate commit and exact-tag verification.

## Result

Status: **pass** for current public corpus health.

The public endpoint returned HTTP `200` with eight chunks. The response
contained current stable Developer Docs evidence and current Make/Core evidence
in the same bounded result set. Automated validation passed on attempt `1`:

- `freshness.developerDocs`: `true`
- `freshness.releaseCycle`: `true`
- `validation.ok`: `true`
- Current source types: `make-core`, `developer-docs`
- Observed source types: `make-core`, `wordpress-news`, `developer-docs`,
  `developer-blog`

Endpoint:
`https://101d836c-480b-4b39-b14e-505a6aa58f47.search.ai.cloudflare.com/search`

Query:
`WordPress 7.1 block.json metadata reference Gutenberg 23.8`

The query deliberately combines a stable handbook/reference identifier with a
current release-cycle anchor. The request retained Flavor Agent's runtime cap
of eight results rather than widening validation beyond the product contract.
Cloudflare documents `max_num_results` as a per-request control from 1 through
50: <https://developers.cloudflare.com/ai-search/configuration/retrieval/result-controls/>.

## Updater run identity

Command:

```bash
npm run docs:ai-search:update -- \
  --release=7-1 \
  --no-delete \
  --skip-configure \
  --source-url=https://make.wordpress.org/core/2026/08/19/whats-new-in-gutenberg-23-8-19-august/ \
  --poll-seconds=600 \
  --validation-attempts=5 \
  --output=output/docs-ai-search-release-2026-08-26-final
```

- Started: `2026-08-26T07:12:00.104Z`
- Finished: `2026-08-26T07:15:24.954Z`
- Status: `ok`
- Release profile: `7-1`
- Instance: `wp-dev-docs`
- Explicit source URLs: `1`
- Prepared documents: `1`
- Uploaded: `0` (the desired generation already matched)
- Skipped unchanged: `1`
- Desired-key settlement polls: `1`
- Initial / best / final active items: `0 / 0 / 0`
- Pending desired keys: `0`
- Item errors: `0`
- Discovery/build/upload errors: `0 / 0 / 0`
- Same-source deletion: `0`, reason `targeted-run`
- Bulk stale deletion: `0`, reason `disabled`
- Instance configuration: skipped
- Remote completed-source baseline observed: `13,500`

Local output artifact identities:

- `output/docs-ai-search-release-2026-08-26-final/summary.json`
  - SHA-256: `fbd66779eabb2adb1305bce968f89b4fd14426af781a7e9f283640febc5232c7`
  - Size: `4,843` bytes
- `output/docs-ai-search-release-2026-08-26-final/manifest.json`
  - SHA-256: `8d880c490b45ba520ff1873a9634d2a0a059a36742fdb333f2402820e9a03869`
  - Size: `511` bytes

These ignored local artifacts remain available for the later candidate-log
bundle. This repository record contains their relevant bounded content and
checksums.

## Returned evidence

| Rank | Source type | Source URL | `published_at` | `retrieved_at` | Classification |
| ---: | --- | --- | --- | --- | --- |
| 1 | `make-core` | <https://make.wordpress.org/core/2026/08/05/wordpress-7-1-field-guide/> | `2026-08-05T14:42:44.000Z` | `2026-08-14T05:48:37.462Z` | Current, `published-at` |
| 2 | `make-core` | <https://make.wordpress.org/core/2026/08/04/a-unified-public-exposure-flag-for-abilities-in-wordpress-7-1/> | `2026-08-04T12:49:37.000Z` | `2026-08-05T06:11:56.044Z` | Stale, `stale-published-at` |
| 3 | `make-core` | <https://make.wordpress.org/core/2026/08/19/whats-new-in-gutenberg-23-8-19-august/> | `2026-08-19T12:03:42.000Z` | `2026-08-26T06:44:39.897Z` | Current, `published-at` |
| 4 | `make-core` | <https://make.wordpress.org/core/2026/08/19/whats-new-in-gutenberg-23-8-19-august/> | `2026-08-19T12:03:42.000Z` | `2026-08-26T06:44:39.897Z` | Current, `published-at` |
| 5 | `wordpress-news` | <https://wordpress.org/news/2026/07/wordpress-7-1-beta-1/> | `2026-07-15T15:53:33.000Z` | `2026-07-28T07:48:39.645Z` | Stale, `stale-published-at` |
| 6 | `make-core` | <https://make.wordpress.org/core/2026/08/05/wordpress-7-1-field-guide/> | `2026-08-05T14:42:44.000Z` | `2026-08-14T05:48:37.462Z` | Current, `published-at` |
| 7 | `developer-docs` | <https://developer.wordpress.org/reference/classes/wp_block_metadata_registry/get_metadata/> | — | `2026-06-17T04:08:06.623Z` | Current, `retrieved-at` |
| 8 | `developer-blog` | <https://developer.wordpress.org/news/2024/07/json-schema-in-wordpress/> | `2024-07-19T16:51:07.000Z` | `2026-06-17T04:05:35.338Z` | Stale, `stale-published-at` |

The duplicate Gutenberg 23.8 and Field Guide chunks are distinct chunks from
the same source, not separate source identities. They are recorded rather than
silently de-duplicated because the runtime intentionally preserves distinct
retrieved chunks.

## Remediation and safety notes

The August 26 scheduled GitHub Actions updater could not run because the account
billing lock left its job at `runner_id: 0` with zero steps. This local run is
therefore the current updater identity; it does not turn that hosted check
green.

An earlier targeted refresh exposed that superseded same-source generations
could still be removed even when bulk stale deletion was disabled. The settled
replacement remained present, but the behavior contradicted the runbook's
targeted-run promise. A regression test now requires explicit-source runs to
return `targeted-run` and delete nothing. The focused updater suite passes
`81/81`, and the final run above confirms both deletion counters remained zero.

This was a targeted refresh, so it proves desired-key completeness for the
August 19 source and current public-query health. It does not claim a fresh
full-corpus desired-key sweep across all `13,500` completed source URLs. A
healthy scheduled/full incremental run remains preferable once GitHub Actions
can execute again.
