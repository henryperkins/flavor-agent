# Recommendation Follow-ups Runtime Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement these tasks with TDD. The parent task owns integration review and browser/aggregate gates.

**Goal:** Implement explicit diagnostic dismissal and a bounded administrator fixture review export.

**Architecture:** Add explicit displayed identities to existing diagnostic observations, independently of the capped ranking snapshot. Require those identities, contextual permission, and fresh matching set/signature before a dismissal; derive a stable server ID for retry deduplication. Export a strict projection through a separate schema validator and server-owned provenance declaration, downloading a candidate and a separate restricted review record.

**Tech Stack:** PHP 8.2+, WordPress REST API, Gutenberg data/components, PHPUnit, Jest.

**Spec:** `docs/reference/recommendation-outcome-followups.md` at `b6e34b0`.

## Global Constraints

- Ranking remains static with respect to outcome history; no provider calls.
- Dismissal: explicit action only, `dismissed` / `user_dismissed`, diagnostic, nonterminal, not undoable.
- One observation per named shown suggestion; missing/stale/conflicting identities block rather than infer targets.
- Export: `manage_options`, explicit date/surface/rowLimit selection, maximum 1000 sampled rows, newest first, save lifecycle excluded from recommendation sample.
- Candidate: `recommendation-fixture-export-v1`, no identifying/raw text, URLs/paths, precise times, hashes/fingerprints, provider/model identifiers, or unknown keys. Aliases are fresh for each export.
- Trusted `FLAVOR_AGENT_FIXTURE_EXPORT_PROVENANCE` declares independently verified origin/SHA/public versions; absent or mismatched provenance blocks export.
- Exact UTC timestamp cutoffs are supported in the same date fields; exporter provenance does not certify historical row-generation versions.
- No new dismissal metric names in exporter v1. Its v1 metrics keep explicit numerator, denominator and coverage.
- Commit explicit own-file allowlists only; no push, PR, deployment, shared Docker changes, or publication.

## Review Focus

- Panel collapse/unmount, navigation, refresh and no interaction must produce no dismissal.
- Capped ranking snapshots and collapsed/advisory overflow must never become full shown identity evidence.
- Retries with different client row IDs and permission/owner mismatches must not duplicate or disclose rows.
- Nested arbitrary keys, malformed finite scores, unknown schema/vocabulary and malicious text must be omitted/rejected.
- Missing provenance and zero metric denominators must stay unavailable; local custody data must remain outside the candidate.
- Distinct canonical tuples must not collide on delimiters or truncation, and pending persistence is never confirmed success.
- New server generations have distinct sets across editor sessions; cached replays of the same generation retain their identity. The existing client session scopes the request-token fallback.

### Task 1: Canonical dismissal observations and reporting

**Files:** `inc/Activity/RecommendationOutcome.php`, `Repository.php`, `RecommendationOutcomeMetrics.php`, `GovernanceLearningReport.php`; `src/store/recommendation-outcomes.js`, `src/store/index.js`; nearest PHPUnit/Jest tests.

**Interfaces:** `shownSuggestionKeys` contains explicit displayed identities independently of `topSuggestionKeys`/`rankingSet`. `dismissRecommendationSuggestions({ surface, suggestions, currentRequestSignature, isStale, document, target })` records one diagnostic per strictly matching visible suggestion. `Repository::create()` verifies latest matching shown-set observations in the canonical scope before storing dismissal.

- [ ] Write regressions for canonical identities, fixed generic diagnostic, missing/stale/absent shown identities, changed IDs on replay, permission boundaries, and dismissal denominator isolation.
- [ ] Run focused PHPUnit/Jest regressions; expect new dismissal assertions to fail on missing feature.
- [ ] Add catalogs/strict identity projection, full shown keys, server deduplication/freshness checks, client action and separate dismissal counts/coverage in runtime report.
- [ ] Run focused tests; expect all assertions to pass without ranking changes.

### Task 2: Visible explicit dismissal UI

**Files:** create `src/components/RecommendationDismissal.js` and tests; integrate existing block/template/template-part/style/pattern/navigation cards where canonical identities are available.

**Interfaces:** `RecommendationDismissal({surface, suggestions, isStale, currentRequestSignature, target})` records only mounted displayed suggestions as shown and dispatches Task 1's explicit dismissal action on click. Later review/apply stays available.

- [ ] Write UI regressions for explicit click, passive mount/unmount, stale/absent identity disabling, retry and unchanged apply/review controls.
- [ ] Run tests; expect missing UI/action failure.
- [ ] Implement shared control inside displayed cards/lanes, including advisory children so hidden overflow is not recorded.
- [ ] Run nearest surface/component suites; expect fresh action works and stale/noninteraction produces no dismissal.

### Task 3: Strict bounded local fixture review export

**Files:** create `inc/Activity/RecommendationFixtureExport.php`, `RecommendationFixtureSchema.php`, `FixtureExportProvenance.php`, `inc/REST/FixtureExportController.php`, PHP tests; create `src/admin/FixtureExport.js` and tests; bootstrap route and Activity UI; update runtime docs.

**Interfaces:** `RecommendationFixtureExport::build($entries,$selection,$provenance)` produces a candidate or error; `RecommendationFixtureSchema::validate($candidate)` rejects unknown keys/types/bounds/enums; `FixtureExportProvenance::resolve()` accepts server constant only. `POST /flavor-agent/v1/activity/fixture-export` accepts only reviewed date/surfaces/rowLimit selection and returns `{candidate}`. The browser creates a separate restricted review record from local custody fields and downloads `{candidate,reviewRecord}`. Custody values never enter the export request or candidate.

- [ ] Write regressions for privilege denial, strict selection/provenance, bounded newest-first save-excluded sample, fresh aliases/equal within-export joins, unknown schemas, nested text/secret keys, zero denominators and incomplete joins.
- [ ] Run tests; expect missing exporter failure.
- [ ] Implement strict projection/validation, bounded repository sample, provenance checks, candidate metric populations, restricted review metadata and explicit local-download UI.
- [ ] Run all PHPUnit/Jest suites, build, JS/PHP lint and docs checks; retain results and flag any unavailable browser/aggregate gate for parent integration.
- [ ] Self-review privacy and contracts, then commit explicit allowlisted files and return exact SHA/test evidence to parent for independent review.
