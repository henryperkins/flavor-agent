# Flavor Agent Impact Brief: WordPress 7.0 and Gutenberg 23

> Compiled: 2026-04-23
> Reviewed: 2026-04-30
> Scope: dated upstream release posture plus the concrete impact on this repo's shipped code and docs
> Status: retained as a point-in-time compatibility snapshot for the WordPress 7.0 / Gutenberg 23.0 release cycle. Use `docs/reference/gutenberg-feature-tracking.md` for ongoing Gutenberg/API tracking and `docs/reference/wordpress-ai-roadmap-tracking.md` for ongoing WordPress AI roadmap pressure.
> Use `docs/wordpress-7.0-developer-docs-index.md` for the broader upstream source map, `docs/wp7-migration-opportunities.md` for the older migration snapshot, and `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md` for the overlap-remediation backlog.

## Current Upstream Snapshot

| Topic | Current state | Why it matters here |
| --- | --- | --- |
| WordPress 7.0 schedule | Core published an updated 7.0 schedule on 2026-04-22. The new target general release is 2026-05-20. `RC3` lands 2026-05-08 but should be tested like a "new Beta 1", and `RC4` lands 2026-05-14 acting like a new `RC1`. | Repo docs should no longer say the updated schedule is still pending. |
| WordPress 7.0 release mode | 7.0 remains pre-release as of the 2026-04-30 review. The cycle is still in stabilization mode after the RTC architecture delay. | Keep the dedicated WP 7.0 browser harness framed as a pre-release compatibility path until the repo intentionally moves to the stable image. |
| Gutenberg plugin latest | The latest Gutenberg plugin release is `23.0.0`, published 2026-04-22. | Useful for forward-compat testing and upstream watch items, but not a signal that WordPress core 7.0 will ship every 23.0 API unchanged. |
| Gutenberg in core | The Block Editor handbook still lists WordPress `7.0.x` as based on Gutenberg `22.6`, with later bug fixes cherry-picked as needed during beta/RC. | Flavor Agent should keep runtime assumptions anchored to WordPress 7.0 core behavior first, and treat 22.7-23.0 as supplemental compatibility context. |
| Main upstream pressure point | The release delay is about RTC persistence and hosting compatibility, especially around cache behavior and plugin metabox compatibility. | Flavor Agent does not ship classic metabox UI or RTC transport/storage code, so this is not a direct product blocker. |

## Repo Areas Already Aligned

### WordPress AI Client and Connectors

Flavor Agent already treats the WordPress AI Client plus Connectors as a real first-class runtime path.

- `inc/LLM/WordPressAIClient.php` uses `wp_ai_client_prompt()` directly and fails clearly when the core AI client is unavailable.
- `inc/OpenAI/Provider.php` already checks connector registration and connector-backed credentials such as `connectors_ai_openai_api_key`.
- Live docs already frame `Settings > Connectors` as the fallback path rather than a side note.

Impact:

- No architecture change is needed for WordPress 7.0 here.
- Docs should keep acknowledging the broader connector ecosystem, not just the three initial official providers.

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
- Do not simplify it on the assumption that Gutenberg 23.0 implies stable pattern settings in WordPress 7.0 core.

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

### DataViews and DataForm admin surface

The plugin's `Settings > AI Activity` admin screen is already built on the same WordPress-native data-view stack that 7.0 keeps evolving.

- `src/admin/activity-log.js` imports `DataForm` and `DataViews` from `@wordpress/dataviews/wp`.

Impact:

- This area should stay under regression watch as Gutenberg iterates, but nothing in the current 7.0 / 23.0 snapshot forces a redesign.

### RTC and metabox compatibility

The current WordPress 7.0 RTC delay is mostly not about this plugin.

- A repo-wide search does not show Flavor Agent shipping classic `add_meta_box()` editor UI.
- The plugin does not implement RTC persistence, transport, or co-editing behavior.

Impact:

- The RTC architectural delay changes release timing, not the plugin's current implementation strategy.
- If Flavor Agent later grows live collaborative post-editor features, this should be re-evaluated from scratch.

## Concrete Follow-Ups

### 1. Keep the runtime baseline anchored to WordPress 7.0 core, not Gutenberg 23.0

Gutenberg `23.0.0` is the newest plugin release, but WordPress `7.0.x` is still documented as based on Gutenberg `22.6` plus cherry-picked fixes.

Action:

- Treat Gutenberg 23.0 as a forward-compat reference and testing snapshot.
- Avoid assuming that new `23.0.0` APIs or UI details are available in every WordPress 7.0 environment.

### 2. Update the WP 7.0 harness image when the stable Docker image exists

The repo still pins the Docker-backed WP 7.0 harness to `wordpress:beta-7.0-RC2-php8.2-apache`.

Grounding:

- `scripts/wp70-e2e.js`
- `docs/local-wordpress-ide.md`
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

- No RTC implementation work
- No metabox migration work
- No Abilities API rewrite
- No Connectors API redesign
- No Pattern API simplification
- No `@wordpress/build` migration

## Source Set

- [WordPress 7.0 Release Party Updated Schedule](https://make.wordpress.org/core/2026/04/22/wordpress-7-0-release-party-updated-schedule/)
- [The Path Forward for WordPress 7.0](https://make.wordpress.org/core/2026/04/02/the-path-forward-for-wordpress-7-0/)
- [Extending the 7.0 Cycle](https://make.wordpress.org/core/2026/03/31/extending-the-7-0-cycle/)
- [WordPress 7.0 release page](https://make.wordpress.org/core/7-0/)
- [What’s new for developers? (April 2026)](https://developer.wordpress.org/news/2026/04/whats-new-for-developers-april-2026/)
- [Gutenberg 23.0.0 release](https://github.com/WordPress/gutenberg/releases/tag/v23.0.0)
- [Gutenberg versions in WordPress](https://developer.wordpress.org/block-editor/contributors/versions-in-wordpress/)
