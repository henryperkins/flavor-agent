# Flavor Agent Feature Surface Matrix

This is the fastest document for answering four questions:

- Where does a Flavor Agent surface appear?
- When does it show up, stay hidden, or degrade?
- What can the user actually do there?
- Does the surface support deterministic apply and Flavor Agent undo?

Use this together with `docs/features/README.md` for per-surface flows and `docs/reference/abilities-and-routes.md` for exact contracts.

## First-Party Editor And Admin Surfaces

| Surface | Exact UI location | Shows when | Hidden or limited when | What the user can do | Apply / undo |
|---|---|---|---|---|---|
| [Block recommendations](features/block-recommendations.md) | Native block Inspector `AI Recommendations` panel; fallback document panel keeps the last selected block available after selection clears | A block is selected or remembered, and that block is not in `disabled` editing mode | Hidden for disabled blocks; fetch is disabled when `flavorAgentData.canRecommendBlocks` is false; content-only blocks stay visible but are restricted | Enter a prompt, fetch suggestions, apply settings/style/block suggestions, review recent AI actions; selected `core/navigation` blocks also expose navigation guidance | Inline apply; latest valid activity tail can be undone |
| [Pattern recommendations](features/pattern-recommendations.md) | Native inserter `Recommended` category plus inserter-toggle badge | Post type is known and pattern backends are configured; passive fetch runs on editor load and active fetch runs from inserter search | No recommendations when the allowed pattern set is empty or the pattern index is unavailable; badge stays hidden if no anchor exists or there is no visible state to show | Browse ranked patterns, see enriched descriptions and badge state, insert through the native inserter | No direct Flavor Agent apply or undo; the user inserts the pattern through core UI |
| [Navigation recommendations](features/navigation-recommendations.md) | Nested section inside the block `AI Recommendations` panel for selected `core/navigation` blocks | The selected block is `core/navigation`, `flavorAgentData.canRecommendNavigation` is true, and the block can provide a menu ID or serialized markup | Hidden for every other block; advisory-only even when available | Ask for structure, overlay, or accessibility ideas and review categorized changes | No apply contract; no activity entry |
| [Template recommendations](features/template-recommendations.md) | Site Editor `PluginDocumentSettingPanel` named `AI Template Recommendations` | The current edited entity is `wp_template` and `flavorAgentData.canRecommendTemplates` is true | Hidden outside template editing; recommendations clear when template context changes | Prompt for structure/layout changes, follow entity links, preview validated operations, confirm apply, review recent AI actions | Deterministic apply for validated operations; latest valid activity tail can be undone |
| [Template-part recommendations](features/template-part-recommendations.md) | Site Editor `PluginDocumentSettingPanel` named `AI Template Part Recommendations` | The current edited entity is `wp_template_part` and `flavorAgentData.canRecommendTemplateParts` is true | Hidden outside template-part editing; unsupported or ambiguous suggestions stay advisory-only | Prompt for focused composition help, jump to focus blocks, browse patterns, preview validated operations, confirm apply, review recent AI actions | Deterministic apply for validated operations; latest valid activity tail can be undone |
| [AI activity and undo](features/activity-and-audit.md) | `Recent AI Actions` group inside block, template, and template-part recommendation panels | The current editor scope has recorded AI actions | Hidden when no actions exist for the current scope; older entries are blocked while newer still-applied AI actions remain | Review recent actions, see undo state, undo the newest valid tail entry | Undo is inline and editor-scoped |
| [Admin AI Activity](features/activity-and-audit.md) | `Settings > AI Activity` | Current user has `manage_options` | Hidden for non-admin users; read-only today | Browse the recent server-backed activity feed, use filters and saved views, inspect DataForm details, jump back to affected entities and settings | Audit only; no apply or row-action undo |
| [Settings and pattern sync](features/settings-backends-and-sync.md) | `Settings > Flavor Agent` | Current user has `manage_options` | Hidden for non-admin users; validation errors keep prior saved credentials in place | Configure provider/backends, inspect credential-source diagnostics, review sync state, manually run pattern sync | Settings save and sync only; no undo |

## Programmatic Surfaces

| Surface | Where it lives | Gating model | What it provides | Reference |
|---|---|---|---|---|
| WordPress Abilities API | Server-registered abilities under the `flavor-agent` category; hydrated by core on supported WordPress 7.0+ admin screens | Each ability is gated by capability and, where relevant, backend availability | Eleven structured abilities for recommendations, listings, docs grounding, theme tokens, and backend status | `docs/reference/abilities-and-routes.md` |
| Flavor Agent REST API | Routes under `flavor-agent/v1` consumed by the `flavor-agent` data store and admin scripts | Per-route capability callbacks plus route-specific validation and sanitization | First-party request/response path for recommendations, server-backed activity, and manual pattern sync | `docs/reference/abilities-and-routes.md` |

## Quick Mapping

- Block Inspector -> `POST /flavor-agent/v1/recommend-block` -> `flavor-agent/recommend-block`
- Pattern Inserter -> `POST /flavor-agent/v1/recommend-patterns` -> `flavor-agent/recommend-patterns`
- Navigation Inspector -> `POST /flavor-agent/v1/recommend-navigation` -> `flavor-agent/recommend-navigation`
- Template panel -> `POST /flavor-agent/v1/recommend-template` -> `flavor-agent/recommend-template`
- Template-part panel -> `POST /flavor-agent/v1/recommend-template-part` -> `flavor-agent/recommend-template-part`
- Inline and admin activity -> `GET/POST /flavor-agent/v1/activity` and `POST /flavor-agent/v1/activity/{id}/undo` (REST only)
- Settings sync button -> `POST /flavor-agent/v1/sync-patterns` (REST only)

## Update Rule

When a surface changes, update three places together:

1. this matrix
2. the matching file in `docs/features/`
3. `docs/reference/abilities-and-routes.md` if the contract changed
