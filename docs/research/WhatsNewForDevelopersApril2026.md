---
title: "What’s new for developers? (April 2026)"
source: "https://developer.wordpress.org/news/2026/04/whats-new-for-developers-april-2026/"
author:
published: 2026-04-10
created: 2026-04-19
description: "Learn about all the new updates coming to WordPress for developers, covering plugins and tools, theme updates, and the new Playground MCP Server."
tags:
  - "clippings"
---
> **Note:** This file is a clipped source snapshot created on April 19, 2026. Treat it as research input rather than canonical Flavor Agent product truth. For maintained repo takeaways from the same upstream material, use `docs/wordpress-7.0-developer-docs-index.md`, `docs/wordpress-7.0-gutenberg-22.8-reference.md`, and `docs/wp7-migration-opportunities.md`.

One of the more exciting things about working on an open-source project of the size and scope of WordPress is that anything can happen.

On [March 31, 2026, it was announced](https://make.wordpress.org/core/2026/03/31/extending-the-7-0-cycle/) that the WordPress 7.0 release cycle needed to be extended to get the Real-Time Collaboration (RTC) database architecture right. Then, on April 2nd, a [follow-up post](https://make.wordpress.org/core/2026/04/02/the-path-forward-for-wordpress-7-0/) spelled out exactly what that means in practice.

It’s an unusual situation. Returning to beta status after entering the Release Candidate phase is, as the post itself notes, unprecedented. But the reasoning is sound: the current RTC implementation has a performance issue that requires a deeper architectural fix, not a late-cycle patch, before this ships to millions of sites.

**Pre-release versions are paused until April 17th**, at which point the release squad and project leadership will have enough context to set a new schedule, to be announced no later than April 22nd.

The good news? Everything else in the 7.0 feature set is ready to go. There’s a lot to cover in this post, so let’s get into it.

**Note:** WordPress 7.0 will drop support for PHP 7.2 and 7.3. The new minimum will be PHP 7.4, with PHP 8.2+ recommended. If you’re managing sites still running older PHP, that’s your first action item before the update lands.

Table of Contents

## Highlights

### WordPress 7.0 news and updates

The flagship feature of WordPress 7.0 is [Real-Time Collaboration (RTC)](https://make.wordpress.org/core/2026/03/10/real-time-collaboration-in-the-block-editor/). The implementation uses [Yjs](http://yjs.dev/) as the underlying CRDT engine, with an HTTP polling sync provider chosen over WebRTC for universal hosting compatibility. The current architecture stores sync data persistently via `post_meta` on a special `wp_sync_storage` internal post type, but this approach disables WordPress’s persistent post query caches whenever a user has the editor open.

The fix being worked through now involves a dedicated database table for collaboration data. A new schedule for the remainder of the 7.0 cycle will be published by April 22nd.

In the meantime, there’s still a lot worth knowing:

- **Classic meta boxes will disable collaboration mode** for a post. If you’re still using `add_meta_box()`, now’s a good time to consider migrating to `register_post_meta()` and a `PluginSidebar` component. The 7.0 cycle is intended to be a window for plugin developers to implement compatibility bridges. If you’re not sure how to migrate your meta boxes to the sidebar, this [tutorial will guide you](https://developer.wordpress.org/news/2023/06/using-block-inspector-sidebar-groups/) through the process.
- **Hosts can customize the sync provider** using the `sync.providers` filter — useful if you want to offer a WebSocket-based transport on platforms that support it.
- For full developer details on RTC, including pitfalls around local state drift and unintended block insertion side effects, read the [RTC Dev Note](https://make.wordpress.org/core/2026/03/10/real-time-collaboration-in-the-block-editor/).

### AI Client and Connectors API

Two major interconnected systems are set to debut in WordPress 7.0:

**WP AI Client** is a new core PHP library providing a standardized interface for communicating with AI services. Rather than each plugin integrating directly with OpenAI, Anthropic, Google, or others, the WP AI Client provides a single abstraction layer, so provider switching is a configuration change, not a code rewrite. See the [Introducing the AI Client in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/) dev note for full details.

**The Connectors API** establishes credentials storage and provider selection as platform-level infrastructure. As reported in the [March edition](https://developer.wordpress.org/news/2026/03/whats-new-for-developers-march-2026/#ai-provider-packages), the new Connectors screen in the admin will let site owners configure their preferred AI providers using one of the three official provider plugins in the Plugin Directory for [OpenAI](https://wordpress.org/plugins/openai-provider/), [Google](https://wordpress.org/plugins/google-ai-provider/), and [Anthropic](https://wordpress.org/plugins/anthropic-ai-provider/).

![WordPress dashboard showing the new Connectors page](https://developer.wordpress.org/news/files/2026/04/Connectors.png)

WordPress dashboard showing the new Connectors page

Since then, community-built providers for [OpenRouter](https://wordpress.org/plugins/ai-provider-for-openrouter/), [Ollama](https://wordpress.org/plugins/ai-provider-for-ollama/), and [Mistral](https://wordpress.org/plugins/ai-provider-for-mistral/) have been published, alongside a [dedicated call for testing](https://make.wordpress.org/ai/2026/03/25/call-for-testing-community-ai-connector-plugins/). If you want to build your own provider plugin, the [Connectors API Dev Note](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/) is a must-read.

### Client-Side Abilities API

First introduced on the PHP side in WordPress 6.9, the Abilities API now has its JavaScript counterpart landing in 7.0. Two new packages handle it: `@wordpress/abilities` for pure state management, and `@wordpress/core-abilities` for the WordPress integration layer, which auto-fetches server-registered abilities via REST. You can register abilities with input/output schemas, permission callbacks, and annotations, laying the groundwork for browser agents and WebMCP integration. Read the [Client-Side Abilities API Dev Note](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/) for all the details.

### Gutenberg releases

Three Gutenberg releases landed ahead of the WordPress 7.0 release. You can read the full release posts for each version at the links below, or carry on reading this post for the highlights.

- [**Gutenberg 22.7**](https://make.wordpress.org/core/2026/03/11/whats-new-in-gutenberg-22-7-11-march/)
- [**Gutenberg 22.8**](https://make.wordpress.org/core/2026/03/25/whats-new-in-gutenberg-22-8-25-march/)
- [**Gutenberg 22.9**](https://make.wordpress.org/core/2026/04/09/whats-new-in-gutenberg-22-9-8-april/)

## User facing changes

### Style variation transform previews

If your theme offers block style variations, users can now see a [live preview of the variation](https://make.wordpress.org/core/2026/03/11/whats-new-in-gutenberg-22-7-11-march/#previews-for-style-variation-transforms) before applying it. Style variations are also now available for patterns in `contentOnly` mode.

### Playlist block WaveformPlayer

The Playlist block now has a [waveform audio visualization](https://make.wordpress.org/core/2026/03/11/whats-new-in-gutenberg-22-7-11-march/#playlist-block-now-has-a-visualizer) using the [@arraypress/waveform-player](https://github.com/arraypress/waveform-player) library. This displays a visual representation of the audio file being listened to, and opens the door to more designs for the block.

### Site Logo & Icon in the Design panel

Site logo and icon management is getting a [dedicated screen in the Site Editor’s Design panel](https://make.wordpress.org/core/2026/03/25/whats-new-in-gutenberg-22-8-25-march/#site-logo-icon-in-the-design-panel), shipping in Gutenberg 22.8. The new screen uses a compact media editor for both fields, making it quicker to set or swap your site’s logo and favicon without navigating out to admin settings separately. A small but welcome quality-of-life improvement for anyone building or managing block themes.

### Navigation: add links directly from the sidebar List View

You can now [add new navigation links directly from the Site Editor’s sidebar List View](https://github.com/WordPress/gutenberg/pull/75918) for navigation menus, rather than needing to open the block in the editor canvas and interact with the block inspector. It’s a small quality-of-life change, but one that meaningfully speeds up the process of managing navigation menus in the Site Editor.

## Plugins and tools

### RTC: Collaborator text selections now visible

In Gutenberg 22.8, real-time collaboration now shows collaborator [**text selections**](https://make.wordpress.org/core/2026/03/25/whats-new-in-gutenberg-22-8-25-march/#real-time-collaboration-improvements), not just cursor positions. When another user selects text, you’ll see their selection highlighted in their assigned color — consistent with what you’d expect from collaborative tools like Google Docs. The release also introduced redesigned presence avatars, peer limits, and disconnection debouncing for improved stability.

### Connectors extensibility

Gutenberg 22.8 introduced [extensibility improvements to the Connectors system](https://make.wordpress.org/core/2026/03/25/whats-new-in-gutenberg-22-8-25-march/#connectors-extensibility), allowing third-party provider plugins to integrate more deeply with the Connectors screen and API.

### Client-side navigation block variant for create-block

The `@wordpress/create-block` scaffolding tool has a [new interactive variant](https://github.com/WordPress/gutenberg/pull/76331) that adds client-side navigation support out of the box. The variant provides a self-contained working example that covers common real-world patterns — query parameter navigation for pagination, search results, and filtered archives — and works immediately after scaffolding, with no additional setup required. If you’ve ever found the Interactivity API’s client-side navigation tricky to wire up from scratch, this is a great starting point.

### Pattern overrides for custom blocks

Pattern overrides in WordPress 7.0 are being [extended to support custom blocks](https://make.wordpress.org/core/2026/03/16/pattern-overrides-in-wp-7-0-support-for-custom-blocks/) — not just core blocks. This means you can create synced patterns that include your own custom blocks while still allowing per-instance content edits. Read the [Pattern Overrides Dev Note](https://make.wordpress.org/core/2026/03/16/pattern-overrides-in-wp-7-0-support-for-custom-blocks/) and the [Pattern Editing Dev Note](https://make.wordpress.org/core/2026/03/15/pattern-editing-in-wordpress-7-0/) for the full picture.

### Changes to the Interactivity API

The Interactivity API has some notable changes heading into 7.0: `state.navigation` is now **deprecated**. The new `watch()` function and server-side `state.url` population enables cleaner patterns for side effects and navigation tracking. If your plugin or theme uses `state.navigation`, now’s the time to migrate. See the [Interactivity API Dev Note](https://make.wordpress.org/core/2026/03/04/changes-to-the-interactivity-api-in-wordpress-7-0/) for migration guidance.

### Command palette: sections and recently used

The command palette received a [structural update in 22.9](https://github.com/WordPress/gutenberg/pull/75691), introducing **sections** to organize commands into logical groups, along with a **Recently used** section that surfaces your most-used commands at the top. If your plugin registers custom commands via `wp.data.dispatch( 'core/commands' )`, they’ll now appear within the appropriate section rather than in a flat list.

### Forms Block: hidden input field variation

The Forms block gained a [hidden input field variation](https://github.com/WordPress/gutenberg/pull/74131) in Gutenberg 22.9. This lets you add hidden fields to block-based forms — useful for tracking parameters, form identifiers, or any metadata you need to pass along on submission without displaying it to the user.

## Themes

### Button pseudo-state styling in Global Styles

A new **State** dropdown now appears in Global Styles next to the Button block title. Selecting a state — Hover, Focus, or Active — switches all style controls to edit that specific pseudo-state, with a live preview in the block preview area. No more needing `theme.json` or custom CSS to style button states. This shipped in [Gutenberg 22.8](https://github.com/WordPress/gutenberg/pull/75627).

### Pseudo-element support for buttons in theme.json

Theme developers are now able to style `:hover`, `:focus`, `:focus-visible`, and `:active` states directly on `button` blocks and their style variations using `theme.json`. This was previously only possible using custom CSS. The [Dev Note](https://make.wordpress.org/core/2026/03/09/pseudo-element-support-for-blocks-and-their-variations-in-theme-json/) contains a detailed example of how this works. This work is part of a broader effort towards a [standardized way to modify interactive states](https://github.com/WordPress/gutenberg/issues/38277).

### Block visibility: viewport-based controls

WordPress 7.0 expands the Block Visibility feature from 6.9. Users will be able to show or hide blocks per device — mobile, tablet, desktop — via the new `viewport` key inside `blockVisibility` metadata. Crucially, this is implemented via CSS, **not** DOM removal.

If your code assumes `blockVisibility` is always a boolean, you’ll need to update it to handle an object as well. No changes are needed if your blocks don’t interact with the markup server-side. Full details in the [Block Visibility Dev Note](https://make.wordpress.org/core/2026/03/15/block-visibility-in-wordpress-7-0/).

### Navigation link: style the current menu item via theme.json

The Navigation Link block now [supports styling the current/active menu item](https://github.com/WordPress/gutenberg/pull/75736) directly via `theme.json`. Previously, targeting the active state required custom CSS with specificity workarounds. Theme authors can now handle it cleanly in the same place as the rest of their navigation styles.

### Tabs block inner block restructure

The Tabs block has undergone [further restructuring of its inner blocks](https://github.com/WordPress/gutenberg/pull/75954) in Gutenberg 22.8, following the significant refactor covered in the [February 2026 roundup](https://developer.wordpress.org/news/2026/02/whats-new-for-developers-february-2026/). The Tabs Menu and inner block structure continue to be refined based on contributor feedback. If you’re building themes or plugins that style or extend the Tabs block, keep an eye on this [tracking issue as it evolves](https://github.com/WordPress/gutenberg/issues/73230).

### Cover Block: loop YouTube background videos

The Cover block now supports [a playlist parameter to loop YouTube background videos](https://github.com/WordPress/gutenberg/pull/76004). Previously, YouTube’s embed API didn’t expose a clean way to loop a single video — the block now handles this automatically by passing the correct playlist parameter behind the scenes. No changes needed for existing Cover blocks; the looping behaviour will work automatically for YouTube backgrounds going forward.

### Background gradient support for background images

Gutenberg 22.9 adds [background gradient support that can combine with background images](https://github.com/WordPress/gutenberg/pull/75859) via block supports. Theme and block developers can now layer a gradient over a background image directly through the block editor’s design controls — useful for overlays, text readability treatments, and decorative effects without needing custom CSS. If your block registers `background` support, gradient options will be available alongside existing image controls.

## Playground

### WordPress Playground MCP server

As someone who’s always interested in WordPress AI news, this is one I’m genuinely excited about. [WordPress Playground now has an official MCP server](https://make.wordpress.org/playground/2026/03/17/connect-ai-coding-agents-to-wordpress-playground-with-mcp/) via the new `@wp-playground/mcp` package. One command wires up Claude Code or Gemini CLI to a browser-based Playground instance over WebSocket, letting your AI agent read and write files, execute PHP, manage sites, and navigate pages — all locally, without touching the WordPress admin.

```
claude mcp add --transport stdio --scope user wordpress-playground -- npx -y @wp-playground/mcp
```

The MCP server exposes tools across four areas:

- **Site management:** `playground_get_website_url`, `playground_list_sites`, `playground_open_site`, `playground_rename_site`, `playground_save_site`
- **Code execution:** `playground_execute_php`, `playground_request`
- **Navigation:** `playground_navigate`, `playground_get_current_url`, `playground_get_site_info`
- **Filesystem:** `playground_read_file`, `playground_write_file`, `playground_list_files`, `playground_mkdir`, `playground_delete_file`, and more

Think plugin testing, live database debugging, and theme scaffolding driven entirely by conversation.

## Resources

### WordPress 7.0 Dev Notes and Field Guide

The full Field Guide will be published alongside the rescheduled release. In the meantime, the published dev notes are essential reading if you’re preparing your plugins or themes. Here’s the full list so far:

- [Real-Time Collaboration in the Block Editor](https://make.wordpress.org/core/2026/03/10/real-time-collaboration-in-the-block-editor/)
- [Introducing the AI Client in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- [Introducing the Connectors API in WordPress 7.0](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
- [Client-Side Abilities API in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/)
- [PHP-only block registration](https://make.wordpress.org/core/2026/03/03/php-only-block-registration/)
- [Custom CSS for Individual Block Instances](https://make.wordpress.org/core/2026/03/15/custom-css-for-individual-block-instances-in-wordpress-7-0/)
- [New Block Support: Text Indent (textIndent)](https://make.wordpress.org/core/2026/03/15/new-block-support-text-indent-textindent/)
- [Dimensions Support Enhancements in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/dimensions-support-enhancements-in-wordpress-7-0/)
- [Block Visibility in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/block-visibility-in-wordpress-7-0/)
- [Pattern Overrides in WordPress 7.0: Support for Custom Blocks](https://make.wordpress.org/core/2026/03/16/pattern-overrides-in-wp-7-0-support-for-custom-blocks/)
- [Pattern Editing in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/pattern-editing-in-wordpress-7-0/)
- [Pseudo-element support for blocks and their variations in theme.json](https://make.wordpress.org/core/2026/03/09/pseudo-element-support-for-blocks-and-their-variations-in-theme-json/)
- [Changes to the Interactivity API in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/changes-to-the-interactivity-api-in-wordpress-7-0/)
- [DataViews, DataForm, et al. in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/dataviews-dataform-et-al-in-wordpress-7-0/)
- Customizable [Navigation Overlays in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/customisable-navigation-overlays-in-wordpress-7-0/)
- [Breadcrumb block filters](https://make.wordpress.org/core/2026/03/04/breadcrumb-block-filters/)

Keep an eye on [make.wordpress.org/core](https://make.wordpress.org/core) for additional dev notes and the updated release schedule as they’re published.

### Developer Blog

- If you’re currently using `@wordpress/scripts`, this one’s for you. JuanMa Garrido published [a detailed introduction to `@wordpress/build`](https://developer.wordpress.org/news/2026/04/wordpress-build-the-next-generation-of-wordpress-plugin-build-tooling/) on the Developer Blog. The new tool replaces the webpack and Babel pipeline with a significantly faster esbuild-based engine and automatically generates PHP registration files from `package.json`. The migration path for existing `@wordpress/scripts` users is designed to be low-friction. If you’re planning to update your plugin’s build tooling, that post is essential reading.

Never want to miss an article again? [Subscribe to the WordPress Developer Blog](https://developer.wordpress.org/news/subscribe/).

*Props to [@greenshady](https://profiles.wordpress.org/greenshady/) and [@areziaal](https://profiles.wordpress.org/areziaal/) for reviewing this article.*
