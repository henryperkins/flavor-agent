# Flavor Agent Impact Brief: WordPress 7.0 and Gutenberg 23

> Compiled: 2026-04-23
> Reviewed: 2026-05-21
> Scope: dated upstream release posture plus the concrete impact on this repo's shipped code and docs
> Status: retained as a point-in-time compatibility snapshot for the WordPress 7.0 / Gutenberg 23.x release cycle. Use `docs/reference/gutenberg-feature-tracking.md` for ongoing Gutenberg/API tracking and `docs/reference/wordpress-ai-roadmap-tracking.md` for ongoing WordPress AI roadmap pressure.
> Use `docs/wordpress-7.0-developer-docs-index.md` for the broader upstream source map and `docs/wp7-migration-opportunities.md` for the older migration snapshot.

## Current Upstream Snapshot

| Topic | Current state | Why it matters here |
| --- | --- | --- |
| WordPress 7.0 schedule | WordPress 7.0 "Armstrong" was released on 2026-05-20 after the extended release cycle. The 2026-05-14 Field Guide remains the current developer source map. | Repo docs can now frame 7.0 as released, while keeping the Field Guide as the feature map for compatibility checks. |
| WordPress 7.0 release mode | 7.0 is released as of this 2026-05-21 refresh. Real-time collaboration was removed from 7.0 on 2026-05-08 before final release. | Move harness wording from pre-release to stable once the local Docker/runtime stack intentionally follows the stable image. Do not treat RTC as a WordPress 7.0 core requirement. |
| AI plugin latest | AI plugin `1.0.0` was published on 2026-05-19 and announced on 2026-05-21. Its Flavor Agent-relevant items are Request Logging, Connector Approvals, no-provider/removed-provider guidance, and feature-specific provider handling; its Media Editor alt-text work and the future #238/#325 crop/media-editor issues are adjacent media surfaces. | Connector Approval is now a local request-time integration concern, Request Logging is the next activity-strategy decision, and #238/#325 remain out of scope unless Flavor Agent grows media-editing or image-generation surfaces. |
| Gutenberg plugin latest | The latest tracked Gutenberg release is `23.2.0`, published 2026-05-20. Its Flavor Agent-relevant items are the Connectors read-only filesystem UX, `plugin.is_active` callback support, active-plugin-gated connector settings auto-registration, and clarified AI connector callout copy. | Useful for forward-compat testing and upstream watch items, but not a signal that WordPress core 7.0 ships every 23.2 API unchanged. |
| Gutenberg in core | The Block Editor handbook still lists WordPress `7.0.x` as based on Gutenberg `22.6`, with later bug fixes cherry-picked as needed during beta/RC and point releases. | Flavor Agent should keep runtime assumptions anchored to WordPress 7.0 core behavior first, and treat 22.7-23.2 as supplemental compatibility context. |
| Main upstream pressure point | The remaining Field Guide impact for Flavor Agent is not RTC; it is alignment with the 7.0 AI Client, Client-Side Abilities, Connectors, DataViews/DataForms, design tools, pattern/contentOnly behavior, and PHP 7.4 core minimum. | Flavor Agent is already on the core AI/Abilities path, so this is mostly a documentation and regression-watch update rather than new product work. |

## Repo Areas Already Aligned

### WordPress AI Client and Connectors

Flavor Agent already treats the WordPress AI Client plus Connectors as a real first-class runtime path.

- `inc/LLM/WordPressAIClient.php` prefers the WordPress AI service prompt factory when available, falls back to `wp_ai_client_prompt()`, and fails clearly when the core AI client is unavailable.
- `inc/OpenAI/Provider.php` already checks connector registration and connector-backed credentials such as `connectors_ai_openai_api_key`.
- Live docs already frame `Settings > Connectors` as the fallback path rather than a side note.

Impact:

- No architecture change is needed for WordPress 7.0 here.
- Docs should keep acknowledging the broader connector ecosystem, not just the three initial official providers.
- The Field Guide also introduces `WP_AI_Client_Prompt_Builder` and the `using_model_preference()` helper alongside `wp_ai_client_prompt()`; today the wrapper uses the function-style entry point and does not need the builder class, but treat both as the canonical 7.0 AI Client surface for any future prompt-construction work.

### Client-Side Abilities API

Flavor Agent is already well positioned for the JavaScript-side Abilities rollout in WordPress 7.0.

- `inc/Abilities/Registration.php` registers the server-side abilities and categories.
- The repo already targets WordPress 7.0+, so the client-side `core/abilities` hydration is an additive integration surface, not a migration blocker.
- The plugin's own UI can continue to use its scoped REST routes and `@wordpress/data` store.

Impact:

- No immediate code change is required.
- The main value is future browser-agent or admin-tooling interoperability.

### Pattern API Compatibility Layer

The repo is already preserving the right compatibility posture for pattern APIs.

- `src/patterns/pattern-settings.js` probes stable keys first, then falls back to `__experimentalBlockPatterns`, `__experimentalAdditionalBlockPatterns`, `__experimentalBlockPatternCategories`, `__experimentalAdditionalBlockPatternCategories`, and `__experimentalGetAllowedPatterns`.
- Tests cover both stable and experimental branches.

Impact:

- Keep this adapter-backed approach.
- Do not simplify it on the assumption that Gutenberg 23.x implies stable pattern settings in WordPress 7.0 core.

### Stable `role`, `supports.contentRole`, and viewport block visibility

The repo has already adopted the WordPress 7.0-era content and visibility model.

- `src/context/block-inspector.js` and `inc/Context/BlockTypeIntrospector.php` read the stable `role` key and `supports.contentRole`.
- `inc/LLM/Prompt.php`, `src/store/update-helpers.js`, and related tests already understand `metadata.blockVisibility`, including the `viewport` object form.

Impact:

- No remediation is needed here.
- The existing docs can keep framing these as shipped compatibility work, not open migration items.

### Navigation overlays and Site Editor context

Flavor Agent already speaks the stabilized WordPress 7.0 navigation-overlay model.

- `inc/Context/NavigationContextCollector.php` reads `navigation-overlay` template parts.
- `inc/LLM/NavigationPrompt.php` explicitly treats navigation overlays as a first-class template-part area.
- The abilities schema already exposes `navigation-overlay` as an area value.

Impact:

- No new compatibility work is required for WordPress 7.0.

### DataViews admin surface

The plugin's `Settings > AI Activity` admin screen is already built on the same WordPress-native data-view stack that 7.0 keeps evolving.

- `src/admin/activity-log.js` imports `DataViews` from `@wordpress/dataviews/wp` and renders pending external style-apply controls plus custom detail sections beside the feed.

Impact:

- This area should stay under regression watch as Gutenberg iterates, but nothing in the current 7.0 / 23.2 refresh forces a redesign.
- The 2026-05-17 Field Guide edit added a dedicated DataViews dev note describing the new native Activity layout and Details layout for DataViews/DataForm. Treat both as future enhancement candidates for `src/admin/activity-log.js` (the new Activity layout may replace the bespoke feed presentation; the Details layout may absorb the custom side panel) rather than required 7.0 migration work.

### RTC and metabox compatibility

Real-time collaboration is no longer a WordPress 7.0 ship item.

- A repo-wide search does not show Flavor Agent shipping classic `add_meta_box()` editor UI.
- The plugin does not implement RTC persistence, transport, or co-editing behavior.
- The Gutenberg plugin can still carry RTC reliability fixes and future testing paths, but those are not WordPress 7.0 core requirements.

Impact:

- The RTC removal changes documentation posture, not the plugin's current implementation strategy.
- If Flavor Agent later grows live collaborative post-editor features, this should be re-evaluated from scratch.

## Concrete Follow-Ups

### 1. Keep the runtime baseline anchored to WordPress 7.0 core, not a specific Gutenberg 23.x plugin release

The latest tracked Gutenberg release is `23.2.0`, but WordPress `7.0.x` is still documented as based on Gutenberg `22.6` plus cherry-picked fixes.

Action:

- Treat Gutenberg 23.x releases as forward-compat references and testing snapshots.
- Avoid assuming that new Gutenberg 23.x plugin APIs or UI details are available in every WordPress 7.0 environment.
- For the 23.2 connector changes specifically, keep Flavor Agent's readiness logic based on the WordPress AI Client probe path rather than on whether connector settings were auto-registered in REST.

### 2. Update the WP 7.0 harness image when the stable Docker image exists

The repo still pins the Docker-backed WP 7.0 harness to a pre-release image via `FLAVOR_AGENT_WP70_BASE_IMAGE`; the current pinned tag and override instructions live in `docs/reference/local-environment-setup.md`.

Grounding:

- `scripts/wp70-e2e.js`
- `docs/reference/local-environment-setup.md`
- `STATUS.md`

Action:

- Swap this to the official stable `7.0` image after that image exists.
- Until then, docs should say the schedule is known but the harness is still intentionally pre-release.

### 3. Keep connector docs broader than the initial official trio

The upstream Connectors story is now clearly bigger than just OpenAI, Anthropic, and Google.

Action:

- Continue describing connector-backed chat as a first-class path.
- Avoid wording that implies community providers are fringe or unsupported by concept.

### 4. Keep WP 7.0 browser evidence fresh

This item is no longer blocked by local Docker availability or unresolved WP 7.0 E2E reds in the current checkout. `STATUS.md` records that `npm run test:e2e:wp70` passed on 2026-04-29 with `14 passed / 0 failed`, and `output/verify/summary.json` records a full aggregate pass including `e2e-wp70`.

Action:

- Keep the current green evidence in `STATUS.md`, `docs/reference/cross-surface-validation-gates.md`, and `output/verify/summary.json` aligned after each significant harness rerun.
- Re-run `npm run test:e2e:wp70` after any prerelease image update, stable-image swap, Site Editor/template/style change, or RTC-related upstream change.
- Do not treat historical 2026-04-22 WP 7.0 browser reds as current blockers unless a fresh rerun reproduces them.

## No New Product Work Required From This Refresh

- No RTC implementation work for WordPress 7.0 core
- No metabox migration work
- No Abilities API rewrite
- No Connectors API redesign
- No media-editor, image-generation, focal-point, or crop-suggestion work for `WordPress/ai#238` or `WordPress/ai#325`
- No Pattern API simplification
- No `@wordpress/build` migration

## Source Set

- [WordPress 7.0 Field Guide](https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/)
- [WordPress 7.0 documentation](https://wordpress.org/documentation/wordpress-version/version-7-0/)
- [What’s new in AI 1.0.0?](https://make.wordpress.org/ai/2026/05/21/whats-new-in-ai-1-0-0/)
- [AI plugin 1.0.0 release](https://github.com/WordPress/ai/releases/tag/1.0.0)
- [Real-time collaboration will not ship in WordPress 7.0](https://make.wordpress.org/core/2026/05/08/rtc-removed-from-7-0/)
- [Gutenberg 23.2.0 release](https://github.com/WordPress/gutenberg/releases/tag/v23.2.0)
- [What’s new in Gutenberg 23.1? (07 May)](https://make.wordpress.org/core/2026/05/07/whats-new-in-gutenberg-23-1-07-may/)
- [Roster of design tools per block (WordPress 7.0 edition)](https://make.wordpress.org/core/2026/04/22/roster-of-design-tools-per-block-wordpress-7-0/)
- [WordPress 7.0 Release Party Updated Schedule](https://make.wordpress.org/core/2026/04/22/wordpress-7-0-release-party-updated-schedule/)
- [The Path Forward for WordPress 7.0](https://make.wordpress.org/core/2026/04/02/the-path-forward-for-wordpress-7-0/)
- [Extending the 7.0 Cycle](https://make.wordpress.org/core/2026/03/31/extending-the-7-0-cycle/)
- [WordPress 7.0 release page](https://make.wordpress.org/core/7-0/)
- [What’s new for developers? (April 2026)](https://developer.wordpress.org/news/2026/04/whats-new-for-developers-april-2026/)
- [Gutenberg 23.0.0 release](https://github.com/WordPress/gutenberg/releases/tag/v23.0.0)
- [Gutenberg versions in WordPress](https://developer.wordpress.org/block-editor/contributors/versions-in-wordpress/)
