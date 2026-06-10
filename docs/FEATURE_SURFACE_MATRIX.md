# Flavor Agent Feature Surface Matrix

This is the fast-scan map for first-party UI surfaces. Keep exact flows in `docs/features/` and exact request/response contracts in `docs/reference/abilities-and-routes.md`.

Every executable surface runs the same governed loop — generate, validate, review, apply and record, reverse — and the advisory, editorial, and browse-only surfaces stop earlier on that loop by design. Read the Apply / undo column as the demonstration of the governance layer (`docs/reference/governance-layer.md`): it shows where each surface sits on the loop.

For every chat-backed surface, the WordPress AI plugin Connector Approval experiment can gate the request even when the selected connector is configured. Flavor Agent treats that as a request-time setup notice with connector/caller details, not as an unavailable bootstrap capability.

## First-Party Editor And Admin Surfaces

| Surface | UI location | Gate | User action | Apply / undo | Canonical detail |
| --- | --- | --- | --- | --- | --- |
| Block recommendations | Native block Inspector `AI Recommendations`; fallback document panel for last selected block | Selected editable block; text-generation provider for recommendations | Request grouped settings, style, block, and structural guidance | Settings and Styles support panel-scoped multi-select with recommended-together bundle hints, a single combined undo entry, and post-apply re-baselining so self-applies stay fresh; executable Block-lane and structural Review-lane apply one-at-a-time; reviewed structural apply behind rollout flag; newest valid activity can undo | `docs/features/block-recommendations.md` |
| Pattern recommendations | Native inserter Flavor Agent shelf plus inserter-toggle badge | Post type, Connectors chat, selected Pattern Storage backend, usable pattern index, visible allowed patterns | Browse ranked patterns and insert matched allowed patterns through core insertion | No Flavor Agent undo; direct insert is server-signature revalidated | `docs/features/pattern-recommendations.md` |
| Content recommendations | Post/page document settings panel | Supported post type and text-generation provider | Generate draft, edit, or critique text | Editorial-only; copy-to-clipboard only | `docs/features/content-recommendations.md` |
| Navigation recommendations | Embedded section for selected `core/navigation` blocks inside block recommendations | Selected navigation block, theme capability, text-generation provider, menu ID or serialized markup | Review advisory navigation structure, overlay, and accessibility ideas | No apply or inline undo; scoped request diagnostics only | `docs/features/navigation-recommendations.md` |
| Template recommendations | Site Editor `AI Template Recommendations` panel | Current `wp_template` entity contract and text-generation provider | Preview and apply bounded template operations | Deterministic apply after preview; newest valid activity can undo | `docs/features/template-recommendations.md` |
| Template-part recommendations | Site Editor `AI Template Part Recommendations` panel | Current `wp_template_part` entity contract and text-generation provider | Preview bounded template-part operations, focus blocks, and browse patterns | Deterministic apply after preview; newest valid activity can undo | `docs/features/template-part-recommendations.md` |
| Style and theme intelligence | Site Editor Styles sidebar for Global Styles and Style Book | Native Styles sidebar scope, theme capability, text-generation provider, valid Style Book target when needed | Review `theme.json`-safe Global Styles and Style Book changes | Deterministic apply after review; newest valid style-surface activity can undo. External agents: external apply via `request-style-apply` → admin approval → server execute; server-side undo via `undo-activity` | `docs/features/style-and-theme-intelligence.md` |
| AI activity and undo | Inline recent activity groups for block, template, template-part, Global Styles, and Style Book plus `Settings > AI Activity` | Scoped entries for inline panels; `manage_options` for admin audit and external-apply decisions | Review activity, request diagnostics, provenance, pending external style applies, and undo state | Inline undo for executable rows; admins can approve/reject pending external style applies, while non-pending audit rows remain inspection-only | `docs/features/activity-and-audit.md` |
| Settings and pattern sync | `Settings > Flavor Agent` | `manage_options` | Configure plugin-owned embedding/storage settings, view diagnostics, run pattern sync | Settings save and sync only | `docs/features/settings-backends-and-sync.md` |

Content request diagnostics render inline only when the supported post/page content panel is mounted in its configured state; otherwise scoped request diagnostics remain available through the admin audit feed.

## Programmatic Surfaces

| Surface | Where it lives | What it provides | Canonical detail |
| --- | --- | --- | --- |
| WordPress Abilities API | Server-registered abilities under the `flavor-agent` category | Recommendation, content, helper, diagnostics, docs-grounding, theme, backend status, and feature-gated external-apply (`request-style-apply`, `get-activity`, `list-activity`, `undo-activity`) contracts | `docs/reference/abilities-and-routes.md` |
| Helper abilities and diagnostics | Server helper abilities plus settings readiness/status contracts | Block, pattern, synced-pattern, template-part, theme, token, backend, and docs search helpers | `docs/features/helper-abilities.md` |
| Flavor Agent REST API | Routes under `flavor-agent/v1` | Activity read/write/undo, external-apply decision, and manual pattern sync | `docs/reference/abilities-and-routes.md#rest-routes` |

## Quick Mapping

- Block Inspector -> `flavor-agent/recommend-block`
- Content panel -> `flavor-agent/recommend-content`
- Pattern Inserter -> `flavor-agent/recommend-patterns`
- Navigation subsection in Block Inspector -> `flavor-agent/recommend-navigation`
- Template panel -> `flavor-agent/recommend-template`
- Template-part panel -> `flavor-agent/recommend-template-part`
- Global Styles / Style Book panel -> `flavor-agent/recommend-style`
- Activity read/write -> `GET/POST /flavor-agent/v1/activity`
- Activity undo -> `POST /flavor-agent/v1/activity/{id}/undo`
- External-apply approval -> `POST /flavor-agent/v1/activity/{id}/decision`
- External agent style apply -> `flavor-agent/request-style-apply`; status/attribution reads -> `flavor-agent/get-activity` and `flavor-agent/list-activity`; server-side undo -> `flavor-agent/undo-activity`
- Pattern sync -> `POST /flavor-agent/v1/sync-patterns`; status polling -> `GET /flavor-agent/v1/sync-patterns`

Recommendation surfaces are available through the WordPress Abilities API. The old `flavor-agent/v1/recommend-*` REST paths were removed before public release.

## Update Rule

When a surface changes, update this matrix only for location, gate, user action, or apply/undo summary changes. Put detailed behavior in the matching `docs/features/` file and exact contracts in `docs/reference/abilities-and-routes.md`.
