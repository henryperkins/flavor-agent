# WordPress 7.0 Developer Docs Index

Generated: 2026-03-27 UTC
Updated: 2026-04-20 UTC

> Status: discovery snapshot for release-cycle research, not a live backlog or product-behavior document.
> Use `STATUS.md`, `docs/SOURCE_OF_TRUTH.md`, `docs/FEATURE_SURFACE_MATRIX.md`, and `docs/features/` for current Flavor Agent truth.

## Scope

This index covers official, release-specific WordPress 7.0 developer documentation discovered from:

- Make/Core release pages and tags
- Make/Test release and feature-testing posts
- WordPress Developer Blog roundup posts and related tooling articles returned by a `WordPress 7.0` or `@wordpress/build` search
- the official `Keeping up with Gutenberg: Index 2026` page plus linked Gutenberg 22.5-22.7 weekly update posts listed under the WordPress 7.0 heading
- the Gutenberg GitHub releases page plus the published Gutenberg 22.9 release post
- the Make/Playground blog for the official Playground MCP announcement
- the Make/AI blog for the community connector testing call that confirms the emerging third-party provider ecosystem

This workspace did not have Cloudflare AI Search credentials configured, so discovery was done from the official WordPress site search and REST API endpoints above.

Excluded on purpose:

- recurring agendas, chat summaries, volunteer calls, and similar coordination posts
- generic handbook or reference pages that only mention 7.0 in passing

Note: as of 2026-04-20, a `WordPress 7.0 Field Guide` post was still not discoverable on Make/Core's `field guide` tag or in Make/Core `WordPress 7.0` search results. The official WordPress 7.0 release page now says an updated schedule for the final stretch will be published no later than April 22, 2026.

## Discovery Sources

- [WordPress 7.0 release page](https://make.wordpress.org/core/7-0/)
- [Make/Core dev-notes-7-0 tag](https://make.wordpress.org/core/tag/dev-notes-7-0/)
- [Make/Test search for "WordPress 7.0"](https://make.wordpress.org/test/?s=WordPress+7.0)
- [Developer Blog search for "WordPress 7.0"](https://developer.wordpress.org/news/?s=WordPress+7.0)
- [Developer Blog search for "@wordpress/build"](https://developer.wordpress.org/news/?s=%40wordpress%2Fbuild)
- [Keeping up with Gutenberg: Index 2026](https://make.wordpress.org/core/handbook/references/keeping-up-with-gutenberg-index/)
- [Gutenberg releases](https://github.com/WordPress/gutenberg/releases)
- [Make/Playground](https://make.wordpress.org/playground/)
- [Make/AI](https://make.wordpress.org/ai/)

## New Notes This Refresh

- On 2026-04-02, Core published `The Path Forward for WordPress 7.0`, which explains the RTC architecture issue and confirms that the revised final-stretch schedule will be published later.
- As of 2026-04-20, the official WordPress 7.0 release page still says the updated final-stretch schedule will be published no later than April 22, 2026.
- The March 10, 2026 Developer Blog roundup says the "always-iframed post editor" work was punted to WordPress 7.1, so the WordPress 7.0 iframe note should be read as the inserted-block-version gate rather than a blanket always-iframed rollout.
- The February 20, 2026 `Help Test WordPress 7.0` post expands the concrete release-test surface beyond the standalone dev notes: admin refresh, client-side media processing, Icon and Breadcrumbs blocks, Gallery lightbox, Cover external video, and Grid controls are all called out explicitly.
- The March 11, 2026 Gutenberg 22.7 update and the April roundup together show Connectors work continuing after the standalone Connectors dev note: the Connectors screen and API landed, connector setting names gained the `_ai_` prefix, providers are dynamically registered from the WP AI Client registry, and community providers such as OpenRouter, Ollama, and Mistral now demonstrate that ecosystem in practice.
- The official Gutenberg 2026 index page was still last updated on 2026-03-19, so it stops at Gutenberg 22.7; Gutenberg `22.8.0` (2026-03-25), `22.8.1` (2026-03-26), and `22.9.0` (published on 2026-04-09) were cross-checked directly from the Gutenberg project.
- The March 17, 2026 Playground MCP post is now part of the relevant upstream tooling surface for Flavor Agent because it documents an official agent-facing way to drive local Playground instances.
- The April 2, 2026 `@wordpress/build` article is relevant as a tooling-direction snapshot, but it explicitly says the long-term plan is for it to sit underneath `@wordpress/scripts`, not to force an immediate migration.

## Release Hub and Planning

- 2025-12-02: [WordPress 7.0](https://make.wordpress.org/core/7-0/)
- 2025-12-11: [Planning for 7.0](https://make.wordpress.org/core/2025/12/11/planning-for-7-0/)
- 2026-03-19: [WordPress 7.0 Release Candidate 1 delayed](https://make.wordpress.org/core/2026/03/19/wordpress-7-0-release-candidate-1-delayed/)
- 2026-03-31: [Extending the 7.0 Cycle](https://make.wordpress.org/core/2026/03/31/extending-the-7-0-cycle/)
- 2026-04-02: [The Path Forward for WordPress 7.0](https://make.wordpress.org/core/2026/04/02/the-path-forward-for-wordpress-7-0/)

## Core Dev Notes

### APIs and platform

- 2026-03-24: [Introducing the AI Client in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- 2026-03-24: [Client-Side Abilities API in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/)
- 2026-03-18: [Introducing the Connectors API in WordPress 7.0](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
- 2026-03-04: [DataViews, DataForm, et al. in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/dataviews-dataform-et-al-in-wordpress-7-0/)
- 2026-03-04: [Breadcrumb block filters](https://make.wordpress.org/core/2026/03/04/breadcrumb-block-filters/)
- 2026-03-03: [PHP-only block registration](https://make.wordpress.org/core/2026/03/03/php-only-block-registration/)
- 2026-02-23: [Changes to the Interactivity API in WordPress 7.0](https://make.wordpress.org/core/2026/02/23/changes-to-the-interactivity-api-in-wordpress-7-0/)
- 2026-01-09: [Dropping support for PHP 7.2 and 7.3](https://make.wordpress.org/core/2026/01/09/dropping-support-for-php-7-2-and-7-3/)

### Editor, collaboration, and content model

- 2026-03-16: [Pattern Overrides in WP 7.0: Support for Custom Blocks](https://make.wordpress.org/core/2026/03/16/pattern-overrides-in-wp-7-0-support-for-custom-blocks/)
- 2026-03-15: [Pattern Editing in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/pattern-editing-in-wordpress-7-0/)
- 2026-03-15: [Block Visibility in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/block-visibility-in-wordpress-7-0/)
- 2026-03-10: [Real-Time Collaboration in the Block Editor](https://make.wordpress.org/core/2026/03/10/real-time-collaboration-in-the-block-editor/)
- 2026-02-24: [Iframed Editor Changes in WordPress 7.0](https://make.wordpress.org/core/2026/02/24/iframed-editor-changes-in-wordpress-7-0/)

### Blocks, styles, and themes

- 2026-03-15: [Dimensions Support Enhancements in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/dimensions-support-enhancements-in-wordpress-7-0/)
- 2026-03-15: [Custom CSS for Individual Block Instances in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/custom-css-for-individual-block-instances-in-wordpress-7-0/)
- 2026-03-15: [New Block Support: Text Indent (textIndent)](https://make.wordpress.org/core/2026/03/15/new-block-support-text-indent-textindent/)
- 2026-03-09: [Pseudo-element support for blocks and their variations in theme.json](https://make.wordpress.org/core/2026/03/09/pseudo-element-support-for-blocks-and-their-variations-in-theme-json/)
- 2026-03-04: [Customisable Navigation Overlays in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/customisable-navigation-overlays-in-wordpress-7-0/)

## Testing Docs

- 2026-03-11: [It's time to test real-time collaboration!](https://make.wordpress.org/test/2026/03/11/its-time-to-test-real-time-collaboration/)
- 2026-02-27: [Call for Testing - Pattern editing and content-only interactivity in WordPress 7.0](https://make.wordpress.org/test/2026/02/27/call-for-testing-pattern-editing-and-content-only-interactivity-in-wordpress-7-0/)
- 2026-02-20: [Help Test WordPress 7.0](https://make.wordpress.org/test/2026/02/20/help-test-wordpress-7-0/)
- 2026-02-17: [Test Scrub Schedule for WordPress 7.0](https://make.wordpress.org/test/2026/02/17/test-scrub-schedule-for-wordpress-7-0/)
- 2026-01-27: [Call for Testing - Customizable Navigation ("Mobile") Overlays](https://make.wordpress.org/test/2026/01/27/call-for-testing-customizable-navigation-mobile-overlays/)

## Supplemental Gutenberg Cycle Coverage

These are not dedicated WordPress 7.0 dev notes. The official Gutenberg index carries 22.5-22.7 under the WordPress 7.0 heading, and the later 22.8.x stable line plus the published 22.9 release summary were cross-checked directly from Gutenberg because the index had not yet been refreshed.

- 2026-04-09: [What’s new in Gutenberg 22.9? (8 April)](https://make.wordpress.org/core/2026/04/09/whats-new-in-gutenberg-22-9-8-april/)
- 2026-03-26: [Gutenberg 22.8.1 release](https://github.com/WordPress/gutenberg/releases/tag/v22.8.1)
- 2026-03-25: [Gutenberg 22.8.0 release](https://github.com/WordPress/gutenberg/releases/tag/v22.8.0)
- 2026-03-11: [What’s new in Gutenberg 22.7? (11 March)](https://make.wordpress.org/core/2026/03/11/whats-new-in-gutenberg-22-7-11-march/)
- 2026-02-25: [What’s new in Gutenberg 22.6? (25 February)](https://make.wordpress.org/core/2026/02/25/whats-new-in-gutenberg-22-6-25-february/)
- 2026-02-04: [What’s new in Gutenberg 22.5? (04 February)](https://make.wordpress.org/core/2026/02/04/whats-new-in-gutenberg-22-5-04-february/)

## Supplemental Developer Blog Coverage

These are not dedicated release docs, but they surfaced in Developer Blog searches for `WordPress 7.0` and can help track the release from a developer-facing angle.

- 2026-04-10: [What's new for developers? (April 2026)](https://developer.wordpress.org/news/2026/04/whats-new-for-developers-april-2026/)
- 2026-04-02: [@wordpress/build, the next generation of WordPress plugin build tooling](https://developer.wordpress.org/news/2026/04/wordpress-build-the-next-generation-of-wordpress-plugin-build-tooling/)
- 2026-03-10: [What's new for developers? (March 2026)](https://developer.wordpress.org/news/2026/03/whats-new-for-developers-march-2026/)
- 2026-02-10: [What's new for developers? (February 2026)](https://developer.wordpress.org/news/2026/02/whats-new-for-developers-february-2026/)
- 2026-01-12: [What's new for developers? (January 2026)](https://developer.wordpress.org/news/2026/01/whats-new-for-developers-january-2026/)
- 2025-12-10: [What's new for developers? (December 2025)](https://developer.wordpress.org/news/2025/12/whats-new-for-developers-december-2025/)

## Supplemental Playground And AI Ecosystem Coverage

These are not core release-plan docs, but they affect how WordPress 7.0-era AI and local tooling can be used around Flavor Agent.

- 2026-03-17: [Connect AI coding agents to WordPress Playground with MCP](https://make.wordpress.org/playground/2026/03/17/connect-ai-coding-agents-to-wordpress-playground-with-mcp/)
- 2026-03-25: [Call for Testing: Community AI Connector Plugins](https://make.wordpress.org/ai/2026/03/25/call-for-testing-community-ai-connector-plugins/)

## Counts

- 4 release hub and schedule docs
- 18 Make/Core dev notes
- 5 testing docs
- 6 supplemental Developer Blog roundup posts and tooling articles
- 6 supplemental Gutenberg release/update posts
- 2 supplemental Playground and AI ecosystem posts
