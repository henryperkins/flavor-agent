# WordPress AI Roadmap Tracking

This document tracks the [WordPress AI Planning & Roadmap project](https://github.com/orgs/WordPress/projects/240) and the overlap between active upstream work and Flavor Agent surfaces.

Use it when you need to answer:

- which upstream AI initiative collides with which Flavor Agent surface
- which board items are imminent (already a PR) versus directional (still in discussion)
- what to refactor, retire, or hand back to core when a given board item ships

## Source And Refresh

- Project: `https://github.com/orgs/WordPress/projects/240` (WordPress AI Planning & Roadmap)
- Public board: yes (read-only access requires `gh auth refresh -s read:project`)
- Snapshot date: 2026-06-20 for the full project-board counts, using the maintained four-file workspace snapshot in `/home/dev/roadmaptrac/` (`wordpress-ai-roadmap.md`, `wordpress-ai-open-issues.md`, `wordpress-ai-planned-work.md`, and `wordpress-ai-cross-repo-dependencies.md`), regenerated via `/home/dev/roadmaptrac/wp-ai-roadmap-refresh.sh`.
- Snapshot shape: 245 items total: 180 Done plus 65 not-Done items (54 open issues and 11 PR cards — 10 genuinely open plus the stale merged card #484). Status distribution is Done 180, In discussion / Needs decision 22, In progress 21, Backlog 10, To do 6, Needs review 5, Triage 1.
- AI plugin release overlay refreshed: 2026-07-20. `WordPress/ai` release `1.2.0` is now the latest shipped release (2026-07-14, milestone #19), superseding `1.1.0` (2026-07-01, milestone #21) and the `1.0.2` patch milestone (2026-06-16); no successor milestone date was re-verified in this pass. The project-board counts elsewhere in this doc remain the 2026-06-20 snapshot — only the release train was re-verified, on 2026-07-02 for `1.1.0` and 2026-07-20 for `1.2.0`. `1.2.0` requires no Flavor Agent code change; that finding was taken from the `1.1.0...1.2.0` file diff rather than the changelog, and the reasoning is recorded in the `1.2.0` overlay below. `1.1.0` shipped two items with direct Flavor Agent bearing — the Key Encryption experiment (`#560`) and the developer-settings explicit-Save change (`#761`, no FA code change, smoke green) — plus the renamed `core/read-settings` ability (`#691`/`#806`) and character-based content gating (`#581`/`#802`); see the `1.1.0` overlay and items table below. `WordPress/ai#595` ("Better connector approval matching") shipped in `1.0.1` on 2026-05-26, so Flavor Agent's remaining Connector Approval work is post-ship smoke validation, not waiting for the upstream PR.
- Release-cycle grounding: WordPress 7.0 is the current AI-stack floor for Flavor Agent. The relevant platform facts are the AI Client, Client-Side Abilities API, Connectors screen, Connectors API, DataViews/DataForms, and MCP/Abilities integration path.
- Board ownership: the board is operationally the `WordPress/ai` showcase-plugin development tracker. The full board has 244 of 245 items in `WordPress/ai` plus one Google provider item (`WordPress/ai-provider-for-google#23`); `WordPress/abilities-api#84` is still open upstream (milestone Later) but is no longer on Project #240, so it is now a removed-board reference rather than a counted item. A separate curated cross-repo watchlist tracks 16 Gutenberg + abilities-api dependencies (10 open / 3 closed / 3 merged) outside the Project #240 totals.

## 2026-06-15 Governance Read

The upstream WordPress AI program is no longer just proving that individual AI helpers can run in wp-admin. Its roadmap is converging on a platform split:

- **Core and the AI plugin own shared AI infrastructure**: Connectors, provider discovery, AI Request Logs, model/provider routing, ability registration, MCP exposure, and any eventual AI Management layer for permissions, metering, budgets, and routing.
- **Feature experiments own task UX**: title/excerpt/alt text, content classification/resizing/summarization, editorial updates, Type Ahead, C2PA/provenance, content generation, media experiments, and early site-agent concepts.
- **Governance remains unsettled upstream**: `WordPress/ai#348` (AI Management), `#354` (Abilities exposure controls), `#21` (scaling thousands of abilities), and `#40` (core ability set) are still design questions, not settled runtime contracts.
- **Automattic/agents-api is the emerging agent runtime substrate to watch**: it sits above AI Client and Abilities API for agent registration, conversation loops, tool mediation, memory/transcripts/sessions, principals, channels, workflows, and pending-action envelopes; it still leaves product UX, concrete tools, prompt policy, storage/materialization policy, and mutation apply/undo semantics to consumers.

For Flavor Agent, that strengthens rather than weakens the positioning:

- The recommendation surfaces are the demonstration; the governance layer is the product.
- Flavor Agent should not rebuild broad provider settings, global usage metering, model routing, or site-wide AI permissions once those belong to Core / the AI plugin.
- Flavor Agent should keep owning the mutation lifecycle it can prove today: bounded proposals, human review for structural/theme changes, server-side attribution, freshness checks, and drift-safe undo.
- When upstream AI Management or surface-level ability controls ship, treat them as an outer policy plane. Flavor Agent's inner contract remains the per-surface apply/review/undo journal for WordPress changes it mediates.
- Site Agent / AI Workspace roadmap items validate the need for this layer: external or conversational agents can propose actions, but Flavor Agent's shipped governance stance is that approval stays in WordPress and never becomes an agent ability.

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
gh api repos/WordPress/ai/milestones/21   # 1.1.0 (was #20 before the 1.0.2 patch carve-out)
gh api 'repos/WordPress/ai/issues?milestone=21&state=all&per_page=100'
gh api repos/WordPress/ai/milestones/19
gh api 'repos/WordPress/ai/issues?milestone=19&state=all&per_page=100'
```

Then update the **Snapshot date**, the **AI Plugin Release Milestone Overlay**, the **Release-Train Items To Watch First** section if any new PR has appeared, and the **Active Items By Collision Area** tables. Move shipped items to **Out Of Scope** or delete them. When a board item ships and a Flavor Agent integration step closes, strike it through in **Action Implications**.

When this doc is updated, run `npm run check:docs` if any other live contributor doc (CLAUDE.md, AGENTS.md, copilot-instructions.md, README.md, the source-of-truth doc, the feature surface matrix, or the existing reference docs) was touched in the same change.

## Board Shape At Snapshot

| Status                         | Count |
| ------------------------------ | ----- |
| Triage                         | 1     |
| Backlog                        | 10    |
| In discussion / Needs decision | 22    |
| To do                          | 6     |
| In progress                    | 21    |
| Needs review                   | 5     |
| Done                           | 180   |

| Dimension | Count / read |
| --- | --- |
| Total items | 245 = 174 PRs + 71 issues |
| Open work | 65 = 54 open issues + 11 PR cards (10 open + stale merged #484) |
| By repo | `WordPress/ai` 244, `WordPress/ai-provider-for-google` 1 (`abilities-api#84` removed from board) |
| Latest shipped | `WordPress/ai` `1.2.0` (2026-07-14, milestone #19) |
| Active / next release | Not re-verified in the 2026-07-20 release-train pass; milestone counts not re-snapshotted since the 2026-06-20 board snapshot |
| Real roadmap backlog | `Future Release`: 47 (41 issues + 6 PRs) |

The headline strategic read: WordPress core's AI direction is being prototyped almost entirely inside the core AI plugin. Treat the `WordPress/ai` release milestones as the source of truth for what the plugin is targeting next, and the project board as the broader pressure map for editor, admin, provider, governance, and ability surfaces.

## AI Plugin Release Milestone Overlay

This overlay is separate from the project-board status tables above. It records the AI plugin release train plus any targeted currentness checks made after the broader 2026-06-20 project-board snapshot.

AI plugin `0.9.0` was verified in the local test container on 2026-05-09. Flavor Agent now treats the AI plugin Developer Tools per-feature option `wpai_feature_flavor-agent_field_developer` as the canonical feature-level provider/model preference when present, while explicit per-call provider arguments keep highest precedence.

AI plugin `0.9.0` also shipped adjacent experiments and surfaces including Comment Moderation, Content Resizing, WP-CLI alt-text plumbing, and settings UI work. The only required Flavor Agent code integration from this release is honoring the per-feature developer provider/model setting; the other shipped surfaces remain watch items because Flavor Agent does not call those experiments directly.

AI plugin `1.0.0` shipped on 2026-05-19 and introduced Request Logging in `WordPress/ai#437` and Connector Approvals in `WordPress/ai#467`, plus no-provider and missing-provider handling that points users toward configuring an AI Connector. AI plugin `1.0.1` shipped the Connector Approval caller-matching fix in `WordPress/ai#595`. Flavor Agent now handles request-time Connector Approval denials by preserving the AI plugin's connector/caller metadata and showing an approval notice in the editor. Runtime verification is now a local smoke gate against `1.0.1+` behavior: pending approvals should record `flavor-agent/flavor-agent.php` rather than `ai/ai.php` or the provider connector.

AI plugin `1.0.0` also integrated Alt Text generation into Gutenberg's experimental Media Editor. That keeps the media-editor watch warm, but it does not create new Flavor Agent product work because this plugin does not own media editing, image generation, focal-point selection, or crop metadata surfaces. Open issues `WordPress/ai#325` and `WordPress/ai#238` remain Future Release/In progress media work, not a Flavor Agent collision.

AI plugin `1.1.0` shipped on 2026-07-01 (milestone #21). Two shipped items have direct Flavor Agent bearing. First, the **Key Encryption** experiment (`WordPress/ai#560`) is an opt-in experiment that encrypts the AI plugin's own AI Connector API keys at rest — blanking `connectors_ai_*_api_key` and storing ciphertext under `_secret_ai/*` via a vendored, namespaced copy of `ericmann/displace-secrets-manager`, auto-decrypting when the experiment/AI/plugin is disabled. It is explicitly a proving ground for a *future core* Secrets Management API (Trac #64789), not a public API other plugins can call, so it does not yet cover Flavor Agent's own plaintext secrets — the `STATUS.md` secrets-migration watch item stays open, but the proving ground is now live code rather than a PR. Second, the developer-settings panel now requires an explicit **Save** (`WordPress/ai#761`) before Provider/Model persist (Reset-to-default auto-persists); this is a save-timing UX change only — the `wpai_feature_flavor-agent_field_developer` option name and its `{provider, model}` shape are unchanged, so `FeatureModelSelection::get()` and `WordPressAIClient::resolve_provider_model_selection()` need no change (verified 2026-07-02: `vendor/bin/phpunit tests/phpunit/WordPressAIClientTest.php`, 36 tests / 204 assertions green). The release also renamed the new settings ability to `core/read-settings` (`#691`/`#806`), switched content feature-gating to a locale-aware character-based count (`#581`/`#802`), added the `wpai_has_image_generation_support` filter (`#748`), and shipped the **Type Ahead** experiment (`#151`/`#776`); none of those require Flavor Agent code changes today.

AI plugin `1.2.0` shipped on 2026-07-14 (milestone #19) and requires **no Flavor Agent code change**. This read was taken from the `1.1.0...1.2.0` file diff rather than the changelog, because the changelog understates two of the four collision areas. Findings by area:

- **Abilities / settings exposure.** `1.2.0` extended `includes/Abilities/Show_In_Abilities.php`, which polyfills the `show_in_abilities` flag by filtering `register_setting_args` and `register_post_type_args` — global filters that see every plugin's registrations, including Flavor Agent's. It is nonetheless safe: `mark_setting()` marks an option only when `isset( $settings_map[ $option_name ] )`, and `settings_map()` is an explicit allowlist kept 1:1 with core's `register_initial_settings()` (`blogname`, `siteurl`, `admin_email`, …). No `flavor_agent_*` key can match, so `core/read-settings` (`#852`, `#856`) cannot surface `flavor_agent_cloudflare_workers_ai_api_token` or `flavor_agent_qdrant_key`. **This holds by omission, not by an assertion** — never add a blanket `show_in_abilities` to `inc/Admin/Settings/Registrar.php`; doing so would expose provider credentials to any MCP client running as an administrator. A guard test pinning that invariant is proposed but not yet written.
- **New core read abilities.** `core/read-content` (`#739`) and `core/read-users` (`#774`) create no overlap requiring consolidation. `post_types_map()` curates `core/read-content` to `post` and `page` only — `wp_block` is excluded — so `flavor-agent/list-synced-patterns` and `flavor-agent/get-synced-pattern` are not redundant even for raw fetching, and their `syncStatus` derivation has no core equivalent. Flavor Agent registers no user-query surface at all (`get_users()` / `WP_User_Query` appear zero times in `inc/`), so `core/read-users` neither duplicates nor replaces anything. Separately, `recommend-content` and `recommend-post-blocks` collect post content server-side by design (`ServerCollector`, `PostBlocksContextCollector`) because freshness signatures and drift checks require server-collected state; a core read ability serves the agent, not that contract, and cannot absorb them.
- **Logging.** `includes/Logging/` has **zero changed files** across `1.1.0...1.2.0`. The `wpai_request_logs` schema is unchanged, so the dual-logging contract and `request.ai.requestLogId` in `docs/reference/activity-log-request-logging-coexistence.md` are intact. It also confirms the symlink source-attribution fix tracked at that doc's line 180 has still not landed upstream, so Flavor Agent's defense-in-depth capture bridge stays load-bearing.
- **Connectors / Approvals.** No `includes/Connectors/` changes. The only `Connector_Approval.php` change is experiment-description copy, but it now states plainly that enabling the experiment "will block all AI interactions, including those from the AI plugin, until an approved connector is available." That behavior is not new and forces no code change — request-time denial handling already exists — but it means an operator who enables Approvals takes every Flavor Agent recommendation surface dark until a connector is approved. Worth carrying into operator docs; see the Connector Approvals watch item in `docs/reference/current-open-work.md`.

Three further items are inert for Flavor Agent: the "Advanced settings" toggle (`#842`) hides per-feature config fields in Settings → AI but leaves the on/off toggle visible, and `inc/AI/FlavorAgentFeature.php` declares no inline fields; the new `wp_ai_client_default_request_timeout` filter (`#862`) is an unused optional lever, since every `REQUEST_TIMEOUT` in `inc/` belongs to Flavor Agent's own HTTP clients rather than the AI Client path; and the Suggest Reply experiment (`#724`) plus bulk "Generate AI Summary" (`#650`) touch comment-moderation and list-table surfaces Flavor Agent does not own. Honesty bound: this overlay records a static diff read as of 2026-07-20. Flavor Agent has not been run against `1.2.0`, and `#830`'s notice implementation in `src/`/`routes/` was not inspected — no-collision there is inferred from Flavor Agent rendering its notices on its own screens (`inc/Admin/ActivityPage.php`, `inc/AI/FeatureBootstrap.php:133`). Closing that gap means bumping the local runtime to `1.2.0` and running `node scripts/verify.js --skip-e2e` plus the open Connector Approvals smoke gate.

| Milestone URL                | Plugin version | State                         | Read for Flavor Agent                                                                                                                                                                                                                                     |
| ---------------------------- | -------------- | ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WordPress/ai` milestone #17 | `0.9.0`        | Verified locally 2026-05-09 | Developer provider/model preference, Comment Moderation, Content Resizing, settings UI, and early provider-model work became baseline context. |
| `WordPress/ai` milestone #7  | `1.0.0`        | Released 2026-05-19 | Request Logging and Connector Approvals shipped; provider/onboarding errors became more explicit; client-side Abilities API usage became part of the AI plugin baseline. |
| `WordPress/ai` milestone #18 | `1.0.1`        | Released 2026-05-26 | Connector Approval caller matching (`#595`) shipped. Flavor Agent should validate post-approval behavior against this shipped baseline when a representative provider stack is available. |
| `WordPress/ai` 1.0.2 milestone | `1.0.2`        | Released 2026-06-16 | Patch milestone carved out of 1.1.0 (11 items): Request Log copy-feedback (`#699`) and header-overlap (`#704`) fixes plus CJK/empty-state/button-sizing/translation stabilization (`#571`, `#578`, `#390`, `#391`, `#701`, `#721`) and Editorial Updates reload matching (`#678`). All AI-plugin-internal polish; no new Flavor Agent integration. |
| `WordPress/ai` milestone #21 | `1.1.0`        | Released 2026-07-01 | Two items with direct Flavor Agent bearing: the Key Encryption experiment (`#560`) and developer-settings explicit-Save (`#761`, no FA code change — smoke green 2026-07-02). Also renamed `core/read-settings` ability (`#691`/`#806`), character-based content gating (`#581`/`#802`), Type Ahead experiment (`#151`/`#776`), `wpai_has_image_generation_support` filter (`#748`), guest-comment moderation toggle (`#751`), and Request Log/UX stabilization. See the `1.1.0` narrative above and the items table below. |
| `WordPress/ai` milestone #19 | `1.2.0`        | Released 2026-07-14 | No Flavor Agent code change, established from the `1.1.0...1.2.0` file diff. `show_in_abilities` polyfill (`#852`) is allowlist-scoped and cannot reach `flavor_agent_*` options; `core/read-content` (`#739`) is curated to `post`/`page`, so the synced-pattern abilities stay non-redundant; `core/read-users` (`#774`) has no local counterpart; `includes/Logging/` untouched, so the symlink source-attribution fix is still unlanded and the capture bridge stays load-bearing; `Connector_Approval` changed only in description copy, which now states that enabling it blocks all AI interactions until a connector is approved. See the `1.2.0` narrative above. (The pre-release entry here, derived from the 2026-06-20 board snapshot, predicted C2PA manifest detection and agentic Refine work that did not ship in this milestone — treat board-derived milestone contents as directional until the release lands.) |

## Planned But Not Shipped Work To Track

The June 20 planned-work snapshot splits the 65 not-Done items into three tiers:

| Tier | Items | Flavor Agent read |
| --- | --- | --- |
| Dated releases (`1.1.0`, `1.2.0`) | 15 not-Done items (11 issues + 4 PRs) | Mostly stabilization and review work. Track for compatibility, not new Flavor Agent product scope. |
| Planned backlog (`Future Release`) | 47 not-Done items (41 issues + 6 PRs) | This is where the strategic overlap lives: Abilities/MCP scale, AI Management, provider discovery, prompt/template extension points, Agents API substrate, native vector search/RAG (`#683`), Site Agent, AI Workspace, and content-generation experiments. |
| Unmilestoned / straggler | 3 not-Done items | The stale merged card `#484` (0.9.0) plus two board-new unmilestoned In-progress bug fixes — `#750` (guest-comment moderation) and `#752` (Request Log 30-day window). Neither bug fix collides with Flavor Agent. |

Contribution and local-planning priority:

1. **Review and smoke test near-release PRs before building parallel local features.** Examples: locale-aware word/character counting, DataViews translations, connector enable/disable, uninstall cleanup, Type Ahead, C2PA Monitor, and alt-text URL matching.
2. **Treat C2PA/provenance as artifact-provenance infrastructure, not the whole Attest layer.** Upstream Content/Image Provenance work can sign content artifacts and C2PA Monitor can preserve incoming credentials; Flavor Agent's owned lane is governed-change attestation for mutations it mediates. C2PA only creates Flavor Agent product work if upstream provenance rows, verification semantics, or C2PA emission become a shared convention that FA can attach to its signed governed-change statements.
3. **Treat WebMCP and Service Accounts as external-agent pressure, not stable API input.** They validate Flavor Agent's external-agent parity boundary, but WebMCP remains speculative and Service Accounts remain exploratory.
4. **Keep ability sanitization on the watch list.** If `WordPress/ai#481` or an Abilities API execution filter lands, re-check Flavor Agent ability schemas and the `Support\NormalizesInput` / REST-normalization split.
5. **Do not preempt core's AI Management layer.** `#348`, `#354`, and `#21` are the governance handoff points. Flavor Agent should be ready to plug into them, not ship a competing global permissions/metering/routing plane.

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
| `WordPress/ai#437` | AI Request Logging                                                                                   | Merged PR    | Sits at a different layer than `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, and `src/admin/activity-log.js`. Implemented for coexistence in `docs/reference/activity-log-request-logging-coexistence.md`: enrich core's `wpai_request_logs.context` via the `wpai_request_log_context` filter, dual-log Flavor Agent's `request_diagnostic` rows alongside core by default (opt-out via the AI Activity Dual Logging setting), keep apply/undo rows local. |
| `WordPress/ai#457` | Improvements in Connectors and AI flow                                                               | Open issue   | Pressures connector-readiness copy and plugin-owned fallback settings.                                                   |
| `WordPress/ai#472` | Update settings page to use `@wordpress/ui` components                                               | Open PR      | Pressures visual alignment for Flavor Agent's admin settings page.                                                       |
| `WordPress/ai#481` | Ensure any `sanitize_callback` in Abilities input schema is executed                                 | Open PR; Future Release | Pressures `inc/Abilities/*` schema design and input normalization expectations.                                  |
| `WordPress/ai#497` | For image gen, move guidelines from system instructions to prompt                                    | Open PR      | Watch for final Guidelines prompt-placement semantics before changing Flavor Agent prompt assembly again.                |

Flavor Agent-relevant `1.0.0` items:

| Upstream artifact                                                                                  | Flavor Agent counterpart                                                   | Implication                                                                                                                     |
| -------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `WordPress/ai#21` — How to best support hundreds or thousands of abilities                         | `inc/Abilities/Registration.php` defines 32 abilities (7 recommendation + 13 helper + 1 docs search + 5 preview siblings + 6 external-apply) | Ability consolidation or per-surface registration is now a Future Release governance concern, not a 1.0-era release blocker.                                      |
| `WordPress/ai#27` — Developer support for pre-configured AI providers                              | Provider precedence and settings docs                                      | Flavor Agent should keep plugin-owned chat/provider UX fallback-only and defer to core AI plugin configuration where available. |
| `WordPress/ai#33` — Advanced configuration tools for power users                                   | Settings page and provider diagnostics                                     | Avoid building parallel advanced-provider configuration that will be superseded by AI-plugin settings.                          |
| `WordPress/ai#182` — Graceful degradation when no AI provider is configured                        | `CapabilityNotice`, surface capability flags                               | Align disabled-state and remediation copy with core AI plugin behavior.                                                         |
| `WordPress/ai#183`, `#428`, `#450` — onboarding and provider setup guidance                        | Settings/connectors navigation                                             | Keep Flavor Agent onboarding lightweight and link out to the core AI plugin/Connectors flow.                                    |
| `WordPress/ai#184`, `#185` — developer examples for AI Experiments, Abilities API, and MCP Adapter | Flavor Agent docs and ability contracts                                    | Use upstream examples as the canonical extension shape; avoid Flavor Agent-only ability conventions where possible.             |
| `WordPress/ai#324` — Refine collaborative and agentic editorial workflows                          | Content and Inspector recommendation panels                                | Watch for editor-native collaboration/agent workflows that could absorb parts of the content recommendation surface.            |
| `WordPress/ai#342`, `#343` — plugin access permissions for connected providers                     | `inc/Abilities/SurfaceCapabilities.php`, provider readiness, admin notices | Permission controls become a `1.0.0` target; Flavor Agent must not assume connector availability implies plugin authorization.  |
| `WordPress/ai#437` — Request Logging                                                              | `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, `src/admin/activity-log.js` | Core AI request logging now exists. Coexistence (not consolidation): enrich `wpai_request_logs.context` via the `wpai_request_log_context` filter, dual-log `request_diagnostic` rows alongside core by default (opt-out via AI Activity Dual Logging), keep apply/undo rows local — see `docs/reference/activity-log-request-logging-coexistence.md`. |
| `WordPress/ai#467` — Connector Approvals                                                          | `inc/LLM/WordPressAIClient.php`, request-error details, per-surface notices | Shipped and integrated locally (`wpai_connector_not_approved` detection, structured `connectorApproval` error data, admin-only `Open approvals page` action). **Post-1.0.1 follow-up:** with a configured text-generation provider and Connector Approval enabled, re-run the end-to-end smoke against AI plugin `1.0.1+`; verify the pending option records `flavor-agent/flavor-agent.php` (not `ai/ai.php` or the provider connector). Capture outcome in `docs/validation/2026-05-21-connector-approvals-smoke.md`. |
| `WordPress/ai#452` — Content Classification relevance                                              | `inc/Abilities/ContentAbilities.php`, content panel taxonomy suggestions   | Content classification relevance work may become the canonical taxonomy/classification layer.                                   |
| `WordPress/ai#482` — client-side Abilities API                                                     | Editor-side ability access and hydration assumptions                       | Merged in `1.0.0`; keep Flavor Agent's abilities bridge aligned with core hydration instead of adding parallel REST execution paths. |
| `WordPress/ai#486` — developer settings mode for desired provider/model per feature                | Provider/model diagnostics and settings                                    | Merged in `0.9.0` and already honored by `WordPressAIClient::chat()`; do not create a competing Flavor Agent model pinning UI.  |

Flavor Agent-relevant `1.1.0` items (shipped 2026-07-01):

| Upstream artifact | Flavor Agent counterpart | Implication |
| ----------------- | ------------------------ | ----------- |
| `WordPress/ai#560` — Key Encryption experiment | `flavor-agent.php` secret options (`flavor_agent_cloudflare_workers_ai_api_token`, `flavor_agent_qdrant_key`), `STATUS.md` secrets watch item | Shipped as an opt-in experiment that encrypts **only the AI plugin's own** connector keys (`connectors_ai_*_api_key` → `_secret_ai/*`) via a vendored, namespaced `ericmann/displace-secrets-manager`; auto-decrypts when the experiment/AI/plugin is disabled. It is a proving ground for a *future core* Secrets Management API (Trac #64789), not a public API. No FA change today: FA's two plaintext secrets are out of scope, and adopting the AI plugin's internal vendored classes is not a supported path. Keep the `STATUS.md` watch item — the proving ground is now live code, not a PR. |
| `WordPress/ai#761` — developer-settings explicit Save | `inc/AI/FeatureModelSelection.php`, `inc/LLM/WordPressAIClient.php:528` | Save-timing UX change only; the `wpai_feature_flavor-agent_field_developer` option name and `{provider, model}` shape are unchanged. No FA change — smoke green 2026-07-02 (`WordPressAIClientTest.php`, 36 tests / 204 assertions). Under the new draft/Save flow an unsaved selection never persists, so `resolve_provider_model_selection()` reads empty and uses its default routing — strictly safer than the old auto-save-on-change. |
| `WordPress/ai#691` / `#806` — new `core/read-settings` ability | `inc/Abilities/Registration.php`, `@wordpress/core-abilities` hydration | New core-owned read ability (renamed from `core/settings`). FA references neither name, so no break. Adjacent to the `#40` core-abilities surface; keep FA's abilities bridge aligned with core hydration rather than shadowing core reads. |
| `WordPress/ai#581` / `#802` — character-based minimum-content gating | Content-length gating in the content recommender surface | Upstream feature-availability gating is now locale-aware character counting instead of word counting. Watch/mirror only if FA gates a surface on content length; no current FA change. |
| `WordPress/ai#151` / `#776` — Type Ahead experiment | `src/inspector/*` panel recommenders | Shipped. Cursor-inline ghost-text pattern, distinct from FA's panel-driven recommendations; supports provider/model overrides and Guidelines. Glance-only; no collision today. |
| `WordPress/ai#748` — `wpai_has_image_generation_support` filter | (none) | Lets third parties claim image-generation support without an API key. Out of FA scope (no image-generation surface). |

## Release-Train Items To Watch First

AI plugin `1.0.1` is now shipped. For Flavor Agent, the urgent release-train items are no longer "wait for 1.0.0"; they are the follow-through decisions created by shipped Request Logging and Connector Approvals, the post-`1.0.1` caller-attribution smoke, and the still-open Ability input-schema sanitization work in `WordPress/ai#481`. The larger architecture items are Future Release governance and agentic-workflow pressure, not current editor-surface migration requirements.

Direct collisions with Flavor Agent:

| Upstream artifact or item                                                 | Flavor Agent counterpart                                                                                                                              | Implication                                                                                                                                                                                                                                                                                     |
| ------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WordPress/ai#437` — AI Request Logging (merged in `1.0.0`)               | `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, `src/admin/activity-log.js`, `Settings > AI Activity`                                   | Sits at the AI Client HTTP transporter layer; Flavor Agent's repository sits at the editor-apply layer. Coexistence implementation at `docs/reference/activity-log-request-logging-coexistence.md`: subscribe to the `wpai_request_log_context` filter to enrich `wpai_request_logs` with Flavor Agent surface/scope/document, dual-log `request_diagnostic` writes alongside core by default (opt-out via AI Activity Dual Logging), keep apply/undo rows and the editor-inline `AIActivitySection` history local. |
| `WordPress/ai#294` / `#302` / `#421` — C2PA content, image, and intake provenance | `inc/Attestation/*`, `inc/REST/AttestationController.php`, `Settings > AI Activity`, `docs/reference/ring-iii-attestation-design.md` | Different Attest layer. Upstream C2PA work signs or preserves content credentials for text/images; Flavor Agent signs governed changes it applies to WordPress state. Keep FA statements public-safe, self-signed, and verifiable through its own routes today; add C2PA emission later only as an output format for the same governed-change statement, not as a replacement for the review/apply/undo chain. |
| `WordPress/ai#467` / `#595` — Connector Approvals and caller attribution  | `inc/LLM/WordPressAIClient.php`, `src/store/request-error-details.js`, per-surface `CapabilityNotice` rendering                                       | Local request-time denial handling exists and `#595` is merged in AI plugin `1.0.1`; final runtime approval success remains a provider-state smoke item. Do not couple editor bootstrap to AI plugin approval-store internals.                                                                                    |
| `WordPress/ai#345` — usage safeguards (closed in `1.0.0`)                 | `inc/Activity/*`, `Support\MetricsNormalizer`, admin audit summaries                                                                                  | Align cost/limit/visibility concepts with core AI plugin safeguards instead of inventing a parallel metering vocabulary.                                                                                                                                                                        |
| `WordPress/ai#481` — Ability schema sanitization                          | `inc/Abilities/*`, `Support\NormalizesInput`, REST argument normalization                                                                             | Re-check ability input schemas once upstream callback execution lands; avoid duplicate or divergent sanitization paths between REST and Abilities execution.                                                                                                                                    |
| `WordPress/ai#155` — Comment Moderation experiment (merged in `0.9.0`)    | `inc/Abilities/ContentAbilities.php`, `inc/LLM/WritingPrompt.php`, content recommendation panel                                                       | Adjacent surface, not a direct UI collision. Use it as the canonical Experiment + Ability pattern for content-classification workflows.                                                                                                                                                         |
| `WordPress/ai#419` — Site Agent / Natural Language Admin (Future Release) | `inc/Abilities/Registration.php`, the Inspector and Site Editor recommendation panels under `src/inspector/`, `src/templates/`, `src/template-parts/` | Different surface (admin chat versus editor inspector), but same conceptual primitive: ability invocation that mutates the site, with logging. The Site Agent remains the likely canonical "agentic mutation" surface; Flavor Agent panels remain the editor-bound complement until that ships. |
| `Automattic/agents-api` — WordPress-shaped agent runtime substrate         | `inc/Abilities/Registration.php`, `inc/Activity/Repository.php`, `docs/reference/governance-layer.md`                                                 | Names a likely substrate for future admin/chat agents: agent identity, conversation loop, tool mediation, memory/transcripts/sessions, execution principals, workflows, channels, and pending-action envelopes. Treat it as optional upstream context for now, not a Flavor Agent dependency. If Flavor Agent later integrates, feature-detect `wp_register_agent()` / `wp_agents_api_init`, expose existing abilities as tools, and keep product UX plus mutation apply/approve/undo policy local. |

## High-Priority Strategic Items

These are the only items currently flagged `Priority: High` on the board. Every one touches Flavor Agent.

| #                 | Title                                                   | Status        | Flavor Agent collision                                                                                                                                                                                                                                                                                                                                                    |
| ----------------- | ------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WordPress/ai#21` | How to best support hundreds or thousands of abilities  | In discussion | `inc/Abilities/Registration.php` defines 32 abilities under the `flavor-agent/` namespace (7 recommendation + 13 helper + 1 docs search + 5 preview siblings + 6 external-apply). Helper/read abilities and preview siblings register whenever the Abilities API and AI plugin contracts exist; recommendation and external-apply abilities also require the WordPress AI feature gate. Ten externally-useful helpers and all five preview siblings are MCP-public on the universal default bridge; three editor-internal helpers stay Abilities-API-only. The issue argues 1-to-1 ability->tool exposure breaks model tool selection at scale and proposes a "Layered Tool Pattern". Likely outcome: Flavor Agent consolidates into a smaller "router" ability surface, or registers abilities only on opt-in surfaces under issue 354. Per the [2026-05-20 AI contributor summary](https://make.wordpress.org/ai/2026/05/21/ai-contributor-weekly-summary-20-may-2026/), `@justlevine` is the named owner; the action item is to document emerging ecosystem trends and, if necessary, open an expansive Trac ticket for broader core developer feedback. The "find abilities" discovery workflow inside the AI companion plugin is the team's named next technical milestone, mirroring the third-party "find tool" pattern. |
| `WordPress/ai#27` | Add developer support for pre-configured AI providers   | In discussion | Flavor Agent now routes chat through the WordPress AI Client / Connectors runtime and keeps only a hidden `flavor_agent_openai_provider` compatibility value. Embeddings are configured through Cloudflare Workers AI fields because Connectors does not expose embedding generation yet.                                |
| `WordPress/ai#36` | Validate integration patterns across multiple Abilities | To do         | Defines the canonical "right way" to compose abilities across providers. `inc/LLM/ChatClient.php` and the per-prompt classes under `inc/LLM/` diverge from whatever pattern this issue settles on.                                                                                                                                                                        |
| `WordPress/ai#37` | MCP usage across features and request routing           | In discussion | Goal: MCP-based routing for at least one feature plus a reusable adapter. `inc/LLM/ChatClient.php` and `inc/OpenAI/Provider.php` do provider routing inside the plugin; once core ships an MCP routing adapter, Flavor Agent's routing becomes parallel infrastructure.                                                                                                   |
| `WordPress/ai#47` | Low-/no-tech educational content for WP 6.9 launch      | In discussion | Marketing-only; no Flavor Agent collision.                                                                                                                                                                                                                                                                                                                                |

## Active Items By Collision Area

Each table below maps board items to the Flavor Agent code paths they pressure.

### Logging, Observability, And Usage Safeguards

Compete with `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`, `inc/Activity/Permissions.php`, `src/admin/activity-log.js`, and `src/admin/activity-log-utils.js`. Note for `#732`: Flavor Agent's `RequestLoggingBridge` enriches and links the core request-log row that the AI Client HTTP transporter writes, so for providers using a non-SDK/custom transport core may have no row to link — Flavor Agent still dual-logs its own `request_diagnostic` row, so this is a core observability gap, not a Flavor Agent logging gap.

| #                  | Title                                                                                       | Status              | Team             |
| ------------------ | ------------------------------------------------------------------------------------------- | ------------------- | ---------------- |
| `WordPress/ai#437` | AI Request Logging                                                                          | Merged; `1.0.0`     | Showcase Plugin  |
| `WordPress/ai#419` | Comment Moderation, AI Observability (Logging), and the Site Agent (Natural Language Admin) | Future Release      | Showcase Plugin  |
| `WordPress/ai#345` | Add usage safeguards to AI Client (limits, visibility, and cost awareness)                  | Closed; `1.0.0`     | Showcase Plugin  |
| `WordPress/ai#689` | Manual age-based Request Log cleanup control (PR #735)                                       | In progress; Future | Showcase Plugin  |
| `WordPress/ai#732` | AI Request Logs miss non-SDK-transport providers (sidecar/custom transport invisible)       | Backlog; Future     | Showcase Plugin  |
| `WordPress/ai#193` | Add developer-only log panel for inspecting AI provider responses                           | Backlog             | LLM Integrations |

### Provenance And Attestation Semantics

These items do not compete with Flavor Agent's editor UX, but they define the upstream vocabulary for Attest. The split to preserve: WordPress C2PA work signs or preserves content-artifact provenance; Flavor Agent's Ring III work signs governed changes to WordPress state after review and apply. Request logs, service accounts, visual revisions, and future Site Agent flows are evidence and identity inputs for attestations, not attestations by themselves.

| #                  | Title                                                        | Status / read                 |
| ------------------ | ------------------------------------------------------------ | ----------------------------- |
| `WordPress/ai#294` | Content Provenance experiment                                | Direct C2PA artifact attestation |
| `WordPress/ai#302` | Image Provenance experiment                                  | Direct C2PA artifact attestation |
| `WordPress/ai#421` / `#459` | Detect and preserve C2PA manifests on upload       | Attestation intake / evidence |
| `WordPress/ai#211` | Add Service Account experiment                               | Identity infrastructure       |
| `WordPress/ai#507` | Editorial Updates end flow to Visual Revisions               | Review evidence               |
| `WordPress/ai#324` | Collaborative / agentic editorial workflow                   | Potential human sign-off point |
| `WordPress/ai#189` | Site Agent / Natural Language Admin                          | Future attestation-ready execution |

### Provider Routing And Connector Permissions

Compete with `inc/OpenAI/Provider.php`, `inc/Embeddings/ConfigurationValidator.php`, `inc/LLM/WordPressAIClient.php`, `inc/LLM/ChatClient.php`, and the provider selection UI in `src/admin/settings-page.js` plus `src/admin/settings-page-controller.js`.

| #                  | Title                                                                                                    | Status           |
| ------------------ | -------------------------------------------------------------------------------------------------------- | ---------------- |
| `WordPress/ai#148` | Add Extended Providers experiment                                                                        | Closed           |
| `WordPress/ai#262` | Provider-Level Model Bucketing for Model Selection                                                       | In discussion    |
| `WordPress/ai#343` | Implement plugin permissions management system                                                           | Closed           |
| `WordPress/ai#467` | Connector Approval experiment                                                                            | Merged; `1.0.0` with caller-attribution caveat |
| `WordPress/ai#595` | Deepest originating extension caller attribution for Connector Approval                                  | Merged; `1.0.1` |
| `WordPress/ai#660` | Clearer error copy when a provider is blocked by Connector Approvals                                     | In progress; `1.2.0` |
| `WordPress/ai#486` | Add developer settings mode with the ability to set desired provider and model per feature               | Merged; `0.9.0` |
| `WordPress/ai#761` | Explicit Save button for developer settings provider/model (Reset-to-default auto-persists)              | Shipped; `1.1.0` |
| `WordPress/ai#342` | Add permission controls for plugins to use a connected provider                                          | In discussion    |
| `WordPress/ai#441` | Require explicit admin approval for plugin access to Connectors plus improve connector secret protection | In discussion    |
| `WordPress/ai#560` | Key Encryption experiment (encrypt AI Connector API keys at rest; proving ground for a core Secrets API) | Shipped; `1.1.0` |
| `WordPress/ai#211` | Add Service Account experiment                                                                           | In discussion    |
| `WordPress/ai#191` | Add import/export support for AI settings and provider configuration                                     | Backlog          |
| `WordPress/ai#27`  | Add developer support for pre-configured AI providers (also High priority above)                         | In discussion    |
| `WordPress/ai#502` | Provider plugin discovery, curation, and labeling (parent of #27)                                        | In discussion    |

### Abilities Exposure And Surface Controls

Compete with `inc/Abilities/Registration.php` (32 defined abilities: seven recommendation, thirteen helper/read, one docs search, five preview siblings, and six external-apply abilities, with recommendation/external-apply registration gated by the AI feature), `inc/Abilities/SurfaceCapabilities.php`, the surface gating in `src/utils/capability-flags.js`, and the editor hydration of abilities into `@wordpress/core-abilities`.

| #                  | Title                                                                                         | Status           |
| ------------------ | --------------------------------------------------------------------------------------------- | ---------------- |
| `WordPress/ai#40`  | WordPress Core Abilities                                                                      | Open; `0.9.0`    |
| `WordPress/ai#691` | New `core/read-settings` ability (renamed from `core/settings` in `#806`)                     | Shipped; `1.1.0` |
| `WordPress/ai#354` | Unified Abilities exposure controls (per-surface gating)                                      | In discussion    |
| `WordPress/ai#348` | Feature Request: Unified AI Management Layer for WordPress Core                               | In discussion    |
| `WordPress/ai#481` | Ensure any `sanitize_callback` in Abilities input schema is executed                          | Open PR; Future Release |
| `WordPress/ai#482` | Utilize the new client-side Abilities API                                                     | Merged; `1.0.0`  |
| `WordPress/ai#346` | Executing summarization ability with `@wordpress/abilities` in WP 7.0 fails on invalid schema | Needs review     |
| `WordPress/ai#736` | Per-feature role/user access controls (draft PR #749)                                          | In progress      |
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
| `WordPress/ai#151` | Add Type Ahead experiment (provider/model overrides + Guidelines via `#776`) | Shipped; `1.1.0` |
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

As of 2026-06-20, `Automattic/agents-api` is the concrete substrate to watch for this family. Its contract is runtime plumbing, not product scope, so it should not trigger a panel migration or new runtime dependency until the Site Agent / AI Workspace architecture settles.

| #                  | Title                                                                          | Status         |
| ------------------ | ------------------------------------------------------------------------------ | -------------- |
| `Automattic/agents-api` | WordPress-shaped agent runtime substrate                                  | Public repo    |
| `WordPress/ai#419` | Site Agent backend (future-release carry-over; see release-train section)      | Future Release |
| `WordPress/ai#189` | Explore an admin Site Agent for executing WordPress actions                    | Backlog        |
| `WordPress/ai#142` | Frontend chat agent powered by site content                                    | Backlog        |
| `WordPress/ai#282` | Chat experiment: Integration outside the editor and outside single-task AI use | In discussion  |
| `WordPress/ai#430` | Skills in a WordPress admin context                                            | In discussion  |

### Embeddings, Vector Search, And RAG

Compete with Flavor Agent's plugin-owned embedding and retrieval stack: `inc/Embeddings/` (Cloudflare Workers AI embeddings), `inc/Patterns/PatternIndex.php` and `inc/Patterns/Retrieval/`, the Qdrant utilities, and the private Cloudflare AI Search pattern backend. Embeddings remain the one backend Flavor Agent still owns directly because Connectors does not expose embedding generation.

| #                  | Title                                                                                  | Status                      |
| ------------------ | -------------------------------------------------------------------------------------- | --------------------------- |
| `WordPress/ai#683` | Native vector search / RAG (MariaDB-backed, post-meta fallback embeddings) — draft PR  | In progress; Future Release |
| `WordPress/ai#142` | Frontend chat agent powered by site content (needs an embeddings/RAG pipeline)         | Backlog                     |

If core ships a native embeddings + vector-search primitive (`#683`), revisit whether Flavor Agent's Cloudflare Workers AI embeddings, Qdrant storage, and pattern-retrieval backends should defer to it the way chat now defers to Connectors. Treat it as a future provider-ownership migration candidate, not current work: `#683` is a Future Release draft and core embeddings are not yet exposed through Connectors. **Currentness check 2026-07-18:** the library layer moved first — `php-ai-client` `1.4.0` (tagged 2026-07-15) shipped the embedding-generation APIs (`WordPress/php-ai-client#244`) that the WordPress-side integration consumes, while the WordPress-core embeddings integration missed the 7.1 Beta 1 cutoff (the team's pencils-down deadline was 2026-07-14) and is now targeted at WordPress 7.2, with an open question about attempting 7.1 Beta 2 instead, per the [2026-07-15 AI contributor summary](https://make.wordpress.org/ai/2026/07/17/ai-contributor-weekly-summary-15-july-2026/). Primitives shipping ahead of the core integration slightly strengthens the eventual-deferral bet; the posture stays watch-only.

### MCP And WebMCP

Flavor Agent exposes abilities over MCP through two servers, both of which require the `mcp-adapter` plugin. The **universal default server** surfaces only abilities marked `meta.mcp.public = true` — the 10 read helpers, `search-wordpress-docs`, and the 5 read-only `preview-recommend-*` siblings (16 tools) — via `discover-abilities` / `execute-ability`. The 7 write-side `recommend-*` abilities and the 6 external-apply abilities deliberately carry **no `mcp` meta** (`Registration::recommendation_meta()` / `external_apply_meta()`): because `request_diagnostic` rows can carry prompts, generic discover/execute exposure stays curated. They are instead published on a **dedicated Flavor Agent MCP server** at `/wp-json/mcp/flavor-agent` (`inc/MCP/ServerBootstrap.php`) whose explicit tool list is the 7 recommendation + 6 external-apply abilities (13 tools in `tools/list`); it registers only when the recommendation feature is enabled, and transport access requires `edit_posts`/`edit_theme_options`. The 3 editor-internal helpers (`list-synced-patterns`, `get-synced-pattern`, `check-status`) are on neither MCP server — Abilities-API-only. Future upstream MCP routing work still pressures provider and ability-routing layers, but Flavor Agent no longer relies only on the universal default-server bridge.

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
- `WordPress/php-ai-client#100` — Add support for streaming. **Slipped the `1.4.0` release**: `php-ai-client` `1.4.0` was tagged 2026-07-15 with the embedding-generation APIs (`#244`) but no streaming; the implementation is now open PR `WordPress/php-ai-client#255`, under review (verified 2026-07-18 — issue `#100` and PR `#255` still carry the stale, still-open `1.4.0` milestone on GitHub, so do not read the milestone as the ship vehicle). Per the [2026-07-15 AI contributor summary](https://make.wordpress.org/ai/2026/07/17/ai-contributor-weekly-summary-15-july-2026/), maintenance constraints in the WordPress `Requests` library affect the implementation, and the current direction is for the necessary change to land inside `Requests` rather than as a separate WordPress-core implementation — the `#237` buffering constraint has moved into the dependency itself. Prior scaffolding was deliberately removed in `WordPress/php-ai-client#170` (merged 2026-01-16) so a clean interface — likely a standalone `StreamingTextGenerationModelInterface` per Felix Arntz — can emerge. This remains the upstream gate for Flavor Agent's streaming work. Full design at `docs/reference/streaming-recommendations-design.md`; its trigger condition was rebased 2026-07-18 (the "1.4.0 RC" premise is dead — the gate is now `#255` merging, streaming primitives shipping in a tagged release, and an AI plugin streaming Experiment in trunk). Watch-only.

## Out Of Scope At Snapshot

Active board items below have no Flavor Agent collision and are listed only so refresh runs can confirm scope did not drift.

- Image and media UI: `#270`, `#288`, `#325`, `#388`, `#402`, `#435`, `#238` (focus-aware crop PR `#494`). C2PA/provenance items `#294`, `#302`, and `#421` remain out of Flavor Agent media scope but are tracked above for Attest semantics.
- Connector debugging or environment bugs: `#339`, `#387`, `#420`
- Experiment polish or documentation that does not touch shared subsystems: `#90`, `#145`, `#180`, `#181`, `#190`, `#203`, `#221`, `#225`, `#257`, `#270`, `#390`, `#391`, `#397`, `#425`
- Marketing or release: `#47`

If any of these get rescoped to share infrastructure with Flavor Agent (for example, `#190` site-wide content insights gaining an Inspector surface), promote them into the appropriate **Active Items By Collision Area** table.

The 2026-05-21 AI `1.0.0` release post names `#325` and `#238` as 1.1.0-or-future Media Editor exploration, and both issues are open Future Release items on GitHub. They remain out of scope because Flavor Agent has no media-library, media-editor, image-generation, focal-point, or crop-metadata surface.

## Action Implications For Flavor Agent

Each bullet is keyed to the board item that drives it. Strike through completed work when the corresponding upstream item ships.

1. ~~**`#437`, `#419`** — Request Logging shipped in AI `1.0.0`; subscribe and mirror future Site Agent review. The design is **coexistence, not consolidation**: core's Request Logging captures every AI Client HTTP call transparently via the SDK HTTP transporter decorator, so Flavor Agent should enrich `wpai_request_logs.context` with surface/scope/document/ability data via the `wpai_request_log_context` filter, stop persisting `request_diagnostic` rows when core logging is enabled, and keep the apply/undo journal plus the editor-inline `AIActivitySection` history in `inc/Activity/Repository.php`. Full design at `docs/reference/activity-log-request-logging-coexistence.md`.~~ **Done 2026-05-25 via Request Logging bridge, revised 2026-06-03 for dual logging: `RequestLoggingBridge` injects context, captures `wpai_request_logged` IDs, keeps Flavor Agent diagnostics by default, and the Activity admin can inspect the matching core row inline.** Suppression is now the AI Activity Dual Logging opt-out.
2. **`#21`, `#354`** — Plan ability consolidation. Decide whether the 32 defined abilities under `flavor-agent/` collapse into a smaller router surface, or remain individual with helper/read and preview abilities always available and recommendation/external-apply abilities registered against per-surface gates once `#354` defines them. **Tracked as an upstream watch item in `docs/reference/current-open-work.md`; not yet an implementation plan.**
3. **`#348`, `#354`** — Keep the governance split explicit. If core gains a unified AI Management layer, treat it as the outer policy plane for plugin permission, usage metering, budgets, and provider routing. Flavor Agent should keep its inner mutation-governance contract: bounded proposals, human approval for structural/theme changes, server-side attribution, freshness checks, and drift-safe undo for changes the plugin mediates. **Not yet covered by an implementation workstream.**
4. **`Automattic/agents-api`, `#189`, `#282`, `#419`** — Track Agents API as optional runtime substrate for future conversational/admin agents. Do not require it or migrate editor-bound panels preemptively; if it stabilizes as the Site Agent / AI Workspace runtime, adapt through `wp_register_agent()` / `wp_agents_api_init` and keep Flavor Agent's approve/apply/undo journal as the product-owned mutation-governance layer. **Tracked as an upstream watch item in `docs/reference/current-open-work.md`; not yet an implementation plan.**
5. **`#294`, `#302`, `#421`, `#211`, `#507`, `#324`, `#189`** — Keep the Attest split explicit. Upstream content/image provenance is artifact-level C2PA; service identity, request logs, revisions, and Site Agent concepts are evidence/identity/execution inputs. Flavor Agent's owned Attest surface is governed-change attestation for FA-mediated mutations: approval, bounded operation, resulting artifact digest, public statement/key routes, and live-state or revert/supersede verification. **Implemented locally for the Ring III external style-apply lane; future C2PA emission or transparency-log anchoring stays additive.**
6. ~~**`#37`, `#262`, `#27`, `#348`** — Stop investing in independent provider routing UX. Treat `inc/OpenAI/Provider.php` and the provider selector in `src/admin/settings-page-controller.js` as fallback-only once core ships unified routing or model bucketing.~~ **Done 2026-04-28 via Workstream C (Provider Ownership Migration). Direct chat fields removed; chat is fully Connectors-owned. Plugin retains direct embedding credentials only.**
7. ~~**`#345`, `#437`, `#419`** — Define the activity story now that Request Logging and usage-safeguards work shipped in the AI plugin. The decision lands on coexistence (see implication 1 above and `docs/reference/activity-log-request-logging-coexistence.md`): core Request Logging owns provider/model/tokens/cost observability; Flavor Agent's Activity Repository owns apply/undo state; the admin audit page cross-links into `Tools → AI Request Logs` rather than duplicating or retiring it. Cost and limit metering vocabulary should align with core's safeguards rather than inventing a parallel metering layer.~~ **Done 2026-05-25 via Request Logging bridge Phase 1-4; cost stays in core Request Logs while Flavor Agent shows linked request details and local apply/undo state.**
8. **`#192`** — Hold on bespoke prompt-template extension points. The canonical hook will land here; resist building Flavor Agent-specific extension points in the meantime. **Not yet covered by an existing workstream.**
9. **`WordPress/abilities-api#75`** — Watch for REST-as-ability unification. If accepted, decide whether the remaining activity persistence, undo-status, and manual pattern-sync REST adapters should gain ability equivalents. Recommendation consumers already use the Abilities API rather than parallel plugin REST endpoints.
10. **`WordPress/abilities-api#149`** — Once execution lifecycle filters land, move `inc/Activity/Repository.php` instrumentation onto them to avoid double-logging when callers hit core's filter set in addition to the Flavor Agent wrapper. **Not yet covered by an existing workstream.**
11. **WordPress 7.1 Guidelines API (`wp_register_guideline()`)** — Source guidelines into prompt assembly under `inc/LLM/` (`Prompt.php`, `TemplatePrompt.php`, `TemplatePartPrompt.php`, `NavigationPrompt.php`, `StylePrompt.php`, `WritingPrompt.php`) so Flavor Agent recommendations respect site-wide guidelines as soon as core's API ships. **Bridge implemented 2026-04-28 via Workstream D: Flavor Agent now has a core-first repository bridge, prompt formatter, and settings migration framing. Keep watching for the public `wp_register_guideline()` API and core's final write/defaults model before adding write migration.**
12. **`#467`, `#595`** — Connector Approval request-time handling is implemented locally and upstream caller matching shipped in AI plugin `1.0.1`, but final post-approval runtime success remains a smoke gate. Keep the validation artifact honest when provider configuration returns `missing_text_generation_provider`, and re-run the manual approval path when the local stack has representative text-generation provider state.
13. **Native streaming (`WordPress/php-ai-client#100` → PR `#255`)** — The [2026-05-20 AI contributor summary](https://make.wordpress.org/ai/2026/05/21/ai-contributor-weekly-summary-20-may-2026/) named native streaming the critical priority for the 7.1 cycle, but streaming **slipped the `1.4.0` release** (tagged 2026-07-15 with embeddings, without streaming) and is now open PR `php-ai-client#255` under review. The [2026-07-15 summary](https://make.wordpress.org/ai/2026/07/17/ai-contributor-weekly-summary-15-july-2026/) records maintenance constraints in the WordPress `Requests` library and the direction that the necessary change lands inside `Requests` rather than as a separate WordPress-core implementation. Flavor Agent surface adoption matrix, transport options (chunked HTTP vs polling), and schema/review-signature reconciliation live in `docs/reference/streaming-recommendations-design.md`; its trigger condition was rebased 2026-07-18 to "`#255` merges + streaming primitives ship in a tagged `php-ai-client` release + the AI plugin lands a streaming Experiment in trunk". Hold posture unchanged: do not add `is_streaming_supported()` stubs or streaming endpoints until the upstream primitives stabilize. **Not yet covered by an existing workstream.**

14. **`WordPress/ai#683` (native vector search / RAG)** — Watch core's MariaDB-backed embeddings/vector-search experiment as a future provider-ownership migration point for Flavor Agent's plugin-owned embedding (Cloudflare Workers AI), Qdrant storage, and pattern-retrieval backends. Do not migrate now: `#683` is a Future Release draft and core embeddings are not exposed through Connectors yet — though as of 2026-07-18 the library layer has moved: `php-ai-client` `1.4.0` (2026-07-15) shipped the embedding-generation APIs (`#244`), and the core-side embeddings integration is targeted at WordPress 7.2 after missing 7.1 Beta 1, which strengthens the eventual-migration bet without making it current work. **Not yet covered by an existing workstream.**

### Workstream History

The earlier overlap-remediation plan tracked these workstreams; results have been folded back into the live source tree.

| Workstream                          | Status                                                                 | Driven by these board items                                                                                                                                           |
| ----------------------------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A (Pattern Surface Reset)           | Done 2026-04-23                                                        | Pattern API stabilization (no specific item on the board today)                                                                                                       |
| B (Block Inspector Ownership Reset) | Done 2026-04-23                                                        | None active on the board today                                                                                                                                        |
| C (Provider Ownership Migration)    | Done 2026-04-28                                                        | `WordPress/ai#27`, `#37`, `#262`, `#342`, `#348`, `#441`                                                                                                              |
| D (Guidelines Bridge and Migration) | Read bridge implemented 2026-04-28; write/public API migration pending | WordPress 7.1 Guidelines API (Trac, not on this board); bridge reads `wp_guideline` / `wp_guideline_type` now and defers write migration until the public API settles |
| E (Settings Screen Modernization)   | Pending                                                                | `WordPress/ai#197`, `#451`, `#472`, `#428`, `#323` (these are core's own settings UX, but they pressure Flavor Agent's settings to align)                             |

Open action implications above are upstream pressures, validation chores, or watch items, not active implementation plans. When any of them moves from "watch" to "act", record the workstream in `docs/SOURCE_OF_TRUTH.md` or the relevant feature doc rather than tracking implementation details here.

## Related References

- `docs/SOURCE_OF_TRUTH.md` — canonical product definition, current state, and architectural guardrails.
- `docs/FEATURE_SURFACE_MATRIX.md` — every shipped surface, gate, and apply/undo path.
- `docs/reference/abilities-and-routes.md` — REST and Abilities contract map.
- `docs/reference/provider-precedence.md` — backend selection, credential fallback chain, and Connectors-first runtime activation.
