# Developer Docs Grounding Corpus Endpoint Alignment - 2026-06-17

Run time: 2026-06-17 (UTC). Performed during a local agent session against the
built-in public Cloudflare AI Search endpoint currently configured by
`AISearchClient::DEFAULT_PUBLIC_SEARCH_URL`.

Endpoint: `https://101d836c-480b-4b39-b14e-505a6aa58f47.search.ai.cloudflare.com/search`

Instance/default name: `wp-dev-docs`

## Result

Status: **needs attention**.

The endpoint is reachable and populated after a full corpus run against
`wp-dev-docs`, but the canonical validation query still fails the runbook
source-mix requirement: it returns current Make/Core chunks only, with no stable
Developer Docs chunks in the top results.

The full run completed at `2026-06-17T05:31:06Z` with:

- `sourceUrls`: `13209`
- `preparedDocuments`: `13197`
- `uploaded`: `13196`
- `uploadErrors`: `0`
- `buildErrors`: `11` (all observed examples were skipped cross-posts whose
  canonical URLs resolved outside trusted roots)
- `duplicateSources`: `1`
- `pending`: `4655` at the run's validation time
- `staleDeletion`: disabled

Follow-up stats at `2026-06-17T06:05:30Z` showed:

- `queued`: `0`
- `running`: `14`
- `completed`: `13183`
- `error`: `0`
- `outdated`: `0`

## Validation query

Matches the `VALIDATION_QUERY` constant in `scripts/update-docs-ai-search.js`:

```
WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes
```

Observed public endpoint response before the full run:

- HTTP status: `200`
- `result.search_query`: `WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes`
- `result.chunks`: `[]`
- `result.hybrid_meta.search_methods`: `[]`

Observed public endpoint response after the full run:

- HTTP status: `200`
- `result.chunks`: `8`
- `result.hybrid_meta.search_methods`: `["vector","keyword"]`
- Returned sources:
  - `https://make.wordpress.org/core/2026/03/18/dev-chat-agenda-march-18-2026/`
  - `https://make.wordpress.org/core/2026/04/01/dev-chat-agenda-april-1-2026/`
  - `https://make.wordpress.org/core/2026/04/21/dev-chat-agenda-april-22-2026/`
  - `https://make.wordpress.org/core/2026/05/27/dev-chat-agenda-may-27-2026/`
  - `https://make.wordpress.org/core/2026/03/11/dev-chat-agenda-march-11-2026/`
  - `https://make.wordpress.org/core/2026/03/04/dev-chat-agenda-march-04-2026/`
  - `https://make.wordpress.org/core/2026/05/13/summary-dev-chat-may-6-2026/`
  - `https://make.wordpress.org/core/2026/04/07/dev-chat-agenda-april-8-2026/`

Stable Developer Docs are indexed and retrievable for targeted queries. For
example, `wp_register_ability` returned `developer.wordpress.org` chunks for
`wp_register_ability()`, `WP_Ability`, and related hooks.

## Remediation recorded with this validation

- `.github/workflows/update-docs-ai-search.yml` now uses the same fallback
  instance and public URL as `scripts/update-docs-ai-search.js`.
- `docs/reference/developer-docs-public-corpus-runbook.md` now documents
  `wp-dev-docs` and the `101d836c-480b-4b39-b14e-505a6aa58f47` endpoint.
- `scripts/__tests__/update-docs-ai-search.test.js` now guards both workflow
  and runbook defaults against drifting from `parseArgs([])`.

## Required follow-up before release reliance

Let the final running items settle, then rerun the validation query. If it still
returns only Make/Core chunks, tune the corpus query/ranking/configuration or the
validation query until the top result set includes both stable Developer Docs
chunks and current release-cycle chunks. Replace this needs-attention record with
a passing validation record only after that source mix is confirmed.
