# Activity And Audit

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surfaces

- Inline editor activity: `Recent AI Actions` inside the block, template, template-part, Global Styles, and Style Book recommendation panels, plus read-only `Recent Content Requests` inside the content panel
- Inline success/error notices: shared status notices for immediate post-apply, post-undo, and request/apply/undo failures in those same panels
- Admin audit surface: `Settings > AI Activity`

Navigation, pattern, and the content recommendation panel do not create executable apply-and-undo entries because they do not run Flavor Agent-owned apply flows. The server does, however, persist scoped read-only `request_diagnostic` rows for successful and failed recommendation fetches across content, pattern, navigation, block, template, template-part, Global Styles, and Style Book when a document scope is available. On executable surfaces, those request rows are separate from any later apply-and-undo activity row. Content request diagnostics also appear inline as `Recent Content Requests`; the other diagnostics appear in the wp-admin audit page.

## Surfacing Conditions

- Inline activity appears only when the current editor scope has recorded block, template, template-part, Global Styles, or Style Book AI actions, or read-only content request diagnostics
- The admin audit page appears only for users with `manage_options`
- Sitewide activity queries are admin-only; scoped activity access follows contextual capability checks through `FlavorAgent\Activity\Permissions`

## End-To-End Flow

1. A deterministic apply flow succeeds in the block, template, template-part, Global Styles, or Style Book surface, or a scoped content request succeeds or fails
2. The store builds a structured activity entry and persists it through the server-backed activity repository
   The persisted request payload now carries AI execution provenance including provider/backend label, model, configuration owner, credential source, route/ability, and fallback path when the selected provider resolves to a different runtime backend. The repository also projects filterable admin columns from that payload so wp-admin queries do not need to decode every historical `request_json` blob to filter by provider or route metadata.
3. `ActivitySessionBootstrap()` resolves the current editor scope and calls `loadActivitySession()` whenever the edited entity changes
4. The store hydrates the current scope from server entries, merges pending local entries, and keeps `sessionStorage` as a cache/fallback for the active surface
5. `AIActivitySection` renders the newest entries for the current scope, shows compact execution summaries, exposes a collapsed `Execution details` section for provider/path/ability/route/prompt/reference metrics, and exposes inline undo when the entry is still valid and tail-undoable. Content uses the same component for read-only request history only.
6. `undoActivity()` validates the live editor state, performs the local undo, and persists the undo-status transition to `POST /flavor-agent/v1/activity/{id}/undo`
7. The admin page bootstraps `src/admin/activity-log.js`, queries recent server-backed entries, and renders them through `DataViews` and a read-only `DataForm` that now exposes provider path, configuration owner, credential source, selected-provider fallback notes, explicit undo reasons, and separate review-only / blocked / failed summary buckets alongside the existing before/after summaries and quick links

## What This Surface Can Do

- Persist block, template, template-part, Global Styles, and Style Book apply events to a shared server-backed activity store
- Persist read-only `request_diagnostic` audit rows for scoped recommendation fetches across all recommendation surfaces without pretending advisory-only surfaces now support executable apply/undo; content shows those rows inline as `Recent Content Requests`
- Hydrate activity back into editor-scoped history when the current entity changes
- Preserve machine-readable AI request provenance so audit views can explain which backend actually handled a recommendation and where that configuration lives
- Show ordered undo state: undo status values are `available`, `undone`, `blocked`, and `failed`; persistence sync states (`server`/`local` with `syncType` of `undo` or `create`) are tracked separately
- Let the user undo the newest valid tail action directly from the editor panel
- Let admins inspect recent server-backed AI activity across surfaces from wp-admin, including provider ownership, credential-source, ability/route, review-only request diagnostics, and undo-reason details
- Filter audit entries by absolute or relative time without silently broadening malformed date filters; malformed active filters are blocked in the UI or rejected by REST, and `inThePast` and `over` use true timestamp windows, including hour-based filters that cross midnight correctly
- Keep the executable surfaces aligned on one learned-once status model even though block supports inline apply and template/template-part require preview first

This is still the first audit surface, not the final observability product. It does not yet provide diff-oriented inspection, richer operator workflows, row actions/discovery, or a broader diagnostics console.

## Ordered Undo Rules

- Undo is tail-ordered: older entries are blocked while newer still-applied AI actions remain
- Server-side undo transitions are one-way from `available` only; terminal rows cannot be rewritten to a different terminal state
- Block undo is path-plus-attribute based
- Template and template-part undo rely on stable locators plus persisted post-apply snapshots
- Global Styles and Style Book undo both rely on the active `root/globalStyles` entity id plus the persisted post-apply user config snapshot; Style Book also validates the recorded target block before restoring styles
- If the live editor state no longer matches the recorded post-apply state, the entry becomes blocked or unavailable instead of forcing an unsafe undo

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| Scope bootstrap | `ActivitySessionBootstrap()` in `src/components/ActivitySessionBootstrap.js` | Re-hydrates activity whenever the edited entity changes |
| Inline UI | `AIActivitySection` in `src/components/AIActivitySection.js` | Renders recent entries and inline undo buttons |
| Store hydration | `loadActivitySession()` in `src/store/index.js` | Merges local and server-backed activity for the active scope |
| Store undo | `undoActivity()` in `src/store/index.js` | Runs safe undo and persists the undo-status transition |
| Global Styles undo helpers | `getGlobalStylesActivityUndoState()` and `undoGlobalStyleSuggestionOperations()` in `src/utils/style-operations.js` | Validate and restore the current Global Styles entity |
| Admin page registration | `ActivityPage` in `inc/Admin/ActivityPage.php` | Registers `Settings > AI Activity` and localizes admin boot data |
| Admin UI | `src/admin/activity-log.js` | Renders the DataViews feed, summary cards, and read-only detail form |
| REST handlers | `Agent_Controller::handle_get_activity()`, `handle_create_activity()`, `handle_update_activity_undo()` | Serve activity query, persistence, and undo-status updates |
| Permissions | `FlavorAgent\Activity\Permissions` | Applies contextual capability rules for scoped and global activity access |

## Related Routes And Abilities

- REST: `GET /flavor-agent/v1/activity`
- REST: `POST /flavor-agent/v1/activity`
- REST: `POST /flavor-agent/v1/activity/{id}/undo`
- There is no dedicated Abilities API surface for activity yet

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
- `inc/Activity/Permissions.php`
- `inc/Activity/Repository.php`
- `inc/Activity/Serializer.php`
- `inc/REST/Agent_Controller.php`
