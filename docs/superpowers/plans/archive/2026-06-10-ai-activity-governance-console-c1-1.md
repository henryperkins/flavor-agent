# AI Activity Governance Console (C1.1) Implementation Plan

> Status: Archived 2026-06-10. Implemented same-day as the AI Activity governance-console slice (Tasks 1–6 landed together with their Jest and WP70 browser coverage); retained only as historical execution context. Verification evidence lives in `STATUS.md`.

> Status when written: Proposed 2026-06-10. This is the focused follow-up to governed external applies (C1). Do not implement broader learning reports, template/block external applies, or notification workflows from this plan.

**Goal:** Make `Settings > AI Activity` feel like the human governance console for AI-mediated WordPress changes, not just a recent-activity audit feed. C1 proved the external-agent loop; C1.1 should make the review evidence, decision path, failure/drift state, and post-decision provenance obvious enough for operators to trust and demo.

**Thesis fit:** AI proposes; WordPress approves. The admin surface should show what the agent requested, why the operation is bounded, who decided, what changed, and whether reversal is still safe.

**Current baseline:** C1 shipped feature-gated external style applies (`request-style-apply`, `get-activity`, `list-activity`, `undo-activity`), the admin decision route, pending/rejected/expired/failed lifecycle states, and a targeted WP70 browser spec proving pending visibility, reject-with-note, and approve-on-drift fail-closed behavior.

**Non-goals:**

- No new abilities, REST routes, database tables, or executor surfaces.
- No template, template-part, block, pattern, content, or navigation external apply support.
- No editor-side pending-apply live visibility.
- No approval notifications or cross-device workflow.
- No aggregate learning reports yet; keep this slice focused on per-row governance review and audit.

## Product Scope

C1.1 should improve four operator jobs:

1. **Triage:** quickly find pending external applies and understand which ones need attention.
2. **Review:** inspect proposed operations, target, request provenance, freshness evidence, and risk/failure reasons without reading raw JSON.
3. **Decide:** approve or reject with confidence, and see the decision result immediately.
4. **Audit/reverse:** after approval, understand who requested/approved/executed the change and whether undo is available, blocked, failed, or already done.

## Implementation Tasks

### Task 1: Governance Detail Model

**Files likely involved:**

- `src/admin/activity-log-utils.js`
- `src/admin/__tests__/activity-log-utils.test.js`

Add a single normalized detail helper for style external-apply rows, such as `getGovernanceDetails( entry )`, that derives UI-ready fields from existing activity shapes.

It should cover:

- lifecycle status: `pending`, `available`, `rejected`, `expired`, `failed`, `undone`, `blocked`
- request provenance: requester, requested time, expiry, request reference, activity id
- decision provenance: approver, decision time, note, executed time
- target: Global Styles vs Style Book, global styles id, block name/title when present
- operation summaries: proposed operations from `request.apply.operations` before execution, executed operations from `after.operations` after execution
- freshness evidence: signature presence, baseline hash presence, stale/failure code, stale/failure message
- undo evidence: undo status, canUndo, ordered-undo blocked, drift/failure copy

Keep raw hashes available only as compact/copyable diagnostic text; do not make hashes the primary UI.

**Acceptance:** unit tests cover pending, rejected, approval-time failed, executed available, undone, and blocked style rows.

### Task 2: Review Evidence Section

**Files likely involved:**

- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`
- `src/admin/__tests__/activity-log.test.js`
- `src/admin/activity-log.css`

Replace the current pending-only decision block with a reusable governance evidence section for style external applies.

It should show, in this order:

1. lifecycle banner: pending approval, rejected, expired, apply failed, applied/undo available, undo blocked, undone
2. requested operation summaries using existing style presentation helpers where possible
3. target and scope summary
4. request and decision provenance
5. freshness/drift/failure explanation when present
6. raw diagnostic disclosure for activity id, request reference, and signatures

The section should render for pending rows and remain useful after the row transitions to rejected, failed, available, undone, or blocked.

**Acceptance:** admin app tests assert the evidence section for pending, rejected-with-note, failed-with-stale-reason, and executed rows.

### Task 3: Safer Decision Interaction

**Files likely involved:**

- `src/admin/activity-log.js`
- `src/admin/__tests__/activity-log.test.js`

Harden the approve/reject interaction without changing the REST contract.

Required behavior:

- Approve and Reject buttons remain visible only when `canApproveStyleApplies` and the row is pending.
- Decision note stays optional, but entered text is preserved while a request is in flight.
- Double-clicks or repeated submits are disabled while the decision request is pending.
- Success keeps the selected row open and refreshes it in place.
- Failure renders the server error next to the decision controls and does not clear the note.

**Acceptance:** tests cover disabled submit while pending, reject success refresh, approve failure message, and note preservation on failure.

### Task 4: Discovery And Filters

**Files likely involved:**

- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`
- `src/admin/__tests__/activity-log.test.js`

Make pending external applies easy to find without turning the screen into a new app.

Required behavior:

- Keep the existing activity feed model.
- Add or refine an Approvals quick filter that maps to `status=pending`.
- Make pending rows visually distinct in the feed with a short status label and expiry hint.
- Preserve existing status filters and summary counts.
- Do not hide request diagnostics or older apply rows when the user is not in the Approvals filter.

**Acceptance:** tests assert the quick filter request params, pending row feed treatment, and unchanged all-activity feed behavior.

### Task 5: Visual Before/Proposed/After For Style Rows

**Files likely involved:**

- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`
- `src/admin/__tests__/activity-log-utils.test.js`
- `src/admin/__tests__/activity-log.test.js`

Add a style-only comparison view that is constrained to the existing operation vocabulary.

Required behavior:

- Pending rows show current baseline/proposed values when data is available, otherwise show proposed operations with an explicit "baseline unavailable" note.
- Executed rows show before/after values derived from snapshots and operations.
- Failed/rejected/expired rows never imply a mutation happened.
- Unsupported or unknown operation shapes fall back to a safe raw summary, not an empty diff.

Keep this narrow: do not introduce a generic visual diff framework in C1.1.

**Acceptance:** utility tests cover `set_styles`, `set_block_styles`, `set_theme_variation`, unknown operations, empty snapshots, and Style Book branch snapshots.

### Task 6: Browser Proof

**Files likely involved:**

- `tests/e2e/flavor-agent.approvals.spec.js`

Extend the existing WP70 MySQL approval spec only where it proves governance-console behavior.

Required assertions:

- pending row exposes operation evidence and request provenance before decision
- reject keeps decision provenance visible after refresh
- approve-on-drift shows the fail-closed explanation in the selected row

Do not seed a fake successful approval row in browser unless it uses real server signatures; PHPUnit already covers the successful executor path.

**Verification:**

- `npm run build`
- `npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js`
- `npm run test:e2e:wp70 -- tests/e2e/flavor-agent.approvals.spec.js`
- `npm run check:docs` if public docs or guarded strings change

## Stop Lines

- Stop if implementing this requires changing ability schemas or the activity table shape; write a new plan instead.
- Stop if the UI needs live editor synchronization for pending applies; that is C2.
- Stop if a generic diff viewer becomes necessary; narrow the display to style operation summaries and snapshots.
- Stop if template, template-part, or block external applies enter scope.

## Follow-Up After C1.1

If this ships cleanly, the next planning decision should be one of:

1. editor-side pending external-apply visibility
2. approval notifications
3. learning attribution join contract
4. template/template-part external apply executors, only with a separate bounded apply/undo/drift design
