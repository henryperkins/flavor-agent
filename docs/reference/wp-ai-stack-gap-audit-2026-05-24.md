# WP AI Stack Gap Audit — 2026-05-24

Audit grounded in the last 30 days of WordPress core AI activity (Make WP AI, Make WP Core, WordPress.org Developer Blog, WordPress News). Purpose: surface where Flavor Agent's current code, docs, and roadmap are aligned with — or drifting from — the post-WP 7.0 AI stack, with special attention to items that landed AFTER the 2026-05-21 refresh of `wordpress-ai-roadmap-tracking.md`.

> Currentness note, 2026-06-10: this remains a dated audit snapshot. Subsequent preview-ability and governed external-apply work raised the current contract to 29 abilities (seven recommendation, twelve helper/read, one docs search, five preview siblings, and four external-apply abilities). The ability-scale watch item remains valid, but use `docs/reference/current-open-work.md` and `docs/reference/abilities-and-routes.md` for the current count.

## How to read this

- **Aligned** — Flavor Agent matches canonical guidance; no action needed.
- **Drifting** — implementation works, but a documented or recommended pattern has moved; schedule a touch-up.
- **Watch** — upstream change is forming; do not invest in the affected area without re-checking.
- **Gap** — net-new pressure not yet covered by `wordpress-ai-roadmap-tracking.md` or `STATUS.md`. These are the items this audit adds.

## News input (digest summary)

15 AI-relevant posts in the last 30 days. Highest-leverage sources:

- **AI 1.0.0 release** — 2026-05-19 (post: [What's new in AI 1.0.0](https://make.wordpress.org/ai/2026/05/21/whats-new-in-ai-1-0-0/)). New Experiments: **Request Logging** and **Connector Approvals**. Provider onboarding & error guidance improvements. Editorial rename: "Review Notes" → "Editorial Notes", "Refine from Notes" → "Editorial Updates".
- **AI Contributor Weekly Summary — 20 May 2026** ([post](https://make.wordpress.org/ai/2026/05/21/ai-contributor-weekly-summary-20-may-2026/)). Most consequential for Flavor Agent:
  - Abilities API at massive scale (#21) — active concern; "find abilities" discovery pattern is the next technical milestone.
  - Slug-fragments PR pending merge into 7.1 trunk → unlocks multi-layered nested abilities.
  - Experiment Initialization PR #159 — moving experiment bootstrap from explicit `register()` to standard hook-based `init()`.
  - Programmatic Encryption & Secrets Management PR #560 — proving ground for a dedicated core Secrets Management API in 7.1.
  - **Native streaming officially marked critical priority for 7.1** — top requested missing feature among 7.0 testers.
  - AI Plugin 1.1.0 due **2026-06-04** (11 days from this audit).
- **Leadership transition** — 2026-05-18 ([post](https://make.wordpress.org/ai/2026/05/18/leadership-transition-for-the-core-ai-team/)). Felix Arntz (@flixos90) and James LePage (@isotropic) step back; Jason Adams (@jason_the_adams) takes over as Team Rep. A 7.1 landscape re-survey is planned.
- **AI Client image-gen tutorial** — 2026-05-14 ([post](https://developer.wordpress.org/news/2026/05/how-to-build-an-image-generation-plugin-with-the-wordpress-ai-client/)) by Felix Arntz. Confirms canonical patterns: `wp_ai_client_prompt()`, `is_supported_for_*()`, `using_model_preference()`, `Settings > Connectors` for credentials, `Requires at least: 7.0`.
- **22 Apr 2026 contributor summary** ([post](https://make.wordpress.org/ai/2026/04/27/ai-contributor-weekly-summary-22-april-2026/)). MCP Adapter is being released as a standalone WordPress.org plugin; Composer remains an option but is not the primary delivery method.

Full digest written to `output/wp-core-ai-news-2026-05-25.json` / `.md` during this audit.

## Surface-by-surface alignment

### AI Client wiring — `inc/LLM/WordPressAIClient.php` — **Aligned (exemplar)**

The implementation outpaces the May 14 Field Guide tutorial:

- Canonical entry through `wp_ai_client_prompt()` with a `WordPress\AI\get_ai_service()->create_textgen_prompt()` pre-pass (lines 313–341). This is the right order: prefer the AI plugin's service when present, fall back to the core entry point.
- Support gating via `is_supported_for_text_generation()` before generation (`ensure_text_generation_supported`, lines 90–93 and 1122–1142).
- Soft model preferences via `using_model_preference()`, sourced from `WordPress\AI\get_preferred_models_for_text_generation()` (lines 361–379). Matches Felix's "preferences, not requirements" guidance verbatim.
- `wp_ai_client_prevent_prompt` handling: catches `Prompt_Prevented_Exception` and converts to a structured `WP_Error` (lines 1144–1154, 1181–1183).
- Structured output via `as_json_response()` with a 16-union schema cap and ranking-contract `$ref` compaction (lines 730–763, 902–918).
- Reasoning effort and provider-specific custom options applied through safe builder cloning so unsupported configurations don't poison the prompt (lines 580–693).

No drift. This module is a better reference than the public tutorial.

### Connector Approvals — `inc/LLM/WordPressAIClient.php` lines 284–307, 1197–1270 — **Aligned (shipped-ready)**

Connector Approvals shipped as an Experiment in AI 1.0.0 on 2026-05-19. Flavor Agent already:

- Detects approval errors structurally (`wpai_connector_not_approved` code) and via a fallback message regex (`connector_approval_error_from_throwable`, lines 1240–1270).
- Surfaces a `connectorApproval` block in the WP_Error data with `connectorId`, `callerBasename`, `callerName`, and the admin URL.
- Exposes `connectorApprovalUrl` to JS through `flavor_agent_get_editor_bootstrap_data()` (`flavor-agent.php` line 238–240) for inline UI affordances.

Watch item is `WordPress/ai#595` — "deepest originating extension caller attribution" — already tracked in `wordpress-ai-roadmap-tracking.md` for 1.1.0 (due 2026-06-04). No action needed beyond keeping that watch entry.

### Abilities registration — `inc/Abilities/Registration.php`, `inc/AI/Abilities/Recommend*Ability.php` — **Watch (architectural pressure rising)**

At the time of this audit, Flavor Agent registered 20 abilities under the `flavor-agent/` category — 7 recommendation abilities plus 13 helper/read abilities (block introspection, list-allowed-blocks, list-patterns, get-pattern, list-synced-patterns, get-synced-pattern, list-template-parts, search-wordpress-docs, get-active-theme, get-theme-presets, get-theme-styles, get-theme-tokens, check-status). The current contract is 29 abilities after the five `preview-recommend-*` siblings and four governed external-apply abilities shipped.

The 20 May contributor summary identified the **`WordPress/ai#21` "supporting massive scale"** discussion as a high-priority architectural concern: "long-term optimization required to safely register, filter, and surface hundreds or thousands of concurrent abilities within an active system without introducing database degradation." Action item assigned to `@justlevine`. Separately, the team named **"find abilities" discovery** as the next technical milestone, mirroring the third-party "find tool" pattern.

`wordpress-ai-roadmap-tracking.md` already lists this as a future action ("Plan ability consolidation. Decide whether the defined abilities under `flavor-agent/` collapse into a smaller router surface..."). What's new from the 20 May meeting: this is now an *active* architectural workstream with named owners. Recommend:

- Avoid registering additional helper abilities in the short term unless they bring obvious value.
- Track `#21` directly (not just via the AI 1.0.0 milestone) and prepare a position on whether Flavor Agent's helper abilities should collapse behind a discovery layer once `find-abilities` lands.

### Nested abilities / slug fragments — **Watch**

The 20 May summary: "As soon as the pending slug fragments PR safely merges into core trunk for 7.1, the team will begin prototyping multi-layered, complex nested abilities." Flavor Agent's recommendation abilities currently sit flat under `flavor-agent/recommend-{surface}`. If nesting lands, the natural shape becomes `flavor-agent/recommend/{surface}` or similar — purely cosmetic until 7.1 trunk reopens (officially scheduled for "immediately following the final 7.0 deployment", per the 20 May summary), but worth tracking before any ability-namespace refactor.

### Request Logging — `inc/Activity/Repository.php` + `src/admin/activity-log.js` — **Drifting (decision deferred)**

Request Logging shipped in AI 1.0.0 (Experiment). `wordpress-ai-roadmap-tracking.md` already flags the collision at line 111 and item 4 in "Action implications" (line 312): "Choose between forwarding Activity Repository writes into core's metering/logging pipeline or retiring the admin audit page in favor of core's dashboard, while keeping the editor-inline activity surface." **Not yet covered by a workstream.**

Net-new from the AI 1.0.0 changelog: Request Logging is described as observability into AI *requests and responses* generated through core, plugins, and themes — i.e. it sits at the AI Client transport layer. Flavor Agent's Activity Repository sits at a different layer: it logs *applied editor changes* (with undo), not raw AI requests. These can be complementary rather than a forced choice:

- Keep `Activity\Repository` as the apply-and-undo journal (it has a 4-version schema, ordered undo, admin projection backfill, 90-day retention — none of that is what Request Logging gives you).
- Decide whether to *also* emit AI Client request metadata into core's Request Logging when it's enabled, so a site admin can correlate "AI request" with "applied change" without duplicating the storage.

Recommend converting line 312 of `wordpress-ai-roadmap-tracking.md` from "choose between" to "decide whether to also emit" and open a small workstream that adds a forwarder gated on `Request Logging` being active.

### MCP Adapter integration — `inc/MCP/ServerBootstrap.php`, `flavor-agent.php` line 34 — **Aligned; doc framing corrected 2026-05-26**

`ServerBootstrap::register()` hooks into `mcp_adapter_init`, uses `WP\MCP\Core\McpAdapter`, `WP\MCP\Transport\HttpTransport`, `WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler`, and `WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler`, registers all 7 recommendation abilities as MCP tools, and supplies its own auth callback. That matches the canonical contract from `WordPress/mcp-adapter`.

Doc framing: the original 2026-05-24 audit recommended framing WP.org as the primary distribution path based on the 22 Apr 2026 contributor summary alone. A cross-check on 2026-05-26 against the upstream README at v0.5.0 (released 2026-04-15) shows the README treats Composer as the primary install method and the plugin form as an alternative, and does not mention WP.org. The 22 Apr decision still stands as a stated intent (`@justlevine` to initiate WP.org submission, `@jeffpaul` coordinating), but it has not landed in the README or in a WP.org listing. The corrected framing in `CLAUDE.md` line 48 and `docs/reference/local-environment-setup.md` lines 83 and 264 records both the contributor-track intent and the current README/v0.5.0 reality; the GitHub clone remains the active local-setup path until WP.org publication actually lands.

### MCP feature gate — **Aligned**

`ServerBootstrap::register()` returns early unless `FeatureBootstrap::canonical_contracts_available()` AND `FeatureBootstrap::recommendation_feature_enabled()`. The recommendation feature gate is the documented `wpai_features_enabled` + `wpai_feature_flavor-agent_enabled` filter pair. Good defensive posture for the experimental MCP Adapter — if the AI plugin feature framework isn't active, MCP doesn't register at all.

### Experiment bootstrap pattern — `flavor-agent.php` line 32, `inc/AI/FeatureBootstrap.php`, `inc/AI/FlavorAgentFeature.php` — **Watch**

Flavor Agent self-registers as a downstream AI plugin Feature via `wpai_default_feature_classes`. The 20 May contributor summary noted PR **#159** is discussing "refactoring how plugin experiments are booted, moving initialization logic away from explicit `register()` methods toward standard, hook-based `init()` action routines. Sentiment leaned towards closing out this PR." The thread is unresolved — comments may push it to merge or close.

If #159 merges, the `Experiment::register()` pattern Flavor Agent's `FlavorAgentFeature` likely follows (need to confirm from `inc/AI/FlavorAgentFeature.php`) becomes deprecated in favor of standard init hooks. Action: subscribe to #159, no code changes today.

### Provider/credentials — **Aligned, with one Secrets Management note**

`wordpress-ai-roadmap-tracking.md` line 311 confirms Workstream C (Provider Ownership Migration, 2026-04-28) removed direct chat fields; chat is fully Connectors-owned. Flavor Agent retains direct provider/vector-store settings only for embeddings and retrieval:

- `flavor_agent_cloudflare_workers_ai_account_id`
- `flavor_agent_cloudflare_workers_ai_api_token`
- `flavor_agent_cloudflare_workers_ai_embedding_model`
- `flavor_agent_pattern_retrieval_backend`
- `flavor_agent_cloudflare_pattern_ai_search_instance_id`
- `flavor_agent_qdrant_url`
- `flavor_agent_qdrant_key`

The 20 May summary surfaced an exploratory PR **#560** for "Programmatic Encryption & Secrets Management" — a standalone encryption utility built from prior Two-Factor plugin code, "to act as a proving ground for pitching a dedicated core Secrets Management API in 7.1."

Implication: when the Secrets Management API lands in 7.1 (or 7.2), Flavor Agent's two true plaintext secret options (`flavor_agent_cloudflare_workers_ai_api_token`, `flavor_agent_qdrant_key`) become migration targets. They are created with `add_option(..., '', '', false)` (autoload=no) in `flavor-agent.php` lines 40–41; the wider dependency-change loop at lines 102–110 watches seven Flavor Agent retrieval/embedding options plus `home`, so audit the adjacent non-secret settings at the same time. **Net-new item not in `wordpress-ai-roadmap-tracking.md`.**

The 22 Apr summary also noted "Embeddings within PHP/WP AI Clients" was blocked at that time, with outreach planned. If embeddings ship in the AI Client itself (rather than requiring direct provider plugins), Flavor Agent's Cloudflare Workers AI embedding path becomes a fallback rather than the primary backend. **Worth a watch entry.**

### Streaming — **Net-new gap**

20 May summary: "Native streaming data support remains the top requested missing feature among early 7.0 testers. Building out deep streaming infrastructure is officially marked as a critical priority for the 7.1 cycle."

Flavor Agent's `WordPressAIClient::chat()` is strictly request/response — `generate_text_result()` is one-shot, surfaces are blocked while waiting for the full payload, and the JS store consumes a complete recommendation object. The user-facing wait is most painful on `recommend-content` (drafting) and `recommend-template` (long structural suggestions). When the AI Client gains streaming primitives, these surfaces will be the natural first adopters.

No work to do today — but if a "streaming-ready recommendation lane" workstream is on the horizon, this is the right moment to start sketching how a streamed `GenerativeAiResult` would land in the existing review-before-apply path (the streamed payload still needs to clear the `ResponseSchema` validators and the per-surface review signature before any operations execute). **Not yet covered in `wordpress-ai-roadmap-tracking.md` or `STATUS.md`.**

### AI Plugin 1.1.0 release window — **Net-new operational note**

Due **2026-06-04** — 11 days after this audit. From the 20 May summary, in-flight items include:

- Type Ahead Suggestions experiment
- Promoted AI Provider Connectors
- AI Playground
- Content Provenance (C2PA) for text and images
- Integration with Gutenberg experimental Media Editor
- `WordPress/ai#595` Connector Approval caller attribution — approved by maintainer 2026-05-23, CI 17/18 green, MERGEABLE but BLOCKED awaiting an additional approval; the fix changes resolve-first-match to resolve-deepest-extension-frame and adds `Logging/`, `Settings/`, `helpers.php` to the skip prefixes

For Flavor Agent, the only material 1.1.0 watch item is **#595** (already tracked). The Type Ahead Suggestions experiment is worth a glance only insofar as it overlaps the content recommender surface — they're different patterns (cursor-inline vs panel-driven) but the team may end up with shared infrastructure later. If a Flavor Agent release is scheduled in early June, prefer landing it **before** 2026-06-04 or scheduling for the week of 2026-06-09 so 1.1.0 changes don't quietly invalidate a smoke test.

### Editorial naming — **Aligned/no-op**

AI 1.0.0 renamed "Review Notes" → "Editorial Notes" and "Refine from Notes" → "Editorial Updates". Flavor Agent's surfaces use "review-before-apply", "Apply", "Undo" — independent vocabulary. Worth a one-line check: do any user-facing strings or docs in `src/components/` or `inc/Admin/` borrow the old "Review Notes" / "Refine from Notes" labels? If yes, swap to the new names for consistency. (Quick grep confirms no current usage in `src/components/AIReviewSection.js` or `src/components/InlineActionFeedback.js`.)

### Leadership transition — **Strategic note**

Felix Arntz (the AI Client's principal author and the May 14 image-gen tutorial author) and James LePage (co-Team Rep) stepped back on 2026-05-18. Jason Adams is now Team Rep. The 20 May summary flagged a "landscape re-survey" coming in the next few contributor calls, with the caveat that "model behavior and agent frameworks have shifted drastically since the team's roadmap was first drafted a year ago" and that strategic guidance from Matt will be disseminated to the team.

Practical implication for Flavor Agent: this is *not* a moment to invest heavily in any speculative API shape (e.g. the not-yet-defined `find-abilities` discovery layer, the not-yet-merged slug-fragments nesting, or experimental WP Agent Skills). It *is* a good moment to:

- Land any pending compliance/cleanup work that's well-grounded in existing 1.0.0 APIs (Connector Approval polish, Activity Repository ↔ Request Logging forwarder, ability metadata accuracy).
- Hold off on net-new abilities or surface additions until the re-survey conclusions are public.

## Prioritized follow-up list (additive to existing roadmap)

The numbered "Action implications for Flavor Agent" list in `wordpress-ai-roadmap-tracking.md` (lines 308–321) already covers Request Logging forwarding (#1, #4), Ability consolidation (#2), provider-routing settled (#3, done), prompt-template hooks (#5), and connector approval smoke (#9). The items below are net additions surfaced by this audit.

1. ~~**Update `CLAUDE.md` and `docs/reference/local-environment-setup.md`** to acknowledge MCP Adapter's WP.org plugin path as the primary distribution (verify whether the listing is now live; if it is, switch the example install to `wp plugin install mcp-adapter --activate`). Drives from 22 Apr 2026 distribution decision. Low effort.~~ **Reframed 2026-05-26 after upstream README cross-check: the README at v0.5.0 (2026-04-15) treats Composer as the primary install method and the plugin form as an alternative, and does not mention WP.org. The 22 Apr WP.org intent has not landed in the README or a WP.org listing. `CLAUDE.md` line 48 and `docs/reference/local-environment-setup.md` now record both the contributor-track intent and the README/v0.5.0 reality. Switch to `wp plugin install mcp-adapter --activate` only if and when WP.org publication actually lands.**

2. ~~**Convert `wordpress-ai-roadmap-tracking.md` line 312 framing** from "choose between forwarding or retiring" to "decide whether to also emit AI Client request metadata into core's Request Logging when active." This keeps Flavor Agent's apply/undo log (which Request Logging does not replicate) and adds optional observability correlation. Net code change: small forwarder in `RecommendationAbilityExecution` or `WordPressAIClient` once Request Logging exposes a stable hook.~~ **Done 2026-05-25 via Request Logging bridge, revised 2026-06-03 for dual logging: `RequestLoggingBridge` registers the core hooks, enriches request-log context, captures core `log_id`s, keeps Flavor Agent diagnostics by default, and lets the Activity admin inspect the matching core row.**

3. **Add `WordPress/ai#21`** to `wordpress-ai-roadmap-tracking.md` "Abilities-related work" section as an active architectural workstream (currently only the consequences are listed; the ticket itself is missing). Note Justin Levine is the named owner.

4. **Add a Secrets Management watch entry** to `wordpress-ai-roadmap-tracking.md` for `WordPress/ai#560`. When this lands in 7.1, the two true secret options in `flavor-agent.php` lines 40–41 are the encrypted-storage migration targets; audit the seven related Flavor Agent retrieval/embedding options in lines 102–110 alongside them.

5. **Add a streaming watch entry** for the 7.1 cycle. No ticket number yet — track from the contributor summaries. When streaming primitives land, `recommend-content` and `recommend-template` are the first candidates and the schema-clearing + review-signature path needs design.

6. **Add an Experiment-init watch** for `WordPress/ai#159`. If hook-based `init()` becomes the canonical pattern, `inc/AI/FlavorAgentFeature.php`'s registration will need a small refactor. No action until #159 resolves.

7. **One-line scan** of `src/components/AIReviewSection.js`, `src/components/InlineActionFeedback.js`, and any operator/admin doc copy for the strings "Review Notes" or "Refine from Notes" — if any creep in, switch to "Editorial Notes" / "Editorial Updates" to match AI 1.0.0 vocabulary.

8. **Hold posture** on net-new abilities, ability namespace changes, and any speculative WP Agent Skills / find-abilities integration until the post-leadership-transition landscape re-survey is published. Roughly 2–4 contributor calls' worth of waiting.

## Verification

- News digest written: `output/wp-core-ai-news-2026-05-25.json` (15 items, 30-day window, 6 sources scanned, 1 skipped due to feed 429).
- Code references in this audit point to verified file paths and line numbers as of 2026-05-24. Re-verify before acting on items 1–7 — touch points may have shifted in the interim.
- Cross-checked against `wordpress-ai-roadmap-tracking.md` (last refreshed 2026-05-21) to avoid duplicating its tracking. Items above are deliberately additive.

## Currentness — 2026-05-25

This audit was integrated into the repo's active tracking docs the day after it was written. One prioritized item has since landed in code, and another now has a detailed design doc:

- **Item 2** (Activity Repository ↔ Request Logging coexistence) is designed and bridge implemented. [`docs/reference/activity-log-request-logging-coexistence.md`](activity-log-request-logging-coexistence.md) captures the design, and `inc/Activity/RequestLoggingBridge.php` is registered from `flavor-agent.php` on `init`. The bridge uses the `wpai_request_log_context` filter to enrich `wpai_request_logs.context` with Flavor Agent surface/scope/document, captures `wpai_request_logged` IDs, dual-logs Flavor Agent `request_diagnostic` rows alongside core by default, and cross-links the admin Activity page. Disabling AI Activity Dual Logging restores the suppress-and-defer behavior. `docs/reference/activity-log-request-logging-bridge-implementation-plan.md` records the shipped bridge phases, and `wordpress-ai-roadmap-tracking.md` action implications #1 and #4 now mark the work done.
- **Item 5** (streaming watch entry) is fully designed in [`docs/reference/streaming-recommendations-design.md`](streaming-recommendations-design.md). Surface adoption matrix, schema/review-signature reconciliation, and the trigger condition for starting implementation (php-ai-client 1.4.0 RC + AI plugin streaming Experiment in trunk) live there. `wordpress-ai-roadmap-tracking.md` action implications now include item #10 for this, and the off-board cross-repo section now tracks `WordPress/php-ai-client#100`.

Item 1 (MCP Adapter doc drift) was reframed on 2026-05-26 after a cross-check against the upstream MCP Adapter README. The README at v0.5.0 (2026-04-15) treats Composer as the primary install method and the plugin form as an alternative, and does not mention WP.org. The 22 Apr 2026 contributor summary recorded an intent to add WP.org as the primary distribution, but no WP.org listing is live as of 2026-05-26 and the README has not been updated. `CLAUDE.md` line 48 and `docs/reference/local-environment-setup.md` lines 83 and 264 now record both the contributor-track intent and the current README/v0.5.0 reality, and keep the GitHub clone as the active local-setup step. Switch the local-setup steps to `wp plugin install mcp-adapter --activate` only if and when WP.org publication actually lands.

Items 3, 6, 7, and 8 (track `#21` directly, `#560` Secrets Management watch, `#159` Experiment-init watch, vocabulary scan for "Review Notes" / "Refine from Notes") are reflected in `wordpress-ai-roadmap-tracking.md` updates. The vocabulary scan returned no `src/` occurrences as of this date.
