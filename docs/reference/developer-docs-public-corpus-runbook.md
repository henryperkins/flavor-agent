# Developer Docs Public Corpus Runbook

This document is the contract reference for the built-in public Developer Docs grounding corpus.

Use it when you need to answer:

- Who owns the public Cloudflare AI Search corpus used for Developer Docs grounding?
- Which source scopes and refresh cadence are required before the current-release coverage gate can ship?
- Which validation evidence must be recorded before enabling the current-release-cycle coverage gate?

Endpoint: `https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/search`

Owner: Flavor Agent release maintainer for the built-in public Developer Docs grounding endpoint.

Execution stop line: do not enable the current-release coverage gate until the person or team with Cloudflare AI Search corpus access has explicitly accepted this ownership and refresh cadence in the release notes or this runbook. The gate surfaces a trusted-but-degraded warning (it does not block recommendations on the release-cycle dimension), and it stays disabled by default.

Current release decision: for the `v0.1.0` target release environment, corpus ownership and the refresh cadence below are accepted as of the 2026-05-19 validation pass. Enable the current-release coverage gate (which surfaces the trusted-but-degraded warning) in the target release environment with:

```php
define( 'FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE', true );
```

Alternatively, a target environment may return `true` from the `flavor_agent_docs_grounding_require_current_coverage` filter. Keep Cloudflare AI Search reranking disabled for the built-in public Developer Docs endpoint until a dedicated reranking evaluation fixture exists and duplicate/provenance-poor chunks have been cleaned up or proven harmless under `DocsGroundingSourcePolicy`.

Required source scopes:
- `https://developer.wordpress.org/block-editor/`
- `https://developer.wordpress.org/rest-api/`
- `https://developer.wordpress.org/themes/`
- `https://developer.wordpress.org/reference/`
- `https://developer.wordpress.org/news/`
- `https://make.wordpress.org/core/7-0/`
- `https://make.wordpress.org/core/tag/dev-notes-7-0/`
- `https://make.wordpress.org/core/tag/gutenberg-new/`

Replace the release-specific Make/Core entries whenever the active major-release cycle changes. Keep the stable `developer.wordpress.org` scopes unless `DocsGroundingSourcePolicy` changes.

## Source Update Workflow

The `wordpress-docs-ai-search` MCP server and Flavor Agent's built-in Developer Docs grounding path both depend on the public Cloudflare AI Search corpus. Updating MCP source coverage means updating that corpus, not changing the Codex or Claude MCP registration. Client MCP config only points agents at the search endpoint.

The preferred updater is the scheduled/manual GitHub Actions workflow at `.github/workflows/update-docs-ai-search.yml`. It runs `npm run docs:ai-search:update -- --release=7-0`, discovers trusted source URLs, uploads changed Markdown items into the `wp-dev` AI Search built-in storage using bounded source keys shaped like `ai-search/wp-dev/{host}/{path-slug}/{short-hash}/part-0001.md` (capped at 128 bytes to satisfy Cloudflare's item-filename limit; the full canonical URL and content hash travel in item metadata, not the key), removes stale managed docs items, polls item status, and validates the public endpoint. Configure these repository secrets before enabling scheduled writes:

- `CLOUDFLARE_ACCOUNT_ID`
- `CLOUDFLARE_AI_SEARCH_API_TOKEN` with Account > AI Search:Edit and Account > AI Search:Run

Optional repository variables override the defaults:

- `CLOUDFLARE_AI_SEARCH_INSTANCE` (default `wp-dev`)
- `CLOUDFLARE_AI_SEARCH_PUBLIC_URL` (default the endpoint above)

For a local smoke test that does not write to Cloudflare:

```bash
npm run docs:ai-search:update -- --dry-run --source-url=https://developer.wordpress.org/block-editor/
```

Use this workflow whenever the active WordPress release cycle changes, a Field Guide or release candidate post lands, a dev-note batch is edited, a Gutenberg release materially changes editor APIs, or the validation query stops returning current release-cycle sources.

1. Set the active release identifier for the cycle, such as `7-0`.
2. Start from the stable Developer Docs scopes above. These must stay in the corpus because release-cycle sources do not replace handbook and reference grounding.
3. Add or refresh the release-cycle source set for the active identifier:
   - the Make/Core release hub, for example `https://make.wordpress.org/core/7-0/`
   - the Make/Core dev-note tag, for example `https://make.wordpress.org/core/tag/dev-notes-7-0/`
   - the current Make/Core Field Guide and any post-publication edits
   - Make/Core release candidate, schedule, and release party posts that change developer-facing expectations
   - Make/Core Gutenberg release posts that are inside the active core cycle or are needed as cutting-edge compatibility context
   - WordPress Developer Blog monthly "What's new for developers?" posts and focused developer articles that document active-cycle APIs, block editor changes, theme.json behavior, build tooling, or connector/runtime expectations
4. Keep supporting sources such as Make/Test, Make/Playground, Make/AI, and Gutenberg GitHub releases in separate research snapshots unless the runtime policy is expanded. They can help humans decide what to ingest, but they do not currently satisfy Flavor Agent's trusted-source coverage gate.
5. Remove or deprioritize superseded release-cycle source entries when their `published_at` date can no longer pass the freshness windows below. Retaining them as historical search context is acceptable only if current release-cycle sources still rank for the validation query.
6. After ingestion, run both an MCP search smoke check and the public endpoint validation query. Do not enable or keep the current-release coverage gate unless both the source mix and the freshness metadata match this runbook.

## Source Eligibility

Stable Developer Docs sources qualify when the canonical URL is under one of these scopes:

- `developer.wordpress.org/block-editor/`
- `developer.wordpress.org/rest-api/`
- `developer.wordpress.org/themes/`
- `developer.wordpress.org/reference/`

Current release-cycle and cutting-edge developer update sources qualify only when the canonical URL is under one of these scopes:

- `developer.wordpress.org/news/`
- `make.wordpress.org/core/`

For current-release coverage, `make-core` chunks must be published within 21 days of validation, and `developer-blog` chunks must be published within 45 days. Recrawling an old release post does not make it current. Stable `developer-docs` chunks may use crawl freshness and are current for 90 days.

The corpus may include additional official WordPress project sources for agent research, but Flavor Agent's recommendation grounding must still filter by `inc/Support/DocsGroundingSourcePolicy.php`. Do not treat sources outside the trusted scopes as satisfying the release gate unless the policy, tests, and this runbook are updated together.

## Chunk Metadata

Every ingested item or chunk should preserve enough provenance for `inc/Cloudflare/AISearchClient.php` to normalize and validate it:

- `source_url` or equivalent metadata resolving to the canonical HTTPS URL
- `retrieved_at` as the crawl timestamp
- `published_at` for Make/Core and Developer Blog posts
- `content_hash` for change detection
- a stable title
- a source key that is either reconstructable to the canonical URL (`<host>/<path>` or `ai-search/<instanceId>/<host>/<path>/<hash>/part-0001.md`), or a bounded managed key `ai-search/<instanceId>/<host>/<slug>/<short-hash>/part-0001.md` whose `<host>` segment matches the canonical URL host. Deep developer-docs URLs exceed Cloudflare's 128-byte item-filename limit, so their keys carry a truncated slug plus short hash and rely on the metadata `source_url` for provenance.

Source URLs must be HTTPS, must not include credentials, must not use a non-443 port, and must not contain encoded path delimiters or `.` / `..` path segments. If the source key cannot be reconciled with the canonical URL — it reconstructs to a different URL, sits outside the managed `ai-search/<instanceId>/` namespace, mismatches the URL host, or contains path traversal — Flavor Agent discards the chunk.

Refresh cadence:
- Weekly during active WordPress major-release cycles.
- Within 48 hours of a Make/Core Field Guide, dev note batch, RC post, or Gutenberg release post.
- Monthly outside active major-release cycles.

Release gate:
- Run the validation query: `WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes`.
- Replace `WordPress 7.0` in the validation query with the active major-release label whenever the release cycle changes.
- In an agent session with the MCP server available, run the same query through `wordpress-docs-ai-search` and confirm the returned chunks include the stable docs plus current-cycle sources expected below.
- Query the public endpoint with the same request shape used by `AISearchClient::build_search_request_body()`: a user message containing the validation query and `ai_search_options.retrieval.max_num_results` set to at least `4`.
- Confirm at least one `developer-docs` chunk and at least one `make-core` or `developer-blog` chunk.
- Confirm release-cycle chunks from `make.wordpress.org/core` or the Developer Blog include a recent `published_at`; a recent `retrieved_at` crawl timestamp does not make an old release-cycle post current. Stable handbook/reference chunks from `developer.wordpress.org` may use `retrieved_at` for crawl freshness because those pages represent maintained reference material rather than dated release-cycle posts.
- Record the observed `retrieved_at`, `published_at`, source URLs, and result count in the release notes or verification log.
- Record the validation evidence under `docs/validation/` and make this runbook the final release decision point for enabling `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE`.
- Enabling the gate surfaces a warning; it does not fail-close recommendations on the release-cycle dimension. With `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` (or the `flavor_agent_docs_grounding_require_current_coverage` filter) on, the coverage probe runs and a missing current release-cycle source attaches a trusted-but-degraded warning to recommendations instead of blocking them. Record the validation evidence before enabling so operators know when the warning is expected.

Coverage behavior:
- `missing-current-release-cycle` (trusted stable Developer Docs present, but no current `make-core`/`developer-blog` source) degrades-to-warn: `DocsGuidanceResult::resolve_status()` resolves `grounded`/`degraded`/`stale` and the coverage summary carries the warning, so recommendations proceed. There is no coverage grace window, last-known-current snapshot, or gate-block Settings warning — make-core publishing is bursty, so blocking on currency would dead-end recommendations during normal between-release lulls.
- Hard-blocks (`unavailable` status, HTTP 503 via `DocsGuidanceResult::unavailable_error()`, which still fires the `flavor_agent_docs_grounding_unavailable` action) remain for three cases only: no trusted official guidance at all, `missing-developer-docs` (make-core/developer-blog present but no stable Developer Docs backbone), and a coverage-probe transport failure. These are genuine outages, not a release-cycle gap.
- Validation paths (`AISearchClient::validate_configuration()` and the Settings page) see the raw probe result so admins can act on corpus drift.
