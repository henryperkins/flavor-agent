# Template-Part External-Apply Executor (v1) — Design

> Date: 2026-06-24
> Status: Proposed. Implementation plan to follow under `docs/superpowers/plans/`.
> Decisions locked (brainstorming): template-PART surface only · all three operations (`insert_pattern`, `replace_block_with_pattern`, `remove_block`) · server-side pattern→blocks resolution · **no attestation** · thin executor-dispatch seam (not a plugin-extensible framework).
> Predecessor: `2026-06-10-governed-external-applies-c1-design.md` (the Global Styles / Style Book lane this extends).

## Goal

Extend the governed external-apply loop from the style lane to the **template-part** surface: an agent that received a `recommend-template-part` / `preview-recommend-template-part` result can request a structural apply through the Abilities API / dedicated MCP server, a capability-holding human approves it in `Settings > AI Activity`, the server executes it against the live `wp_template_part` with freshness + structural revalidation, and the resulting change is attributed, auditable, and reversible.

The external contract mirrors the editor and the style lane: template-part structural operations are review-safe tier, so every external template-part apply is review-gated. The reviewer is a site-side human, not the calling agent. "AI proposes; WordPress approves."

Scope is bounded to a **single `wp_template_part`** per request, the **≤3 path-addressed operations** the surface already emits, validated by the existing `TemplatePartPrompt::validate_operations` contract. Page-level templates, the block surface, attestation, and any new operation types are out of scope.

## What exists vs. what is net-new

**Reused server-side primitives:** the C1 pending lifecycle and one-row persistence (`request.apply` → `entry.apply`), `PendingApplyDecision`'s decision flow (find / lazy-expire / pending-check / reject / approve / transition), the per-user pending cap + TTL, the `resolveSignatureOnly` freshness-recompute path, contextual `Activity\Permissions`, the ordered-undo rule, the `available → undone/failed` machine for executed rows, the admin Approvals view + decision route, the editor-side pending-apply visibility (`AIActivitySection` already recognizes external-apply lifecycle rows generically), `TemplatePartContextCollector` + `parse_blocks` for reading the live tree, `TemplatePartPrompt::validate_operations` for the operation contract, and `PatternCatalog`'s registry access.

**Net-new:**
1. A **server-side template-part structural executor** (`TemplatePartApplyExecutor`) — apply and undo over parsed block trees. No server-side structural block mutation exists today (block/template/template-part applies all run in the client editor stores).
2. A **`serialize_blocks` + persist** path to the `wp_template_part` post, materializing a theme-provided part into the DB on first apply (the only existing CPT-write precedent is `StyleApplyExecutor`'s global-styles `wp_update_post`).
3. A **server-side pattern→blocks resolver** bounded to the recommendation's allowlist (registered patterns via `PatternCatalog`/`WP_Block_Patterns_Registry`; synced patterns as `core/block` references).
4. An **apply-time validation-context builder** that rebuilds `operationTargets` / `insertionAnchors` / `blockLookup` from the **live** part and re-runs `TemplatePartPrompt::validate_operations`.
5. One **new ability** (`flavor-agent/request-template-part-apply`) plus surface dispatch in `PendingApplyDecision` and `ApplyAbilities::undo_activity`.
6. A **template-part-aware admin governance projection** in `src/admin/activity-log-utils.js` / `activity-log.js`: the governance evidence section currently formats operations through the style-shaped `formatStyleOperationSummary` and labels targets with style helpers, so template-part rows need a small structural-operation summary (op type · target path · pattern / expected block) and a template-part target label.

## New ability (30 → 31)

In the `flavor-agent` apply category, registered behind the same recommendation feature gate as `recommend-*`, exposed on the dedicated MCP server roster, **not** `meta.mcp.public` (the universal server stays read-only helpers; activity rows can carry prompts). Mirrors `request-style-apply` exactly in shape.

| Ability | Permission | Annotations | Behavior |
| --- | --- | --- | --- |
| `flavor-agent/request-template-part-apply` | `edit_theme_options` | `destructive:false, idempotent:false` (creates a queue row, mutates nothing) | Validates the ≤3 operations against the **live** template-part execution contract (rebuilt `operationTargets`/`insertionAnchors`/`blockLookup` re-run through `TemplatePartPrompt::validate_operations`), recomputes and `hash_equals`-matches the template-part `resolvedContextSignature`/`reviewContextSignature` via the `resolveSignatureOnly` path, confirms the live content still hashes to the claimed baseline, then creates a **pending** external-apply activity row carrying the proposed operations + `baselineContentHash`. Returns `activityId`, status, expiry. Stale signatures or content drift → `flavor_agent_apply_stale` + a `stale_blocked` outcome diagnostic (mirrors editor + style-lane behavior). Per-user pending cap (shared `flavor_agent_external_apply_pending_cap`) → `flavor_agent_apply_queue_full`. |

`get-activity`, `list-activity`, and `undo-activity` are **reused, not duplicated**: `get`/`list` are already surface-agnostic; `undo-activity`'s surface allowlist (currently `{global-styles, style-book}`) gains `template-part` and dispatches to the new executor. Approval remains **not** an ability — the human gate is never exposed to agents; it stays the admin REST decision action.

## Executor dispatch seam (the one structural change to shared code)

`PendingApplyDecision::decide` and `ApplyAbilities::undo_activity` are currently hard-wired to `StyleApplyExecutor` (they call `resolve_user_global_styles`, `comparable_config_hash`, `StyleApplyExecutor::apply/undo`). Introduce a thin contract:

```php
interface ExternalApplyExecutor {
    // Re-resolve the live subject and return the drift baseline for gate 2.
    public static function resolve_baseline( array $entry ): string|\WP_Error;
    public static function apply( array $entry ): array|\WP_Error;   // returns before/after/target
    public static function undo( array $entry ): array|\WP_Error;
}
```

`StyleApplyExecutor` and `TemplatePartApplyExecutor` both satisfy it; the decision service and undo handler dispatch by `entry.surface`. This is justified by a **concrete** second consumer, not a speculative registry — it is a `match($surface)` dispatch, not a plugin-extensible framework, and it explicitly does **not** touch the attestation lane (see below). The style lane's existing behavior must remain byte-identical after the refactor (covered by the existing `ExternalApplyLifecycleTest`).

## Pending lifecycle & persistence

Identical to C1, reusing the same table, `request.apply` shape, and `execution_result` mirroring. Differences are confined to the payload:

- `surface: 'template-part'`, `type: 'apply_template_part_suggestion'`.
- `target`: `{ templatePartId, slug, area, theme }` (the identity `recommend-template-part` already resolves), instead of `{ globalStylesId }`.
- `request.apply.operations`: the validated ≤3 path-addressed ops, each retaining its `expectedTarget` fingerprint.
- `request.apply.signatures`: `{ resolvedContextSignature, reviewContextSignature, baselineContentHash }` — `baselineContentHash` replaces `baselineConfigHash`.
- `document`: `{ scopeKey, postType: 'wp_template_part', entityId, entityKind: 'templatePart' }`.
- Pending / rejected / expired / approval-failed rows keep `undo.status: not_applicable` and empty `before`/`after`; executed rows store full pre-apply / post-apply `post_content` snapshots (see Undo).

All admin projection, status-filter, queue-cap, lazy-expiry, and ordered-undo-exclusion rules carry over unchanged because they key off `execution_result` and `apply.status`, not the surface.

## Server-side executor (`inc/Apply/TemplatePartApplyExecutor.php`)

**Apply:**
1. Resolve the live `wp_template_part` via the existing template-part resolution path; read `->content`.
2. **Gate 2 (decision-time drift):** hash the parsed→reserialized live content; it must `hash_equals` the stored `baselineContentHash`. Mismatch → `flavor_agent_apply_stale` → row `failed`.
3. **Re-validate operations** against the live tree: rebuild `operationTargets`/`insertionAnchors`/`blockLookup` and re-run `TemplatePartPrompt::validate_operations`; the stored op count must survive unchanged (mirrors `request_style_apply`'s apply-time re-validation).
4. **Per-op structural re-resolution:** for each op, resolve `targetPath` in the parsed tree and verify the live block matches the op's `expectedTarget` fingerprint (block name + `childCount`, with attribute checks). Any mismatch → abort the whole plan.
5. **Resolve patterns:** `insert_pattern` / `replace_block_with_pattern` resolve `patternName` → blocks from the same allowlist the recommendation used (registered → `parse_blocks(content)`; synced → `core/block` ref). Unresolvable pattern → abort.
6. **Apply atomically:** parse once; because `validate_operations` already rejects overlapping/ancestor paths and caps at 3, resolve all targets up front, then mutate in a drift-safe order (removals/replacements per-parent in descending sibling index; insertions applied against captured anchors), `serialize_blocks` once, persist once. **All-or-nothing** — any failure discards every mutation and writes no content.
7. **Persist** through core post APIs, materializing a theme-provided part into a `wp_template_part` post on first apply (slug + `wp_theme` term), firing the same cache invalidation discipline `StyleApplyExecutor` uses for its CPT write.
8. Snapshot `before.content` (pre-apply `post_content`) and `after.content` (post-apply) plus `after.operations` (executed ops) into the editor-compatible row shapes.

**Undo:** equality checks exactly as the style lane does them — already-undone short-circuit, drift failure when the live content no longer equals the recorded `after.content`, else restore `before.content` through the same persistence path. Ordered-undo (`can_perform_ordered_undo`) still applies.

## Attestation boundary

**No attestation for template-part applies.** `AttestationService` is hard-bounded to `external-style-apply-v1` (`assert_owned_lane_context()` throws for any non-`{global-styles, style-book}` surface, and a second producer was already designed and rejected as premature). `PendingApplyDecision` today *always* calls `AttestationService::record_apply` after a successful apply and `record_revert` on undo; this design **branches those calls to the style lane only**. Template-part applies get the full pending → approve → drift-checked apply → undo loop and complete activity audit, but no signed statement. A template attestation lane, if ever, is a separate later decision; this work does not touch the byte-exact envelope, `Canonicalizer`, or the lane guard.

## Admin approval surface

No new view. The existing `Settings > AI Activity` Approvals view renders pending external applies generically; it gains template-part rows, and the decision route `POST /flavor-agent/v1/activity/{id}/decision` is unchanged (template-part rows require the page's `manage_options` **and** the row's mutation capability, `edit_theme_options`). One admin-JS gap to close (see net-new #6): the governance evidence section formats operations with the style-shaped `formatStyleOperationSummary` and labels targets with style-specific helpers, so template-part rows need a small **structural-operation summary** (op type · target path · pattern / expected block) and a template-part target label. The rich visual diff viewer is correctly style-only — `getStyleVisualDiffRows` gates on surface, so it renders empty for template-part and the row shows the structural summary + raw `State snapshots`, which is honest for structural ops.

## Error handling

- Stale signatures or content drift **at request** → no pending row; `flavor_agent_apply_stale` + `stale_blocked` diagnostic.
- Drift, failed re-validation, `expectedTarget` mismatch, or unresolvable pattern **at approval** → row `failed` with a specific reason the agent sees via `get-activity`; no partial mutation.
- Undo: ordered-undo violation → 409; content drift → `failed` persisted with message; already-undone → idempotent success without rewrite. Non-executed rows are not undo candidates and do not block older executed rows.
- Queue abuse → shared per-user pending cap; TTL prevents indefinite pending buildup.

## Docs and guard updates (same change, not follow-ups)

- `docs/reference/abilities-and-routes.md` — new ability row, undo-surface extension, lifecycle note; bump the **guarded count string** (currently `30`) → `31` together with its `check-doc-freshness.sh` pattern.
- `CLAUDE.md` + `.github/copilot-instructions.md` — byte-parity `30 abilities across … categories` string updated in both, plus the guard pattern.
- `docs/reference/governance-layer.md` — Surface Coverage gains external template-part apply; External-Agent Parity note updated.
- `docs/reference/activity-state-machine.md` (template-part rows reuse the pre-apply section), `docs/FEATURE_SURFACE_MATRIX.md` (programmatic table), `docs/SOURCE_OF_TRUTH.md`, `STATUS.md`, `docs/reference/current-open-work.md` (move the template-part executor from open to shipped; keep the page-level template executor open).
- The `18 always-on` guarded string is unaffected: the new ability is feature-gated.

## Testing (TDD, PHPUnit-heavy)

- **Executor:** apply happy path (each op type); gate-2 content drift; failed live re-validation; per-op `expectedTarget` mismatch; **path-drift within a multi-op plan** (e.g. `remove [0]` then an op addressing a shifted sibling); atomic rollback on partial failure (no content written); pattern-resolution failure; theme-part materialization on first apply; undo happy path; undo drift-blocked; ordered-undo-blocked; **attestation NOT recorded for template-part** (and style-lane attestation still recorded — regression guard on the branch).
- **Lifecycle/contract:** extend `ExternalApplyLifecycleTest` for the template-part surface and the dispatch seam (style behavior unchanged); queue cap / expiry shared with style; serializer/projection for the new `target`/`document` shape; `request-template-part-apply` registration + schema (`RegistrationSchemaTest` / `AbilitySchemaContractTest`); dedicated-server roster (`MCPServerBootstrapTest`); permissions matrix incl. approver capability.
- **Freshness:** stale at request, stale at approval, both via recomputed template-part signatures.
- **JS:** the template-part structural-operation summary + target label in the activity-log admin utils (the style visual-diff viewer stays empty for the surface).
- **Gates** (cross-surface: ability contracts + activity subsystem + a new executor): `node scripts/verify.js --skip-e2e` + summary, `npm run check:docs`. E2E WP70 is manual-only per the coverage topology — keep lifecycle guarantees in PHPUnit; add a thin admin decision Playground spec only if the seeded flow can demonstrate it honestly, else record a waiver.

## Risks

- **Theme-part materialization** is the highest-uncertainty area: persisting a file-backed part into a `wp_template_part` post server-side (correct slug, `wp_theme` term, cache invalidation) without the REST request context. Mitigation: mirror the Site Editor's customize-on-save semantics and `StyleApplyExecutor`'s CPT-write discipline; cover first-apply materialization explicitly in tests; if a part cannot be safely materialized, fail closed before any write.
- **Path drift within a plan** is the core executor hazard the block-operation extension notes flag. Mitigation: overlap rejection (already enforced) + `expectedTarget` re-resolution + drift-safe mutation ordering + atomic all-or-nothing apply.
- **An open Site Editor session** does not live-refresh after an external apply lands; activity hydration shows it on next load (documented, same as the style lane).
- **Snapshot shape** for template-part rows is content-based (full `post_content`), distinct from style rows; the undo drift check keys off `after.content` equality, asserted by tests.

## Out of scope (later specs)

Page-level template executor; a shared executor framework beyond the two-impl dispatch seam; any template/template-part attestation lane; parameterized / `preserve` pattern application; structural review-diff UI beyond the existing governance evidence rendering; inline-safe direct-apply fast path.
