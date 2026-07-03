# Post-Blocks (Block-Surface) External-Apply Executor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the governed external-apply loop to a fourth subject type: an external agent can request a drift-checked, human-approved, reversible structural apply (`insert_pattern` / `replace_block_with_pattern` / `remove_block`, ≤3 path-addressed ops) against one **post or page**'s `post_content`, with server-side block-locking enforcement.

**Architecture:** Introduce a new `post-blocks` surface rather than overloading the editor's `block` surface. The editor block lane stays exactly as-is (clientId-addressed, single-op, pattern-required, editor-side apply/undo); the external lane is path-addressed and server-executed, mirroring the shipped template-part lane (`inc/Apply/TemplatePartApplyExecutor.php`). Two pillars are net-new relative to the shipped lanes: (1) a server-side post-blocks context collector — the "document target contract" — that parses live `post_content` and publishes the executable-target allowlist, and (2) lock-aware target exclusion (`attrs.lock.remove/move`, `templateLock`, contentOnly) so the allowlist never offers what the editor UI forbids. Everything else reuses shipped machinery: `BlockTreeMutator`, the descending-pass atomic multi-op apply, `ExternalApplyExecutorRegistry` dispatch, `PendingApplyDecision` gates, pending-row lifecycle, advisory claims, admin approval UI, and ordered server-side undo.

**Tech Stack:** PHP 8.2 (`FlavorAgent\`, PSR-4, `declare(strict_types=1)`), WordPress block + post APIs (`parse_blocks`/`serialize_blocks`/`get_post`/`wp_update_post`), PHPUnit, `@wordpress/scripts` Jest for the admin slice.

**Scope decisions (resolved 2026-07-02 with the maintainer):**

1. **Op model:** template-part parity — ≤3 ordered ops (`insert_pattern`, `replace_block_with_pattern`, `remove_block`), path-addressed with `expectedTarget` fingerprints and overlap rejection.
2. **Target scope:** `post` and `page` only in v1. Capability is `edit_posts` escalating to `edit_post` on the target ID (the base-class escalation in `RecommendationAbility::permission_callback()` plus `Activity\Permissions` post-scoped defense-in-depth both already exist).
3. **Locking:** enforced fail-closed in v1. Locked blocks are excluded from `operationTargets`/`insertionAnchors`, and apply-time re-validation therefore rejects ops that target them.

## Source Grounding (verified against HEAD `cfe65ab`, 2026-07-02)

- Registry has three arms and dispatches decision + undo by surface: `inc/Apply/ExternalApplyExecutorRegistry.php` (`global-styles`/`style-book`, `template-part`, `template`).
- The executor contract is `resolve_baseline` / `execute` / `undo`: `inc/Apply/ExternalApplyExecutor.php`.
- `BlockTreeMutator` (path-addressed, `innerContent`-marker-correct) and the phase-verified, lexicographic-**descending** single-pass `apply_operations` live in `inc/Apply/BlockTreeMutator.php` and `inc/Apply/TemplatePartApplyExecutor.php` (see archived plan R1 — this ordering is the load-bearing correctness property and must be shared, not re-derived).
- Request-time gate sequence to mirror: `ApplyAbilities::request_template_part_apply` (`inc/Abilities/ApplyAbilities.php:227`) — signature verification, fresh-context re-validation, pending cap (`flavor_agent_external_apply_pending_cap`), TTL, queue as pending with `reference => 'external-apply:' . $scope_key`.
- Approval-time gates run generically through `PendingApplyDecision::decide` via the registry (baseline gate 2 + execute + committed transition clearing the advisory claim).
- `TemplatePartContextCollector::for_template_part` (`inc/Context/TemplatePartContextCollector.php:24`) builds `blockTree` / `operationTargets` / `insertionAnchors` / `structuralConstraints` from `parse_blocks()` output via `TemplateStructureAnalyzer` — content-agnostic over a parsed tree, so a post collector reuses the analyzer.
- `ServerCollector::for_block` (`inc/Context/ServerCollector.php:103`) assembles **client-supplied** editor context; it does not parse a post. The post-blocks collector is net-new.
- Editor block ops remain single-op + pattern-required (`inc/Context/BlockOperationValidator.php:84,245`); this plan does not touch that validator.
- Permissions: `capability_for_context` (`inc/Activity/Permissions.php:142`) resolves non-theme surfaces to `edit_posts`, and post-scoped activity additionally requires `edit_post:N` (defense-in-depth comment at `Permissions.php:242`). `can_decide_activity_request` = `manage_options` + the row's mutation capability — for post-scoped rows that means the decider needs `edit_post` on the target too.
- Attestation is hard-bounded: `AttestationService::GOVERNANCE_LANE = 'external-style-apply-v1'` (`inc/Attestation/AttestationService.php:16`), enforced at `PendingApplyDecision.php:147` and `ApplyAbilities.php:786`. **Post-blocks applies are not attested.**
- Ability inventory today: 32 in `inc/Abilities/Registration.php`. This plan adds three (→ 35): `recommend-post-blocks`, `preview-recommend-post-blocks`, `request-post-blocks-apply`.

## Global Constraints

- Surface string is exactly `post-blocks`; activity `type` is exactly `apply_post_blocks_suggestion`. Do NOT reuse the editor's `block` surface — keeping the surfaces distinct is what lets registry dispatch, undo eligibility, and admin projection stay unambiguous without a row-provenance discriminator.
- One structural-operation grammar owner. The ≤3-op cap, overlap rejection, placement vocabulary, and `expectedTarget` fingerprint rules currently live in `inc/LLM/TemplatePartPrompt.php`. Extract them into a shared validation unit (Task 2) rather than copying; `TemplatePartPrompt` behavior must stay byte-identical (its full PHPUnit suite green, zero fixture churn).
- Target resolution accepts only `post` / `page` post types in statuses `publish`, `draft`, `pending`, `private`; anything else (including trash, autosave/revision IDs, password-protected handling per existing repo conventions) fails closed with `flavor_agent_apply_target_unavailable`.
- Locking fails closed at **both** ends: collection (locked targets never enter the allowlist) and apply-time re-validation (an op addressing a locked path is rejected even if a stale allowlist offered it).
- Drift fails closed everywhere: request-time baseline, approval-time baseline (gate 2), `expectedTarget` re-resolution against the live parsed tree, pattern re-resolution. Any failure aborts with zero writes.
- No attestation: never pass `post-blocks` to `AttestationService` (`assert_owned_lane_context()` throws). `external-style-apply-v1`, `Canonicalizer`, `StatementBuilder` untouched.
- Shipped lanes stay byte-identical: `ExternalApplyLifecycleTest`, `StyleApplyExecutorTest`, `TemplatePartApplyExecutorTest`, `TemplateApplyExecutorTest` all green after every task.
- Run PHPUnit single-file (`vendor/bin/phpunit --filter` or one path). Commit after every task. JS formatting only via `npm run lint:js -- --fix`.

## Non-Goals (v1)

- No tier-2 patternless ops beyond `remove_block` (no move/duplicate/wrap/unwrap), no parameterized patterns (`preserve`/`parameters`), no selection/section sub-scoping beyond whole-document paths, no editor UI for requesting external post applies, no attestation extension, no CPT support, no changes to the editor block lane or `BlockOperationValidator`.

---

## Task Group A — Shared grammar + post context (foundation)

### Task 1: `PostBlocksContextCollector` — the document target contract

**Files:** create `inc/Context/PostBlocksContextCollector.php`; modify `inc/Context/ServerCollector.php` (add `for_post_blocks( int $post_id )` + `resolve_post_for_apply( int $post_id )` facades); test `tests/phpunit/PostBlocksContextCollectorTest.php`.

- [ ] Resolve the post (`get_post`), enforce the post-type/status allowlist, and build context from `parse_blocks( $post->post_content )` via `TemplateStructureAnalyzer`: `blockTree`, `operationTargets`, `insertionAnchors`, `structuralConstraints`, plus `postId`, `postType`, `postStatus`, `title`, and `baselineContentHash = sha256( serialize_blocks( parse_blocks( content ) ) )`.
- [ ] If the analyzer's collection methods are template-part-named but tree-generic, add thin generic entry points rather than duplicating traversal; assert template-part outputs unchanged.
- [ ] Tests: unknown post / wrong type / trashed status fail closed; nested tree produces correct paths; baseline hash matches the executor's future `resolve_baseline`.

### Task 2: Lock-aware target exclusion + shared structural-operation grammar

**Files:** modify `inc/Context/TemplateStructureAnalyzer.php` (lock detection during target/anchor collection); create `inc/LLM/StructuralOperationsGrammar.php` (extraction target — name per repo taste); modify `inc/LLM/TemplatePartPrompt.php` (delegate, behavior-preserving); create `inc/LLM/PostBlocksPrompt.php` with `validate_operations_for_apply( array $operations, array $context )`; tests `tests/phpunit/PostBlocksPromptApplyValidationTest.php` + existing `TemplatePartPromptApplyValidationTest.php` green.

- [ ] Lock rules (fail closed, both collection and validation): a block with `attrs.lock.remove === true` is not removable/replaceable; `attrs.lock.move === true` blocks nothing in v1 (no move op) but record it in the target entry for honesty; a container with `templateLock` of `all`/`insert`/`contentOnly` excludes its children from removal/replacement and excludes insertion anchors inside it. Lock exclusion applies to `operationTargets` and `insertionAnchors` for the post-blocks collector; template-part collection behavior is unchanged unless a follow-up deliberately promotes lock parity there (record as follow-up, do not silently change).
- [ ] Extract the op grammar (≤3 cap, overlap rejection via `block_paths_overlap`, placement vocabulary, `expectedTarget` name+childCount fingerprint rules — NOT attributes, per archived R3) so `TemplatePartPrompt` and `PostBlocksPrompt` share one implementation.
- [ ] `PostBlocksPrompt` v1 also owns the generation-side prompt for `recommend-post-blocks` (system/user prompt over the Task 1 context, response schema addition in `inc/LLM/ResponseSchema.php`, budget via `inc/LLM/PromptBudget.php`), reusing the template-part prompt shape with post-document framing.
- [ ] Tests: op targeting a locked path is rejected with a dedicated reason code (`target_locked` — add to the shared validation-reasons vocabulary, which is a cross-surface contract change; see Verification); valid ops survive; overlap and cap behavior identical to template-part fixtures.

## Task Group B — Executor + shared apply pass

### Task 3: Extract the shared atomic apply pass (behavior-preserving)

**Files:** create `inc/Apply/StructuralOperationsApplier.php` (phase-1 expectedTarget verification, phase-2 pattern pre-resolution, phase-3 lexicographic-descending single-pass apply, `effective_order_path` / `compare_paths` helpers — lifted from `TemplatePartApplyExecutor`); modify `inc/Apply/TemplatePartApplyExecutor.php` and `inc/Apply/TemplateApplyExecutor.php` to delegate; existing executor tests must stay green with zero assertion changes.

- [ ] This is the same seam discipline as the shipped Task-1 style refactor: extract, delegate, prove byte-identical behavior, commit before any new-surface code.

### Task 4: `PostBlocksApplyExecutor`

**Files:** create `inc/Apply/PostBlocksApplyExecutor.php`; modify `inc/Apply/ExternalApplyExecutorRegistry.php` (add `'post-blocks'` arm — remember archived R2: the arm and its `assertSame` land together, `::class` resolves even for missing classes); test `tests/phpunit/PostBlocksApplyExecutorTest.php`.

- [ ] `resolve_baseline`: resolve post via `ServerCollector::resolve_post_for_apply`, hash reserialized content (identical recipe to Task 1's `baselineContentHash`).
- [ ] `execute`: re-resolve post → re-collect context (Task 1) → `PostBlocksPrompt::validate_operations_for_apply` (count-preserving or 409 with `validationReasons`) → `StructuralOperationsApplier` → persist via `wp_update_post( [ 'ID' => $id, 'post_content' => $after ] )` → `clean_post_cache` → return `{target: {postId, postType, title}, before: {content}, after: {content, operations}}`. `wp_update_post` creating a revision is expected and desirable (extra recovery evidence); assert it in tests if the bootstrap stubs support it, otherwise record as environment-limited.
- [ ] `undo`: restore `before.content` behind the same live-hash drift gate and ordered-undo semantics the template lanes use; write through the same persist + cache-clean path.
- [ ] Test matrix (mirror archived R8, all fail-closed cases asserting **zero writes**): each op type success incl. nested paths; mixed `remove [0]` + `insert after [2]`; multi-insert; replace(1→N)+insert; childCount mismatch; name mismatch; unregistered pattern; locked-target op rejected at apply time even when present in the stored request; wrong post type/status; undo round-trip reads fresh content (archived R7 guard).

## Task Group C — Abilities, request lifecycle, permissions

### Task 5: `request-post-blocks-apply` ability + pending lifecycle

**Files:** create `inc/AI/Abilities/RequestPostBlocksApplyAbility.php` (+ handler in `inc/Abilities/ApplyAbilities.php` mirroring `request_template_part_apply` at `ApplyAbilities.php:227`); modify `inc/Abilities/Registration.php`; tests: `tests/phpunit/ApplyAbilitiesTest.php`, `tests/phpunit/ExternalApplyLifecycleTest.php` additions.

- [ ] Request-time gates in shipped order: feature gate; capability (`edit_posts` + `edit_post:{postId}` escalation); requestSignature + baselineContentHash verification against the minting recommendation (mind the archived template-lane note at `ApplyAbilities.php:428` — a faithful external replay must not false-fail as stale); fresh Task-1 context re-validation; pending cap; TTL; queue pending row with post-scoped `scope_key` so `Activity\Permissions` post-scoped defense-in-depth (`Permissions.php:242`) binds both access and decision to `edit_post:N`.
- [ ] Registration (archived R4 checklist, all four seams): `external_apply_ability_classes()` entry; `external_apply_meta()` arm (`destructive: false, idempotent: false` — do not let it fall to `default => readonly: true`); `external_apply_output_schema()` arm (`activityId/status/expiresAt/requestReference`); dedicated input schema (`required: ['target','operations','signatures']`, op enum from the shared grammar, permissive envelope for the strict abilities-bridge ajv). Dedicated-server exposure only — external-apply abilities do not get `meta.mcp.public = true`.
- [ ] Lifecycle tests: queue → approve → executed (content actually mutated) → undo; queue → reject / expire / approval-time drift → correct terminal states, zero writes; decider without `edit_post` on the target is refused even with `manage_options`.

### Task 6: `recommend-post-blocks` + `preview-recommend-post-blocks`

**Files:** create `inc/AI/Abilities/RecommendPostBlocksAbility.php` (CAPABILITY `edit_posts`; base-class `edit_post` escalation); callback home `inc/Abilities/PostBlocksAbilities.php` (or fold into an existing abilities class per repo taste) using the shared `RecommendationAbilityExecution` pipeline (guidelines, provider routing, best-effort docs grounding, `request_diagnostic` row, `generationId` + `learningAttribution`); modify `inc/Abilities/Registration.php` (recommendation arm — feature-gated — and preview sibling — gate-independent, `meta.mcp.public = true`, matching the five shipped siblings at `Registration.php:99-119`); tests: `tests/phpunit/RegistrationTest.php`, `RegistrationSchemaTest.php`, `AbilitySchemaContractTest.php`, `MCPServerBootstrapTest.php` (counts 32→35), plus a recommendation-shape test.

- [ ] Input: `{ postId, prompt, requestReference? }` — server-collected context only; no client-supplied tree is trusted for this surface.
- [ ] Output: validated ops (path-addressed, `expectedTarget` fingerprints), `operationTargets`/`insertionAnchors` echo, freshness `signatures` (`requestSignature`, `baselineContentHash`) minted exactly the way `request-post-blocks-apply` verifies them, validation reasons, docs-grounding fingerprints per the 2026-06-18 split (`DocsGuidanceResult::content_fingerprint()` for signatures).

### Task 7: Admin + editor surfacing

**Files:** modify `src/admin/activity-log-utils.js` (governance projection copy for `post-blocks`, as the template lane did) + `src/admin/__tests__/activity-log-utils.test.js`; modify the pending-approval admin notice source covered by `tests/phpunit/ActivityPageTest.php` so post-blocks pendings are included with target post title/type context; verify `src/components/AIActivitySection.js` lifecycle labeling and the surface-casing helper handle the new surface (Jest: `src/components/__tests__/AIActivitySection.test.js`).

- [ ] The AI Activity structural diff for post-blocks rows should reuse whatever the template/template-part rows render from `before`/`after` content snapshots; no new visual-diff work in v1 beyond correct labels.

## Task Group D — Docs, contracts, verification

### Task 8: Contract docs + inventory

- [ ] Update: `docs/reference/abilities-and-routes.md` (three new abilities, dedicated-server placement, schemas), `docs/reference/governance-layer.md` (loop coverage + external-agent parity tables; attestation boundary note), `docs/FEATURE_SURFACE_MATRIX.md`, `docs/SOURCE_OF_TRUTH.md`, `docs/features/activity-and-audit.md`, `STATUS.md`, `CLAUDE.md` ability count (32→35) + integration-points bullet, and `docs/reference/current-open-work.md` (move the row, record the slice). `uninstall.php`: no new options/tables/crons expected — confirm and state so.
- [ ] `npm run check:docs` green.

### Task 9: Cross-surface validation gates (`docs/reference/cross-surface-validation-gates.md`)

This slice touches ability contracts, the shared validation-reasons vocabulary, activity/undo, and admin surfaces — the gates apply in full:

- [ ] Nearest targeted suites (all new PHPUnit files; `ExternalApplyLifecycleTest`; template-part/template regression suites; the two Jest suites).
- [ ] `node scripts/verify.js --skip-e2e` + inspect `output/verify/summary.json`.
- [ ] `npm run check:docs`.
- [ ] Playwright: `playground` harness for any post-editor-visible behavior; `wp70` harness for the AI Activity approval flow (extend `tests/e2e/flavor-agent.approvals.spec.js` with a post-blocks pending row if feasible). If a harness is red/unavailable, record the blocker or an explicit waiver — no silent skips.

## Open Risks

- **Grammar extraction regression risk (Tasks 2–3):** the template-part suites are the safety net; any fixture churn there is a stop signal, not a test to update.
- **Editor-concurrent edits:** a post open in the editor while a pending apply awaits approval is handled by the approval-time baseline gate (stale → failed, zero writes), same as template lanes; autosaves live on separate rows and don't move `post_content`. State this in the docs rather than engineering anything new.
- **Lock semantics drift:** `templateLock`/`lock` interpretation should match current core semantics at implementation time; verify against the wp-dev-news-digest / docs-grounding corpus before freezing the exclusion rules, and encode each rule as a test.
- **Analyzer generalization:** if `TemplateStructureAnalyzer` turns out to be less tree-generic than its collector call sites suggest, prefer a thin post-specific analyzer over forcing shared code — but only after reading it, not preemptively.

## Suggested Execution Order

Task 1 → 2 → 3 (foundation, each independently committable and shipped-lane-green) → 4 → 5 → 6 → 7 → 8 → 9. Tasks 5 and 6 may swap if minting signatures first makes the request-side tests cleaner; keep the registry arm (Task 4) ahead of both.
