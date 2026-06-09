# Developer Docs Grounding Corpus Validation - 2026-06-08

Run time: 2026-06-08 (UTC). Performed during an agent session with the
`wordpress-docs-ai-search` MCP server available, against the public Cloudflare
AI Search corpus (instance `ba566764-a507-4cd0-8cc8-cffbbde72ac3`, the `wp-dev`
instance) read by both the MCP server and the plugin's Developer Docs grounding.

Scope: the release-gate validation procedure in
`docs/reference/developer-docs-public-corpus-runbook.md` (§ "Release gate"),
recording the evidence required before relying on
`FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE`. Active release label: `7-0`.

## Result

Status: **pass** (current-release coverage gate satisfiable).

The canonical validation query returned at least one trusted stable
`developer-docs` chunk **and** at least one current `make-core`/`developer-blog`
chunk with a qualifying `published_at`, so
`DocsGroundingSourcePolicy::source_coverage_summary()` resolves
`hasDeveloperDocs = true`, `hasCurrentReleaseCycle = true`, status `current`.

This confirms the make.wordpress.org/core release-cycle content added by the
ingester fix (commit `e610dc7`) is present in the corpus; before that fix it was
absent. The corpus was last re-ingested 2026-06-08 (make-core chunks carry
`retrieved_at` ~2026-06-08T21:47Z).

## Validation query

Matches the `VALIDATION_QUERY` constant in `scripts/update-docs-ai-search.js`:

```
WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes
```

Retrieval: hybrid (vector + keyword, RRF fusion), 50 vector + 50 keyword
candidates, ~10 chunks returned.

## Observed qualifying chunks

Stable Developer Docs (`developer-docs`; crawl freshness, current < 90 days):

- `https://developer.wordpress.org/block-editor/getting-started/` — `retrieved_at` 2026-06-06T00:59:48Z
- `https://developer.wordpress.org/block-editor/reference-guides/packages/` — `retrieved_at` 2026-06-06T01:01:26Z

Developer Blog (`developer-blog`; current < 45 days):

- `https://developer.wordpress.org/news/` — `published_at` 2026-05-12T17:44:24Z (27 days → current)
- `https://developer.wordpress.org/news/all-posts/` — `published_at` 2026-05-12T17:44:24Z

Make/Core (`make-core`; current < 21 days, or `published_at` ≥ 2026-05-20 for WP 7.0):

- `https://make.wordpress.org/core/2026/05/21/whats-new-in-gutenberg-23-2-21-may/` — `published_at` 2026-05-21T17:31:30Z, `retrieved_at` 2026-06-08T21:47:58Z (within 21-day window and ≥ 2026-05-20 → current)

Additional in-scope make-core posts confirmed present in the corpus (adjacent
probes, all `retrieved_at` ~2026-06-08T21:47Z):

- `https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/` (`published_at` 2026-05-14)
- `https://make.wordpress.org/core/2026/05/20/commence-operation-wp-7-1/` (`published_at` 2026-05-20)
- `https://make.wordpress.org/core/2026/05/21/wordpress-7-1-call-for-volunteers/` (`published_at` 2026-05-21)
- `https://make.wordpress.org/core/2026/05/27/summary-dev-chat-may-27-2026/` (`published_at` 2026-05-27)

## Endpoint caveats recorded during validation

The MCP `/mcp` endpoint (agent reader) does not honor retrieval tuning — see
"MCP search endpoint behavior" in the corpus runbook:

- `ai_search_options.max_num_results` is ignored at both the flat path and the
  documented `ai_search_options.retrieval.max_num_results` nesting (requested 8
  and 3 respectively; ~10 chunks returned both times).
- `ai_search_options.filters` is ignored (an impossible `source_url` equality
  filter returned the full unfiltered result set).
- Raw results also intermix older posts and archive/index pages, and expose a
  rewritten `search_query`.

These affect agents using the MCP server, not the plugin: the plugin queries
`/search` with `ai_search_options.retrieval.*` and enforces trust, freshness, and
source classification in PHP via `DocsGroundingSourcePolicy` +
`AISearchClient::normalize_chunks()`, so trusted-source correctness does not
depend on Cloudflare-side filtering.

## Operational note

**Confirmed root cause of the red cron: the `CLOUDFLARE_ACCOUNT_ID` and
`CLOUDFLARE_AI_SEARCH_API_TOKEN` repository secrets are not configured.** A manual
`workflow_dispatch` on post-fix code (run `27171140768`, 2026-06-08T22:31Z,
release `7-0`, `delete_stale` off) failed in ~10s at `getAuth()`
(`scripts/update-docs-ai-search.js:1141`) with
`Error: CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_AI_SEARCH_API_TOKEN are required unless --dry-run is used.`
— the runner reported both env values empty. The prior scheduled run
(2026-06-08T06:55Z) fails identically. The hardened ingester itself is healthy
(local `--dry-run` is clean, exit 0); it simply never authenticates, so it never
reaches discovery, upload, or the summary write.

Remediation (maintainer, privileged): add the two repository secrets — the API
token needs `Account > AI Search:Edit` and `Account > AI Search:Run` — then
re-dispatch `update-docs-ai-search.yml` to confirm green. Until then the corpus is
refreshed only by manual `npm run docs:ai-search:update` runs. The workflow's
GitHub Actions were also bumped to their Node 24 (v5) versions (commit pending) to
avoid the 2026-06-16 forced-Node-24 deprecation.
