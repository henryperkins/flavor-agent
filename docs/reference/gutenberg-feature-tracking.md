# Gutenberg Feature Tracking

This document tracks Gutenberg plugin releases, their stabilized APIs, and the forward-looking editor roadmap alongside the Flavor Agent code paths each one affects.

Use it when you need to answer:

- which Gutenberg release stabilized an API Flavor Agent currently shims
- which new editor extension point is worth adopting next
- which API surface in the repo can be simplified now that core caught up
- which iteration issue or project board signals a near-term collision with a Flavor Agent surface

For project-board pressure on the AI program (project 240), use `wordpress-ai-roadmap-tracking.md`.
For point-in-time WP 7.0 release-cycle compatibility analysis, use `docs/wordpress-7.0-gutenberg-23-impact-brief.md`.

## Source And Refresh

Primary sources:

- Gutenberg release posts: `https://make.wordpress.org/core/tag/gutenberg-new/`
- WordPress release schedule and Phase status: `https://wordpress.org/about/roadmap/`
- 2026 yearly priorities: `https://make.wordpress.org/project/2026/01/23/big-picture-goals-for-2026/`
- WordPress org GitHub project boards: `https://github.com/orgs/WordPress/projects`
- Gutenberg iteration issues: filter the gutenberg repo by label `[Type] Iteration`
- Block Editor Handbook: `https://developer.wordpress.org/block-editor/`

Secondary signals (paraphrase primary sources, not authoritative): WP Tavern, agency blogs. Treat any version-specific feature claim that is not on `make.wordpress.org` or in a tracking issue as unconfirmed.

Snapshot date: 2026-07-02.
Latest Gutenberg release at snapshot: `23.5.0` (published 2026-07-01; local static alignment updated WPDS color-token compatibility and the direct `@wordpress/theme` dependency. Real-browser smoke against local WordPress `7.1-alpha-62619` + Gutenberg `23.5.0` passed Flavor Agent surface mount/overflow checks for AI Activity, Global Styles, Style Book, templates, and template parts, with one representative upstream/runtime observation: Gutenberg emits the React 19 `inert` boolean-attribute console warning. A prior `/wp/v2/registered-templates` 500 came from an accidental non-baseline AI Services activation; after removing `ai-services`, the route returns 200 while core/Gutenberg still emits null-post-type capability warnings.)
WordPress core: `7.0` "Armstrong" was released on 2026-05-20. The 2026-05-14 Field Guide remains the current 7.0 developer source map; real-time collaboration was removed from the 7.0 release on 2026-05-08.

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
| 3 — Collaboration / Workflows | Active | Real-time collaboration did not ship in WordPress 7.0. Keep Notes (#76316), 7.1 collaborative editing (#76377), accessibility (#73942), CRDT cleanup, and Gutenberg-plugin RTC reliability fixes on watch, but do not treat RTC as a 7.0 core contract. |
| 4 — Multilingual | Future | No active code work. Reference: `gutenberg#59250`. No primary-source timeline. |

## Versions Tracked

| Version | Date | Headline APIs and stabilizations |
| --- | --- | --- |
| 22.4 | 2026-01-20 | Pattern Overrides extended to all custom blocks via Block Bindings; Block Visibility by Screen Size (experimental); Font Library + Global Styles for classic/hybrid themes |
| 22.5 | 2026-02-04 | Per-block custom CSS (`has-custom-css`); Pattern Editing stabilized; Block Visibility by viewport stabilized |
| 22.6 | 2026-02-25 | Visual Revisions UI (text + block diff); SVG Icon Registration + REST `/wp/v2/icons`; client-side media processing stabilized |
| 22.7 | 2026-03-11 | `Settings > Connectors`; custom CSS selectors in `block.json`; Style variation transforms preview; patterns in contentOnly; RTC on by default in the Gutenberg plugin line, later removed from WP 7.0 core; Content Guidelines experimental REST + CPT |
| 22.8 | 2026-03-25 | `registerConnector()` / `unregisterConnector()`; Button pseudo-state styling in Global Styles; Site Identity moves into Design panel; AVIF in client uploads; command palette in admin bar |
| 23.0 | 2026-04-22 | Revisions row for templates, template parts, patterns; `__rtc_compatible_meta_box`; DataForm date-range; `wordpress/ui` CSS-defense module; Site Identity panel consolidation |
| (WordPress 7.0) | released 2026-05-20 | AI Client `wp_ai_client_prompt()` + `WP_AI_Client_Prompt_Builder` class + `using_model_preference()` + `wp_ai_client_prevent_prompt` filter; client-side Abilities API (`@wordpress/abilities`, `@wordpress/core-abilities`); ability `meta.annotations.{readonly,destructive,idempotent}`; `Settings > Connectors` and Connectors API with `wp_connectors_init`; DataForm/DataViews v2 with new Activity and Details layouts; PHP-only block registration (`'supports' => array( 'autoRegister' => true )`); pattern/contentOnly changes with the `block_editor_settings_all` opt-out hook and the `disableContentOnlyForUnsyncedPatterns` editor setting; block design-tool/support additions; `@wordpress/boot` package for custom Site Editor pages; Interactivity API `watch()` / `data-wp-watch` and server-populated `state.url`. RTC was removed before final. 2026-05-14 Field Guide is the canonical map; 2026-05-17 edit added the DataViews dev note plus `textIndent`/margin-free styles; 2026-05-18 edit dropped the standalone Notes section. |
| 23.1 | 2026-05-07 | `@wordpress/ui` `Drawer` and `Autocomplete` primitives; developer-preview `@wordpress/grid`; custom taxonomies and media editor experiments; Classic block inserter filter; RTC reliability fixes in the Gutenberg plugin; Core Abilities readiness promise and Guidelines renaming/type-awareness remain compatibility context for Flavor Agent. |
| 23.2 | 2026-05-20 | Connectors page read-only filesystem handling; default connector `plugin.is_active` callback support; connector settings auto-registration gated on the referenced plugin being active; AI plugin callout copy clarified for AI connectors; responsive global block styles with states; `@wordpress/ui` SelectControl, motion tokens, and grid/modal/select polish. |
| 23.3 | 2026-06-03 | React 19 first landed in the plugin line, then `23.3.2` reverted it to React 18 after JSX-runtime incompatibilities; style-state and inserter DOM checks stayed relevant for Flavor Agent even after the revert. |
| 23.4 | 2026-06-17 | React 19 returns behind an experiment flag; Site Editor follows the admin color scheme; Pattern labels replace "Block pattern" in core UI; `core/loginout` is allowed inside `core/navigation-submenu`; DataViewsPicker gains `pickerActivity`; `@wordpress/theme` drops density support and adds element-size design tokens; entity view config becomes a filterable API; Connectors blocks API-key autocomplete. |
| 23.5 | 2026-07-01 | Media Editor cropping and Cover block crop support; unified device preview with resizable editor canvas; Global Styles `textShadow`; Gutenberg minimum WP version becomes 6.9; `@wordpress/theme` publicly exports `ThemeProvider`, provides token defaults without a runtime provider, and renames public `--wpds-color-bg-*` / `--wpds-color-fg-*` tokens to `--wpds-color-background-*` / `--wpds-color-foreground-*`; DataViews view-config REST work moves toward core readiness; Block Fields fixes a pattern-overrides binding crash. |

WordPress core release cadence at snapshot: WP 7.0 shipped May 20, 2026; WP 7.1 August 19, 2026; WP 7.2 December 10, 2026 (per the WordPress.org roadmap).

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
| Global Styles `textShadow` | 23.5 | Global Styles can now configure text shadows independently from the existing shadow support path. Flavor Agent currently validates/recommends the existing `shadow` style path only; adding `textShadow` needs a scoped style-contract update rather than a token-only compatibility patch. | `src/store/update-helpers.js`, `inc/LLM/Prompt.php`, `inc/LLM/StylePrompt.php` |
| Content Guidelines experimental REST + CPT | 22.7 | Potential grounding source for content recommendations. **Coordinate with Workstream D**: the upstream design is still in discussion (#75171). Flavor Agent's bridge already reads `wp_guideline` / `wp_guideline_type`; the 22.7 experiment may or may not become the public API. Hold write migration. | `inc/LLM/WritingPrompt.php` |
| `registerConnector()` / `unregisterConnector()` | 22.8 | Public extension point if Flavor Agent ever ships a self-contained connector independent of the user's default. | None today; track for future work |
| Button pseudo-state styling in Global Styles | 22.8 | New operation type the Global Styles recommender should learn to emit. | `inc/LLM/StylePrompt.php` |
| Revisions row for templates, template parts, patterns | 23.0 | Some apply paths can hand undo to core revisions instead of growing the activity store. Design effort, not just plumbing — the activity-state-machine has ordered undo that core revisions does not match. | `inc/Activity/Repository.php`, `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js` |
| Entity view configuration filter | 23.4 | Core exposes entity view configuration through a filterable API. Flavor Agent already normalizes the unchanged REST shape in `editor-entity-contracts`; adopt the direct API only if a future surface needs server-side filtering or a stable selector that replaces local fallbacks. | `src/utils/editor-entity-contracts.js` |
| DataViewsPicker `pickerActivity` layout | 23.4 | Future fit for AI Activity and Global Styles revision-picking affordances, but it is not a required migration for the current DataViews feed. | `src/admin/activity-log.js`, `src/admin/activity-log-utils.js` |
| DataForm/DataViews v2 (combobox, adaptiveSelect, validation) | WP 7.0 | Settings page can drop bespoke validation and combobox handling. | `src/admin/settings-page-controller.js` |
| DataViews Activity layout and Details layout | WP 7.0 (added in the 2026-05-17 Field Guide edit) | `Settings > AI Activity` already uses DataViews for the feed, pending external style-apply controls, and custom detail blocks. The new native Activity layout may eventually replace the bespoke feed layout, and the Details layout may absorb the custom side panel — both are future enhancement candidates, not migrations required for 7.0. | `src/admin/activity-log.js`, `src/admin/activity-log-utils.js` |
| `block_editor_settings_all` filter for pattern `contentOnly` opt-out | WP 7.0 | If Flavor Agent ever needs a per-context override for the new pattern-level `contentOnly` default, this is the documented hook. Today the plugin already respects whatever `contentOnly` the editor enforces and does not need to opt out. | None today; track for future contentOnly recommender work |
| Real-Time Collaboration compatibility guards | Gutenberg plugin watch item; removed from WP 7.0 core | Apply paths may eventually need a final freshness re-check immediately before mutation under RTC, but this is no longer a 7.0 release requirement. | `src/store/index.js` apply actions, `inc/Support/RecommendationResolvedSignature.php` |

## Still Experimental — Shims Required

| API | Status | Tracking | Flavor Agent file |
| --- | --- | --- | --- |
| `__experimentalRegisterBlockPattern` | No stable replacement; no current top-level tracking issue (gutenberg#48743 closed 2026-02-11). Stabilization continues piecemeal. | n/a | `src/patterns/compat.js`, `src/patterns/pattern-settings.js` |
| `__experimentalAdditionalBlockPatterns` / `__experimentalAdditionalBlockPatternCategories` | No stable replacement | n/a | `src/patterns/pattern-settings.js` |
| Inserter DOM selectors (no API equivalent) | No public API | gutenberg#40316 (legacy) | `src/patterns/inserter-dom.js` |
| Global Styles current entity/base/variation selectors | Stable selectors are probed first; experimental fallbacks remain required on current Gutenberg trunk | n/a | `src/global-styles/selectors.js` |

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

| Gutenberg 23.x item | Flavor Agent decision | Release gate |
| --- | --- | --- |
| Tabs block WCAG rename | No action; Flavor Agent does not reference `core/tabs` or `core/tab` block names. | Run `rg -n "\\bcore/(tabs|tab)\\b" inc src tests docs readme.txt STATUS.md` during release validation. |
| `react-dom/client` externalized | No action until the repo bumps `@wordpress/scripts` for the WP 7.x toolchain. | Run `npm run build` after dependency bumps. |
| Design Tools viewport visibility key/value swap | No action; Flavor Agent reads persisted `metadata.blockVisibility.viewport.{key}: bool`, not the Inspector control prop shape. | Keep `ViewportVisibilityAnalyzer` tests green. |
| Strip per-block custom CSS on save without `edit_css` | No action; Flavor Agent does not generate per-block custom CSS recommendations today. | Revisit before adding scoped custom CSS operations. |
| Connectors page read-only filesystem handling | No code change; Flavor Agent deep-links to the core Connectors screen and should let core explain plugin-install constraints. | Keep `flavorAgentData.connectorsUrl` and settings/help links pointed at `admin_url( 'options-connectors.php' )`; smoke manually in read-only deployments only if that stack is in scope. |
| Connector `plugin.is_active` support and settings auto-registration gating | No connector registration change today; Flavor Agent should continue probing the WordPress AI Client for text-generation support instead of treating registered connector settings as proof of availability. | Provider/connector tests must register active providers before asserting connector-backed metadata or model readiness. |
| AI plugin callout copy clarified for AI connectors | No local UI change unless Flavor Agent quotes the upstream callout. The plugin's own copy already names `Settings > Connectors` as the text-generation setup owner. | No release gate. |
| Experimental Media Editor and AI media issues `WordPress/ai#325` / `#238` | No code change; Flavor Agent does not own Media Library, Media Editor, image-generation, focal-point, or crop-metadata surfaces. | Keep as roadmap watch-only unless a future Flavor Agent feature adds media-editing recommendations or upstream shares the same recommendation/apply infrastructure. |
| React 19 experiment in Gutenberg 23.4+ | No dependency bump is required for this alignment pass. Treat React 19 as a runtime smoke target and watch for ref callback cleanup, bundled `react/jsx-runtime`, DataViews `inert`, and selector-stability warnings. | Browser pass with the React 19 experiment enabled when the local stack can activate it; do not infer coverage from Gutenberg version alone. |
| Pattern labels now prefer "Patterns" over "Block patterns" | Local user-facing Pattern Inserter notices now use "Pattern" / "patterns" for the direct snackbar and no-patterns notice. Keep "block pattern" only for technical/API docs where WordPress uses that concept. **Done 2026-06-18.** | `npm run test:unit -- --runTestsByPath src/patterns/__tests__/PatternRecommender.test.js --runInBand`; matching Playwright snackbar assertion updated. |
| `core/loginout` allowed inside `core/navigation-submenu` | Navigation parsing now labels nested Loginout items as `Log in/out` and treats `loginout` as a supported navigation item instead of an unknown non-link type. **Done 2026-06-18.** | `vendor/bin/phpunit --filter NavigationParserTest`; browser Navigation submenu smoke still open. |
| `@wordpress/theme` density removal and element-size tokens | No code change from static review. AI Activity/DataViews and theme-token surfaces should be browser-smoked before release, especially if `@wordpress/*` dependencies are bumped. | Keep `src/admin/activity-log-utils.js` density use under watch; run AI Activity and theme-token browser checks. |
| `@wordpress/theme` bg/fg color token rename | Flavor Agent CSS now prefers `--wpds-color-background-*` / `--wpds-color-foreground-*` while retaining legacy `--wpds-color-bg-*` / `--wpds-color-fg-*` fallbacks for the currently installed package line. The admin runtime bridge emits the new brand/foreground/background tokens and keeps legacy aliases. `@wordpress/theme` is now a direct dependency because `src/admin/wpds-runtime.css` imports its prebuilt design tokens. | `npm run test:unit -- --runTestsByPath src/admin/__tests__/wpds-token-compatibility.test.js --runInBand`; run `npm run build` after any package bump; browser-smoke AI Activity/DataViews and editor/theme-token surfaces on a real 23.5 runtime. |
| Gutenberg 23.5 real-browser smoke | The 2026-07-02 local browser pass on WordPress `7.1-alpha-62619` + Gutenberg `23.5.0` passed Flavor Agent visibility/overflow checks for AI Activity/DataViews at desktop and 390px mobile, Global Styles, Style Book, template edit canvas, and template-part edit canvas. No Flavor Agent page exceptions were observed. The prior template-hub 500 was invalid representative evidence because `ai-services` was accidentally active; after removing that non-baseline plugin, `/wp-json/wp/v2/registered-templates` returns 200 and still exposes core/Gutenberg null-post-type capability warnings. Gutenberg also emits the React 19 `inert` boolean-attribute console warning. | Keep the browser pass as "pass with known environmental observations" until the upstream/core `registered-templates` warning and React warning are resolved; do not treat those as Flavor Agent regressions. |
| Global Styles `textShadow` | No local code change in the WPDS-token compatibility slice. Treat this as feature parity work only after a style-contract plan decides whether Flavor Agent should recommend/apply the new text-shadow control. | Fresh plan and tests before changing `src/store/update-helpers.js` and PHP prompt/validation contracts; include Global Styles text-shadow visibility in the 23.5 browser pass. |
| Tooltip migration to `@wordpress/ui` | No immediate migration. Existing `@wordpress/components` `Tooltip` calls stay unless a dependency bump or runtime warning makes the change necessary. | Audit `LinkedEntityText`, `TemplateRecommender`, and `TemplatePartRecommender` after any package update. |
| Developer Docs corpus coverage for 23.4 | Public endpoint validation on 2026-06-18 returned current Make/Core and Developer Blog sources, but the exact 23.4 query still ranked the 23.3 Make/Core post and June developer blog above the 23.4 release post. | Refresh or wait for the scheduled docs AI Search corpus run, then record evidence under `docs/validation/`. |
| Developer Docs corpus coverage for 23.5 | Managed docs AI Search on 2026-07-02 returned older Global Styles / Handbook material for a 23.5 WPDS-token and `textShadow` query rather than the 2026-07-01 Make/Core release post. Use Make/Core plus Gutenberg PRs as current release grounding until the managed corpus refreshes. | Re-run docs AI Search after corpus refresh and record evidence under `docs/validation/` if it becomes a release gate. |

1. ~~Wire `wp_ai_client_prevent_prompt` into `inc/LLM/WordPressAIClient.php` so a site-level AI kill switch short-circuits before provider routing.~~ **Done 2026-04-29.** `ensure_text_generation_supported()` probes the filter to return a labeled `prompt_prevented` 503 error (instead of the misleading `missing_text_generation_provider` 400) when AI is blocked while a provider is configured; `call_prompt_method()` catches `Prompt_Prevented_Exception` for the race-condition path. The error code flows naturally into the activity log via `Agent_Controller::persist_request_diagnostic_failure_activity()`.
2. ~~Add behavior annotations to every ability registration in `inc/Abilities/Registration.php` and per-category ability files. Read abilities default to `readonly: true`; recommendation/apply-like surfaces declare method-safe MCP and idempotency hints correctly.~~ **Done 2026-04-29.** Both meta helpers (`public_recommendation_meta()`, `readonly_rest_meta()`) emit nested `annotations` blocks. Recommendation abilities intentionally do not set WP-format `readonly` or direct MCP `readOnlyHint`; they stay on POST and advertise only `destructive:false` and `idempotent:false`. Data-read abilities set `readonly:true`, `destructive:false`, and `idempotent:true`. Tests at `tests/phpunit/RegistrationTest.php` cover both ability groups plus complete registered-ability coverage.
3. ~~Re-audit `src/patterns/compat.js` for tier-collapse opportunities now that Pattern Editing (22.5) is stable. Track unchanged: `__experimentalRegisterBlockPattern`, `__experimentalAdditional*` keys, inserter DOM selectors.~~ **Done 2026-04-30.** Production callers only use read-side pattern settings, allowed-pattern selectors, and diagnostics; unused `setBlockPatterns()` / `setBlockPatternCategories()` write helpers and their write-only tests were removed, while required experimental read shims remain.
4. **Watch `gutenberg#77069` (Navigation in Sidebar)**. When the new navigation sidebar surface lands in 7.1, decide whether `src/inspector/NavigationRecommendations.js` continues to embed in the block Inspector or projects into the new sidebar. **Not yet covered by an existing workstream.**
5. **Watch `gutenberg#77199` (Block Bindings + Block Fields)**. Pattern Overrides is being absorbed; scope new template-part apply work against Block Fields, not Pattern Overrides. **Not yet covered by an existing workstream.**
6. **Watch `gutenberg#75171` (Content Guidelines)** alongside Workstream D. The upstream design is still in discussion; do not pre-commit Flavor Agent's bridge to either the 22.7 experiment or a future `wp_register_guideline()` API until core's final write/defaults model is announced. **Tracked under Workstream D in the remediation plan.**
7. Watch RTC stable rollout after its removal from WordPress 7.0. If core later ships collaborative editing, add a freshness re-check in `src/store/index.js` apply actions immediately before mutation. Coordinate with `gutenberg#76377`. **Not yet covered by an existing workstream.**
8. Evaluate handing template/template-part undo to core revisions (23.0) for surfaces where the activity-state-machine duplicates revision behavior. Design effort, not just plumbing. **Not yet covered by an existing workstream.**

When any of these moves from "watch" to "act", record the workstream in `docs/SOURCE_OF_TRUTH.md` or the relevant feature doc rather than tracking implementation details here.

## Workstream History

The earlier overlap-remediation plan tracked these workstreams; results have been folded back into the live source tree.

| Workstream | Status | Driver from this matrix |
| --- | --- | --- |
| A — Pattern Surface Reset | Done 2026-04-23 | Pattern API stabilization |
| B — Block Inspector Ownership Reset | Done 2026-04-23 | Inspector slot stabilization |
| C — Provider Ownership Migration | Done 2026-04-28 | `Settings > Connectors` 22.7, `registerConnector` 22.8, AI Client 7.0 |
| D — Guidelines Bridge and Migration | Read bridge implemented 2026-04-28; write/public API migration pending | `gutenberg#75171` Content Guidelines, 22.7 experimental REST + CPT |
| E — Settings Screen Modernization | Pending | DataForm/DataViews v2 in 7.0; `@wordpress/ui` (#76135) long-term |

Action implications 4, 5, 7, and 8 above describe upstream pressures with no corresponding workstream yet. Implications 1 (`wp_ai_client_prevent_prompt`) and 2 (`meta.annotations`) shipped 2026-04-29 as small additive changes in `inc/LLM/WordPressAIClient.php` and `inc/Abilities/Registration.php`; implication 3 (`pattern compatibility audit`) shipped 2026-04-30 as a scoped cleanup in `src/patterns/pattern-settings.js` and `src/patterns/compat.js`.

## Related References

- `docs/reference/wordpress-ai-roadmap-tracking.md` — board pressure (project 240) and active overlap with Flavor Agent surfaces.
- `docs/reference/gutenberg-23-3-nightly-validation-checklist.md` — manual runtime checklist for Gutenberg 23.3, React 19, Connectors, Abilities bridge, Inspector style states, pattern inserter, and Style Book checks.
- `docs/validation/2026-06-18-gutenberg-23-4-alignment.md` — static/code alignment record plus open 23.4 runtime and corpus-refresh evidence.
- `docs/wordpress-7.0-gutenberg-23-impact-brief.md` — point-in-time compatibility brief for the WP 7.0 release cycle.
- `docs/wordpress-7.0-developer-docs-index.md` — broader upstream source map for WP 7.0.
- `docs/wp7-migration-opportunities.md` — older migration snapshot.
