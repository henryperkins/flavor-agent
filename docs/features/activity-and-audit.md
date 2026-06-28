# Activity And Audit

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surfaces

- Inline editor activity: collapsed `Recent AI Actions` inside the block, template, and template-part recommendation panels; `Recent AI Style Actions` inside Global Styles; `Recent AI Style Book Actions` inside Style Book; and read-only `Recent Content Requests` inside the content panel only while the post/page content surface is supported and configured. Block request diagnostics can also appear inline in the block activity section when a request fails or returns no block-lane suggestions. Template and template-part sidebars show executable apply history only; their request diagnostics remain available through the admin approval/audit/attestation-discovery page.
- Inline success/error notices: shared status notices for immediate post-apply, post-undo, and request/apply/undo failures in those same panels
- Admin approval/audit/attestation-discovery surface: `Settings > AI Activity`

Navigation, pattern, and content recommendations do not create executable apply-and-undo entries because they do not run Flavor Agent-owned apply flows. Scoped recommendation requests and recommendation outcomes can still persist diagnostic audit rows when a document scope is available; inline hydration is intentionally limited to the surfaces that expose a matching current-scope panel. Exact diagnostic row shapes and route contracts are canonical in `docs/reference/abilities-and-routes.md`.

External-agent applies are intentionally narrower than the editor-owned apply map. In `0.1.0`, external agents can request only Global Styles / Style Book applies through `request-style-apply`; administrators approve or reject those pending rows in `Settings > AI Activity`; approval executes server-side after freshness and operation revalidation. Template, template-part, block, content, navigation, and pattern external applies are not exposed.

## Surfacing Conditions

- Inline activity appears only when the current editor scope has recorded executable block, template, template-part, Global Styles, or Style Book AI actions, or read-only diagnostics that match the current inline panel filters for content, block, Global Styles, or Style Book requests
- The admin approval/audit/attestation-discovery page appears only for users with `manage_options`
- Sitewide activity queries are admin-only; scoped activity access follows contextual capability checks through `FlavorAgent\Activity\Permissions`

## End-To-End Flow

1. A deterministic apply flow succeeds in the block, template, template-part, Global Styles, or Style Book surface, or a scoped recommendation request succeeds, fails, or returns diagnostic-only output
2. The store builds a structured activity entry and persists it through the server-backed activity repository
   The persisted payload carries AI execution provenance and projected admin columns; exact fields live in `docs/reference/abilities-and-routes.md#activity-entry-shape`.
3. `ActivitySessionBootstrap()` resolves the current editor scope and calls `loadActivitySession()` whenever the edited entity changes
4. The store hydrates the current scope from server entries, merges pending local entries, and keeps `sessionStorage` as a cache/fallback for the active surface
5. `AIActivitySection` renders newest matching entries for the current scope and exposes inline undo only when an executable entry is still valid and tail-undoable
6. `undoActivity()` validates the live editor state, performs the local undo, and persists the undo-status transition to `POST /flavor-agent/v1/activity/{id}/undo`
7. The admin page bootstraps `src/admin/activity-log.js`, queries recent server-backed entries, and renders them through `DataViews` plus custom detail sections
8. For a pending external style apply, an administrator approves or rejects through `POST /flavor-agent/v1/activity/{id}/decision`; approval revalidates and executes the style operation on the server, while rejection records the decision without mutating the site
9. For an attested external style apply, the detail view exposes the attestation id, a site-run verification summary, raw signed-statement and subject-state URLs, and any revert/supersede chain context so a reviewer can move from wp-admin audit evidence to external verification without confusing endpoint loading for cryptographic verification

## What This Surface Can Do

- Persist block, template, template-part, Global Styles, and Style Book apply events to a shared server-backed activity store
- Persist read-only `request_diagnostic` and `recommendation_outcome` audit rows without turning advisory-only surfaces into executable apply/undo surfaces
- Hydrate activity back into editor-scoped history when the current entity changes
- Preserve machine-readable AI request provenance so audit views can explain which backend actually handled a recommendation and where that configuration lives
- Show ordered undo state using the canonical state machine in `docs/reference/activity-state-machine.md`
- Let the user undo the newest valid tail action directly from the editor panel
- Let admins inspect recent server-backed AI activity across surfaces from wp-admin, including provenance, diagnostics, undo-reason details, and structured state snapshots
- Let admins inspect style-governance rows through a first rich visual diff layer with lifecycle-honest proposed/applied/undone/blocked states, swatches or chips where the stored payload supports them, and raw state snapshots as fallback evidence
- Let admins move from a selected row to the honest target, a focused `Settings > AI Activity` permalink, or closely related feed pivots without inventing a second admin route contract
- Let admins approve or reject pending external Global Styles / Style Book applies from wp-admin; approval is the only external-agent apply gate and it executes server-side
- Show advisory "being reviewed by X" claims on pending approvals so concurrent admins can coordinate, without the claim ever gating a decision; if another admin decides a row first, it resolves to its terminal approved/rejected state rather than surfacing a generic error
- Let admins discover Ring III governed-change attestations for eligible external style applies, including a site-run verification summary plus public envelope and subject-state links without making attestation a general AI-governance claim
- Surface passive feed badges for pending approval, AI request-log availability, and attestation evidence before a row is opened
- Let global admin activity reads request and render a bounded, sanitized governance learning report with outcome rates and aggregate groups by surface, operation type, validation reason, ranking signal, guideline version, and provider/model.
- Filter audit entries by absolute or relative time without silently broadening malformed date filters; malformed active filters are blocked in the UI or rejected by REST, and `inThePast` and `over` use true timestamp windows, including hour-based filters that cross midnight correctly
- Keep the executable surfaces aligned on one learned-once status model even though block supports inline apply and template/template-part require preview first

This is still the first governance-console slice, not the final observability product. It includes external style-apply decisions, attestation discovery, structured diff and before/after summaries, a rendered backend/API aggregate report contract, the first selected-row action/discovery layer (focused-row banner, honest target/focused-view links, related-row pivots, passive evidence badges), and a first rich visual diff layer for style-governance rows. Broader cross-operator workflows and deeper observability remain open.

## Ordered Undo Rules

Undo is tail-ordered and state-validated before a stored action can be reverted. The canonical state model, terminal transitions, per-surface undo inputs, and blocked/unavailable projections live in `docs/reference/activity-state-machine.md`.

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| Scope bootstrap | `ActivitySessionBootstrap()` in `src/components/ActivitySessionBootstrap.js` | Re-hydrates activity whenever the edited entity changes |
| Inline UI | `AIActivitySection` in `src/components/AIActivitySection.js` | Renders recent entries and inline undo buttons |
| Store hydration | `loadActivitySession()` in `src/store/index.js` | Merges local and server-backed activity for the active scope |
| Store undo | `undoActivity()` (implemented in `src/store/activity-undo.js`, exposed via the store) | Runs safe undo and persists the undo-status transition |
| Global Styles undo helpers | `getGlobalStylesActivityUndoState()` and `undoGlobalStyleSuggestionOperations()` in `src/utils/style-operations.js` | Validate and restore the current Global Styles entity |
| Admin page registration | `ActivityPage` in `inc/Admin/ActivityPage.php` | Registers `Settings > AI Activity` and localizes admin approval/audit/attestation-discovery boot data |
| Admin UI | `src/admin/activity-log.js` | Renders the DataViews feed, linked-row banner, selected-row action strip, passive discovery badges, bounded learning-report aggregates, external-apply decision controls, attestation affordances, and custom detail sections |
| REST handlers | `Agent_Controller::handle_get_activity()`, `handle_create_activity()`, `handle_update_activity_undo()`, `handle_activity_decision()`, `handle_activity_claim()`, `handle_activity_claim_release()`; `AttestationController` | Serve activity query, persistence, undo-status updates, admin approval/rejection decisions, advisory review-claim coordination, and public attestation verification reads |
| Permissions | `FlavorAgent\Activity\Permissions` | Applies contextual capability rules for scoped and global activity access |
| Learning report | `FlavorAgent\Activity\GovernanceLearningReport` | Builds the optional bounded `learningReport` payload rendered for global admin activity reads |

## Related Routes And Abilities

- REST: `GET /flavor-agent/v1/activity`
- REST: `POST /flavor-agent/v1/activity`
- REST: `POST /flavor-agent/v1/activity/{id}/undo`
- REST: `POST /flavor-agent/v1/activity/{id}/decision`
- REST: `POST /flavor-agent/v1/activity/{id}/claim`
- REST: `DELETE /flavor-agent/v1/activity/{id}/claim`
- REST: `GET /flavor-agent/v1/attestations/{id}`
- REST: `GET /flavor-agent/v1/attestations/{id}/verification`
- REST: `GET /flavor-agent/v1/attestations/{id}/subject-state`
- REST: `GET /flavor-agent/v1/attestations/keys`
- Abilities: `flavor-agent/request-style-apply`, `flavor-agent/get-activity`, `flavor-agent/list-activity`, `flavor-agent/undo-activity`

## Key Implementation Files

- `src/components/ActivitySessionBootstrap.js`
- `src/components/AIActivitySection.js`
- `src/store/index.js`
- `src/store/activity-history.js` — session cache/fallback layer, scope resolution, and undo-state resolution; see `docs/reference/shared-internals.md`
- `src/store/update-helpers.js` — attribute snapshot comparison for block undo validation; see `docs/reference/shared-internals.md`
- `src/store/block-targeting.js` — resolves activity targets by clientId or blockPath; see `docs/reference/shared-internals.md`
- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`
- `inc/Admin/ActivityPage.php`
- `inc/REST/AttestationController.php`
- `inc/Activity/Permissions.php`
- `inc/Activity/Repository.php`
- `inc/Activity/Serializer.php`
- `inc/REST/Agent_Controller.php`
