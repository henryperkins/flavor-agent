# WordPress AI Roadmap Tracking

This document tracks the [WordPress AI Planning & Roadmap project](https://github.com/orgs/WordPress/projects/240) and the overlap between active upstream work and Flavor Agent surfaces.

Use it when you need to answer:

- which upstream AI initiative collides with which Flavor Agent surface
- which board items are imminent (already a PR) versus directional (still in discussion)
- what to refactor, retire, or hand back to core when a given board item ships

## Source And Refresh

- Project: `https://github.com/orgs/WordPress/projects/240` (WordPress AI Planning & Roadmap)
- Public board: yes (read-only access requires `gh auth refresh -s read:project`)
- Snapshot date: 2026-04-28
- Snapshot shape: 304 items total, 230 Done, 73 active across Triage, Backlog, In discussion / Needs decision, To do, In progress, and Needs review.
- Active items live almost entirely in `WordPress/ai` (the AI Experiments showcase plugin); `WordPress/wp-ai-client`, `WordPress/abilities-api`, and `WordPress/php-ai-client` have no active items on this board as of the snapshot date.
- Teams visible on the board: `LLM Integrations`, `Abilities API`, `Integration Bridges`, `Showcase Plugin`. Showcase Plugin owns 294 of 304 items.

To refresh this snapshot:

```bash
# 1. Pull the full board into a working file.
gh project item-list 240 --owner WordPress --format json --limit 400 > /tmp/wp240-items.json

# 2. Active item count and breakdown by status.
jq '[.items[] | {n: .content.number, repo: (.content.repository // ""), title: (.title // .content.title), status, team, priority}]
    | group_by(.status) | map({status: .[0].status, count: length})' /tmp/wp240-items.json

# 3. List of items in non-Done statuses, sorted by team and priority.
jq '[.items[]
     | select(.status=="In progress" or .status=="Needs review" or .status=="To do"
              or .status=="In discussion / Needs decision" or .status=="Backlog")
     | {n: .content.number, repo: (.content.repository // ""), title: (.title // .content.title), status, team, priority}]
    | sort_by(.team, .priority, .status)' /tmp/wp240-items.json

# 4. High-priority strategy items.
jq '[.items[] | select(.priority=="High")
     | {n: .content.number, title: (.title // .content.title), status}]' /tmp/wp240-items.json
```

Then update the **Snapshot date**, the **Imminent** section if any new PR has appeared, and the **Active Items By Collision Area** tables. Move shipped items to **Out Of Scope** or delete them. When a board item ships and a Flavor Agent integration step closes, strike it through in **Action Implications**.

When this doc is updated, run `npm run check:docs` if any other live contributor doc (CLAUDE.md, AGENTS.md, copilot-instructions.md, README.md, the source-of-truth doc, the feature surface matrix, or the existing reference docs) was touched in the same change.

## Board Shape At Snapshot

| Status | Count |
| --- | --- |
| Triage | 0 |
| Backlog | 20 |
| In discussion / Needs decision | 24 |
| To do | 10 |
| In progress | 12 |
| Needs review | 7 |
| Done | 230 |
| (untagged) | 1 |

| Team | Active items |
| --- | --- |
| Showcase Plugin (`WordPress/ai`) | 70 |
| LLM Integrations (`WordPress/wp-ai-client`, `WordPress/php-ai-client`) | 2 |
| Abilities API (`WordPress/abilities-api`) | 0 |
| Integration Bridges | 0 |
| (untagged) | 1 |

The headline strategic read: WordPress core's AI direction is being prototyped almost entirely inside the AI Experiments plugin. Treat that repo's roadmap as the source of truth for "what core is going to ship next" in the AI editor and admin surfaces.

## Imminent: PR `WordPress/ai#419`

`WordPress/ai#419` is an open PR titled *"Comment Moderation, AI Observability (Logging), and the Site Agent (Natural Language Admin)"*, status **In progress** on the board. It is the single most urgent board item for Flavor Agent because it ships real PHP, not a proposal.

Scope (849 additions, 13 files):

- `includes/Admin/Observability_Logger.php`
- `includes/Admin/Site_Agent_Page.php`
- `includes/Abilities/Site_Agent/Site_Agent.php` plus `system-instruction.php`
- `includes/Abilities/Comment_Moderation/Comment_Moderation.php` plus `system-instruction.php`
- `includes/Experiments/Comment_Moderation/Comment_Moderation.php`
- `includes/Experiments/Experiments.php`, `includes/bootstrap.php`, plus CI/workflow changes

Direct collisions with Flavor Agent:

| Upstream artifact | Flavor Agent counterpart | Implication |
| --- | --- | --- |
| `Observability_Logger.php` | `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, `src/admin/activity-log.js`, `Settings > AI Activity` | Two competing per-call AI event logs over the same data shape (provider, tokens, latency, surface). Plan to forward Repository writes into the Observability Logger schema, or retire the admin audit page in favor of core's surface while keeping the editor-inline `AIActivitySection` history. |
| `Admin/Site_Agent_Page.php`, `Abilities/Site_Agent/` | `inc/Abilities/Registration.php`, the Inspector and Site Editor recommendation panels under `src/inspector/`, `src/templates/`, `src/template-parts/` | Different surface (admin chat versus editor inspector), but same conceptual primitive: ability invocation that mutates the site, with logging. The Site Agent will become the canonical "agentic mutation" surface; Flavor Agent panels remain the editor-bound complement. |

## High-Priority Strategic Items

These are the only items currently flagged `Priority: High` on the board. Every one touches Flavor Agent.

| # | Title | Status | Flavor Agent collision |
| --- | --- | --- | --- |
| `WordPress/ai#21` | How to best support hundreds or thousands of abilities | In discussion | `inc/Abilities/Registration.php` registers 20 abilities under the `flavor-agent/` namespace. The issue argues 1-to-1 ability→tool exposure breaks model tool selection at scale and proposes a "Layered Tool Pattern". Likely outcome: Flavor Agent consolidates into a smaller "router" ability surface, or registers abilities only on opt-in surfaces under issue 354. |
| `WordPress/ai#27` | Add developer support for pre-configured AI providers | In discussion | Flavor Agent already supports pre-configured providers via `flavor_agent_openai_provider`, `flavor_agent_azure_*`, and `flavor_agent_openai_native_*` options, but in a way that is invisible to AI Experiments. Once core defines a unified pre-config layer (constants, filters, or config files), Flavor Agent's settings duplicate it. |
| `WordPress/ai#36` | Validate integration patterns across multiple Abilities | To do | Defines the canonical "right way" to compose abilities across providers. `inc/LLM/ChatClient.php` and the per-prompt classes under `inc/LLM/` diverge from whatever pattern this issue settles on. |
| `WordPress/ai#37` | MCP usage across features and request routing | In discussion | Goal: MCP-based routing for at least one feature plus a reusable adapter. `inc/LLM/ChatClient.php` and `inc/OpenAI/Provider.php` do provider routing inside the plugin; once core ships an MCP routing adapter, Flavor Agent's routing becomes parallel infrastructure. |
| `WordPress/ai#47` | Low-/no-tech educational content for WP 6.9 launch | In discussion | Marketing-only; no Flavor Agent collision. |

## Active Items By Collision Area

Each table below maps board items to the Flavor Agent code paths they pressure.

### Logging, Observability, And Usage Safeguards

Compete with `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, `inc/Activity/Permissions.php`, `src/admin/activity-log.js`, and `src/admin/activity-log-utils.js`.

| # | Title | Status | Team |
| --- | --- | --- | --- |
| `WordPress/ai#419` | Comment Moderation, AI Observability (Logging), and the Site Agent (Natural Language Admin) | In progress (PR) | Showcase Plugin |
| `WordPress/ai#345` | Add usage safeguards to AI Client (limits, visibility, and cost awareness) | In progress | Showcase Plugin |
| `WordPress/ai#193` | Add developer-only log panel for inspecting AI provider responses | Backlog | LLM Integrations |

### Provider Routing And Connector Permissions

Compete with `inc/OpenAI/Provider.php`, `inc/AzureOpenAI/ConfigurationValidator.php`, `inc/LLM/WordPressAIClient.php`, `inc/LLM/ChatClient.php`, and the provider selection UI in `src/admin/settings-page.js` plus `src/admin/settings-page-controller.js`.

| # | Title | Status |
| --- | --- | --- |
| `WordPress/ai#262` | Provider-Level Model Bucketing for Model Selection | In discussion |
| `WordPress/ai#342` | Add permission controls for plugins to use a connected provider | In discussion |
| `WordPress/ai#441` | Require explicit admin approval for plugin access to Connectors plus improve connector secret protection | In discussion |
| `WordPress/ai#211` | Add Service Account experiment | In discussion |
| `WordPress/ai#191` | Add import/export support for AI settings and provider configuration | Backlog |
| `WordPress/ai#27` | Add developer support for pre-configured AI providers (also High priority above) | In discussion |

### Abilities Exposure And Surface Controls

Compete with `inc/Abilities/Registration.php` (20 abilities globally registered), `inc/Abilities/SurfaceCapabilities.php`, the surface gating in `src/utils/capability-flags.js`, and the editor hydration of abilities into `@wordpress/core-abilities`.

| # | Title | Status |
| --- | --- | --- |
| `WordPress/ai#354` | Unified Abilities exposure controls (per-surface gating) | In discussion |
| `WordPress/ai#348` | Feature Request: Unified AI Management Layer for WordPress Core | In discussion |
| `WordPress/ai#346` | Executing summarization ability with `@wordpress/abilities` in WP 7.0 fails on invalid schema | Needs review |
| `WordPress/ai#21` | How to best support hundreds or thousands of abilities (also High priority above) | In discussion |

### Editor Content Surfaces

Compete with the block recommendation flow in `src/inspector/BlockRecommendationsPanel.js`, the content-aware prompt in `inc/LLM/Prompt.php`, the `flavor-agent/recommend-content` ability in `inc/Abilities/ContentAbilities.php`, and the chip-based suggestion UX in `src/inspector/SuggestionChips.js`.

| # | Title | Status |
| --- | --- | --- |
| `WordPress/ai#324` | Evolve Refine from Notes into collaborative and agentic editorial workflows | In discussion |
| `WordPress/ai#300` | New Experiment: Content Resizing | Needs review |
| `WordPress/ai#297` | New experiment: Content Generation | Backlog |
| `WordPress/ai#452` | Content Classification: improve relevance of taxonomy suggestions | To do |
| `WordPress/ai#338` | New Experiments: Analytics-aware content and amplification recommendations | In discussion |
| `WordPress/ai#151` | Add Type Ahead experiment | In progress |
| `WordPress/ai#186` | Add tone adjustment controls for AI-generated content | Backlog |
| `WordPress/ai#187` | Support multilingual rewriting and translation via AI | Backlog |
| `WordPress/ai#188` | Add persona-driven content generation experiments | Backlog |
| `WordPress/ai#192` | Add extension points for custom prompt templates | Backlog |

### Site And Admin Agent Direction

Strategic overlap with the Inspector-bound recommendation model in `src/index.js`, the dual-store entity resolution in `src/utils/editor-entity-contracts.js`, and any future admin-wide expansion.

| # | Title | Status |
| --- | --- | --- |
| `WordPress/ai#419` | Site Agent backend (also Imminent above) | In progress (PR) |
| `WordPress/ai#189` | Explore an admin Site Agent for executing WordPress actions | Backlog |
| `WordPress/ai#142` | Frontend chat agent powered by site content | Backlog |
| `WordPress/ai#282` | Chat experiment: Integration outside the editor and outside single-task AI use | In discussion |
| `WordPress/ai#430` | Skills in a WordPress admin context | In discussion |

### MCP And WebMCP

Flavor Agent does not expose Model Context Protocol today; the abilities under `inc/Abilities/` are the natural participation surface once `WordPress/ai#354` exposes a per-surface gate.

| # | Title | Status |
| --- | --- | --- |
| `WordPress/ai#37` | MCP usage across features and request routing (also High priority above) | In discussion |
| `WordPress/ai#448` | Add WebMCP experiment | In discussion |
| `WordPress/ai#224` | Add WebMCP adapter experiment | In discussion |

### Settings UX

Compete with the settings page in `src/admin/settings-page.js`, `src/admin/settings-page-controller.js`, and `inc/Settings.php`.

| # | Title | Status |
| --- | --- | --- |
| `WordPress/ai#197` | General Settings enhancement | To do |
| `WordPress/ai#451` | Compress settings page above-the-fold | In progress |
| `WordPress/ai#472` | Update settings page to use `@wordpress/ui` components | In progress |
| `WordPress/ai#428` | Add onboarding guide to settings page | In discussion |
| `WordPress/ai#323` | Refine post-installation process when installed via the new Connectors page | In discussion |
| `WordPress/ai#457` | Improvements in Connectors and AI flow | In discussion |

### Canonical Service Abstraction And Contributor Plumbing

| # | Title | Status |
| --- | --- | --- |
| `WordPress/ai#233` | Refactor experiments to leverage AI_Service layer | To do |
| `WordPress/ai#307` | Add AGENTS.md to streamline contributor onboarding | In progress |

### LLM Integrations Team

| # | Title | Status |
| --- | --- | --- |
| `WordPress/ai#387` | Gemini connector: 400 Bad Request – `additionalProperties` not supported in `response_schema` | In progress |
| `WordPress/ai#193` | Developer-only log panel (also Logging above) | Backlog |

### Cross-Repo Items Not On The Board

These are not currently on project 240, but they are tracked in upstream repos and are referenced because they affect Flavor Agent's integration assumptions:

- `WordPress/wp-ai-client#64` — Plugins that include their own `wp-ai-client` via Composer can break the Connectors screen. Flavor Agent's `composer.json` does not bundle `wordpress/wp-ai-client` or `wordpress/php-ai-client`; do not add them.
- `WordPress/wp-ai-client#66` — Lifecycle events `wp_ai_client_before_generate_result` and `wp_ai_client_after_generate_result` never fire. When fixed they become the natural place for `inc/Activity/Repository.php` to subscribe instead of wrapper-level instrumentation.
- `WordPress/abilities-api#160` — Question of archiving the standalone repo since the Abilities API merged into core. Stay subscribed for the migration message.
- `WordPress/abilities-api#149` — Proposal to add input/output and permission/logging filters around ability invocation. Once filters land, do not double-log: wire `inc/Activity/Repository.php` writes through the lifecycle filter rather than wrapper code.
- `WordPress/abilities-api#75` — Proposal that REST endpoints themselves become abilities. If this lands, the parallel surface in `inc/REST/Agent_Controller.php` (8 routes mirroring 20 abilities) becomes redundant.
- `WordPress/abilities-api#38` — In progress, High priority. Convenient filtering of registered abilities by category, namespace, and metadata. Flavor Agent registers under nine categories; once filtering is canonical, internal listings should migrate.

## Out Of Scope At Snapshot

Active board items below have no Flavor Agent collision and are listed only so refresh runs can confirm scope did not drift.

- Image and media: `#270`, `#288`, `#294`, `#302`, `#325`, `#388`, `#402`, `#421`, `#435`, `#238`
- Connector debugging or environment bugs: `#339`, `#387`, `#420`
- Experiment polish that does not touch shared subsystems: `#90`, `#145`, `#155`, `#180`, `#181`, `#182`, `#183`, `#184`, `#185`, `#190`, `#197` (settings is tracked above), `#203`, `#221`, `#225`, `#257`, `#270`, `#390`, `#391`, `#397`, `#425`, `#428`, `#472`
- Marketing or release: `#47`

If any of these get rescoped to share infrastructure with Flavor Agent (for example, `#190` site-wide content insights gaining an Inspector surface), promote them into the appropriate **Active Items By Collision Area** table.

## Action Implications For Flavor Agent

Each bullet is keyed to the board item that drives it. Strike through completed work when the corresponding upstream item ships.

1. **`#419`** — Subscribe and mirror PR review. Prepare `inc/Activity/Repository.php` to forward writes into the Observability Logger schema (or to be retired in favor of core), while keeping the editor-inline `AIActivitySection` history backed by Flavor Agent. **Not yet covered by an existing workstream.**
2. **`#21`, `#354`** — Plan ability consolidation. Decide whether the 20 abilities under `flavor-agent/` collapse into a smaller router surface, or remain individual but registered against per-surface gates once `#354` defines them. **Not yet covered by an existing workstream.**
3. ~~**`#37`, `#262`, `#27`, `#348`** — Stop investing in independent provider routing UX. Treat `inc/OpenAI/Provider.php` and the provider selector in `src/admin/settings-page-controller.js` as fallback-only once core ships unified routing or model bucketing.~~ **Done 2026-04-28 via Workstream C (Provider Ownership Migration). Direct chat fields removed; chat is fully Connectors-owned. Plugin retains direct embedding credentials only.**
4. **`#345`, `#419`** — Define the activity story before duplication ships. Choose between forwarding Activity Repository writes into core's metering pipeline or retiring the admin audit page in favor of core's dashboard, while keeping the editor-inline activity surface. **Not yet covered by an existing workstream.**
5. **`#192`** — Hold on bespoke prompt-template extension points. The canonical hook will land here; resist building Flavor Agent-specific extension points in the meantime. **Not yet covered by an existing workstream.**
6. **`WordPress/abilities-api#75`** — Watch for REST-as-ability unification. If accepted, plan to merge `inc/REST/Agent_Controller.php` into the abilities surface so consumers do not get two parallel entry points for the same recommendation. **Adjacent to Workstream C (which assumes feature-specific REST routes survive); revisit Workstream C scope if `#75` is accepted.**
7. **`WordPress/abilities-api#149`** — Once execution lifecycle filters land, move `inc/Activity/Repository.php` instrumentation onto them to avoid double-logging when callers hit core's filter set in addition to the Flavor Agent wrapper. **Not yet covered by an existing workstream.**
8. **WordPress 7.1 Guidelines API (`wp_register_guideline()`)** — Source guidelines into prompt assembly under `inc/LLM/` (`Prompt.php`, `TemplatePrompt.php`, `TemplatePartPrompt.php`, `NavigationPrompt.php`, `StylePrompt.php`, `WritingPrompt.php`) so Flavor Agent recommendations respect site-wide guidelines as soon as core's API ships. **In progress 2026-04-28 via Workstream D: Flavor Agent now has a core-first repository bridge, prompt formatter, and settings migration framing. Keep watching for the public `wp_register_guideline()` API and core's final write/defaults model before adding write migration.**

### Mapping To The Remediation Plan

`docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md` is the action-oriented backlog for places where Flavor Agent should hand ownership back to core. This tracking doc is the upstream-pressure source for that backlog. Reference cross-walk:

| Remediation workstream | Pending? | Driven by these board items |
| --- | --- | --- |
| A (Pattern Surface Reset) | Done 2026-04-23 | Pattern API stabilization (no specific item on the board today) |
| B (Block Inspector Ownership Reset) | Done 2026-04-23 | None active on the board today |
| C (Provider Ownership Migration) | Done 2026-04-28 | `WordPress/ai#27`, `#37`, `#262`, `#342`, `#348`, `#441` |
| D (Guidelines Bridge and Migration) | In progress 2026-04-28 | WordPress 7.1 Guidelines API (Trac, not on this board); bridge reads `wp_guideline` / `wp_guideline_type` now and defers write migration until the public API settles |
| E (Settings Screen Modernization) | Pending | `WordPress/ai#197`, `#451`, `#472`, `#428`, `#323` (these are core's own settings UX, but they pressure Flavor Agent's settings to align) |

Action implications 1, 2, 4, 5, and 7 above describe upstream pressures with no corresponding workstream yet. When any of them moves from "watch" to "act", add a workstream to the remediation plan rather than tracking implementation details here.

## Related References

- `docs/SOURCE_OF_TRUTH.md` — canonical product definition, current state, and architectural guardrails.
- `docs/FEATURE_SURFACE_MATRIX.md` — every shipped surface, gate, and apply/undo path.
- `docs/reference/abilities-and-routes.md` — REST and Abilities contract map.
- `docs/reference/provider-precedence.md` — backend selection, credential fallback chain, and Connectors-first runtime activation.
- `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md` — active backlog of places where Flavor Agent should stop duplicating Gutenberg.
