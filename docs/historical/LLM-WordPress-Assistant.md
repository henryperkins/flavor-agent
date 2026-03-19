> **SUPERSEDED** - This document describes an early design (Dispatcher/Generator/Transformer/Executor, REST-only approval flow) that was never implemented. The project evolved to an Abilities API-native, dual-backend architecture. See `STATUS.md` for the current inventory. Kept for historical reference only.

---

# Gutenberg block editor APIs for LLM-powered Inspector integration

**WordPress provides a complete, slot-based architecture for injecting AI controls into the native Inspector sidebar.** The `InspectorControls` component's `group` prop maps directly to 12+ named SlotFill pairs across three fixed tabs (Settings, Appearance, List View), while the `core/block-editor` and `core/blocks` data stores expose every block's attributes, supports, styles, and theme design tokens at runtime. Together, these APIs give an LLM recommendation engine everything it needs: full schema introspection, real-time block state, theme context, and clean injection points into the native UI. Here is the complete reference.

---

## Inspector Controls injection via the `group` prop

The `InspectorControls` component from `@wordpress/block-editor` uses internally created SlotFill pairs — one per group value. The `group` prop (stabilized in WordPress 6.2, previously `__experimentalGroup`) determines which Inspector sidebar section receives your controls.

### All available group values

|`group` value|Tab|Purpose|
|---|---|---|
|_(none)_ / `"default"` / `"settings"`|**Settings**|Default slot for block configuration controls|
|`"advanced"`|**Settings**|The "Advanced" accordion (HTML anchor, CSS classes)|
|`"position"`|**Settings**|Position controls (sticky, fixed)|
|`"bindings"`|**Settings**|Block Bindings controls (WP 6.5+)|
|`"styles"`|**Appearance**|General style controls outside support panels|
|`"color"`|**Appearance**|Injected into the Color ToolsPanel|
|`"typography"`|**Appearance**|Injected into the Typography ToolsPanel|
|`"dimensions"`|**Appearance**|Injected into the Dimensions ToolsPanel|
|`"border"`|**Appearance**|Injected into the Border ToolsPanel|
|`"background"`|**Appearance**|Background image/size controls|
|`"filter"`|**Appearance**|Filter controls (duotone)|
|`"list"`|**List View**|Child item management (Navigation block)|

### Three-tab layout rules (WordPress 6.2+)

The sidebar splits into **Settings** (gear icon), **Appearance** (paintbrush icon), and **List View** (list icon) tabs. A tab renders only if it contains items. If only a single tab would exist, tabs disappear entirely and controls render flat. Tab order is fixed and cannot be reordered. You can disable tabs per-block via the `block_editor_settings_all` PHP filter:

```php
add_filter( 'block_editor_settings_all', function( $settings ) {
    $settings['blockInspectorTabs'] = array_merge(
        $settings['blockInspectorTabs'] ?? [],
        [ 'my-plugin/my-block' => false ]  // disable tabs for this block
    );
    return $settings;
} );
```

### Injecting into each tab — code examples

**Settings tab (default):**

```jsx
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
    return (
        <>
            <InspectorControls>
                <PanelBody title="AI Recommendations">
                    <TextControl
                        label="Suggested heading"
                        value={ attributes.label }
                        onChange={ ( val ) => setAttributes( { label: val } ) }
                    />
                </PanelBody>
            </InspectorControls>
            {/* block content */}
        </>
    );
}
```

**Appearance tab — general styles area:**

```jsx
<InspectorControls group="styles">
    <PanelBody title="AI Style Suggestions">
        <SelectControl
            label="Layout style"
            value={ attributes.layoutStyle }
            options={ [
                { label: 'Default', value: 'default' },
                { label: 'Card', value: 'card' },
            ] }
            onChange={ ( val ) => setAttributes( { layoutStyle: val } ) }
        />
    </PanelBody>
</InspectorControls>
```

**Appearance tab — inside support sub-panels (color, typography, dimensions, border):**

Controls injected into `color`, `typography`, `dimensions`, and `border` groups render inside CSS Grid layouts. Use `grid-column: 1 / -1` for full width. For UI consistency, wrap controls in `ToolsPanelItem`:

```jsx
import { InspectorControls } from '@wordpress/block-editor';
import {
    __experimentalToolsPanelItem as ToolsPanelItem,
    __experimentalUnitControl as UnitControl,
} from '@wordpress/components';

<InspectorControls group="dimensions">
    <ToolsPanelItem
        hasValue={ () => !! attributes.aiWidth }
        label="AI-suggested width"
        onDeselect={ () => setAttributes( { aiWidth: undefined } ) }
        isShownByDefault
        panelId={ clientId }
    >
        <UnitControl
            label="Width"
            value={ attributes.aiWidth || '' }
            onChange={ ( val ) => setAttributes( { aiWidth: val } ) }
        />
    </ToolsPanelItem>
</InspectorControls>
```

**Advanced panel (two equivalent methods):**

```jsx
// Method 1: group prop
<InspectorControls group="advanced">
    <TextControl label="Custom data attribute" value={ attributes.customData }
        onChange={ ( val ) => setAttributes( { customData: val } ) } />
</InspectorControls>

// Method 2: dedicated component
import { InspectorAdvancedControls } from '@wordpress/block-editor';
<InspectorAdvancedControls>
    <TextControl label="HTML anchor" value={ attributes.anchor }
        onChange={ ( val ) => setAttributes( { anchor: val } ) } />
</InspectorAdvancedControls>
```

### Extending core blocks via the `editor.BlockEdit` filter

This is the recommended pattern for an LLM recommendation plugin that adds controls to blocks it does not own:

```jsx
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';

const withAIRecommendations = createHigherOrderComponent( ( BlockEdit ) => {
    return ( props ) => {
        // Optionally restrict to specific blocks:
        // if ( ! [ 'core/paragraph', 'core/heading' ].includes( props.name ) ) {
        //     return <BlockEdit { ...props } />;
        // }
        return (
            <>
                <BlockEdit { ...props } />
                <InspectorControls group="styles">
                    <PanelBody title="AI Style Suggestions">
                        {/* Render LLM-powered controls here */}
                    </PanelBody>
                </InspectorControls>
                <InspectorControls>
                    <PanelBody title="AI Settings">
                        {/* Settings-tab controls here */}
                    </PanelBody>
                </InspectorControls>
            </>
        );
    };
}, 'withAIRecommendations' );

addFilter(
    'editor.BlockEdit',
    'my-plugin/ai-recommendations',
    withAIRecommendations
);
```

**There is no API for registering custom Inspector tabs.** The three tabs are fixed. You can only inject into existing groups within existing tabs. Custom sub-extensibility within your own block can use `createSlotFill` from `@wordpress/components` to expose extension points.

---

## Block supports and capabilities — full runtime introspection

The `core/blocks` data store provides three selectors for reading block type capabilities at runtime. The **`getBlockType(name)`** selector returns the complete registration object including `supports`, `attributes`, `styles`, `variations`, and all metadata.

### Reading the full supports object

```js
import { store as blocksStore } from '@wordpress/blocks';
import { useSelect } from '@wordpress/data';

function useBlockCapabilities( blockName ) {
    return useSelect( ( select ) => {
        const store = select( blocksStore );
        return {
            type: store.getBlockType( blockName ),
            styles: store.getBlockStyles( blockName ),
            supportsColor: store.hasBlockSupport( blockName, 'color' ),
            colorDetails: store.getBlockSupport( blockName, 'color' ),
        };
    }, [ blockName ] );
}

// Console usage (no build step):
const type = wp.data.select( 'core/blocks' ).getBlockType( 'core/group' );
console.log( type.supports );
```

### Complete `supports` schema reference

The `supports` object in block.json can contain these keys, all of which are readable via `getBlockType().supports`:

```js
supports: {
    // Alignment
    align: true | false | [ 'left', 'center', 'right', 'wide', 'full' ],
    alignWide: true,

    // Anchors and IDs
    anchor: false,
    ariaLabel: false,

    // Class names
    className: true,
    customClassName: true,

    // Color (maps to Color panel in Appearance tab)
    color: {
        background: true,
        text: true,
        link: false,
        heading: false,        // WP 6.5+
        button: false,         // WP 6.5+
        gradients: false,
        enableContrastChecker: true,
    },

    // Background (WP 6.5+ — maps to Background panel)
    background: {
        backgroundImage: false,
        backgroundSize: false,
    },

    // Typography (maps to Typography panel)
    typography: {
        fontSize: false,
        lineHeight: false,
        textAlign: false,
    },

    // Spacing (maps to Dimensions panel)
    spacing: {
        margin: false | true | [ 'top', 'bottom', 'left', 'right' ],
        padding: false | true | [ 'top', 'bottom', 'left', 'right' ],
        blockGap: false | true | 'horizontal' | 'vertical',
    },

    // Border (maps to Border panel)
    border: {
        color: false,
        radius: false,
        style: false,
        width: false,
    },

    // Dimensions (maps to Dimensions panel, WP 6.2+)
    dimensions: {
        aspectRatio: false,     // WP 6.5+
        height: false,
        minHeight: false,
        width: false,
    },

    // Position (maps to Position controls in Settings tab)
    position: { sticky: false, fixed: false },

    // Shadow (maps to Appearance tab, WP 6.5+)
    shadow: false,

    // Filter
    filter: { duotone: false },

    // Layout
    layout: false | true | {
        default: { type: 'flex' | 'flow' | 'constrained' | 'grid' },
        allowSwitching: false,
        allowEditing: true,
        allowInheriting: true,
        allowSizingOnChildren: false,
    },

    // Interactivity API
    interactivity: false | true | { clientNavigation: false, interactive: false },

    // HTML editing, inserter, reusable, locking
    html: true,
    inserter: true,
    reusable: true,
    lock: true,
    multiple: true,
    renaming: true,      // WP 6.5+
    splitting: false,
}
```

### How supports map to Inspector panels

When a block declares support for a feature, WordPress **automatically registers the needed attributes** (`backgroundColor`, `textColor`, `style`, `gradient`, `align`, etc.), renders the corresponding UI controls, and applies generated classes/styles via `useBlockProps()`. The mapping from support key to Inspector panel is direct: `color.*` → Color panel, `typography.*` → Typography panel, `spacing.*` → Dimensions panel, `border.*` → Border panel, `dimensions.*` → Dimensions panel, `shadow` → shadow picker in Appearance, `anchor` → Advanced panel HTML anchor field.

---

## Block attributes schema — full runtime access

Every attribute defined in block.json or `registerBlockType()` is available on the block type object at `blockType.attributes`. Each attribute definition has these possible properties:

|Property|Description|
|---|---|
|`type`|`string`, `number`, `integer`, `boolean`, `object`, `array`, `null`|
|`enum`|Array of allowed values|
|`default`|Default value matching the type|
|`source`|Where value is extracted: `attribute`, `text`, `html`, `query`, or omitted (JSON in comment delimiter)|
|`selector`|CSS selector targeting element(s) within block markup|
|`attribute`|HTML attribute name (used with `source: 'attribute'`)|
|`query`|Nested attribute definitions (used with `source: 'query'`)|
|`role`|**`"content"`** (editable in content-only mode) or **`"local"`** (non-persistable, WP 6.8+)|

### Reading attributes for any block type

```js
const blockType = wp.data.select( 'core/blocks' ).getBlockType( 'core/image' );
Object.entries( blockType.attributes ).forEach( ( [ name, def ] ) => {
    console.log( name, {
        type: def.type,
        source: def.source || 'comment delimiter',
        selector: def.selector,
        default: def.default,
        role: def.role,
        enum: def.enum,
    } );
} );
```

The `role: "content"` property is critical for LLM integration. It marks attributes as user-editable content, used by content-locking (`templateLock: "contentOnly"`). **`getBlockAttributesNamesByRole`** (stabilized in WP 6.7, was `__experimentalGetBlockAttributesNamesByRole`) returns attribute names filtered by role.

### `selectors` in block.json → CSS output mapping

The `selectors` property in block.json controls which CSS selectors are used when generating Global Styles CSS (not per-block inline styles). It supports three nesting levels: root → feature → subfeature, with a fallback chain from most to least specific:

```json
{
    "selectors": {
        "root": ".wp-block-my-notice",
        "color": ".wp-block-my-notice",
        "typography": {
            "root": ".wp-block-my-notice > h2",
            "text-decoration": ".wp-block-my-notice > h2 span"
        },
        "border": ".wp-block-my-notice .inner",
        "filter": { "duotone": ".wp-block-my-notice img" }
    }
}
```

---

## Theme.json design tokens and global styles access in JavaScript

### `useSettings()` — the current public API (WordPress 6.5+)

The `useSetting()` hook (singular) was **deprecated in WordPress 6.5**. The replacement is `useSettings()` (plural) from `@wordpress/block-editor`, which accepts multiple paths and returns an array:

```js
import { useSettings } from '@wordpress/block-editor';

function MyBlockEdit() {
    // Single value
    const [ colorPalette ] = useSettings( 'color.palette' );

    // Multiple values at once
    const [ fontSizes, fontFamilies, spacingSizes, shadowPresets ] = useSettings(
        'typography.fontSizes',
        'typography.fontFamilies',
        'spacing.spacingSizes',
        'shadow.presets'
    );

    // Boolean toggles
    const [ hasDropCap ] = useSettings( 'typography.dropCap' );
    const [ hasCustomColors ] = useSettings( 'color.custom' );

    // Layout
    const [ layout ] = useSettings( 'layout' );
    // layout.contentSize, layout.wideSize

    // Custom CSS properties (from settings.custom in theme.json)
    const [ customLineHeight ] = useSettings( 'custom.lineHeight.body' );

    // Gradients and duotone
    const [ gradients ] = useSettings( 'color.gradients' );
    const [ duotone ] = useSettings( 'color.duotone' );
}
```

The hook resolves through a specificity hierarchy: **user customizations → block-level settings → global theme.json settings → WordPress core defaults**. It is context-aware — called inside a `core/paragraph` edit component, it returns paragraph-specific overrides if they exist in theme.json under `settings.blocks["core/paragraph"]`.

### `getSettings()` from the `core/block-editor` store

For non-component contexts or when you need the raw settings object, use `getSettings()`. It returns both legacy flat properties and the full theme.json tree under `__experimentalFeatures`:

```js
const settings = wp.data.select( 'core/block-editor' ).getSettings();

// Legacy flat arrays (merged, effective values)
settings.colors;     // [{ name, slug, color }]
settings.fontSizes;  // [{ name, slug, size }]
settings.gradients;  // [{ name, slug, gradient }]

// Full theme.json tree with origin separation
settings.__experimentalFeatures.color.palette.default;  // Core colors
settings.__experimentalFeatures.color.palette.theme;    // Theme colors
settings.__experimentalFeatures.color.palette.custom;   // User customizations

settings.__experimentalFeatures.typography.fontFamilies.theme;
settings.__experimentalFeatures.spacing.spacingSizes.theme;
settings.__experimentalFeatures.shadow.presets.theme;
```

### Mapping from theme.json paths to JS access

|theme.json path|`useSettings()` path|`getSettings()` path|
|---|---|---|
|`settings.color.palette`|`'color.palette'`|`__experimentalFeatures.color.palette`|
|`settings.color.gradients`|`'color.gradients'`|`__experimentalFeatures.color.gradients`|
|`settings.typography.fontFamilies`|`'typography.fontFamilies'`|`__experimentalFeatures.typography.fontFamilies`|
|`settings.typography.fontSizes`|`'typography.fontSizes'`|`__experimentalFeatures.typography.fontSizes`|
|`settings.spacing.spacingSizes`|`'spacing.spacingSizes'`|`__experimentalFeatures.spacing.spacingSizes`|
|`settings.shadow.presets`|`'shadow.presets'`|`__experimentalFeatures.shadow.presets`|
|`settings.layout`|`'layout'`|`__experimentalFeatures.layout`|
|`settings.custom.*`|`'custom.*'`|`__experimentalFeatures.custom.*`|

### Differentiating theme defaults vs. user customizations

**Origin-separated presets** in `__experimentalFeatures` store values by origin (`default`, `theme`, `custom`). The `custom` origin contains user-made changes via the Global Styles UI. For full separation, compare the Global Styles entity record against theme data:

```js
import { useSelect } from '@wordpress/data';

const userStyles = useSelect( ( select ) => {
    const globalStylesId = select( 'core/edit-site' )
        ?.__experimentalGetCurrentGlobalStylesId?.();
    if ( ! globalStylesId ) return null;
    return select( 'core' ).getEditedEntityRecord(
        'root', 'globalStyles', globalStylesId
    );
}, [] );
// userStyles.settings = user-customized settings only
// userStyles.styles = user-customized styles only
```

On the server side, `wp_get_global_settings( [], [ 'origin' => 'theme' ] )` returns theme-only values without user customizations.

### Client-side filter: `blockEditor.useSetting.before`

This hook (WP 6.2+) lets your LLM plugin intercept settings before they reach blocks — useful for dynamically restricting or expanding options based on AI recommendations:

```js
import { addFilter } from '@wordpress/hooks';
import { select } from '@wordpress/data';

addFilter(
    'blockEditor.useSetting.before',
    'my-plugin/ai-setting-filter',
    ( settingValue, settingName, clientId, blockName ) => {
        if ( blockName === 'core/column' && settingName === 'spacing.units' ) {
            return [ 'px', 'rem' ];  // AI restricts to px and rem
        }
        return settingValue;
    }
);
```

### Private Global Styles hooks (`useGlobalSetting`, `useGlobalStyle`)

These hooks exist but are **locked behind private APIs** (`wp.blockEditor.experiments`) and are not part of the public API. There is an open GitHub issue (#63796) requesting they be made public. For production plugins, use `useSettings()` for reading and the entity API for writing.

---

## Block style variations

### Registering styles (PHP and JS)

```php
// PHP — register_block_style() in an init hook
register_block_style( 'core/image', [
    'name'       => 'rounded-shadow',
    'label'      => 'Rounded Shadow',
    'style_data' => [  // WP 6.6+ — theme.json-like, editable in Global Styles
        'border' => [ 'radius' => '12px' ],
        'shadow' => '0 4px 6px rgba(0,0,0,0.1)',
    ],
] );
```

```js
// JavaScript — registerBlockStyle()
import { registerBlockStyle } from '@wordpress/blocks';

registerBlockStyle( 'core/image', {
    name: 'rounded-shadow',
    label: 'Rounded Shadow',
    isDefault: false,
} );
```

### Reading registered styles at runtime

```js
import { store as blocksStore } from '@wordpress/blocks';
import { useSelect } from '@wordpress/data';

const styles = useSelect(
    ( select ) => select( blocksStore ).getBlockStyles( 'core/button' ),
    []
);
// => [{ name: 'fill', label: 'Fill', isDefault: true },
//     { name: 'outline', label: 'Outline' }]

// Console:
wp.data.select( 'core/blocks' ).getBlockStyles( 'core/button' );
```

Selecting a style adds `is-style-{name}` to the block's wrapper class. Only one style can be active per block instance. Styles appear in the **Appearance tab** as visual thumbnail buttons.

---

## Block editor data stores — complete selector reference

### `core/block-editor` — block tree state

This is the primary store for reading block structure, selection, attributes, and editor settings. It works in any block editor context (post, site, widgets).

**Reading the selected block and its context:**

```js
import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

function useBlockContext( clientId ) {
    return useSelect( ( select ) => {
        const s = select( blockEditorStore );
        return {
            attributes:   s.getBlockAttributes( clientId ),
            blockName:    s.getBlockName( clientId ),
            parentIds:    s.getBlockParents( clientId ),
            rootId:       s.getBlockRootClientId( clientId ),
            index:        s.getBlockIndex( clientId ),
            childOrder:   s.getBlockOrder( clientId ),
            childCount:   s.getBlockCount( clientId ),
            editingMode:  s.getBlockEditingMode( clientId ),
            isSelected:   s.isBlockSelected( clientId ),
            listSettings: s.getBlockListSettings( clientId ),
        };
    }, [ clientId ] );
}
```

**Reading the full block tree:**

```js
// All top-level blocks with full innerBlocks recursion
const blocks = wp.data.select( 'core/block-editor' ).getBlocks();

// Children of a specific container
const children = wp.data.select( 'core/block-editor' ).getBlocks( rootClientId );

// Single block with recursive innerBlocks
const block = wp.data.select( 'core/block-editor' ).getBlock( clientId );

// All client IDs matching a block name (including nested)
const allParagraphs = wp.data.select( 'core/block-editor' )
    .getBlocksByName( 'core/paragraph' );
```

**Key selectors quick reference:**

|Selector|Returns|
|---|---|
|`getSelectedBlock()`|Currently selected block object, or null|
|`getSelectedBlockClientId()`|Selected block's client ID|
|`getBlocks( rootClientId? )`|Block objects array (top-level or children)|
|`getBlock( clientId )`|Single block with recursive `innerBlocks`|
|`getBlockAttributes( clientId )`|Attributes object for a block|
|`getBlockName( clientId )`|Block name string (e.g., `'core/paragraph'`)|
|`getBlockParents( clientId, ascending? )`|Parent client ID chain|
|`getBlockParentsByBlockName( clientId, name )`|Parents filtered by block name|
|`getBlockRootClientId( clientId )`|Immediate parent's client ID|
|`getBlockOrder( rootClientId? )`|Ordered array of child client IDs|
|`getBlockCount( rootClientId? )`|Number of blocks|
|`getBlockIndex( clientId )`|Position within parent|
|`getBlocksByClientId( ids )`|Block objects for an array of client IDs|
|`getBlocksByName( name )`|All client IDs matching a block name|
|`getGlobalBlockCount( name? )`|Total blocks including nested|
|`getClientIdsOfDescendants( rootIds )`|All descendant client IDs|
|`getMultiSelectedBlockClientIds()`|Multi-selection set|
|`getSettings()`|Editor settings (colors, fonts, layout, etc.)|
|`getBlockEditingMode( clientId )`|`'default'`, `'contentOnly'`, or `'disabled'`|
|`getTemplateLock( rootClientId? )`|`'all'`, `'insert'`, `'contentOnly'`, or `false`|
|`canInsertBlockType( name, rootClientId? )`|Whether insertion is allowed|
|`getBlockListSettings( clientId )`|InnerBlocks settings (allowed blocks, orientation)|

### `core/blocks` — block type registry

|Selector|Returns|
|---|---|
|`getBlockType( name )`|Full registration object (supports, attributes, etc.)|
|`getBlockTypes()`|All registered block types|
|`getBlockStyles( name )`|Registered style variations|
|`getBlockSupport( name, feature )`|Value of a specific support|
|`hasBlockSupport( name, feature )`|Boolean check|
|`getBlockVariations( name, scope? )`|Block variations|
|`getCategories()`|All block categories|
|`getChildBlockNames( name )`|Allowed child block names|

### Store hierarchy

```
core/block-editor  — Block tree: structure, attributes, selection, settings
core/blocks        — Block type registry: definitions, supports, variations, styles
core/editor        — Post/entity editing: save, publish, post meta, editing mode
core/edit-post     — Post editor UI chrome (many selectors migrated to core/editor)
core/edit-site     — Site editor UI chrome
core               — REST API entities: getEntityRecord, getEditedEntityRecord
core/preferences   — User preferences (fixedToolbar, distractionFree)
```

Since **WP 6.5**, `core/editor` works in both the post editor and site editor. Many selectors from `core/edit-post` have migrated there. For block tree operations, always prefer `core/block-editor` directly.

---

## WordPress 6.8 and 7.0 API changes

### WordPress 6.8 (April 2025)

**Stabilized APIs:** `isPreviewMode` settings flag (replaces `__unstableIsPreviewMode`), `LinkControl` component (replaces `__experimentalLinkControl`). Both experimental versions still work but log deprecation warnings.

**New block supports across core blocks:** Border support added to Archives, Categories, Latest Comments, Latest Posts, Page List, RSS, Tag Cloud. Color and spacing support expanded on Content, Page List, and RSS blocks.

**Block Hooks extended** to work with post content and synced patterns. **Query Total block** added for displaying total posts in query loops. **`:focus-visible` pseudo-class** support added in theme.json styles.

**Deprecations:** Navigation component (and subcomponents) deprecated with removal planned for WP 7.1 — use Navigator component instead. RadioGroup and ButtonGroup components deprecated.

### WordPress 7.0 (April 2026 — current release cycle)

WordPress 7.0 launches **Gutenberg Phase 3: Collaboration**, with real-time co-editing via HTTP polling, inline block commenting with @ mentions, and visual revision comparison.

**Critical change: the post editor always runs as an iframe** regardless of block `apiVersion`. The non-iframe fallback from WP 6.3 is fully removed. All blocks must function correctly inside an iframe — references to `document` and `window` should use `element.ownerDocument` and `element.ownerDocument.defaultView` instead:

```js
import { useBlockProps } from '@wordpress/block-editor';
import { useRefEffect } from '@wordpress/element';

export default function Edit() {
    const ref = useRefEffect( ( element ) => {
        const { ownerDocument } = element;
        const { defaultView } = ownerDocument;
        // Use defaultView instead of window
        defaultView.addEventListener( 'resize', handler );
        return () => defaultView.removeEventListener( 'resize', handler );
    }, [] );
    return <div { ...useBlockProps( { ref } ) }>Content</div>;
}
```

**New infrastructure:** `WP_Block_Processor` for streaming block parsing, Abilities API (introduced in 6.9) for AI integration capability registry, DataViews replacing WP List Tables, view transitions for smooth navigation, and PHP 7.4 minimum requirement.

### Block API version 3 (WordPress 6.3+)

Version 3 enables iframe support. The key change from version 2: the editor content renders in an isolated iframe, meaning admin styles don't affect content and viewport-relative units work correctly. **WP 6.9 began logging console warnings** for blocks with apiVersion 2 or lower. WP 7.0 enforces the iframe for all content.

### theme.json v3 (WordPress 6.6+)

The breaking change: **`defaultFontSizes` and `defaultSpacingSizes` default to `false`** in v3 (they defaulted to `true` in v2). This means WordPress core presets are hidden unless explicitly opted in. Theme presets with the same slugs as defaults no longer automatically override them — set `defaultFontSizes: false` to reclaim those slugs:

```json
{
    "version": 3,
    "settings": {
        "typography": {
            "defaultFontSizes": false,
            "fontSizes": [
                { "name": "Small", "slug": "small", "size": "10px" },
                { "name": "Large", "slug": "large", "size": "20px" }
            ]
        },
        "spacing": { "defaultSpacingSizes": false }
    }
}
```

### Pseudo-class support in theme.json

Supported on `elements` (link, button): `:hover`, `:focus`, `:active`, `:visited`, `:link`, `:any-link`, and **`:focus-visible`** (WP 6.8+). These work on elements, **not** directly on block-level styles — use `styles.elements.link.:hover`, not `styles.blocks.core/button.:hover`. An open GitHub issue (#49716) tracks block-level `:hover` support.

```json
{
    "styles": {
        "elements": {
            "link": {
                "color": { "text": "#0000ff" },
                ":hover": { "color": { "text": "#ff0000" } },
                ":focus-visible": {
                    "outline": { "width": "2px", "style": "dashed", "color": "green" }
                }
            }
        }
    }
}
```

---

## Conclusion

An LLM-powered recommendation engine has clean, well-defined integration points across the entire Gutenberg stack. The **`editor.BlockEdit` filter** combined with **`InspectorControls group="..."`** lets you inject AI controls into any of the 12+ named slots across the three fixed Inspector tabs without breaking native UI. Full block introspection is available through `getBlockType()` for supports and attributes schema, `getBlockStyles()` for style variations, and `getBlockAttributes()` for runtime state. Theme context flows through `useSettings()` for design tokens and `getSettings().__experimentalFeatures` for origin-separated presets. The most important architectural constraint to note: **custom Inspector tabs cannot be created** — you must work within the existing Settings/Appearance/List View structure. For WordPress 7.0 compatibility, ensure all code handles iframe isolation (use `ownerDocument.defaultView` instead of `window`), declare `apiVersion: 3` in block.json, and target theme.json v3's changed default-preset behavior.

---

## **`block-inspector.js`**
````javascript
/**
 * Block Introspector
 *
 * Recursively analyzes a block tree and produces a capability manifest
 * for every block — its supports, attributes schema, registered styles,
 * variations, current attribute values, and which Inspector panels it
 * exposes. This manifest is what the LLM uses to make specific,
 * actionable recommendations per Inspector tab.
 *
 * The full recursive tree is built internally; a summarized version
 * is what gets sent to the LLM to stay within token budgets.
 */
import { select } from '@wordpress/data';
import { store as blocksStore } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';

// ── Supports → Inspector panel mapping ──────────────────────

const SUPPORT_TO_PANEL = {
	'color.background':   'color',
	'color.text':         'color',
	'color.link':         'color',
	'color.heading':      'color',
	'color.button':       'color',
	'color.gradients':    'color',
	'typography.fontSize':   'typography',
	'typography.lineHeight': 'typography',
	'typography.textAlign':  'typography',
	'spacing.margin':     'dimensions',
	'spacing.padding':    'dimensions',
	'spacing.blockGap':   'dimensions',
	'dimensions.aspectRatio': 'dimensions',
	'dimensions.minHeight':   'dimensions',
	'dimensions.height':      'dimensions',
	'dimensions.width':       'dimensions',
	'border.color':  'border',
	'border.radius': 'border',
	'border.style':  'border',
	'border.width':  'border',
	'shadow':        'shadow',
	'filter.duotone': 'filter',
	'background.backgroundImage': 'background',
	'background.backgroundSize':  'background',
	'position.sticky': 'position',
	'position.fixed':  'position',
	'layout': 'layout',
	'anchor': 'advanced',
};

/**
 * Flatten a nested supports object into dot-path → value entries.
 *
 * e.g. { color: { background: true, text: true } }
 *   → [ ['color.background', true], ['color.text', true] ]
 */
function flattenSupports( obj, prefix = '' ) {
	const entries = [];
	if ( obj == null || typeof obj !== 'object' ) return entries;

	for ( const [ key, val ] of Object.entries( obj ) ) {
		const path = prefix ? `${ prefix }.${ key }` : key;

		if ( typeof val === 'boolean' || typeof val === 'string' || Array.isArray( val ) ) {
			entries.push( [ path, val ] );
		} else if ( val === true ) {
			entries.push( [ path, true ] );
		} else if ( typeof val === 'object' && val !== null ) {
			// Recurse into nested supports objects
			entries.push( ...flattenSupports( val, path ) );
		}
	}
	return entries;
}

/**
 * Determine which Inspector panels a block exposes based on supports.
 *
 * @param {object} supports  Block supports object from getBlockType().
 * @return {object} Map of panel name → list of enabled feature paths.
 */
export function resolveInspectorPanels( supports ) {
	const panels = {};
	const flat = flattenSupports( supports );

	for ( const [ path, value ] of flat ) {
		// Find the best matching panel for this support path.
		const panelKey = SUPPORT_TO_PANEL[ path ];
		if ( panelKey && isTruthy( value ) ) {
			if ( ! panels[ panelKey ] ) panels[ panelKey ] = [];
			panels[ panelKey ].push( path );
		}
	}

	return panels;
}

function isTruthy( val ) {
	if ( val === true ) return true;
	if ( val === false || val == null ) return false;
	if ( Array.isArray( val ) ) return val.length > 0;
	if ( typeof val === 'object' ) return Object.keys( val ).length > 0;
	return !! val;
}

/**
 * Introspect a single block type by name.
 *
 * @param {string} blockName  e.g. 'core/heading'
 * @return {object|null} Block capability manifest.
 */
export function introspectBlockType( blockName ) {
	const store = select( blocksStore );
	const blockType = store.getBlockType( blockName );
	if ( ! blockType ) return null;

	const supports = blockType.supports || {};
	const attributes = blockType.attributes || {};
	const styles = store.getBlockStyles( blockName ) || [];
	const variations = store.getBlockVariations( blockName, 'block' ) || [];

	// Separate content-role attributes from configuration attributes.
	const contentAttrs = {};
	const configAttrs = {};
	for ( const [ name, def ] of Object.entries( attributes ) ) {
		const entry = {
			type: def.type,
			default: def.default,
			role: def.role,
		};
		if ( def.enum ) entry.enum = def.enum;
		if ( def.source ) entry.source = def.source;

		if ( def.role === 'content' ) {
			contentAttrs[ name ] = entry;
		} else {
			configAttrs[ name ] = entry;
		}
	}

	return {
		name: blockName,
		title: blockType.title,
		category: blockType.category,
		description: blockType.description,
		supports,
		inspectorPanels: resolveInspectorPanels( supports ),
		contentAttributes: contentAttrs,
		configAttributes: configAttrs,
		styles: styles.map( ( s ) => ( {
			name: s.name,
			label: s.label,
			isDefault: s.isDefault || false,
		} ) ),
		variations: variations.map( ( v ) => ( {
			name: v.name,
			title: v.title,
			description: v.description,
			scope: v.scope,
		} ) ),
		parent: blockType.parent || null,
		allowedBlocks: blockType.allowedBlocks || null,
		apiVersion: blockType.apiVersion || 1,
	};
}

/**
 * Introspect a live block instance — combines type capabilities with
 * current attribute values from the editor.
 *
 * @param {string} clientId  Block client ID in the editor.
 * @return {object|null}
 */
export function introspectBlockInstance( clientId ) {
	const editor = select( blockEditorStore );
	const blockName = editor.getBlockName( clientId );
	if ( ! blockName ) return null;

	const typeMeta = introspectBlockType( blockName );
	if ( ! typeMeta ) return null;

	const currentAttrs = editor.getBlockAttributes( clientId );
	const editingMode = editor.getBlockEditingMode( clientId );
	const parentIds = editor.getBlockParents( clientId );
	const childCount = editor.getBlockCount( clientId );

	return {
		...typeMeta,
		clientId,
		currentAttributes: currentAttrs,
		editingMode,
		parentChain: parentIds,
		childCount,
		activeStyle: currentAttrs?.className
			? extractActiveStyle( currentAttrs.className, typeMeta.styles )
			: null,
	};
}

function extractActiveStyle( className, registeredStyles ) {
	if ( ! className ) return null;
	for ( const style of registeredStyles ) {
		if ( className.includes( `is-style-${ style.name }` ) ) {
			return style.name;
		}
	}
	return null;
}

/**
 * Recursively introspect an entire block tree from a root.
 *
 * @param {string|null} rootClientId  Root block ID, or null for the post root.
 * @param {number}      maxDepth      Safety limit.
 * @return {Array} Array of introspected block nodes with children.
 */
export function introspectBlockTree( rootClientId = null, maxDepth = 10 ) {
	if ( maxDepth <= 0 ) return [];

	const editor = select( blockEditorStore );
	const childIds = editor.getBlockOrder( rootClientId || '' );

	return childIds.map( ( clientId ) => {
		const instance = introspectBlockInstance( clientId );
		if ( ! instance ) return null;

		const children = introspectBlockTree( clientId, maxDepth - 1 );

		return {
			...instance,
			innerBlocks: children.filter( Boolean ),
		};
	} ).filter( Boolean );
}

/**
 * Summarize a full introspected tree for the LLM prompt.
 *
 * Produces a compact representation: block name, key attributes,
 * available panels, active style, and child structure — without
 * the full attribute schema that would blow up the token budget.
 *
 * @param {Array} tree  Output from introspectBlockTree().
 * @return {Array} Summarized tree.
 */
export function summarizeTree( tree ) {
	return tree.map( ( node ) => {
		const summary = {
			block: node.name,
			title: node.title,
		};

		// Include meaningful current attribute values, skip internals.
		const meaningful = pickMeaningfulAttributes( node.currentAttributes, node.name );
		if ( Object.keys( meaningful ).length ) {
			summary.currentValues = meaningful;
		}

		// Which Inspector panels are available.
		const panels = Object.keys( node.inspectorPanels );
		if ( panels.length ) {
			summary.availablePanels = panels;
		}

		// Active style variation.
		if ( node.activeStyle ) {
			summary.activeStyle = node.activeStyle;
		}

		// Available style variations.
		if ( node.styles.length > 1 ) {
			summary.styleOptions = node.styles.map( ( s ) => s.name );
		}

		// Editing constraints.
		if ( node.editingMode !== 'default' ) {
			summary.editingMode = node.editingMode;
		}

		// Recursive children.
		if ( node.innerBlocks.length ) {
			summary.children = summarizeTree( node.innerBlocks );
		}

		return summary;
	} );
}

/**
 * Pick attributes that are meaningful for LLM context.
 * Skip auto-generated IDs, lock states, and empty defaults.
 */
function pickMeaningfulAttributes( attrs, blockName ) {
	if ( ! attrs ) return {};

	const SKIP_KEYS = new Set( [
		'lock', 'metadata', 'className',
	] );

	const result = {};
	for ( const [ key, val ] of Object.entries( attrs ) ) {
		if ( SKIP_KEYS.has( key ) ) continue;
		if ( val === undefined || val === null || val === '' ) continue;
		if ( typeof val === 'object' && Object.keys( val ).length === 0 ) continue;

		result[ key ] = val;
	}
	return result;
}

/**
 * Build a deduplicated block capability index for all unique block types
 * present in a tree. This tells the LLM "these are all the knobs you
 * can turn" without repeating per-instance.
 *
 * @param {Array} tree  Output from introspectBlockTree().
 * @return {object} Map of blockName → capability manifest.
 */
export function buildCapabilityIndex( tree ) {
	const index = {};

	function walk( nodes ) {
		for ( const node of nodes ) {
			if ( ! index[ node.name ] ) {
				index[ node.name ] = {
					title: node.title,
					inspectorPanels: node.inspectorPanels,
					styles: node.styles,
					variations: node.variations.slice( 0, 5 ),
					contentAttributes: Object.keys( node.contentAttributes ),
					configAttributes: Object.keys( node.configAttributes ),
					supportsSummary: Object.keys( node.inspectorPanels ),
				};
			}
			if ( node.innerBlocks.length ) {
				walk( node.innerBlocks );
			}
		}
	}
	walk( tree );
	return index;
}
```
---

## **`theme-tokens.js`**
```javascript
/**
 * Theme Token Collector
 *
 * Reads the complete set of design tokens from the current theme's
 * theme.json, user customizations, and computed editor settings.
 * Produces a structured manifest the LLM uses to suggest specific
 * values — actual color hex codes, font family stacks, spacing
 * scale values, shadow presets, and layout constraints.
 *
 * Uses `getSettings().__experimentalFeatures` for origin-separated
 * presets (default / theme / custom) so the LLM knows what the theme
 * provides vs. what the user has overridden.
 */
import { select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Collect the full design token manifest.
 *
 * @return {object} Token manifest with colors, typography, spacing, etc.
 */
export function collectThemeTokens() {
	const settings = select( blockEditorStore ).getSettings();
	const features = settings.__experimentalFeatures || {};

	return {
		color: collectColorTokens( settings, features ),
		typography: collectTypographyTokens( settings, features ),
		spacing: collectSpacingTokens( features ),
		layout: collectLayoutTokens( features, settings ),
		shadow: collectShadowTokens( features ),
		border: collectBorderTokens( features ),
		background: collectBackgroundTokens( features ),
		elements: collectElementStyles( features ),
	};
}

// ── Color tokens ────────────────────────────────────────────

function collectColorTokens( settings, features ) {
	const paletteFeature = features?.color?.palette || {};

	// Merge origins: default < theme < custom. Theme and custom override.
	const palette = mergeOrigins( paletteFeature );
	const gradients = mergeOrigins( features?.color?.gradients || {} );
	const duotone = mergeOrigins( features?.color?.duotone || {} );

	return {
		palette: palette.map( ( c ) => ( {
			name: c.name,
			slug: c.slug,
			color: c.color,
			cssVar: `var(--wp--preset--color--${ c.slug })`,
		} ) ),
		gradients: gradients.map( ( g ) => ( {
			name: g.name,
			slug: g.slug,
			gradient: g.gradient,
			cssVar: `var(--wp--preset--gradient--${ g.slug })`,
		} ) ),
		duotone: duotone.map( ( d ) => ( {
			name: d.name,
			slug: d.slug,
			colors: d.colors,
		} ) ),
		// Feature toggles
		customColors: features?.color?.custom !== false,
		customGradients: features?.color?.customGradient !== false,
		defaultPalette: features?.color?.defaultPalette !== false,
		backgroundEnabled: features?.color?.background !== false,
		textEnabled: features?.color?.text !== false,
		linkEnabled: features?.color?.link ?? false,
	};
}

// ── Typography tokens ───────────────────────────────────────

function collectTypographyTokens( settings, features ) {
	const fontSizesFeature = features?.typography?.fontSizes || {};
	const fontFamiliesFeature = features?.typography?.fontFamilies || {};

	const fontSizes = mergeOrigins( fontSizesFeature );
	const fontFamilies = mergeOrigins( fontFamiliesFeature );

	return {
		fontSizes: fontSizes.map( ( fs ) => ( {
			name: fs.name,
			slug: fs.slug,
			size: fs.size,
			fluidSize: fs.fluid || null,
			cssVar: `var(--wp--preset--font-size--${ fs.slug })`,
		} ) ),
		fontFamilies: fontFamilies.map( ( ff ) => ( {
			name: ff.name,
			slug: ff.slug,
			fontFamily: ff.fontFamily,
			cssVar: `var(--wp--preset--font-family--${ ff.slug })`,
		} ) ),
		// Feature toggles
		customFontSize: features?.typography?.customFontSize !== false,
		lineHeight: features?.typography?.lineHeight ?? false,
		dropCap: features?.typography?.dropCap ?? true,
		fontStyle: features?.typography?.fontStyle ?? false,
		fontWeight: features?.typography?.fontWeight ?? false,
		letterSpacing: features?.typography?.letterSpacing ?? false,
		textDecoration: features?.typography?.textDecoration ?? false,
		textTransform: features?.typography?.textTransform ?? false,
		writingMode: features?.typography?.writingMode ?? false,
		fluidTypography: features?.typography?.fluid ?? false,
	};
}

// ── Spacing tokens ──────────────────────────────────────────

function collectSpacingTokens( features ) {
	const spacingSizes = mergeOrigins( features?.spacing?.spacingSizes || {} );
	const units = features?.spacing?.units ?? [ 'px', 'em', 'rem', 'vh', 'vw', '%' ];

	return {
		spacingSizes: spacingSizes.map( ( s ) => ( {
			name: s.name,
			slug: s.slug,
			size: s.size,
			cssVar: `var(--wp--preset--spacing--${ s.slug })`,
		} ) ),
		units,
		margin: features?.spacing?.margin ?? false,
		padding: features?.spacing?.padding ?? false,
		blockGap: features?.spacing?.blockGap ?? null,
		customSpacingSize: features?.spacing?.customSpacingSize !== false,
	};
}

// ── Layout tokens ───────────────────────────────────────────

function collectLayoutTokens( features, settings ) {
	const layout = features?.layout || {};

	return {
		contentSize: layout.contentSize || settings?.layout?.contentSize || '',
		wideSize: layout.wideSize || settings?.layout?.wideSize || '',
		allowEditing: layout.allowEditing !== false,
		allowCustomContentAndWideSize:
			layout.allowCustomContentAndWideSize !== false,
	};
}

// ── Shadow tokens ───────────────────────────────────────────

function collectShadowTokens( features ) {
	const presets = mergeOrigins( features?.shadow?.presets || {} );
	const defaultPresets = features?.shadow?.defaultPresets ?? true;

	return {
		presets: presets.map( ( s ) => ( {
			name: s.name,
			slug: s.slug,
			shadow: s.shadow,
			cssVar: `var(--wp--preset--shadow--${ s.slug })`,
		} ) ),
		defaultPresets,
	};
}

// ── Border tokens ───────────────────────────────────────────

function collectBorderTokens( features ) {
	return {
		color: features?.border?.color ?? false,
		radius: features?.border?.radius ?? false,
		style: features?.border?.style ?? false,
		width: features?.border?.width ?? false,
	};
}

// ── Background tokens ───────────────────────────────────────

function collectBackgroundTokens( features ) {
	return {
		backgroundImage: features?.background?.backgroundImage ?? false,
		backgroundSize: features?.background?.backgroundSize ?? false,
	};
}

// ── Element pseudo-class styles (links, buttons, headings) ──

function collectElementStyles( features ) {
	// If global styles are available, extract element-level style hints.
	// This tells the LLM about :hover, :focus, :focus-visible states.
	const styles = features?.styles?.elements || {};
	const result = {};

	for ( const [ element, styleDef ] of Object.entries( styles ) ) {
		result[ element ] = {
			base: styleDef?.color || {},
			hover: styleDef?.[ ':hover' ]?.color || {},
			focus: styleDef?.[ ':focus' ]?.color || {},
			focusVisible: styleDef?.[ ':focus-visible' ] || {},
		};
	}

	return result;
}

// ── Utility: merge origin-separated presets ─────────────────

/**
 * Merge default, theme, and custom origins into a single array.
 * Custom overrides theme which overrides default (by slug).
 */
function mergeOrigins( feature ) {
	const defaultItems = feature?.default || [];
	const themeItems = feature?.theme || [];
	const customItems = feature?.custom || [];

	// If no origin separation, the feature itself might be an array.
	if ( Array.isArray( feature ) ) return feature;

	const bySlug = new Map();

	for ( const item of defaultItems ) {
		bySlug.set( item.slug, { ...item, origin: 'default' } );
	}
	for ( const item of themeItems ) {
		bySlug.set( item.slug, { ...item, origin: 'theme' } );
	}
	for ( const item of customItems ) {
		bySlug.set( item.slug, { ...item, origin: 'custom' } );
	}

	return [ ...bySlug.values() ];
}

/**
 * Produce a compact token summary for the LLM prompt.
 * Includes actual values so the model can suggest specific presets.
 */
export function summarizeTokens( tokens ) {
	return {
		colors: tokens.color.palette.map( ( c ) => `${ c.slug }: ${ c.color }` ),
		gradients: tokens.color.gradients.map( ( g ) => g.slug ),
		fontSizes: tokens.typography.fontSizes.map( ( fs ) => {
			const fluid = fs.fluidSize ? ` (fluid: ${ JSON.stringify( fs.fluidSize ) })` : '';
			return `${ fs.slug }: ${ fs.size }${ fluid }`;
		} ),
		fontFamilies: tokens.typography.fontFamilies.map(
			( ff ) => `${ ff.slug }: ${ ff.fontFamily }`
		),
		spacing: tokens.spacing.spacingSizes.map( ( s ) => `${ s.slug }: ${ s.size }` ),
		shadows: tokens.shadow.presets.map( ( s ) => `${ s.slug }: ${ s.shadow }` ),
		layout: {
			content: tokens.layout.contentSize,
			wide: tokens.layout.wideSize,
		},
		enabledFeatures: {
			lineHeight: tokens.typography.lineHeight,
			dropCap: tokens.typography.dropCap,
			customColors: tokens.color.customColors,
			linkColor: tokens.color.linkEnabled,
			fluid: tokens.typography.fluidTypography,
			margin: tokens.spacing.margin,
			padding: tokens.spacing.padding,
			borderColor: tokens.border.color,
			borderRadius: tokens.border.radius,
		},
	};
}
```
## **`Context.js`**
```javascript
/**
 * Context Collector
 *
 * Assembles a comprehensive snapshot of the current editor state
 * for agent REST calls. This is the single most important module
 * in the plugin — the quality of LLM recommendations depends
 * entirely on the richness and accuracy of this context.
 *
 * The context has three tiers:
 *   1. Full context  — complete introspected tree + all tokens (internal use)
 *   2. LLM context   — summarized tree + token summary (sent to the model)
 *   3. Scoped context — focused on a single block (for per-block suggestions)
 */
import { select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as blocksStore, serialize } from '@wordpress/blocks';

import {
	introspectBlockTree,
	introspectBlockInstance,
	summarizeTree,
	buildCapabilityIndex,
	collectThemeTokens,
	summarizeTokens,
} from '../introspection';

/**
 * Build the full context snapshot for a recommendation request.
 *
 * @param {object} options
 * @param {string} options.scope   'page' | 'selection' | 'block'
 * @param {string} options.clientId  Target block (for scope='block')
 * @return {object} Context payload for REST.
 */
export function collectContext( { scope = 'page', clientId = null } = {} ) {
	const editor = select( blockEditorStore );
	const editorModule = select( 'core/editor' );
	const editSite = select( 'core/edit-site' );
	const settings = editor.getSettings();
	const agentConfig = settings.flavorAgent || {};

	// ── 1. Editing surface metadata ─────────────────────────

	const surface = agentConfig.surface || 'post';
	const postType = editorModule?.getCurrentPostType?.() || 'post';
	const templateType = editSite?.getEditedPostType?.() || '';
	const postTitle = editorModule?.getEditedPostAttribute?.( 'title' ) || '';
	const postExcerpt = editorModule?.getEditedPostAttribute?.( 'excerpt' ) || '';
	const templateSlug = editorModule?.getEditedPostAttribute?.( 'template' ) || '';

	// ── 2. Block tree introspection ─────────────────────────

	const fullTree = introspectBlockTree( null, 8 );
	const treeSummary = summarizeTree( fullTree );
	const capabilityIndex = buildCapabilityIndex( fullTree );

	// ── 3. Selection context ────────────────────────────────

	let selectionContext = null;
	const selectedId = editor.getSelectedBlockClientId();
	const multiIds = editor.getMultiSelectedBlockClientIds();
	const activeIds = multiIds.length ? multiIds : ( selectedId ? [ selectedId ] : [] );

	if ( activeIds.length ) {
		const selectedInstances = activeIds
			.map( ( id ) => introspectBlockInstance( id ) )
			.filter( Boolean );

		selectionContext = {
			blocks: selectedInstances.map( ( inst ) => ( {
				name: inst.name,
				title: inst.title,
				clientId: inst.clientId,
				currentAttributes: inst.currentAttributes,
				inspectorPanels: inst.inspectorPanels,
				styles: inst.styles,
				activeStyle: inst.activeStyle,
				editingMode: inst.editingMode,
				parentChain: inst.parentChain,
			} ) ),
			serializedMarkup: serialize(
				activeIds.map( ( id ) => editor.getBlock( id ) ).filter( Boolean )
			),
		};
	}

	// ── 4. Scoped block context (for per-block recommendations) ──

	let scopedBlock = null;
	if ( scope === 'block' && clientId ) {
		const instance = introspectBlockInstance( clientId );
		if ( instance ) {
			scopedBlock = {
				...instance,
				siblingsBefore: getSiblingNames( clientId, 'before', 3 ),
				siblingsAfter: getSiblingNames( clientId, 'after', 3 ),
			};
		}
	}

	// ── 5. Theme design tokens ──────────────────────────────

	const themeTokens = collectThemeTokens();
	const tokenSummary = summarizeTokens( themeTokens );

	// ── 6. Registered patterns inventory ────────────────────

	const allPatterns = settings.__experimentalBlockPatterns || [];
	const patternInventory = allPatterns.map( ( p ) => ( {
		name: p.name,
		title: p.title,
		categories: p.categories || [],
		templateTypes: p.templateTypes || [],
		blockTypes: p.blockTypes || [],
	} ) );

	// ── 7. Active block types (plugin detection heuristic) ──

	const allBlockTypes = select( blocksStore ).getBlockTypes();
	const thirdPartyBlocks = allBlockTypes
		.filter( ( bt ) => ! bt.name.startsWith( 'core/' ) )
		.map( ( bt ) => ( {
			name: bt.name,
			title: bt.title,
			category: bt.category,
		} ) );

	// ── 8. Assemble ─────────────────────────────────────────

	return {
		surface,
		postType,
		templateType,
		templateSlug,
		postTitle,
		postExcerpt,
		blockTree: treeSummary,
		capabilityIndex,
		blockCount: fullTree.length,
		selection: selectionContext,
		scopedBlock,
		themeTokens: tokenSummary,
		patternInventory,
		thirdPartyBlocks: thirdPartyBlocks.slice( 0, 30 ),
		contentOnlyHints: agentConfig.contentOnlyHints || {},
		featureFlags: agentConfig.featureFlags || {},
	};
}

/**
 * Build a focused context for a single block's Inspector recommendations.
 * Lighter than the full context — used for real-time per-block suggestions.
 *
 * @param {string} clientId
 * @return {object|null}
 */
export function collectBlockContext( clientId ) {
	if ( ! clientId ) return null;

	const instance = introspectBlockInstance( clientId );
	if ( ! instance ) return null;

	const themeTokens = collectThemeTokens();
	const tokenSummary = summarizeTokens( themeTokens );

	return {
		block: {
			name: instance.name,
			title: instance.title,
			currentAttributes: instance.currentAttributes,
			inspectorPanels: instance.inspectorPanels,
			styles: instance.styles,
			activeStyle: instance.activeStyle,
			variations: instance.variations,
			contentAttributes: instance.contentAttributes,
			configAttributes: instance.configAttributes,
			editingMode: instance.editingMode,
		},
		siblingsBefore: getSiblingNames( clientId, 'before', 3 ),
		siblingsAfter: getSiblingNames( clientId, 'after', 3 ),
		themeTokens: tokenSummary,
	};
}

// ── Helpers ─────────────────────────────────────────────────

function getSiblingNames( clientId, direction, count ) {
	const editor = select( blockEditorStore );
	const rootId = editor.getBlockRootClientId( clientId );
	const order = editor.getBlockOrder( rootId || '' );
	const index = order.indexOf( clientId );
	if ( index === -1 ) return [];

	const slice = direction === 'before'
		? order.slice( Math.max( 0, index - count ), index )
		: order.slice( index + 1, index + 1 + count );

	return slice.map( ( id ) => editor.getBlockName( id ) ).filter( Boolean );
}
```
---
## **`Index.js`**
```javascript
/**
 * Flavor Agent data store.
 *
 * Per-block, per-tab recommendation state. Each recommendation set
 * contains suggestions scoped to Settings, Styles, and Block tabs
 * so Inspector injection components render in the right place.
 */
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

const STORE_NAME = 'flavor-agent';

const DEFAULT_STATE = {
	status: 'idle',
	error: null,
	/** Per-block recs keyed by clientId → { settings[], styles[], block[], explanation, timestamp } */
	blockRecommendations: {},
	pageRecommendations: null,
	pendingOperation: null,
	activityLog: [],
};

const actions = {
	setStatus( status, error = null ) {
		return { type: 'SET_STATUS', status, error };
	},

	setBlockRecommendations( clientId, recommendations ) {
		return { type: 'SET_BLOCK_RECS', clientId, recommendations };
	},

	clearBlockRecommendations( clientId ) {
		return { type: 'CLEAR_BLOCK_RECS', clientId };
	},

	setPageRecommendations( recommendations ) {
		return { type: 'SET_PAGE_RECS', recommendations };
	},

	setPendingOperation( operation ) {
		return { type: 'SET_PENDING', operation };
	},

	clearPending() {
		return { type: 'CLEAR_PENDING' };
	},

	logActivity( entry ) {
		return { type: 'LOG_ACTIVITY', entry };
	},

	/** Thunk: fetch per-block recommendations for Inspector injection. */
	fetchBlockRecommendations( clientId, context, prompt = '' ) {
		return async ( { dispatch } ) => {
			dispatch( actions.setStatus( 'loading' ) );
			try {
				const result = await apiFetch( {
					path: '/flavor-agent/v1/recommend-block',
					method: 'POST',
					data: { context, prompt, clientId },
				} );
				dispatch( actions.setBlockRecommendations( clientId, {
					blockName: context.block?.name || '',
					settings: result.payload?.settings || [],
					styles: result.payload?.styles || [],
					block: result.payload?.block || [],
					explanation: result.payload?.explanation || '',
					timestamp: Date.now(),
				} ) );
				dispatch( actions.setStatus( 'idle' ) );
			} catch ( err ) {
				dispatch( actions.setStatus( 'error', err.message || 'Request failed.' ) );
			}
		};
	},

	/** Thunk: page-level pattern/template recommendations. */
	fetchPageRecommendations( context, prompt = '' ) {
		return async ( { dispatch } ) => {
			dispatch( actions.setStatus( 'loading' ) );
			try {
				const result = await apiFetch( {
					path: '/flavor-agent/v1/recommend-patterns',
					method: 'POST',
					data: { context, prompt },
				} );
				dispatch( actions.setPageRecommendations( result.payload ) );
				dispatch( actions.setPendingOperation( result ) );
				dispatch( actions.setStatus( 'idle' ) );
			} catch ( err ) {
				dispatch( actions.setStatus( 'error', err.message || 'Request failed.' ) );
			}
		};
	},

	/** Thunk: apply a single suggestion to a block's attributes. */
	applySuggestion( clientId, suggestion ) {
		return async ( { dispatch } ) => {
			if ( suggestion.attributeUpdates ) {
				wp.data.dispatch( 'core/block-editor' )
					.updateBlockAttributes( clientId, suggestion.attributeUpdates );
			}
			dispatch( actions.logActivity( {
				type: 'apply_suggestion',
				blockClientId: clientId,
				suggestion: suggestion.label,
				timestamp: new Date().toISOString(),
			} ) );
		};
	},

	approveOperation() {
		return async ( { dispatch, select: sel } ) => {
			const pending = sel.getPendingOperation();
			if ( ! pending ) return;
			dispatch( actions.setStatus( 'loading' ) );
			try {
				const result = await apiFetch( {
					path: '/flavor-agent/v1/approve',
					method: 'POST',
					data: { operation_id: pending.operation_id },
				} );
				dispatch( actions.logActivity( {
					type: result.type,
					action: result.result?.action || 'unknown',
					timestamp: new Date().toISOString(),
				} ) );
				dispatch( actions.clearPending() );
				dispatch( actions.setStatus( 'idle' ) );
				return result;
			} catch ( err ) {
				dispatch( actions.setStatus( 'error', err.message || 'Approval failed.' ) );
			}
		};
	},
};

function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'SET_STATUS':
			return { ...state, status: action.status, error: action.error };
		case 'SET_BLOCK_RECS':
			return {
				...state,
				blockRecommendations: {
					...state.blockRecommendations,
					[ action.clientId ]: action.recommendations,
				},
			};
		case 'CLEAR_BLOCK_RECS': {
			const next = { ...state.blockRecommendations };
			delete next[ action.clientId ];
			return { ...state, blockRecommendations: next };
		}
		case 'SET_PAGE_RECS':
			return { ...state, pageRecommendations: action.recommendations };
		case 'SET_PENDING':
			return { ...state, pendingOperation: action.operation };
		case 'CLEAR_PENDING':
			return { ...state, pendingOperation: null, pageRecommendations: null };
		case 'LOG_ACTIVITY':
			return { ...state, activityLog: [ ...state.activityLog, action.entry ] };
		default:
			return state;
	}
}

const selectors = {
	getStatus: ( state ) => state.status,
	getError: ( state ) => state.error,
	isLoading: ( state ) => state.status === 'loading',
	getBlockRecommendations: ( state, clientId ) => state.blockRecommendations[ clientId ] || null,
	getSettingsSuggestions: ( state, clientId ) => state.blockRecommendations[ clientId ]?.settings || [],
	getStylesSuggestions: ( state, clientId ) => state.blockRecommendations[ clientId ]?.styles || [],
	getBlockSuggestions: ( state, clientId ) => state.blockRecommendations[ clientId ]?.block || [],
	getPageRecommendations: ( state ) => state.pageRecommendations,
	getPendingOperation: ( state ) => state.pendingOperation,
	getActivityLog: ( state ) => state.activityLog,
	hasRecentRecommendations: ( state, clientId ) => {
		const rec = state.blockRecommendations[ clientId ];
		return rec ? ( Date.now() - rec.timestamp ) < 300_000 : false;
	},
};

const store = createReduxStore( STORE_NAME, { reducer, actions, selectors } );
register( store );

export default store;
export { STORE_NAME };
```
## **`InspectorInjector.js`**
```javascript
/**
 * Inspector Injector
 *
 * Uses the editor.BlockEdit filter to wrap every block's edit component
 * and inject AI recommendation controls into the native Inspector tabs:
 *
 *   Settings tab (group=default)  → SettingsRecommendations
 *   Appearance tab (group=styles) → StylesRecommendations
 *   Appearance sub-panels         → Color, Typography, Dimensions suggestions
 *
 * Recommendations are fetched on-demand when a block is selected and
 * the user clicks "Get suggestions", or auto-fetched if the sidebar
 * is open and the block changes.
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Button, Spinner, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useCallback } from '@wordpress/element';
import { sparkles as icon } from '@wordpress/icons';

import { STORE_NAME } from '../store';
import { collectBlockContext } from '../store/context';
import SettingsRecommendations from './SettingsRecommendations';
import StylesRecommendations from './StylesRecommendations';

/**
 * HOC that wraps BlockEdit to inject AI controls into the Inspector.
 */
const withAIRecommendations = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		const { clientId, name, isSelected } = props;

		const { recommendations, isLoading, error } = useSelect( ( sel ) => {
			const s = sel( STORE_NAME );
			return {
				recommendations: s.getBlockRecommendations( clientId ),
				isLoading: s.isLoading(),
				error: s.getError(),
			};
		}, [ clientId ] );

		const { fetchBlockRecommendations, setStatus } = useDispatch( STORE_NAME );
		const [ prompt, setPrompt ] = useState( '' );

		const handleFetch = useCallback( () => {
			const ctx = collectBlockContext( clientId );
			if ( ctx ) {
				fetchBlockRecommendations( clientId, ctx, prompt );
			}
		}, [ clientId, prompt, fetchBlockRecommendations ] );

		// Only render AI controls when this block is selected.
		if ( ! isSelected ) {
			return <BlockEdit { ...props } />;
		}

		const hasRecs = recommendations &&
			( recommendations.settings.length > 0 ||
			  recommendations.styles.length > 0 ||
			  recommendations.block.length > 0 );

		return (
			<>
				<BlockEdit { ...props } />

				{ /* ── Settings tab: AI recommendations panel ── */ }
				<InspectorControls>
					<PanelBody
						title="AI Recommendations"
						initialOpen={ false }
						icon={ icon }
					>
						<div style={ { marginBottom: '8px' } }>
							<textarea
								placeholder="What are you trying to achieve?"
								value={ prompt }
								onChange={ ( e ) => setPrompt( e.target.value ) }
								rows={ 2 }
								style={ { width: '100%', resize: 'vertical' } }
							/>
						</div>
						<Button
							variant="primary"
							onClick={ handleFetch }
							disabled={ isLoading }
							icon={ icon }
							style={ { width: '100%', justifyContent: 'center' } }
						>
							{ isLoading ? <Spinner /> : 'Get Suggestions' }
						</Button>

						{ error && (
							<Notice
								status="error"
								isDismissible
								onDismiss={ () => setStatus( 'idle' ) }
								style={ { marginTop: '8px' } }
							>
								{ error }
							</Notice>
						) }

						{ recommendations?.explanation && (
							<p style={ {
								marginTop: '8px',
								fontSize: '12px',
								color: 'var(--wp-components-color-foreground-secondary, #757575)',
							} }>
								{ recommendations.explanation }
							</p>
						) }
					</PanelBody>

					{ /* Settings-tab specific suggestions */ }
					{ hasRecs && recommendations.settings.length > 0 && (
						<SettingsRecommendations
							clientId={ clientId }
							suggestions={ recommendations.settings }
						/>
					) }
				</InspectorControls>

				{ /* ── Appearance tab: style suggestions ── */ }
				{ hasRecs && recommendations.styles.length > 0 && (
					<InspectorControls group="styles">
						<StylesRecommendations
							clientId={ clientId }
							suggestions={ recommendations.styles }
						/>
					</InspectorControls>
				) }

				{ /* ── Appearance sub-panels: targeted suggestions ── */ }
				{ hasRecs && (
					<>
						<ColorSuggestions
							clientId={ clientId }
							suggestions={ recommendations.styles.filter(
								( s ) => s.panel === 'color'
							) }
						/>
						<TypographySuggestions
							clientId={ clientId }
							suggestions={ recommendations.styles.filter(
								( s ) => s.panel === 'typography'
							) }
						/>
						<DimensionsSuggestions
							clientId={ clientId }
							suggestions={ recommendations.styles.filter(
								( s ) => s.panel === 'dimensions'
							) }
						/>
						<BorderSuggestions
							clientId={ clientId }
							suggestions={ recommendations.styles.filter(
								( s ) => s.panel === 'border'
							) }
						/>
					</>
				) }
			</>
		);
	};
}, 'withAIRecommendations' );

// ── Sub-panel injection components ──────────────────────────

function ColorSuggestions( { clientId, suggestions } ) {
	if ( ! suggestions.length ) return null;
	return (
		<InspectorControls group="color">
			<SuggestionChips
				clientId={ clientId }
				suggestions={ suggestions }
				label="AI color suggestions"
			/>
		</InspectorControls>
	);
}

function TypographySuggestions( { clientId, suggestions } ) {
	if ( ! suggestions.length ) return null;
	return (
		<InspectorControls group="typography">
			<SuggestionChips
				clientId={ clientId }
				suggestions={ suggestions }
				label="AI typography suggestions"
			/>
		</InspectorControls>
	);
}

function DimensionsSuggestions( { clientId, suggestions } ) {
	if ( ! suggestions.length ) return null;
	return (
		<InspectorControls group="dimensions">
			<SuggestionChips
				clientId={ clientId }
				suggestions={ suggestions }
				label="AI spacing suggestions"
			/>
		</InspectorControls>
	);
}

function BorderSuggestions( { clientId, suggestions } ) {
	if ( ! suggestions.length ) return null;
	return (
		<InspectorControls group="border">
			<SuggestionChips
				clientId={ clientId }
				suggestions={ suggestions }
				label="AI border suggestions"
			/>
		</InspectorControls>
	);
}

/**
 * Renders a set of suggestion chips that apply attribute updates on click.
 * Used inside ToolsPanel sub-panels (color, typography, dimensions, border)
 * where space is tight and CSS grid layout applies.
 */
function SuggestionChips( { clientId, suggestions, label } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );

	return (
		<div
			style={ {
				gridColumn: '1 / -1',
				display: 'flex',
				flexWrap: 'wrap',
				gap: '4px',
				padding: '4px 0',
			} }
			aria-label={ label }
		>
			{ suggestions.map( ( s, i ) => (
				<Button
					key={ i }
					variant="secondary"
					size="small"
					onClick={ () => applySuggestion( clientId, s ) }
					title={ s.description || s.label }
					style={ {
						fontSize: '11px',
						padding: '2px 8px',
						height: 'auto',
						lineHeight: '1.6',
					} }
				>
					{ s.label }
					{ s.preview && (
						<span
							style={ {
								display: 'inline-block',
								width: '12px',
								height: '12px',
								borderRadius: '2px',
								backgroundColor: s.preview,
								marginLeft: '4px',
								verticalAlign: 'middle',
								border: '1px solid rgba(0,0,0,0.1)',
							} }
						/>
					) }
				</Button>
			) ) }
		</div>
	);
}

// ── Register the filter ─────────────────────────────────────

addFilter(
	'editor.BlockEdit',
	'flavor-agent/ai-recommendations',
	withAIRecommendations,
);

export default withAIRecommendations;
```
## **`SettingsRecommendations.js`**
```javascript
/**
 * Settings Recommendations
 *
 * Renders AI-suggested configuration changes in the Settings tab
 * of the native Inspector sidebar. These are non-style settings:
 * layout options, alignment, anchor, position, block-specific
 * config attributes, and structural suggestions.
 *
 * Each suggestion includes:
 *   - label: human-readable name
 *   - description: why the LLM recommends this
 *   - attributeUpdates: the exact attributes to set on the block
 *   - panel: which settings area it targets
 *   - confidence: 0-1 score
 */
import { PanelBody, Button, ExternalLink } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { Icon, check, arrowRight } from '@wordpress/icons';

import { STORE_NAME } from '../store';

export default function SettingsRecommendations( { clientId, suggestions } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );

	if ( ! suggestions.length ) return null;

	// Group suggestions by their target panel.
	const grouped = {};
	for ( const s of suggestions ) {
		const key = s.panel || 'general';
		if ( ! grouped[ key ] ) grouped[ key ] = [];
		grouped[ key ].push( s );
	}

	return (
		<PanelBody title="AI Settings" initialOpen>
			{ Object.entries( grouped ).map( ( [ panel, items ] ) => (
				<div key={ panel } style={ { marginBottom: '12px' } }>
					{ Object.keys( grouped ).length > 1 && (
						<div style={ {
							fontSize: '11px',
							fontWeight: 600,
							textTransform: 'uppercase',
							letterSpacing: '0.5px',
							color: 'var(--wp-components-color-foreground-secondary, #757575)',
							marginBottom: '6px',
						} }>
							{ panelLabel( panel ) }
						</div>
					) }

					{ items.map( ( suggestion, i ) => (
						<SuggestionCard
							key={ i }
							suggestion={ suggestion }
							onApply={ () => applySuggestion( clientId, suggestion ) }
						/>
					) ) }
				</div>
			) ) }
		</PanelBody>
	);
}

function SuggestionCard( { suggestion, onApply } ) {
	const { label, description, confidence, currentValue, suggestedValue } = suggestion;

	return (
		<div style={ {
			padding: '8px 10px',
			marginBottom: '6px',
			background: 'var(--wp-components-color-background, #f0f0f0)',
			borderRadius: '4px',
			border: '1px solid var(--wp-components-color-accent-inverted, #e0e0e0)',
		} }>
			<div style={ {
				display: 'flex',
				justifyContent: 'space-between',
				alignItems: 'center',
				marginBottom: description ? '4px' : 0,
			} }>
				<span style={ { fontWeight: 500, fontSize: '13px' } }>
					{ label }
				</span>
				<Button
					variant="primary"
					size="small"
					onClick={ onApply }
					icon={ check }
					label="Apply"
					style={ { minWidth: 'auto', padding: '0 8px', height: '24px' } }
				>
					Apply
				</Button>
			</div>

			{ description && (
				<p style={ {
					margin: '0 0 4px',
					fontSize: '12px',
					color: 'var(--wp-components-color-foreground-secondary, #757575)',
					lineHeight: '1.4',
				} }>
					{ description }
				</p>
			) }

			{ currentValue !== undefined && suggestedValue !== undefined && (
				<div style={ {
					display: 'flex',
					alignItems: 'center',
					gap: '6px',
					fontSize: '11px',
					fontFamily: 'monospace',
				} }>
					<span style={ { opacity: 0.6, textDecoration: 'line-through' } }>
						{ formatValue( currentValue ) }
					</span>
					<Icon icon={ arrowRight } size={ 12 } />
					<span style={ { fontWeight: 600 } }>
						{ formatValue( suggestedValue ) }
					</span>
				</div>
			) }

			{ confidence != null && (
				<div style={ {
					marginTop: '4px',
					height: '3px',
					borderRadius: '2px',
					background: 'var(--wp-components-color-accent-inverted, #e0e0e0)',
					overflow: 'hidden',
				} }>
					<div style={ {
						width: `${ Math.round( confidence * 100 ) }%`,
						height: '100%',
						background: 'var(--wp-components-color-accent, #3858e9)',
						borderRadius: '2px',
					} } />
				</div>
			) }
		</div>
	);
}

function panelLabel( panel ) {
	const labels = {
		general: 'General',
		layout: 'Layout',
		position: 'Position',
		advanced: 'Advanced',
		alignment: 'Alignment',
		bindings: 'Bindings',
	};
	return labels[ panel ] || panel;
}

function formatValue( val ) {
	if ( val === true ) return 'true';
	if ( val === false ) return 'false';
	if ( val == null ) return 'none';
	if ( typeof val === 'object' ) return JSON.stringify( val );
	return String( val );
}
```
---
## **`StylesRecommendations.js`**
```javascript
/**
 * Styles Recommendations
 *
 * Renders AI-suggested style changes in the Appearance tab of the
 * native Inspector sidebar (group="styles"). These suggestions
 * include specific values derived from the theme's design tokens:
 * exact color presets, font size slugs, spacing scale values,
 * shadow presets, and style variation recommendations.
 *
 * Each suggestion includes:
 *   - label: human-readable name (e.g. "Use theme accent for background")
 *   - description: rationale
 *   - panel: which sub-panel it targets (color, typography, dimensions, border, shadow)
 *   - attributeUpdates: exact attribute changes
 *   - preview: optional visual preview (hex color, font preview string, etc.)
 *   - presetSlug: the theme.json preset slug being referenced
 *   - cssVar: the CSS custom property (e.g. var(--wp--preset--color--accent))
 *
 * Suggestions targeting specific sub-panels (color, typography, etc.)
 * are ALSO injected directly into those panels by InspectorInjector.
 * This component renders the general styles overview and style
 * variation recommendations.
 */
import { PanelBody, Button, ButtonGroup } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { Icon, check, styles as stylesIcon } from '@wordpress/icons';

import { STORE_NAME } from '../store';

export default function StylesRecommendations( { clientId, suggestions } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );

	if ( ! suggestions.length ) return null;

	// Separate style variation suggestions from attribute-level suggestions.
	const variationSuggestions = suggestions.filter( ( s ) => s.type === 'style_variation' );
	const attributeSuggestions = suggestions.filter( ( s ) => s.type !== 'style_variation' );

	// Group attribute suggestions by panel for the overview.
	const byPanel = {};
	for ( const s of attributeSuggestions ) {
		// Skip ones that will render in their native sub-panels.
		if ( [ 'color', 'typography', 'dimensions', 'border' ].includes( s.panel ) ) continue;
		const key = s.panel || 'general';
		if ( ! byPanel[ key ] ) byPanel[ key ] = [];
		byPanel[ key ].push( s );
	}

	return (
		<PanelBody title="AI Style Suggestions" initialOpen icon={ stylesIcon }>
			{ /* Style variation recommendations */ }
			{ variationSuggestions.length > 0 && (
				<div style={ { marginBottom: '12px' } }>
					<div style={ {
						fontSize: '11px',
						fontWeight: 600,
						textTransform: 'uppercase',
						letterSpacing: '0.5px',
						color: 'var(--wp-components-color-foreground-secondary)',
						marginBottom: '8px',
					} }>
						Block Style
					</div>

					<ButtonGroup style={ { display: 'flex', flexWrap: 'wrap', gap: '4px' } }>
						{ variationSuggestions.map( ( s, i ) => (
							<Button
								key={ i }
								variant={ s.isCurrentStyle ? 'primary' : 'secondary' }
								size="compact"
								onClick={ () => applySuggestion( clientId, s ) }
								title={ s.description }
							>
								{ s.label }
								{ s.isRecommended && (
									<span style={ {
										marginLeft: '4px',
										fontSize: '10px',
										opacity: 0.7,
									} }>
										★
									</span>
								) }
							</Button>
						) ) }
					</ButtonGroup>
				</div>
			) }

			{ /* General style suggestions (shadow, filter, background, etc.) */ }
			{ Object.entries( byPanel ).map( ( [ panel, items ] ) => (
				<div key={ panel } style={ { marginBottom: '10px' } }>
					<div style={ {
						fontSize: '11px',
						fontWeight: 600,
						textTransform: 'uppercase',
						letterSpacing: '0.5px',
						color: 'var(--wp-components-color-foreground-secondary)',
						marginBottom: '6px',
					} }>
						{ panel }
					</div>

					{ items.map( ( s, i ) => (
						<StyleSuggestionRow
							key={ i }
							suggestion={ s }
							onApply={ () => applySuggestion( clientId, s ) }
						/>
					) ) }
				</div>
			) ) }

			{ /* Summary of what's available in native sub-panels */ }
			{ attributeSuggestions.some( ( s ) => [ 'color', 'typography', 'dimensions', 'border' ].includes( s.panel ) ) && (
				<p style={ {
					fontSize: '11px',
					color: 'var(--wp-components-color-foreground-secondary)',
					marginTop: '8px',
					fontStyle: 'italic',
				} }>
					More suggestions appear in the Color, Typography, Dimensions,
					and Border panels above.
				</p>
			) }
		</PanelBody>
	);
}

function StyleSuggestionRow( { suggestion, onApply } ) {
	const { label, description, preview, presetSlug, cssVar } = suggestion;

	return (
		<div style={ {
			display: 'flex',
			alignItems: 'center',
			gap: '8px',
			padding: '6px 8px',
			marginBottom: '4px',
			background: 'var(--wp-components-color-background, #f0f0f0)',
			borderRadius: '4px',
			border: '1px solid var(--wp-components-color-accent-inverted, #e0e0e0)',
		} }>
			{ /* Visual preview */ }
			{ preview && isColor( preview ) && (
				<span style={ {
					flexShrink: 0,
					width: '20px',
					height: '20px',
					borderRadius: '4px',
					backgroundColor: preview,
					border: '1px solid rgba(0,0,0,0.15)',
				} } />
			) }

			{ /* Label and description */ }
			<div style={ { flex: 1, minWidth: 0 } }>
				<div style={ { fontSize: '12px', fontWeight: 500, lineHeight: '1.3' } }>
					{ label }
				</div>
				{ description && (
					<div style={ {
						fontSize: '11px',
						color: 'var(--wp-components-color-foreground-secondary)',
						lineHeight: '1.3',
						marginTop: '1px',
					} }>
						{ description }
					</div>
				) }
				{ cssVar && (
					<code style={ {
						fontSize: '10px',
						opacity: 0.5,
						display: 'block',
						marginTop: '2px',
					} }>
						{ cssVar }
					</code>
				) }
			</div>

			{ /* Apply button */ }
			<Button
				variant="tertiary"
				size="small"
				onClick={ onApply }
				icon={ check }
				label="Apply"
				style={ { flexShrink: 0 } }
			/>
		</div>
	);
}

function isColor( str ) {
	return /^(#|rgb|hsl|var\()/.test( str );
}
```
---
## **`agent_controller.php`**
```php
<?php

declare(strict_types=1);

namespace FlavorAgent\REST;

use FlavorAgent\Agents\Dispatcher;

/**
 * REST API surface for agent operations.
 */
final class Agent_Controller {

	private const NAMESPACE = 'flavor-agent/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$common_args = [
			'context' => [
				'required'    => true,
				'type'        => 'object',
				'description' => 'Editor context snapshot.',
			],
			'prompt' => [
				'required'    => false,
				'type'        => 'string',
				'default'     => '',
			],
		];

		// Page-level operations.
		$routes = [
			'/recommend-patterns'     => 'recommend_patterns',
			'/recommend-template'     => 'recommend_template',
			'/generate-pattern'       => 'generate_pattern',
			'/transform-blocks'       => 'transform_selected_blocks',
			'/generate-overlay'       => 'generate_overlay',
			'/generate-interactivity' => 'generate_interactivity_module',
		];

		foreach ( $routes as $path => $operation ) {
			register_rest_route( self::NAMESPACE, $path, [
				'methods'             => 'POST',
				'callback'            => fn( \WP_REST_Request $req ) => self::handle( $operation, $req ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => $common_args,
			] );
		}

		// Per-block recommendation endpoint — returns tab-scoped suggestions.
		register_rest_route( self::NAMESPACE, '/recommend-block', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_recommend_block' ],
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'args' => [
				'context'  => [ 'required' => true, 'type' => 'object' ],
				'prompt'   => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'clientId' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		// Approval endpoint
		register_rest_route( self::NAMESPACE, '/approve', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_approve' ],
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'args' => [
				'operation_id' => [ 'required' => true, 'type' => 'string' ],
			],
		] );
	}

	private static function handle( string $operation, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$context = $request->get_param( 'context' );
		$prompt  = $request->get_param( 'prompt' );

		$result = Dispatcher::run( $operation, $context, $prompt );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200 );
	}

	/**
	 * LLM-enhanced value suggestions for a specific block.
	 *
	 * The JS client sends the full context including the block's capability
	 * manifest and current design tokens. The LLM re-ranks and refines the
	 * local suggestions with contextual reasoning.
	 */
	public static function handle_suggest_values( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$context   = $request->get_param( 'context' );
		$client_id = $request->get_param( 'clientId' );

		$result = Dispatcher::run( 'suggest_values', $context, $client_id );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200 );
	}

	public static function handle_approve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$op_id  = $request->get_param( 'operation_id' );
		$result = Dispatcher::approve( $op_id );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200 );
	}
}
```
