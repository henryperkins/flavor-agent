# Gutenberg Feature Tracking

This document tracks Gutenberg plugin releases, their stabilized APIs, and the forward-looking editor roadmap alongside the Flavor Agent code paths each one affects.

Use it when you need to answer:

- which Gutenberg release stabilized an API Flavor Agent currently shims
- which new editor extension point is worth adopting next
- which API surface in the repo can be simplified now that core caught up
- which iteration issue or project board signals a near-term collision with a Flavor Agent surface

For project-board pressure on the AI program (project 240), use `wordpress-ai-roadmap-tracking.md`.
For point-in-time WP 7.0 release-cycle compatibility analysis, use `docs/wordpress-7.0-gutenberg-23-impact-brief.md`.
For the active overlap-remediation backlog, use `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md`.

## Source And Refresh

Primary sources:

- Gutenberg release posts: `https://make.wordpress.org/core/tag/gutenberg-new/`
- WordPress release schedule and Phase status: `https://wordpress.org/about/roadmap/`
- 2026 yearly priorities: `https://make.wordpress.org/project/2026/01/23/big-picture-goals-for-2026/`
- WordPress org GitHub project boards: `https://github.com/orgs/WordPress/projects`
- Gutenberg iteration issues: filter the gutenberg repo by label `[Type] Iteration`
- Block Editor Handbook: `https://developer.wordpress.org/block-editor/`

Secondary signals (paraphrase primary sources, not authoritative): WP Tavern, agency blogs. Treat any version-specific feature claim that is not on `make.wordpress.org` or in a tracking issue as unconfirmed.

Snapshot date: 2026-04-29.
Latest Gutenberg release at snapshot: `23.0.0` (2026-04-22).
WordPress core: `7.0` targeted 2026-05-20, still pre-release at snapshot.

To refresh:

1. Read each "What's New in Gutenberg X.Y" post since the snapshot date and update **Versions Tracked** with new rows.
2. For each post, capture stabilized APIs (experiment flag removed), new public extension points, and deprecations. Update the appropriate matrix table.
3. Read the most recent active iteration issue under `WordPress/gutenberg` (filter by label `[Type] Iteration` and the current target milestone) and update **WP 7.x Iteration Issues To Watch**.
4. Re-check `https://wordpress.org/about/roadmap/` for Phase status changes and the next two WordPress release dates.
5. Bump the **Snapshot date** at the top.

When this doc is updated, run `npm run check:docs` if any other live contributor doc was touched in the same change.

## Phase Status

| Phase | State | Notes |
| --- | --- | --- |
| 1 — Easier Editing | Shipped, ongoing | Active follow-up on inserter UX (gutenberg#71995, #77698), block-transforms unification (#73821), and `contentOnly` audit (#65778). |
| 2 — Customization / Site Editor | Shipped through WP 6.3, ongoing | Project board `WordPress/projects/56` still open; template activation (#71735), Navigation Sidebar (#76037, #77069), Content Types experiment (#77600). |
| 3 — Collaboration / Workflows | Active | RTC landed in 7.0; Notes iteration (#76316), 7.1 collaborative editing iteration (#76377, In discussion), accessibility (#73942), CRDT cleanup cron (#75466). |
| 4 — Multilingual | Future | No active code work. Reference: `gutenberg#59250`. No primary-source timeline. |

## Versions Tracked

| Version | Date | Headline APIs and stabilizations |
| --- | --- | --- |
| 22.4 | 2026-01-20 | Pattern Overrides extended to all custom blocks via Block Bindings; Block Visibility by Screen Size (experimental); Font Library + Global Styles for classic/hybrid themes |
| 22.5 | 2026-02-04 | Per-block custom CSS (`has-custom-css`); Pattern Editing stabilized; Block Visibility by viewport stabilized |
| 22.6 | 2026-02-25 | Visual Revisions UI (text + block diff); SVG Icon Registration + REST `/wp/v2/icons`; client-side media processing stabilized |
| 22.7 | 2026-03-11 | `Settings > Connectors`; custom CSS selectors in `block.json`; Style variation transforms preview; patterns in contentOnly; RTC on by default; Content Guidelines experimental REST + CPT |
| 22.8 | 2026-03-25 | `registerConnector()` / `unregisterConnector()`; Button pseudo-state styling in Global Styles; Site Identity moves into Design panel; AVIF in client uploads; command palette in admin bar |
| 23.0 | 2026-04-22 | Revisions row for templates, template parts, patterns; `__rtc_compatible_meta_box`; DataForm date-range; `wordpress/ui` CSS-defense module; Site Identity panel consolidation |
| (WordPress 7.0) | targeted 2026-05-20 | AI Client `wp_ai_client_prompt()` + `wp_ai_client_prevent_prompt` filter; client-side Abilities API (`@wordpress/abilities`, `@wordpress/core-abilities`); ability `meta.annotations.{readonly,destructive,idempotent}`; DataForm/DataViews v2; RTC opt-in for beta; Interactivity API `withSyncEvent()` and `state.navigation` deprecation |
| 23.1 | projected ~2026-05-06 | Not yet posted at snapshot; track via 7.1 iteration issues below |
| 23.2 | projected ~2026-05-20 | Same |

WordPress core release cadence at snapshot: WP 7.0 May 20, 2026; WP 7.1 August 19, 2026; WP 7.2 December 10, 2026 (per the WordPress.org roadmap).

## Stabilized APIs Already Used By Flavor Agent

These rows record APIs Flavor Agent depends on (or shims) and the version that stabilized them. Use this table to find shim-collapse opportunities.

| API | Stabilized in | Flavor Agent file | Status |
| --- | --- | --- | --- |
| Pattern Editing (no longer experiment-gated) | 22.5 | `src/patterns/compat.js`, `src/patterns/pattern-settings.js` | Pattern Editing surface itself is stable. Compatibility audit completed 2026-04-30: production callers only need read-side stable/experimental settings probes plus allowed-pattern diagnostics, so dead `setBlockPatterns()` / `setBlockPatternCategories()` write helpers were removed. Three-tier read shims remain required for `__experimentalRegisterBlockPattern`, `__experimentalAdditional*` keys, and inserter DOM selectors until stable replacements land. |
| Block Visibility by viewport | 22.5 | `src/utils/structural-identity.js`, `src/inspector/BlockRecommendationsPanel.js` | New attribute key `blockVisibility.viewport`. Apply paths must not interpret a hidden block as missing/removed; CSS hide, not DOM removal. Audit advisory/executable classifiers. |
| `Settings > Connectors` admin page | 22.7 | `inc/LLM/WordPressAIClient.php`, `inc/OpenAI/Provider.php`, `flavorAgentData.connectorsUrl` localization in `flavor-agent.php` | Connectors-first chat routing matches the now-stable surface. Verify `connectorsUrl` resolves to the new screen. |
| WordPress AI Client (`wp_ai_client_prompt`, `is_supported_for_text_generation`, `wp_ai_client_prevent_prompt`) | WP 7.0 | `inc/LLM/WordPressAIClient.php` | Routed through. The prevent filter is honored in two places: a probe in `ensure_text_generation_supported()` differentiates "blocked by filter" from "no provider configured" (`prompt_prevented` 503 vs `missing_text_generation_provider` 400), and `call_prompt_method()` catches `Prompt_Prevented_Exception` for the race-condition path. |
| Client-side Abilities API (`@wordpress/abilities`, `@wordpress/core-abilities`) | WP 7.0 | `inc/Abilities/Registration.php` | Server abilities hydrate into the client `core/abilities` store automatically. Recommendation abilities intentionally do not set WP-format `readonly` or direct MCP `readOnlyHint`; they stay on POST and advertise only `destructive:false` and `idempotent:false`. Data-read abilities set `readonly:true`, `destructive:false`, and `idempotent:true`. |

## Stabilized APIs Worth Adopting (Not Yet Used)

These rows record stabilized or shipped APIs that Flavor Agent does not currently use but could.

| API | Available since | Why this matters | Candidate file |
| --- | --- | --- | --- |
| Pattern Overrides for custom blocks | 22.4 | Template-part operations could target overrides instead of full pattern swaps. **But:** see Deprecation Watch below — Pattern Overrides is being absorbed into Block Fields/Bindings (#77199); favor the new Block Fields shape if scoping new work. | `inc/LLM/TemplatePartPrompt.php`, `src/template-parts/TemplatePartRecommender.js` |
| Per-block custom CSS attribute | 22.5 | New advisory channel for "scoped CSS" suggestions; also a new attribute Flavor Agent must respect when synthesizing apply diffs. | `src/inspector/BlockRecommendationsPanel.js`, `src/store/update-helpers.js` |
| Visual Revisions UI (text + block diff) | 22.6 | `AIReviewSection` can render through the native diff once exported, removing bespoke diff logic. | `src/components/AIReviewSection.js` |
| Custom CSS selectors in `block.json` | 22.7 | Style Book operations can target block elements without core-side patches; expand the operation vocabulary. | `inc/LLM/StylePrompt.php`, `src/style-book/StyleBookRecommender.js` |
| Style variation transforms preview | 22.7 | Review-before-apply UX is more native; Style Book preview can lean on the native preview component. | `src/style-book/StyleBookRecommender.js` |
| Content Guidelines experimental REST + CPT | 22.7 | Potential grounding source for content recommendations. **Coordinate with Workstream D**: the upstream design is still in discussion (#75171). Flavor Agent's bridge already reads `wp_guideline` / `wp_guideline_type`; the 22.7 experiment may or may not become the public API. Hold write migration. | `inc/LLM/WritingPrompt.php` |
| `registerConnector()` / `unregisterConnector()` | 22.8 | Public extension point if Flavor Agent ever ships a self-contained connector independent of the user's default. | None today; track for future work |
| Button pseudo-state styling in Global Styles | 22.8 | New operation type the Global Styles recommender should learn to emit. | `inc/LLM/StylePrompt.php` |
| Revisions row for templates, template parts, patterns | 23.0 | Some apply paths can hand undo to core revisions instead of growing the activity store. Design effort, not just plumbing — the activity-state-machine has ordered undo that core revisions does not match. | `inc/Activity/Repository.php`, `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js` |
| DataForm/DataViews v2 (combobox, adaptiveSelect, validation) | WP 7.0 | Settings page can drop bespoke validation and combobox handling. | `src/admin/settings-page-controller.js` |
| Real-Time Collaboration on by default | 22.7 (opt-in for 7.0 beta) | Apply paths must re-check `RecommendationResolvedSignature` immediately before mutation under RTC; consider an "another editor is active" guard. | `src/store/index.js` apply actions, `inc/Support/RecommendationResolvedSignature.php` |

## Still Experimental — Shims Required

| API | Status | Tracking | Flavor Agent file |
| --- | --- | --- | --- |
| `__experimentalRegisterBlockPattern` | No stable replacement; no current top-level tracking issue (gutenberg#48743 closed 2026-02-11). Stabilization continues piecemeal. | n/a | `src/patterns/compat.js`, `src/patterns/pattern-settings.js` |
| `__experimentalAdditionalBlockPatterns` / `__experimentalAdditionalBlockPatternCategories` | No stable replacement | n/a | `src/patterns/pattern-settings.js` |
| Inserter DOM selectors (no API equivalent) | No public API | gutenberg#40316 (legacy) | `src/patterns/inserter-dom.js`, `src/patterns/find-inserter-search-input.js` |

Do not collapse these tiers without a stable replacement landing first.

## WP 7.1 Iteration Issues To Watch

These are the active 7.1 iteration issues with direct or adjacent collisions to Flavor Agent surfaces. Each iteration issue is the canonical scope for that strand of 7.1 work.

| Iteration | Issue | Status | Flavor Agent collision |
| --- | --- | --- | --- |
| Navigation in Sidebar | `gutenberg#77069` | Active | Direct overlap. Core is shipping a new sidebar surface for navigation management. Flavor Agent's `core/navigation` Inspector embed (`src/inspector/NavigationRecommendations.js`) needs to confirm whether the embedded recommendation panel still applies in the new surface, or whether to project recommendations into the new sidebar instead. |
| Block Bindings | `gutenberg#77199` | Active | Pattern Overrides is being absorbed into Block Fields + Bindings; the old Pattern Overrides API surface is on a deprecation track. `inc/LLM/TemplatePartPrompt.php` and `src/template-parts/TemplatePartRecommender.js` need to follow the Block Fields shape rather than scoping new work to Pattern Overrides. |
| Content Guidelines | `gutenberg#75171` | In discussion | Direct overlap with `inc/LLM/WritingPrompt.php` and Workstream D. Site-wide editorial rules upstream may converge with or replace Flavor Agent's guideline bridge. Hold write migration. |
| DataViews / DataForm 7.1 | `gutenberg#76045` | Active | Site Editor screen extensibility (Pages, Templates, Patterns), `register_field` server-side, DataForm in PHP-only blocks. `src/admin/activity-log.js` and the Settings page may benefit from the v3-ish primitives. |
| Pattern Editing 7.1 | `gutenberg#75717` | Active | Simplified pattern editing called out in 2026 big-picture goals. Watch for additional stable surface area in `src/patterns/compat.js`. |
| Block Fields 7.1 | `gutenberg#75037` | Active | Foundation for Bindings UIs. Affects pattern apply paths and template-part recommendations. |
| Block Visibility breakpoints | `gutenberg#75707` | Active | theme.json-driven responsive breakpoints. Inspector advisory and executable classifiers must respect breakpoint-driven visibility. |
| Block Supports & Design Tools 7.1 | `gutenberg#76525` | Active | New Inspector controls land here; the `editor.BlockEdit` projection in `src/inspector/InspectorInjector.js` should test against new controls before stabilization. |
| Collaborative Editing 7.1 | `gutenberg#76377` | In discussion | Workflow controls, attribution, accessibility for RTC. Apply paths must coexist with CRDT operations. |
| Notes 7.1 | `gutenberg#76316` | Active | Editorial notes alongside RTC. Adjacent to, but distinct from, Flavor Agent's activity tail. |
| Block Styles in Inspector | `gutenberg#77595` | Active | Display inherited Global Styles + style variations in Block Inspector. Adjacent to the Inspector style projection in `src/inspector/BlockRecommendationsPanel.js`. |
| Theme.json controls disable/enable | `gutenberg#71013` | Active | First-class block locking via theme.json. Affects every Inspector projection in Flavor Agent — locked attributes must drop out of executable updates. |
| `contentOnly` audit | `gutenberg#65778` | Active | May shift which attributes are considered locked under contentOnly. `src/store/update-helpers.js` content-only filtering needs to track changes. |
| Design System: AI for agents | `gutenberg#77205` | Tracking | Gutenberg-internal AI-surface tracking issue. Watch for Inspector-side patterns and primitives Flavor Agent should adopt rather than reimplement. |

## Active GitHub Project Boards

These boards are checked during refresh; only those with Flavor Agent collisions are tracked here.

| Board | Updated | Why this matters |
| --- | --- | --- |
| `WordPress/projects/271` — WP 7.0 Editor Tasks | 2026-04-29 | Winding down as 7.0 ships May 20. |
| `WordPress/projects/291` — WP 7.1 Editor Tasks | 2026-04-28 | Active editor planning board for 7.1; cross-reference with the iteration issues above. |
| `WordPress/projects/240` — AI Planning & Roadmap | 2026-04-28 | Tracked separately in `wordpress-ai-roadmap-tracking.md`. |
| `WordPress/projects/255` — MCP Adapter Planning | 2026-04-29 | Separate from #240. Adapter version, transports, and ability bridge changes flow through here. |
| `WordPress/projects/229` — Design Systems Backlog | recent | Drives `@wordpress/ui` (#76135). Long-term reshape of every Inspector control surface. |
| `WordPress/projects/146` — Increase Gutenberg Extensibility | recent | Curated extension-point work; relevant when an Inspector or Site Editor extension point Flavor Agent needs is on the list. |
| `WordPress/projects/281` — React 19 Upgrade | recent | Track for breaking ref/forwardRef and Strict Mode side-effect changes that affect the editor script. |

## Deprecation Watch

| Item | Deprecated in | Removal | Migration | Flavor Agent file |
| --- | --- | --- | --- | --- |
| Interactivity API `state.navigation` (including `hasStarted`/`hasFinished`) | WP 7.0 | A future version (TBA) | Use `watch()` + `state.url`. WP 7.1 will add an official navigation-state mechanism. | None today (Flavor Agent does not introspect Interactivity router state). Track in case advisory surfaces ever recommend Interactivity-using blocks. |
| Pattern Overrides as a standalone API | Effectively deprecated by 7.1 work | Absorbed into Block Bindings + Block Fields (#77199) | Follow Block Fields shape | `inc/LLM/TemplatePartPrompt.php`, `src/template-parts/TemplatePartRecommender.js` |
| `@wordpress/scripts` (likely successor: esbuild-based `@wordpress/build`) | Soft, post-7.0 | TBA | New build pipeline auto-generates PHP registration | `package.json`, build configuration |
| `__experimentalAdditional*` pattern settings keys | No formal deprecation; piecemeal stabilization | TBA | Stable per-feature replacements as they ship | `src/patterns/pattern-settings.js` |

## Action Implications For Flavor Agent

Concrete tasks driven by this matrix, in priority order. Strike through completed work when shipped.

1. ~~Wire `wp_ai_client_prevent_prompt` into `inc/LLM/WordPressAIClient.php` so a site-level AI kill switch short-circuits before provider routing.~~ **Done 2026-04-29.** `ensure_text_generation_supported()` probes the filter to return a labeled `prompt_prevented` 503 error (instead of the misleading `missing_text_generation_provider` 400) when AI is blocked while a provider is configured; `call_prompt_method()` catches `Prompt_Prevented_Exception` for the race-condition path. The error code flows naturally into the activity log via `Agent_Controller::persist_request_diagnostic_failure_activity()`.
2. ~~Add behavior annotations to every ability registration in `inc/Abilities/Registration.php` and per-category ability files. Read abilities default to `readonly: true`; recommendation/apply-like surfaces declare method-safe MCP and idempotency hints correctly.~~ **Done 2026-04-29.** Both meta helpers (`public_recommendation_meta()`, `readonly_rest_meta()`) emit nested `annotations` blocks. Recommendation abilities intentionally do not set WP-format `readonly` or direct MCP `readOnlyHint`; they stay on POST and advertise only `destructive:false` and `idempotent:false`. Data-read abilities set `readonly:true`, `destructive:false`, and `idempotent:true`. Tests at `tests/phpunit/RegistrationTest.php` cover both ability groups plus complete registered-ability coverage.
3. ~~Re-audit `src/patterns/compat.js` for tier-collapse opportunities now that Pattern Editing (22.5) is stable. Track unchanged: `__experimentalRegisterBlockPattern`, `__experimentalAdditional*` keys, inserter DOM selectors.~~ **Done 2026-04-30.** Production callers only use read-side pattern settings, allowed-pattern selectors, and diagnostics; unused `setBlockPatterns()` / `setBlockPatternCategories()` write helpers and their write-only tests were removed, while required experimental read shims remain.
4. **Watch `gutenberg#77069` (Navigation in Sidebar)**. When the new navigation sidebar surface lands in 7.1, decide whether `src/inspector/NavigationRecommendations.js` continues to embed in the block Inspector or projects into the new sidebar. **Not yet covered by an existing workstream.**
5. **Watch `gutenberg#77199` (Block Bindings + Block Fields)**. Pattern Overrides is being absorbed; scope new template-part apply work against Block Fields, not Pattern Overrides. **Not yet covered by an existing workstream.**
6. **Watch `gutenberg#75171` (Content Guidelines)** alongside Workstream D. The upstream design is still in discussion; do not pre-commit Flavor Agent's bridge to either the 22.7 experiment or a future `wp_register_guideline()` API until core's final write/defaults model is announced. **Tracked under Workstream D in the remediation plan.**
7. Watch RTC stable rollout. Once core RTC ships post-7.0-beta, add a freshness re-check in `src/store/index.js` apply actions immediately before mutation. Coordinate with `gutenberg#76377`. **Not yet covered by an existing workstream.**
8. Evaluate handing template/template-part undo to core revisions (23.0) for surfaces where the activity-state-machine duplicates revision behavior. Design effort, not just plumbing. **Not yet covered by an existing workstream.**

When any of these moves from "watch" to "act", add a workstream to `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md` rather than tracking implementation details here.

## Mapping To The Remediation Plan

`docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md` is the action-oriented backlog. Cross-walk:

| Remediation workstream | Driver from this matrix |
| --- | --- |
| A — Pattern Surface Reset (Done 2026-04-23) | Pattern API stabilization |
| B — Block Inspector Ownership Reset (Done 2026-04-23) | Inspector slot stabilization |
| C — Provider Ownership Migration (Done 2026-04-28) | `Settings > Connectors` 22.7, `registerConnector` 22.8, AI Client 7.0 |
| D — Guidelines Bridge and Migration (read bridge implemented 2026-04-28; write/public API migration pending) | `gutenberg#75171` Content Guidelines, 22.7 experimental REST + CPT |
| E — Settings Screen Modernization (Pending) | DataForm/DataViews v2 in 7.0; `@wordpress/ui` (#76135) long-term |

Action implications 4, 5, 7, and 8 above describe upstream pressures with no corresponding workstream yet. Implications 1 (`wp_ai_client_prevent_prompt`) and 2 (`meta.annotations`) shipped 2026-04-29 as small additive changes in `inc/LLM/WordPressAIClient.php` and `inc/Abilities/Registration.php`; implication 3 (`pattern compatibility audit`) shipped 2026-04-30 as a scoped cleanup in `src/patterns/pattern-settings.js` and `src/patterns/compat.js` (no workstream needed).

## Related References

- `docs/reference/wordpress-ai-roadmap-tracking.md` — board pressure (project 240) and active overlap with Flavor Agent surfaces.
- `docs/wordpress-7.0-gutenberg-23-impact-brief.md` — point-in-time compatibility brief for the WP 7.0 release cycle.
- `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md` — active workstream backlog for handing ownership back to core.
- `docs/wordpress-7.0-developer-docs-index.md` — broader upstream source map for WP 7.0.
- `docs/wp7-migration-opportunities.md` — older migration snapshot.
