# WordPress 7.0 Developer Docs Index

Generated: 2026-03-25 UTC

> Status: discovery snapshot for release-cycle research, not a live backlog or product-behavior document.
> Use `STATUS.md`, `docs/SOURCE_OF_TRUTH.md`, and `docs/2026-03-25-roadmap-aligned-execution-plan.md` for current Flavor Agent truth.

## Scope

This index covers official, release-specific WordPress 7.0 developer documentation discovered from:

- Make/Core release pages and tags
- Make/Test release and feature-testing posts
- WordPress Developer Blog roundup posts returned by a `WordPress 7.0` search
- the official `Keeping up with Gutenberg: Index 2026` page plus linked Gutenberg 22.5-22.7 weekly update posts listed under the WordPress 7.0 heading

This workspace did not have Cloudflare AI Search credentials configured, so discovery was done from the official WordPress site search and REST API endpoints above.

Excluded on purpose:

- recurring agendas, chat summaries, volunteer calls, and similar coordination posts
- generic handbook or reference pages that only mention 7.0 in passing

Note: as of 2026-03-25, a `WordPress 7.0 Field Guide` post was not discoverable on Make/Core's `field guide` tag or in Make/Core `WordPress 7.0` search results, even though the WordPress 7.0 release page still lists Field Guide publication as part of the March 19, 2026 RC1 milestone. Release timing also shifted; see the RC1 delay post below.

## Discovery Sources

- [WordPress 7.0 release page](https://make.wordpress.org/core/7-0/)
- [Make/Core dev-notes-7-0 tag](https://make.wordpress.org/core/tag/dev-notes-7-0/)
- [Make/Test search for "WordPress 7.0"](https://make.wordpress.org/test/?s=WordPress+7.0)
- [Developer Blog search for "WordPress 7.0"](https://developer.wordpress.org/news/?s=WordPress+7.0)
- [Keeping up with Gutenberg: Index 2026](https://make.wordpress.org/core/handbook/references/keeping-up-with-gutenberg-index/)

## New Notes This Refresh

- The March 10, 2026 Developer Blog roundup says the "always-iframed post editor" work was punted to WordPress 7.1, so the WordPress 7.0 iframe note should be read as the inserted-block-version gate rather than a blanket always-iframed rollout.
- The February 20, 2026 `Help Test WordPress 7.0` post expands the concrete release-test surface beyond the standalone dev notes: admin refresh, client-side media processing, Icon and Breadcrumbs blocks, Gallery lightbox, Cover external video, and Grid controls are all called out explicitly.
- The March 11, 2026 Gutenberg 22.7 update shows Connectors work continuing after the standalone Connectors dev note: the Connectors screen and API landed, connector setting names gained the `_ai_` prefix, and providers are dynamically registered from the WP AI Client registry.
- The official Gutenberg 2026 index lists the Gutenberg 22.5, 22.6, and 22.7 weekly update posts under the WordPress 7.0 heading, making it the best official cross-check for cycle-adjacent Gutenberg changes that are not always published as standalone 7.0 dev notes.

## Release Hub and Planning

- 2025-12-02: [WordPress 7.0](https://make.wordpress.org/core/7-0/)
- 2025-12-11: [Planning for 7.0](https://make.wordpress.org/core/2025/12/11/planning-for-7-0/)
- 2026-03-19: [WordPress 7.0 Release Candidate 1 delayed](https://make.wordpress.org/core/2026/03/19/wordpress-7-0-release-candidate-1-delayed/)

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

These are not dedicated WordPress 7.0 dev notes, but the official Gutenberg index lists them under the WordPress 7.0 heading and they carry relevant release-cycle detail.

- 2026-03-11: [What’s new in Gutenberg 22.7? (11 March)](https://make.wordpress.org/core/2026/03/11/whats-new-in-gutenberg-22-7-11-march/)
- 2026-02-25: [What’s new in Gutenberg 22.6? (25 February)](https://make.wordpress.org/core/2026/02/25/whats-new-in-gutenberg-22-6-25-february/)
- 2026-02-04: [What’s new in Gutenberg 22.5? (04 February)](https://make.wordpress.org/core/2026/02/04/whats-new-in-gutenberg-22-5-04-february/)

## Supplemental Developer Blog Coverage

These are not dedicated release docs, but they surfaced in Developer Blog searches for `WordPress 7.0` and can help track the release from a developer-facing angle.

- 2026-03-10: [What's new for developers? (March 2026)](https://developer.wordpress.org/news/2026/03/whats-new-for-developers-march-2026/)
- 2026-02-10: [What's new for developers? (February 2026)](https://developer.wordpress.org/news/2026/02/whats-new-for-developers-february-2026/)
- 2026-01-12: [What's new for developers? (January 2026)](https://developer.wordpress.org/news/2026/01/whats-new-for-developers-january-2026/)
- 2025-12-10: [What's new for developers? (December 2025)](https://developer.wordpress.org/news/2025/12/whats-new-for-developers-december-2025/)

## Counts

- 3 release hub and schedule docs
- 18 Make/Core dev notes
- 5 testing docs
- 4 supplemental Developer Blog roundup posts
- 3 supplemental Gutenberg weekly update posts
