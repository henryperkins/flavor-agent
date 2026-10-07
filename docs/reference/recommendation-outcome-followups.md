# Recommendation Outcome Follow-up Contracts

Contract version: v1. Status: approved documentation proposal, 2026-10-07. These contracts do not ship a dismissal action, fixture exporter, production dataset, or adaptive ranking. Runtime contracts remain in [abilities and routes](abilities-and-routes.md), [activity state machine](activity-state-machine.md), and [save persistence outcomes](../features/save-persistence-outcomes.md).

## Current Runtime Boundary

`inc/Activity/RecommendationOutcome.php` and `src/store/recommendation-outcomes.js` accept the existing recommendation and save outcome catalogs; both reject `dismissed`. The current admin `governance-learning-report-v1` is a bounded aggregate report, not a shareable fixture-export schema. Its representative activity IDs, provider/model labels, fingerprints, and timestamp are not automatically safe to export.

Ranking remains static with respect to outcome history. Current request/context signals and deterministic validation penalties can affect ranking; outcome reporting and collection must not influence scoring. Adaptive ranking requires separate consent, design, and evaluation evidence before implementation or use.

## Proposed Explicit Dismissal v1

A future explicit user action may emit `event: dismissed` with the fixed `reason: user_dismissed`. The action must name the `recommendationSetId` and the selected `suggestionKey` values. Dismissing a whole set names its visible suggestion IDs explicitly; it must not infer that unshown suggestions were dismissed. Missing or stale identities must block recording rather than guess a target.

- This is a diagnostic, non-executable, nonterminal outcome: `executionResult: diagnostic`, diagnostic visibility, `undo.canUndo: false`, and `undo.status: not_applicable`. It does not apply, undo, invalidate, or terminally reject the suggestion. Later review or apply remains possible after the usual freshness and validation checks.
- Deduplicate by surface, recommendation set ID, suggestion ID, `dismissed`, and `user_dismissed`. One action naming several suggestions produces one deduplicated observation per named suggestion; repeated actions/retries do not increase counts. A new recommendation set has a distinct identity.
- Closing a panel, navigation, timeout, refresh, or no interaction never counts as dismissal. Absence of a later event is unknown engagement, not rejection or inferred dismissal.
- Do not accept free-text dismissal reasons, prompts, suggestion text, attributes, or operations in the diagnostic. Use generic labels and the fixed reason only.
- No current route/UI/catalog accepts this proposed event. A future implementation must update both catalogs, identity/deduplication handling, permission checks, UI, and reporting contracts together and verify the explicit action versus passive noninteraction cases.

Proposed reporting keeps suggestion dismissal counts separate from set-level review/apply rates. A dismissal rate is distinct dismissed suggestions divided by explicitly shown, identifiable suggestions in the same bounded sample. Missing shown identities or capped ranking snapshots mean incomplete coverage; report the denominator and excluded count, never equate a top-three snapshot with all displayed suggestions. Dismissal does not enter apply-attempt, failure, or save-persistence denominators.

## Proposed Local Fixture Export Schema v1

The proposed schema identifier is `recommendation-fixture-export-v1`. Export is an explicit administrator action bounded by `manage_options`, a reviewed local date/surface selection, and a declared row limit. It writes a local review candidate only. Sharing, upload, production collection, or changes to ranking are separate actions. Export must build an allowlisted projection; it must never dump raw activity rows or use the existing activity response as an export payload.

The following table is the complete field-family allowlist. Every nested object rejects unknown/arbitrary keys. Optional fields are omitted when absent or unsafe; nulls or aliases must not invent evidence. A future implementation needs strict type, finite-number, enum, size, and bounds validation before emitting a candidate.

| Field | Allowed value and boundary |
|---|---|
| `schemaVersion` | Literal `recommendation-fixture-export-v1` |
| `provenance.kind` | Enum `synthetic_fixture`, `local_runtime`, or `real_site`; origin must be verified, not inferred from row shape |
| `provenance.implementationSha` | Exact reviewed implementation Git SHA; exporting docs SHA is not runtime provenance |
| `provenance.publicVersions` | Fixed keys `plugin`, `wordpress`, `gutenberg`, `ranking`, `validationVocabulary`, `report`; publicly released/versioned values only |
| `provenance.config` | Fixed keys `surfaces` (supported surface enums) and `rankingMode` (literal `static`); retain other configuration only in the local review record |
| `sample` | Fixed keys `rowLimit`, `eligibleRowCount`, `sampleSize`, `exportedRowCount`, `excludedRowCount`, `truncated`, `shownSetCount`, `shownSuggestionCount`, `unlinkedApplyCount`, `missingIdentityCount`; counts are nonnegative integers and truncation is boolean |
| `rows[].aliases` | Fixed keys `row`, `set`, `suggestion`, `generation`, `sourceSignature`, `guideline`, `docsContent`, `docsRuntime`, `linkedApply`, `saveOccurrence`; export-local aliases only, and only when the supported projection needs the join |
| `rows[].surface` | Current supported surface enum: `block`, `template`, `template-part`, `global-styles`, `style-book`, `pattern`, `navigation`, `content`, `post-blocks` |
| `rows[].event`, `rows[].reason` | Supported runtime outcome enums and fixed reasons from the reviewed implementation/vocabulary; `dismissed`/`user_dismissed` are reserved for the future implementation, not accepted as current observations |
| `rows[].state` | Fixed keys `executionResult`, `undoStatus`, `persistenceVerdict`, `verificationCoverage`; supported enums only, no payload or state snapshots |
| `rows[].ranking` | Fixed keys `rank`, `score`, `contextScore`, `contextEvidence`, `contextPenalties`; finite numeric values from the supported ranking contract only; no recomputed or inferred scores |
| `rows[].validationReason` | A code present in the reviewed public validation vocabulary; omit message text and arbitrary codes |
| `rows[].patternTraits` | Current bounded pattern-trait enums only |
| `coverage` | Fixed keys `surfaces`, `missingShownLinks`, `missingApplyLinks`, `unverifiedCoverageCount`; enum surface list and nonnegative counts only |
| `metrics[]` | Fixed keys `name`, `numerator`, `denominator`, `value`, `coverage`; metric name and coverage are reviewed enums, counts are nonnegative integers, value is finite or omitted for an unavailable denominator |

`contextEvidence` permits only `prompt_match`, `operation_fit`, `supports_fit`, `section_role_match`, `docs_freshness`, `pattern_readiness`, `visible_scope_match`, `native_preset_fit`, `accessibility_fit`, and `design_semantics_fit`. `contextPenalties` permits only `possible_no_op`, `weak_prompt_match`, `unsupported_control`, `stale_docs`, and `validation_risk`. Their names identify fixed numeric evidence, not prompt text. Future vocabulary additions require a reviewed schema version/update rather than passing arbitrary keys through.

Metric names are limited to `reviewSelectionRate`, `applyConversionRate`, `patternInsertionRate`, `undoRate`, `validationBlockedRate`, `insertFailedRate`, `savePersistedRate`, `saveDiscardedRate`, `saveUnverifiableRate`, and `verificationCoverageRate`. `metrics[].coverage` is limited to `complete_sample`, `missing_links`, or `no_denominator`; `complete_sample` means complete within the declared sample only. `eligibleRowCount` is omitted when the eligible total is unknown; neither `sampleSize` nor `rowLimit` substitutes for that total. Proposed dismissal metrics require a later schema revision with implemented catalog support.

Remap recommendation/set/generation/activity/save IDs and content-derived signatures or fingerprints to export-local aliases such as `set-1` and `suggestion-1`. Equal supported join identities map consistently within one export; aliases and their mapping never enable joins across exports. The reverse mapping stays local and is not shared. Site, user, entity, and request IDs are dropped entirely, not preserved under alias fields. Aliases must not contain original IDs, hashes, labels, or text fragments.

Drop prompts, generated/raw text, labels, descriptions, messages, attributes, operations, provider bodies, provider/model free-form identifiers, site/user/entity/request IDs, private keys, credentials, URLs/paths, save contents/snapshots, precise timestamps, and all unknown/arbitrary keys. Private guideline versions and content/docs fingerprints become aliases rather than public version strings. Alias remapping does not make raw/free text safe; validate before sharing.

## Review, Provenance, and Coverage

A local review record must name the source custodian/site, collection period, selected surfaces, implementation SHA, runtime/public versions, non-secret configuration, collection permission/scope, selection/filter/order rules, row limit, exclusions, and reviewer decision. Keep site identity and collection dates in that restricted record, outside the shareable fixture projection. The shared export links to its review record through local review custody, not a site URL or identifying string inside rows. Review provenance and privacy separately from schema validity; a valid JSON projection alone is insufficient approval to share.

1. Declare the eligible population and bounded sample before selecting rows. Record date/surface filters, newest-first ordering, save-lifecycle exclusion from the recommendation sample, row cap, eligible/sample/exported/excluded counts, and truncation. Record whether the eligible total is unknown; do not manufacture a total from the cap.
2. Apply the fixed projection and local aliases, validate all values, and inspect the full candidate for leaked identifiers, text, keys, timestamps, and secret-bearing metadata. Exclude unsafe rows/fields and record exclusion counts. Never redact by merely hashing free text.
3. Review linked shown/review/apply/undo/save coverage and missing joins. Publish metric numerator and denominator definitions and counts alongside values. A zero denominator is unavailable evidence even where current runtime reports `0.0`; preserve that runtime convention honestly without describing it as observed success/failure.
4. An administrator/reviewer approves the concrete candidate and provenance for the intended recipient/use before sharing. Keep the reviewed candidate, approval record, and artifact digest together locally. An unreviewed candidate is not a published dataset.

Current recommendation metrics use distinct surface/set identities for shown/review rates; apply conversion intersects linked applied sets with sampled shown sets; validation blocking uses distinct attempted events. Undo uses apply rows, and insertion failure uses recorded pattern success/failure attempts. These populations differ. Boundary rows may lack their earlier shown/review counterpart; flag incomplete joins rather than infer missing observations or combine denominators. Save verification metrics retain the server-resolved cohorts in [save persistence outcomes](../features/save-persistence-outcomes.md#metrics), not editor apply as saved-state proof. No bounded report establishes site-wide quality or population-wide causal effect.

A real-site dataset needs a named site, period, surfaces, implementation SHA/config without secrets, explicit collection scope, and actual collected records with review provenance. The user selected a real production site for its last 30 days, and a bounded read-only collection is now recorded in the [October 7 validation record](../validation/2026-10-07-real-site-outcomes.md). The restricted local candidate retains nine projected outcome/apply records from 41 returned feed rows. Hosted implementation SHA/config provenance remains unverified, so it is not a qualified exporter fixture. Native/synthetic fixture rows remain fixture evidence and must never be called real-site evidence; this contract documentation itself produces no samples.

## Implementation Gate

These approved human contracts are design input only. Before shipping either feature, implement and validate the permission/action boundaries, fixed schemas, retry/deduplication behavior, privacy projection, and denominator/coverage rules against a reviewed bounded dataset. Keep static ranking unchanged. Any adaptive use of outcomes needs its own consent/design/evaluation decision and evidence.
