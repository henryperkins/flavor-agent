# Flavor Agent Save-Persistence Outcomes 1.0

- **Status:** Canonical design contract; no runtime implementation exists yet
- **Contract version:** `1.0`
- **Date:** 2026-09-11
- **Baseline commit:** `fd28015` (`master`)
- **Scope:** What an editor-lane "Apply" claims, how persistence is proven, and how that evidence reaches reporting

The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**, and **MAY** are normative.

## 1. Purpose

`docs/flavor-agent-wordpress-context-audit.md` records that an ordinary editor Apply means Flavor Agent changed editor state and recorded the operation — not that WordPress saved the document. This document defines the contract that closes that gap without redefining any existing recorded event.

It defines:

- five new recommendation outcome events covering the save lifecycle
- an immutable execution-origin discriminator on apply rows
- server-authored persistence verification, including the reset-to-theme deletion path
- client-authored save-attempt reporting with per-entity save-occurrence correlation
- derived (never separately stored) persistence assurance, read through one shared resolver
- the separation of *inconclusive verification* from *verification never performed*

## 2. Implementation status

This is a design contract. None of it is implemented.

The current repository contains:

- ten recommendation outcome events (`src/store/recommendation-outcomes.js`, `inc/Activity/RecommendationOutcome.php`)
- seven `apply_*` activity types, each written by **both** the editor lane (`src/store/activity-undo.js`) and the governed external lane (`inc/Abilities/ApplyAbilities.php`)
- `RecommendationOutcomeMetrics::evaluate()` with `applyConversionRate` and `reviewApplyConversionRate`
- governed server persistence with read-back verification for external applies (`inc/Apply/ExistingPostContentWriter.php`)

The current repository contains **no** save-lifecycle code. There is not one reference to `savePost`, `isEditedPostDirty`, `isSavingPost`, `didPostSaveRequestSucceed`, or `saveEditedEntityRecord` anywhere in `src/`, tests included. This is greenfield.

## 3. Problem statement

Two distinct guarantees currently share one row type.

An `apply_template_suggestion` row written by `src/store/activity-undo.js` means editor state changed, with no persistence proof. The identical row type written by `inc/Abilities/ApplyAbilities.php` means a governed server write occurred. `RecommendationOutcomeMetrics::evaluate()` counts both identically in `applyConversionRate`.

Two facts make this worse than it appears:

1. `inc/Activity/Serializer.php:37` defaults `executionResult` to `'applied'`. Every editor-lane apply row is stamped `applied` at creation with no write having occurred. For the editor lane, `executionResult = 'applied'` carries **no information about persistence at all**.
2. `inc/Abilities/ApplyAbilities.php:176` creates governed rows with `executionResult => 'pending'` *before* any write, and those rows may never be approved — they expire on the `PENDING_TTL_FILTER`. Origin therefore does not imply verification either.

## 4. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Flavor Agent **observes** saves; it never drives them | Keeps the save decision with the human, consistent with a review-gated governance layer; purely additive |
| D2 | Persistence attributes **per apply**, verified | Only option that answers "did this recommendation survive to publish?"; a rate counting undone applies as persisted would be the same unverified claim this work exists to remove |
| D3 | Both save mechanisms covered in one pass | Four of six editor-lane surfaces are Site Editor only; a post-editor-only pass would leave most of the product still overclaiming |
| D4 | Lane labelling forward-only; no historical rewrite | Ends ambiguity going forward without moving any reported number |
| D5 | Persistence is **server-verified**; the client reports only attempts | `save_confirmed` is a claim about persistence. A client-asserted one replaces an unproven claim with a more specific unproven claim |
| D6 | Assurance is **derived** from durable evidence, never stored in a second mutable column | A second column would duplicate state that already exists and could disagree with it |

## 5. Contract and vocabulary

### 5.1 Events

Five events are added to `OUTCOME_EVENTS` (`src/store/recommendation-outcomes.js`) and `RecommendationOutcome::EVENTS` (`inc/Activity/RecommendationOutcome.php`). The ten existing events are **unchanged**.

| Event | Author | Meaning |
|---|---|---|
| `save_attempted` | client | User submitted a qualifying entity save for a scope holding ≥1 outstanding apply |
| `save_failed` | client | The client observed an error during the save request; **persistence is undetermined** |
| `save_confirmed` | server | WordPress persisted; this apply's recorded change was **verified present** |
| `save_discarded` | server | WordPress persisted; this apply's recorded change was **verified absent** |
| `save_unverifiable` | server | WordPress persisted, but the recorded change could **not be conclusively verified** present or absent |

`save_unverifiable` MUST remain separate from both success and discard in all reporting. Recording an inconclusive comparison as either verdict would be a guess.

### 5.2 Precedence

Server-authored verdicts are authoritative and terminal for an apply.

- `save_failed` MUST NOT override, suppress, or delay a server verdict.
- A `save_confirmed` MAY legitimately coexist with an earlier `save_failed` for the same apply and occurrence.
- Reporting MUST NOT treat `save_failed` as a negative persistence verdict.

This is not a theoretical case. `class-wp-rest-templates-controller.php:551-562` runs `wp_trash_post( $id )` and *then* returns `rest_cannot_delete` when the result is falsy — the mutation has already been attempted when the error returns. A lost response can likewise follow a successful write.

### 5.3 Execution origin

A new nullable `apply_lane` column on the activity table records **execution origin**, not achieved assurance:

| Value | Written by |
|---|---|
| `editor-state` | `src/store/activity-undo.js` |
| `server-executed` | `inc/Abilities/ApplyAbilities.php` |
| `NULL` | historical rows — treated as `unknown` |

`apply_lane` is immutable, assigned at creation. It MUST NOT be interpreted as evidence of verification (see §3, item 2).

### 5.4 Derived assurance

Achieved persistence assurance MUST be derived from durable evidence and MUST NOT be stored in a separate mutable column.

Three requirements govern the derivation:

1. `executionResult = 'applied'` alone MUST NOT establish persistence, for any lane.
2. Reporting MUST load linked outcome rows by explicit apply/occurrence lookup, beyond the displayed page or date window. Evidence is retained alongside the apply.
3. Historical rows without sufficient evidence remain `unknown`. Historical reports MUST replay the recorded verdict and MUST NOT re-check today's content.

One shared resolver, `Activity\PersistenceAssurance`, serves both `RecommendationOutcomeMetrics` and the admin UI, so reporting and presentation cannot drift.

### 5.5 Authorship enforcement

`RecommendationOutcome::normalize_entry()` (`inc/Activity/RecommendationOutcome.php:93`) is the only validation chokepoint, but it is reached from `Repository::create()` (`:174`) for both REST-ingested and internally created rows. It cannot infer origin. Enforcement is therefore two-layer:

1. `Agent_Controller` MUST reject `save_confirmed`, `save_discarded`, and `save_unverifiable` on the client POST path, before `Repository::create()`.
2. `normalize_entry()` MUST additionally require an internal marker for those three events that the REST path cannot set, so a bypass fails closed.

The client MUST NOT emit the three server-authored events under any circumstance.

### 5.6 Silence

An entity the user excludes from a selective Site Editor save fires no hook and emits nothing. That is recorded as **no persistence verdict**. Silence is not evidence that nothing persisted.

## 6. Server-side verification

### 6.1 Hooks

Normal post-backed saves use `wp_after_insert_post`, guarded against `wp_is_post_autosave()`, `wp_is_post_revision()`, and `DOING_AUTOSAVE`. This covers all six editor-lane surfaces, since `wp_template`, `wp_template_part`, and `wp_global_styles` are post types.

Reset-to-theme requires a **paired** hook, because the verdict spans a deletion. `class-wp-rest-templates-controller.php:375-383` shows a reset arriving as an *update* request that executes `wp_delete_post( $template->wp_id, true )` — so `wp_after_insert_post` never fires:

- `before_delete_post` captures the `wp_theme` relationship, slug, post type, **and the comparison snapshot**, while the row still exists.
- `after_delete_post` performs the verification.

`deleted_post` MUST NOT be used. `wp-includes/post.php:3996-3998` fires `deleted_post` and only then calls `clean_post_cache( $post )`; resolving inside `deleted_post` risks reading stale data. `after_delete_post` fires at the conclusion of `wp_delete_post()`, after cache cleanup.

### 6.2 Reset-to-theme verdicts

Deleting the database override does not settle presence. WordPress deletes the override and then resolves the fallback, which MAY return a theme template, a registered plugin template, or `null`. The returned identity and `source` MUST be inspected; unresolved identity is `save_unverifiable`.

| Verification result | Outcome |
|---|---|
| Recorded change provably absent | `save_discarded` |
| Recorded change provably present | `save_confirmed` |
| Target identity or comparison inconclusive | `save_unverifiable` |

`reason = reset_to_theme` is recorded independently of the verdict.

### 6.3 Batching and continuation

Outstanding applies are queried from existing columns (`document_scope_key`, `entity_type`, `entity_ref`) for rows with no terminal verdict.

- A count limit bounds each **processing batch**, not the candidate set. Remaining work continues through a durable cursor/job, following the existing `flavor_agent_reindex_patterns` cron precedent. A bare `LIMIT` MUST NOT silently abandon candidates.
- Deferred work MUST use the **captured saved version**, with the eligible apply set fixed at submission. Reading whatever content exists later would verify a different event.
- Age limits are an explicit retention policy. They still exclude old applies; where retained applies remain reportable after verification eligibility expires, the missing coverage MUST be shown explicitly.

### 6.4 Two distinct non-verdicts

| State | Meaning | Recorded as |
|---|---|---|
| `save_unverifiable` | Compared, could not tell | verdict row |
| not verified | Never compared (batch limit, aged out, apply not yet in server storage) | **no row**; surfaced as missing coverage |

Reaching a processing limit MUST NOT produce `save_discarded` or a comparison-based `save_unverifiable`.

### 6.5 Identity before comparison

A resolving block path does not establish identity. `BlockTreeMutator::resolve()` (`inc/Apply/BlockTreeMutator.php:16-29`) follows indexes only, with no name or identity check; reordering places a different block at the same path and `resolve()` returns it without complaint. Recorded target identity MUST be verified before comparing.

Comparison is operation-specific:

| Surface | Basis | Inconclusive when |
|---|---|---|
| block (attributes) | normalized `after.attributes` snapshot | path unresolvable, identity mismatch, unsupported extraction |
| block structural, template, template-part | per-operation evaluation against `after.operations` | identity ambiguous, operation not evaluable |
| global-styles, style-book | affected paths of saved user overrides | target path indeterminate |

Structural, template, and template-part rows record `after.operations` and a `structuralSignature` (`src/store/activity-undo.js:282-285`), not a single comparable block snapshot, so they MUST NOT be compared as one.

### 6.6 Normalization

Editor attributes may originate from HTML or from defaults, so raw `parse_blocks()` attributes are not universally equivalent to editor attributes.

The normalizer MUST take the **complete registered schema**, including `selector`, `attribute`, and `query` definitions, and MUST report unsupported extraction as `save_unverifiable`. `BlockTypeIntrospector` (`inc/Context/BlockTypeIntrospector.php:254-275`) is **not** sufficient as it stands: it copies `type`, `default`, `enum`, and `source` into a metadata entry, recording `source` as a string while performing no extraction and never reading `selector`, `attribute`, or `query`.

Only fields affected by the apply are compared.

A missing known JSON path is not automatically inconclusive. It proves an expected value absent when a value was expected, and confirms success when the operation intended a deletion.

### 6.7 Global Styles subject

Global Styles verification concerns **saved user overrides in `wp_global_styles`**, comparing only the paths affected by the apply. Effective inherited styles are outside this contract; including them would let a `theme.json` change read as a discarded recommendation.

### 6.8 Evidence record

Each verdict MUST record:

- the apply link
- the save-occurrence ID
- canonical entity identity
- a content fingerprint
- the verifier version
- `reason` and per-operation results
- the retained comparison snapshot, for deferred work

Post ID and `post_modified_gmt` are insufficient: multiple writes can share a timestamp, and a theme fallback has no replacement post timestamp. A fingerprint alone cannot reconstruct the comparison.

## 7. Client-side attempt reporting

### 7.1 Observation

One save-attempt controller is added at editor bootstrap, independent of which recommendation panel is open. It uses core-data entity identity and save/autosave state to identify relevant operations, with a narrowly scoped `apiFetch` middleware observing outgoing save requests and their results.

An attempt begins when a **qualifying entity save request is submitted**. Opening the save dialog, cancelling it, or excluding an entity emits nothing. Autosaves, previews, revisions, and Flavor Agent's own telemetry requests are excluded.

### 7.2 Granularity

Tracking follows the **physical entity being persisted**. Global Styles and Style Book applies targeting the same `wp_global_styles` record share one save occurrence. Templates and template parts saved together each receive their own occurrence.

At submission, the outstanding apply IDs associated with that entity are **frozen**. Later applies belong to a later save. This tracking is independent of the active scope and of the paginated activity display.

### 7.3 Correlation

A fresh `saveOccurrenceId` is generated for each submitted entity save and attached to that request through a namespaced header. The server reads it from the `WP_REST_Request` and carries it into the saved snapshot and verdicts of §6.

The `saveOccurrenceId` is a **correlation token only**. Server authorization, entity resolution, and comparison remain authoritative.

For batched saves, each relevant subrequest is annotated and its individual response inspected. An outer successful batch response does not establish that every member succeeded: `class-wp-rest-server.php:1899` returns `WP_Http::MULTI_STATUS` with individually enveloped per-member `responses`, and `:1838-1847` returns per-member errors for members failing pre-validation. Core-data routes multi-entity saves through this path (`@wordpress/core-data/build-module/batch/default-processor.mjs`).

### 7.4 Client events

Each client outcome links one apply to one save occurrence. Reporting counts **distinct occurrences** when measuring saves.

| Observation | Client action |
|---|---|
| Qualifying request submitted | Record `save_attempted` for each frozen apply |
| Request or corresponding batch member errors | Record `save_failed` for those same applies |
| Request returns successfully | Refresh linked server verdicts |
| Tab closes or observation is interrupted | Leave the result unknown |

### 7.5 Delivery and retries

Attempt events are persisted in a retryable outbox that survives scope changes and is independent of activity-display trimming. Deduplication is by `(saveOccurrenceId, applyId, event)`. Retrying telemetry preserves these identifiers; submitting another save creates a new occurrence.

Telemetry failure MUST NOT block or change WordPress's save result.

If an apply has not reached server storage when §6.3 freezes its candidates, missing verification coverage is recorded for that occurrence. It MUST NOT be retrospectively attached to that saved version, and remains eligible for a later save.

### 7.6 Reconciliation

After either request success or failure, verdicts are fetched through explicit apply/occurrence lookup, with bounded retries while server work is queued. Request status, persistence verdict, and missing coverage are kept separate. In-flight attempts retain their original identity across navigation.

## 8. Metrics and admin surface

### 8.1 Metrics

Existing outputs of `RecommendationOutcomeMetrics::evaluate()` keep their definitions and values. Nothing already reported moves.

Added:

- `saveAttemptedOccurrences`
- `savePersistedRate`, `saveDiscardedRate`, `saveUnverifiableRate`
- `verificationCoverageRate`, `unverifiedCoverageCount`
- `applyConversionRateByLane`, bucketed `editor-state` / `server-executed` / `unknown`

Save-denominated rates count distinct save occurrences; per-apply rates count applies. Historical rows fall in `unknown` and are excluded from persisted rates — never inferred. All reads go through `Activity\PersistenceAssurance`.

### 8.2 Admin surface

`Settings > AI Activity` presents three independent facts per row — request status, persistence verdict, and coverage — which MUST NOT be collapsed into a single badge. `save_unverifiable` and *not verified* render distinctly. The admin UI uses the same resolver as metrics.

## 9. Verification

Scenario coverage MUST include: selective saves, mixed batch results, draft autosaves, repeated saves, edits during an in-flight save, reset-to-theme, scope changes, telemetry retries, and a lost response after successful server persistence.

- **PHP:** per-surface comparators, hook guards, the `before_delete_post` / `after_delete_post` pair, batch continuation, evidence-record shape, REST authorship rejection.
- **JS:** attempt controller, middleware scoping, occurrence freezing, outbox dedup on `(saveOccurrenceId, applyId, event)`, per-member batch handling.

This change touches multiple surfaces and shared subsystems, so `docs/reference/cross-surface-validation-gates.md` applies in full, plus `npm run check:docs` for the contract changes.

## 10. Out of scope

- Driving or initiating saves (D1)
- Effective inherited style verification (§6.7)
- Re-cutting or backfilling `applyConversionRate` (D4)
- Adaptive ranking consumption of persistence signals
- Post-blocks external apply, which already performs guarded persistence with read-back verification

## 11. Known blockers

Verification on baseline `fd28015` returned `status: fail` (5 passed, 3 failed, 1 skipped). These are pre-existing and not introduced by this design, but they block the gate requirement in §9:

| Step | State |
|---|---|
| `unit` | 21 of 114 suites fail to run. `uuid@14.0.0` is pure ESM (`"type": "module"`, no `main`, no `exports['.'].require`), reached transitively through `@wordpress/blocks` → `api/factory.ts`. 1,263 tests passed; roughly 611 never executed. Jest also reports haste collisions from `.worktrees/` and `output/playground-tmp/` |
| `e2e-playground` | Harness cannot start: `Could not move .../gutenberg.23.9.0/gutenberg to /wordpress/wp-content/plugins/gutenberg: Operation not permitted`, with `lockWholeFile: unlock failed` — Windows file locking |
| `e2e-wp70` | 31 passed, 1 failed: `@wp70-site-editor global styles surface keeps stale results visible but disables review and apply until refresh`. Not yet triaged |

`build`, `lint-js`, `lint-plugin`, `lint-php`, and `test-php` passed. PHP was green at `OK (2258 tests, 10744 assertions)`.
