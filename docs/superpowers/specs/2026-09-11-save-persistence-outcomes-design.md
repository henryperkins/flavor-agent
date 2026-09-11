# Flavor Agent Save-Persistence Outcomes 1.1

- **Status:** Canonical design contract; no runtime implementation exists yet
- **Contract version:** `1.1`
- **Date:** 2026-09-11
- **Baseline commit:** `fd28015` (`master`)
- **Revision:** `1.1` applies 28 findings from an adversarial verification pass over `1.0`
- **Scope:** What an Apply claims — to the editor who clicked it and to the operator reading the audit — and how persistence is proven

The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**, and **MAY** are normative.

## 1. Purpose

`docs/flavor-agent-wordpress-context-audit.md` records that an ordinary editor Apply means Flavor Agent changed editor state and recorded the operation — not that WordPress saved the document.

That overclaim is made to **two audiences**, and this contract addresses both:

- the **editor** who clicked Apply and is told it succeeded (§8.3)
- the **operator** reading `Settings > AI Activity` and the learning report (§6, §8.1, §8.2)

A contract that fixed only the second would leave the overclaim intact in the one moment it is made to a human.

## 2. Implementation status

This is a design contract. None of it is implemented.

The current repository contains:

- ten recommendation outcome events (`src/store/recommendation-outcomes.js`, `inc/Activity/RecommendationOutcome.php`)
- seven `apply_*` activity types. **Six** are written by the editor lane (`src/store/activity-undo.js`); **five** by the governed external lane (`inc/Abilities/ApplyAbilities.php`). **Four** are dual-lane (`apply_template_suggestion`, `apply_template_part_suggestion`, `apply_global_styles_suggestion`, `apply_style_book_suggestion`); `apply_suggestion` and `apply_block_structural_suggestion` are editor-lane only; `apply_post_blocks_suggestion` is external-lane only
- `RecommendationOutcomeMetrics::evaluate()` with `applyConversionRate` and `reviewApplyConversionRate`
- governed server persistence with read-back verification for external applies (`inc/Apply/ExistingPostContentWriter.php`)

The current repository contains **no** save-lifecycle code. There is not one reference to `savePost`, `isEditedPostDirty`, `isSavingPost`, `didPostSaveRequestSucceed`, or `saveEditedEntityRecord` anywhere in `src/`, tests included. This is greenfield.

## 3. Problem statement

Two distinct guarantees share four row types, and the metric that reads them measures only one lane.

An `apply_template_suggestion` row written by `src/store/activity-undo.js` means editor state changed, with no persistence proof. The identical row type written by `inc/Abilities/ApplyAbilities.php` means a governed server write was *requested*.

Because external-lane rows carry no `request.recommendation`, `RecommendationOutcomeMetrics::evaluate()` never links them to a shown set — every external-lane apply falls into `unlinkedApplyCount` (`inc/Activity/RecommendationOutcomeMetrics.php:99-108`). **The defect is not that both lanes are counted identically. It is that `applyConversionRate` silently measures the editor lane alone while reading as a whole-product figure.**

Two further facts make the editor lane's claim empty:

1. `src/store/activity-history.js:628` stamps every non-diagnostic editor-lane entry `executionResult: 'applied'` at creation, before any save, and `inc/REST/Agent_Controller.php:694-696` persists it verbatim. (`inc/Activity/Serializer.php:37`'s `?? 'applied'` fallback is never reached for these rows.) For the editor lane, `executionResult = 'applied'` carries no information about persistence at all.
2. `inc/Abilities/ApplyAbilities.php:177` creates governed rows with `executionResult => 'pending'` *before* any write — identically at `:365`, `:551`, `:771`, one per external lane — and those rows may never be approved, expiring on the `PENDING_TTL_FILTER`. Origin therefore does not imply verification either.

## 4. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Flavor Agent **observes** saves; it never drives them | Keeps the save decision with the human, consistent with a review-gated governance layer |
| D2 | Persistence attributes **per apply**, verified | Only option answering "did this recommendation survive to publish?" |
| D3 | Both save mechanisms covered in one pass | Four of six editor-lane surfaces are Site Editor only |
| D4 | Lane labelling forward-only; **definitions** of existing metrics unchanged | Values will shift — see §8.1. No stored row is rewritten |
| D5 | Persistence is **server-verified**; the client reports only attempts | A client-asserted `save_confirmed` replaces an unproven claim with a more specific unproven claim |
| D6 | Assurance is **derived**; no second mutable assurance column | Immutable evidence columns (§5.3) are not assurance state and do not conflict |
| D7 | Editor-time success language MUST NOT assert persistence | The overclaim is made to a human at apply time; fixing only reporting leaves it standing |

## 5. Contract and vocabulary

### 5.1 Events

Five events are added.

| Event | Author | Meaning |
|---|---|---|
| `save_attempted` | client | User submitted a qualifying entity save for an entity holding ≥1 candidate apply |
| `save_failed` | client | The client observed an error during the save request; **persistence is undetermined** |
| `save_confirmed` | server | WordPress persisted; this apply's recorded change was **verified present** |
| `save_discarded` | server | WordPress persisted; this apply's recorded change was **verified absent** |
| `save_unverifiable` | server | WordPress persisted, but the change could **not be conclusively verified** either way |

Registration is **asymmetric**, because `OUTCOME_EVENTS` is the client's emit gate, not a display vocabulary:

- The two client-authored events are added to `OUTCOME_EVENTS` (`src/store/recommendation-outcomes.js:11-22`) and to `RecommendationOutcome::EVENTS`.
- The three server-authored events are added to `RecommendationOutcome::EVENTS` **only**. They MUST NOT be added to `OUTCOME_EVENTS`; membership there is the sole condition for client emission (`:24`, `:847`), so adding them is exactly what would let the client author them, contradicting §5.5.

Each of the five MUST also receive a distinct entry in the parallel `RecommendationOutcome::EVENT_LABELS` (`inc/Activity/RecommendationOutcome.php:69-80`) and, for the two client events, `OUTCOME_LABELS` (`src/store/recommendation-outcomes.js:34-66`). `EVENT_LABELS` is dereferenced without a null-coalesce guard at `:226`, so an omitted entry yields a PHP warning and an empty `suggestion` column — the column §8.2 relies on.

`save_unverifiable` MUST remain separate from both success and discard in all reporting.

### 5.2 Precedence and terminality

Server-authored verdicts are authoritative. `save_failed` MUST NOT override, suppress, or delay one; a `save_confirmed` MAY legitimately coexist with an earlier `save_failed`. Reporting MUST NOT read `save_failed` as a negative persistence verdict.

Terminality is **not uniform**:

- `save_confirmed` and `save_discarded` are terminal.
- `save_unverifiable` is **not** terminal. It settles one occurrence but leaves the apply in the eligible set, so a later save MAY produce a conclusive verdict. §6.3's eligible-set predicate is therefore *no `save_confirmed` and no `save_discarded`*.
- An apply MAY accumulate multiple `save_unverifiable` rows, one per occurrence. `saveUnverifiableRate` counts **applies whose latest verdict is `save_unverifiable`**, not rows.

An error returning after a successful write is not theoretical: `class-wp-rest-templates-controller.php:392-413` writes via `wp_update_post()` / `wp_insert_post()` and can still return an error from `update_additional_fields_for_object()` afterwards — and since `wp_after_insert_post()` is only called at `:421`, that path returns after the row was written but before verification would fire. A lost response can likewise follow a successful write.

### 5.3 Schema

Three nullable columns are added to the activity table:

| Column | Purpose |
|---|---|
| `apply_lane` | Execution origin: `editor-state`, `server-executed`, or `NULL` (historical = unknown). Immutable |
| `linked_apply_activity_id varchar(191) NULL` | Verdict → apply link |
| `save_occurrence_id varchar(64) NULL` | Verdict → occurrence link |

with `KEY save_lifecycle_link (linked_apply_activity_id, save_occurrence_id)`. The latter two are **immutable evidence**, not assurance state, so they do not conflict with D6 — which forbids a second *mutable assurance* column. Without them the §5.4 lookup degrades to a full-table JSON scan.

`apply_lane` MUST NOT be read as evidence of verification (§3, item 2).

Delivering these columns requires four coordinated changes that `RecommendationOutcome::normalize_entry()` alone cannot provide:

1. `Serializer::normalize_entry()` (`inc/Activity/Serializer.php:21-42`) MUST gain the new keys. It returns a **fixed 14-key array with no passthrough** and runs at `Repository::create():164`, before any other validation.
2. `Repository::create()`'s `$record` array (`:204-222`) MUST map them to columns.
3. `Serializer::hydrate_row()` (`:241-259`) and `ADMIN_PROJECTION_SELECT_SQL` (`:31`) MUST expose them on read, or metrics and the admin UI see nothing.
4. `Repository::SCHEMA_VERSION` (`:13`, currently `5`) MUST be bumped, since `maybe_install()` re-runs `dbDelta` only on a version increase (`:51-55`). The bump also schedules an admin-projection backfill on every upgrading site (`:144-146`); stored projection values are preserved, but the admin surface falls back to full-row candidate selection until the cron drains. This is an accepted temporary cost, not a historical rewrite under D4.

### 5.4 Derived assurance

Achieved assurance MUST be derived from durable evidence, through one shared resolver `Activity\PersistenceAssurance`, serving both metrics and the admin surface.

1. `executionResult = 'applied'` alone MUST NOT establish persistence, for any lane.
2. Reporting MUST load linked outcome rows by explicit apply/occurrence lookup, beyond the displayed page or date window. Because `Repository::delete_before()` (`:1568-1597`) prunes purely by `created_at`, verdict rows MUST be exempted from the prune while their linked apply row survives — otherwise requirement 3 is unsatisfiable.
3. Historical rows without sufficient evidence remain `unknown`. Historical reports MUST replay the recorded verdict and MUST NOT re-check today's content.
4. **Absence of a verdict MUST NOT be treated as evidence of non-persistence.** Applies with no terminal verdict — for any reason, not only historical `unknown` rows — are excluded from the numerator **and** denominator of every persistence rate, and counted only in `unverifiedCoverageCount` / `verificationCoverageRate`.

### 5.5 Authorship enforcement

Validation is **not** a single chokepoint. `Serializer::normalize_entry()` and the `ExternalApplyDecisionClaim` check both run before `RecommendationOutcome::normalize_entry()` (`inc/Activity/RecommendationOutcome.php:93`, reached from `Repository::create()` `:174`), which cannot infer origin. Enforcement is therefore:

1. `Agent_Controller` MUST reject the three server-authored events on the client POST path, before `Repository::create()`.
2. Server-authored verdicts are written through a dedicated internal writer that sets a **module-private static authorship flag** around the `Repository::create()` call. `normalize_entry()` gains a static accessor — not an entry field — and MUST reject the three events when the flag is unset.

A marker carried **inside the entry array is explicitly not acceptable**: a top-level key is stripped by `Serializer::normalize_entry()`'s fixed whitelist before validation runs, and a nested key is freely settable over REST (`inc/REST/Agent_Controller.php:684-696` → `sanitize_structured_value()` at `:416-420`, which applies no key filter).

### 5.6 Silence

An entity excluded from a selective save fires no hook and emits nothing. That is recorded as **no persistence verdict**. Silence is not evidence that nothing persisted.

## 6. Server-side verification

### 6.1 Hooks and guards

Normal post-backed saves use `wp_after_insert_post`, covering all six editor-lane surfaces since `wp_template`, `wp_template_part`, and `wp_global_styles` are post types.

Guards:

- `wp_is_post_autosave()`, `wp_is_post_revision()`, and `DOING_AUTOSAVE`. This is sufficient for the new-draft case: all autosaves route through `WP_REST_Autosaves_Controller::create_item()`, which defines `DOING_AUTOSAVE` at `:213-214`, including the author-draft path that writes to the post itself at `:253`.
- **Status transitions to or from `trash` MUST be skipped.** `wp_trash_post()` / `wp_untrash_post()` reach `wp_after_insert_post` with `post_content` unchanged (`post.php:4130-4135`); trashing MUST NOT yield `save_confirmed`.

**Double-fire is mandatory to handle.** `wp_after_insert_post` fires **twice** per REST save for `wp_template` and `wp_template_part`: `WP_REST_Templates_Controller::update_item()` calls `wp_update_post( wp_slash( (array) $changes ), false )` at `:392` — the `false` is `$wp_error`, leaving `$fire_after_hooks` at its default `true` — and then calls `wp_after_insert_post( $post, $update, $post_before )` explicitly at `:421`. Server-side idempotency (§6.8) is therefore mandatory, not optional.

**Verification is NOT occurrence-gated.** When the hook fires for a save carrying no Flavor Agent occurrence header — Quick Edit, bulk edit, WP-CLI, a direct REST write, a second browser tab, another user, a revision restore — verification still runs and records a verdict with a `NULL` `save_occurrence_id` and `origin = unobserved`. Gating on the header would make the single most valuable discard signal, a colleague overwriting the AI change, permanently invisible, and would leave D2's question unanswerable.

### 6.2 Reset-to-theme and trash routes

A reset arrives as an *update* request that executes a permanent delete (`class-wp-rest-templates-controller.php:375-383`), so `wp_after_insert_post` never fires. It requires a paired hook:

- `before_delete_post` (`post.php:3887`, firing before `wp_delete_object_term_relationships()` at `:3891`) captures the `wp_theme` relationship, slug, post type, and the comparison snapshot while the row still exists.
- `after_delete_post` (`:4019`) performs verification, after `clean_post_cache( $post )` at `:3998`.

`deleted_post` (`:3996`) MUST NOT be used — it fires before cache cleanup.

The Site Editor's **non-force** template delete runs `wp_trash_post`, not `wp_delete_post` (`:551`), and only falls through to the delete pair when `EMPTY_TRASH_DAYS` is falsy. Both routes MUST be handled.

Deleting the override does not settle presence: WordPress then resolves the fallback, which MAY return a theme template, a registered plugin template, or `null`. `get_block_template()` runs an uncached `WP_Query`, so resolving inside `after_delete_post` is safe. The returned identity and `source` MUST be inspected.

| Verification result | Outcome |
|---|---|
| Recorded change provably absent | `save_discarded` |
| Recorded change provably present | `save_confirmed` |
| Target identity or comparison inconclusive | `save_unverifiable` |

`reason = reset_to_theme` is recorded independently of the verdict.

### 6.3 Eligible set, batching, continuation

The **server-side eligible set** is: applies for this entity with no `save_confirmed` and no `save_discarded`, **and** whose `undo.status` is not `undone` (§6.9).

- A count limit bounds each **processing batch**, not the candidate set. Remaining work continues through a durable cursor/job, following the `flavor_agent_reindex_patterns` cron precedent. A bare `LIMIT` MUST NOT silently abandon candidates.
- Deferred work MUST use the captured saved version, with the eligible set fixed at submission.
- The verification age limit MUST be a distinct, shorter option than `flavor_agent_activity_retention_days`, and §5.4 requirement 2's prune exemption governs their interaction.

### 6.4 Two distinct non-verdicts

| State | Meaning | Recorded as |
|---|---|---|
| `save_unverifiable` | Compared, could not tell | verdict row (non-terminal) |
| not verified | Never compared | **no row**; derived at read time (§7.5) |

Reaching a processing limit MUST NOT produce `save_discarded` or a comparison-based `save_unverifiable`.

### 6.5 Identity before comparison

A resolving block path does not establish identity. `BlockTreeMutator::resolve()` (`inc/Apply/BlockTreeMutator.php:16-29`) follows indexes only, with no name or identity check.

| Surface | Basis | Inconclusive when |
|---|---|---|
| block (attributes) | normalized `after.attributes` snapshot | path unresolvable, identity mismatch, unsupported extraction |
| block structural | `after.operations` + `structuralSignature` (`src/store/activity-undo.js:279,283`) | identity ambiguous, operation not evaluable |
| template, template-part | `after.operations` / `before.operations` only — **no signature** (`:313-314`, `:342-343`) | identity unestablished from `target.templateRef` / `target.templatePartRef` |
| global-styles, style-book | affected paths of saved user overrides | target path indeterminate |

None of the three operation-based shapes is a single comparable block snapshot, so none MUST be compared as one. For template and template-part the verifier MUST establish identity from the target refs and per-operation `before` state, since no recorded signature exists.

### 6.6 Normalization

Editor attributes may originate from HTML or defaults, so raw `parse_blocks()` attributes are not universally equivalent to editor attributes.

The normalizer MUST take the **complete registered schema**, including `selector`, `attribute`, and `query`, and MUST report unsupported extraction as `save_unverifiable`. `BlockTypeIntrospector` (`inc/Context/BlockTypeIntrospector.php:254-275`) is **not** sufficient: it copies `type`, `default`, `enum`, and `source` into a metadata entry, recording `source` as a string while performing no extraction and never reading `selector`, `attribute`, or `query`.

Only fields affected by the apply are compared. A missing known JSON path is not automatically inconclusive: it proves absence when a value was expected, and confirms success when the operation intended a deletion.

### 6.7 Global Styles subject

Verification concerns **saved user overrides in `wp_global_styles`**, comparing only paths affected by the apply. Effective inherited styles are out of contract; including them would let a `theme.json` change read as a discarded recommendation.

### 6.8 Evidence and idempotency

Each verdict MUST record: the apply link, the save-occurrence ID **or `NULL` when the save was unobserved**, canonical entity identity, a content fingerprint, the verifier version, `reason`, per-operation results, and a **reference to** the retained comparison snapshot.

Post ID and `post_modified_gmt` are insufficient: multiple writes can share a timestamp, and a theme fallback has no replacement post timestamp.

**Snapshot lifecycle.** The comparison snapshot is a separate, earlier artifact, written at capture time keyed by `(saveOccurrenceId, entityType, entityRef)`, before any verdict exists. It is the input §6.3's deferred job reads. Snapshots are deleted once every apply frozen into their occurrence has a terminal verdict, or when the verification age limit expires, whichever comes first.

**Idempotency.** Verdict rows are idempotent on `(applyId, saveOccurrenceId, verifierVersion)`. A second pass for the same tuple MUST update the existing record in place, never insert a second row.

**Scope key.** A verdict row inherits the `document.scopeKey` of the apply row it links to, verbatim. It MUST NOT be written with a derived or empty scope key: `Repository::create()` rejects an empty one (`:183-192`), and `Permissions::can_access_context_values()` escalates an unresolvable scope to `manage_options` (`inc/Activity/Permissions.php:110-112`), which would hide the verdict from the editor who authored the apply.

### 6.9 Undo and verdicts

Undo is durable, server-side, queryable state (`undo_state`, `inc/Activity/Repository.php:88`). Without a rule, D2 breaks in both directions: a confirmed-then-undone apply counts as persisted forever, and an undone-then-saved apply yields `save_discarded` for a change the user deliberately reverted.

1. The §6.3 eligible-set predicate excludes applies whose `undo.status` is `undone`, so an undone-then-saved apply is never frozen and never yields `save_discarded`.
2. An undo recorded **after** a terminal verdict invalidates that verdict for reporting. `Activity\PersistenceAssurance` MUST resolve `undone` ahead of `save_confirmed`.
3. Undo is a fourth independent fact in §8.2's row model. A row MUST NOT render "Persisted" with no indication it was undone.
4. Undone applies are excluded from **both** the numerator and denominator of `savePersistedRate` (§8.1).

## 7. Client-side attempt reporting

### 7.1 Observation

One save-attempt controller is added at editor bootstrap, independent of which panel is open, using core-data entity identity and save/autosave state, with a narrowly scoped `apiFetch` middleware observing outgoing save requests and their results. `WP_REST_Server::get_headers()` (`class-wp-rest-server.php:1987-2011`) passes every `HTTP_*` key through with no allowlist, and api-fetch's default handler and nonce middleware both spread rather than replace `options.headers`, so a namespaced header reaches `WP_REST_Request` intact.

An attempt begins when a qualifying entity save request is **submitted**. Opening the save dialog, cancelling it, or excluding an entity emits nothing. Autosaves, previews, revisions, and Flavor Agent's own telemetry requests are excluded.

### 7.2 Granularity and candidate freeze

Tracking follows the **physical entity being persisted**. Global Styles and Style Book applies targeting the same `wp_global_styles` record share one occurrence. Templates and template parts saved together each receive their own.

At submission the client freezes a **client-side candidate set**: applies recorded in this session for the entity being persisted, for which the client holds no cached terminal verdict. This is explicitly best-effort and MAY be a superset of the server's eligible set (§6.3). The server intersects the two and **the server's result is authoritative**. This tracking is independent of the active scope and of the paginated activity display.

### 7.3 Correlation

A fresh `saveOccurrenceId` is generated per submitted entity save and attached through a namespaced header. The server reads it from the `WP_REST_Request` and carries it into §6's snapshot and verdicts. It is a **correlation token only**; authorization, entity resolution, and comparison remain authoritative server-side.

Multi-entity Site Editor saves are **not batched**. `saveDirtyEntities` dispatches one `saveEditedEntityRecord` per entity (`@wordpress/editor/build-module/store/private-actions.mjs:111`), producing N independent requests, each annotated and inspected individually. `/batch/v1` is not on any editor save path in this baseline — it has zero references in `@wordpress/editor` or `@wordpress/edit-site`.

### 7.4 Client events

| Observation | Client action |
|---|---|
| Qualifying request submitted | Record `save_attempted` for each frozen apply |
| Request errors | Record `save_failed` for those same applies |
| Request returns successfully | Refresh linked server verdicts |
| Tab closes or observation is interrupted | Leave the result unknown |

### 7.5 Delivery, retries, missing coverage

Attempt events are persisted in a retryable outbox surviving scope changes, independent of activity-display trimming, deduplicated by `(saveOccurrenceId, applyId, event)`. Telemetry failure MUST NOT block or change WordPress's save result.

If an apply has not reached server storage when §6.3 freezes its candidates, **no verdict row is written**. The gap is derived at read time by `Activity\PersistenceAssurance` as *(applies with a `save_attempted` for this occurrence) minus (applies with a terminal verdict for this occurrence)*, and surfaced as missing coverage. It MUST NOT be retrospectively attached to that saved version; the apply remains eligible for a later save.

### 7.6 Reconciliation

Verdicts are fetched through explicit apply/occurrence lookup, with bounded retries while server work is queued. Request status, persistence verdict, and missing coverage are kept separate. In-flight attempts retain their identity across navigation. An apply the client froze but the server had already settled is reported with the existing verdict, **not re-verified**.

## 8. Reporting and surfaces

### 8.1 Metrics

Existing outputs of `RecommendationOutcomeMetrics::evaluate()` keep their **definitions**. Their **values will shift on live sites** unless save-lifecycle rows are excluded from the report sample: the learning report is computed over the newest `DEFAULT_REPORT_ROW_LIMIT` (500) rows (`inc/Activity/Repository.php:24`, `:407`), and the new rows would evict `shown` / `selected_for_review` / `apply_*` rows out of that window.

Therefore: **save-lifecycle outcome rows MUST be excluded from the learning-report sample** by a baseline clause in `build_admin_sql_filter_clauses()`, or the sample MUST be drawn per row-class.

| Metric | Numerator | Denominator |
|---|---|---|
| `saveAttemptedOccurrences` | distinct `saveOccurrenceId` with ≥1 `save_attempted` | — (count) |
| `savePersistedRate` | applies whose latest verdict is `save_confirmed` | applies with a terminal verdict, excluding undone |
| `saveDiscardedRate` | applies whose latest verdict is `save_discarded` | applies with a terminal verdict, excluding undone |
| `saveUnverifiableRate` | applies whose latest verdict is `save_unverifiable` | applies with any verdict |
| `verificationCoverageRate` | applies with any verdict | applies with ≥1 `save_attempted` |
| `unverifiedCoverageCount` | per §7.5 derivation | — (count) |

All are **per-apply** except `saveAttemptedOccurrences`. Mixed-verdict occurrences therefore need no scoring rule.

`applyConversionRateByLane` is **not** a like-for-like comparison — the lanes do not share a surface mix (§2), and external-lane rows never reach `applyConversionRate` at all (§3) — and MUST be labelled as such wherever surfaced.

Value stability and shape stability differ: adding keys changes the returned shape, which §9 addresses.

### 8.2 Admin surface

`Activity\PersistenceAssurance` resolves **server-side only**. The activity REST payload gains resolved, read-only fields per row (`persistenceVerdict`, `verificationCoverage`, `requestStatus`, `undoState`); `src/admin/activity-log-utils.js` MUST render them verbatim and MUST NOT re-derive them, in contrast to the existing client-side derivation at `:1590-1629`.

The existing single status badge stays as request status; the other facts render as separate adjacent indicators. `save_unverifiable` and *not verified* render distinctly. A row MUST NOT render "Persisted" without surfacing an undo (§6.9).

### 8.3 Editor-time claim

Per D7, editor-time success language MUST NOT assert persistence. Where the Inspector panel or apply toast currently confirms an apply as done, it MUST convey that the change is in the editor and not yet saved, until a save occurs.

Exact wording, i18n, and interaction with `buildToastForActivity` are implementation concerns. The normative requirement is only that no editor-time affordance state or imply that WordPress has stored the change when it has not.

## 9. Verification

Scenarios: selective saves, **partial failure across a multi-entity save**, draft autosaves, repeated saves, edits during an in-flight save, reset-to-theme, **trash / untrash / status transitions**, **an unobserved external save (Quick Edit, WP-CLI, second session, another user)**, **undo before and after a verdict**, scope changes, telemetry retries, and a lost response after successful server persistence.

- **PHP:** per-surface comparators; hook guards; the `before_delete_post` / `after_delete_post` pair; the trash route; **`wp_after_insert_post` double-fire idempotency**; batch continuation; evidence-record shape; REST authorship rejection; the static authorship flag. **`tests/phpunit/RecommendationOutcomeEvaluationTest.php:27-40` pins `evaluate()`'s exact return with `assertSame` and will fail on the added keys** — extend it to assert the new keys' defaults so the contract stays pinned.
- **JS:** attempt controller; middleware scoping; candidate freeze; outbox dedup on `(saveOccurrenceId, applyId, event)`; per-entity (not per-batch-member) handling.

`docs/reference/cross-surface-validation-gates.md` applies in full, plus `npm run check:docs` — which covers `docs/reference/shared-internals.md:83`, documenting `OUTCOME_EVENTS`.

## 10. Out of scope

- Driving or initiating saves (D1)
- Effective inherited style verification (§6.7)
- Re-cutting or backfilling `applyConversionRate` (D4). Making `ApplyAbilities` write `request.recommendation` would move that rate and is deferred
- Adaptive ranking consumption of persistence signals
- Post-blocks external apply, which already performs guarded persistence with read-back verification
- **Pattern-inserter conversion.** Pattern insertions mutate the document with the same unproven-persistence claim, but are recorded as outcome rows rather than `apply_*` rows and are deferred to a follow-on contract. Until then `patternInsertionRate` MUST be labelled as an editor-state metric wherever it is surfaced

## 11. Open questions

Raised by the verification pass, not yet decided:

1. **Verdict authorship.** `Repository::create()` stamps `user_id => get_current_user_id()` (`:206`). A verdict written during a *different* user's save would be attributed to the saver, not the applier, while `KEY user_created (user_id, created_at)` (`:115`) and the admin surface's per-user filtering treat that column as meaningful. Both an audit-integrity and a privacy question.
2. **Comparison cost inside the save request.** `wp_after_insert_post` runs synchronously in the user's save; §6.5/§6.6 require parsing and schema-normalizing content. No latency budget is set, and write amplification is unbounded (`save_attempted` per apply per save).
3. **Ring III attestation interaction.** `inc/Attestation/Repository.php:62,68,260-268` links attestations to apply rows. A `save_discarded` on an attested lane means the signed statement and the verdict disagree about the same subject. `docs/reference/governance-layer.md` likely needs updating.
4. **Client storage version.** `src/store/activity-history.js:19` pins `ACTIVITY_STORAGE_VERSION = 4` and `:301-302` discards lower-versioned cache. Whether this bumps is unstated.

## 12. Known blockers

Verification on baseline `fd28015` returned `status: fail` (5 passed, 3 failed, 1 skipped). Pre-existing, not introduced by this design, but they block §9:

| Step | State |
|---|---|
| `unit` | 21 of 114 suites fail to run. `uuid@14.0.0` is pure ESM, reached through `@wordpress/blocks` → `api/factory.ts`. 1,263 tests passed; ~611 never executed |
| `e2e-playground` | Harness cannot start: `Could not move .../gutenberg.23.9.0/gutenberg to /wordpress/wp-content/plugins/gutenberg: Operation not permitted` — Windows file locking |
| `e2e-wp70` | 31 passed, 1 failed: `@wp70-site-editor global styles surface keeps stale results visible but disables review and apply until refresh`. Not triaged |

`build`, `lint-js`, `lint-plugin`, `lint-php`, and `test-php` passed. PHP was green at `OK (2258 tests, 10744 assertions)`.
