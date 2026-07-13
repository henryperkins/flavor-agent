# Daily WordPress Docs/News Ingestion — Design

Date: 2026-07-12
Status: Approved (design review 2026-07-12)

## Goal

The public `wp-dev-docs` Cloudflare AI Search corpus refreshes **daily** instead of weekly, and additionally ingests **wordpress.org/news** (main WordPress News blog) and **make.wordpress.org/ai** alongside the existing sources. Stale-item pruning stays weekly. No new script: the existing pipeline (`scripts/update-docs-ai-search.js` run by `.github/workflows/update-docs-ai-search.yml`) is retuned. Both corpus consumers — the plugin's best-effort docs grounding (`inc/Cloudflare/AISearchClient.php`) and the `wordpress-docs-ai-search` MCP server — pick the changes up automatically because they query the same corpus.

## Decisions (user-confirmed)

1. **New sources:** `https://wordpress.org/news/` and `https://make.wordpress.org/ai/`. Existing sources (developer.wordpress.org handbooks/reference, Developer Blog at developer.wordpress.org/news, make.wordpress.org/core) are unchanged.
2. **Cadence:** daily incremental ingest without stale deletion; the Monday run keeps `--delete-stale` (weekly prune). Two cron entries on the existing workflow.
3. **Implementation approach:** surgical widening of the existing script functions — no source-registry refactor.

## Verified source facts (2026-07-12)

- `make.wordpress.org/ai` serves a standard `wp-sitemap.xml` index (posts, pages, handbook). Post permalinks are day-dated: `/ai/YYYY/MM/DD/slug/`. Existing root-relative sitemap seeding discovers it with no changes.
- `wordpress.org/news` has **no** `/news/wp-sitemap.xml` (404). Its sitemap is robots-advertised at `https://wordpress.org/news/sitemap.xml` — a Jetpack index nesting `sitemap-index-1.xml` → `sitemap-N.xml` (plus image/video sitemaps). Post permalinks are **month-dated**: `/news/YYYY/MM/slug/`.
- `https://wordpress.org/robots.txt` also advertises `https://wordpress.org/sitemap.xml`, `/news-sitemap.xml`, `/themes/sitemap.xml`, and `/plugins/sitemap.xml`. Discovery must not crawl these (huge, out of scope).

## Changes

### 1. `scripts/update-docs-ai-search.js`

- **Trusted roots:** add `https://wordpress.org/news/` and `https://make.wordpress.org/ai/` to `sourceRootsForRelease()`. Extend `isTrustedPath()`: host `wordpress.org` allows `/news` + `/news/…`; host `make.wordpress.org` allows `/ai` + `/ai/…` in addition to `/core`.
- **Recency gate:** generalize `makeCorePostDate()` / `withinMakeCoreWindow()` into a dated-post window covering:
  - `make.wordpress.org/core/YYYY/MM/DD/…` and `make.wordpress.org/ai/YYYY/MM/DD/…` (day-dated),
  - `wordpress.org/news/YYYY/MM/…` (month-dated; publish date = first of month, UTC).
  One shared window value. New canonical CLI flag `--recent-post-max-age-days`; `--make-core-max-age-days` remains as an accepted alias for the same option. Default stays 180; `0` disables the gate. Undated make-subsite pages (e.g. `/ai/handbook/…`) remain excluded when a window is set, matching today's `/core/handbook/` behavior. Explicit `--source-url` entries continue to bypass the gate.
  - **xpost skip:** dated make-subsite posts whose slug segment starts with `xpost-` are excluded from discovery (cross-post stubs, corpus noise).
- **Document gate:** extend `isCorpusDocumentUrl()` with `/ai/\d{4}/\d{2}/\d{2}/slug` (same shape as core) and `/news/\d{4}/\d{2}/slug` for host `wordpress.org`, so archive/category/listing pages never join the desired corpus.
- **Sitemap scoping:** extend the sitemap URL filter (`sitemapUrlWithinOrigins()` call sites) with optional per-origin **path prefixes**: for origin `https://wordpress.org`, only sitemap URLs whose path starts with `/news/` are crawled. All other origins keep today's origin-wide behavior (developer.wordpress.org's root `wp-sitemap.xml` must keep working). Consequences: `/news/sitemap.xml` and its Jetpack children qualify; root `/sitemap.xml`, `/news-sitemap.xml`, `/plugins/…`, `/themes/…` are dropped. Image/video sitemaps under `/news/` may be crawled; their page `<loc>` entries deduplicate harmlessly.
- **Unchanged:** upload/keying (`ai-search/<instance>/<host>/<slug>/<short-hash>/part-0001.md` already host-generic), manifest handling, `/stats` polling, all stale-deletion safety checks, retry/backoff, `DOC_LAYOUT_VERSION` (no layout change to existing documents — no bump, no `--full` run required).
- **Implementation checkpoint:** verify Markdown extraction quality for both new page themes during the dry run (make/ai is P2 like make/core; wordpress.org/news is a different theme). Add a host-specific selector branch only if the generic extraction is poor.

### 2. `.github/workflows/update-docs-ai-search.yml`

- `schedule:` becomes two entries: `17 5 * * 0,2-6` (every day except Monday: daily ingest) and `17 5 * * 1` (Monday, prune day).
- `INPUT_DELETE_STALE` for scheduled runs: `true` only when `github.event.schedule == '17 5 * * 1'`. Manual `delete_stale` input behavior unchanged (opt-in).
- Add `concurrency: { group: update-docs-ai-search, cancel-in-progress: false }` so a manual dispatch queues behind a running scheduled run instead of writing concurrently.
- Everything else (manifest cache with prefix restore-keys, 120-minute timeout, summary artifact) unchanged.

### 3. `inc/Support/DocsGroundingSourcePolicy.php`

- Add `SOURCE_MAKE_AI = 'make-ai'` (host `make.wordpress.org`, path starts `/ai/`) and `SOURCE_WORDPRESS_NEWS = 'wordpress-news'` (host `wordpress.org`, path starts `/news/`). `make.wordpress.org` otherwise still labels `make-core`; unknown hosts still fall back to `developer-docs`.
- Labels remain non-gating (display/prompt only). Wherever label strings surface (prompt text, Settings/source chips), add matching display strings for the two new labels.

### 4. Contract docs (move together, per the runbook's own rule)

- `docs/reference/developer-docs-public-corpus-runbook.md`: add the two source scopes (with dated-post window notes and the wordpress.org sitemap-scoping caveat); add Source Eligibility entries with a **21-day** validation-currency window for both `make-ai` and `wordpress-news` (parity with `make-core`, including the WordPress 7.0 release-date rule); rewrite the refresh-cadence section to "daily ingest / weekly prune (Monday)"; update the Source Update Workflow paragraph for the two-cron schedule; update the MCP corpus-scope statement.
- `CLAUDE.md`: extend the MCP-tooling corpus-coverage sentence with WordPress News and Make/AI.
- Validation minimums are **unchanged** (still ≥1 `developer-docs` chunk and ≥1 `make-core`/`developer-blog` chunk); presence of the new sources is recorded as evidence, not required.
- `npm run check:docs` must pass.

## Testing

- `scripts/__tests__/update-docs-ai-search.test.js`: trusted-path accept (news + make/ai) and reject (`wordpress.org/plugins/…`, `wordpress.org` root); day- vs month-dated window math incl. `0` disable; `isCorpusDocumentUrl` patterns for the new sources; wordpress.org sitemap path scoping (accept `/news/sitemap.xml`, reject root `/sitemap.xml`); `xpost-` skip; flag alias.
- PHPUnit: label mapping for the two new sources plus existing-label regression.
- Gates (cross-surface: tooling + shared `Support\` policy): targeted Jest + PHPUnit, then `node scripts/verify.js --skip-e2e` with `output/verify/summary.json` inspected, `npm run check:docs`. No E2E (no editor-behavior change).

## Rollout

1. Local `npm run docs:ai-search:update -- --dry-run --limit=40` — confirm both new sources are discovered and extraction reads well; no Cloudflare writes.
2. One manual workflow dispatch **without** `delete_stale` — verify the summary artifact, `/stats` settling, and a live public-endpoint search returning a fresh news or make/ai post.
3. Let the crons take over. The next Monday prune's safety checks tolerate corpus growth (only prepared-count *regressions* block deletion).

## Risks / accepted trade-offs

- Month-granularity dating on News admits posts up to ~1 month older than the nominal window — acceptable at a 180-day default.
- wordpress.org/news extraction quality is unproven until the dry run; the 2% build-error tolerance protects pruning if a subset of pages extracts poorly.
- Daily runs increase load on WordPress.org properties; incremental sitemap-lastmod skipping keeps page fetches to changed content only.

## Out of scope

- No digest/notification output — this is corpus ingestion only.
- No new Make subsites beyond `/ai` (runbook keeps make/test, make/playground etc. as research snapshots).
- No plugin runtime behavior changes (grounding timeout/messaging is a separate concern).
