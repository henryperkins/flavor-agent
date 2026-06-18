# WordPress 7.0 Developer Docs Index

Generated: 2026-03-27 UTC
Updated: 2026-06-18 UTC

> Status: discovery snapshot for release-cycle research, not a live backlog or product-behavior document.
> Use `STATUS.md`, `docs/SOURCE_OF_TRUTH.md`, `docs/FEATURE_SURFACE_MATRIX.md`, and `docs/features/` for current Flavor Agent truth.

## Scope

This index covers official, release-specific WordPress 7.0 developer documentation discovered from:

- Make/Core release pages and tags
- the WordPress.org 7.0 release announcement and release notes page
- the 2026-05-14 WordPress 7.0 Field Guide and its post-publication edits
- Make/Test release and feature-testing posts
- WordPress Developer Blog roundup posts and related tooling or AI Client articles returned by a `WordPress 7.0`, `@wordpress/build`, or `WordPress AI Client` search
- the official `Keeping up with Gutenberg: Index 2026` page plus linked Gutenberg 22.5-22.7 weekly update posts listed under the WordPress 7.0 heading
- the Gutenberg GitHub releases page, including the 2026-04-22 `23.0.0` release and the published Gutenberg 22.9-23.4 release/update posts
- the Make/Playground blog for the official Playground MCP announcement
- the Make/AI blog and WordPress/ai GitHub releases for the post-7.0 canonical AI plugin surface

This release-cycle research snapshot did not use Flavor Agent's built-in docs endpoint; discovery was done from the official WordPress site search and REST API endpoints above.

Excluded on purpose:

- recurring agendas, chat summaries, volunteer calls, and similar coordination posts
- generic handbook or reference pages that only mention 7.0 in passing

Note: the `WordPress 7.0 Field Guide` was published on 2026-05-14 during the release-candidate phase, and WordPress 7.0 "Armstrong" was released on 2026-05-20. Core removed real-time collaboration from the 7.0 release on 2026-05-08, so RTC remains a Gutenberg-plugin / future-release watch item rather than a WordPress 7.0 core contract.

## Discovery Sources

- [WordPress 7.0 release page](https://make.wordpress.org/core/7-0/)
- [WordPress 7.0 "Armstrong" announcement](https://wordpress.org/news/2026/05/armstrong/)
- [WordPress.org Version 7.0 documentation](https://wordpress.org/documentation/wordpress-version/version-7-0/)
- [Make/Core dev-notes-7-0 tag](https://make.wordpress.org/core/tag/dev-notes-7-0/)
- [Make/Test search for "WordPress 7.0"](https://make.wordpress.org/test/?s=WordPress+7.0)
- [Developer Blog search for "WordPress 7.0"](https://developer.wordpress.org/news/?s=WordPress+7.0)
- [Developer Blog search for "@wordpress/build"](https://developer.wordpress.org/news/?s=%40wordpress%2Fbuild)
- [Developer Blog search for "WordPress AI Client"](https://developer.wordpress.org/news/?s=WordPress+AI+Client)
- [Keeping up with Gutenberg: Index 2026](https://make.wordpress.org/core/handbook/references/keeping-up-with-gutenberg-index/)
- [Gutenberg releases](https://github.com/WordPress/gutenberg/releases)
- [Gutenberg versions in WordPress](https://developer.wordpress.org/block-editor/contributors/versions-in-wordpress/)
- [Make/Playground](https://make.wordpress.org/playground/)
- [Make/AI](https://make.wordpress.org/ai/)
- [WordPress/ai releases](https://github.com/WordPress/ai/releases)

## New Notes This Refresh

- WordPress 7.0 "Armstrong" was released on 2026-05-20. The WordPress.org Version 7.0 page is now part of the canonical release source set alongside the announcement and Field Guide.
- The Version 7.0 documentation says `@wordpress` packages are no longer specifically updated for each core release; for WordPress 7.0, use the Gutenberg `wp/7.0` branch at sha `a2a354cf35e5b69c3330d6c1cfd42d8dc2efb9fd` when checking bundled package code.
- On 2026-05-14, Core published the WordPress 7.0 Field Guide. For Flavor Agent, the live release-cycle anchors are the AI Client, Client-Side Abilities API, `Settings > Connectors` screen, Connectors API, pattern/contentOnly changes, design-tool/block-support additions, PHP-only block registration, DataViews/DataForms, and the PHP 7.4 core minimum.
- The Field Guide edits matter for stale docs: the 2026-05-17 edit added the DataViews dev note, `textIndent` block support, and margin-free component styles; the 2026-05-18 edit removed the standalone Notes section and refreshed the Gallery slideshow image; the current Field Guide also links the additional accessibility and author-title-attribute dev notes.
- The 2026-04-22 `Roster of design tools per block (WordPress 7.0 edition)` dev note is now indexed under blocks/styles/themes. It is the canonical reference for which block supports each first-party block declares in WordPress 7.0 and is linked from the Field Guide's Design Agility section; the entry was previously missing from this index.
- The 2026-05-23 `Accessibility Improvements in WordPress 7.0` dev note is now indexed. It confirms the accessibility-focused follow-up set: media-library voice-control improvements, image alt text imported from metadata, contrast and control-semantics fixes, and editor navigation/interactions.
- The 2026-05-14 author-link dev note is now indexed. WordPress 7.0 removes default `title` attributes from author posts archive links and `wp_list_authors()` output, exposes the author-posts text to hooks, and adds a `$use_title_attr` parameter to `get_the_author_link()` / `the_author_link()`.
- The 2026-05-22 PHP support clarification is now indexed as post-release compatibility context: WordPress 7.0 keeps PHP 7.4 as the minimum supported version and is now documented as fully supporting PHP 8.5.
- On 2026-05-08, Core announced real-time collaboration will not ship in WordPress 7.0. Keep RTC references in this repo framed as Gutenberg-plugin or future-release compatibility watch items, not as WordPress 7.0 core requirements.
- The WordPress 7.1 cycle is now open, but 7.1 material belongs here only when it clarifies the post-7.0 boundary. The React 19 upgrade/revert posts are included as supplemental Gutenberg compatibility context because Gutenberg 23.3 first shipped React 19, Gutenberg 23.3.2 reverted it, and 23.4 brings it back behind an experiment flag.
- The Make/AI 1.0.0 post and WordPress/ai 1.0.0 / 1.0.2 GitHub releases are now indexed as adjacent WordPress 7.0 AI-stack sources. They add Request Logging, Connector Approvals, client-side Abilities usage in the canonical plugin, request-log polish, and strict REST/MCP schema fixes.
- The WordPress Developer Blog now has a dedicated AI Client image-generation tutorial. It is useful as the clearest official example of the `WP_AI_Client_Prompt_Builder`, `using_model_preference()`, capability checks, and `Settings > Connectors` setup pattern.
- The May and June Developer Blog roundups are now indexed. May covers the last pre-release Gutenberg workstream; June confirms 7.0 is released, 7.1 testing has begun, and the ongoing watch items include client-side media processing, React 19 compatibility, Abilities API refinements, PHP support labels, and Playground CLI migration.
- The supplemental Gutenberg set now extends through 23.4. Keep the distinction explicit: WordPress 7.0.x is still documented as based on Gutenberg 22.6, while Gutenberg 23.2-23.4 are forward-compatibility context for plugin testing.
- The 2026-05-07 Gutenberg 23.1 release post is now part of the supplemental compatibility set. It added `@wordpress/ui` `Drawer` and `Autocomplete` primitives, a developer-preview `@wordpress/grid` package, experiments for custom taxonomies and the media editor, and RTC reliability fixes in the Gutenberg plugin.
- On 2026-04-22, Core published `WordPress 7.0 Release Party Updated Schedule`, which moves the target general release to 2026-05-20 and explicitly says `RC3` on 2026-05-08 should be tested like a new Beta 1, with `RC4` on 2026-05-14 acting like a new `RC1`.
- The March 10, 2026 Developer Blog roundup says the "always-iframed post editor" work was punted to WordPress 7.1, so the WordPress 7.0 iframe note should be read as the inserted-block-version gate rather than a blanket always-iframed rollout.
- The February 20, 2026 `Help Test WordPress 7.0` post expands the concrete release-test surface beyond the standalone dev notes: admin refresh, client-side media processing, Icon and Breadcrumbs blocks, Gallery lightbox, Cover external video, and Grid controls are all called out explicitly.
- The March 11, 2026 Gutenberg 22.7 update and the April roundup together show Connectors work continuing after the standalone Connectors dev note: the Connectors screen and API landed, connector setting names gained the `_ai_` prefix, providers are dynamically registered from the WP AI Client registry, and community providers such as OpenRouter, Ollama, and Mistral now demonstrate that ecosystem in practice.
- The official Gutenberg 2026 index page was still last updated on 2026-03-19, so it stops at Gutenberg 22.7; Gutenberg `22.8.0` (2026-03-25), `22.8.1` (2026-03-26), `22.9.0` (2026-04-09), `23.0.0` (2026-04-22), `23.1` (2026-05-07), `23.2` (2026-05-21), `23.3` (2026-06-03), and `23.4` (2026-06-17) were cross-checked directly from the Gutenberg project.
- The Block Editor handbook's `Gutenberg versions in WordPress` page was last updated on 2026-06-16 and still lists WordPress `7.0.x` as based on Gutenberg `22.6`, which is the key reminder that later Gutenberg releases are compatibility context rather than a one-to-one core snapshot.
- The March 17, 2026 Playground MCP post is now part of the relevant upstream tooling surface for Flavor Agent because it documents an official agent-facing way to drive local Playground instances.
- The April 2, 2026 `@wordpress/build` article is relevant as a tooling-direction snapshot, but it explicitly says the long-term plan is for it to sit underneath `@wordpress/scripts`, not to force an immediate migration.

## Release Hub and Planning

- 2025-12-02: [WordPress 7.0](https://make.wordpress.org/core/7-0/)
- 2025-12-11: [Planning for 7.0](https://make.wordpress.org/core/2025/12/11/planning-for-7-0/)
- 2026-03-19: [WordPress 7.0 Release Candidate 1 delayed](https://make.wordpress.org/core/2026/03/19/wordpress-7-0-release-candidate-1-delayed/)
- 2026-03-31: [Extending the 7.0 Cycle](https://make.wordpress.org/core/2026/03/31/extending-the-7-0-cycle/)
- 2026-04-02: [The Path Forward for WordPress 7.0](https://make.wordpress.org/core/2026/04/02/the-path-forward-for-wordpress-7-0/)
- 2026-04-22: [WordPress 7.0 Release Party Updated Schedule](https://make.wordpress.org/core/2026/04/22/wordpress-7-0-release-party-updated-schedule/)
- 2026-05-08: [Real-time collaboration will not ship in WordPress 7.0](https://make.wordpress.org/core/2026/05/08/rtc-removed-from-7-0/)
- 2026-05-14: [WordPress 7.0 Field Guide](https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/)
- 2026-05-19: [WordPress 7.0 Release Candidate 5](https://make.wordpress.org/core/2026/05/19/wordpress-7-0-release-candidate-5/)
- 2026-05-20: [WordPress 7.0 Release Day Process](https://make.wordpress.org/core/2026/05/20/wordpress-7-0-release-day-process/)
- 2026-05-20: [WordPress 7.0 "Armstrong"](https://wordpress.org/news/2026/05/armstrong/)
- 2026-05-20: [Version 7.0 documentation](https://wordpress.org/documentation/wordpress-version/version-7-0/)

## Core Dev Notes

### APIs and platform

- 2026-03-24: [Introducing the AI Client in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- 2026-03-24: [Client-Side Abilities API in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/)
- 2026-03-18: [Introducing the Connectors API in WordPress 7.0](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
- 2026-03-04: [DataViews, DataForm, et al. in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/dataviews-dataform-et-al-in-wordpress-7-0/)
- 2026-03-04: [Breadcrumb block filters](https://make.wordpress.org/core/2026/03/04/breadcrumb-block-filters/)
- 2026-03-03: [PHP-only block registration](https://make.wordpress.org/core/2026/03/03/php-only-block-registration/)
- 2026-02-23: [Changes to the Interactivity API in WordPress 7.0](https://make.wordpress.org/core/2026/02/23/changes-to-the-interactivity-api-in-wordpress-7-0/)
- 2026-05-22: [PHP support clarification, spring 2026 edition](https://make.wordpress.org/core/2026/05/22/php-support-clarification-2026/)
- 2026-01-09: [Dropping support for PHP 7.2 and 7.3](https://make.wordpress.org/core/2026/01/09/dropping-support-for-php-7-2-and-7-3/)

### Editor, collaboration, and content model

- 2026-03-16: [Pattern Overrides in WP 7.0: Support for Custom Blocks](https://make.wordpress.org/core/2026/03/16/pattern-overrides-in-wp-7-0-support-for-custom-blocks/)
- 2026-03-15: [Pattern Editing in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/pattern-editing-in-wordpress-7-0/)
- 2026-03-15: [Block Visibility in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/block-visibility-in-wordpress-7-0/)
- 2026-03-10: [Real-Time Collaboration in the Block Editor](https://make.wordpress.org/core/2026/03/10/real-time-collaboration-in-the-block-editor/) — superseded for WordPress 7.0 core by the 2026-05-08 removal post above.
- 2026-02-24: [Iframed Editor Changes in WordPress 7.0](https://make.wordpress.org/core/2026/02/24/iframed-editor-changes-in-wordpress-7-0/)

### Blocks, styles, and themes

- 2026-04-22: [Roster of design tools per block (WordPress 7.0 edition)](https://make.wordpress.org/core/2026/04/22/roster-of-design-tools-per-block-wordpress-7-0/)
- 2026-03-15: [Dimensions Support Enhancements in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/dimensions-support-enhancements-in-wordpress-7-0/)
- 2026-03-15: [Custom CSS for Individual Block Instances in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/custom-css-for-individual-block-instances-in-wordpress-7-0/)
- 2026-03-15: [New Block Support: Text Indent (textIndent)](https://make.wordpress.org/core/2026/03/15/new-block-support-text-indent-textindent/)
- 2026-03-09: [Pseudo-element support for blocks and their variations in theme.json](https://make.wordpress.org/core/2026/03/09/pseudo-element-support-for-blocks-and-their-variations-in-theme-json/)
- 2026-03-04: [Customisable Navigation Overlays in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/customisable-navigation-overlays-in-wordpress-7-0/)

### Accessibility and compatibility

- 2026-05-23: [Accessibility Improvements in WordPress 7.0](https://make.wordpress.org/core/2026/05/23/accessibility-improvements-in-wordpress-7-0/)
- 2026-05-14: [Removing title attributes in author link functions](https://make.wordpress.org/core/2026/05/14/removing-title-attributes-in-author-link-functions/)

## Testing Docs

- 2026-03-11: [It's time to test real-time collaboration!](https://make.wordpress.org/test/2026/03/11/its-time-to-test-real-time-collaboration/)
- 2026-02-27: [Call for Testing - Pattern editing and content-only interactivity in WordPress 7.0](https://make.wordpress.org/test/2026/02/27/call-for-testing-pattern-editing-and-content-only-interactivity-in-wordpress-7-0/)
- 2026-02-20: [Help Test WordPress 7.0](https://make.wordpress.org/test/2026/02/20/help-test-wordpress-7-0/)
- 2026-02-17: [Test Scrub Schedule for WordPress 7.0](https://make.wordpress.org/test/2026/02/17/test-scrub-schedule-for-wordpress-7-0/)
- 2026-01-27: [Call for Testing - Customizable Navigation ("Mobile") Overlays](https://make.wordpress.org/test/2026/01/27/call-for-testing-customizable-navigation-mobile-overlays/)

## Supplemental Gutenberg Cycle Coverage

These are not dedicated WordPress 7.0 dev notes. The official Gutenberg index carries 22.5-22.7 under the WordPress 7.0 heading, and the later 22.8.x through 23.4 line was cross-checked directly from Gutenberg because the index had not yet been refreshed.

- 2026-06-17: [What's new in Gutenberg 23.4? (June 17, 2026)](https://make.wordpress.org/core/2026/06/17/whats-new-in-gutenberg-23-4-june-17-2026/)
- 2026-06-17: [Gutenberg 23.4.0 release](https://github.com/WordPress/gutenberg/releases/tag/v23.4.0)
- 2026-06-05: [React 19 upgrade temporarily reverted in Gutenberg](https://make.wordpress.org/core/2026/06/05/react-19-upgrade-temporarily-reverted-in-gutenberg/)
- 2026-06-03: [What's new in Gutenberg 23.3? (03 Jun)](https://make.wordpress.org/core/2026/06/03/whats-new-in-gutenberg-23-3-03-jun/)
- 2026-05-21: [What's new in Gutenberg 23.2? (21 May)](https://make.wordpress.org/core/2026/05/21/whats-new-in-gutenberg-23-2-21-may/)
- 2026-05-07: [What’s new in Gutenberg 23.1? (07 May)](https://make.wordpress.org/core/2026/05/07/whats-new-in-gutenberg-23-1-07-may/)
- 2026-04-22: [Gutenberg 23.0.0 release](https://github.com/WordPress/gutenberg/releases/tag/v23.0.0)
- 2026-04-09: [What’s new in Gutenberg 22.9? (8 April)](https://make.wordpress.org/core/2026/04/09/whats-new-in-gutenberg-22-9-8-april/)
- 2026-03-26: [Gutenberg 22.8.1 release](https://github.com/WordPress/gutenberg/releases/tag/v22.8.1)
- 2026-03-25: [Gutenberg 22.8.0 release](https://github.com/WordPress/gutenberg/releases/tag/v22.8.0)
- 2026-03-11: [What’s new in Gutenberg 22.7? (11 March)](https://make.wordpress.org/core/2026/03/11/whats-new-in-gutenberg-22-7-11-march/)
- 2026-02-25: [What’s new in Gutenberg 22.6? (25 February)](https://make.wordpress.org/core/2026/02/25/whats-new-in-gutenberg-22-6-25-february/)
- 2026-02-04: [What’s new in Gutenberg 22.5? (04 February)](https://make.wordpress.org/core/2026/02/04/whats-new-in-gutenberg-22-5-04-february/)

## Supplemental Developer Blog Coverage

These are not dedicated release docs, but they surfaced in Developer Blog searches for `WordPress 7.0` and can help track the release from a developer-facing angle.

- 2026-06-10: [What's new for developers? (June 2026)](https://developer.wordpress.org/news/2026/06/whats-new-for-developers-june-2026/)
- 2026-05-14: [How to build an image generation plugin with the WordPress AI Client](https://developer.wordpress.org/news/2026/05/how-to-build-an-image-generation-plugin-with-the-wordpress-ai-client/)
- 2026-05-12: [What's new for developers? (May 2026)](https://developer.wordpress.org/news/2026/05/whats-new-for-developers-may-2026/)
- 2026-04-10: [What's new for developers? (April 2026)](https://developer.wordpress.org/news/2026/04/whats-new-for-developers-april-2026/)
- 2026-04-02: [@wordpress/build, the next generation of WordPress plugin build tooling](https://developer.wordpress.org/news/2026/04/wordpress-build-the-next-generation-of-wordpress-plugin-build-tooling/)
- 2026-03-10: [What's new for developers? (March 2026)](https://developer.wordpress.org/news/2026/03/whats-new-for-developers-march-2026/)
- 2026-02-10: [What's new for developers? (February 2026)](https://developer.wordpress.org/news/2026/02/whats-new-for-developers-february-2026/)
- 2026-01-12: [What's new for developers? (January 2026)](https://developer.wordpress.org/news/2026/01/whats-new-for-developers-january-2026/)
- 2025-12-10: [What's new for developers? (December 2025)](https://developer.wordpress.org/news/2025/12/whats-new-for-developers-december-2025/)

## Supplemental Playground And AI Ecosystem Coverage

These are not core release-plan docs, but they affect how WordPress 7.0-era AI and local tooling can be used around Flavor Agent.

- 2026-06-16: [AI plugin 1.0.2 release](https://github.com/WordPress/ai/releases/tag/1.0.2)
- 2026-05-21: [What's new in AI 1.0.0 (19 MAY 2026)?](https://make.wordpress.org/ai/2026/05/21/whats-new-in-ai-1-0-0/)
- 2026-05-19: [AI plugin 1.0.0 release](https://github.com/WordPress/ai/releases/tag/1.0.0)
- 2026-03-17: [Connect AI coding agents to WordPress Playground with MCP](https://make.wordpress.org/playground/2026/03/17/connect-ai-coding-agents-to-wordpress-playground-with-mcp/)
- 2026-03-25: [Call for Testing: Community AI Connector Plugins](https://make.wordpress.org/ai/2026/03/25/call-for-testing-community-ai-connector-plugins/)

## Counts

- 12 release hub, schedule, field-guide, and release-note docs
- 22 Make/Core dev notes and compatibility posts
- 5 testing docs
- 9 supplemental Developer Blog roundup posts and tooling articles
- 13 supplemental Gutenberg release/update posts
- 5 supplemental Playground and AI ecosystem posts
