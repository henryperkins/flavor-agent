# Save-Persistence Outcomes Implementation Plan

> **For agentic workers:** Use the approved save-persistence design and implement the checked tasks in order. Independent verification repairs may run in parallel under `superpowers:dispatching-parallel-agents`; coordinate file ownership before edits.

**Goal:** Repair the recorded verification failures and distinguish editor applies from server-verified persistence without changing historical outcome definitions.

**Architecture:** A save observer captures immutable entity content, registered attribute schemas, and the server-side candidate boundary. Durable bounded jobs compare each recorded apply with that saved version and write authoritative outcome rows. One server resolver supplies metrics and activity views; an editor-wide middleware records attempts through a separate retryable outbox and never initiates saves.

**Tech Stack:** WordPress 7.0+, PHP 8.2+, Gutenberg/core-data/api-fetch, PHPunit, Jest, Playwright; existing custom activity table and WordPress cron.

**Spec:** `docs/superpowers/specs/2026-09-11-save-persistence-outcomes-design.md`

## Global constraints

- Clients author only `save_attempted` and `save_failed`; only the internal server writer authors `save_confirmed`, `save_discarded`, and `save_unverifiable`.
- Never drive saves, reinterpret existing events, rewrite historical rows, use current content to replay historical verdicts, or infer persistence from an HTTP success or `executionResult`.
- Global Styles comparisons concern stored user overrides. Pattern-inserter conversion and adaptive ranking remain outside this contract.
- Preserve absent evidence, inconclusive comparison, partial persistence, and editor undo as distinct facts.
- Sequence verdicts by capture order, not worker completion. Freeze content, schemas, identity, and candidates before comparisons.
- Keep snapshots and outboxes private; preserve original apply authorization and the distinction between applier and saver.
- Make local changes reviewable. Publication, release, billing changes, and closing GitHub discussions are separate operations.

## Task 1: Restore the recorded verification gates

**Files:** restore installed dependencies from the existing lockfiles; add the real UUID/Jest interoperability regression; update `playwright.config.js`, Playground blueprint/harness tests, and the existing Global Styles stale-results browser case. The locked Jest command and package versions remain unchanged.

- [x] Reproduce one Jest ESM loading failure and trace the VM/CommonJS boundary. Preserve real UUID generation.
- [x] Reproduce the Playground install failure and repair its Windows bootstrap path while retaining exact Gutenberg 23.9.0.
- [x] Trace the Global Styles context change before its first Review click. Repair the cause or the fixture's setup race; preserve stale-result blocking assertions.
- [x] Run the closest regressions; collect final integrated evidence during Task 6.

## Task 2: Resolve contract decisions and persist authoritative evidence

**Files:** design spec; `inc/Activity/Repository.php`, `Serializer.php`, `RecommendationOutcome.php`; new persistence occurrence storage and internal outcome writer; `inc/REST/Agent_Controller.php`; corresponding PHPUnit tests.

**Interfaces:** entry fields `applyLane`, `linkedApplyActivityId`, `saveOccurrenceId`; snapshot fields `saveOccurrenceId`, `saveSequence`, `origin`, `entity`, `content`, `schemas`, `reason`, `capturedAt`, and a frozen eligible boundary. `entity` carries `postId`, `postType`, `type`, `ref`, and template/theme identity when relevant.

- [x] Pin decisions: verdict ownership follows the applier, saver is separate evidence; background comparisons use bounded batches; attestations remain historical; existing activity cache version remains readable alongside a separately versioned outbox.
- [x] Add failing tests for schema round trips, reserved event rejection, immutable lane/link fields, original author access, idempotent verdicts, and preservation during pruning.
- [x] Add schema migration and durable occurrence metadata with monotonically increasing capture sequence. Retain eligibility/ordering metadata when comparison content is purged.
- [x] Add the private authorship scope and REST rejection before normalization. Public entry fields cannot activate the internal writer.
- [x] Validate with targeted `ActivityRepositoryTest`, `ActivitySerializerTest`, `RecommendationOutcomeTest`, and new persistence tests.

## Task 3: Capture saves and compare affected changes

**Files:** new `inc/Activity/PersistenceComparison.php`, normalizer, save observer and occurrence worker; `flavor-agent.php`, `uninstall.php`; new PHPUnit suites.

**Interfaces:** `PersistenceComparison::compare( array $apply, array $snapshot ): array` returns `event`, `reason`, and `operations` (each operation includes a `state` of `present`, `absent`, or `inconclusive`, plus its reason). Snapshots use the Task 2 fields; comparators do not query today's content or mutate WordPress.

- [x] Add fixtures for all six editor apply types, affected JSON-path deletion, mismatched/ambiguous identity, schema defaults and HTML extraction, partial operation persistence, and unsupported extraction.
- [x] Implement comparison from frozen content and complete pinned schema slices. All present means confirmed; all absent means discarded; mixed present/absent means discarded with `partial_persistence`; any inconclusive result means unverifiable.
- [x] Add hook tests for autosave/revision/trash guards, template double-fire, two headerless saves, hard reset fallback, and later saves after undo.
- [x] Capture correlation only from matching REST requests; generate distinct server occurrences for unobserved saves. Continue queued work over the same frozen boundary and content.
- [x] Test out-of-order jobs, late-arriving applies, pruning, retries, and age expiry without fabricated verdicts.

## Task 4: Observe client attempts and reconcile server evidence

**Files:** new editor save controller/outbox and tests; `src/store/activity-history.js`, `recommendation-outcomes.js`, editor bootstrap; activity lookup route.

**Interfaces:** a single editor-wide api-fetch middleware matches actual non-autosave post-type entity writes, generates `X-Flavor-Agent-Save-Occurrence`, freezes session candidates, and records client outcomes linked to the apply and occurrence. It forwards WordPress's result unchanged.

- [x] Test actual entity request matching, selective saves, multiple entities, failed/lost responses, ignored autosaves/previews/telemetry, scope changes, candidate freeze, retries, and outbox deduplication.
- [x] Persist the independent versioned outbox under site/user-scoped storage; retry without blocking WordPress saves or depending on the displayed activity page.
- [x] Fetch linked server verdicts with bounded retries; never author a confirmation from the response.
- [x] Label newly created editor applies forward-only and preserve additive fields through cache and REST serialization.

## Task 5: Share assurance across metrics and UI

**Files:** new `inc/Activity/PersistenceAssurance.php`; `RecommendationOutcomeMetrics.php`, activity read/report queries; admin activity utilities/rendering; editor success copy; relevant JS/PHP tests and feature/reference docs.

**Interfaces:** `PersistenceAssurance` resolves `persistenceVerdict`, `verificationCoverage`, `requestStatus`, and `undoState` from durable evidence. Reports add the six spec metrics while existing metric definitions remain unchanged.

- [x] Test highest-sequence resolution and all eligible/compared/conclusive cohorts, including zero denominators and an inconclusive comparison followed by a later conclusive verdict.
- [x] Load linked verdicts independently of page/date limits and exclude lifecycle rows from the original learning-report sample.
- [x] Render request, persistence, coverage, and undo independently from server fields. Immediate apply toasts identify an unsaved change; persistent inline feedback qualifies the editor action without claiming it remains unsaved after a later Save.
- [x] Update documentation and exact-shape fixtures. Preserve separate meanings of historical attestation and later saved-state evidence.

## Task 6: Verify the integrated change

- [x] Run targeted PHPUnit and Jest suites for the shared persistence contract.
- [x] Run `node scripts/verify.js --strict` to include all non-browser gates, documentation checks, Playground, and exact Gutenberg; record each lane and final status. Run the required bundled-editor leg separately.
- [x] Run Playground, bundled-editor, and exact-Gutenberg browser gates with real persistence/undo/save coverage and separate output paths.
- [x] Inspect rendered editor and activity feedback, review the diff, and record remaining blockers without calling a partial gate green.

The corrected implementation passed all nine strict gates with no skips, plus the separate bundled-editor gate. Final browser counts are Playground 17/17, exact Gutenberg 23.9.0 34/34, and bundled editor 34/34. The 702-file source manifest and seven generated build files stayed unchanged across the final runs. See the [verification record](../../validation/2026-09-12-save-persistence-outcomes.md) for the reproduced integration gaps, runtime selection, hashes, logs, and local/unreleased evidence boundary.
