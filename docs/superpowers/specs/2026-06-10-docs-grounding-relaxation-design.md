# Docs Grounding Relaxation — Design

- **Date:** 2026-06-10
- **Status:** Approved (design); pending implementation plan
- **Branch:** `relax-docs-grounding-gate`
- **Owner:** Henry Perkins

## Problem

Developer-docs grounding currently gates recommendations behind a stack of runtime
knobs — synchronous-warm timeouts (5s/8s/20s), a freshness/currency status machine
(`grounded`/`degraded`/`stale`/`unavailable`), a coverage-probe subsystem, a
last-known-current "grace" window, and a runtime trusted-source re-classifier. When
any of these is unsatisfied the executor **fail-closes** and the ability returns
HTTP 503 (`flavor_agent_docs_grounding_unavailable`): _"Flavor Agent could not verify
current WordPress developer guidance for this recommendation."_

Observed on `wp-hperkins-com` (2026-06-10): the AI Search index is large and current,
yet every recommendation hard-blocks. Root cause is a compound of these knobs — the
5s synchronous foreground warm times out on a large corpus while the 8s probe / 20s
async warm succeed, and the 6-hour grace anchor is permanently frozen because the
corpus returns date-less chunks (freshness always `unknown`, never `grounded`, so the
anchor never refreshes). Two independent knobs combine to produce a hard failure on a
healthy index.

The runtime machinery also **duplicates** work the ingestion script already does.
`scripts/update-docs-ai-search.js` is the single source of trust and currency: it
restricts ingestion to trusted WordPress hosts/paths (`isTrustedPath`,
`sourceRootsForRelease`), bounds release-cycle freshness (`--make-core-max-age-days`),
and stamps `published_at` / `retrieved_at`. The runtime's `classify_url` /
`TRUSTED_SCOPES` and per-source freshness windows re-litigate that same trust and
currency at query time.

## Goal

Docs grounding becomes **best-effort prompt context**. It enriches a recommendation
when the corpus returns chunks and is simply absent when it can't. The **only** thing
that stops grounding is the backend actually being down (transport failure /
unreachable), and even then the recommendation still runs — grounding never blocks a
recommendation again.

Trust and currency are owned by the ingestion script. The runtime does not re-grade
them.

## New contract

For every recommendation surface:

1. Build one query, run **one** synchronous best-effort search against the corpus with
   a **single** timeout. Reuse the existing 20s request default rather than introduce a
   new tunable — it is a give-up ceiling, not a typical wait (a healthy backend returns
   in a couple seconds); on exceed, proceed without grounding.
2. Got chunks → attach them to the prompt as grounding context. Done.
3. Empty result or transport error (backend down) → grounding is absent; the
   recommendation **proceeds without it**. Surface a single soft, non-blocking
   "docs grounding temporarily unavailable" operator signal. **No 503, ever.**
4. A short-TTL result cache avoids re-hitting the backend for identical queries. It is
   a performance optimization only — it never gates.

There is no `grounded`/`degraded`/`stale`/`unavailable` status, no freshness grade, no
coverage probe, no grace window, no runtime trust re-classification, and no
sync/async/probe timeout trio.

### Backend-down behavior (confirmed)

When the backend is down, the recommendation proceeds **without** grounding rather than
blocking. "The only thing that should stop docs grounding from working is if it's
down" — docs stop, recommendations do not.

## Components

### Delete

- **The gate.** `DocsGuidanceResult::unavailable_error()` and the
  `if ( ! is_actionable( $docs_result ) ) return unavailable_error()` blocks in all six
  gate sites: `BlockAbilities.php:103`, `PatternAbilities.php:565`,
  `StyleAbilities.php:139`, `NavigationAbilities.php:83`,
  `TemplateAbilities.php:96` and `:227`. Also `DocsGuidanceResult::is_actionable()` and
  the `flavor_agent_docs_grounding_unavailable` error/action.
- **Freshness + status grading.** `DocsGuidanceResult::resolve_status()` and the whole
  `grounded`/`degraded`/`stale`/`unavailable` vocabulary;
  `DocsGroundingSourcePolicy::freshness_status()` + per-source max-age windows +
  `CURRENT_RELEASE_PUBLIC_DATE`; the `coverage`/`requireCurrentSourceCoverage`/
  `coverage_indicates_hard_block` paths.
- **Coverage-probe subsystem** in `AISearchClient`: `get_current_source_coverage()`,
  `requires_current_source_coverage()`, `probe_current_source_coverage()`,
  `SOURCE_COVERAGE_*` constants/cache, and the
  `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` constant +
  `flavor_agent_docs_grounding_require_current_coverage` filter.
- **Grace anchor.** `lastKnownCurrentAt` / `lastKnownCurrentGuidance` /
  `LAST_KNOWN_CURRENT_GRACE_TTL` / `get_last_known_current_guidance_for_grace()`.
- **Runtime trust re-classification.** `DocsGroundingSourcePolicy::classify_url()`,
  `TRUSTED_SCOPES`, `is_trusted_url()`, `is_allowed_guidance_source()`,
  `source_key_matches_trusted_host()`. The corpus is trusted at ingestion.
- **The warm/timeout machinery.** The 5s/8s/20s timeouts, the foreground-warm lock,
  the family/entity/generic cache layers and their builders, the async warm queue +
  `flavor_agent_warm_docs_context` cron, and docs prewarm
  (`prewarm`/`schedule_prewarm`/`should_prewarm`/`get_prewarm_state` and their
  lifecycle wiring). Collapse to one synchronous search + one simple result cache.

### Simplify (keep the field, drop the knobs)

- **`AISearchClient`** reduces to: `is_configured()`, `configured_instance_id()`,
  `validate_configuration()`, a single best-effort `search`/`maybe_search` (one 20s
  timeout; reads/writes the existing exact-query cache, `CACHE_TTL` 6h), and a minimal
  runtime signal (below). The large warm/prewarm/coverage public surface is removed
  along with its callers.
- **`DocsGuidanceResult`** keeps `from_guidance()` (now: normalize chunks + compute a
  content fingerprint, no grading), `guidance()`, and a collapsed `public_summary()`.
  `public_summary()` returns the status-free shape
  `{ available: bool, sourceTypes: string[], count: int }`. The `status` enum is gone.
- **`docsGroundingFingerprint`** stays in the ability output schema
  (`Registration.php:769`, `:839`) and the resolved-context signature, but is now a
  **content hash of the attached guidance only** (no policy/coverage/status inputs). The
  `RecommendationResolvedSignature` composition is otherwise unchanged, so drift
  detection at apply/undo still works — it now keys on "did the attached guidance
  change," which is the part that actually affected the prompt.
- **`CollectsDocsGuidance::collect()`** simplifies to a single cached search. With the
  family/entity caches gone, it needs only the query builder; the `build_entity_key` /
  `build_family_context` callables and the per-ability builders for them are removed.
  The `CoreRoadmapGuidance` merge is **kept** unchanged (it does not gate).
- **Settings runtime signal.** `AISearchClient::get_runtime_state()` and the
  `Admin/Settings/State.php` docs section (lines ~413-463) collapse from the
  status/freshness machine to a minimal "ok / backend unreachable" indicator derived
  from the last search outcome. The "degraded / freshness unknown" copy is removed.
- **JS notices.** `DocsGroundingNotice`, `utils/docs-grounding-warning.js`, and the
  `docsGrounding` branches in `executable-surfaces` / `executable-surface-runtime` /
  `BlockRecommendationsPanel` / `PatternRecommender` / `StyleBookRecommender` /
  `TemplateRecommender` / `store/index.js` drop the `stale`/`degraded`/`unavailable`
  handling, keeping at most a single soft "temporarily unavailable" notice.

### Keep (untouched)

- The per-request `request_diagnostic` activity row (governance attribution).
- The roadmap-guidance merge (`CoreRoadmapGuidance`).
- Provider routing, response schemas, operation validators, the resolved/review
  signature mechanism itself (only its docs-grounding input is simplified).

## Error handling

- A best-effort search never throws into the recommendation flow. Transport errors and
  empty results both resolve to "no guidance attached," and the recommendation
  continues normally.
- The ability path no longer returns `WP_Error` for any docs-grounding condition. The
  only `WP_Error`s a recommendation can return are the pre-existing provider/validation
  ones, unrelated to grounding.

## Testing strategy

- **PHPUnit.** Rewrite `DocsGuidanceResultTest`, `AISearchClientTest`, and the
  docs-grounding cases in `BlockAbilitiesTest`, `PatternAbilitiesTest`,
  `StyleAbilitiesTest`, `NavigationAbilitiesTest`, `TemplateAbilitiesTest`. New
  assertions: empty/backend-down search → recommendation proceeds with no guidance and
  **no `WP_Error`**; non-empty search → guidance attached + a content fingerprint
  present; the `unavailable_error`/503 path no longer exists.
- **JS (Jest).** Update `executable-surfaces` / `executable-surface-runtime` /
  `StyleBookRecommender` suites to drop the `unavailable`/`stale` UI branches and assert
  the soft-notice-only behavior.
- **Gates.** Cross-surface change → run `node scripts/verify.js --skip-e2e` and inspect
  `output/verify/summary.json`; `npm run check:docs`; matching Playwright harnesses
  (`playground` for block/pattern/navigation, `wp70` for template/style surfaces).

## Docs / governance updates

- `docs/reference/governance-layer.md` — rewrite the "enforces the docs-grounding
  **fail-closed** rules" framing (line ~33) to the best-effort contract.
- `CLAUDE.md` — update the docs-grounding lifecycle / external-services notes that
  reference prewarm, coverage, and the runtime state machine.
- `docs/FEATURE_SURFACE_MATRIX.md` and `docs/reference/abilities-and-routes.md` — remove
  the 503 / `flavor_agent_docs_grounding_unavailable` documentation.

## Non-goals / out of scope

- **Corpus quality.** The date-less / duplicate-generation chunks are an ingestion-side
  issue handled by `scripts/update-docs-ai-search.js` (delete-stale + re-ingest). This
  change makes the runtime indifferent to it; it does not fix the corpus.
- **Removing `docsGroundingFingerprint` / `docsGrounding` from the contract.** They stay
  (simplified) to bound blast radius and preserve the resolved-signature and ability
  output schemas. A later change could remove them entirely.
- **Roadmap guidance** behavior is unchanged.

## Rollout

Single branch, deletion-heavy. Verify with the cross-surface gates above, then a manual
pass on the nightly container and on `wp-hperkins-com` confirming recommendations run on
the current index with no 503 and a clean Settings docs indicator.
