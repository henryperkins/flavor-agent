# Developer Docs Public Corpus Runbook

This document is the contract reference for the built-in public Developer Docs grounding corpus.

Use it when you need to answer:

- Who owns the public Cloudflare AI Search corpus used for Developer Docs grounding?
- Which source scopes and refresh cadence are required for the managed corpus?
- Which validation evidence must be recorded when the corpus is refreshed?

Endpoint: `https://101d836c-480b-4b39-b14e-505a6aa58f47.search.ai.cloudflare.com/search`

Owner: Flavor Agent release maintainer for the built-in public Developer Docs grounding endpoint.

Execution stop line: corpus refreshes (and especially stale deletion) require that the person or team with Cloudflare AI Search corpus access has explicitly accepted this ownership and refresh cadence in the release notes or this runbook.

Current release decision: for the `v0.1.0` target release environment, corpus ownership and the refresh cadence below are accepted as of the 2026-05-19 validation pass. There is no runtime coverage gate or grounding constant to configure: grounding is best-effort (`AISearchClient::maybe_search_best_effort`), never blocks a recommendation, and at runtime applies only structural URL hygiene plus non-gating source labels. Trust and currency of the corpus are owned by `scripts/update-docs-ai-search.js` at ingestion time.

2026-06-17 endpoint alignment: the built-in public endpoint and the updater defaults now target the `wp-dev-docs` Cloudflare AI Search corpus (`101d836c-480b-4b39-b14e-505a6aa58f47`). The GitHub Actions workflow fallback values, local script defaults, this runbook, and `AISearchClient::DEFAULT_PUBLIC_SEARCH_URL` must stay aligned. If the validation query returns zero chunks after an endpoint change, dispatch a full corpus run against `wp-dev-docs` before treating the endpoint as release-ready.

Keep Cloudflare AI Search query rewriting disabled for the built-in public Developer Docs endpoint so exact WordPress identifiers such as `wp_register_ability`, `block.json`, and `theme.json` survive retrieval unchanged. Keep reranking enabled with `@cf/baai/bge-reranker-base`; the 2026-06-15 smoke evaluation showed exact-symbol queries regressed when reranking was disabled.

Required source scopes:
- `https://developer.wordpress.org/block-editor/`
- `https://developer.wordpress.org/rest-api/`
- `https://developer.wordpress.org/themes/`
- `https://developer.wordpress.org/reference/`
- `https://developer.wordpress.org/news/`
- `https://make.wordpress.org/core/` (release-cycle posts under dated `/core/YYYY/MM/DD/` permalinks, bounded to a recency window — see Source Update Workflow)

The updater discovers Make/Core posts from the `make.wordpress.org/core/` subsite sitemap (`/core/wp-sitemap.xml`, since the network-root `robots.txt` does not advertise it) and keeps only those whose permalink date falls within `--make-core-max-age-days` (default 180). That captures the active cycle's dev notes, Field Guide, RC, and Gutenberg-release posts without dragging in the long-tail Make/Core archive, and it self-maintains across release cycles without per-release tag edits. Keep the stable `developer.wordpress.org` scopes unless `DocsGroundingSourcePolicy` changes.

## Source Update Workflow

The `wordpress-docs-ai-search` MCP server and Flavor Agent's built-in Developer Docs grounding path both depend on the public Cloudflare AI Search corpus. Updating MCP source coverage means updating that corpus, not changing the Codex or Claude MCP registration. Client MCP config only points agents at the search endpoint.

The preferred updater is the scheduled/manual GitHub Actions workflow at `.github/workflows/update-docs-ai-search.yml`. It restores the prior `output/docs-ai-search/manifest.json` from the Actions cache, runs `npm run docs:ai-search:update -- --release=7-0`, discovers trusted source URLs, filters release-cycle listing/archive URLs out of the desired corpus, uploads changed Markdown items into the public Cloudflare AI Search corpus on `wp-dev-docs` using bounded source keys shaped like `ai-search/wp-dev-docs/{host}/{path-slug}/{short-hash}/part-0001.md` (capped at 128 bytes to satisfy Cloudflare's item-filename limit; the full canonical URL and content hash travel in item metadata, not the key), polls the instance `/stats` endpoint until queued/running/outdated counts settle, does one item-level sweep to verify desired keys, validates the public endpoint, and — only when stale deletion is enabled, the destructive full-run safety checks pass (no discovery/upload/poll problems, build errors remain within the 2% tolerance below, it is not a targeted run, and the prepared count has not regressed against the restored manifest or the live completed-corpus baseline) — removes stale managed docs items. Public endpoint validation failures still mark the run as needing attention, but they do not block stale deletion because stale generations can be the reason validation is noisy. Scheduled runs pass `--delete-stale` by default; manual dispatch remains opt-in through the `delete_stale` input. Instance configuration is skipped by default because Cloudflare treats config updates as an instance-wide resync; use the workflow's `configure_instance` input or the script's `--configure` flag only for deliberate metadata/search-config changes. Configure these repository secrets before enabling scheduled writes:

- `CLOUDFLARE_ACCOUNT_ID`
- `CLOUDFLARE_AI_SEARCH_API_TOKEN` with Account > AI Search:Edit and Account > AI Search:Run

Optional repository variables override the defaults:

- `CLOUDFLARE_AI_SEARCH_INSTANCE` (default `wp-dev-docs`)
- `CLOUDFLARE_AI_SEARCH_PUBLIC_URL` (default the endpoint above)

For a local smoke test that does not write to Cloudflare:

```bash
npm run docs:ai-search:update -- --dry-run --source-url=https://developer.wordpress.org/block-editor/
```

Make/Core recency is tunable with `--make-core-max-age-days=<n>` (default 180; `0` ingests every matched dated post). Script-level stale deletion remains explicitly gated by `--delete-stale`: the weekly schedule passes it automatically, while local and manual workflow runs only prune when the flag/input is set. Even when enabled it is skipped for `--limit`, targeted `--source-url`/`--source-file` runs, any sitemap/discovery error, skipped polling, item/upload failures, a build-error ratio above 2% of discovered URLs (the persistent sub-1% noise from binary attachment pages in the sitemaps does not block pruning), or a prepared-count regression versus the larger of the cached manifest length and the live count of distinct completed managed `source_url` values. A public endpoint validation failure is recorded as a warning and keeps the summary in a needs-attention state, but does not block pruning after the destructive-safety checks have passed. Targeted runs replace sitemap discovery and never delete, so they are safe for spot-checks. Because item keys include a short content hash, extraction or source-content changes create a new item generation; after a full healthy run uploads the desired generation, run a full `delete_stale` workflow or let the next healthy scheduled run prune older managed generations from the public corpus. The same full `delete_stale` pass also prunes previously managed release-cycle listing/archive pages such as `developer.wordpress.org/news/`, `developer.wordpress.org/news/all-posts/`, and `make.wordpress.org/core/`, because they are no longer part of the desired corpus.

The updater is **incremental**: it reads each sitemap's `<lastmod>` and skips re-fetching any page whose newest existing corpus item was already crawled at or after that timestamp (matched by `source_url` and the latest `retrieved_at`). Only new or changed pages are fetched and uploaded, which keeps the weekly run well inside the Actions timeout and gentle on developer.wordpress.org / make.wordpress.org (a full re-fetch of the ~13k discovered URLs otherwise overruns the job). Page fetches and Cloudflare API requests retry transient `5xx`/`429` responses; Cloudflare retries respect `Retry-After` when present. Pass `--full` (or `--force-refetch`, or the workflow's `full` input) to bypass the skip and re-fetch every discovered URL. Note that re-fetched pages whose content hash is unchanged are still not re-uploaded, so a change to the stored document layout (frontmatter/body framing) only propagates when `DOC_LAYOUT_VERSION` in `scripts/update-docs-ai-search.js` is bumped — the version is folded into every content hash, which mints new item keys and forces re-upload. Bump it together with extraction/Markdown changes, dispatch one `full` run, and prune the superseded generation with a follow-up `delete_stale` run or the next healthy scheduled run. Conditional HTTP requests are not used because developer.wordpress.org pages return no `Last-Modified`/`ETag`.

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
4. Keep supporting sources such as Make/Test, Make/Playground, Make/AI, and Gutenberg GitHub releases in separate research snapshots. They can help humans decide what to ingest, but they are not part of the ingestion allowlist (`TRUSTED_ROOTS` in `scripts/update-docs-ai-search.js`).
5. Remove or deprioritize superseded release-cycle source entries when their `published_at` date can no longer pass the freshness windows or WordPress 7.0 release-date rule below. Retaining them as historical search context is acceptable only if current release-cycle sources still rank for the validation query.
6. After ingestion, run both an MCP search smoke check and the public endpoint validation query. Confirm both the source mix and the freshness metadata match this runbook before trusting the refreshed corpus.

## Source Eligibility

Stable Developer Docs sources qualify when the canonical URL is under one of these scopes:

- `developer.wordpress.org/block-editor/`
- `developer.wordpress.org/rest-api/`
- `developer.wordpress.org/themes/`
- `developer.wordpress.org/reference/`

Current release-cycle and cutting-edge developer update sources qualify only when the canonical URL is under one of these scopes:

- `developer.wordpress.org/news/`
- `make.wordpress.org/core/`

For corpus-currency validation, `make-core` chunks count as current when they are published within 21 days of validation, and `developer-blog` chunks within 45 days. For WordPress 7.0, a `make-core` or `developer-blog` chunk also counts when its `published_at` is on or after the May 20, 2026 public release date. Recrawling an older release post does not make it current. Stable `developer-docs` chunks may use crawl freshness and count as current for 90 days.

The corpus may include additional official WordPress project sources for agent research, but keeping the desired corpus inside the scopes above is the ingestion script's job (`TRUSTED_ROOTS` in `scripts/update-docs-ai-search.js`); at runtime `inc/Support/DocsGroundingSourcePolicy.php` only labels chunks for display and prompts. Do not widen the ingestion allowlist unless the updater, its tests, and this runbook are updated together.

## Chunk Metadata

Every ingested item or chunk should preserve enough provenance for `inc/Cloudflare/AISearchClient.php` to normalize and validate it:

- `source_url` or equivalent metadata resolving to the canonical HTTPS URL
- `retrieved_at` as the crawl timestamp
- `published_at` for Make/Core and Developer Blog posts
- `content_hash` for change detection
- a stable title
- a source key that is either reconstructable to the canonical URL (`<host>/<path>` or `ai-search/<instanceId>/<host>/<path>/<hash>/part-0001.md`), or a bounded managed key `ai-search/<instanceId>/<host>/<slug>/<short-hash>/part-0001.md` whose `<host>` segment matches the canonical URL host. Deep developer-docs URLs exceed Cloudflare's 128-byte item-filename limit, so their keys carry a truncated slug plus short hash and rely on the metadata `source_url` for provenance.

Source URLs must be HTTPS, must not include credentials, must not use a non-443 port, and must not contain encoded path delimiters or `.` / `..` path segments. Source keys are only a URL-derivation fallback for chunks with no explicit metadata/frontmatter URL: Flavor Agent derives URLs solely from keys inside the managed `ai-search/<instanceId>/` namespace, and a chunk that resolves no structurally valid URL at all is dropped. A chunk that carries a valid explicit URL is kept regardless of its source-key namespace.

Refresh cadence:
- Weekly during active WordPress major-release cycles.
- Within 48 hours of a Make/Core Field Guide, dev note batch, RC post, or Gutenberg release post.
- Monthly outside active major-release cycles.

Corpus validation:
- Run the validation query: `WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes`.
- Replace `WordPress 7.0` in the validation query with the active major-release label whenever the release cycle changes.
- In an agent session with the MCP server available, run the same query through `wordpress-docs-ai-search` and confirm the returned chunks include the stable docs plus current-cycle sources expected below.
- Query the public endpoint with the same request shape used by `AISearchClient::build_search_request_body()`: a user message containing the validation query and `ai_search_options.retrieval.max_num_results` set to at least `4`.
- Confirm at least one `developer-docs` chunk and at least one `make-core` or `developer-blog` chunk.
- Confirm release-cycle chunks from `make.wordpress.org/core` or the Developer Blog include a qualifying `published_at`: within the rolling freshness window, or on/after May 20, 2026 for WordPress 7.0. A recent `retrieved_at` crawl timestamp does not make an older release-cycle post current. Stable handbook/reference chunks from `developer.wordpress.org` may use `retrieved_at` for crawl freshness because those pages represent maintained reference material rather than dated release-cycle posts.
- Record the observed `retrieved_at`, `published_at`, source URLs, and result count in the release notes or verification log.
- Record the validation evidence under `docs/validation/`; this runbook is the decision record for corpus refreshes.

Runtime behavior:
- Grounding is best-effort: each recommendation runs one cached corpus search (`AISearchClient::maybe_search_best_effort`, 6-hour query cache, bounded timeout). A transport failure attaches no guidance, records an `ok`/`unreachable` signal in `flavor_agent_docs_runtime_state`, and never blocks the recommendation; the editor shows a soft "running without docs grounding" notice.
- There is no coverage probe, release-cycle gate, grace window, or 503 path at request time. Corpus currency is validated by the workflow above at ingestion/refresh time, not per request.
- Validation paths (`AISearchClient::validate_configuration()` and the Settings page) still query the endpoint directly so admins can act on corpus drift.

## MCP search endpoint behavior

The `wordpress-docs-ai-search` MCP server points agents at the same corpus through the `/mcp` endpoint (sibling to the plugin's `/search` endpoint on instance `wp-dev-docs` / `https://101d836c-480b-4b39-b14e-505a6aa58f47.search.ai.cloudflare.com/mcp`). That endpoint behaves differently from the `/search` request the plugin builds, and agents must account for it (verified 2026-06-08):

- **`max_num_results` is ignored.** Requests at both `ai_search_options.max_num_results` and the Cloudflare-documented `ai_search_options.retrieval.max_num_results` returned the default (~10) chunk count regardless of the requested cap.
- **`filters` are ignored.** An impossible `source_url` equality filter returned the full unfiltered result set instead of zero matches.
- Results are **not de-duplicated by source** (several chunks of one page can appear), and exact-symbol retrieval depends on the instance staying configured with query rewriting disabled. Legacy unpruned corpora may still return archive/index pages (`/news/`, `/news/all-posts/`) until a healthy full `delete_stale` run removes them. The updater sends `rewrite_query: false` because the previous rewrite path substituted rare developer identifiers such as `wp_register_ability` with more common but wrong symbols. Prefer exact function, class, hook, package, and slug terms (`wp_register_ability`, `WP_Abilities_Registry`, `@wordpress/data`, etc.) when querying the MCP server.

Consequences:

- Agents consuming the MCP server should weigh source scope and `published_at`/`retrieved_at` currency themselves and must not rely on server-side bounding or filtering; the corpus allowlist lives in `scripts/update-docs-ai-search.js`, and the plugin's runtime policy only labels sources.
- The plugin's recommendation grounding is unaffected: it queries `/search` with `ai_search_options.retrieval.*` and applies structural URL hygiene plus non-gating source labels in PHP (`DocsGroundingSourcePolicy` + `AISearchClient::normalize_chunks()`), independent of any Cloudflare-side filtering. De-duplication is intentionally not applied in `normalize_chunks()` because distinct chunks of the same current item usually carry different excerpts. Cross-generation duplicate items are corpus hygiene issues: clear them with a healthy full `delete_stale` updater run rather than client-side chunk filtering inside the plugin.
