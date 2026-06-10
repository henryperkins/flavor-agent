# Governed External Applies (C1) — Design

> Date: 2026-06-10
> Status: Implemented; historical design context. The shipped implementation plan is archived at `docs/superpowers/plans/archive/2026-06-10-governed-external-applies-c1.md`, and current behavior is documented in `docs/reference/governance-layer.md`, `docs/reference/abilities-and-routes.md`, and `docs/features/activity-and-audit.md`.
> Decisions locked: site-side approval queue · style surfaces first · built on master
> Predecessor: `2026-06-10-governance-layer-repositioning-design.md` (Approach B, shipped)

## Goal

Extend the governance loop to external agents: an agent that received a `recommend-style` / `preview-recommend-style` result can request an apply through the Abilities API / dedicated MCP server, a capability-holding human approves it in `Settings > AI Activity`, the server executes it with freshness revalidation, and the resulting change is attributed, auditable, and reversible — closing the parity boundary documented in `docs/reference/governance-layer.md`.

The external contract mirrors the editor: style operations are review-safe tier, so every external style apply is review-gated. The reviewer is a site-side human, not the calling agent. "AI proposes; WordPress approves."

## What exists vs. what is net-new

Reused server-side primitives: operation validation at generation time, freshness signature recomputation (`resolveSignatureOnly` path), activity persistence with before/after snapshots and provenance projections, the ordered-undo rule (409), the one-way `available → undone/failed` machine for executed rows, contextual `Activity\Permissions`.

Net-new: a server-side style mutation executor (today all applies/undos mutate via client editor stores), a pre-apply pending lifecycle, an admin approval surface, and four abilities.

## New abilities (25 → 29)

All in the `flavor-agent` category, registered behind the same recommendation feature gate as `recommend-*`, exposed on the dedicated MCP server roster, **not** `meta.mcp.public` (universal server stays read-only helpers; activity rows can carry prompts).

| Ability | Permission | Annotations | Behavior |
| --- | --- | --- | --- |
| `flavor-agent/request-style-apply` | `edit_theme_options` | `destructive:false, idempotent:false` (creates a queue row, mutates nothing) | Validates operations against the current execution contract, recomputes and matches `resolvedContextSignature`/`reviewContextSignature`, then creates a **pending** external-apply activity row with proposed operations. Returns `activityId`, status, expiry. Stale signatures → `flavor_agent_apply_stale` plus a `stale_blocked` outcome diagnostic (mirrors editor behavior). Per-user pending cap (default 10, filterable) → `flavor_agent_apply_queue_full`. |
| `flavor-agent/get-activity` | contextual (`Activity\Permissions`) | `readonly:true` | One row by `activityId` — the agent's status-polling and attribution read. |
| `flavor-agent/list-activity` | contextual | `readonly:true` | Scoped list with surface/status filters; admin-global reads stay REST-only. |
| `flavor-agent/undo-activity` | contextual per row (style rows: `edit_theme_options`) | `destructive:true, idempotent:false` | Server-side undo: ordered-undo rule, drift check (current config must equal recorded `after`; equal-to-`before` reports already-undone), restore `before`, persist `undone`/`failed`. Works for externally created executed rows and editor-created style rows; Global Styles full snapshots and Style Book branch-only snapshots are both supported and verified in tests. |

Approval itself is deliberately **not** an ability: the human gate is never exposed to agents. It is an admin REST action only.

## Pending lifecycle (new, pre-apply)

`apply.status`: `pending → available (approved + executed) | rejected | expired | failed`

- One row throughout: the pending row carries proposed operations + requester provenance (`requestedBy`, request reference, signatures); on approval the server re-validates freshness and operations, executes, records execution-time `before`/`after` snapshots, sets `decidedBy`/`decidedAt`, and the row becomes a normal undoable apply row (`apply.status: available`, `undo.status: available`, ordered-undo applies).
- Freshness is checked **twice**: at request and again at approval execution; drift at approval → `failed` with a stale reason the agent sees via `get-activity`.
- Expiry: default 24 h (`flavor_agent_external_apply_pending_ttl` filter), enforced lazily on read plus swept by the existing prune cron.
- `docs/reference/activity-state-machine.md` gains this pre-apply section; the post-apply machine is unchanged.

## Persistence and projection contract

C1 keeps the single `flavor_agent_activity` table and one-row lifecycle, but it must make the pre-apply state explicit instead of overloading undo state.

- Persist the logical apply lifecycle in `request.apply` and hydrate it as top-level `entry.apply` for REST and ability responses. Shape: `{ status, requestedBy, requestedAt, expiresAt, operations, signatures, requestReference, decidedBy?, decidedAt?, decisionNote?, failureCode?, failureMessage?, executedAt? }`.
- Mirror SQL-filterable lifecycle status in `execution_result`: `pending`, `rejected`, `expired`, `failed` before execution; `applied` once approval has executed and the row is undoable. The public `entry.apply.status` for that executed state is `available`.
- Pending, rejected, expired, and approval-time failed rows use `undo.status: not_applicable` and empty `before` / `after` mutation snapshots. Proposed operations live only under `request.apply.operations` until execution.
- On approved execution, write the snapshots and executed operations into the existing `before`, `after`, and `target` shapes used by editor-created style rows, set `request.apply.status: available`, `request.apply.executedAt`, `execution_result: applied`, and `undo.status: available`.
- Admin projections and filters expand the status vocabulary to include `pending`, `rejected`, and `expired`, and derive pending operation metadata from `request.apply.operations`; executed rows continue to derive operation metadata from `after.operations`.
- Ordered undo and admin "newer active" status ignore non-executed rows (`pending`, `rejected`, `expired`, and approval-time `failed` with no `executedAt`). Executed rows with `undo.status: failed` keep the current blocking semantics because the mutation may still be live.
- Queue-cap checks count only unexpired `execution_result = pending` rows requested by the current user after lazy expiry has run.

## Server-side executor

`inc/Apply/StyleApplyExecutor.php` (new `FlavorAgent\Apply\` namespace, shared by approval execution and ability undo):

1. Resolve the user global styles entity (`WP_Theme_JSON_Resolver` user CPT) — target identity recorded as the client does (`target.globalStylesId`).
2. Normalize + validate operations against the server execution contract (same vocabulary as `recommend-style` output: path/value sets, variations, `set_block_styles` for Style Book), enforce `StyleContrastValidator` (WCAG AA 4.5) on the merged result — porting the client pipeline in `src/utils/style-operations.js` (`applyGlobalStyleSuggestionOperations`).
3. Apply to config, write the CPT through core APIs so theme-token cache invalidation fires, and snapshot in the editor-compatible shape:
   - Global Styles rows store full `before.userConfig` / `after.userConfig`.
   - Style Book rows store the targeted `styles.blocks.<blockName>` branch only, matching `buildStyleBookActivityEntry()` and keeping ability undo compatible with existing editor-created rows. The executor still resolves full config for validation, contrast, write, and drift checks, then trims the persisted snapshots.
4. Undo path: equality checks exactly as the client does them — already-undone short-circuit, after-mismatch drift failure, else restore `before`.

## Admin approval surface

`Settings > AI Activity` (existing DataViews app) gains an Approvals view (`apply.status: pending`) with operation summaries (reuse `src/style-surfaces/presentation.js` formatting), before/proposed detail, and Approve / Reject actions with optional note.

REST: `POST /flavor-agent/v1/activity/{id}/decision` `{ decision: approve|reject, note? }` — requires the page's `manage_options` **and** the row's mutation capability (`edit_theme_options` for style rows). Decision provenance is projected for audit filtering.

Editor-side visibility of pending external applies is deferred to C2; C1's contract is admin-page-only.

## Error handling

- Stale at request → no pending row; `flavor_agent_apply_stale` + `stale_blocked` diagnostic.
- Stale or invalid at approval → row `failed` with reason; agent observes via `get-activity`.
- Undo: ordered-undo violation → 409 semantics as today; drift → `failed` persisted with error message; already-undone → idempotent success report without rewrite (one-way persistence rules unchanged). Non-executed pending/rejected/expired/approval-failed rows are not undo candidates and do not block older executed rows.
- Queue abuse → per-user pending cap; expiry prevents indefinite pending buildup.

## Docs and guard updates (same change, not follow-ups)

- `docs/reference/abilities-and-routes.md` — four new ability rows, decision route, lifecycle notes; update the **guarded string** `defines 25 ability contracts` → 29 together with its `check-doc-freshness.sh` pattern.
- `CLAUDE.md` + `.github/copilot-instructions.md` — byte-parity string `25 abilities across … categories` updated in both plus the guard pattern.
- `docs/reference/governance-layer.md` — External-Agent Parity boundary rewritten (apply/undo/activity abilities now exist; approval is the human gate); Surface Coverage note for external style applies.
- `docs/reference/activity-state-machine.md`, `docs/FEATURE_SURFACE_MATRIX.md` (programmatic table), `docs/SOURCE_OF_TRUTH.md`, `STATUS.md`.
- The `18 always-on` guarded string is unaffected: all four abilities are feature-gated.

## Testing (TDD)

- PHPUnit: executor apply/undo/drift/contrast/Style Book branch; Global Styles full snapshot and Style Book branch-only snapshot compatibility; queue transitions, expiry, caps; serializer/hydration and admin SQL projection for `pending`/`rejected`/`expired`/pre-execution `failed`; ordered-undo exclusion for non-executed rows; permissions matrix incl. approver capability; freshness binding (stale at request, stale at approval); registration/schema coverage extending `RegistrationSchemaTest` / `AbilitySchemaContractTest`; decision route in `AgentRoutesTest`; dedicated-server roster in `MCPServerBootstrapTest`.
- JS: approvals view and decision actions in the activity-log app.
- Gates (cross-surface: ability contracts + activity subsystem): `node scripts/verify.js --skip-e2e` + summary, `npm run check:docs`, wp70 style-undo parity already covered; admin approvals browser coverage via a Playground spec or an explicit recorded waiver.

## Risks

- Approval-time drift vs. an open Site Editor session — handled by the second freshness check failing closed; operator guidance documented.
- Editor-created rows undone via ability depend on snapshot-shape compatibility — full Global Styles rows and branch-only Style Book rows are asserted by tests; any legacy shape falls back to a scoped unavailable/failed reason rather than a destructive restore.
- An open Site Editor session does not live-refresh after an external apply lands; activity hydration shows it on next load (documented).

## Out of scope (C2+)

Template/template-part/block executors, editor-side pending-apply visibility, inline-safe direct-apply fast path, approval notifications.
