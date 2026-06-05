# Docs-Grounding Coverage Gate: Survivability Design

- **Date:** 2026-06-02
- **Status:** Implemented; historical design context. The shipped implementation plan is archived at `docs/superpowers/plans/archive/2026-06-02-docs-grounding-gate-survivability.md`, and current behavior is documented in `docs/reference/developer-docs-public-corpus-runbook.md` and `docs/features/settings-backends-and-sync.md`.
- **Scope:** Shared subsystem (docs grounding) — affects every recommendation surface that gates on `DocsGuidanceResult::is_actionable()`
- **Related:** Commit `5b82ab2` (grace window + decoupled gate diagnostics) — this design supersedes the coverage-gate half of it.

## Problem

When `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` is enabled, recommendation requests that reach the docs-grounding gate return `flavor_agent_docs_grounding_unavailable` (HTTP 503) whenever the AI Search corpus lacks a **current** release-cycle source.

Root cause (reproduced live, 2026-06-02): `DocsGroundingSourcePolicy` marks `hasCurrentReleaseCycle = true` only if a `make-core` or `developer-blog` chunk is `current` freshness, where make-core/dev-blog freshness is computed from `publishedAt` against a **21-day** ceiling. make-core dev-notes are **bursty** — clustered around releases, then quiet for weeks — so during a normal between-release lull, no make-core post is < 21 days old and the gate hard-blocks, even though trusted developer-docs are present and current.

The grace window added in `5b82ab2` cannot help: it seeds a "last-known-current" snapshot **only** when a probe returns `current`, so a corpus that was never `current` (cold start) has nothing to grace from, and even a seeded grace expires after 7 days — shorter than ordinary make-core gaps.

## Goal

Make the coverage requirement **survivable**: a missing-current-release-cycle corpus must not dead-end recommendations. Preserve honesty by **signalling** the gap (the existing degraded warning) rather than **blocking**.

## Non-goals

- Changing make-core/dev-blog/developer-docs freshness ceilings or the `publishedAt` basis (`DocsGroundingSourcePolicy::freshness_status`). Unchanged.
- Touching the last-known-current **guidance-serving** fallback (`LAST_KNOWN_CURRENT_GRACE_TTL`, `get_last_known_current_guidance_for_grace`, runtime `lastKnownCurrentAt`/`lastKnownCurrentGuidance`). This serves recently-current guidance *content* as a 6-hour fallback and is orthogonal to the coverage gate. Out of scope.
- Renaming `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` (back-compat). Redocument only.
- Building a separate opt-in "true hard-block on currency" mode. YAGNI — not until something needs it.
- The insertion-context "no inserter patterns" message (the other half of the original report) — separate issue, separate spec.

## Decisions (locked)

1. **Degrade-to-warn** when the sole deficiency is `missing-current-release-cycle`.
2. **Drop the coverage-gate grace machinery** entirely (it no longer gates anything).
3. **No currency hard-block** — the coverage requirement never produces `unavailable` for the currency dimension.

## Behavior matrix

`status` is the value `DocsGuidanceResult::resolve_status()` returns; `is_actionable()` is true for `grounded`/`degraded`/`stale`, false for `unavailable`.

| Coverage situation | Before | After |
| --- | --- | --- |
| No official guidance at all (`! has_official_guidance`) | `unavailable` (block) | `unavailable` (block) — unchanged |
| `current` | grounded/degraded/stale by freshness | same |
| **`missing-current-release-cycle`** (developer-docs present) | **`unavailable` (503)** | **freshness-resolved (grounded/degraded/stale) + coverage warning** |
| `missing-developer-docs` (make-core/dev-blog only, no stable docs) | `unavailable` (block) | `unavailable` (block) — see Open Decision 1 |
| `unavailable` (coverage probe transport error) | `unavailable` (block) | `unavailable` (block) |
| coverage not required (constant off) | freshness-resolved, no probe | unchanged |

The coverage warning rides along automatically: `DocsGuidanceResult::public_summary()` carries `coverage.status`, and the client's `normalizeDocsGroundingWarning()` raises a warning whenever `coverage.status` ∉ {`current`,`unknown`} regardless of top-level status — except when top-level status is `unavailable` (suppressed). The message already exists (`getDocsGroundingWarningMessage`, the `missing-current-release-cycle` branch). **No client change required.**

## Constant redefinition

`FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` (and the `flavor_agent_docs_grounding_require_current_coverage` filter) shift meaning:

- **Before:** "hard-block recommendations when current release-cycle coverage is missing."
- **After:** "probe for release-cycle coverage and **surface a warning** when it's missing." It no longer blocks for the currency dimension. Genuine outages (no trusted docs, transport error) still produce `unavailable` independent of this flag.

Consequence: the dev container's `wp-config.php:131` setting can stay as-is — it now enables the warning instead of the block. No environment change needed for the fix to land.

## Changes

### 1. `inc/Support/DocsGuidanceResult.php` — the one behavioral change

`resolve_status()`: replace the blocking coverage branch

```php
if ( $requires_coverage && ! self::coverage_satisfies_required_gate( $coverage ) ) {
    return 'unavailable';
}
```

with a hard-block test limited to genuinely-unusable coverage:

```php
if ( $requires_coverage && self::coverage_indicates_hard_block( $coverage ) ) {
    return 'unavailable';
}
```

Add `coverage_indicates_hard_block(array $coverage): bool` returning true only for coverage `status` ∈ {`missing-developer-docs`, `unavailable`} (see Open Decision 1). `missing-current-release-cycle` and `unknown` fall through to the freshness ladder.

Remove:
- `coverage_satisfies_required_gate()` (sole caller was the line above).
- Grace fields `withinGrace`, `graceLastKnownCurrentAt`, `graceExpiresAt` from `normalize_coverage()` and from the `coverage` block of `fingerprint()`.

### 2. `inc/Cloudflare/AISearchClient.php` — remove coverage-gate grace (mechanism A)

- Delete methods: `maybe_decorate_with_coverage_grace()`, `read_last_known_current_coverage_snapshot()`, `write_last_known_current_coverage_snapshot()`.
- `get_current_source_coverage()`: drop the four `maybe_decorate_with_coverage_grace( … )` wrappers (return the normalized summary directly).
- `write_source_coverage_cache()`: remove the `if ( 'current' === … ) write_last_known_current_coverage_snapshot()` call.
- `normalize_source_coverage_summary()`: remove `withinGrace`, `graceLastKnownCurrentAt`, `graceExpiresAt` keys.
- Delete constants: `SOURCE_COVERAGE_GRACE_TTL`, `SOURCE_COVERAGE_LAST_KNOWN_CURRENT_OPTION`.
- **Keep** mechanism B unchanged: `LAST_KNOWN_CURRENT_GRACE_TTL`, `get_last_known_current_guidance_for_grace()`, runtime `lastKnownCurrentAt`/`lastKnownCurrentGuidance`, and the coverage probe/cache (`SOURCE_COVERAGE_CACHE_KEY`, probe query, current/negative/error TTLs) — the latter still computes and attaches the warning.

### 3. Remove the now-dead gate-block diagnostic

The "decoupled gate diagnostics" surface was keyed to `missing-current-release-cycle`, which no longer blocks — so the recorder fires for nothing the UI reads, and the Settings warning condition can never be true.

- `inc/Cloudflare/AISearchClient.php`: delete `record_coverage_gate_blocked()` and the `lastCoverageGateBlockedAt/Status/Reason/InGrace` fields from runtime-state defaults and projections (`get_runtime_state`, the public projection, the writer).
- `flavor-agent.php:132-133`: remove the `add_action( 'flavor_agent_docs_grounding_unavailable', [ AISearchClient::class, 'record_coverage_gate_blocked' ] )` registration.
- `inc/Admin/Settings/State.php:457-471`: remove the release-cycle coverage-gate Settings warning block.
- The `flavor_agent_docs_grounding_unavailable` action itself **stays** (still fired by `DocsGuidanceResult::unavailable_error()` for genuine outages); we only remove our listener.

### 4. Ability output-schema contract — `inc/Abilities/Registration.php`

The shared docs-grounding output schema (`Registration.php:565-587`, `additionalProperties: false`) declares `coverage.withinGrace` (boolean), `coverage.graceLastKnownCurrentAt` (string), `coverage.graceExpiresAt` (string). Remove these three properties so the declared contract matches the payload (which no longer carries them). Keep `hasCurrentReleaseCycle` — the policy still computes it. This is the REST/abilities contract change that triggers `npm run check:docs`.

### 5. Orphaned-data cleanup

Add `flavor_agent_docs_source_coverage_last_known_current` to `inc/UninstallOptions.php::names()` so a clean uninstall removes the now-unwritten snapshot option. On a live upgrade the option simply lingers unread until uninstall (harmless); the `flavor_agent_docs_source_coverage_v2` transient and `lastCoverageGateBlocked*` runtime-state keys lapse/age-out by TTL and next write. No migration routine required.

## Resolved decision

**OD-1 — `missing-developer-docs` handling. RESOLVED 2026-06-02: keep the narrow block.** `missing-developer-docs` (make-core/dev-blog present but no stable developer-docs) and coverage-probe transport `unavailable` remain hard-blocks via `coverage_indicates_hard_block()`. Only `missing-current-release-cycle` (and `unknown`) degrade-to-warn. This matches `5b82ab2`'s principle that stable developer-docs are the required backbone and preserves a meaningful `unavailable` floor; "no hard-block" applied to the *currency* dimension only.

## Testing

- `tests/phpunit/DocsGuidanceResultTest.php`: add `requires_coverage = true` + `missing-current-release-cycle` (developer-docs present) ⇒ actionable, not `unavailable`; assert `missing-developer-docs`/transport still `unavailable`. Remove grace-decoration and `coverage_satisfies_required_gate` assertions.
- `tests/phpunit/AISearchClientTest.php`: remove coverage-grace decoration / snapshot / `withinGrace` tests; keep mechanism-B (guidance grace) and probe/cache tests.
- `tests/phpunit/RegistrationTest.php`: drop assertions on the `record_coverage_gate_blocked` action wiring.
- `tests/phpunit/SettingsTest.php`: remove the release-cycle gate Settings-warning assertions.
- Ability tests asserting the 503 for this combo (e.g. `BlockAbilitiesTest.php` around the `require_current_coverage` filter; the parallel pattern test): change to assert **proceeds with a docs-grounding warning** instead of erroring.

## Verification gates

This is a shared-subsystem change (per `docs/reference/cross-surface-validation-gates.md`):

- Targeted PHPUnit: `DocsGuidanceResultTest`, `AISearchClientTest`, `SettingsTest`, `RegistrationTest`, and the pattern/block ability tests.
- Targeted JS: `docs-grounding-warning` and `DocsGroundingNotice` suites (assert no regression — behavior is reused, not changed).
- `node scripts/verify.js --skip-e2e`, inspect `output/verify/summary.json`.
- `npm run check:docs` — contracts/surfacing rules change (constant semantics, removed Settings warning).
- Docs to update: `docs/reference/developer-docs-public-corpus-runbook.md`, `docs/features/settings-backends-and-sync.md`, `docs/reference/external-service-disclosure.md` (all touched by `5b82ab2`); note the constant's new meaning and the removed grace window/Settings warning.
- Live re-confirm in the dev container: with the constant still on, a pattern request at a valid insertion point returns recommendations with the degraded coverage warning (no 503).

## Out of scope (restated)

- Mechanism B (guidance-serving fallback) — keep.
- Freshness ceiling / `publishedAt` basis — unchanged.
- Constant rename; separate true-hard-block mode — not now.
- Insertion-context empty-pattern message — separate spec.
