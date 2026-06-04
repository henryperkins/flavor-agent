# Phase 3: Validation Feedback & Diagnostics — Design

- **Date:** 2026-06-04
- **Status:** Revised after review rounds 4–5 (all findings confirmed against HEAD and folded in); OD-1 and OD-2 resolved (R5). **Ready for the implementation plan.**
- **Scope:** Cross-surface — the four executable recommendation parsers (block, style, template, template-part) plus shared subsystems: ranking (`RecommendationContextScorer`), recommendation-outcome capture/metrics (`RecommendationOutcome*`, `recommendation-outcomes.js`, `executable-surface-runtime.js`), and (audit-only) request diagnostics (`RecommendationAbilityExecution`). Cross-surface validation gates (`docs/reference/cross-surface-validation-gates.md`) apply.
- **Roadmap:** Phase 3 of `improving-levers.md`. **Reframed:** the consuming north star is a **live learning loop** that adapts ranking from real apply / undo / ignore outcomes on production sites. Phase 3's outputs are therefore *training inputs*, which raises signal consistency, joinability, stability, and unbiasedness from "nice" to "load-bearing."
- **Related:** Contextual Ranking V1 (`inc/Support/RecommendationContextScorer.php`) — already carries a `validation_risk` penalty. `inc/Activity/RecommendationOutcome.php` + `RecommendationOutcomeMetrics.php` — the live-loop substrate this builds on. Supersedes the validation half of `improving-levers.md` Phase 3's checklist, which predates that substrate and is partly stale.
- **Review:** Round 4 folded in three findings (one High, two Medium) — see *Identity strategy*, *Reason vocabulary*, Change 10. Round 5 folded in eight more (two High, three Medium, three Low), all confirmed against HEAD by reading every cited function; each is resolved inline below and tagged **(R5)**. The two High items are the metric surface-scope leak (Change 9) and the OD-2 × Change 4 capture tension (OD-2 + acceptance criteria).

## Problem

The roadmap frames Phase 3 as greenfield ("use validation outcomes as quality signals, not just filters"). Grounding against HEAD shows it is half-built and uneven, and that a **live** loop exposes three concrete defects.

### What already exists (do not rebuild)

- **The join key is built and flowing.** Every outcome row carries `recommendationSetId` + `suggestionKey` + `sourceRequestSignature` + `rank` (`recommendation-outcomes.js:626-688`, normalized server-side in `RecommendationOutcome::normalize_entry()`). Apply rows (`apply_*_suggestion`) carry the same `request.recommendation` key, and `RecommendationOutcomeMetrics::evaluate()` already joins applies→shown sets.
- **The ranking snapshot is co-located on every outcome** — `modelScore` / `deterministicScore` / `contextScore` / `blendedScore` / `contextEvidence` / `contextPenalties` / `rankingVersion` (`RecommendationOutcome::normalize_ranking_snapshot()`). Ranking→outcome is already joinable and learnable, and `contextPenalties` already includes `validation_risk`.
- **A normalized `reason` slot already exists** on outcomes (`RecommendationOutcome::normalize_reason()`: lowercased `[a-z0-9_-]`, 64-char cap) and participates in the dedupe `event_key`.
- **The funnel is already computed:** `reviewSelectionRate`, `applyConversionRate`, `reviewApplyConversionRate`, `staleBlockedRate`, **`validationBlockedRate`**, and `unlinkedApplyCount` (a join-quality canary).
- **Per-surface generation validation already runs**, but emits inconsistent shapes:
  - **block** — `BlockOperationValidator` returns coded `rejectedOperations: [{code, message, operation}]` with **15** codes (`BlockOperationValidator.php:16-30`), surfaced in the ability schema (`Registration.php:2051`) and consumed client-side (`recommendation-actionability.js`, `block-operation-catalog.js:13-37` — identical strings).
  - **style** — `StylePrompt::validate_operations()` silently drops invalid ops and folds a **prose** `select_downgrade_reason()` (≈`StylePrompt.php:986`) into the description; contrast failures produce a prose reason. No structured codes.
  - **template** — `TemplatePrompt::validate_template_operations()` returns `{operations, invalid}`; the `invalid` flag is internal only.
  - **template-part** — `TemplatePartPrompt::validate_operations()` drops invalid ops silently.
- **Structured validation-risk input is block-only today.** `RecommendationContextScorer::has_validation_risk()` (`:367`) reads structured rejections only from block's `rejectedOperations`; a fragile prose text-scan (`:372-376`) also fires across surfaces but is heuristic, not structured (Change 6 retires it). So style/template/template-part rejections never penalize ranking through a structured signal.
- **The server request-diagnostic is pattern-shaped and lacks the join key.** `persist_request_diagnostic_activity()` writes `after.pipelineDropReasons` (`:645`), but `build_request_diagnostic_pipeline_drop_reasons()` hard-returns `[]` for every non-`pattern` surface (`:826`). It is identified by a separate `requestRef`, **not** `suggestionKey`/`recommendationSetId` — and it cannot carry them: those are minted **client-side** in `decorateRecommendationPayload()` (`recommendation-outcomes.js:306`) after the payload returns, and `clientRequest` is stripped before the ability runs (`build_execution_input`, `:261-266`).
- **Non-block apply-time validation has no reason codes.** The shared executable runtime records every non-block apply failure as a hard-coded `operation_validation_failed` (`executable-surface-runtime.js:561`); the underlying validators return prose-only errors (`template-operation-sequence.js:156`, `style-operations.js:1369`).

### The three gaps a *live* loop exposes

1. **Reasons are inconsistent across surfaces.** The `reason` dimension means different things per surface (coded on block, prose on style, absent on template/part), so it cannot be trained on.
2. **The template discard is a survivorship-bias bug.** `TemplatePrompt.php:637` and `:648` `continue` (drop the entire suggestion, including its already-validated advisory `templateParts`/`patternSuggestions`) when any operation is invalid. The suggestion never reaches a `shown` outcome, so its rejection leaves **no joinable per-suggestion signal** — the loop can never tie that operation shape to downstream behavior. This is silent training-data corruption, not just a UX gap. It also violates Phase 3's own criteria ("preserve advisory remnants"; "penalize partial validity instead of always discarding").
3. **Metrics count `validation_blocked` but never break it down by reason.** `validationBlockedRate` is a scalar; the loop needs per-reason counts to learn *which* reason codes predict non-apply.

## Goal

Produce a **stable, consistent, joinable, unbiased** validation-reason signal across all four executable surfaces, captured on the records the live loop already join-keys and ranking-stamps. The verbs are **capture / normalize / join / expose** — not *adapt*.

## Non-goals (YAGNI)

- **The learning phase itself** — weight/threshold adaptation from the per-reason data. Out of scope; this is preparation for it.
- **Navigation / content / pattern surfaces.** Navigation and content are advisory (no executable operations to reject); pattern is browse/rank-only with its own visible-scope filtering and its own outcome events — `pattern_inserted_from_shelf`, `insert_failed`, **and `validation_blocked` carrying pattern-pipeline reasons** (`not_visible_in_inserter`, `empty_pattern_blocks`, `disallowed_block_types` — `PatternRecommender.js:476`/`:1183`/`:1206`/`:1283`). None get the new normalizer, so those pattern reasons stay **outside** the versioned vocabulary — which is exactly why the Change 9 breakdown must be surface-scoped (R5, see Change 9).
- **New activity DB columns / migration.** Reasons ride the existing serialized JSON (`target_json` / `after` / `request`), as ranking already does.
- **Expanding the outcome record to carry a reason *list*.** The outcome keeps a single primary `reason` code (existing schema) plus the vocabulary version; the full list lives on the suggestion.
- **A reason aggregation/index store.** Extraction from JSON during ETL is the learning phase's job.
- **Pattern apply/undo semantics.** Unchanged.

## Decisions (locked)

1. **Approach B — additive normalized `validationReasons` projection.** A shared normalizer maps each surface's raw validation output to a common shape; block derives it from its existing `rejectedOperations` (zero regression). Not Approach A (rewrite every surface onto `rejectedOperations`) — too much risk to the working style/template prose-downgrade and contrast paths for marginal uniformity. Not Approach C (no shared field) — divergent shapes starve the loop.
2. **The suggestion carries the full `validationReasons`; the loop reads the primary code from join-keyed places.** Reasons travel as a field on each suggestion (exactly as `ranking` does today). Generation reasons land on the `shown.rankingSet` per-suggestion entry; apply-time blocks land on the `validation_blocked.reason` slot. **(R5 — OD-2 resolved)** Engaged outcomes (`selected_for_review`, apply) additionally carry the generation primary code in a **sibling `validationReason` sub-key** — distinct from the dedupe-bearing `reason` slot, exactly as `outcome.ranking` already rides today — so an engaged suggestion's generation reason is stamped **directly** rather than recovered by a lossy join. `RANKING_SET_CAP` stays at 3 (never-engaged advisory remnants are covered in aggregate by the Change-7 request diagnostic). The loop join is always via the outcome record, never the server `request_diagnostic` (which lacks the key). See *Identity strategy*.
3. **Existing block codes are the vocabulary, verbatim.** The 15 `BlockOperationValidator` codes — already identical strings in `block-operation-catalog.js` — are adopted unchanged; block normalization is pass-through. New codes are added only for style/template/template-part, and the existing generic `operation_validation_failed` is kept as the apply-time fallback. This preserves the zero-regression block path.
4. **A new `validationVocabularyVersion`** constant, co-located with the primary reason on the outcome (alongside `recommendationSetId`/`suggestionKey`) — so the learning phase can segment historical training data across vocabulary changes without a join. Not piggybacked on `catalogVersion`.
5. **Per-reason metric breakdown is in-scope** (resolves gap #3), but stops at measurement — it surfaces counts, it does not adapt anything.
6. **Template discard is fixed:** keep the advisory remnant and attach `validationReasons` for the dropped operations, so the failure is recorded instead of vanishing.
7. **The server `request_diagnostic` reason aggregate is audit-only.** It is built by a new executable-surface helper (not the pattern-only `build_request_diagnostic_pipeline_drop_reasons`), is identified by `requestRef`, and is never the loop's join record.

## Reason vocabulary (v1)

Codes name what existing validators already detect; they are not invented. The block set is **adopted verbatim** from `BlockOperationValidator` (and is already identical, string-for-string, in `block-operation-catalog.js` — see OD-1), so the block path is pure pass-through with zero regression. New codes are added only where a surface has no code today. `severity ∈ {rejected, downgraded, no_op}` (`rejected` = operation dropped from the executable set; `downgraded` = suggestion kept advisory-only; `no_op` = proposed change equals current state).

**Block — adopted verbatim (15):** `block_structural_actions_disabled`, `multi_operation_unsupported`, `invalid_operation_payload`, `unknown_operation_type`, `missing_pattern_name`, `pattern_not_available`, `missing_target_client_id`, `stale_target`, `cross_surface_target`, `invalid_target_type`, `locked_target`, `content_only_target`, `invalid_insertion_position`, `action_not_allowed`, `client_server_operation_mismatch`.

**New codes — seeded from confirmed validator branches.** This table is the **seed, not the ceiling** (review round 4, Finding 3): the plan must enumerate *every* deterministic rejection branch in `StylePrompt::validate_operations()`, `style-operations.js` (`validatePresetStyleOperation` et al., `:1008`+), `TemplatePrompt::validate_template_operations()` (`:1128`), and `template-operation-sequence.js`, map each to a specific code, and add a test per branch. `operation_validation_failed` is reserved for genuinely unmappable/unexpected failures only — **no deterministic branch may silently collapse to it.**

| code | surfaces | derived from | default severity |
| --- | --- | --- | --- |
| `unsupported_scope` | style | `set_styles` on Style Book / `set_block_styles` block mismatch | rejected |
| `unsupported_path` | style | style path not in `supportedStylePaths` | rejected |
| `failed_contrast` | style | contrast `low_ratio` / `unavailable` | downgraded |
| `preset_required` | style | freeform value where a theme preset is required (`style-operations.js:1017`) | rejected |
| `preset_metadata_mismatch` | style | preset type/slug no longer matches the live theme contract (`:1028`) | rejected |
| `preset_reference_mismatch` | style | parsed preset value no longer matches its metadata (`:1037`) | rejected |
| `preset_unavailable` | style | preset slug no longer available in the theme (`:1048`) | rejected |
| `invalid_freeform_value` | style | missing/invalid freeform value | rejected |
| `missing_style_book_target` | style | missing/unregistered Style Book block target | rejected |
| `unavailable_variation` | style | requested theme variation not available | rejected |
| `no_executable_operations` | style, template, template-part | normalized operation set is empty | rejected |
| `invalid_template_area` | template, template-part | area not in allowed/empty/assigned lookups | rejected |
| `no_assigned_part` | template | nothing assigned to replace (`TemplatePrompt.php:1193`) | rejected |
| `duplicate_area_mutation` | template | area already mutated this sequence (`:1153`/`:1205`) | rejected |
| `area_mismatch` | template | assigned part's area ≠ operation area (`:1213`) | rejected |
| `same_slug_no_op` | template | replace slug equals current slug (`:1220`) | no_op |
| `invalid_anchor` / `invalid_placement` | template, template-part | insertion anchor/placement not in lookup | rejected |
| `unknown_pattern` | template, template-part | pattern not in visible/`pattern_lookup` | rejected |
| `repeated_pattern_insert` | template, template-part | same pattern inserted more than once | rejected |
| `malformed_operation` | template, template-part | non-array / unparseable operation (`:1142`) | rejected |
| `overlapping_block_paths` | template-part | overlapping target block paths (`template-operation-sequence.js:149`) | rejected |
| `no_op` | all | proposed change equals current state (scorer no-op detection) | no_op |
| `unknown_operation_type` | template, template-part | unsupported operation `type` (`TemplatePrompt.php:1316`) — reuse block's code | rejected |
| `too_many_operations` | template-part | operation count exceeds the per-suggestion cap (`TemplatePartPrompt.php:876`; JS `template-operation-sequence.js:344`) | rejected |

**(R5) `no_op` producer is ambiguous:** the generic `no_op` row is *scorer*-derived (`is_possible_no_op`), but `validationReasons` is attached by the *parsers*, not the scorer — so either a parser gains no-op detection or the scorer attaches the reason (a concern-mix). `same_slug_no_op` (template) is already parser-emittable (`:1220`); resolve generic `no_op`'s owner during planning.

**Apply/review-time client reasons — adopted verbatim (2):** `advisory_only` (severity `downgraded`) and `missing_structural_context` (severity `rejected`) are already emitted on block `validation_blocked` outcomes today (`src/store/index.js:1999`, `:2268`). They are folded into the vocabulary so the per-reason breakdown stays inside the versioned set (review Finding 2).

**Generic fallback (kept):** `operation_validation_failed` — the existing hard-coded apply-time reason (`executable-surface-runtime.js:561`, `src/store/index.js:2063`/`:2325`) is retained as the fallback when a validator cannot yet produce a specific code, so no existing outcome reason regresses.

The per-reason breakdown (Change 9) aggregates **`validation_blocked` events from the five executable outcome surfaces only** (`block`/`template`/`template-part`/`global-styles`/`style-book` — R5; pattern's `validation_blocked` reasons are unversioned and excluded, and there is no `style` outcome surface); `stale_blocked` reasons (e.g. `stale_docs`) are a separate event/dimension and are not mixed in.

The list is **versioned** (`validationVocabularyVersion = 'validation-reasons-v1'`). Adding a code does not bump the version; **renaming or removing** one does.

## Data contract

```jsonc
// carried on each suggestion in the server's generation output (like `ranking`)
"validationReasons": [
  { "code": "unsupported_scope", "severity": "rejected", "message": "…" } // message bounded + sanitized, optional
]
```

- **On the suggestion** (parser output): the full list. Block continues to also carry `rejectedOperations` (no regression); its `validationReasons` is its existing codes, verbatim. This field travels to the client with the payload (added to the ability output schema, Change 11).
- **On the outcome** (`RecommendationOutcome`): the **primary** code (highest severity, then first) + `validationVocabularyVersion` ride the **`shown.rankingSet` entry** (per-suggestion, alongside that entry's ranking) and the **`validation_blocked.reason`** slot — both join-keyed by `suggestionKey`/`recommendationSetId`. **(R5 — OD-2 resolved)** `selected_for_review`/apply outcomes additionally carry the generation primary code in a **sibling `validationReason` sub-key** (not the dedupe-bearing `reason` slot; see *Identity strategy* step 4). These are the loop's join records.
- **On the server `request_diagnostic` row** (audit surface only): an optional request-level aggregate built by a **new** executable-surface helper. Identified by `requestRef`; not used for the loop join.

### Identity strategy (resolves the join — review Finding 1)

The server cannot stamp the loop join key: `recommendationSetId`/`suggestionKey`/`sourceRequestSignature` are minted **client-side** in `decorateRecommendationPayload()` (`recommendation-outcomes.js:306`) after the ability returns, and `clientRequest` is stripped before execution (`:261-266`). So validation reasons reach the loop the **same way `ranking` already does**:

1. Parsers attach `validationReasons` to each **suggestion** (server output).
2. `decorateRecommendationPayload()` reads `suggestion.validationReasons` and carries the **primary code + vocabulary version** into the `shown` event's per-suggestion `rankingSet` entry — the canonical per-suggestion generation-time feature record. **This requires extending the `rankingSet` item schema**: both `buildRankingSetFromSuggestions()` (JS) and `RecommendationOutcome::normalize_ranking_set()` (PHP) currently emit only `{suggestionKey, ranking}` and would otherwise silently drop the reason; they must accept `{suggestionKey, ranking, validationReason?, validationVocabularyVersion?}`.
3. **`validation_blocked`** outcomes carry the primary code in the existing `reason` slot (that slot's purpose) via `RecommendationOutcome::normalize_entry()`.
4. **Engaged outcomes carry the generation reason in a sibling sub-key, not the `reason` slot. (R5 — OD-2 resolved.)** `selected_for_review` and apply rows keep their existing event `reason` (`review_opened`, emitted by the per-surface recommenders — `BlockRecommendationsPanel.js:764`, `TemplateRecommender.js:754`, `GlobalStylesRecommender.js:855`, etc.; **not** `src/store/index.js:764`, which is unrelated). Putting a validation reason in that *shared* `reason` slot would collide with the event reason and corrupt the dedupe `event_key`, so the generation primary code instead rides a **separate `outcome.validationReason` sub-key** — exactly as `outcome.ranking` already does for non-`shown` events today (`buildRecommendationOutcomeEntry:622-623`, `RecommendationOutcome.php:124-128`). `selected_for_review` stamps it via that same hook; apply entries carry it through an extended `getRecommendationIdentityForApply()` (`recommendation-outcomes.js:692`, which today strips even `ranking`) into `request.recommendation`. This stamps the reason **directly** on every engaged outcome regardless of rank, dodging the lossy `shown.rankingSet` join (cap 3 + the non-stable-key positional-fallback miss). Dedupe is unaffected — `event_key`/dedupe read `reason`, not the new sub-key. **(R5) PHP persistence boundary differs by row type:** `selected_for_review` is a `recommendation_outcome` row handled by `RecommendationOutcome::normalize_entry()`, but apply rows are **not** outcome rows — that method returns them unchanged (`:58` gate), so their `request.recommendation.validationReason` rides through `Serializer::normalize_entry()` (which deep-preserves `request` via `normalize_structured_value`), the step that runs *first* in `Repository::create()` (`:157`).
5. **Apply-time blocks:** the executable runtime and per-surface validators emit a specific code (Finding 3) instead of the hard-coded `operation_validation_failed`, populating the `validation_blocked` outcome `reason`.

Consequence: the generation reason lives in `shown.rankingSet[i]` (per-suggestion, top-`RANKING_SET_CAP`) and — **(R5)** — directly on each engaged outcome via the sibling `validationReason` sub-key; apply-time blocks live in `validation_blocked.reason`. Never-engaged advisory remnants below the rank cap are still aggregated per request by the Change-7 diagnostic. The numeric "had validation risk" signal flows independently via `ranking.contextPenalties.validation_risk` once Change 6 lands; the reason **code** is the new, specific dimension.

### Identity & freshness boundary (critical for the live loop)

- **`validationReasons` must NOT enter `getSuggestionKey()`** (`src/inspector/suggestion-keys.js:37`; fingerprint object at `:49-66`). The suggestion key is the identity of *what is proposed* (operations, attributeUpdates, values); reasons are a *feature/annotation*. Folding reasons into the key would break the shown→selected→applied join. The current fingerprint does not include `rejectedOperations`; the new field must likewise be excluded. A guard test asserts this.
- **`validationReasons`/`validationVocabularyVersion` never reach applicability signatures, by construction.** Every signature call site is a **resolved/review context** signature built from **request-side context** (`TemplateAbilities.php:116`/`:247`/`:1533`, `StyleAbilities.php:115`/`:373`, `BlockAbilities.php:71`, `NavigationAbilities.php:161`, `PatternAbilities.php`); response-side suggestion annotations are not in those inputs. `RecommendationSignature::from_payload()` does hash the *entire* payload recursively (`RecommendationSignature.php:9`), so the guarantee is "the keys are never in the context payload passed to it" — **not** that the core helper learns to ignore them (that would wrongly broaden the signature contract — review round 4, Finding 2). Tests therefore assert the **actual context signature inputs** the abilities build are annotation-free; no call site passes response suggestions into a signature, so no central stripping is added.

### Cross-language vocabulary

Server generation-time reasons (PHP parsers) and client apply/review-time reasons (`block-operation-catalog.js` re-validation that drives the `validation_blocked` outcome) **must use the same codes**. Block already satisfies this (PHP and JS strings match). The vocabulary is a cross-language contract for the **new** codes too — see OD-1.

## Privacy & bounds

- Reasons are **enum codes** plus an optional **bounded, sanitized** message. No raw provider payloads, no block trees, no full operation objects, no PII. Matches the existing `normalize_reason()` discipline and Phase 3's "diagnostics stay bounded and sanitized" criterion.
- Diagnostics persistence policy is unchanged: the `request_diagnostic` row is still emitted for every request (existing always-emit contract), but its reason aggregate is **non-empty only when there are reasons** — a clean pass leaves it empty rather than adding noise. No new rows, no routine clean-pass reason logging. (The live loop's *positive* examples come from `shown`→`applied` conversions already captured, not from logging every successful validation.)

## Changes (file-level)

### 1. `inc/Support/ValidationReason.php` — new
Canonical vocabulary (code + severity constants), `validationVocabularyVersion`, and `normalize( array $raw ): array` producing the bounded `validationReasons` list, plus `primary( array $reasons ): array` (highest severity, then first). Pure, no WP deps beyond sanitizers; unit-tested in isolation. **(R5 — OD-1 resolved)** The vocabulary's single source of truth is a new **`shared/validation-reasons.json`** (`{code, severity, surfaces, sinceVersion}`), matching the existing `shared/support-to-panel.json` precedent; this class and the JS catalog (`block-operation-catalog.js` + the new style/template codes) are **asserted in sync** against it by test. Block's existing PHP/JS constants stay byte-for-byte unchanged (zero regression) and a parity test asserts they are a subset of the JSON.

### 2. `inc/Context/BlockOperationValidator.php` + `inc/LLM/Prompt.php`
Derive `validationReasons` on the block suggestion from its existing `rejectedOperations[].code` — **pass-through** (codes are already the vocabulary). Keep `rejectedOperations` intact.

### 3. `inc/LLM/StylePrompt.php` + `src/utils/style-operations.js`
Generation-time (`validate_operations()`, contrast path) **and** apply-time (`style-operations.js` validators — `validatePresetStyleOperation` at `:1008`, preset branches `:1019`/`:1031`/`:1044`/`:1058`) record a specific code for *every* failure branch — scope, path, the four preset branches, freeform, Style Book target, variation, empty-ops — emitting `validationReasons` (PHP) / returning a `code` (JS). The prose `select_downgrade_reason()` and apply error strings stay for humans; codes derive from the same conditions. A test asserts each branch maps to a specific code, never `operation_validation_failed`. **(R5) Implementation note — this is a return-contract change on the PHP side, not an in-place code addition:** `validate_operations()` (`:1014-1163`) currently drops every invalid op with a bare `continue` and returns *only the survivors* (zero signal today — not even prose), and there are **two** reason-producing sites that must be merged onto one suggestion: the `continue` branches *and* the separate `StyleContrastValidator::evaluate()` contrast path (`:785`). The function's return shape and its caller `validate_suggestions()` (`:783`) must change to carry rejections+codes. The `set_block_styles` continues at `:1039-1048` are compound (surface-mismatch / missing-target / name-mismatch) and must be split. (Client-side `style-operations.js` is easier — its validators already return prose `{ok:false, error}`, so it only gains a `code`.)

### 4. `inc/LLM/TemplatePrompt.php`
Three changes: **(a) fix the discard** at `:637`/`:648` — instead of `continue`, keep the advisory remnant (`templateParts`/`patternSuggestions`) and attach `validationReasons`; only fully empty suggestions (no ops, no advisory) are still skipped. **(b) `validate_template_operations()` (`:1128`) must retain per-branch codes** — today every rejection funnels into a single `invalid_template_operations_result()` (`:1110`) with no code, so it must instead return the specific code per branch (`malformed_operation`, `duplicate_area_mutation`, `same_slug_no_op`, `no_assigned_part`, `area_mismatch`, `invalid_template_area`, `unknown_pattern`, …). A test covers each branch. **(c) (R5) `derive_template_operations()` (`:1332`) is the *second* discard source feeding `:648`** and currently returns a code-less `['operations'=>[], 'invalid'=>true]` at `:1350` (duplicate-area). It must return a code-bearing shape (`duplicate_area_mutation`) with its own test, or the `:648` path still collapses a deterministic rejection to a missing reason. **(R5) Implementation note — return-contract change, not an in-place addition:** `validate_template_operations()` returns `{operations, invalid:bool}` via *early return on first invalid op*, so it reports at most one reason per suggestion (acceptable — the outcome keeps a single primary code), but the bool must become a code and the caller `validate_template_suggestions()` (`:625-653`) must thread it onto the entry. Two cited branches are **compound** and must be split to satisfy "every deterministic branch → a specific code": `:1157-1163` conflates invalid-unused-part / area-not-empty / area-already-assigned, and `:1248-1254` conflates empty-pattern-name / empty-placement / unknown-pattern. Add a code for the unknown-operation-type default at `:1316` (reuse block's `unknown_operation_type`).

### 5. `inc/LLM/TemplatePartPrompt.php`
Emit `validationReasons` from `validate_operations()` drops (preserves existing advisory-first behavior; no discard bug here). **(R5)** Same return-contract shape as style/template: `validate_operations()` (`:869`) drops via `continue 2` / early `return []` and yields only survivors, so the return shape and caller must change to carry codes. Add a code for the **`> 3` operations cap** (`:876`, and the JS mirror at `template-operation-sequence.js:344`) — e.g. `too_many_operations` — which today returns empty/prose with no code; it is a deterministic branch and must not collapse to the generic fallback.

### 6. `inc/Support/RecommendationContextScorer.php`
`has_validation_risk()` reads normalized `validationReasons` (severity `rejected`/`downgraded`) across surfaces, with `rejectedOperations` kept as a compat fallback. **(R5) Retire the prose text-scan** at `:372-376` — today the method also returns `true` when suggestion text contains `rejected`/`invalid`/`validation`/`contrast failed`, so a benign description (e.g. "avoid invalid contrast pairings") sets `contextPenalties.validation_risk` with **no** corresponding versioned `validationReason`. Because this penalty is now a live-loop training input, that false-positive corrupts the feature contract (a ranking penalty with no joinable reason code). Once every executable surface emits structured `validationReasons`, drop the prose scan; add a test asserting a prose-only match no longer triggers `validation_risk`. The penalty then flows into `contextPenalties` on the outcome ranking snapshot automatically.

### 7. `inc/Abilities/RecommendationAbilityExecution.php` — audit aggregate only
Add a **new** executable-surface helper that aggregates `validationReasons` from the payload's suggestions and persists a bounded reason summary + `validationVocabularyVersion` on the `request_diagnostic` row for the **audit surface**, joined by `requestRef`. Do **not** route through `build_request_diagnostic_pipeline_drop_reasons()` — it hard-returns `[]` for non-pattern surfaces (`:826`). This is audit-only; the loop join is via the outcome (see *Identity strategy*).

### 8. `inc/Activity/RecommendationOutcome.php`
Persist the primary normalized reason code in the existing `reason` slot for `validation_blocked`, plus `validationVocabularyVersion`. **Extend `normalize_ranking_set()`** so each item accepts `validationReason` + `validationVocabularyVersion` alongside `{suggestionKey, ranking}` (today it strips everything else — review Finding 1). No new list field on the outcome.

### 9. `inc/Activity/RecommendationOutcomeMetrics.php`
Add a per-reason breakdown of `validation_blocked` events (counts keyed by primary reason code) to `evaluate()`. This aggregates the **apply/review-time** blocking signal. **(R5) The breakdown MUST be scoped to the executable *outcome* surfaces — `block`, `template`, `template-part`, `global-styles`, `style-book` — and exclude `pattern`.** Note the metric filters on the outcome `surface` field, whose style values are `global-styles`/`style-book` — there is **no** `style` outcome surface (`style` is the parser/ability family; the style ability maps to those two scopes — `RecommendationOutcome::SURFACES` at `:27`, `executable-surfaces.js:211`/`:281`). Today `evaluate()` buckets every `validation_blocked` event with no surface filter (`:68`), and pattern emits `validation_blocked` with unversioned pipeline reasons (`empty_pattern_blocks`, `disallowed_block_types`, `not_visible_in_inserter` — see Non-goals). Without the filter the breakdown would contain unversioned strings, violating the acceptance criterion below. Scope the new breakdown via that surface allow-list; leave the existing scalar `validationBlockedRate` unchanged (its denominator semantics are out of scope, and changing them would itself need a metrics-gate note). The alternative — formally versioning the pattern reasons into the vocabulary — is rejected because Non-goals deliberately keep pattern out of the normalizer. (Separately, the template discard fix in Change 4 closes the survivorship gap on the **generation-time** side — previously-discarded template rejections now surface as recorded `validationReasons` on `shown` advisory suggestions, regardless of whether the user later triggers a client-side block.)

### 10. Client — apply-time reason codes + decoration (review Finding 3)
- `src/store/recommendation-outcomes.js` — `decorateRecommendationPayload()` carries the primary reason code + `validationVocabularyVersion` from `suggestion.validationReasons` into `recommendationOutcome`; **extend `buildRankingSetFromSuggestions()`** so `rankingSet` items carry `validationReason` + version (today it emits only `{suggestionKey, ranking}` — review Finding 1). **(R5 — OD-2)** also stamp the sibling `outcome.validationReason` on engaged outcomes: `buildRecommendationOutcomeEntry()` adds it for `selected_for_review` (next to the `ranking` it already stamps at `:622-623`), and `getRecommendationIdentityForApply()` (`:692`) carries the primary code into `request.recommendation` for apply entries — directly, not via the `shown.rankingSet` join.
- `src/store/executable-surface-runtime.js` — replace the hard-coded `recordBlockedOutcome('validation_blocked', 'operation_validation_failed')` (`:561`) with the normalized `result.code`, falling back to `operation_validation_failed` only when absent.
- `src/utils/template-operation-sequence.js`, `src/utils/style-operations.js`, `src/utils/template-actions.js` — validators/executors return a normalized `code` alongside the prose `error` (e.g. `overlapping_block_paths`, `unsupported_scope`).
- `src/store/executable-surfaces.js` — thread `result.code` through the apply/blocked-outcome wiring.
- `src/utils/recommendation-actionability.js`, `src/utils/block-operation-catalog.js` — extend advisory rendering beyond block so style/template/template-part rejected-but-advisory suggestions show a concise reason; block path already coded.

### 11. `inc/Abilities/Registration.php`
Expose `validationReasons` on the style/template/template-part suggestion output schemas (block already exposes `rejectedOperations` at `:2071`; add the derived field there too for parity) so the field survives transport to the client. **(R5 — OD-1 resolved) Type `code` as a bounded string** (`{type: string, maxLength: 64, pattern: ^[a-z0-9_-]+$}`), **not** an enum: the abilities bridge runs strict client-side ajv, so an out-of-enum value would fail the *whole* recommendation payload ("could not revalidate") — unacceptable for a diagnostic annotation, and the vocabulary is versioned-and-growing, so an enum would turn every code addition into a synchronized-schema-or-outage. (Block's `rejectedOperations.code` at `:2076-2079` stays enum'd because those 15 codes are frozen; the new vocabulary is not.) This keeps the output schema **out** of the vocabulary contract — PHP + JS only, both deriving from the shared JSON (OD-1). Add a guard test that a clean suggestion's `validationReasons: []` (empty array, not the `{}`→`[]` coercion case) survives the bridge.

## Acceptance criteria

From `improving-levers.md` Phase 3, plus the live-loop additions:

- Diagnostics can explain why a suggestion was downgraded, rejected, or kept advisory-only, using a **single cross-surface vocabulary** that adopts the existing block codes verbatim.
- Ranking components include validation state on **all four** executable surfaces (not just block); the penalty appears in the outcome's `contextPenalties`.
- A rejected-but-advisory suggestion is **kept** (advisory remnant preserved) — specifically, the template discard at `TemplatePrompt.php:637`/`:648` no longer drops such suggestions.
- The primary reason code + `validationVocabularyVersion` are captured on the **`shown.rankingSet` entry** (extended item schema, JS + PHP) and the **`validation_blocked.reason`** slot; **(R5)** `selected_for_review`/apply keep their event reasons and carry the generation reason **directly** in a sibling `outcome.validationReason` sub-key (not by joining). `validationReasons`/version are **excluded** from `getSuggestionKey()` and never enter the context inputs of any signature class — guard tests assert both (JS key guard + PHP context-input assertions; no core-helper key-stripping).
- The per-reason `validation_blocked` breakdown is **scoped to the five executable outcome surfaces** (`block`/`template`/`template-part`/`global-styles`/`style-book`; R5 — `pattern` excluded, and there is no `style` outcome surface) and contains only versioned-vocabulary codes (existing `advisory_only` / `missing_structural_context` / `operation_validation_failed` included), never unversioned strings.
- **Every deterministic** generation- and apply-time rejection branch across style/template/template-part maps to a **specific** code (per-branch tests prove it); `operation_validation_failed` appears only for genuinely unmappable failures.
- `RecommendationOutcomeMetrics` exposes a per-reason `validation_blocked` breakdown (apply/review-time signal).
- Previously-discarded template suggestions are now recorded as `shown` advisory suggestions carrying `validationReasons` (generation-time survivorship fix). **(R5 — OD-2 resolved)** Their reasons are visible to the loop via the Change-7 request diagnostic (always, full set) and `shown.rankingSet` (top-3); engaged suggestions additionally carry their generation reason **directly** on the outcome via the sibling `validationReason` sub-key (any rank). Never-engaged remnants below the rank cap are intentionally aggregate-only — there is no downstream outcome to join them to.
- Reasons are bounded/sanitized: no raw payloads, no block trees, no routine clean-pass logs.
- **Metrics gate (offline Phase 0 harness):** `invalidOperationRate` flat or down; `noOpRate` flat; advisory remnants do **not** raise `invalidOperationRate`; rejected executable operations lower the blended score.

## Test surface

- **New:** `tests/phpunit/ValidationReasonTest.php` (vocabulary + normalizer + `primary()` in isolation).
- **PHP:** `RecommendationEvaluationTest`, `BlockAbilitiesTest`, `StyleAbilitiesTest`, `TemplateAbilitiesTest`, `TemplatePartPromptTest`, `RecommendationAbilityExecutionTest`; **per-branch code coverage** for `validate_template_operations()` and the style validators; outcome/metrics coverage for the per-reason breakdown (incl. `advisory_only` / `missing_structural_context`); `RecommendationOutcome::normalize_ranking_set()` item-schema coverage; and **signature tests asserting the context inputs the abilities build for `RecommendationResolvedSignature` / `RecommendationReviewSignature` contain no `validationReasons`/version** (call-site boundary, not core-helper key-stripping). **(R5)** add: `RecommendationEvaluationTest` asserts the per-reason breakdown **excludes `pattern` `validation_blocked` events**; `derive_template_operations()` duplicate-area branch returns a code (not bare `invalid:true`); `RecommendationContextScorer::has_validation_risk()` does **not** fire on prose-only text (no `validationReasons`); and the Change 11 **bounded-string** schema accepts every vocabulary `code` and a clean `validationReasons: []` (ajv-survival). **(R5)** `RecommendationOutcome::normalize_entry()` preserves the sibling `validationReason` on `selected_for_review` outcomes without changing the dedupe `event_key`; **apply rows are not outcome rows** — assert their `request.recommendation.validationReason` round-trips through `Serializer::normalize_entry()` (`request` is deep-preserved by `normalize_structured_value`, so no new normalization logic is needed); and a `shared/validation-reasons.json` ↔ PHP parity test (block subset + new codes).
- **JS:** `src/store/__tests__/store-actions.test.js`, `src/store/__tests__/executable-surface-runtime.test.js` (specific apply-time codes), recommendation-outcomes/actionability suites (incl. `buildRankingSetFromSuggestions` carrying the reason), per-branch validator coverage (`template-operation-sequence`, `style-operations`), and the `src/inspector/suggestion-keys.js` `getSuggestionKey` exclusion guard. **(R5)** add: `getRecommendationIdentityForApply()` and the `selected_for_review` path carry the sibling `validationReason` (not the `reason` slot, no dedupe-key change); and a `shared/validation-reasons.json` ↔ JS-catalog parity test.

## Verification

```bash
composer run test:php -- --filter 'ValidationReasonTest|RecommendationEvaluationTest|BlockAbilitiesTest|StyleAbilitiesTest|TemplateAbilitiesTest|TemplatePartPromptTest|RecommendationAbilityExecutionTest'
npm run test:unit -- --runInBand src/store/__tests__/store-actions.test.js src/store/__tests__/executable-surface-runtime.test.js
node scripts/verify.js --skip-e2e   # inspect output/verify/summary.json
npm run check:docs
git diff --check
```

Record Phase 0 metric movement in the same run (the metrics gate is not "passed" without `RecommendationEvaluationTest` in that run, per `improving-levers.md` risk controls).

## Open decisions

- **OD-1 — cross-language vocabulary contract. (R5 — RESOLVED.)** Block codes are already identical strings in PHP (`BlockOperationValidator:16-30`) and JS (`block-operation-catalog.js:13-37`). **Resolution:** a new **`shared/validation-reasons.json`** is the single canonical source for the full vocabulary (block + new codes), asserted in sync on both sides by test (matching `shared/support-to-panel.json`); block's existing constants stay unchanged and are verified as a subset. The output schema is **not** a third mirror — Change 11 types `code` as a bounded string, not an enum, so PHP + JS (both derived from the JSON) are the only two sides of the contract. Lands before client Change 10.
- **OD-2 — generation-reason capture for engaged outcomes. (R5 — RESOLVED; supersedes the round-4 `RANKING_SET_CAP` framing.)** The problem splits in two. **(P1) Never-engaged advisory remnants** (Change 4's output) are advisory-only — they route to `AIAdvisorySection`, never produce `selected_for_review`/apply, and have no downstream to join to; their reasons are captured in aggregate by the Change-7 request diagnostic, which suffices for generation-quality frequency. **(P2) Executable suggestions ranked > 3 that are engaged/applied** are the real per-suggestion join loss (the positive-example path) — recovery via `shown.rankingSet` is lossy (cap 3, plus the non-stable-key positional-fallback miss in `getStableRankingSetSuggestionKey`; `unlinkedApplyCount` does **not** catch the rank > 3 case). **Resolution:** fix P2 by stamping a sibling `outcome.validationReason` sub-key directly on engaged outcomes (Identity step 4), and **keep `RANKING_SET_CAP = 3`** — raising it would not fix P2's non-stable-key miss, would cost storage on every `shown` row, and P1 does not need it. The acceptance criterion is reworded accordingly (below).

## Risk controls honored

- No widening of pattern recommendations into apply/undo. ✓
- No hashing of volatile diagnostics into applicability signatures. ✓ (explicit exclusion)
- Model ranking stays non-authoritative; validation is deterministic. ✓
- No routine successful-validation logs persisted as diagnostics. ✓
- Metrics-gate claims require `RecommendationEvaluationTest` in the same run. ✓
- Zero-regression block path: existing codes adopted verbatim, `rejectedOperations` untouched, `operation_validation_failed` retained as fallback. ✓
- Per-reason metric breakdown is executable-surface-scoped, so unversioned `pattern` reasons never enter the versioned breakdown. ✓ (R5)
- Scorer `validation_risk` penalty fires only on structured `validationReasons` (prose heuristic retired), so no penalty enters training data without a joinable reason code. ✓ (R5)
