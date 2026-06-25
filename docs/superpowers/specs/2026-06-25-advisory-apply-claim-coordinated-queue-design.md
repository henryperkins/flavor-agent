# Advisory Apply-Claim — Coordinated Approval Queue (v1) — Design

> Date: 2026-06-25
> Status: Proposed. Implementation plan to follow under `docs/superpowers/plans/`.
> Decisions locked (brainstorming): collision-safe coordinated queue thread — collision-safety of the decision *outcome* is the write-boundary guard's job; the claim itself is **best-effort** visibility, not a race-proof lock (thread chosen over accountability-audit and routing/assignment) · **advisory, non-blocking** claim (Approach B, not an enforced lock) · per-row **transient** storage, no schema migration · claim TTL **5 minutes** · **no claim-steal** in v1 (holder-release + auto-expire only) · claims attach only to **pending external-apply rows** · **no ability registered** (REST routes, not abilities — the `30/31` ability-count guard is untouched).
> Predecessors: `2026-06-10-governed-external-applies-c1-design.md` (the external-apply lane this extends) and the `Settings > AI Activity` governance console shipped from `docs/superpowers/plans/archive/2026-06-10-ai-activity-governance-console-c1-1.md` (this is the next bounded slice past that plan's now fully-consumed follow-up list).

## Goal

Make the pending external-apply approval queue in `Settings > AI Activity` legible to **multiple administrators at once**, so two operators do not unknowingly review and decide the same pending row. Two changes deliver this: (1) an **advisory, auto-expiring "being reviewed by X" claim** surfaced on pending rows, and (2) a **legible race-loss** — when a second admin's decision loses the commit race, the UI resolves and shows the row's terminal "already approved by another admin · 2m ago" state instead of a generic error.

This buys **coordination visibility only**. Correctness is already guaranteed elsewhere and is not touched: `Repository::transition_external_apply` keeps a pending guard at the write boundary (`inc/Activity/Repository.php:897`) so "simultaneous decisions cannot overwrite an already-decided row after both readers saw pending." A claim is never a lock; it never gates the decision path. This explicitly avoids building a competing global-governance / assignment plane (the Unified-AI-Management upstream-watch caution): there is no enforced ownership, no routing, no steal/override semantics.

## Core invariant

**A claim is advisory.** It is a hint about who is currently looking at a row. It is never read by `PendingApplyDecision::decide` and never changes who may decide. The only enforcement of single-execution remains the existing write-boundary pending guard. Every part of this design must preserve that: a held claim must not block another capability-holding admin from approving or rejecting, and the decision path's behavior must stay byte-identical.

The claim **write is also best-effort**: it is a plain read-then-`set_transient` with no atomic compare-and-swap, so two near-simultaneous claims race benignly (last write can win, and both admins may briefly see themselves as the reviewer). That is acceptable *because* the claim is advisory — a transient double-"reviewing" state misleads no one into a double-apply, since the decision guard, not the claim, prevents double-execution. v1 deliberately does **not** add a CAS primitive: core transients expose no clean cross-backend compare-and-swap, and locking an advisory visibility hint would be over-engineering. "Collision-safe" in this spec therefore describes the decision *outcome* (guaranteed by the guard), never the claim write.

## What exists vs. what is net-new

**Reused, not duplicated:**
- The write-boundary double-execution guard (`Repository::transition_external_apply`, `inc/Activity/Repository.php:829` with the conditional pending update at `:897`–`:920`). The claim layer sits entirely above it.
- Decision attribution is already captured and displayed: `decide()` records `decidedBy` / `decidedAt` / `decisionNote` on every terminal transition (`inc/Apply/PendingApplyDecision.php:58`–`:69`), and the admin UI already renders them (`src/admin/activity-log-utils.js:1593`–`1598`, `:2359`–`2362`; the "Decision note" detail row at `src/admin/activity-log.js:2341`). The legible-conflict change reuses this display; no new attribution data is invented.
- The per-row capability gate `ActivityPermissions::can_decide_activity_request` (`inc/Activity/Permissions.php:75` — `manage_options` **and** the row's contextual `can_access_entry`). The claim routes reuse it verbatim.
- The decision route registration shape (`inc/REST/Agent_Controller.php:351`), the admin read query `Repository::query_admin` (`inc/Activity/Repository.php:392`), the client `reloadToken` refetch (`src/admin/activity-log.js:2611`, `:2863`), and `submitDecision` (`src/admin/activity-log.js:2350`).
- The uninstall stance that dynamic best-effort transients are not bulk-deleted (`docs/SOURCE_OF_TRUTH.md` inventory item 8) — claims are exactly that kind of ephemeral transient.

**Net-new:**
1. An `ApplyClaim` service (`inc/Apply/ApplyClaim.php`) — transient-backed `get` / `claim` / `release` / `clear`, with a TTL constant and a pending-external-apply precondition.
2. Two REST routes — `POST /flavor-agent/v1/activity/{id}/claim` (acquire/refresh) and `DELETE /flavor-agent/v1/activity/{id}/claim` (release) — plus handlers, both gated by `can_decide_activity_request`.
3. Admin read enrichment: pending external-apply rows carry `apply.claim` in the serialized payload.
4. A single `ApplyClaim::clear` call in `transition_external_apply`'s **committed-success path** (after the write-boundary update lands, `inc/Activity/Repository.php:917`+), so a decided row never shows a stale claim — while a non-committing `500`/`409` leaves the claim untouched.
5. JS: claim/release request builders, auto-claim when a pending row's decision controls open, a "being reviewed by X" badge, the legible race-loss (pinned resolved entry), and an opportunistic focus/visibility refresh.
6. Docs: the two new routes in `docs/reference/abilities-and-routes.md` and a surface note in `docs/features/activity-and-audit.md`. **No ability-count change.**
7. One boot-data field — `currentUserId` in the `flavorAgentActivityLog` localize array (`inc/Admin/ActivityPage.php:199`, which exposes no viewer identity today) — so the admin app can tell "You're reviewing this" from another viewer's claim.

## Claim store (`inc/Apply/ApplyClaim.php`)

- Storage: one WordPress transient per row, key `'flavor_agent_apply_claim_' . md5( $activity_id )` — a fixed 32-char hash, **not** the raw id concatenated. `activity_id` is `varchar(191)`, caller-supplied and only trimmed (`inc/Activity/Repository.php:73`, `:169`), so concatenating it could blow WordPress's ~172-char transient-name limit; `md5` here is a non-cryptographic cache-key hash, not a security boundary (finding 3). Value `[ 'userId' => int, 'claimedAt' => string(ISO-8601) ]`, written with `set_transient( …, …, self::TTL )`. `self::TTL = 5 * MINUTE_IN_SECONDS`.
- `get( string $activity_id ): array|null` — returns the live claim or `null` (an expired transient already reads as absent; no manual expiry math).
- `claim( string $activity_id, int $user_id ): array|\WP_Error` — returns `[ 'claim' => array|null, 'entry' => array ]` (or `WP_Error`); the handler returns it as the response body. `entry` is the **current serialized row** after lazy-expiry, so any claim call doubles as a fresh single-row read the client can pin — this is how a decision made elsewhere becomes legible without adding a `GET /activity/{id}` (finding 1). A **missing** row returns `WP_Error( 'flavor_agent_activity_not_found', 404 )`, mirroring `decide()` (and required because `can_decide_activity_request` passes missing rows through so the handler returns the 404). The hydrated row is first run through `ActivityRepository::maybe_expire_pending_apply()` exactly as `decide()` does (`inc/Apply/PendingApplyDecision.php:30`), so an overdue pending request materializes its expiry here too. A row that is **not** pending after that recheck (`apply.status !== 'pending'`) writes no claim and returns `claim => null` with the terminal `entry`. Otherwise: if there is no live claim, or the live claim is the caller's own, set/refresh the caller's claim and return it; if **another** user appears to hold a live claim, return that existing claim **without overwriting it** (best-effort — in the common non-racing case the first claim stands and we report rather than steal; the write is not atomic, so a simultaneous claim can still win, per the Core invariant).
- `release( string $activity_id, int $user_id ): array|\WP_Error` — same `[ 'claim' => array|null, 'entry' => array ]` shape; a missing row returns the same `404` (handler symmetry with claim/decide). On an existing row, `delete_transient` **iff** the live claim's `userId` equals `$user_id` (or the claim is already absent); a foreign live claim is left intact (release is not a steal vector). Idempotent.
- `clear( string $activity_id ): void` — unconditional `delete_transient`, called by `transition_external_apply` only after a **committed** transition out of pending (not on its non-committing `500`/`409` returns). Idempotent.
- Display names are **not** stored. The admin UI resolves `userId → label` at render via the existing `formatUserIdLabel` (which renders the "User #<id>" form — `src/admin/activity-log-utils.js:1663`; friendly display-name resolution is a noted out-of-scope enhancement), so a renamed user never shows a stale name.
- Forward-compatible by surface: the precondition keys off `apply.status === 'pending'`, not a hard-coded `global-styles`/`style-book` surface, so the template-part external-apply executor (separate plan) inherits claims for free with no change here.

## REST contract

Both routes mirror `/activity/{id}/decision` and share its `permission_callback`:

| Route | Method | Permission | Behavior |
| --- | --- | --- | --- |
| `/activity/{id}/claim` | `POST` | `can_decide_activity_request` | `ApplyClaim::claim( id, current_user )`. `404` if the row is missing (handler-side, mirroring decision). Returns `{ claim: {userId, claimedAt}|null, entry: <serialized row> }`, `200`. Idempotent: re-claiming refreshes TTL; a foreign live claim is returned unchanged so the client can show "User #&lt;id&gt; is reviewing"; `entry` lets the client pin a decided-elsewhere row. Never `409` — claiming is advisory (best-effort write, per the Core invariant). |
| `/activity/{id}/claim` | `DELETE` | `can_decide_activity_request` | `ApplyClaim::release( id, current_user )`. `404` if the row is missing (symmetry with claim/decision). Returns `{ claim: …|null, entry: <serialized row> }` (a foreign claim is left intact). Idempotent no-op when there is nothing of the caller's to release. |

Handlers (`handle_activity_claim`, `handle_activity_claim_release`) live beside `handle_activity_decision` in `Agent_Controller` and call `ActivityPermissions::forbidden_error()` on gate failure, exactly as the decision handler does.

**Terminal-state pinning (resolves the race-loss everywhere — finding 1).** The pending-only Approvals filter drops a row the instant it leaves pending, and `selectedEntry` is derived solely from `entries.find( … === selectedEntryId )` (`src/admin/activity-log.js:3202`), so a refetch that loses the row goes `null` and clears the selection (`:3221`–`:3229`). To keep every terminal outcome legible — not just the submit race — the client **pins** a serialized entry locally and renders the row's terminal "already approved/rejected/expired by …" state from the pinned copy, independent of the active filter. The pinned entry comes from whichever path fires:
- **Successful local decision** → pin the decision response's `entry` (`handle_activity_decision` already returns `{ entry }`, consumed at `onDecided` — `src/admin/activity-log.js:2370`), so the deciding admin sees the confirmed result instead of the row vanishing (open question 1: yes, success pins too).
- **Race-lost decision** (`flavor_agent_apply_not_pending` 409 / `flavor_agent_apply_expired` 410) → issue one `POST /claim`; its response `entry` is the terminal row; pin it.
- **Decided elsewhere while watching** (no local submit) → the selected pending row's focus-refresh re-claim (see Refresh model) returns `claim => null` with the terminal `entry`; pin it.

The pinned banner persists until the reviewer dismisses it or navigates; a `reloadToken` bump still follows to reconcile the rest of the feed. The decision request-token guard (`decisionRequestTokenRef`) is preserved. No single-row `GET /activity/{id}` is added — the claim and decision responses already carry the entry.

## Admin feed integration

The admin activity read path enriches each returned row whose `apply.status === 'pending'` with `apply.claim = ApplyClaim::get( activity_id )` before serialization. This is a single bounded pass (pending external applies are few), reads transients only, and requires **no** change to `ADMIN_PROJECTION_SELECT_SQL` and **no** new column. Non-pending rows are never enriched and never carry a claim field with a value.

## Boot data

`inc/Admin/ActivityPage.php`'s `flavorAgentActivityLog` localize array (`:199`) exposes no viewer identity today (it has `canApproveStyleApplies` but no user id). Add one field — `currentUserId => get_current_user_id()` — so the admin app can render "You're reviewing this" for the viewer's own claim versus "User #<id> is reviewing" for another's. No display-name lookup is added: labels stay the existing "User #<id>" form via `formatUserIdLabel` (`src/admin/activity-log-utils.js:1663`); friendly-name resolution is a noted out-of-scope enhancement.

## Legible conflict (Approach A core — always included)

`submitDecision` (`src/admin/activity-log.js:2350`) currently catches the `409`/`410` and shows `error?.message || 'The decision could not be recorded.'`. Change: on `flavor_agent_apply_not_pending` / `flavor_agent_apply_expired`, resolve and pin the terminal entry per **Terminal-state pinning** above (a single `POST /claim` fetches it), then render the row's terminal state using the existing `decidedBy`/`decidedAt`/`decisionNote` display with a specific notice — e.g. "This was already approved by User #<id> · {relative time}" (or "rejected" / "expired"). **Retryable** failures are handled differently (finding 2): a `500` (`flavor_agent_activity_storage_unavailable` / `flavor_agent_activity_update_failed`) leaves the row pending, so `submitDecision` shows the existing inline retry error and **keeps** the claim — it does not pin a terminal state and does not release. The decision request-token guard (`decisionRequestTokenRef`) is preserved.

## Refresh model — opportunistic, not polling

No `setInterval`. Opportunistic triggers:
1. A debounced window `focus` / `visibilitychange` listener bumps `reloadToken` so an admin returning to the tab sees current claims and decisions. If a **pending row is selected**, the same event re-issues its `POST /claim` — which refreshes the claim TTL while the reviewer is actively looking **and** detects a decision made elsewhere (the response `entry` comes back terminal → pin it, per Terminal-state pinning).
2. Opening a pending row's decision controls auto-claims (`POST …/claim`); the response `entry` is the fresh row.
3. **Release (`DELETE …/claim`) fires only on explicit abandon** — deselecting, closing the row, or navigating away — **never on decision submit** (finding 2). A *successful* decision already clears the claim server-side via `transition_external_apply` → `ApplyClaim::clear`, and a *retryable* failure (`500`, row still pending) must **keep** the claim so the active reviewer is not advertised as gone. The 5-minute TTL covers any missed release (tab close, crash).

This is documented as advisory/best-effort: a claim may briefly lag reality, and the write-boundary guard — not the claim — is the real safety.

## UI

- **Pending-row badge** (DataViews feed and the selected-row panel): when `apply.claim` is present and its `userId` is **not** the current viewer (compared against the new `currentUserId` boot field), "🟡 Being reviewed by User #<id> · {relative time}" (rendered via the existing `formatUserIdLabel`); when `userId` equals the viewer, "You're reviewing this." Absent/expired claim renders nothing.
- **Decision panel:** opening it auto-claims. A **Release** control renders **only when the current viewer holds the claim** (`apply.claim.userId === currentUserId`) — open question 2; there is nothing for a non-holder to release and we do not steal. If another user holds the claim, only the passive note — "User #<id> is reviewing — you can still decide" — sits above the unchanged approve/reject controls. After a decision resolves (success or race-loss), the panel shows the pinned terminal state (per Terminal-state pinning) instead of the row silently disappearing.
- The badge and note are passive, reuse existing notice/badge styling, and never disable the decision buttons.

## Error handling / edge cases

- Expired claim reads as absent (transient TTL) — no special-casing.
- Claim attempt on a now-non-pending row: no write; returns `claim => null` with the terminal `entry` (`200`), which the client pins.
- A user who lost `manage_options` or the row's contextual capability cannot claim or decide — the shared gate blocks both.
- Two simultaneous claims race benignly: the transient write is not atomic, so last write can win and both admins may briefly see themselves as the reviewer. Harmless — the claim is advisory; the decision guard (not the claim) prevents double-execution, so whichever decision commits first wins and the other admin gets the legible race-loss.
- A **retryable** decision failure (`500`: `flavor_agent_activity_storage_unavailable` / `flavor_agent_activity_update_failed`) leaves the row pending; the client keeps the claim and shows the inline retry error (finding 2) — only committed terminal outcomes pin or clear.
- A row **decided elsewhere** while the viewer watches: the next focus-refresh re-claim (or any claim/decision call) returns the terminal `entry`, which the client pins, so the viewer sees the resolution instead of the row vanishing.
- A recorded decision (approve/reject/failed/expired) clears the claim via `transition_external_apply` → `ApplyClaim::clear` **after the committed write**, so decided rows never show a stale "being reviewed" badge.
- Release of a foreign live claim is a no-op that leaves it intact (no steal-by-release).

## Docs and guard updates (same change, not follow-ups)

- `docs/reference/abilities-and-routes.md` — add the two `/activity/{id}/claim` route rows (POST/DELETE) and a one-line lifecycle note. The guarded **ability** count string is unaffected (claims are REST routes, not abilities).
- `docs/features/activity-and-audit.md` — note advisory claims and legible race-loss under "What This Surface Can Do."
- `docs/reference/activity-state-machine.md` — a short note that claims are an advisory overlay on the pending state and never a transition input.

## Testing (TDD)

- **PHPUnit (`ApplyClaim`):** set/get/auto-expire (TTL); `claim` on pending sets/refreshes own; `claim` by a second user returns the first user's claim (the common non-racing path — we do not assert atomicity, since the write is best-effort by design); `claim` on a non-pending row writes nothing and returns claim-less; **`claim` on an overdue pending row runs `maybe_expire_pending_apply()` first and grants no claim** (P2 #3 regression); `release` clears only the caller's own; `release` of a foreign claim is a no-op leaving it intact; `clear` is unconditional and idempotent; `claim`/`release` responses include the current serialized `entry`; the transient key is `md5( $activity_id )`-hashed and stays within the transient-name limit even for a 191-char id (finding 3).
- **PHPUnit (REST + permissions):** `POST`/`DELETE /claim` route registration and schema; permission parity with `/decision` (`manage_options` + per-row `can_access_entry`); `404` on missing row.
- **PHPUnit (regression — the core invariant):** a held foreign claim does **not** block `transition_external_apply`; `decide` clears the claim on every **committed** terminal transition (approve/reject/failed/expired) while a non-committing `500`/`409` leaves the claim untouched (finding 2); the write-boundary pending guard behavior is unchanged.
- **PHPUnit (read enrichment):** pending external-apply rows serialize with `apply.claim`; non-pending rows do not.
- **JS (Jest):** badge states keyed off `currentUserId` (own → "You're reviewing this"; other → "User #<id> is reviewing"; none/expired → nothing); the **Release** control renders only for the claim holder (open question 2); auto-claim on opening decision controls; **release fires on abandon/close, not on decision submit** (finding 2); a **successful** decision pins `response.entry` and renders the confirmed terminal state in the pending-only view (open question 1); a **race-lost** `409`/`410` re-claims for the terminal `entry`, pins it, and **survives the row dropping out of the pending-only Approvals filter** (finding 1); a **retryable `500`** keeps the claim and shows the inline retry error without pinning or releasing (finding 2); the focus/visibility refresh re-claims the selected pending row and bumps `reloadToken`.
- **Gates** (cross-surface: REST contract + activity subsystem + admin JS): `node scripts/verify.js --skip-e2e` then inspect `output/verify/summary.json`; `npm run check:docs`. WP70 browser E2E is manual-only per the coverage topology; add a thin Playground admin spec only if the seeded flow can demonstrate claim/legible-conflict honestly, else record an explicit waiver.

## Risks

- **Best-effort release.** A tab close or crash can leave a claim set until TTL. Mitigation: the 5-minute TTL and "advisory" framing; nothing depends on a claim being released, and the decision guard is independent.
- **Refresh lag.** Opportunistic (non-polling) refresh means a claim or decision by another admin may not appear until focus/visibility change or the next action. Accepted and documented; correctness never depends on freshness of the claim view.
- **Transient backend variance.** On sites without a persistent object cache, transients live in the options table and still honor TTL; on shared/object-cache backends, eviction can drop a claim early — which is safe (reads as "no claim"). No design dependency on durability.

## Out of scope (later specs)

Enforced locking, claim steal/override, or any decision-gating by claim · **atomic / race-proof claim writes** (no CAS primitive — the write is best-effort by design) · durable `claimed_by` / `claimed_at` columns · **friendly display-name resolution** beyond the existing "User #<id>" label · active presence/polling beyond opportunistic refresh · assignment / routing / handoff / shared saved queues (the routing thread) · cross-operator accountability beyond the already-shipped `decidedBy`/`decisionNote` display (the audit thread) · claims on non-external-apply rows · operator notifications (already shipped 2026-06-22). The separate `SOURCE_OF_TRUTH.md` open-backlog drift and unarchived shipped plans are honest housekeeping but are **not** part of this slice.
