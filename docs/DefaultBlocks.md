---
title: "02-Apr-Technical-Notes"
source: "https://khushalsdaily.wordpress.com/2026/04/02/02-apr-technical-notes/"
author:
  - "[[Khushal Sain S]]"
published: 2026-04-02
created: 2026-04-02
description: "Default Blocks Description:The WordPress Block Editor includes a large set of built in blocks called core blocks or default blocks. These are the standard blocks available in Gutenberg for writing content, building layouts, embedding media, and designing pages. They act as the foundation of the editor and are also a very useful reference when learning…"
tags:
  - "clippings"
---
## Default Blocks

**Description:**  
The WordPress Block Editor includes a large set of built in blocks called core blocks or default blocks. These are the standard blocks available in Gutenberg for writing content, building layouts, embedding media, and designing pages. They act as the foundation of the editor and are also a very useful reference when learning custom block development. If you want to understand how block structure, block settings, block supports, and nested blocks work in practice, core blocks are the best examples to study.

---

### Why Default Blocks Matter

| Purpose | Explanation |
| --- | --- |
| Content creation | Used for writing paragraphs, headings, lists, quotes, and more |
| Layout building | Used for grouping blocks, columns, media layouts, and spacing |
| Design reference | Show how WordPress handles alignment, spacing, colors, and typography |
| Learning resource | Help developers understand how custom blocks should behave |

---

### Common Default Blocks

| Block | Block Name | Purpose |
| --- | --- | --- |
| Paragraph | `core/paragraph` | Main text content block |
| Heading | `core/heading` | Section titles and headings |
| Image | `core/image` | Insert and style a single image |
| Group | `core/group` | Wrap and organize inner blocks |
| Columns | `core/columns` | Multi column layout |
| Column | `core/column` | Individual column inside Columns |
| Media & Text | `core/media-text` | Place media and text side by side |
| Query Loop | `core/query` | Display dynamic lists of posts |

---

### Important Default Block Features

| Feature | Example Blocks |
| --- | --- |
| Text editing | Paragraph, Heading |
| Media handling | Image, Gallery, Cover |
| Nested blocks | Group, Columns, Buttons |
| Dynamic content | Query Loop, Latest Posts |
| Supports integration | Paragraph, Image, Group |

---

### Paragraph Block

**Description:**  
The Paragraph block is the most basic block in WordPress. Most writing starts with this block. It supports alignment, color, typography, and other standard options. It is often the first block developers compare against when creating a custom editable block with `RichText`.

---

### Heading Block

**Description:**  
The Heading block organizes content and improves accessibility and SEO. It supports heading levels, alignment, anchors, and text styling. It is a very common example when learning editable blocks because it uses text based attributes and rich text formatting.

---

### Image Block

**Description:**  
The Image block inserts images and supports alignments, duotone filters, borders, and other visual options. It is useful for understanding media related attributes and block supports.

---

### Group and Columns

**Description:**  
The Group block is a general purpose container block that wraps inner blocks. The Columns block creates structured multi column layouts and each Column block acts as a direct child block. These blocks are important examples of nested blocks and `InnerBlocks`.

---

## Creating a Block

**Description:**  
Creating a custom block means defining its metadata, editor behavior, frontend output, and optional styles. The modern WordPress approach uses the `block.json` file as the single source of truth for block registration. WordPress reads this file on both the server and client side, which reduces duplication and improves performance by loading assets only when needed.

---

### Recommended Way to Start

The easiest and recommended way to begin block development is to scaffold a block using the `@wordpress/create-block` package. This automatically creates the required plugin structure, build tools, JavaScript files, and metadata.

```
npx @wordpress/create-block@latest my-custom-block --variant=dynamic
```

This command generates a plugin called `my-custom-block`. The `dynamic` variant prepares the block for server side rendering, but the same generated project can also be adapted into a static block if needed.

---

### Why block.json is Important

| Benefit | Explanation |
| --- | --- |
| Single metadata file | Register once for PHP and JavaScript |
| Better performance | Assets are loaded only when needed |
| Cleaner structure | Metadata is kept separate from logic |
| Easier maintenance | Title, icon, category, supports, and attributes are centralized |

---

### Basic block.json Example

```
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "my-plugin/custom-block",
  "version": "1.0.0",
  "title": "Custom Block",
  "category": "widgets",
  "icon": "star",
  "description": "A customized block.",
  "attributes": {
    "message": {
      "type": "string",
      "source": "html",
      "selector": ".message"
    }
  },
  "supports": {
    "html": false,
    "color": {
      "text": true,
      "background": true
    }
  },
  "editorScript": "file:./index.js",
  "editorStyle": "file:./index.css",
  "style": "file:./style-index.css",
  "render": "file:./render.php"
}
```

---

### Important block.json Properties

| Property | Purpose |
| --- | --- |
| `name` | Unique block identifier in `namespace/block-name` format |
| `title` | Display name shown in inserter |
| `category` | Where the block appears in inserter |
| `icon` | Block icon |
| `description` | Short description |
| `attributes` | Structured data for block state |
| `supports` | Built in editor features |
| `editorScript` | JavaScript for editor |
| `style` | Frontend and editor styles |
| `render` | PHP file for dynamic rendering |

---

### Registering the Block in JavaScript

```
import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
    icon: 'smiley',
    edit: Edit,
    save: save
} );
```

This connects the block metadata with the edit and save components.

---

## Basic Static Block

**Description:**  
A static block saves its final HTML directly into the post content. That means the markup produced by the `save` function is stored in the database and used as is on the frontend. Static blocks are best when the output does not need to change automatically after saving. They are simple, fast, and easy to reason about, but they must be handled carefully because changing the saved structure later may cause validation errors.

---

### How Static Blocks Work

| Part | Role |
| --- | --- |
| `edit` function | Controls how the block behaves in the editor |
| `save` function | Defines the HTML saved in post content |
| `attributes` | Store the values used by both edit and save |
| `useBlockProps()` | Applies required editor wrapper props |

---

### Why useBlockProps() Matters

WordPress requires `useBlockProps()` on the outermost editor wrapper and `useBlockProps.save()` on the outermost saved element. These helper functions add standard class names, block supports styles, and other generated properties needed for the editor and frontend to work consistently.

---

### Static Block Edit Example

```
import { useBlockProps, RichText } from '@wordpress/block-editor';

export default function Edit( { attributes, setAttributes } ) {
    const blockProps = useBlockProps( { className: 'my-custom-class' } );
    
    return (
        <div { ...blockProps }>
            <RichText
                tagName="p"
                value={ attributes.content }
                onChange={ ( val ) => setAttributes( { content: val } ) }
                placeholder="Enter text here..."
            />
        </div>
    );
}
```

---

### Static Block Save Example

```
import { useBlockProps, RichText } from '@wordpress/block-editor';

export default function save( { attributes } ) {
    const blockProps = useBlockProps.save();
    
    return (
        <div { ...blockProps }>
            <RichText.Content tagName="p" value={ attributes.content } />
        </div>
    );
}
```

---

### Static Block Advantages

| Advantage | Explanation |
| --- | --- |
| Fast output | HTML is already saved |
| Easy rendering | No server callback required |
| Good for fixed content | Best for content that does not need live updates |

---

### Static Block Limitation

| Limitation | Explanation |
| --- | --- |
| Validation risk | If `save()` output changes later, block may become invalid |
| No automatic updates | Saved markup does not refresh from external data |
| Less flexible | Harder to handle content that depends on runtime context |

---

### Block Validation

When the editor loads, WordPress compares the stored markup with the current result of the `save` function. If they do not match exactly, the block is marked invalid. This is why static blocks need stable save markup and may require the deprecation API if the structure changes over time.

---

## Dynamic Block

**Description:**  
A dynamic block does not save final HTML into post content. Instead, it saves block attributes and generates the output on the server at render time. Dynamic blocks are useful when content should always stay updated, when frontend output depends on external data, or when you want to change block markup later without triggering validation errors. WordPress commonly uses this pattern for blocks like Latest Posts and Query Loop.

---

### When to Use a Dynamic Block

| Scenario | Why Dynamic is Better |
| --- | --- |
| Latest posts list | Output should change when posts change |
| Date or time block | Content should always reflect current values |
| User specific content | Output depends on runtime context |
| Frequently evolving markup | No validation problems from changing save HTML |

---

### How Dynamic Blocks Differ

| Static Block | Dynamic Block |
| --- | --- |
| Saves HTML in post content | Saves attributes and renders later |
| Uses `save()` to return markup | Usually uses `save()` returning `null` |
| Validation sensitive | No saved markup validation issue |
| Good for fixed content | Good for live or computed content |

---

### Dynamic Block block.json Example

```
{
  "name": "my-plugin/dynamic-block",
  "render": "file:./render.php"
}
```

---

### Dynamic Render File Example

```
<?php
$latest_posts = get_posts( array(
    'posts_per_page' => 1,
    'post_status'    => 'publish',
) );

if ( empty( $latest_posts ) ) {
    echo '<p>No posts found.</p>';
    return;
}

$post = $latest_posts;
?>
<div <?php echo get_block_wrapper_attributes(); ?>>
    <a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>">
        <?php echo esc_html( get_the_title( $post->ID ) ); ?>
    </a>
</div>
```

---

### Important PHP Helper

`get_block_wrapper_attributes()` is the PHP side equivalent used for block wrapper attributes in dynamic rendering. It adds block classes, styles, and support related attributes to the outer element, just like `useBlockProps.save()` does in JavaScript based save functions.

---

### Fetching Dynamic Data in the Editor

Even if the frontend uses PHP rendering, the editor still needs a preview. WordPress gives two common ways to do that.

---

### Option 1: useSelect with Core Data

```
import { useBlockProps } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';

export default function Edit() {
    const posts = useSelect( ( select ) => {
        return select( 'core' ).getEntityRecords( 'postType', 'post', { per_page: 1 } );
    }, [] );

    return (
        <div { ...useBlockProps() }>
            { ! posts && 'Loading...' }
            { posts && posts.length > 0 && (
                <a href={ posts[ 0 ].link }>{ posts[ 0 ].title.rendered }</a>
            ) }
        </div>
    );
}
```

This is the recommended approach because it uses the WordPress data system and is usually faster than making a full server side render request for every change.

---

### Option 2: ServerSideRender Component

```
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( props ) {
    return (
        <div { ...useBlockProps() }>
            <ServerSideRender block="my-plugin/dynamic-block" attributes={ props.attributes } />
        </div>
    );
}
```

This is useful as a fallback, especially when the editor preview is too complex to reproduce on the client side. However, it is slower because it depends on server requests.

---

## Supports

**Description:**  
The Block Supports API lets a block opt into standard WordPress editor features such as color controls, spacing controls, typography tools, dimensions, alignment, borders, and more. Instead of building these controls manually, a developer declares them in `block.json` and WordPress automatically creates the necessary UI and saves the corresponding classes or inline styles. This is the preferred approach because it gives a consistent experience across custom blocks and core blocks.

---

### Why Supports Are Important

| Benefit | Explanation |
| --- | --- |
| Less custom code | Built in controls are reused |
| Consistent UI | Same sidebar and toolbar patterns as core blocks |
| Automatic attributes | WordPress handles storage internally |
| Better compatibility | Easier integration with global styles and themes |

---

### Common Support Areas

| Support Area | Examples |
| --- | --- |
| Alignment | left, right, wide, full |
| Color | text, background, link, gradients |
| Spacing | margin, padding, blockGap |
| Typography | fontSize, lineHeight, textAlign |
| Dimensions | width, height, aspect ratio |

---

### Example Supports Declaration

```
"supports": {
  "color": {
    "background": true,
    "text": true,
    "link": true
  },
  "spacing": {
    "margin": true,
    "padding": true,
    "blockGap": true
  },
  "typography": {
    "fontSize": true,
    "lineHeight": true
  }
}
```

---

### Alignment Support Example

```
"supports": { "align": ["left", "right", "wide", "full"] }
```

This allows the block to participate in alignment options just like many core blocks.

---

### How Saved Markup Looks

When users apply support based settings, Gutenberg outputs classes and inline styles automatically. A saved element may look like this:

```
<p class="wp-block-my-plugin has-accent-color has-text-color has-background" style="margin-top:20px; font-size:18px;">
    Hello World
</p>
```

---

### Supports Summary

| Support | What It Adds |
| --- | --- |
| `align` | Alignment options |
| `color` | Text, background, link, gradient controls |
| `spacing` | Margin, padding, block gap controls |
| `typography` | Font size and line height controls |
| `dimensions` | Sizing controls |

---

## Extending the Editor UI

**Description:**  
WordPress allows developers to extend the editor UI in a structured way. This can happen at the block level using `BlockControls` and `InspectorControls`, or at the global editor level using systems like SlotFill and plugin sidebars. The key idea is to use built in extension points instead of modifying WordPress core directly.

---

## Block Level Editor Extensions

### BlockControls

`BlockControls` is used to add controls to the floating toolbar above a selected block. These controls are best for content related actions that should be visible immediately while editing.

```
import { BlockControls, AlignmentToolbar } from '@wordpress/block-editor';

export default function Edit( { attributes, setAttributes } ) {
    return (
        <>
            <BlockControls group="block">
                <AlignmentToolbar 
                    value={ attributes.alignment } 
                    onChange={ ( val ) => setAttributes( { alignment: val } ) } 
                />
            </BlockControls>
            <div style={{ textAlign: attributes.alignment }}>Content</div>
        </>
    );
}
```

---

### InspectorControls

`InspectorControls` is used to add controls to the editor sidebar. These controls are better for block level settings that are less frequently changed or need more screen space.

```
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
    return (
        <InspectorControls>
            <PanelBody title="Custom Settings" initialOpen={ true }>
                <ToggleControl
                    label="Enable Feature"
                    checked={ attributes.isEnabled }
                    onChange={ ( val ) => setAttributes( { isEnabled: val } ) }
                />
            </PanelBody>
        </InspectorControls>
    );
}
```

---

### Toolbar vs Sidebar

| UI Area | Best Use |
| --- | --- |
| BlockControls | Quick content or block actions |
| InspectorControls | Detailed block settings |

---

## Global Editor UI Extensions

**Description:**  
If you need to extend the editor beyond one block, WordPress uses the SlotFill system. This allows plugins to inject panels, menu items, status information, and other custom UI into predefined areas of the editor.

---

### Common Global Extension Points

| Extension Point | Purpose |
| --- | --- |
| PluginSidebar | Add a custom sidebar |
| PluginDocumentSettingPanel | Add a panel in document settings |
| PluginPostStatusInfo | Add info in post status area |
| PluginMoreMenuItem | Add item in the more menu |

---

### Plugin Sidebar Example with Post Meta

```
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/editor';
import { TextControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';

const MetaBlockField = () => {
    const metaFieldValue = useSelect( ( select ) => {
        return select( 'core/editor' ).getEditedPostAttribute( 'meta' )[ 'my_custom_meta_field' ];
    }, [] );

    const { editPost } = useDispatch( 'core/editor' );

    return (
        <TextControl
            label="Custom Meta Field"
            value={ metaFieldValue }
            onChange={ ( content ) => {
                editPost( { meta: { my_custom_meta_field: content } } );
            } }
        />
    );
};

registerPlugin( 'my-custom-sidebar', {
    icon: 'admin-post',
    render: () => (
        <PluginSidebar name="my-plugin-sidebar" title="My Custom Sidebar">
            <div className="plugin-sidebar-content">
                <MetaBlockField />
            </div>
        </PluginSidebar>
    ),
} );
```

This example is important because it shows how editor UI extensions can work together with post meta and editor data stores.

---

## Formatting Toolbar API

**Description:**  
Another way to extend the editor UI is to add custom buttons to the rich text formatting toolbar. This uses the Format API and is appropriate when you want inline formatting for selected text in a `RichText` field.

---

### Custom Format Example

```
import { registerFormatType, toggleFormat } from '@wordpress/rich-text';
import { RichTextToolbarButton } from '@wordpress/block-editor';

const MyCustomButton = ( { isActive, onChange, value } ) => (
    <RichTextToolbarButton
        icon="editor-code"
        title="Sample Format"
        onClick={ () => {
            onChange( toggleFormat( value, { type: 'my-custom/sample' } ) );
        } }
        isActive={ isActive }
    />
);

registerFormatType( 'my-custom/sample', {
    title: 'Sample Format',
    tagName: 'samp',
    className: 'my-custom-class',
    edit: MyCustomButton,
} );
```

This allows selected text to be wrapped in a custom tag such as `<samp class="my-custom-class">`.