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
| [Block recommendations](features/block-recommendations.md) | Native block Inspector `AI Recommendations` panel; fallback document panel keeps the last selected block available after selection clears | A block is selected or remembered, and that block is not in `disabled` editing mode | Hidden for disabled blocks; fetch is disabled when `flavorAgentData.canRecommendBlocks` is false but the panel now explains both valid setup paths (`Settings > Flavor Agent` direct chat or `Settings > Connectors` via the WordPress AI Client); content-only blocks stay visible but are restricted | Follow the shared scope/freshness -> prompt -> featured recommendation -> grouped lanes -> undo/history model, with explicit inline-apply rationale for safe local block updates; block recommendations are also the one surface that now keeps stale results visible behind an explicit refresh action; style rows and delegated native sub-panel chips keep feedback beside the clicked control; selected `core/navigation` blocks also expose navigation guidance | Inline apply for safe local attribute changes; latest valid activity tail can be undone |
| [Pattern recommendations](features/pattern-recommendations.md) | Native inserter `Recommended` category plus inserter-toggle badge | Post type is known and pattern backends are configured; passive fetch runs on editor load and active fetch runs from inserter search | When pattern backends are unavailable, opening the inserter now shows a shared setup notice with `Settings > Flavor Agent` embedding/Qdrant guidance and `Settings > Connectors` chat guidance when available; no recommendations when the allowed pattern set is empty or the pattern index is unavailable; badge stays hidden if no anchor exists or there is no visible state to show | Browse ranked patterns, see enriched descriptions and badge state, or learn which backend or setup path is missing before trying again; the patched category slug follows the shared `wp_block` entity contract and falls back to `recommended` when WordPress does not expose live view metadata | No direct Flavor Agent apply or undo; the user inserts the pattern through core UI |
| [Navigation recommendations](features/navigation-recommendations.md) | Nested section inside the block `AI Recommendations` panel for selected `core/navigation` blocks | The selected block is `core/navigation`; fetch additionally requires `flavorAgentData.canRecommendNavigation` and a menu ID or serialized markup | Hidden for every other block; when unavailable the section now stays visible with a settings or capability notice; advisory-only through v1.0 | Follow the same prompt -> featured recommendation -> grouped manual lanes framing as the executable surfaces, but stop at advisory review only and keep request state separate from block recommendations | No apply contract; no activity entry |
| [Template recommendations](features/template-recommendations.md) | Site Editor `PluginDocumentSettingPanel` named `AI Template Recommendations` | The current edited entity is `wp_template`; fetching additionally requires `flavorAgentData.canRecommendTemplates` | Hidden outside template editing; when unavailable the panel now stays visible with the shared setup/capability notice, including `Settings > Flavor Agent` and `Settings > Connectors` links when available; non-deterministic ideas stay advisory-only and recommendations clear when template context changes | Follow the shared prompt -> featured recommendation -> grouped `Review first` / `Manual ideas` lanes -> review -> apply -> undo/history model, with clickable entity links and explicit preview-confirm-apply for validated operations, including bounded pattern inserts that must declare explicit placement | Deterministic apply only after preview; latest valid activity tail can be undone |
| [Template-part recommendations](features/template-part-recommendations.md) | Site Editor `PluginDocumentSettingPanel` named `AI Template Part Recommendations` | The current edited entity is `wp_template_part`; fetching additionally requires `flavorAgentData.canRecommendTemplateParts` | Hidden outside template-part editing; when unavailable the panel now stays visible with the shared setup/capability notice, including `Settings > Flavor Agent` and `Settings > Connectors` links when available; unsupported or ambiguous suggestions stay advisory-only | Follow the shared prompt -> featured recommendation -> grouped `Review first` / `Manual ideas` lanes -> review -> apply -> undo/history model, with focus-block links, pattern browse links, and advisory fallback cards | Deterministic apply only after preview; latest valid activity tail can be undone |
| [Style and theme intelligence](features/style-and-theme-intelligence.md) | Site Editor Styles sidebar panel for Global Styles and Style Book; falls back to `PluginDocumentSettingPanel` named `AI Style Suggestions` if the native sidebar slot is unavailable | The active Site Editor Styles sidebar resolves a current `root/globalStyles` entity; Global Styles uses `flavorAgentData.canRecommendGlobalStyles`, Style Book uses `flavorAgentData.canRecommendStyleBook`, and Style Book additionally requires an active Style Book target block | Hidden outside the Site Editor Styles sidebar; when unavailable the panel stays visible with a plugin-settings or capability notice; unsupported or non-`theme.json` ideas stay advisory-only | Follow the shared scope/freshness -> prompt -> featured recommendation -> grouped `Review first` / `Manual ideas` lanes -> review -> apply -> undo/history model for validated site-level Global Styles changes, validated Style Book block changes, and Global Styles-only theme style variations | Deterministic apply only after review; latest valid style-surface tail entry can be undone while the live config still matches the recorded post-apply state |
| [AI activity and undo](features/activity-and-audit.md) | `Recent AI Actions` group inside block, template, template-part, Global Styles, and Style Book recommendation panels | The current editor scope has recorded AI actions | Hidden when no actions exist for the current scope; older entries are blocked while newer still-applied AI actions remain | Review recent actions, see the shared status/undo model, and undo the newest valid tail entry for block, template, template-part, Global Styles, or Style Book surfaces | Undo is inline and editor-scoped |
| [Admin AI Activity](features/activity-and-audit.md) | `Settings > AI Activity` | Current user has `manage_options` | Hidden for non-admin users; read-only today | Browse the recent server-backed activity feed, use filters and saved views, inspect DataForm details, jump back to affected entities and settings | Audit only; no apply or row-action undo |
| [Settings and pattern sync](features/settings-backends-and-sync.md) | `Settings > Flavor Agent` | Current user has `manage_options` | Hidden for non-admin users; validation errors keep prior saved credentials in place | Configure provider/backends, inspect credential-source diagnostics, review sync state, manually run pattern sync | Settings save and sync only; no undo |

## Programmatic Surfaces

| Surface | Where it lives | Gating model | What it provides | Reference |
|---|---|---|---|---|
| WordPress Abilities API | Server-registered abilities under the `flavor-agent` category; hydrated by core on supported WordPress 7.0+ admin screens | Each ability is gated by capability and, where relevant, backend availability | Thirteen structured abilities for recommendations, content drafting/critique, listings, docs grounding, theme tokens, style intelligence, and backend status | `docs/reference/abilities-and-routes.md` |
| Flavor Agent REST API | Routes under `flavor-agent/v1` consumed by the `flavor-agent` data store and admin scripts | Per-route capability callbacks plus route-specific validation and sanitization | First-party request/response path for recommendations, server-backed activity, and manual pattern sync | `docs/reference/abilities-and-routes.md` |

## Quick Mapping

- Block Inspector -> `POST /flavor-agent/v1/recommend-block` -> `flavor-agent/recommend-block`
- Programmatic content lane -> `POST /flavor-agent/v1/recommend-content` -> `flavor-agent/recommend-content`
- Pattern Inserter -> `POST /flavor-agent/v1/recommend-patterns` -> `flavor-agent/recommend-patterns`
- Navigation Inspector -> `POST /flavor-agent/v1/recommend-navigation` -> `flavor-agent/recommend-navigation`
- Template panel -> `POST /flavor-agent/v1/recommend-template` -> `flavor-agent/recommend-template`
- Template-part panel -> `POST /flavor-agent/v1/recommend-template-part` -> `flavor-agent/recommend-template-part`
- Global Styles / Style Book panel -> `POST /flavor-agent/v1/recommend-style` -> `flavor-agent/recommend-style`
- Inline and admin activity -> `GET/POST /flavor-agent/v1/activity` and `POST /flavor-agent/v1/activity/{id}/undo` (REST only)
- Settings sync button -> `POST /flavor-agent/v1/sync-patterns` (REST only)

Content recommendations are scaffolded programmatically today. There is no first-party post-editor panel yet.

## Update Rule

When a surface changes, update three places together:

1. this matrix
2. the matching file in `docs/features/`
3. `docs/reference/abilities-and-routes.md` if the contract changed
