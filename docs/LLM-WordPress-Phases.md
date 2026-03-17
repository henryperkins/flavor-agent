## Concrete plugin architecture

Yes — based on your notes, I’d build it as a **recommend → validate → approve → apply** system inside Gutenberg. The notes already give you the core pieces: a sidebar/editor plugin, a custom REST proxy with capability checks, editor-context collection from the selected block and block tree, and a safeguard layer that parses AI markup before insertion [^1][^2][^3].

### Folder tree

```text
lfd-ai-editor-agent/
├── lfd-ai-editor-agent.php
├── inc/
│   ├── class-plugin.php
│   ├── class-settings.php
│   ├── class-rest-controller.php
│   ├── class-generation-service.php
│   ├── class-validation-service.php
│   ├── class-pattern-registry.php
│   ├── class-log-service.php
│   └── class-dynamic-block-service.php
├── patterns/
│   ├── hero-cta.php
│   ├── services-grid.php
│   └── footer-cta.php
├── src/
│   ├── index.js
│   ├── plugin/register.js
│   ├── ui/Sidebar.jsx
│   ├── ui/RecommendationList.jsx
│   ├── ui/PreviewPanel.jsx
│   ├── ui/ApprovalBar.jsx
│   ├── store/index.js
│   ├── store/actions.js
│   ├── store/reducer.js
│   ├── store/selectors.js
│   ├── store/resolvers.js
│   ├── context/getSelectedBlock.js
│   ├── context/getBlockTree.js
│   ├── context/getEditedContent.js
│   ├── context/getContextPresets.js
│   ├── transforms/parseMarkup.js
│   ├── transforms/serializeBlocks.js
│   └── interactive/
│       ├── scaffoldBlockJson.js
│       ├── scaffoldEdit.js
│       ├── scaffoldRenderPhp.js
│       └── scaffoldViewScript.js
└── build/
```

Keep approved patterns as first-class assets in `patterns/` and register them on `init`; that matches the native pattern API and lets you scope recommendations with metadata like `blockTypes`, `postTypes`, and `templateTypes` [^4].

### Minimal bootstrap

```php
<?php
/**
 * Plugin Name: LFD AI Editor Agent
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LFD_AI_Editor_Agent {
    public function __construct() {
        add_action( 'init', [ $this, 'register_patterns' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor' ] );
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_patterns() {
        // Load approved files from /patterns and call register_block_pattern().
    }

    public function enqueue_editor() {
        // Enqueue build/index.js and localize REST URL + nonce.
    }

    public function register_routes() {
        // Recommend / transform / validate / promote routes.
    }
}
new LFD_AI_Editor_Agent();
```

## REST routes

| Route | Method | Purpose |
| --- | --- | --- |
| `/lfd-ai/v1/recommend` | POST | Return ranked block/pattern recommendations |
| `/lfd-ai/v1/transform` | POST | Return a draft transform for the selected block tree |
| `/lfd-ai/v1/validate` | POST | Sanitize, parse, serialize, and preview returned markup |
| `/lfd-ai/v1/promote-pattern` | POST | Promote an approved output into reusable/pattern storage |
| `/lfd-ai/v1/logs` | GET | Return prompt/response approval history |

A simple split is:

- `recommend` handles ranking and explanation
- `transform` handles draft markup generation
- `validate` is the safety gate
- `promote-pattern` handles the move from draft output to reusable asset

## Store shape with `@wordpress/data`

Centralize session state in a custom `@wordpress/data` store. The notes describe that package in terms of stores, reducers, actions, selectors, and resolvers, which is the right shape for recommendation sessions, previews, and approvals [^5].

```js
{
  status: "idle",
  context: {
    selectedBlock: null,
    blockTree: [],
    editedContent: "",
    contextPreset: null,
    patternInventory: []
  },
  recommendations: [],
  draftOperation: null,
  validation: {
    isValid: false,
    errors: [],
    serializedHtml: ""
  },
  approval: {
    approved: false,
    rejected: false
  },
  logs: [],
  error: null
}
```

Keep the context payload trimmed. Send `blockName`, `attributes`, `innerBlocks`, selected block info, current edited content, and optional presets from approved layouts such as [[Lakefront Digital Website Content Guide]], rather than dumping the full editor state [^2].

## Typed agent output schemas

The model should return **typed payloads**, not direct editor mutations.

### `recommend_patterns`

```json
{
  "type": "recommend_patterns",
  "goal": "add a services section under the hero",
  "recommendations": [
    {
      "patternName": "lfd/services-grid",
      "score": 0.94,
      "reason": "Matches grouped marketing content",
      "suggestedEdits": ["Change headings", "Swap CTA copy"]
    }
  ]
}
```

### `transform_selected_blocks`

```json
{
  "type": "transform_selected_blocks",
  "clientIds": ["abc123"],
  "label": "Turn section into feature grid",
  "reason": "Preserves copy while improving layout",
  "markup": "<!-- wp:group -->...<!-- /wp:group -->"
}
```

### `generate_pattern`

```json
{
  "type": "generate_pattern",
  "pattern": {
    "name": "lfd/feature-grid",
    "title": "Feature Grid",
    "description": "Three-column feature section with CTA",
    "categories": ["featured"],
    "keywords": ["services", "grid", "cta"],
    "blockTypes": ["core/group"],
    "templateTypes": ["page"],
    "content": "<!-- wp:group -->...<!-- /wp:group -->"
  }
}
```

### `generate_interactivity_module`

```json
{
  "type": "generate_interactivity_module",
  "block": {
    "name": "lfd/faq-toggle",
    "attributes": {
      "items": []
    },
    "editJs": "// editor scaffold",
    "renderPhp": "<?php // dynamic render scaffold",
    "viewJs": "// interactivity scaffold"
  }
}
```

For template help, use the same shape as `recommend_patterns`, but require each candidate to include `templateTypes`.

## Approval pipeline

1. Collect context with `getSelectedBlock()`, `getBlocks()`, and current edited content.
2. Call `/recommend` or `/transform`.
3. Sanitize returned markup.
4. Parse it into blocks and serialize it back for preview.
5. Show the user the exact preview.
6. On approval, apply with block-editor dispatch actions or promote the result into reusable/pattern storage.
7. Log prompt, response, and final action.

The apply pipeline should sanitize AI markup with `wp_kses_post`, parse it into blocks, serialize it back to canonical block HTML, and only then let the user insert it or save it as a pattern. If you later need to work from saved content outside the editor, the same block HTML can be deserialized from REST content and reserialized for compatible saves [^3][^8].

Before serializing in JavaScript, make sure the relevant block types are registered so the markup formats correctly [^8].

## Pattern and template promotion model

Within the notes here, the strongest template-level strategy is **pattern-driven assistance**: recommend an existing pattern first, then optionally generate a new one and register it. Approved outputs can be saved as reusable content first, and then promoted into checked-in plugin patterns for source control and team reuse [^2][^4].

That gives you a clean lifecycle:

- Draft recommendation
- Preview and approval
- Reusable internal asset
- Promoted shared pattern in `patterns/`

## Dynamic and interactive behavior

For recommendations that need runtime data, scaffold a dynamic block rather than forcing everything into static markup. The notes show the standard path: let `save` return `null`, render on the server with `render_callback`, and use `ServerSideRender` when you want a live editor preview [^6].

For richer front-end behavior like toggles, client-side filtering, or interactive UI state, generate a block scaffold that uses the Interactivity API instead of ad hoc scripts [^7].

## MVP order

- Phase 1: recommend existing patterns and insert them after approval
- Phase 2: transform the selected block subtree
- Phase 3: save approved outputs as plugin-managed patterns
- Phase 4: add dynamic block and Interactivity API scaffolds
- Phase 5: add revision history, tests, and hardened settings

Your rollout note already flags the right production hardening: API key storage and encryption, nonce/capability checks, and tests for both the REST proxy and the editor panel [^9].

If you want, the next useful step is to draft these actual files:

- `lfd-ai-editor-agent.php`
- `inc/class-rest-controller.php`
- `inc/class-pattern-registry.php`
- `src/store/index.js`
- `src/ui/Sidebar.jsx`

Note: The retrieval cap was reached — some matching documents were not included. Upgrade to Copilot Plus for more complete answers.

#### Sources
[^1]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^2]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^3]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^4]: [[Annotations Overview and Important Notice]]
[^5]: [[Extending Content Creation with the WordPress Block Editor API]]
[^6]: [[WordPress Block Editor API Useful Code Examples and Development Patterns]]
[^7]: [[Extending Content Creation with the WordPress Block Editor API]]
[^8]: [[Extending Content Creation with the WordPress Block Editor API]]
[^9]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]

---

## Phase 1: recommend existing patterns and insert them after approval

For Phase 1, keep the assistant focused on **existing block patterns**, not new generation. Patterns are pre-designed collections of blocks that users can insert and then edit independently, which makes them a strong first milestone for an approval-based workflow.[^1]

### What to build

Use a Gutenberg sidebar registered through the editor plugin system. The notes already define a solid starting UI: a prompt field, quick templates, context toggles, and a preview area, with the editor client calling a custom REST endpoint.[^2][^3]

For this phase, that sidebar should do four things:
- Show recommended existing patterns
- Preview the selected pattern
- Require explicit approval
- Insert only after approval

### What context to send

Send a trimmed editor context to the backend:
- The selected block
- A simplified block tree
- Optional current edited content
- An optional pattern slug or template name

The notes specifically suggest collecting block structure with `select( 'core/block-editor' ).getBlocks()`, passing selected-block data from the block editor store, and optionally including content from `getEditedPostAttribute( 'content' )` or the Patterns REST endpoint. They also suggest context presets so recommendations can align with established layouts.[^3][^4]

### Recommendation and approval flow

In this phase, recommend from patterns that already exist. The note explicitly suggests listing already defined patterns, pulling their markup, and reusing those layouts as the basis for assistant-driven suggestions.[^5]

Once the user chooses a pattern:
1. Prepare the pattern markup
2. Validate it with `wp.blocks.parse`
3. Show the exact preview using `wp.blocks.serialize`
4. Insert only after approval with `dispatch( 'core/block-editor' ).insertBlocks`

Before insertion, strip disallowed HTML with `wp_kses_post`, and keep a revision log of the prompt and response as JSON so the action can be audited or reverted.[^6][^7]

### Keep Phase 1 narrow

Only do these things:
- Recommend existing patterns
- Preview the pattern
- Insert after explicit approval
- Log the action

Do not add yet:
- New pattern generation
- Nested pattern remixing
- “Save as Pattern”
- Pattern promotion into plugin files

### What comes next

The next phase can add a “Save as Pattern” toggle using `createReusableBlock` or a REST call to `/wp/v2/patterns`, then store approved outputs as plugin-managed patterns with `register_block_pattern()` so they stay source-controlled.[^5] When you reach that stage, use strong metadata like `title`, `description`, `categories`, and optionally `blockTypes`, `postTypes`, or `templateTypes`; `register_block_pattern()` should be registered on `init`.[^8]

### Phase 1 acceptance criteria

- Sidebar works inside Gutenberg
- Current block context is captured
- Existing patterns are recommended
- A preview is shown before insertion
- Nothing inserts automatically
- Markup is sanitized and validated
- Approved actions are logged

This gives you a clean first slice: **recommend existing patterns, preview them, and insert only after approval**.

#### Sources
[^1]: [[WordPress Developer Guide Best Practices and Advanced Techniques]]
[^2]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^3]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^4]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^5]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^6]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^7]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^8]: [[Annotations Overview and Important Notice]]

---

How exactly does the LLM form its recommendation? What context from the content in the block editor, current page / templates used / current theme/plugins activated ?

---

## How the LLM forms its recommendation

The recommendation is formed from a **structured context packet** that the Gutenberg plugin assembles and sends to the model through a custom REST endpoint. In your notes, the editor-side code pulls data from Gutenberg stores, and the backend proxy adds prompt instructions before sending that payload to the AI provider.[^1][^2]

### What context from the block editor gets included

At the editor level, the most important inputs are:

- The **currently selected block**
- The **surrounding block tree**
- A trimmed JSON version of that tree with:
  - `blockName`
  - `attributes`
  - `innerBlocks`
- Optionally, the **current edited content** of the post/page

That comes directly from selectors like `select( 'core/block-editor' ).getSelectedBlock()` and `select( 'core/block-editor' ).getBlocks()`, plus current content from `getEditedPostAttribute( 'content' )`. Because WordPress block content can be parsed into structured block objects, the model can reason about both the visible content and the layout hierarchy, not just raw HTML.[^1][^3]

### What context from the current page or templates gets included

Yes — the notes explicitly support sending **page/template context**.

The plugin can let the user attach a **pattern slug** or **template name**, fetch that markup from the current editor content or the Patterns REST endpoint, and include it in the prompt. The notes also recommend including **current template-part markup** when helpful. On top of live editor data, they suggest optional **context presets** for known layouts, such as a hero or services section, so the model can extend a familiar structure instead of starting from scratch.[^1][^4]

### What context from the current theme gets included

Yes — theme context is also explicitly part of the design.

The notes mention:
- Context toggles for **global styles**
- Auto-including the **`theme.json` palette**
- Using prompt templates that respect existing **design tokens**

So the model is supposed to recommend something that fits the current site’s styling system, not just generic blocks or layouts.[^4]

### What about block hierarchy and inherited context?

Gutenberg also has **block context**, where ancestor blocks can provide values to descendant blocks within the same hierarchy. That matters because the same selected block can mean different things depending on where it sits in the tree and what parent context it inherits.[^5]

### What about currently activated plugins?

From the notes, the model is not primarily driven by a raw list of active plugins. The more relevant signal is the set of **registered blocks and patterns** available in the editor. WordPress needs block types to be registered for parsing, serialization, and broader API access to work correctly, so plugin influence shows up indirectly through the blocks, patterns, and metadata those plugins register.[^3][^6]

## Practical summary

So, in this architecture, the LLM recommendation should be based on:

- The selected block
- The nearby block tree
- Block attributes and nested structure
- The current page/post content
- Attached pattern or template markup
- Current template-part markup
- Global styles and `theme.json` palette
- Available registered blocks/patterns
- Optional curated layout presets

A simple mental model is:

```json
{
  "selectedBlock": {},
  "blockTree": [],
  "editedContent": "",
  "patternOrTemplateMarkup": "",
  "templatePartMarkup": "",
  "theme": {
    "includeGlobalStyles": true,
    "palette": []
  }
}
```

If you want, I can turn this into a **concrete context schema for Phase 1 pattern recommendation** inside Gutenberg.

#### Sources
[^1]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^2]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^3]: [[Extending Content Creation with the WordPress Block Editor API]]
[^4]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^5]: [[Annotations Overview and Important Notice]]
[^6]: [[Extending Content Creation with the WordPress Block Editor API]]

---

## Recommended Phase 1 context schema

For Phase 1, I’d keep the payload focused on **ranking existing patterns**, not generating new ones. The context packet should combine:

- the current editor content in both raw and parsed block form
- the current selection
- optional template-part and theme style context
- the inventory of already registered patterns
- explicit constraints that force **existing patterns only**

WordPress content already maps well to this shape because block content can be represented as raw markup and also as parsed block objects with `blockName`, `attributes`, `innerBlocks`, and `innerHTML`.[^1] Your notes also support including selected block attributes, global styles or theme tokens, current template-part markup, and existing pattern markup for reuse/recommendation.[^2][^3] Pattern entries should mirror the metadata used when patterns are registered, especially `title`, `content`, `description`, `categories`, `keywords`, `viewportWidth`, `blockTypes`, `inserter`, and, where you use it, `postTypes`.[^4]

```json
{
  "schemaVersion": "1.0",
  "mode": "recommend_existing_patterns",
  "intent": {
    "prompt": "Add a CTA section below this intro",
    "surface": "post",
    "entityType": "page",
    "requiresApproval": true
  },
  "editorContext": {
    "selectedBlock": {
      "blockName": "core/group",
      "attributes": {
        "layout": {
          "type": "constrained"
        }
      },
      "innerBlocks": [],
      "innerHTML": "<div class=\"wp-block-group\"></div>"
    },
    "document": {
      "rawMarkup": "<!-- wp:heading --><h2>Welcome</h2><!-- /wp:heading -->",
      "blocks": [
        {
          "blockName": "core/heading",
          "attributes": {
            "level": 2
          },
          "innerBlocks": [],
          "innerHTML": "<h2>Welcome</h2>"
        }
      ]
    }
  },
  "templateContext": {
    "currentTemplatePart": null,
    "availableTemplateParts": [
      {
        "name": "site-header",
        "title": "Header",
        "area": "header"
      },
      {
        "name": "site-footer",
        "title": "Footer",
        "area": "footer"
      }
    ]
  },
  "themeContext": {
    "globalStyles": {
      "styles": {
        "color": {},
        "spacing": {},
        "typography": {},
        "blocks": {}
      }
    },
    "patternSlugs": ["partner-logos"],
    "styleVariations": [
      {
        "slug": "variation-a",
        "blockTypes": ["core/group", "core/columns", "core/media-text"]
      }
    ]
  },
  "availableBlockTypes": ["core/group", "core/columns", "core/buttons"],
  "patternInventory": [
    {
      "name": "my-plugin/two-buttons",
      "source": "plugin",
      "title": "Two Buttons - Call to Action",
      "description": "Two horizontal buttons, primary and secondary.",
      "categories": ["buttons", "featured"],
      "keywords": ["cta", "buttons"],
      "viewportWidth": 1200,
      "blockTypes": ["core/group"],
      "postTypes": ["page"],
      "inserter": true,
      "content": "<!-- wp:buttons -->...<!-- /wp:buttons -->"
    }
  ],
  "constraints": {
    "existingPatternsOnly": true,
    "respectThemeTokens": true,
    "maxResults": 5,
    "returnReasons": true
  }
}
```

## Field rules

- `editorContext.selectedBlock` and each item in `editorContext.document.blocks` should use the parsed block shape:
  - `blockName`
  - `attributes`
  - `innerBlocks`
  - `innerHTML`[^1]

- `editorContext.document.rawMarkup` should be the current page/post content as block HTML, so the model can reason about the exact saved structure as well as the parsed structure.[^1]

- `templateContext.currentTemplatePart` should be `null` unless the user is editing a template-part-aware surface. If it is set, include:
  - `name`
  - `title`
  - `area`
  - `markup`

- `themeContext.globalStyles` should be a **reduced** subset of `theme.json`, mainly the parts relevant to matching patterns: color, spacing, typography, and any block-specific styles.[^5]

- `themeContext.patternSlugs` should include theme-declared Pattern Directory slugs if the theme registers them that way, and `styleVariations` should capture variation slugs plus the `blockTypes` they apply to.[^6][^7]

- `patternInventory[]` should contain only patterns that are actually available in the current site and should mirror pattern registration metadata as closely as possible.[^4]

## How the LLM should use it

For Phase 1, the model should recommend patterns in this order:

1. Filter by hard rules:
   - `existingPatternsOnly === true`
   - `inserter === true`
   - `postTypes` matches the current entity type
   - `blockTypes` is compatible with the current selection, if present[^4]

2. Compare each candidate pattern’s `content`, `title`, `description`, `categories`, and `keywords` against:
   - the selected block
   - the full page’s parsed block tree
   - the user prompt

3. Boost patterns that fit the current template context and the current site style system:
   - matching `templateContext.currentTemplatePart.area`
   - fitting current `themeContext.globalStyles`
   - aligning with relevant style variations and block types[^5][^7]

4. Return a short ranked list with:
   - pattern name
   - score
   - one-sentence reason
   - original pattern `content` for preview/approval

## Suggested LLM response shape

```json
{
  "type": "recommend_patterns",
  "recommendations": [
    {
      "name": "my-plugin/two-buttons",
      "score": 0.91,
      "reason": "Fits the current CTA need and matches the available button/group styling.",
      "content": "<!-- wp:buttons -->...<!-- /wp:buttons -->"
    }
  ]
}
```

## One practical note on theme/plugins

For Phase 1, I would **not** send a raw `activePlugins` list unless you have a very specific reason. It’s usually cleaner to reflect theme/plugin effects through:

- `patternInventory`
- `availableBlockTypes`
- `templateContext`
- `themeContext`

That keeps the recommendation packet tied to what Gutenberg can actually insert and style.

If you want, I can turn this next into:
- a **TypeScript interface**
- a **PHP REST request schema**
- or the **exact prompt template** that consumes this payload

#### Sources
[^1]: [[Extending Content Creation with the WordPress Block Editor API]]
[^2]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^3]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^4]: [[WordPress Block Editor API Useful Code Examples and Development Patterns]]
[^5]: [[Global Settings and Styles Theme JSON in WordPress 5 8]]
[^6]: [[Global Settings and Styles Theme JSON in WordPress 5 8]]
[^7]: [[Global Settings and Styles Theme JSON in WordPress 5 8]]

---

## Phase 2: transform the selected block subtree

Phase 2 should narrow the AI to one controlled job: inspect the current selection and its nested children, propose a replacement subtree, preview the exact block result, and apply it only after approval [^1][^2].

### Recommended workflow

- Add a sidebar action like **Transform selected structure**
- Ask for a structural instruction such as “turn this group into a 3-column feature grid with buttons”
- Collect context from Gutenberg:
  - `select( 'core/block-editor' ).getSelectedBlock()`
  - `select( 'core/block-editor' ).getBlocks()`
  - optional current edited content
  - optional attached pattern slug or template name plus markup [^1][^5]
- If style fidelity matters, include the existing “include global styles” toggle and send a reduced `theme.json` styles snapshot covering color, spacing, typography, and block-specific styles [^3][^4]
- Post the request through the plugin REST endpoint with `wp.apiFetch` [^5]
- Have the model return either block JSON in `InnerBlocks` format or raw Gutenberg block markup [^2]

### Concrete request schema

```json
{
  "mode": "transform_selected_subtree",
  "prompt": "Turn this section into a 3-column feature grid with buttons",
  "context": {
    "selectedBlock": {
      "blockName": "core/group",
      "attributes": {},
      "innerBlocks": []
    },
    "documentTree": [
      {
        "blockName": "core/group",
        "attributes": {},
        "innerBlocks": []
      }
    ],
    "editedContent": "",
    "attachedPatternOrTemplate": {
      "type": null,
      "name": null,
      "markup": null
    },
    "includeGlobalStyles": true,
    "themeStyles": {
      "styles": {
        "color": {},
        "spacing": {},
        "typography": {},
        "blocks": {}
      }
    }
  }
}
```

`selectedBlock` and `documentTree` should be trimmed to `blockName`, `attributes`, and `innerBlocks`, because that is the structural context the AI needs [^1].

### Supported response formats

```json
{
  "type": "transform_selected_subtree",
  "format": "innerBlocks-json",
  "blocks": [
    {
      "blockName": "core/group",
      "attributes": {},
      "innerBlocks": []
    }
  ]
}
```

```json
{
  "type": "transform_selected_subtree",
  "format": "block-markup",
  "markup": "<!-- wp:group -->...<!-- /wp:group -->"
}
```

If you use raw block markup, keep it in standard Gutenberg block HTML so it can be parsed back into `blockName`, `attributes`, `innerBlocks`, and `innerHTML` [^6].

### Validation and approval

Run the returned markup through `wp.blocks.parse`, then `wp.blocks.serialize`, and show that serialized result in the preview before committing. After approval, insert the parsed blocks with `dispatch( 'core/block-editor' ).insertBlocks` [^2]. Keep the draft transform, validation result, and approval state in a small `@wordpress/data` store so the flow stays predictable [^8].

### Optional pattern-first fallback

Before calling the LLM, you can check whether an existing contextual pattern already matches the selection. Patterns attached to `blockTypes` can appear as transforms for simple blocks, and for a contextual pattern to be suggested, every selected block must be present in that pattern [^7].

### Phase 2 acceptance criteria

- User can select a block or nested group and request a structural transform
- Request includes selected block data plus a trimmed block tree
- LLM returns a draft subtree as block JSON or block markup
- Result is parsed, serialized, and previewed before commit
- Nothing applies automatically
- Approved output is inserted into the editor
- Existing contextual patterns can be used as a first-pass transform option

If you want, I can turn this into:
1. the exact REST request/response contract
2. the React sidebar flow
3. the parse/preview/insert code path

#### Sources
[^1]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^2]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^3]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^4]: [[Global Settings and Styles Theme JSON in WordPress 5 8]]
[^5]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^6]: [[Extending Content Creation with the WordPress Block Editor API]]
[^7]: [[Annotations Overview and Important Notice]]
[^8]: [[Extending Content Creation with the WordPress Block Editor API]]

---

## Phase 3: save approved outputs as plugin-managed patterns

Phase 3 should add a **promotion step**: once an AI result has already been previewed and approved, promote it from a temporary editor result into a reusable, source-controlled pattern owned by the plugin. Your notes explicitly recommend a **“Save as Pattern”** flow and say approved AI outputs should be stored as plugin pattern files registered with `register_block_pattern()`.[^1] For discoverability and reuse, the pattern should also carry clear metadata like `title`, `description`, `categories`, and, when helpful, `blockTypes` or `postTypes`.[^2]

### Recommended workflow

- User approves a transformed section or generated subtree in the sidebar
- Normalize the approved blocks into standard Gutenberg block HTML using `wp.blocks.serialize( blocks )`, since WordPress stores block content as block-delimited HTML with serialized attributes.[^3]
- Package the result as a pattern definition with metadata
- Promote that approved definition into a plugin-managed pattern registered with `register_block_pattern()`
- Expose it in the inserter only in relevant contexts

### Minimal pattern payload

```json
{
  "name": "lfd/services-grid",
  "title": "Services Grid",
  "description": "Three-column services section with CTA",
  "categories": ["featured"],
  "keywords": ["services", "grid", "cta"],
  "blockTypes": ["core/group"],
  "postTypes": ["page"],
  "content": "<!-- wp:group -->...<!-- /wp:group -->"
}
```

The `content` field should be Gutenberg comment-format block markup, and the AI should already be instructed to respect existing design tokens so the saved pattern stays aligned with the site’s theme styling.[^4]

### How it fits into the plugin

Use the same structure from the earlier phases:

- **Editor UI**: sidebar control inside the Gutenberg plugin
- **Backend**: plugin endpoint to handle the promote/save action
- **Security**: nonce validation, capability checks, and sanitized settings/inputs[^5]

If you want a staged workflow, the approval step can first create a reusable block with `wp.data.dispatch( 'core/block-editor' ).createReusableBlock` or use `/wp/v2/patterns`, and then the final approved version can be promoted into a plugin-managed pattern so it remains source-controlled.[^1]

### Guardrails

- Keep patterns as **reusable starting points**, not giant one-off layouts; the notes recommend clear titles/descriptions and warn against overly large or overly specific patterns.[^2]
- Treat patterns as **static inserts**. If the approved result depends on live data, use a dynamic block with PHP `render_callback`, or pair the pattern with interactive/dynamic blocks instead of freezing that behavior into the pattern itself.[^2][^6][^7]
- Be careful when saving markup for evolving custom blocks: if a block’s saved structure changes later, Gutenberg can flag older saved content as invalid unless you provide deprecated versions or migration paths.[^8]

### Phase 3 acceptance criteria

- Approved output can be promoted into a plugin-managed pattern
- Pattern content is stored as valid Gutenberg block HTML
- Pattern metadata includes at least title, description, categories, and content
- Pattern visibility can be constrained with `blockTypes` and `postTypes` where appropriate
- Final approved patterns are source-controlled through plugin registration
- Dynamic behavior is handled by blocks, not hardcoded into static pattern markup

#### Sources
[^1]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^2]: [[WordPress Block Editor API Useful Code Examples and Development Patterns]]
[^3]: [[Extending Content Creation with the WordPress Block Editor API]]
[^4]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^5]: [[Building a WordPress Plugin for AI-Powered Code Generation in Gutenberg Editor]]
[^6]: [[Extending Content Creation with the WordPress Block Editor API]]
[^7]: [[Extending Content Creation with the WordPress Block Editor API]]
[^8]: [[WordPress Block Editor API Useful Code Examples and Development Patterns]]
