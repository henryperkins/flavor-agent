# Activity Undo State Machine Reference

This document is the contract reference for the activity entry lifecycle and the undo state machine.

Use it when you need to answer:

- what undo states exist and which transitions are valid
- when an undo is blocked and why
- how the client and server coordinate undo state

## Activity Entry Lifecycle

1. User applies a suggestion in the editor
2. Client creates an activity entry via `POST /flavor-agent/v1/activity` with `undo.status: "available"`
3. Entry is stored in the `{prefix}_flavor_agent_activity` database table
4. The entry appears in inline activity history and the admin approval/audit page
5. User may undo the entry, transitioning through the state machine below

Activity history is also maintained as a scoped client session. `loadActivitySession()` hydrates the current scope, merges any pending local entries, and then refreshes from the server-backed activity repository for that same scope.

When server hydration requests `groupBySurface=true`, the repository returns the latest bounded window for each surface independently. A noisy read-only surface such as pattern request diagnostics must not evict executable history from template, block, style, or other surfaces in the same scope.

## External Apply Lifecycle (pre-apply)

External agents can request style applies through `flavor-agent/request-style-apply`. That creates a **pending** row in the same activity table; the post-apply undo machine below is unchanged and only begins once the row is executed.

Template-part external applies (`flavor-agent/request-template-part-apply`) reuse this exact pre-apply section — same pending row, same freshness/drift gates at request and approval, same `apply.status` transitions, expiry, pending cap, and advisory claim overlay — with the only difference being that approval executes through the template-part executor in the shared executor registry (re-validating ≤3 bounded operations against one `wp_template_part`) instead of `StyleApplyExecutor`. They are not attested.

`apply.status`: `pending → available (approved + executed) | rejected | expired | failed`

- One row throughout: the pending row carries the proposed operations, requester provenance (`apply.requestedBy`, `apply.requestReference`), and freshness signatures under `request.apply`, hydrated as top-level `entry.apply`. On approval (`POST /flavor-agent/v1/activity/{id}/decision`, `manage_options` + the row's mutation capability) the server re-validates freshness and operations, executes through `FlavorAgent\Apply\StyleApplyExecutor`, records execution-time `before`/`after` snapshots, sets `decidedBy`/`decidedAt`, and the row becomes a normal undoable apply row (`apply.status: available`, `undo.status: available`, ordered undo applies).
- `execution_result` mirrors the lifecycle for SQL filtering: `pending`, `rejected`, `expired`, `failed` before execution; `applied` once executed.
- Freshness is checked twice: at request (signature recompute plus claimed-config equality against the live entity) and again at approval (live config must still match the `baselineConfigHash` recorded at request). Drift at approval transitions the row to `failed` with a stale reason the agent observes via `flavor-agent/get-activity`.
- Expiry: default 24 h (`flavor_agent_external_apply_pending_ttl` filter), enforced lazily on reads and swept by the existing `flavor_agent_prune_activity` cron. A per-user pending cap (default 10, `flavor_agent_external_apply_pending_cap` filter) bounds queue abuse.
- Pending, rejected, expired, and approval-failed rows use `undo.status: not_applicable`, keep `before`/`after` empty, never participate in ordered undo, and never block undo of older executed rows. Executed rows with `undo.status: failed` keep the existing blocking semantics because the mutation may still be live.
- Operator guidance: an open Site Editor session editing Global Styles will make approvals fail closed (the live entity no longer matches the request-time baseline) — re-request the apply after the editing session saves. The Site Editor also does not live-refresh when an external apply lands; the new row appears on the next activity hydration.
- Advisory review claims (`POST`/`DELETE /flavor-agent/v1/activity/{id}/claim`) are an advisory overlay on the `pending` state, never a transition input: they surface "being reviewed by X" coordination but do not gate a decision. The only transition guard remains the write-boundary `pending` check, and a claim is cleared on the committed transition.

## Undo States

| State | Meaning |
|---|---|
| `available` | The action has been applied and can be undone |
| `review` | The row is a read-only request diagnostic rather than an applied action |
| `blocked` | The action is still applied, but undo is temporarily blocked because newer AI actions on the same entity are still runtime-active |
| `undone` | The action was successfully rolled back |
| `failed` | The undo attempt failed (error message stored in `undo.error`) |
| `not_applicable` | The row is diagnostic only and does not represent an undoable user action |

`review` is not an executable undo state for apply actions. It is only used by scoped read-only `request_diagnostic` audit rows, which the admin page renders as review-only or failed request records instead of as undoable activity. `not_applicable` is used by `recommendation_outcome` diagnostics, which are hidden from normal inline feeds by default and never participate in undo. Pending/rejected/expired/approval-failed external-apply rows also use `not_applicable` — they never executed.

## Valid Transitions

```text
available -> undone    (successful persisted undo)
available -> failed    (undo attempted but failed and was persisted)
available -> blocked   (runtime-only ordered-undo overlay)
blocked   -> available (runtime-only once newer activity is no longer active)
```

Only `undone` and `failed` are persisted by `update_undo_status()`. The runtime `blocked` state is computed client-side from the ordered-undo rule and is not written back to the server.

No other persisted transitions are accepted. The `update_undo_status()` method rejects any status value other than `undone` or `failed` with a `400` error.

Persisted server state remains one-way: `update_undo_status()` only writes `undone` and `failed`.

Even those writes are only valid while the persisted server state is still `available`. Terminal rewrites are rejected with HTTP `409`, including:

- `undone -> failed`
- `failed -> failed`
- `failed -> undone`

When the client receives `409 flavor_agent_activity_invalid_undo_transition` while persisting an undo change, it treats that response as a reconciliation signal rather than an automatic permanent failure. The store refreshes the server-backed activity entry and adopts the persisted terminal state when it already exists there.

At runtime, the client can still resolve an entry back to effectively `available` when the live editor or style state shows the recorded "after" snapshot has been reapplied again (for example after a native redo). That runtime revival is computed from current editor/style state; it is not a persisted `undo.status` transition back to `available`.

## Ordered Undo Rule

Undo is not always permitted even when the persisted state is `available`. The server enforces ordered undo to prevent incoherent rollbacks, and the client reflects that constraint as the runtime `blocked` state.

An undo is **blocked** (HTTP 409) when newer activity entries exist on the same entity that have not yet been undone. Specifically:

1. Query all activity entries for the same `entity_type` and `entity_ref`, ordered by `created_at ASC`
2. Walk backward from the newest entry
3. If the target entry is reached before encountering any non-`undone` newer entry, the undo is eligible
4. If any newer executable entry has a status other than `undone`, the undo is blocked

This ensures undos happen in reverse chronological order per entity, preventing partial rollbacks that would leave the entity in an inconsistent state.

Read-only `request_diagnostic` rows do not block executable ordered undo. The repository skips review-only rows when evaluating the persisted ordered-undo rule, and the client gives diagnostic rows separate entity keys.

The persisted `failed` transition is distinct from runtime unavailable/failed presentation. Client-side validators can show a non-persisted failed or unavailable state when a block, template, template-part, Global Styles, or Style Book target no longer matches the recorded post-apply snapshot; those runtime states do not rewrite the stored row unless an undo attempt is persisted as `failed`.

### Client-Side Enforcement

The client also enforces this rule before sending the request:

- `undoActivity()` in the store refreshes server-backed activity before evaluating eligibility
- If a newer entry on the same entity is still runtime-active after live-state reconciliation, the client blocks the undo locally and returns an error without making a network request

## Undo Metadata Fields

| Field | Type | Set when |
|---|---|---|
| `undo.status` | `string` | Always present |
| `undo.error` | `string` or `null` | Set on `failed` transitions |
| `undo.updatedAt` | ISO 8601 `string` | Set on any transition |
| `undo.undoneAt` | ISO 8601 `string` or `null` | Set on `undone` transitions |

## Review-Only Audit Rows

- Recommendation fetches for content, pattern, navigation, block, template, template-part, Global Styles, and Style Book can persist scoped `request_diagnostic` rows when a document scope exists. These rows record the request attempt separately from any later apply/undo row; signature-only freshness probes stay quiet.
- Recommendation outcomes can persist scoped `recommendation_outcome` rows for `shown`, `selected_for_review`, `stale_blocked`, `validation_blocked`, `insert_failed`, and `pattern_inserted_from_shelf`. Pattern `shown` rows are recorded only after the Flavor Agent shelf is attached to the live inserter DOM and visible, not merely when recommendations become ready. Pattern insert failures distinguish Gutenberg exceptions, no-ops, and wrong-target insertions; wrong-target inserts are rolled back before the diagnostic is recorded when the cloned client IDs are visible after dispatch. These rows use diagnostic visibility, generic labels, enum reasons, and hashed/signature identifiers; raw prompts, generated recommendation text, block attributes, post content, validation messages, and pattern payloads are not stored.
- Contextual ranking diagnostics may add a bounded `ranking` snapshot for a single suggestion or a `rankingSet` summary for the top shown suggestions. These shapes are limited to numeric component scores, numeric `contextEvidence` and `contextPenalties` maps, a ranking version string, and existing stable suggestion keys or set-local fallbacks such as `suggestion:1`; they must not derive identifiers from labels, descriptions, operation details, block content, pattern payloads, or raw prompt text.
- Those rows are stored in the same activity table and can travel through the same global `GET /flavor-agent/v1/activity` admin/debug/evaluation feed when diagnostics are included, but they do not participate in the executable ordered-undo lifecycle.
- The admin page resolves request diagnostics into `review` or `failed` buckets based on the recorded execution result and persisted undo payload. Outcome diagnostics stay `diagnostic`/`not_applicable`, while inline executable surfaces continue to care only about `available`, runtime `blocked`, `undone`, and `failed`.

## Retry and Merge Behavior

When a `POST /activity` create request arrives for an entry that already exists (duplicate `activity_id`), the server merges rather than rejecting:

- If the existing entry is `available` and the incoming entry is `failed` or `undone`, the server updates the existing row with the incoming undo state
- This handles the case where the client's original create response was lost but a local undo was already applied

Undo sync retries follow a similar reconciliation model:

- transient failures keep the local entry pending with `persistence.syncType = "undo"`
- `409 flavor_agent_activity_undo_blocked` is treated as an authoritative non-retryable failure
- `409 flavor_agent_activity_invalid_undo_transition` triggers a server refresh so the client can adopt the already-persisted `undone` or `failed` state instead of inventing a new local failure

## Scope Hydration Retry

`loadActivitySession()` also supports an explicit `scope` option for refresh-safe editor restores. If the current editor selectors cannot resolve a scope key yet (i.e. `getScopeKey()` returns falsy) and `retryIfScopeUnavailable` is not `false`, the client:

1. syncs the locally persisted session immediately so cached activity entries are visible
2. schedules a one-shot delayed reload (150 ms) that conditionally re-passes the original scope only if `getScopeKey(scope)` resolves on the retry pass, otherwise omits it so the retry falls through to implicit scope resolution
3. sets `retryIfScopeUnavailable: false` on the retry pass to prevent further retries

This keeps recent AI activity visible across Site Editor refreshes without bypassing the normal server merge once the scope becomes available.

## Pruning

Activity entries are automatically pruned by a daily cron event (`flavor_agent_prune_activity`):

- Default retention: **90 days** (`flavor_agent_activity_retention_days` option)
- Entries with `created_at` older than the retention window are deleted
- Pruning runs via `wp_schedule_event` with the `daily` recurrence

## Storage

| Constant | Value |
|---|---|
| Table suffix | `flavor_agent_activity` |
| Default query limit | 20 |
| Maximum query limit | 100 |

Schema version 3 also projects filterable admin-audit metadata into dedicated columns so privileged global reads can filter by provenance without decoding every `request_json` blob. The projected fields cover post type/entity identifiers, block path, operation metadata, provider/backend/model labels, provider path, configuration owner, credential source, selected provider, ability, route, prompt, and reference. The admin page still hydrates the current page from the full stored rows so the details view can show the complete request and before/after payloads.

## Primary Source Files

- `inc/Activity/Repository.php`
- `inc/Activity/Permissions.php`
- `inc/Activity/Serializer.php`
- `src/store/index.js` (`undoActivity`, `loadActivitySession`, `createActivityEntry`)
- `src/store/activity-history.js`
