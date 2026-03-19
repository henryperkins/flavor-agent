# WordPress 7.0 / Gutenberg 22.8 -- Developer Reference

> Compiled: 2026-03-19
> Sources: WP 7.0-beta5 core, Gutenberg 22.8.0-rc.1, official dev notes
> Scope: API changes, new features, and deprecations relevant to block editor plugin development

### Official References

- [What's New for Developers (March 2026)](https://developer.wordpress.org/news/2026/03/whats-new-for-developers-march-2026/) -- Monthly roundup
- [WordPress 7.0 Release Page](https://make.wordpress.org/core/7-0/) -- Schedule, leads, milestones
- [Planning for 7.0](https://make.wordpress.org/core/2025/12/11/planning-for-7-0/) -- Feature targets and roadmap
- [AI as a WordPress Fundamental](https://make.wordpress.org/core/2025/12/04/ai-as-a-wordpress-fundamental/) -- Vision for AI in core

---

## Table of Contents

1. [Connectors API](#connectors-api)
2. [WP AI Client](#wp-ai-client)
3. [Pattern Editing](#pattern-editing)
4. [Pattern Overrides for Custom Blocks](#pattern-overrides-for-custom-blocks)
5. [Dimensions Support Enhancements](#dimensions-support-enhancements)
6. [Block Bindings API](#block-bindings-api)
7. [Navigation Overlays](#navigation-overlays)
8. [Interactivity API Changes](#interactivity-api-changes)
9. [Block API and Editor Changes](#block-api-and-editor-changes)
10. [Experimental API Status](#experimental-api-status)
11. [New WP-CLI Commands](#new-wp-cli-commands)
12. [Impact on Flavor Agent](#impact-on-flavor-agent)

---

## Connectors API

**Since:** WordPress 7.0.0
**Trac:** [#64591 -- Add WP AI Client and corresponding connectors screen](https://core.trac.wordpress.org/ticket/64591) (closed/fixed, milestone 7.0)
**Dev note:** [Introducing the Connectors API in WordPress 7.0](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
**PR:** [Connectors screen (#75833)](https://github.com/WordPress/gutenberg/pull/75833)
**Files:** `wp-includes/connectors.php`, `wp-includes/class-wp-connector-registry.php`

A unified framework for registering and managing external service integrations. Currently focused on AI providers.

### Core Concepts

- A **connector** represents a connection to an external service with standardized metadata, authentication, and plugin association.
- AI provider plugins that register with the WP AI Client's `ProviderRegistry` get **automatic connector integration** -- no explicit registration needed.
- Built-in connectors: **Anthropic**, **Google**, **OpenAI** (hardcoded defaults, enriched by provider plugin metadata).

### Public Functions

```php
wp_is_connector_registered( string $id ): bool
wp_get_connector( string $id ): ?array
wp_get_connectors(): array
```

### Connector Data Shape

```php
[
    'name'           => 'Anthropic',
    'description'    => 'Text generation with Claude.',
    'logo_url'       => 'https://...',
    'type'           => 'ai_provider',          // Only type currently supported.
    'authentication' => [
        'method'          => 'api_key',          // 'api_key' or 'none'.
        'credentials_url' => 'https://...',
        'setting_name'    => 'connectors_ai_anthropic_api_key',  // Auto-generated.
    ],
    'plugin'         => [
        'slug' => 'ai-provider-for-anthropic',
    ],
]
```

### Authentication Key Resolution Order

For `api_key` connectors, credentials are resolved in this order:
1. **Environment variable**: `{PROVIDER_ID}_API_KEY` (e.g., `ANTHROPIC_API_KEY`)
2. **PHP constant**: same name as environment variable
3. **Database**: `get_option( $setting_name )`

### Settings Registration

`api_key` connectors whose provider exists in the WP AI Client registry get automatic Settings API registration:
- Setting name pattern: `connectors_ai_{$id}_api_key`
- `show_in_rest: true` (accessible via `/wp/v2/settings`)
- API keys are **automatically masked** in REST responses (last 4 chars visible)
- Keys are **validated against the provider** on update via `_wp_connectors_is_ai_api_key_valid()`

### Admin UI

Settings > Connectors screen renders each connector as a card showing:
- Name, description, logo
- Plugin install/activate status
- API key source (env, constant, database, none)
- Connection status

### Hooks

```php
// Override metadata on existing connectors or register additional connectors.
// Fires after built-in connectors and AI Client auto-discovery.
add_action( 'wp_connectors_init', function ( WP_Connector_Registry $registry ) {
    if ( $registry->is_registered( 'openai' ) ) {
        $connector = $registry->unregister( 'openai' );
        $connector['description'] = __( 'Custom description.', 'my-plugin' );
        $registry->register( 'openai', $connector );
    }
} );
```

### Initialization Lifecycle

During `init`:
1. Creates `WP_Connector_Registry` singleton.
2. Registers built-in connectors (Anthropic, Google, OpenAI) with hardcoded defaults.
3. Auto-discovers providers from WP AI Client registry, merges metadata (registry values take precedence).
4. Fires `wp_connectors_init` action for plugin overrides.
5. Registers settings and passes stored API keys to the WP AI Client.

---

## WP AI Client

**Since:** WordPress 7.0.0
**Trac:** [#64591 -- Add WP AI Client and corresponding connectors screen](https://core.trac.wordpress.org/ticket/64591) (closed/fixed, milestone 7.0)
**Proposal:** [Proposal for Merging WP AI Client into WordPress 7.0](https://make.wordpress.org/core/2026/02/03/proposal-for-merging-wp-ai-client-into-wordpress-7-0/)
**Dev repo (deprecated):** [WordPress/wp-ai-client](https://github.com/WordPress/wp-ai-client) -- deprecated in favor of the built-in AI client in WordPress 7.0+

### Architecture

Two layers are bundled in core:

| Layer | Files | Packagist | Purpose |
|-------|-------|-----------|---------|
| PHP AI Client SDK | `wp-includes/php-ai-client/` | [`wordpress/php-ai-client`](https://packagist.org/packages/wordpress/php-ai-client) | Low-level, provider-agnostic SDK (similar to how `Requests` is bundled) |
| WordPress AI Client | `wp-includes/ai-client/`, `wp-includes/ai-client.php` | N/A (part of core) | WordPress wrapper: `WP_AI_Client_Prompt_Builder`, adapters, `wp_ai_client_prompt()` entry point |

The public API entry point is `wp_ai_client_prompt()`:

```php
// The recommended entry point -- uses the default provider registry.
$result = wp_ai_client_prompt( 'Recommend a block layout for a services page.' )
    ->using_system_instruction( 'You are a helpful assistant.' )
    ->using_provider( 'anthropic' )
    ->using_max_tokens( 4096 )
    ->using_temperature( 0.7 )
    ->generate_text();

if ( is_wp_error( $result ) ) {
    // Handle error.
}
```

### WP_AI_Client_Prompt_Builder

Fluent builder wrapping the PHP AI Client SDK with WordPress-specific behavior (WP_Error instead of exceptions, snake_case methods, Abilities API integration).

### Key Methods

| Method | Purpose |
|--------|---------|
| `with_text( string $text )` | Add text to current message |
| `with_file( $file, ?string $mimeType )` | Add a file (image, etc.) |
| `with_function_response( FunctionResponse $resp )` | Add tool call response |
| `with_history( Message ...$messages )` | Add conversation history |
| `using_provider( string $id )` | Set provider (e.g., `'anthropic'`) |
| `using_model( ModelInterface $model )` | Set specific model |
| `using_model_preference( ...$models )` | Set preferred models in order |
| `using_system_instruction( string $text )` | Set system prompt |
| `using_max_tokens( int $n )` | Set max output tokens |
| `using_temperature( float $t )` | Set temperature |
| `using_function_declarations( FunctionDeclaration ...$fns )` | Register tool functions |
| `using_abilities( WP_Ability\|string ...$abilities )` | Register WP abilities as tool functions |
| `as_json_response( ?array $schema )` | Request JSON output |
| `generate_text()` | Generate text (returns `string\|WP_Error`) |
| `generate_image()` | Generate image (returns `File\|WP_Error`) |

### Abilities Integration

The `using_abilities()` method converts `WP_Ability` objects to `FunctionDeclaration` instances using the `wpab__` prefix naming convention:

```php
$builder = ( new WP_AI_Client_Prompt_Builder( AiClient::defaultRegistry() ) )
    ->using_abilities( 'flavor-agent/recommend-block', 'flavor-agent/get-theme-tokens' )
    ->with_text( 'Help me improve this page layout.' )
    ->generate_text();
```

### Error Handling

Only generating methods (`generate_text()`, `generate_image()`, etc.) return `WP_Error`. All other methods return `$this` for chaining. If an error occurs mid-chain, subsequent calls are no-ops until a generating method is called.

Error codes:
- `prompt_network_error` (503)
- `prompt_client_error` (400)
- `prompt_upstream_server_error` (500)
- `prompt_token_limit_reached` (400)
- `prompt_invalid_argument` (400)
- `prompt_prevented` (503) -- blocked by `wp_ai_client_prevent_prompt` filter
- `prompt_builder_error` (500)

### Filters

```php
// Override default HTTP timeout (default: 30s).
add_filter( 'wp_ai_client_default_request_timeout', function () {
    return 60;
} );

// Prevent prompt execution (e.g., for rate limiting or policy).
add_filter( 'wp_ai_client_prevent_prompt', function ( $prevent, $builder ) {
    return $prevent;
}, 10, 2 );
```

---

## Pattern Editing

**Dev note:** [Pattern Editing in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/pattern-editing-in-wordpress-7-0/)
**Tracking issue:** [Content-Only editing (#73775)](https://github.com/WordPress/gutenberg/issues/73775)

### ContentOnly Editing Mode

Patterns now default to **content-only editing** in WP 7.0. When editing a page that contains a synced or unsynced pattern, the pattern's inner blocks are restricted:
- Only `role: "content"` attributes and blocks with `supports.contentRole: true` are editable.
- Style, layout, and other settings panels are hidden.
- Detaching a pattern restores full block access.

### Block Editing Modes

Three modes returned by `getBlockEditingMode( state, clientId )`:

| Mode | Behavior |
|------|----------|
| `'default'` | Full editing: all panels, toolbar, settings |
| `'contentOnly'` | Only content-role attributes editable; style/settings panels hidden |
| `'disabled'` | Block cannot be selected at all |

### Section Block Detection

A block is treated as a "section" (content-only boundary) if:
1. It is `core/block` (synced pattern), OR
2. It has `metadata.patternName` (unsynced pattern) AND `disableContentOnlyForUnsyncedPatterns` is not set, OR
3. It is `core/template-part` AND `disableContentOnlyForTemplateParts` is not set, OR
4. It has `templateLock: 'contentOnly'` and its parent does not

### Opting Out of ContentOnly for Unsynced Patterns

```php
// PHP filter.
add_filter( 'block_editor_settings_all', function ( $settings ) {
    $settings['disableContentOnlyForUnsyncedPatterns'] = true;
    return $settings;
} );
```

```js
// JS dispatch.
wp.data.dispatch( 'core/block-editor' ).updateSettings( {
    disableContentOnlyForUnsyncedPatterns: true,
} );
```

### Content Block Detection

A block is a "content block" (`isContentBlock()`) if:
1. `supports.contentRole` is `true` (block-level declaration), OR
2. Any attribute has `role: "content"` or `__experimentalRole: "content"`

### Blocks with `supports.contentRole: true`

Container blocks that express content through inner blocks rather than their own attributes:
- `core/buttons`
- `core/navigation`
- `core/social-links`
- `core/accordion`
- `core/accordion-item`
- `core/accordion-panel`
- `core/page-list`
- `core/site-tagline`

### Blocks with `role: "content"` Attributes

45+ core blocks define individual content attributes. Key examples:

| Block | Content Attributes |
|-------|--------------------|
| `core/paragraph` | `content` |
| `core/heading` | `content` |
| `core/image` | `url`, `alt`, `caption`, `title`, `href`, `id` |
| `core/button` | `url`, `title`, `text`, `linkTarget`, `rel` |
| `core/cover` | `url` |
| `core/gallery` | `caption` |
| `core/pullquote` | `value`, `citation` |
| `core/quote` | `value`, `citation` |
| `core/verse` | `content` |

### `role` vs `__experimentalRole`

`__experimentalRole` was deprecated in WP 6.7 with removal targeted for 6.8. The stable property is `role`. All core blocks now use `role`. Backward-compat shims still exist in Gutenberg JS for third-party blocks.

```json
// block.json attribute schema
{
    "content": {
        "type": "rich-text",
        "source": "rich-text",
        "selector": "p",
        "role": "content"
    }
}
```

### Isolated Editor Context

When directly editing a pattern (`wp_block`), template part, or navigation entity, the editor enters "isolated" mode. Nested patterns and template parts are NOT treated as content-only sections.

### New Private Selectors (Gutenberg 22.x)

| Selector | Purpose |
|----------|---------|
| `isSectionBlock( state, clientId )` | Whether block is a content-only section boundary |
| `getParentSectionBlock( state, clientId )` | Nearest ancestor section block |
| `getEditedContentOnlySection( state )` | Currently temporarily-unlocked section |
| `isWithinEditedContentOnlySection( state, clientId )` | Whether block is inside an unlocked section |
| `hasBlockSpotlight( state )` | Whether a section is being temporarily edited |

### Deprecated Actions

`__unstableSetTemporarilyEditingAsBlocks()` -- deprecated since 7.0, replaced by private `editContentOnlySection( clientId )`.

---

## Pattern Overrides for Custom Blocks

**Dev note:** [Pattern Overrides in WP 7.0: Support for Custom Blocks](https://make.wordpress.org/core/2026/03/16/pattern-overrides-in-wp-7-0-support-for-custom-blocks/)
**Background:** [New Feature: The Block Bindings API (6.5)](https://make.wordpress.org/core/2024/03/06/new-feature-the-block-bindings-api/) | [Block Bindings improvements in WordPress 6.9](https://make.wordpress.org/core/2025/11/12/block-bindings-improvements-in-wordpress-6-9/)

### How Pattern Overrides Work

Pattern overrides allow individual blocks within a synced pattern to have per-instance attribute overrides while the rest of the pattern stays synchronized.

**Data flow:**
1. A block inside a synced pattern gets `metadata.bindings.__default.source = "core/pattern-overrides"` and a unique `metadata.name`.
2. When the pattern is placed as `core/block`, override values are stored in the `content` attribute, keyed by `metadata.name`.
3. `core/block` provides context via `providesContext: { "pattern/overrides": "content" }`.
4. The `core/pattern-overrides` binding source resolves values from context at render time.

### The `__default` Binding Key

A shorthand that expands to individual bindings for ALL supported attributes of the block type:

```json
{
    "metadata": {
        "bindings": {
            "__default": { "source": "core/pattern-overrides" }
        },
        "name": "my-unique-block-name"
    }
}
```

Expansion happens server-side in `WP_Block::process_block_bindings()` and client-side in `replacePatternOverridesDefaultBinding()`.

### Enabling Pattern Overrides for Custom Blocks

**The key mechanism (since WP 6.9):** The `block_bindings_supported_attributes` filter.

```php
// Register which attributes of your custom block can be bound/overridden.
add_filter(
    'block_bindings_supported_attributes_my-plugin/my-block',
    function ( $supported_attributes ) {
        $supported_attributes[] = 'title';
        $supported_attributes[] = 'description';
        return $supported_attributes;
    }
);
```

**Filter hooks:**
- `block_bindings_supported_attributes` -- general filter, receives `( $supported_attributes, $block_type )`
- `block_bindings_supported_attributes_{$block_type}` -- block-type-specific filter

This filter also controls which blocks appear in the pattern overrides UI in the editor:
```js
const isSupportedBlock = useSelect( ( select ) => {
    const { __experimentalBlockBindingsSupportedAttributes } =
        select( blockEditorStore ).getSettings();
    return !! __experimentalBlockBindingsSupportedAttributes?.[ props.name ];
}, [ props.name ] );
```

### Default Supported Blocks (Core)

```php
// wp-includes/block-bindings.php
$block_bindings_supported_attributes = [
    'core/paragraph'          => [ 'content' ],
    'core/heading'            => [ 'content' ],
    'core/image'              => [ 'id', 'url', 'title', 'alt', 'caption' ],
    'core/button'             => [ 'url', 'text', 'linkTarget', 'rel' ],
    'core/post-date'          => [ 'datetime' ],
    'core/navigation-link'    => [ 'url' ],
    'core/navigation-submenu' => [ 'url' ],
];
```

New in WP 7.0: `caption` on `core/image`, plus `core/post-date`, `core/navigation-link`, `core/navigation-submenu`.

### Requirements for Custom Block Override Support

1. Register the block with standard `register_block_type()`.
2. Use the `block_bindings_supported_attributes_{$block_type}` filter to declare bindable attributes.
3. Attributes must have proper `source` and `selector` in the block type schema for HTML replacement to work (supports `html`, `rich-text`, and `attribute` source types).

### No `supports.patternOverrides` Key

There is no `supports.patternOverrides` or `supports.bindings` in `block.json`. Pattern override support is controlled entirely through the `block_bindings_supported_attributes` filter.

---

## Dimensions Support Enhancements

**Dev note:** [Dimensions Support Enhancements in WordPress 7.0](https://make.wordpress.org/core/2026/03/15/dimensions-support-enhancements-in-wordpress-7-0/)
**Background:** [Minimum height dimensions block support (6.2)](https://make.wordpress.org/core/2023/03/06/minimum-height-dimensions-block-support/)

### Schema

`supports.dimensions` in `block.json`:

```json
{
    "supports": {
        "dimensions": {
            "aspectRatio": false,
            "height": false,
            "minHeight": false,
            "width": false
        }
    }
}
```

All four sub-properties default to `false`.

### Blocks with Dimensions Support (Gutenberg 22.8)

| Block | aspectRatio | height | minHeight | width |
|-------|:-----------:|:------:|:---------:|:-----:|
| `core/group` | | | yes | |
| `core/cover` | yes | | | |
| `core/post-content` | | | yes | |
| `core/verse` | | | yes* | |
| `core/pullquote` | | | yes* | |
| `core/quote` | | | yes* | |
| `core/button` | | | | yes** |
| `core/icon` | | | | yes** |

\* Hidden by default (`__experimentalDefaultControls: { minHeight: false }`)
\** Skips serialization (`__experimentalSkipSerialization: ["width"]`)

### Aspect Ratio vs. Height Mutual Exclusivity

The dimensions hook enforces mutual exclusivity:
- Setting `aspectRatio` injects `minHeight: 'unset'` and `height: 'unset'`
- Setting `height` or `minHeight` injects `aspectRatio: 'unset'`

Plugins should not suggest both simultaneously.

### Image Aspect Ratio

`core/image`, `core/gallery`, and `core/post-featured-image` handle aspect ratio via their own **block-level attributes** (`aspectRatio` attribute), NOT through `supports.dimensions.aspectRatio`.

### Custom Selectors for Dimensions

`block.json` supports per-feature CSS selectors:

```json
{
    "selectors": {
        "dimensions": {
            "root": ".wp-block-button",
            "width": ".wp-block-button"
        }
    }
}
```

### Style Engine CSS Properties

Both PHP and JS style engines handle four properties under the `dimensions` group:

| Style Path | CSS Property | Preset Support |
|------------|-------------|----------------|
| `style.dimensions.aspectRatio` | `aspect-ratio` | `has-aspect-ratio` class |
| `style.dimensions.height` | `height` | `--wp--preset--dimension--$slug` |
| `style.dimensions.minHeight` | `min-height` | `--wp--preset--dimension--$slug` |
| `style.dimensions.width` | `width` | `--wp--preset--dimension--$slug` |

---

## Block Bindings API

**Since:** WordPress 6.5 (expanded in 6.9 and 7.0)
**Dev notes:** [Block Bindings API (6.5)](https://make.wordpress.org/core/2024/03/06/new-feature-the-block-bindings-api/) | [Improvements in 6.7](https://make.wordpress.org/core/2024/10/21/block-bindings-improvements-to-the-editor-experience-in-6-7/) | [Improvements in 6.9](https://make.wordpress.org/core/2025/11/12/block-bindings-improvements-in-wordpress-6-9/)

### Registered Sources (WP 7.0)

| Source | Purpose |
|--------|---------|
| `core/pattern-overrides` | Per-instance attribute overrides in synced patterns |
| `core/post-meta` | Bind attributes to post meta fields |
| `core/term-data` | Bind attributes to taxonomy term data |

### Registering a Custom Binding Source

```php
register_block_bindings_source( 'my-plugin/my-source', [
    'label'              => __( 'My Source', 'my-plugin' ),
    'get_value_callback' => function ( $source_args, $block_instance, $attribute_name ) {
        return 'resolved value';
    },
    'uses_context'       => [ 'postId' ],
] );
```

### Supported Attributes Filter

```php
// General filter.
add_filter( 'block_bindings_supported_attributes', function ( $attrs, $block_type ) {
    if ( $block_type === 'my-plugin/my-block' ) {
        $attrs[] = 'customField';
    }
    return $attrs;
}, 10, 2 );

// Block-specific filter.
add_filter( 'block_bindings_supported_attributes_my-plugin/my-block', function ( $attrs ) {
    $attrs[] = 'customField';
    return $attrs;
} );
```

---

## Navigation Overlays

**Since:** WordPress 7.0.0 (stabilized from experimental)
**Dev note:** [Customisable Navigation Overlays in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/customisable-navigation-overlays-in-wordpress-7-0/)

Navigation overlays are now a first-class template-part area.

### Template Part Area

```php
// The area 'navigation-overlay' is registered by core.
// Patterns can be scoped to it via templateTypes:
register_block_pattern( 'my-plugin/overlay-pattern', [
    'title'         => 'My Overlay',
    'templateTypes' => [ 'navigation-overlay' ],
    'content'       => '<!-- wp:navigation --><!-- /wp:navigation -->',
] );
```

### Key Changes
- [Removed experiment flag (#74968)](https://github.com/WordPress/gutenberg/pull/74968)
- ["Create Overlay" button (#74971)](https://github.com/WordPress/gutenberg/pull/74971)
- [Update naming to 'Navigation Overlay' (#75564)](https://github.com/WordPress/gutenberg/pull/75564)
- [Filter navigation patterns for overlay context (#75276)](https://github.com/WordPress/gutenberg/pull/75276)

---

## Interactivity API Changes

**Dev note:** [Changes to the Interactivity API in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/changes-to-the-interactivity-api-in-wordpress-7-0/)

### New: `watch()` Function

Cleaner pattern for reactive side effects:

```js
import { store, watch } from '@wordpress/interactivity';

const { state } = store( 'my-plugin', {
    state: { count: 0 },
} );

watch( () => {
    console.log( 'Count changed:', state.count );
} );
```

### Server-Side `state.url`

The router now populates `state.url` server-side for navigation tracking.

### Deprecated: `state.navigation`

`state.navigation` is deprecated. Use `state.url` and the router's navigation events instead.

---

## Block API and Editor Changes

### Always-Iframed Post Editor -- Punted to 7.1

The post editor will NOT always be iframed in WP 7.0. This was [deferred to 7.1](https://make.wordpress.org/core/2026/02/24/iframed-editor-changes-in-wordpress-7-0/). The current behavior remains: iframed only when all blocks support API version 3+. The Gutenberg plugin continues to iframe the post editor.

### Block API Version 3

Blocks with `apiVersion: 3` signal iframe compatibility. In WP 7.0, this remains advisory. In 7.1, the post editor will always use an iframe regardless.

### `supports.contentRole`

New block support (Gutenberg 22.x):

```json
{
    "supports": {
        "contentRole": true
    }
}
```

Marks the block itself as a content block, intended for container blocks whose content is expressed through inner blocks rather than their own attributes.

### New Typography Supports

| Support | Blocks | Since | Dev Note |
|---------|--------|-------|----------|
| `typography.fitText` | `core/heading`, `core/paragraph` | Gutenberg 22.x | |
| `typography.textIndent` | `core/paragraph` | WP 7.0.0 | [New Block Support: Text Indent](https://make.wordpress.org/core/2026/03/15/new-block-support-text-indent-textindent/) |

### Block Selectors API Enhancement

["Additional CSS" in Global Styles](https://github.com/WordPress/gutenberg/pull/75799) now honors block-defined feature selectors from `block.json`:

```json
{
    "selectors": {
        "css": {
            "root": ".wp-block-my-block",
            "typography": ".wp-block-my-block > .content"
        }
    }
}
```

### In-Editor Visual Revisions ([#75049](https://github.com/WordPress/gutenberg/pull/75049))

Color-coded overlays in the document inspector:
- Green outlines = added blocks
- Red = removed blocks
- Yellow = modified settings
- Text: green/underlined (added), red/strikethrough (removed), yellow outline (format-only)

### PHP-Only Block Registration

Blocks can now be registered using PHP only with `supports.autoRegister` for autogenerated inspector controls. See [PHP-only block registration dev note](https://make.wordpress.org/core/2026/03/03/php-only-block-registration/).

### SVG Icon Registration API

New [Icon block (#71227)](https://github.com/WordPress/gutenberg/pull/71227) with REST endpoint `/wp/v2/icons`. Third-party icon collection registration planned for 7.1.

---

## Experimental API Status

### Still Experimental (No Stable Replacement)

These APIs remain experimental in both WP 7.0 and Gutenberg 22.8. They are the correct and only way to access their respective features.

| API | Used By | Notes |
|-----|---------|-------|
| `__experimentalBlockPatterns` (editor setting) | Pattern inserter patching | No public `blockPatterns` key exists |
| `__experimentalGetAllowedPatterns` (selector) | Inserter-scoped pattern lists | No deprecation notice (unlike `__experimentalGetAllowedBlocks` which was deprecated in 6.2) |
| `__experimentalFeatures` (editor setting) | Theme token extraction | Deeply embedded, set by PHP in `block-editor-settings.php` |
| `__experimentalBlockPatternCategories` (editor setting) | Pattern category system | No stable replacement |
| `__experimentalBlockBindingsSupportedAttributes` (editor setting) | Pattern override UI gating | Derived from `block_bindings_supported_attributes` filter |

### Private APIs (Not for External Use)

| API | Purpose |
|-----|---------|
| `getAllPatterns` (private selector) | Returns all patterns; listed in Gutenberg's [`docs/private-apis.md`](https://github.com/WordPress/gutenberg/blob/trunk/docs/private-apis.md) |
| `isSectionBlock` / `getParentSectionBlock` | Section detection for contentOnly |
| `editContentOnlySection` | Temporarily unlock section for editing |

### Deprecated

| API | Since | Replacement |
|-----|-------|-------------|
| `__experimentalRole` (attribute property) | 6.7 | `role` |
| `__unstableSetTemporarilyEditingAsBlocks` | 7.0 | `editContentOnlySection` (private) |
| `wp.editPost.PluginDocumentSettingPanel` | 6.6 | `wp.editor.PluginDocumentSettingPanel` |
| `dispatch('core/edit-post').setIsInserterOpened` | 6.5 | `dispatch('core/editor').setIsInserterOpened` |
| `state.navigation` (Interactivity API) | 7.0 | `state.url` |

### Stable Public APIs (Confirmed Safe)

| API | Location |
|-----|----------|
| `getBlockEditingMode` | `core/block-editor` selector |
| `editor.BlockEdit` filter | `@wordpress/hooks` |
| `createHigherOrderComponent` | `@wordpress/compose` |
| `setIsInserterOpened` | `core/editor` action |
| `PluginDocumentSettingPanel` | `@wordpress/editor` component |
| `updateSettings` | `core/block-editor` action |
| `wp_register_ability` / `wp_register_ability_category` | `wp-includes/abilities-api.php` ([Abilities API dev note](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/)) |
| `register_block_bindings_source` | `wp-includes/block-bindings.php` |
| `block_bindings_supported_attributes` filter | `wp-includes/block-bindings.php` |

---

## New WP-CLI Commands

### `wp block` Commands

Read-only access to block entities with pattern/template export:

```bash
wp package install wp-cli/block-command:dev-main
```

### `wp ability` Commands

Manage and inspect WordPress abilities. See [WP-CLI ability command reference](https://developer.wordpress.org/cli/commands/ability/).

```bash
wp package install wp-cli/ability-command:dev-main
```

Both targeting WP-CLI 3.0 stable (end of March 2026).

## Other Dev Notes

| Topic | Link |
|-------|------|
| Real-Time Collaboration | [Real-Time Collaboration in the Block Editor](https://make.wordpress.org/core/2026/03/10/real-time-collaboration-in-the-block-editor/) |
| DataViews / DataForm | [DataViews, DataForm, et al. in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/dataviews-dataform-et-al-in-wordpress-7-0/) |
| Breadcrumb Block Filters | [Breadcrumb block filters](https://make.wordpress.org/core/2026/03/04/breadcrumb-block-filters/) |
| PHP 7.2/7.3 Dropped | [Dropping support for PHP 7.2 and 7.3](https://make.wordpress.org/core/2026/01/09/dropping-support-for-php-7-2-and-7-3/) |
| Gutenberg 22.7 | [What's new in Gutenberg 22.7 (11 March)](https://make.wordpress.org/core/2026/03/11/whats-new-in-gutenberg-22-7-11-march/) |
| Gutenberg 22.6 | [What's new in Gutenberg 22.6 (25 February)](https://make.wordpress.org/core/2026/02/25/whats-new-in-gutenberg-22-6-25-february/) |

---

## Impact on Flavor Agent

### Must Address

| Issue | Description | Severity |
|-------|-------------|----------|
| `supports.contentRole` not checked | Plugin only checks `role: "content"` on attributes, misses block-level `supports.contentRole: true`. Affects 8 container blocks in content-only patterns. | Medium |
| `__experimentalRole` fallback | Plugin only checks `role`, not `__experimentalRole`. Affects unmigrated third-party blocks. | Low |
| Aspect ratio / height exclusivity | LLM prompt doesn't include the mutual exclusivity constraint. Could suggest conflicting dimension values. | Low |

### Should Address (v1.x)

| Opportunity | Description |
|-------------|-------------|
| Connectors API for Anthropic credentials | Replace custom `flavor_agent_api_key` with core's `connectors_ai_anthropic_api_key`. Eliminates duplicate credential management. |
| WP AI Client for block recommendations | Replace `LLM\Client.php` (direct Anthropic HTTP) with `WP_AI_Client_Prompt_Builder`. Makes the plugin provider-agnostic. |
| Block bindings context for LLM | Include `metadata.bindings` in block context so LLM can reason about bound vs. free attributes. |
| Navigation overlay support | Implement stubbed `recommend-navigation` using the now-stable `navigation-overlay` template-part area. |

### No Action Needed

| Item | Why |
|------|-----|
| ContentOnly as default for patterns | Plugin already reads runtime `getBlockEditingMode()` |
| `disableContentOnlyForUnsyncedPatterns` | Plugin reads mode result, not settings flag |
| Editor iframe changes (punted to 7.1) | Plugin uses iframe-safe patterns already |
| New typography supports (`fitText`, `textIndent`) | Auto-detected by `resolveInspectorPanels()` |
| All three `__experimental*` pattern APIs | Still the correct and only way to access these features |
| `PluginDocumentSettingPanel` location | Already imports from `@wordpress/editor` |
| `setIsInserterOpened` location | Already dispatches on `core/editor` |
