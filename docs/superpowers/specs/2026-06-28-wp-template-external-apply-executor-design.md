# Page-Level `wp_template` External-Apply Executor (v1) — Design

> Date: 2026-06-28
> Status: Proposed. Implementation plan to follow under `docs/superpowers/plans/`.
> Decisions locked (brainstorming): page-level `wp_template` surface only · **`insert_pattern` operation only** (single insert per request, enforced by the existing validator) · `assign_template_part` / `replace_template_part` explicitly deferred · theme-file templates materialized into a `wp_template` post on first apply · server-side pattern→blocks resolution · **no attestation** · reuse the executor-dispatch seam, no new framework.
> Predecessors: `2026-06-24-template-part-external-apply-executor-design.md` (the template-part lane this mirrors) and `2026-06-10-governed-external-applies-c1-design.md` (the Global Styles / Style Book lane that established the seam).

## Goal

Extend the governed external-apply loop from the style and template-part lanes to the **page-level `wp_template`** surface: an agent that received a `recommend-template` / `preview-recommend-template` result can request a structural apply through the Abilities API / dedicated MCP server, a capability-holding human approves it in `Settings > AI Activity`, the server executes it against the live `wp_template` with freshness + structural revalidation, and the resulting change is attributed, auditable, and reversible.

The external contract mirrors the editor and the prior lanes: template structural operations are review-safe tier, so every external template apply is review-gated. The reviewer is a site-side human, not the calling agent. "AI proposes; WordPress approves."

Scope is bounded to a **single `wp_template`** per request and the **single path-addressed `insert_pattern`** the surface already emits, validated by the existing `TemplatePrompt` template-operation contract. The slot-addressed operations (`assign_template_part`, `replace_template_part`), the block surface, attestation, and any new operation types are out of scope (see Out of scope).

## Why `insert_pattern` only (the v1 scope decision)

The template surface emits three operation types. They split cleanly by addressing model:

- **`insert_pattern`** has **two placement families**, matching the template-part lane and the live validator (`insert_pattern` branch of the private `TemplatePrompt::validate_template_operations`): anchored placements `before_block_path` / `after_block_path` carry a `targetPath` **and** an `expectedTarget` drift fingerprint; the boundary placements `start` / `end` carry **neither** — they validate through the insertion-anchor lookup only. So the executor must accept `start`/`end` ops with no `targetPath`/`expectedTarget` (its phase-1 `expectedTarget` check runs only when a path is present, which is exactly how the template-part mirror behaves). The generation-time validator caps it at **one insert per operation set** (`repeated_pattern_insert`). All four placements map onto `BlockTreeMutator::insert`, which is post-tree-general and already proven by the template-part executor.
- **`assign_template_part` / `replace_template_part`** are **area/slot-addressed** (`slug` + `area`, no `targetPath`). Executing them server-side means building a `core/template-part` reference block and placing it into a template area slot — there is no server-side container-resolution or block-construction precedent for this anywhere in the codebase (the editor resolves the slot from live state). That is a net-new mutation primitive and the single largest risk the open-work investigation flagged.

v1 therefore covers `insert_pattern` only: maximum reuse, lowest risk, and a shippable governed lane. The slot operations are deferred to a follow-up with their own design.

## What exists vs. what is net-new

**Reused server-side primitives:** the C1 pending lifecycle and one-row persistence (`request.apply` → `entry.apply`), `PendingApplyDecision`'s decision flow (find / lazy-expire / pending-check / reject / approve / transition) — already **surface-generic**, dispatching through `ExternalApplyExecutorRegistry::for_surface()` and reading `signatures.baselineContentHash`; `ApplyAbilities::undo_activity` — also generic through the same registry; the per-user pending cap + TTL; the `resolveSignatureOnly` freshness-recompute path on `recommend_template`; contextual `Activity\Permissions`; the ordered-undo rule; the `available → undone/failed` machine for executed rows; the admin Approvals view + decision route; the editor-side pending-apply visibility (`AIActivitySection` recognizes external-apply lifecycle rows generically); `TemplateContextCollector` + `parse_blocks` for reading the live tree; the `BlockTreeMutator` path primitives (reused unchanged); and `WP_Block_Patterns_Registry` access for pattern resolution.

**Net-new:**
1. A **server-side template structural executor** (`inc/Apply/TemplateApplyExecutor.php`) — apply and undo over the parsed `wp_template` block tree, faithfully mirroring `TemplatePartApplyExecutor` but scoped to `insert_pattern`.
2. A **`serialize_blocks` + persist** path to the `wp_template` post, materializing a theme-file template into the DB on first apply with the **`wp_theme` term only** (no `wp_template_part_area` term — that taxonomy is template-part-specific).
3. An **apply-time validation method** `TemplatePrompt::validate_operations_for_apply()` — none exists today; only the private generation-time `validate_template_operations()` and its test seam do.
4. A **governed-write template resolver** `ServerCollector::resolve_template_for_apply()` — wraps `TemplateRepository::resolve_template()` with no public filter seam.
5. One **new ability** (`flavor-agent/request-template-apply`) plus its `Registration` wiring (`external_apply_ability_classes`, `external_apply_meta`, `external_apply_output_schema` arm) and a `'template'` arm in `ExternalApplyExecutorRegistry`. **A new template-specific request input schema is required** — it must define its own operation sub-schema whose `type` enum is **`insert_pattern` only**, and must **not** reuse the template-part `structural_operation_schema` (`inc/Abilities/Registration.php`), whose `type` enum also permits `replace_block_with_pattern` / `remove_block` and would defeat the v1 guard. Its `placement` enum is `start` / `end` / `before_block_path` / `after_block_path` (matching the live template contract).
6. A **template-aware admin governance projection** in `src/admin/activity-log-utils.js` / `activity-log.js`: a structural-operation summary (op type · target path · pattern / expected block) and a template target label, mirroring what the template-part lane added.

## New ability (31 → 32)

In the `flavor-agent` apply category, registered behind the same recommendation feature gate as `recommend-*`, exposed on the dedicated MCP server roster, **not** `meta.mcp.public` (the universal server stays read-only helpers; activity rows can carry prompts). Mirrors `request-template-part-apply` exactly in shape.

| Ability | Permission | Annotations | Behavior |
| --- | --- | --- | --- |
| `flavor-agent/request-template-apply` | `edit_theme_options` | `destructive:false, idempotent:false` (creates a queue row, mutates nothing) | Validates the single `insert_pattern` operation against the **live** template execution contract (lookups rebuilt from a fresh `ServerCollector::for_template()` and re-run through `TemplatePrompt::validate_operations_for_apply`), recomputes and `hash_equals`-matches the template `resolvedContextSignature`/`reviewContextSignature` via the `resolveSignatureOnly` path, **captures** the live content baseline (computes and stores `baselineContentHash` via `TemplateApplyExecutor::resolve_baseline` — it does **not** accept a caller-supplied baseline, mirroring `ApplyAbilities::request_template_part_apply`), then creates a **pending** external-apply activity row carrying the proposed operation + `baselineContentHash`. Returns `activityId`, status, expiry. Stale signatures or content drift → `flavor_agent_apply_stale`. Non-`insert_pattern` op types → `flavor_agent_apply_operations_invalid` (v1 guard). Per-user pending cap (shared `flavor_agent_external_apply_pending_cap`) → `flavor_agent_apply_queue_full`. |

`get-activity`, `list-activity`, and `undo-activity` are **reused, not duplicated**: `get`/`list` are surface-agnostic; `undo-activity` already dispatches through `ExternalApplyExecutorRegistry`, so adding the `'template'` registry arm is the only change it needs. Approval remains **not** an ability — the human gate is never exposed to agents; it stays the admin REST decision action.

## Executor dispatch seam (no shared-code surgery this time)

The seam the template-part lane generalized is already in place. `ExternalApplyExecutorRegistry::for_surface()` maps a surface to an executor; `PendingApplyDecision::decide` and `ApplyAbilities::undo_activity` both look up the executor by `entry.surface` and call `resolve_baseline` / `execute` / `undo` generically. The only change to shared routing is **one arm**:

```php
return match ( $surface ) {
    'global-styles', 'style-book' => StyleApplyExecutor::class,
    'template-part'               => TemplatePartApplyExecutor::class,
    'template'                    => TemplateApplyExecutor::class, // new
    default                       => null,
};
```

The registry docblock (still "template-part, from Task 7") is refreshed to describe the generalized seam.

## Pending lifecycle & persistence

A request creates **one** activity row with `executionResult: 'pending'`, mirroring `request_template_part_apply`:

- `type: 'apply_template_suggestion'`, `surface: 'template'`
- `target: { templateRef, templateType, slug, title }`
- `request.apply: { status:'pending', requestedBy, requestedAt, expiresAt, operations:[<validated insert_pattern>], signatures:{ resolvedContextSignature, reviewContextSignature, baselineContentHash }, requestReference }`
- `request.requestMeta: { ability:'flavor-agent/request-template-apply', executionTransport:'wp-abilities', route:'wp-abilities:flavor-agent/request-template-apply' }`
- `document: { scopeKey:'wp_template:'+templateRef, postType:'wp_template', entityId:templateRef, entityKind:'template', entityName:'template' }`

Approval transitions the same row to `available` with `before` / `after` / `target` snapshots populated by the executor; the lifecycle states (`pending` → `rejected` / `expired` / `failed` / `available` → `undone` / `failed`) are reused unchanged.

## Server-side executor (`inc/Apply/TemplateApplyExecutor.php`)

A faithful mirror of `TemplatePartApplyExecutor`, scoped to `insert_pattern`:

- **`resolve_baseline($entry)`** — re-resolve the live template via `ServerCollector::resolve_template_for_apply()`; return `sha256( serialize_blocks( parse_blocks( $content ) ) )`. (Gate-2 baseline; identical hashing to the template-part lane so insignificant serialization differences never read as drift.)
- **`execute($entry)`** — read live content + baseline hash; require ≥1 operation; **re-collect** `ServerCollector::for_template()` and **re-validate** every operation via `TemplatePrompt::validate_operations_for_apply` (assert validated count == input count, fail-closed otherwise); verify each op's `expectedTarget` against the **original** parsed tree; apply the single `insert_pattern` via the same `apply_insert` logic over `BlockTreeMutator::insert`; run `assert_template_unchanged` (final read→write concurrency gate) **which returns the freshly re-resolved template entity**; `persist` **against that fresh entity, not the object resolved at the start of execute** (see the persist note on the materialization race). Returns `{ target, before:{content}, after:{content, operations} }`.
- **`undo($entry)`** — re-resolve live; `live == before` → `already_undone` (no write); `live != after` → `flavor_agent_undo_drift` (fail closed); else restore `before` after a final `assert_template_unchanged`. Requires the `before`/`after` content snapshots (the row records them at apply time).
- **`persist($template,$content)`** — `wp_id > 0` → `wp_update_post` in place; `wp_id == 0` → materialize a `wp_template` post (`post_type:'wp_template'`, `post_name: slug`, `tax_input:{ wp_theme:[stylesheet] }` — **no area term**), fail-closed without slug+stylesheet; `clean_post_cache` after every write so `wp_get_block_templates` re-reads fresh content. **The `$template` passed here must be the entity returned by the final concurrency gate (current `wp_id`), not the start-of-execute object** — otherwise a same-content materialization by another actor between the start read and the write passes the content-hash check yet still hits the `wp_id == 0` branch and `wp_insert_post`s a **duplicate** row. The materialize branch must additionally fail closed (or fall back to update-in-place) if a `wp_template` post already exists for the same `post_name` + `wp_theme`, so two racing applies cannot both insert. **Note:** the shipped `TemplatePartApplyExecutor` mirrors the stale-object pattern and carries the same latent race; fixing it there is a separate follow-up, not this slice — this lane is specified correctly from the start.

The v1 `insert_pattern`-only guard is enforced in **both** the request handler and the executor (belt-and-suspenders, fail-closed on any `assign`/`replace` op), in addition to the new template-specific input-schema `type` enum (`insert_pattern` only) — no single point of failure.

## Apply-time validator (`TemplatePrompt::validate_operations_for_apply`)

`validate_template_operations()` is private and generation-time (eight positional lookup args). The new public method rebuilds those lookups from the live `for_template()` context and calls it, then normalizes the `{operations, invalid, code}` result into the `{operations, reasons}` shape the executor and handler expect (`reasons = invalid ? [code] : []`). For v1's single `insert_pattern`, the validator exercises only the `pattern_lookup` / `template_block_lookup` / `insertion_anchor_lookup` branches; the assign/replace lookups are built but unused.

## Freshness / drift gates (the invariant)

All gates fail closed with zero writes on drift:

1. **Gate-1 — signature recompute (request):** recompute `resolvedContextSignature` + `reviewContextSignature` via `recommend_template` `resolveSignatureOnly`, forwarding `templateRef` / `templateType` / `prompt`, plus `visiblePatternNames` **and `designSemantics`** each forwarded **only when the caller supplied it** (the `array_key_exists` conditional, so an always-present key never shifts the signature) — but **never** the editor-state overlays `editorSlots` / `editorStructure`. `hash_equals` against provided. The distinction is deliberate: `recommend_template` folds `designSemantics` into **both** signatures, and `designSemantics` is agent-providable data (it is in the `recommend-template` input schema as an open object, parallel to `prompt` / `visiblePatternNames`), so forwarding it lets a semantically-grounded recommendation still apply through the external lane. `editorSlots` / `editorStructure` are live-editor state the external lane cannot reproduce, so they stay omitted and the external lane remains the **server-only flow**; editor-originated template changes continue to apply client-side. (The template-part probe sidesteps `editorStructure` the same way.)
2. **Gate-2a — baseline capture (request):** store `baselineContentHash` from `TemplateApplyExecutor::resolve_baseline`.
3. **Gate-2b — baseline re-check (approval):** `PendingApplyDecision` re-checks live baseline == the stored `baselineContentHash` (already generic).
4. **Re-validation (execute):** re-collect `for_template`, re-validate the op, and verify `expectedTarget` against the original tree **for anchored (`before_block_path`/`after_block_path`) ops only** — `start`/`end` ops carry no `targetPath`/`expectedTarget` and are re-validated via the insertion-anchor lookup (the executor's `expectedTarget` check runs only when a path is present).
5. **Concurrency gate:** `assert_template_unchanged` immediately before the write — it re-resolves the live template, fails closed if the content hash moved, and **returns the freshly re-resolved entity** so `persist` writes against the current `wp_id`. This closes both the content-overwrite window (a concurrent Site Editor / wp-cli save) **and** the same-content materialization race (a file-backed template materialized to a DB row by another actor between read and write must be updated in place, never duplicated).

## Attestation boundary

Attestation stays **frozen** to `external-style-apply-v1`. The `'template'` surface is **not** added to the style-only `in_array($surface, ['global-styles','style-book'], true)` branches in `PendingApplyDecision::decide` or `ApplyAbilities::undo_activity`. Template applies record full activity audit and zero signed statement — identical to the template-part precedent and the bounded-lane invariant. Never pass a non-style surface to `AttestationService` (`assert_owned_lane_context()` throws by design).

## Admin approval surface

The Approvals view, decision route, and editor-side lifecycle recognition are reused. Current governance copy **defaults every non-`template-part` surface to style-shaped text** — both the surface-aware approval-copy helper (banner + decision prose) and the `formatOperationSummary` selector (`entry.surface === 'template-part' ? formatStructuralOperationSummary : formatStyleOperationSummary`). So a `'template'` row would otherwise render style approval copy and style operation summaries. The checklist must add a `'template'` branch in **all three** spots in `src/admin/activity-log-utils.js` / `activity-log.js`: (1) the approval-copy helper (template banner + decision prose), (2) the `formatOperationSummary` selector (route `'template'` → structural summary: op type · target path · pattern / expected block), and (3) the target label. The style visual-diff viewer stays empty for this surface (no before/proposed/after style payload).

## Error handling

Reuse the established vocabulary, all `WP_Error` with HTTP status: `flavor_agent_apply_stale` (signature/baseline drift), `flavor_agent_apply_operations_invalid` (re-validation failure or non-`insert_pattern` op), `flavor_agent_apply_target_changed` (`expectedTarget` / concurrency drift), `flavor_agent_apply_target_unavailable` (missing template), `flavor_agent_apply_pattern_unavailable` (unresolvable / synced-only pattern), `flavor_agent_apply_write_failed` (persist/materialize failure), `flavor_agent_apply_queue_full` (cap), `flavor_agent_undo_drift` / `flavor_agent_undo_snapshot_unsupported` (undo). Any drift aborts before any mutation.

## Docs and guard updates (same change, not follow-ups)

Bump the ability count **31 → 32** and add the route/ability everywhere it is inventoried: `CLAUDE.md`, `.github/copilot-instructions.md`, `docs/reference/abilities-and-routes.md`, `docs/reference/governance-layer.md` (surface-loop coverage + external-agent parity), `docs/FEATURE_SURFACE_MATRIX.md`, `docs/SOURCE_OF_TRUTH.md`, `STATUS.md`, `docs/reference/local-environment-setup.md` (the "full list to 31 abilities" Explorer note), and the registry docblock. In `docs/reference/current-open-work.md`, move page-level `wp_template` from open → shipped and **keep block-surface open**.

**Critical:** `npm run check:docs` is **not** self-updating — `scripts/check-doc-freshness.sh` itself **hard-codes the count** in its assertion strings (the "thirty-one ability contracts" / "`inc/Abilities/Registration.php` defines 31 ability contracts" guards). The guard must be bumped to 32 **in the same change**, or `check:docs` keeps asserting the old wording and fails. Treat the guard script as a count-bearing file alongside the docs, and re-run `check:docs` to confirm green after both the docs and the guard are updated.

## Testing (TDD, PHPUnit-heavy)

Single-file runs only (multi-file batches false-green). New `TemplateApplyExecutorTest`: insert before / after (anchored, with `expectedTarget`) / start / end (boundary, no `targetPath`/`expectedTarget`); `expectedTarget` name/childCount mismatch on an anchored op → fail-closed-no-write; unresolvable / synced-only pattern → fail-closed; theme-file materialization on first apply (correct `wp_theme` term, no area term, cache invalidation); **same-content materialization between read and write updates in place — one row, no duplicate insert**; undo already-undone / drift / restore; atomic rollback (no content written on partial failure); **attestation NOT recorded for template AND style attestation still recorded** (regression guard). New `TemplatePromptApplyValidationTest`: lookups rebuilt from live context, parity assertion, non-`insert_pattern` rejection. Extend `ExternalApplyLifecycleTest` (request → approve → execute → undo for `template`) and `ApplyAbilitiesTest` (both request gates, cap, stale, **`designSemantics`-bearing signed replay applies, editor-overlay (`editorSlots`/`editorStructure`) recommendation correctly rejected as stale**). `RegistrationTest`: schema/contract (including the template-specific input schema rejecting non-`insert_pattern` `type` values) + ability count 32. `MCPServerBootstrapTest`: assert `flavor-agent/request-template-apply` is in the dedicated MCP server roster and bump the asserted tool count **12 → 13**. **Plus a read→write test through the real REST `permission_callback` + `Serializer::derive_entity`** — lifecycle tests that drive `decide()`/`undo_activity` directly bypass the permission gate and entity derivation, so `scopeKey`-prefix / target-key seam bugs pass units but break the real loop (recorded lesson from the template-part 29-agent review). `BlockTreeMutator` is reused unchanged. E2E stays manual-only (dev container) per the coverage topology.

## Risks

- **Theme-file materialization** (slug + `wp_theme` term + `clean_post_cache`) is the highest-uncertainty area, as it was for template-part; templates use a different taxonomy shape (no area term). Covered by a dedicated test and the fail-closed persist guard.
- **Same-content materialization race:** if another actor materializes the file-backed template (or another apply runs) between execute's start read and its write, a content-hash-only gate passes while persist still inserts a duplicate DB row from a stale `wp_id == 0` object. Mitigated by persisting against the entity re-resolved at the concurrency gate and by the duplicate-row guard in the materialize branch. **Test:** a same-content materialization between read and write must update in place (one row), never insert a second.
- **Signature-overlay coupling:** `recommend_template`'s signatures fold `editorSlots`/`editorStructure` (live-editor state, omitted by the external lane) and `designSemantics` (agent-providable data, forwarded by the external lane). Getting the split wrong rejects valid recommendations as stale: omitting `designSemantics` rejects semantically-grounded ones; forwarding the editor overlays makes the signature unreproducible by an agent. **Test the signed replay both ways** — a `designSemantics`-bearing recommendation must apply; an `editorSlots`/`editorStructure`-bearing one is correctly out of the external lane.
- **Seam-bug class:** handler-level lifecycle tests bypass the REST permission gate + `derive_entity`; mitigated by the required read→write gate test.
- **Single-insert constraint:** the validator caps `insert_pattern` at one per request; v1 accepts this rather than relaxing the generation contract.

## Out of scope (later specs)

`assign_template_part` / `replace_template_part` (slot-addressed mutation, no server precedent — own design); the block-surface external-apply executor; attestation extension beyond `external-style-apply-v1`; multi-insert per request; any new operation types; generalizing `TemplatePartApplyExecutor` + `TemplateApplyExecutor` into a shared base (premature until a third subject type lands).
