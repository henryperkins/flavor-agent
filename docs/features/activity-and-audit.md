# Activity And Audit

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surfaces

- Inline editor activity: `Recent AI Actions` inside the block, template, and template-part recommendation panels
- Inline success notices: immediate post-apply and post-undo feedback in those same panels
- Admin audit surface: `Settings > AI Activity`

Navigation recommendations and pattern recommendations do not currently create Flavor Agent activity entries because they do not run Flavor Agent-owned apply flows.

## Surfacing Conditions

- Inline activity appears only when the current editor scope has recorded block, template, or template-part AI actions
- The admin audit page appears only for users with `manage_options`
- Sitewide activity queries are admin-only; scoped activity access follows contextual capability checks through `FlavorAgent\Activity\Permissions`

## End-To-End Flow

1. A deterministic apply flow succeeds in the block, template, or template-part surface
2. The store builds a structured activity entry and persists it through the server-backed activity repository
3. `ActivitySessionBootstrap()` resolves the current editor scope and calls `loadActivitySession()` whenever the edited entity changes
4. The store hydrates the current scope from server entries, merges pending local entries, and keeps `sessionStorage` as a cache/fallback for the active surface
5. `AIActivitySection` renders the newest entries for the current scope and shows inline undo when the entry is still valid and tail-undoable
6. `undoActivity()` validates the live editor state, performs the local undo, and persists the undo-status transition to `POST /flavor-agent/v1/activity/{id}/undo`
7. The admin page bootstraps `src/admin/activity-log.js`, queries recent server-backed entries, and renders them through `DataViews` and a read-only `DataForm`

## What This Surface Can Do

- Persist block, template, and template-part apply events to a shared server-backed activity store
- Hydrate activity back into editor-scoped history when the current entity changes
- Show ordered undo state, including applied, available, undone, blocked, failed, and pending-sync states
- Let the user undo the newest valid tail action directly from the editor panel
- Let admins inspect recent server-backed AI activity across surfaces from wp-admin

## Ordered Undo Rules

- Undo is tail-ordered: older entries are blocked while newer still-applied AI actions remain
- Block undo is path-plus-attribute based
- Template and template-part undo rely on stable locators plus persisted post-apply snapshots
- If the live editor state no longer matches the recorded post-apply state, the entry becomes blocked or unavailable instead of forcing an unsafe undo

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| Scope bootstrap | `ActivitySessionBootstrap()` in `src/components/ActivitySessionBootstrap.js` | Re-hydrates activity whenever the edited entity changes |
| Inline UI | `AIActivitySection` in `src/components/AIActivitySection.js` | Renders recent entries and inline undo buttons |
| Store hydration | `loadActivitySession()` in `src/store/index.js` | Merges local and server-backed activity for the active scope |
| Store undo | `undoActivity()` in `src/store/index.js` | Runs safe undo and persists the undo-status transition |
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
- `src/store/activity-history.js`
- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`
- `inc/Admin/ActivityPage.php`
- `inc/Activity/Permissions.php`
- `inc/Activity/Repository.php`
- `inc/REST/Agent_Controller.php`
