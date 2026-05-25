# WordPress AI Roadmap Tracking

This document tracks the [WordPress AI Planning & Roadmap project](https://github.com/orgs/WordPress/projects/240) and the overlap between active upstream work and Flavor Agent surfaces.

Use it when you need to answer:

- which upstream AI initiative collides with which Flavor Agent surface
- which board items are imminent (already a PR) versus directional (still in discussion)
- what to refactor, retire, or hand back to core when a given board item ships

## Source And Refresh

- Project: `https://github.com/orgs/WordPress/projects/240` (WordPress AI Planning & Roadmap)
- Public board: yes (read-only access requires `gh auth refresh -s read:project`)
- Snapshot date: 2026-05-09 for the full project-board counts; partial release-train refresh: 2026-05-21
- Snapshot shape: 304 items total, 230 Done, 73 active across Triage, Backlog, In discussion / Needs decision, To do, In progress, and Needs review.
- AI plugin release overlay refreshed: 2026-05-21. `WordPress/ai` release `1.0.0` was published on 2026-05-19, and the 2026-05-21 Make AI release post confirms Request Logging, Connector Approvals, provider/onboarding error improvements, and media-editor AI work as shipped `1.0.0` scope. `WordPress/ai#595` is open against milestone `1.1.0` and remains the post-`1.0.0` Connector Approval compatibility watch item. Media issues `#325` and `#238` are open, Future Release items on the planning board; keep them out of Flavor Agent scope unless they start sharing editor recommendation infrastructure.
- Release-cycle grounding refreshed: 2026-05-21. The WordPress 7.0 Field Guide confirms the AI Client, Client-Side Abilities API, Connectors screen, and Connectors API as WordPress 7.0 developer-facing features. Treat those as release-cycle facts while keeping this document's project-board counts as the older 2026-05-09 snapshot until the board is explicitly refreshed.
- Active items live almost entirely in `WordPress/ai` (the core AI plugin / AI Experiments showcase repository); `WordPress/wp-ai-client`, `WordPress/abilities-api`, and `WordPress/php-ai-client` have no active items on this board as of the snapshot date.
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

# 5. Pull release-milestone overlays from the core AI plugin.
gh api repos/WordPress/ai/milestones/17
gh api 'repos/WordPress/ai/issues?milestone=17&state=all&per_page=100'
gh api repos/WordPress/ai/milestones/7
gh api 'repos/WordPress/ai/issues?milestone=7&state=all&per_page=100'
gh api repos/WordPress/ai/milestones/18
gh api 'repos/WordPress/ai/issues?milestone=18&state=all&per_page=100'
```

Then update the **Snapshot date**, the **AI Plugin Release Milestone Overlay**, the **Release-Train Items To Watch First** section if any new PR has appeared, and the **Active Items By Collision Area** tables. Move shipped items to **Out Of Scope** or delete them. When a board item ships and a Flavor Agent integration step closes, strike it through in **Action Implications**.

When this doc is updated, run `npm run check:docs` if any other live contributor doc (CLAUDE.md, AGENTS.md, copilot-instructions.md, README.md, the source-of-truth doc, the feature surface matrix, or the existing reference docs) was touched in the same change.

## Board Shape At Snapshot

| Status                         | Count |
| ------------------------------ | ----- |
| Triage                         | 0     |
| Backlog                        | 20    |
| In discussion / Needs decision | 24    |
| To do                          | 10    |
| In progress                    | 12    |
| Needs review                   | 7     |
| Done                           | 230   |
| (untagged)                     | 1     |

| Team                                                                   | Active items |
| ---------------------------------------------------------------------- | ------------ |
| Showcase Plugin (`WordPress/ai`)                                       | 70           |
| LLM Integrations (`WordPress/wp-ai-client`, `WordPress/php-ai-client`) | 2            |
| Abilities API (`WordPress/abilities-api`)                              | 0            |
| Integration Bridges                                                    | 0            |
| (untagged)                                                             | 1            |

The headline strategic read: WordPress core's AI direction is being prototyped almost entirely inside the core AI plugin, while the WordPress 7.0 Field Guide now confirms the core AI Client, Client-Side Abilities API, Connectors screen, and Connectors API as 7.0 release-cycle facts. Treat the `WordPress/ai` release milestones as the source of truth for what the plugin is targeting next, and the project board as the broader pressure map for editor, admin, provider, and ability surfaces.

## AI Plugin Release Milestone Overlay

This overlay is separate from the project-board status tables above. It records the AI plugin release train plus any partial currentness checks made after the broader 2026-05-09 project-board snapshot.

AI plugin `0.9.0` was verified in the local test container on 2026-05-09. Flavor Agent now treats the AI plugin Developer Tools per-feature option `wpai_feature_flavor-agent_field_developer` as the canonical feature-level provider/model preference when present, while explicit per-call provider arguments keep highest precedence.

AI plugin `0.9.0` also shipped adjacent experiments and surfaces including Comment Moderation, Content Resizing, WP-CLI alt-text plumbing, and settings UI work. The only required Flavor Agent code integration from this release is honoring the per-feature developer provider/model setting; the other shipped surfaces remain watch items because Flavor Agent does not call those experiments directly.

AI plugin `1.0.0` shipped on 2026-05-19 and is available as a normal release. It introduced Request Logging in `WordPress/ai#437` and Connector Approvals in `WordPress/ai#467`, plus no-provider and missing-provider handling that points users toward configuring an AI Connector. Flavor Agent now handles request-time Connector Approval denials by preserving the AI plugin's connector/caller metadata and showing an approval notice in the editor. Runtime verification still depends on the caller-attribution behavior from `WordPress/ai#595` or an equivalent upstream build so pending approvals are recorded for `flavor-agent/flavor-agent.php` instead of the AI plugin or provider connector.

AI plugin `1.0.0` also integrated Alt Text generation into Gutenberg's experimental Media Editor. That keeps the media-editor watch warm, but it does not create new Flavor Agent product work because this plugin does not own media editing, image generation, focal-point selection, or crop metadata surfaces. Open issues `WordPress/ai#325` and `WordPress/ai#238` remain Future Release/In progress media work, not a Flavor Agent collision.

| Milestone URL                | Plugin version | State                         | Read for Flavor Agent                                                                                                                                                                                                                                     |
| ---------------------------- | -------------- | ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WordPress/ai` milestone #17 | `0.9.0`        | Verified locally 2026-05-09   | Developer provider/model preference, Comment Moderation, Content Resizing, settings UI, and early provider-model work became baseline context.                                                                                                            |
| `WordPress/ai` milestone #7  | `1.0.0`        | Released 2026-05-19           | Request Logging and Connector Approvals are shipped, provider/onboarding errors are more explicit, and client-side Abilities API usage is now part of the AI plugin baseline.                                                                              |
| `WordPress/ai` milestone #18 | `1.1.0`        | Due 2026-06-04; partial check | Watch `WordPress/ai#595` for Connector Approval caller attribution. The Make AI post also names Media Editor/focus-aware crop work as 1.1.0-or-future exploration, but those remain out of scope unless they intersect Flavor Agent recommendation surfaces. |

Flavor Agent-relevant `0.9.0` items:

| #                  | Title                                                                                                | State        | Collision                                                                                                                |
| ------------------ | ---------------------------------------------------------------------------------------------------- | ------------ | ------------------------------------------------------------------------------------------------------------------------ |
| `WordPress/ai#36`  | Validate integration patterns across multiple Abilities                                              | Open issue   | Pressures `inc/Abilities/*`, `inc/LLM/ChatClient.php`, and prompt orchestration patterns.                                |
| `WordPress/ai#40`  | WordPress Core Abilities                                                                             | Open issue   | Pressures assumptions around server-registered abilities and client hydration through `@wordpress/core-abilities`.       |
| `WordPress/ai#148` | Add Extended Providers experiment                                                                    | Closed PR    | Pressures provider routing and any remaining Flavor Agent settings that overlap connector/provider selection.            |
| `WordPress/ai#155` | Add Comment Moderation experiment                                                                    | Merged PR    | Shows the current Experiment + Ability pattern for content-classification work adjacent to Flavor Agent's content panel. |
| `WordPress/ai#197` | General Settings enhancement                                                                         | Open issue   | Pressures Flavor Agent's plugin settings UX and any duplicated onboarding/provider affordances.                          |
| `WordPress/ai#262` | Provider-Level Model Bucketing for Model Selection                                                   | Open issue   | Pressures `inc/OpenAI/Provider.php`, `inc/LLM/WordPressAIClient.php`, and settings copy around model/provider selection. |
| `WordPress/ai#300` | New Experiment: Content Resizing                                                                     | Closed issue | Confirms editor content transformation remains an AI-plugin release surface.                                             |
| `WordPress/ai#323` | Refine post-installation process when installed via the new Connectors page                          | Open issue   | Pressures Flavor Agent's Connectors/settings redirects and capability notices.                                           |
| `WordPress/ai#345` | Add usage safeguards to AI Client (limits, visibility, and cost awareness)                           | Closed issue | Pressures `inc/Activity/*`, metrics normalization, and admin audit/cost-visibility decisions.                            |
| `WordPress/ai#346` | Executing summarization ability with `@wordpress/abilities` in WordPress 7.0 fails on invalid schema | Open issue   | Pressures ability schemas and client-side execution assumptions.                                                         |
| `WordPress/ai#437` | AI Request Logging                                                                                   | Merged PR    | Sits at a different layer than `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, and `src/admin/activity-log.js`. Implemented for coexistence in `docs/reference/activity-log-request-logging-coexistence.md`: enrich core's `wpai_request_logs.context` via the `wpai_request_log_context` filter, suppress Flavor Agent's `request_diagnostic` rows when core logging is on, keep apply/undo rows local. |
| `WordPress/ai#457` | Improvements in Connectors and AI flow                                                               | Open issue   | Pressures connector-readiness copy and plugin-owned fallback settings.                                                   |
| `WordPress/ai#472` | Update settings page to use `@wordpress/ui` components                                               | Open PR      | Pressures visual alignment for Flavor Agent's admin settings page.                                                       |
| `WordPress/ai#481` | Ensure any `sanitize_callback` in Abilities input schema is executed                                 | Open PR; Future Release | Pressures `inc/Abilities/*` schema design and input normalization expectations.                                  |
| `WordPress/ai#497` | For image gen, move guidelines from system instructions to prompt                                    | Open PR      | Watch for final Guidelines prompt-placement semantics before changing Flavor Agent prompt assembly again.                |

Flavor Agent-relevant `1.0.0` items:

| Upstream artifact                                                                                  | Flavor Agent counterpart                                                   | Implication                                                                                                                     |
| -------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `WordPress/ai#21` — How to best support hundreds or thousands of abilities                         | `inc/Abilities/Registration.php` defines 20 abilities                      | Ability consolidation or per-surface registration becomes a `1.0.0` release-train concern.                                      |
| `WordPress/ai#27` — Developer support for pre-configured AI providers                              | Provider precedence and settings docs                                      | Flavor Agent should keep plugin-owned chat/provider UX fallback-only and defer to core AI plugin configuration where available. |
| `WordPress/ai#33` — Advanced configuration tools for power users                                   | Settings page and provider diagnostics                                     | Avoid building parallel advanced-provider configuration that will be superseded by AI-plugin settings.                          |
| `WordPress/ai#182` — Graceful degradation when no AI provider is configured                        | `CapabilityNotice`, surface capability flags                               | Align disabled-state and remediation copy with core AI plugin behavior.                                                         |
| `WordPress/ai#183`, `#428`, `#450` — onboarding and provider setup guidance                        | Settings/connectors navigation                                             | Keep Flavor Agent onboarding lightweight and link out to the core AI plugin/Connectors flow.                                    |
| `WordPress/ai#184`, `#185` — developer examples for AI Experiments, Abilities API, and MCP Adapter | Flavor Agent docs and ability contracts                                    | Use upstream examples as the canonical extension shape; avoid Flavor Agent-only ability conventions where possible.             |
| `WordPress/ai#324` — Refine collaborative and agentic editorial workflows                          | Content and Inspector recommendation panels                                | Watch for editor-native collaboration/agent workflows that could absorb parts of the content recommendation surface.            |
| `WordPress/ai#342`, `#343` — plugin access permissions for connected providers                     | `inc/Abilities/SurfaceCapabilities.php`, provider readiness, admin notices | Permission controls become a `1.0.0` target; Flavor Agent must not assume connector availability implies plugin authorization.  |
| `WordPress/ai#437` — Request Logging                                                              | `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, `src/admin/activity-log.js` | Core AI request logging now exists. Coexistence (not consolidation): enrich `wpai_request_logs.context` via the `wpai_request_log_context` filter, stop persisting `request_diagnostic` rows when core logging is enabled, keep apply/undo rows local — see `docs/reference/activity-log-request-logging-coexistence.md`. |
| `WordPress/ai#467` — Connector Approvals                                                          | `inc/LLM/WordPressAIClient.php`, request-error details, per-surface notices | Shipped and integrated locally; keep final post-approval runtime smoke gated on representative provider state and caller attribution. |
| `WordPress/ai#452` — Content Classification relevance                                              | `inc/Abilities/ContentAbilities.php`, content panel taxonomy suggestions   | Content classification relevance work may become the canonical taxonomy/classification layer.                                   |
| `WordPress/ai#482` — client-side Abilities API                                                     | Editor-side ability access and hydration assumptions                       | Merged in `1.0.0`; keep Flavor Agent's abilities bridge aligned with core hydration instead of adding parallel REST execution paths. |
| `WordPress/ai#486` — developer settings mode for desired provider/model per feature                | Provider/model diagnostics and settings                                    | Merged in `0.9.0` and already honored by `WordPressAIClient::chat()`; do not create a competing Flavor Agent model pinning UI.  |

## Release-Train Items To Watch First

AI plugin `1.0.0` is now shipped. For Flavor Agent, the urgent release-train items are no longer "wait for 1.0.0"; they are the follow-through decisions created by shipped Request Logging and Connector Approvals, plus the still-open Ability input-schema sanitization work in `WordPress/ai#481`. `WordPress/ai#595` is the next Connector Approval compatibility watch item, and `WordPress/ai#419` remains a Future Release strategic architecture preview rather than a current editor-surface migration.

Direct collisions with Flavor Agent:

| Upstream artifact or item                                                 | Flavor Agent counterpart                                                                                                                              | Implication                                                                                                                                                                                                                                                                                     |
| ------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WordPress/ai#437` — AI Request Logging (merged in `1.0.0`)               | `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, `src/admin/activity-log.js`, `Settings > AI Activity`                                   | Sits at the AI Client HTTP transporter layer; Flavor Agent's repository sits at the editor-apply layer. Coexistence implementation at `docs/reference/activity-log-request-logging-coexistence.md`: subscribe to the `wpai_request_log_context` filter to enrich `wpai_request_logs` with Flavor Agent surface/scope/document, suppress duplicative `request_diagnostic` writes when core logging is enabled, keep apply/undo rows and the editor-inline `AIActivitySection` history local. |
| `WordPress/ai#467` / `#595` — Connector Approvals and caller attribution  | `inc/LLM/WordPressAIClient.php`, `src/store/request-error-details.js`, per-surface `CapabilityNotice` rendering                                       | Local request-time denial handling exists; final runtime approval success remains a provider-state and caller-attribution smoke item. Do not couple editor bootstrap to AI plugin approval-store internals.                                                                                    |
| `WordPress/ai#345` — usage safeguards (closed in `1.0.0`)                 | `inc/Activity/*`, `Support\MetricsNormalizer`, admin audit summaries                                                                                  | Align cost/limit/visibility concepts with core AI plugin safeguards instead of inventing a parallel metering vocabulary.                                                                                                                                                                        |
| `WordPress/ai#481` — Ability schema sanitization                          | `inc/Abilities/*`, `Support\NormalizesInput`, REST argument normalization                                                                             | Re-check ability input schemas once upstream callback execution lands; avoid duplicate or divergent sanitization paths between REST and Abilities execution.                                                                                                                                    |
| `WordPress/ai#155` — Comment Moderation experiment (merged in `0.9.0`)    | `inc/Abilities/ContentAbilities.php`, `inc/LLM/WritingPrompt.php`, content recommendation panel                                                       | Adjacent surface, not a direct UI collision. Use it as the canonical Experiment + Ability pattern for content-classification workflows.                                                                                                                                                         |
| `WordPress/ai#419` — Site Agent / Natural Language Admin (Future Release) | `inc/Abilities/Registration.php`, the Inspector and Site Editor recommendation panels under `src/inspector/`, `src/templates/`, `src/template-parts/` | Different surface (admin chat versus editor inspector), but same conceptual primitive: ability invocation that mutates the site, with logging. The Site Agent remains the likely canonical "agentic mutation" surface; Flavor Agent panels remain the editor-bound complement until that ships. |

## High-Priority Strategic Items

These are the only items currently flagged `Priority: High` on the board. Every one touches Flavor Agent.

| #                 | Title                                                   | Status        | Flavor Agent collision                                                                                                                                                                                                                                                                                                                                                    |
| ----------------- | ------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WordPress/ai#21` | How to best support hundreds or thousands of abilities  | In discussion | `inc/Abilities/Registration.php` defines 20 abilities under the `flavor-agent/` namespace. Helper/read abilities register whenever the Abilities API exists; recommendation abilities also require the WordPress AI feature gate. The issue argues 1-to-1 ability->tool exposure breaks model tool selection at scale and proposes a "Layered Tool Pattern". Likely outcome: Flavor Agent consolidates into a smaller "router" ability surface, or registers abilities only on opt-in surfaces under issue 354. Per the [2026-05-20 AI contributor summary](https://make.wordpress.org/ai/2026/05/21/ai-contributor-weekly-summary-20-may-2026/), `@justlevine` is the named owner; the action item is to document emerging ecosystem trends and, if necessary, open an expansive Trac ticket for broader core developer feedback. The "find abilities" discovery workflow inside the AI companion plugin is the team's named next technical milestone, mirroring the third-party "find tool" pattern. |
| `WordPress/ai#27` | Add developer support for pre-configured AI providers   | In discussion | Flavor Agent now routes chat through the WordPress AI Client / Connectors runtime and keeps only a hidden `flavor_agent_openai_provider` compatibility value. Embeddings are configured through Cloudflare Workers AI fields because Connectors does not expose embedding generation yet.                                |
| `WordPress/ai#36` | Validate integration patterns across multiple Abilities | To do         | Defines the canonical "right way" to compose abilities across providers. `inc/LLM/ChatClient.php` and the per-prompt classes under `inc/LLM/` diverge from whatever pattern this issue settles on.                                                                                                                                                                        |
| `WordPress/ai#37` | MCP usage across features and request routing           | In discussion | Goal: MCP-based routing for at least one feature plus a reusable adapter. `inc/LLM/ChatClient.php` and `inc/OpenAI/Provider.php` do provider routing inside the plugin; once core ships an MCP routing adapter, Flavor Agent's routing becomes parallel infrastructure.                                                                                                   |
| `WordPress/ai#47` | Low-/no-tech educational content for WP 6.9 launch      | In discussion | Marketing-only; no Flavor Agent collision.                                                                                                                                                                                                                                                                                                                                |

## Active Items By Collision Area

Each table below maps board items to the Flavor Agent code paths they pressure.

### Logging, Observability, And Usage Safeguards

Compete with `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, `inc/Activity/Permissions.php`, `src/admin/activity-log.js`, and `src/admin/activity-log-utils.js`.

| #                  | Title                                                                                       | Status           | Team             |
| ------------------ | ------------------------------------------------------------------------------------------- | ---------------- | ---------------- |
| `WordPress/ai#437` | AI Request Logging                                                                          | Merged; `1.0.0`  | Showcase Plugin  |
| `WordPress/ai#419` | Comment Moderation, AI Observability (Logging), and the Site Agent (Natural Language Admin) | Future Release   | Showcase Plugin  |
| `WordPress/ai#345` | Add usage safeguards to AI Client (limits, visibility, and cost awareness)                  | Closed; `1.0.0`  | Showcase Plugin  |
| `WordPress/ai#193` | Add developer-only log panel for inspecting AI provider responses                           | Backlog          | LLM Integrations |

### Provider Routing And Connector Permissions

Compete with `inc/OpenAI/Provider.php`, `inc/Embeddings/ConfigurationValidator.php`, `inc/LLM/WordPressAIClient.php`, `inc/LLM/ChatClient.php`, and the provider selection UI in `src/admin/settings-page.js` plus `src/admin/settings-page-controller.js`.

| #                  | Title                                                                                                    | Status           |
| ------------------ | -------------------------------------------------------------------------------------------------------- | ---------------- |
| `WordPress/ai#148` | Add Extended Providers experiment                                                                        | Closed           |
| `WordPress/ai#262` | Provider-Level Model Bucketing for Model Selection                                                       | In discussion    |
| `WordPress/ai#343` | Implement plugin permissions management system                                                           | Closed           |
| `WordPress/ai#467` | Connector Approval experiment                                                                            | Merged; `1.0.0` with caller-attribution caveat |
| `WordPress/ai#595` | Deepest originating extension caller attribution for Connector Approval                                  | Open PR; `1.1.0` |
| `WordPress/ai#486` | Add developer settings mode with the ability to set desired provider and model per feature               | Merged; `0.9.0` |
| `WordPress/ai#342` | Add permission controls for plugins to use a connected provider                                          | In discussion    |
| `WordPress/ai#441` | Require explicit admin approval for plugin access to Connectors plus improve connector secret protection | In discussion    |
| `WordPress/ai#211` | Add Service Account experiment                                                                           | In discussion    |
| `WordPress/ai#191` | Add import/export support for AI settings and provider configuration                                     | Backlog          |
| `WordPress/ai#27`  | Add developer support for pre-configured AI providers (also High priority above)                         | In discussion    |

### Abilities Exposure And Surface Controls

Compete with `inc/Abilities/Registration.php` (20 defined abilities, with recommendation registration gated by the AI feature), `inc/Abilities/SurfaceCapabilities.php`, the surface gating in `src/utils/capability-flags.js`, and the editor hydration of abilities into `@wordpress/core-abilities`.

| #                  | Title                                                                                         | Status           |
| ------------------ | --------------------------------------------------------------------------------------------- | ---------------- |
| `WordPress/ai#40`  | WordPress Core Abilities                                                                      | Open; `0.9.0`    |
| `WordPress/ai#354` | Unified Abilities exposure controls (per-surface gating)                                      | In discussion    |
| `WordPress/ai#348` | Feature Request: Unified AI Management Layer for WordPress Core                               | In discussion    |
| `WordPress/ai#481` | Ensure any `sanitize_callback` in Abilities input schema is executed                          | Open PR; Future Release |
| `WordPress/ai#482` | Utilize the new client-side Abilities API                                                     | Merged; `1.0.0`  |
| `WordPress/ai#346` | Executing summarization ability with `@wordpress/abilities` in WP 7.0 fails on invalid schema | Needs review     |
| `WordPress/ai#21`  | How to best support hundreds or thousands of abilities (also High priority above)             | In discussion    |

### Editor Content Surfaces

Compete with the block recommendation flow in `src/inspector/BlockRecommendationsPanel.js`, the content-aware prompt in `inc/LLM/Prompt.php`, the `flavor-agent/recommend-content` ability in `inc/Abilities/ContentAbilities.php`, and the chip-based suggestion UX in `src/inspector/SuggestionChips.js`.

| #                  | Title                                                                       | Status        |
| ------------------ | --------------------------------------------------------------------------- | ------------- |
| `WordPress/ai#324` | Evolve Refine from Notes into collaborative and agentic editorial workflows | In discussion |
| `WordPress/ai#300` | New Experiment: Content Resizing                                            | Needs review  |
| `WordPress/ai#297` | New experiment: Content Generation                                          | Backlog       |
| `WordPress/ai#452` | Content Classification: improve relevance of taxonomy suggestions           | To do         |
| `WordPress/ai#338` | New Experiments: Analytics-aware content and amplification recommendations  | In discussion |
| `WordPress/ai#151` | Add Type Ahead experiment                                                   | In progress   |
| `WordPress/ai#186` | Add tone adjustment controls for AI-generated content                       | Backlog       |
| `WordPress/ai#187` | Support multilingual rewriting and translation via AI                       | Backlog       |
| `WordPress/ai#188` | Add persona-driven content generation experiments                           | Backlog       |
| `WordPress/ai#192` | Add extension points for custom prompt templates                            | Backlog       |

Guidelines-specific follow-up:

| Item | Status | Flavor Agent decision |
| --- | --- | --- |
| AI plugin Guidelines service content type filter | Filed upstream as `WordPress/ai#529` after 0.9.0 verification | Flavor Agent filters `wp_guideline_type=content` locally; upstream AI plugin service needs the same content type guard to avoid artifact-guideline shadowing. |

### Site And Admin Agent Direction

Strategic overlap with the Inspector-bound recommendation model in `src/index.js`, the dual-store entity resolution in `src/utils/editor-entity-contracts.js`, and any future admin-wide expansion.

| #                  | Title                                                                          | Status         |
| ------------------ | ------------------------------------------------------------------------------ | -------------- |
| `WordPress/ai#419` | Site Agent backend (future-release carry-over; see release-train section)      | Future Release |
| `WordPress/ai#189` | Explore an admin Site Agent for executing WordPress actions                    | Backlog        |
| `WordPress/ai#142` | Frontend chat agent powered by site content                                    | Backlog        |
| `WordPress/ai#282` | Chat experiment: Integration outside the editor and outside single-task AI use | In discussion  |
| `WordPress/ai#430` | Skills in a WordPress admin context                                            | In discussion  |

### MCP And WebMCP

Flavor Agent exposes seven recommendation abilities to the Abilities API default MCP server via `meta.mcp.public = true`: block, content, pattern, template, template-part, navigation, and style recommendations. It also registers a dedicated MCP Adapter server at `/wp-json/mcp/flavor-agent` when the MCP Adapter is active, so the seven recommendation tools appear directly in `tools/list`. Future upstream MCP routing work still pressures provider and ability-routing layers, but Flavor Agent no longer relies only on the universal default-server bridge.

| #                  | Title                                                                    | Status        |
| ------------------ | ------------------------------------------------------------------------ | ------------- |
| `WordPress/ai#37`  | MCP usage across features and request routing (also High priority above) | In discussion |
| `WordPress/ai#448` | Add WebMCP experiment                                                    | In discussion |
| `WordPress/ai#224` | Add WebMCP adapter experiment                                            | In discussion |

### Settings UX

Compete with the settings page in `src/admin/settings-page.js`, `src/admin/settings-page-controller.js`, and `inc/Settings.php`.

| #                  | Title                                                                       | Status        |
| ------------------ | --------------------------------------------------------------------------- | ------------- |
| `WordPress/ai#197` | General Settings enhancement                                                | To do         |
| `WordPress/ai#451` | Compress settings page above-the-fold                                       | In progress   |
| `WordPress/ai#472` | Update settings page to use `@wordpress/ui` components                      | In progress   |
| `WordPress/ai#428` | Add onboarding guide to settings page                                       | In discussion |
| `WordPress/ai#323` | Refine post-installation process when installed via the new Connectors page | In discussion |
| `WordPress/ai#457` | Improvements in Connectors and AI flow                                      | In discussion |

### Canonical Service Abstraction And Contributor Plumbing

| #                  | Title                                              | Status      |
| ------------------ | -------------------------------------------------- | ----------- |
| `WordPress/ai#233` | Refactor experiments to leverage AI_Service layer  | To do       |
| `WordPress/ai#307` | Add AGENTS.md to streamline contributor onboarding | In progress |

### LLM Integrations Team

| #                  | Title                                                                                         | Status      |
| ------------------ | --------------------------------------------------------------------------------------------- | ----------- |
| `WordPress/ai#387` | Gemini connector: 400 Bad Request – `additionalProperties` not supported in `response_schema` | In progress |
| `WordPress/ai#193` | Developer-only log panel (also Logging above)                                                 | Backlog     |

### Cross-Repo Items Not On The Board

These are not currently on project 240, but they are tracked in upstream repos and are referenced because they affect Flavor Agent's integration assumptions:

- `WordPress/wp-ai-client#64` — Plugins that include their own `wp-ai-client` via Composer can break the Connectors screen. Flavor Agent's `composer.json` does not bundle `wordpress/wp-ai-client` or `wordpress/php-ai-client`; do not add them.
- `WordPress/wp-ai-client#66` — Lifecycle events `wp_ai_client_before_generate_result` and `wp_ai_client_after_generate_result` never fire. When fixed they become the natural place for `inc/Activity/Repository.php` to subscribe instead of wrapper-level instrumentation.
- `WordPress/abilities-api#160` — Question of archiving the standalone repo since the Abilities API merged into core. Stay subscribed for the migration message.
- `WordPress/abilities-api#149` — Proposal to add input/output and permission/logging filters around ability invocation. Once filters land, do not double-log: wire `inc/Activity/Repository.php` writes through the lifecycle filter rather than wrapper code.
- `WordPress/abilities-api#75` — Proposal that REST endpoints themselves become abilities. If this lands, consider whether the remaining Flavor Agent REST adapters for activity persistence, undo-status updates, and manual pattern sync should gain ability equivalents. Recommendation execution no longer has parallel plugin REST endpoints.
- `WordPress/abilities-api#38` — In progress, High priority. Convenient filtering of registered abilities by category, namespace, and metadata. Flavor Agent registers under nine categories; once filtering is canonical, internal listings should migrate.
- `WordPress/ai#560` — Programmatic Encryption & Secrets Management. Per the [2026-05-20 AI contributor summary](https://make.wordpress.org/ai/2026/05/21/ai-contributor-weekly-summary-20-may-2026/), `@dkotter` is driving a standalone encryption utility built from prior Two-Factor plugin code, "to act as a proving ground for pitching a dedicated core Secrets Management API in 7.1." When that API lands, Flavor Agent's two true plaintext secret options (`flavor_agent_cloudflare_workers_ai_api_token`, `flavor_agent_qdrant_key` — created with `autoload=no`) are the encrypted-storage migration targets; audit the adjacent retrieval/embedding options in the seven-option dependency-change loop at the same time. Watch-only; no plugin code change until the API surface ships.
- `WordPress/ai#159` — Experiment initialization refactor. Per the [2026-05-20 AI contributor summary](https://make.wordpress.org/ai/2026/05/21/ai-contributor-weekly-summary-20-may-2026/), the team discussed "refactoring how plugin experiments are booted, moving initialization logic away from explicit `register()` methods toward standard, hook-based `init()` action routines." Sentiment leaned toward closing the PR pending further comments. If the hook-based pattern becomes canonical, `inc/AI/FlavorAgentFeature.php` (which extends `Abstract_Feature` and overrides `register()` to wire `enqueue_block_editor_assets`) is the touch point. Watch-only; no refactor until the PR resolves and the canonical pattern is documented.
- `WordPress/php-ai-client#100` — Add support for streaming. Open, milestoned for `php-ai-client` `1.4.0`; prior scaffolding was deliberately removed in `WordPress/php-ai-client#170` (merged 2026-01-16) so a clean interface — likely a standalone `StreamingTextGenerationModelInterface` per Felix Arntz — can emerge. Combined with the 2026-05-20 AI contributor decision to make streaming the WP 7.1 critical priority and the WP HTTP buffering constraint flagged in `WordPress/php-ai-client#237`, this is the upstream gate for Flavor Agent's streaming work. Full design and trigger condition at `docs/reference/streaming-recommendations-design.md`. Watch-only.

## Out Of Scope At Snapshot

Active board items below have no Flavor Agent collision and are listed only so refresh runs can confirm scope did not drift.

- Image and media: `#270`, `#288`, `#294`, `#302`, `#325`, `#388`, `#402`, `#421`, `#435`, `#238`
- Connector debugging or environment bugs: `#339`, `#387`, `#420`
- Experiment polish or documentation that does not touch shared subsystems: `#90`, `#145`, `#180`, `#181`, `#190`, `#203`, `#221`, `#225`, `#257`, `#270`, `#390`, `#391`, `#397`, `#425`
- Marketing or release: `#47`

If any of these get rescoped to share infrastructure with Flavor Agent (for example, `#190` site-wide content insights gaining an Inspector surface), promote them into the appropriate **Active Items By Collision Area** table.

The 2026-05-21 AI `1.0.0` release post names `#325` and `#238` as 1.1.0-or-future Media Editor exploration, and both issues are open Future Release items on GitHub. They remain out of scope because Flavor Agent has no media-library, media-editor, image-generation, focal-point, or crop-metadata surface.

## Action Implications For Flavor Agent

Each bullet is keyed to the board item that drives it. Strike through completed work when the corresponding upstream item ships.

1. ~~**`#437`, `#419`** — Request Logging shipped in AI `1.0.0`; subscribe and mirror future Site Agent review. The design is **coexistence, not consolidation**: core's Request Logging captures every AI Client HTTP call transparently via the SDK HTTP transporter decorator, so Flavor Agent should enrich `wpai_request_logs.context` with surface/scope/document/ability data via the `wpai_request_log_context` filter, stop persisting `request_diagnostic` rows when core logging is enabled, and keep the apply/undo journal plus the editor-inline `AIActivitySection` history in `inc/Activity/Repository.php`. Full design at `docs/reference/activity-log-request-logging-coexistence.md`.~~ **Done 2026-05-25 via Request Logging bridge: `RequestLoggingBridge` injects context, captures `wpai_request_logged` IDs, suppresses duplicate diagnostics, and the Activity admin can inspect the matching core row inline.**
2. **`#21`, `#354`** — Plan ability consolidation. Decide whether the 20 defined abilities under `flavor-agent/` collapse into a smaller router surface, or remain individual with helper/read abilities always available and recommendation abilities registered against per-surface gates once `#354` defines them. **Not yet covered by an existing workstream.**
3. ~~**`#37`, `#262`, `#27`, `#348`** — Stop investing in independent provider routing UX. Treat `inc/OpenAI/Provider.php` and the provider selector in `src/admin/settings-page-controller.js` as fallback-only once core ships unified routing or model bucketing.~~ **Done 2026-04-28 via Workstream C (Provider Ownership Migration). Direct chat fields removed; chat is fully Connectors-owned. Plugin retains direct embedding credentials only.**
4. ~~**`#345`, `#437`, `#419`** — Define the activity story now that Request Logging and usage-safeguards work shipped in the AI plugin. The decision lands on coexistence (see implication 1 above and `docs/reference/activity-log-request-logging-coexistence.md`): core Request Logging owns provider/model/tokens/cost observability; Flavor Agent's Activity Repository owns apply/undo state; the admin audit page cross-links into `Tools → AI Request Logs` rather than duplicating or retiring it. Cost and limit metering vocabulary should align with core's safeguards rather than inventing a parallel metering layer.~~ **Done 2026-05-25 via Request Logging bridge Phase 1-4; cost stays in core Request Logs while Flavor Agent shows linked request details and local apply/undo state.**
5. **`#192`** — Hold on bespoke prompt-template extension points. The canonical hook will land here; resist building Flavor Agent-specific extension points in the meantime. **Not yet covered by an existing workstream.**
6. **`WordPress/abilities-api#75`** — Watch for REST-as-ability unification. If accepted, decide whether the remaining activity persistence, undo-status, and manual pattern-sync REST adapters should gain ability equivalents. Recommendation consumers already use the Abilities API rather than parallel plugin REST endpoints.
7. **`WordPress/abilities-api#149`** — Once execution lifecycle filters land, move `inc/Activity/Repository.php` instrumentation onto them to avoid double-logging when callers hit core's filter set in addition to the Flavor Agent wrapper. **Not yet covered by an existing workstream.**
8. **WordPress 7.1 Guidelines API (`wp_register_guideline()`)** — Source guidelines into prompt assembly under `inc/LLM/` (`Prompt.php`, `TemplatePrompt.php`, `TemplatePartPrompt.php`, `NavigationPrompt.php`, `StylePrompt.php`, `WritingPrompt.php`) so Flavor Agent recommendations respect site-wide guidelines as soon as core's API ships. **Bridge implemented 2026-04-28 via Workstream D: Flavor Agent now has a core-first repository bridge, prompt formatter, and settings migration framing. Keep watching for the public `wp_register_guideline()` API and core's final write/defaults model before adding write migration.**
9. **`#467`, `#595`** — Connector Approval request-time handling is implemented locally, but final post-approval runtime success remains a smoke gate. Keep the validation artifact honest when provider configuration returns `missing_text_generation_provider`, and re-run the manual approval path when the local stack has representative text-generation provider state.
10. **WP 7.1 native streaming (`WordPress/php-ai-client#100`, AI plugin 7.1 cycle)** — The [2026-05-20 AI contributor summary](https://make.wordpress.org/ai/2026/05/21/ai-contributor-weekly-summary-20-may-2026/) named native streaming the critical priority for the 7.1 cycle. The php-ai-client tracking issue is milestoned for `1.4.0` and prior scaffolding was deliberately removed in `php-ai-client#170` so a clean interface can emerge. Flavor Agent surface adoption matrix, transport options (chunked HTTP vs polling), schema/review-signature reconciliation, and the "Hold posture until 1.4.0 RC + AI plugin streaming Experiment lands in trunk" trigger condition all live in `docs/reference/streaming-recommendations-design.md`. Do not add `is_streaming_supported()` stubs or streaming endpoints to the codebase until the upstream primitives stabilize. **Not yet covered by an existing workstream.**

### Workstream History

The earlier overlap-remediation plan tracked these workstreams; results have been folded back into the live source tree.

| Workstream                          | Status                                                                 | Driven by these board items                                                                                                                                           |
| ----------------------------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A (Pattern Surface Reset)           | Done 2026-04-23                                                        | Pattern API stabilization (no specific item on the board today)                                                                                                       |
| B (Block Inspector Ownership Reset) | Done 2026-04-23                                                        | None active on the board today                                                                                                                                        |
| C (Provider Ownership Migration)    | Done 2026-04-28                                                        | `WordPress/ai#27`, `#37`, `#262`, `#342`, `#348`, `#441`                                                                                                              |
| D (Guidelines Bridge and Migration) | Read bridge implemented 2026-04-28; write/public API migration pending | WordPress 7.1 Guidelines API (Trac, not on this board); bridge reads `wp_guideline` / `wp_guideline_type` now and defers write migration until the public API settles |
| E (Settings Screen Modernization)   | Pending                                                                | `WordPress/ai#197`, `#451`, `#472`, `#428`, `#323` (these are core's own settings UX, but they pressure Flavor Agent's settings to align)                             |

Action implications 1, 2, 4, 5, and 7 above describe upstream pressures with no corresponding workstream yet. When any of them moves from "watch" to "act", record the workstream in `docs/SOURCE_OF_TRUTH.md` or the relevant feature doc rather than tracking implementation details here.

## Related References

- `docs/SOURCE_OF_TRUTH.md` — canonical product definition, current state, and architectural guardrails.
- `docs/FEATURE_SURFACE_MATRIX.md` — every shipped surface, gate, and apply/undo path.
- `docs/reference/abilities-and-routes.md` — REST and Abilities contract map.
- `docs/reference/provider-precedence.md` — backend selection, credential fallback chain, and Connectors-first runtime activation.
