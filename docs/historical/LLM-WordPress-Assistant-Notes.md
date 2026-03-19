> **SUPERSEDED** - This document describes an early design (Dispatcher/Generator/Transformer/Executor, REST-only approval flow) that was never implemented. The project evolved to an Abilities API-native, dual-backend architecture. See `STATUS.md` for the current inventory. Kept for historical reference only.

---

Yes — for that kind of agent system, I mean an LLM-powered assistant that sits inside Gutenberg and the Site Editor, understands the current editing context, and produces **recommendations** plus controlled actions for blocks, patterns, templates, and interactive behavior.[1][2][3]
The key is to design it as a recommendation-and-approval system first, not a fully autonomous editor, because WordPress already provides strong insertion points for patterns and templates while your agent should supply ranking, generation, and guided changes on top.[2][4][1]

## Product shape

Think of the product as four assistants in one UI: a block assistant, a pattern recommender, a template assistant, and a frontend behavior assistant.[3][1][2]
The block assistant suggests edits to the current block tree, the pattern recommender proposes reusable layouts, the template assistant helps with page-level or template-part structure, and the behavior assistant generates Interactivity API-ready code for blocks that need richer frontend logic.[4][1][2][3]

## Recommendation engine

For recommendations, you want retrieval before generation: inspect the current post type, selected blocks, nearby block tree, theme styles, available patterns, and template context, then rank candidate patterns or template changes before asking the LLM to explain or adapt them.[1][4]
WordPress patterns are especially good targets because they are a native curated editing mechanism, can be registered in themes and plugins, and can be prioritized for post creation or template creation based on `core/post-content`, post types, and `templateTypes`.[2][1]
That means your agent does not need to invent every layout from scratch; often it should recommend an existing pattern, then optionally personalize it for tone, section purpose, or brand style.[1][2]

## Theme and template help

For the Site Editor, structure recommendations around use cases like “homepage hero,” “404 template,” “archive intro,” “footer CTA,” or “product comparison section,” because template creation in WordPress can already surface patterns associated with specific `templateTypes`.[1]
Your agent can take advantage of that by generating or selecting template-scoped patterns and registering them through `register_block_pattern()`, or by shipping theme/plugin patterns in a `/patterns` directory for block themes.[2]
This creates a strong loop: the assistant watches context, recommends a pattern or template variant, previews it, and only applies it after user approval.[4][2][1]

## Editor UX

The best UX is a right-hand AI panel plus contextual actions in block toolbars and inserters.[4][1]
For example, when a user selects a Group block, the assistant can suggest “turn this into a feature grid,” “extract as a reusable pattern,” or “match this section to the site’s existing hero style,” using editor state from `@wordpress/data` to know what is selected and where it sits in the hierarchy.[4]
For new posts and templates, you can also inject AI-curated starter choices by ranking registered patterns that fit the current post type or template type, then explaining why each one matches the user’s goal.[1]

## Implementation model

A good internal contract is to have the LLM return one of a few typed outputs: `recommend_patterns`, `recommend_template`, `generate_pattern`, `transform_selected_blocks`, or `generate_interactivity_module`.[3][2][4]
When the output is a new pattern, register it with WordPress using `register_block_pattern()` and include properties such as `title`, `content`, `description`, `categories`, `keywords`, and, where relevant, `templateTypes`.[2][1]
When the output includes interactive frontend behavior, emit a block configuration with interactivity support enabled and use `viewScriptModule` with the Interactivity API, because that is the standard WordPress path for interactive blocks.[3]

## Practical plugin blueprint

I would structure the plugin like this:  
- `agent-store/`: custom data store for session state, recommendations, approvals, and errors, built around WordPress data patterns.[4]
- `context/`: collectors for selected blocks, pattern inventory, template type, theme metadata, style tokens, screenshots, and media selections.[5][6][7][4]
- `agents/`: planner, recommender, generator, validator, and executor services.[8]
- `ui/`: sidebar, inserter suggestions, block toolbar actions, pattern preview modal, and diff/approval flows.[1][4]
- `registry/`: pattern registration and theme/plugin pattern packaging, including starter patterns for post and template creation.[2][1]
- `interactive/`: templates for `block.json`, `render.php`, and `viewScriptModule` outputs when the recommendation requires dynamic frontend behavior.[3]

The most powerful version of this system is not “AI writes a theme”; it is “AI continuously recommends the next best block, pattern, template, or interaction based on editor context, while WordPress remains the execution engine.”[2][4][1]
Would you like me to turn this into a concrete plugin architecture with folders, REST routes, data stores, and example agent JSON schemas?

Sources
[1] LLM Friendly – WordPress plugin https://wordpress.org/plugins/llm-friendly/
[2] How We Built a Block Theme for Sensei LMS https://senseilms.com/how-we-built-a-theme-for-sensei/
[3] How I did setup a OpenSource LLM Model in my Wordpress Blog https://www.reddit.com/r/Wordpress/comments/1gk3u05/how_i_did_setup_a_opensource_llm_model_in_my/
[4] MW LLMS Index – WordPress plugin https://wordpress.org/plugins/mw-llm-index/
[5] How does the LLM receive information about existing patterns and pages https://www.perplexity.ai/search/96c6bba1-5073-4195-9295-7d58dad06cd0
[6] Integrate with the Wordpress media library also https://www.perplexity.ai/search/7fe05d3c-cf51-4a13-a27b-21e37cecc80a
[7] Create a streamlined process for the LLM to have visual context https://www.perplexity.ai/search/7323ff70-c050-4dac-a63e-ee307f87d0a5
[8] Agentic RAG https://www.perplexity.ai/search/33857463-dd9b-4f2d-bc21-823e1924d164
[9] AI-Powered Tarot Reading App Prototype: Tools, Platforms & Steps.pdf https://ppl-ai-file-upload.s3.amazonaws.com/web/direct-files/collection_7b7133b6-3d0a-4d94-9805-4d306c951df6/53c41b15-18de-4d71-9d24-10c4863c202c/AI-Powered-Tarot-Reading-App-Prototype-Tools-Platforms-Steps.pdf
[10] 在 IIS 8.5 中使用追蹤對失敗的要求進行疑難解答 - Internet Information Services https://learn.microsoft.com/zh-tw/troubleshoot/developer/webapps/iis/health-diagnostic-performance/troubleshoot-failed-requests-using-tracing-in-iis-85
[11] ベスト プラクティス (Dynamics 365 Customer Engagement (on-premises) の開発者ガイド) https://learn.microsoft.com/ja-jp/dynamics365/customerengagement/on-premises/developer/best-practices-sdk?view=op-9-1
[12] Microsoft.ApiManagement/service/apis/operations - Bicep, ARM template & Terraform AzAPI reference https://learn.microsoft.com/en-us/azure/templates/microsoft.apimanagement/service/apis/operations
[13] Tutorial: Manually install WebLogic Server on Azure Virtual ... https://learn.microsoft.com/en-us/azure/developer/java/migration/migrate-weblogic-to-azure-vm-manually
[14] Orquestrar notebooks e modularizar código em notebooks https://learn.microsoft.com/pt-br/azure/databricks/notebooks/notebook-workflows
[15] win UI 3 - Load DataGrid from DataTable - Microsoft Q&A https://learn.microsoft.com/en-us/answers/questions/849470/win-ui-3-load-datagrid-from-datatable
[16] Account confirmation and password recovery in ASP.NET Core https://learn.microsoft.com/en-us/aspnet/core/security/authentication/accconfirm?tabs=aspnetcore2x&view=aspnetcore-8.0&viewFallbackFrom=aspnetcore-2.1
[17] Power BI visuals display warning icon - Power BI https://learn.microsoft.com/en-us/power-bi/developer/visuals/visual-display-warning-icon
[18] Drag and Drop via ReorderList (C#) https://learn.microsoft.com/en-us/aspnet/web-forms/overview/ajax-control-toolkit/reorderlist/drag-and-drop-via-reorderlist-cs
[19] HandlerThread.Looper Property (Android.OS) https://learn.microsoft.com/ja-jp/dotnet/api/android.os.handlerthread.looper?view=net-android-34.0
[20] Access denied when executing Invoke-CimMethod and ... https://learn.microsoft.com/en-us/answers/questions/231579/access-denied-when-executing-invoke-cimmethod-and
[21] Troubleshoot broken references - Visual Studio https://learn.microsoft.com/en-us/troubleshoot/developer/visualstudio/project-build/troubleshooting-broken-references?view=vs-2019
[22] The type 'VBE' is defined in an assembly that is not referenced. You must add a reference to assmblt 'Microdoft.Vbe.Interop'... - Microsoft Q&A https://learn.microsoft.com/en-us/answers/questions/537901/the-type-vbe-is-defined-in-an-assembly-that-is-not
[23] Microsoft 認定: Power Automate RPA Developer Associate - Certifications https://learn.microsoft.com/ja-jp/credentials/certifications/power-automate-rpa-developer-associate/
[24] Machine Learning - Create a Machine Learning Prediction System Using AutoML https://learn.microsoft.com/en-us/archive/msdn-magazine/2019/july/machine-learning-create-a-machine-learning-prediction-system-using-automl
[25] Master WordPress.com Block Patterns https://developer.wordpress.com/docs/guides/block-patterns/
[26] Patterns – Block Editor Handbook - WordPress Developer Resources https://developer.wordpress.org/block-editor/how-to-guides/curating-the-editor-experience/patterns/
[27] Use block patterns – WordPress.com Support https://wordpress.com/support/wordpress-editor/block-pattern/
[28] Introduction to block patterns - Full Site Editing https://fullsiteediting.com/lessons/introduction-to-block-patterns/
[29] WordPress block pattern, reusable block, or template part - GoDaddy https://www.godaddy.com/resources/skills/wordpress-block-pattern-reusable-block-or-template-part
[30] WordPress Interactivity API Explained - Misha Rudrastyh https://rudrastyh.com/gutenberg/interactivity-api.html
[31] register_block_pattern() – Function - WordPress Developer Resources https://developer.wordpress.org/reference/functions/register_block_pattern/
[32] Patterns – Block Editor Handbook - WordPress Developer Resources https://developer.wordpress.org/block-editor/reference-guides/block-api/block-patterns/
[33] Unlock new possibilities with WordPress Interactivity API - Kinsta https://kinsta.com/blog/wordpress-interactivity-api/
[34] register_block_pattern() WP 5.5.0 https://wp-kama.com/function/register_block_pattern
[35] WordPress Block Theme Development Cheat Sheet Full Site Editor ... https://www.youtube.com/watch?v=uUBknmjVZZg
[36] Getting started with the Interactivity API | 10up - WP Block Editor Best ... https://gutenberg.10up.com/guides/interactivity-api-getting-started/
[37] #7 Register Block Patterns Advanced WordPress Plugin Development https://www.youtube.com/watch?v=iMc1_YinPzs
[38] Comprehensive Guide to WordPress Block Templates - HackMD https://hackmd.io/@sUKciQebSk2Qp7w4YV1q_g/HkUEuZvSR
[39] A Beginner's Guide to the WordPress Interactivity API - WPShout https://wpshout.com/wordpress-interactivity-api/

Yes — I would scaffold the project as an LLM-assisted recommendation system for Gutenberg and the Site Editor, but I would shape it around the WordPress 7.0 editor changes in your notes so it stays compatible with `contentOnly` patterns, collaboration, iframed editors, DataViews, and the newer Interactivity API behavior.[1][2][3][4][5]
That means the assistant should recommend and preview blocks, patterns, templates, overlays, and interactive behaviors through typed operations, while WordPress data stores and editor APIs remain the authority that reads state and applies approved changes.[2][3][6]

## Project layout

I would split the plugin into five modules: editor state, agent orchestration, recommendation registry, approval/execution, and frontend interactivity, because WordPress already gives you a strong state/action model through `@wordpress/data`, native pattern registration, and block interactivity primitives.[3][5][2]
I would also keep patterns, template parts, and overlays as first-class project assets instead of treating everything as transient AI output, because WordPress 7.0 expands `contentOnly` editing for unsynced patterns and template parts and adds customizable navigation overlays as template-part-driven structures.[1]

- `inc/Editor/Settings.php`: manages editor settings, including a safe feature flag for `disableContentOnlyForUnsyncedPatterns` during testing, because unsynced patterns now default to `contentOnly` editing and plugins that touch pattern state need extra testing there.[1]
- `inc/REST/Agent_Controller.php`: exposes endpoints like `recommend_patterns`, `recommend_template`, `transform_selected_blocks`, and `generate_overlay`, while leaving block insertion and post mutation to validated editor actions.[5][2]
- `inc/Registry/Pattern_Registry.php`: registers bundled and AI-approved patterns with `register_block_pattern()` and supports `blockTypes` and `templateTypes` so recommendations can be scoped to post creation, template creation, or overlay editing contexts.[4][5][1]
- `inc/Theme/Overlay_Support.php`: manages navigation overlays as template parts in the `navigation-overlay` area, because WordPress 7.0 expects that area and can scope overlay patterns to `core/template-part/navigation-overlay`.[1]
- `src/store/`: holds your custom data store for recommendation sessions, approvals, pending operations, and errors, which fits naturally with the selector/action model in `@wordpress/data`.[2]
- `src/dataviews/`: renders recommendation lists, approval queues, and activity history using DataViews layouts, which is a good match now that WordPress 7.0 adds activity layouts, richer grouping, and picker-table variants.[1]
- `src/interactivity/`: contains block runtime modules generated for frontend behavior, using `viewScriptModule`, `data-wp-interactive`, and the Interactivity API rather than ad hoc scripts.[3][1]

## Agent contracts

I would have the LLM return only typed operations, not raw freeform edits, because the safest editor architecture is “propose, validate, approve, apply,” and that fits both WordPress data stores and your earlier agentic RAG direction.[6][2]
The core operation types should be small and explicit so they can map to Gutenberg actions, pattern registration, template-part creation, and Interactivity API output without the model directly mutating editor state.[5][2][3]

- `recommend_patterns`: ranks existing registered patterns against current post type, selected blocks, template type, and theme constraints, because patterns are already a native curated mechanism and can be scoped by creation context.[4][5]
- `generate_pattern`: returns block markup plus metadata such as title, categories, keywords, `blockTypes`, and optional `templateTypes`, which mirrors the native pattern registration shape.[4][5]
- `transform_selected_blocks`: takes selected block context from the block editor store and proposes a replacement or wrapper tree instead of editing blindly.[2]
- `recommend_template`: targets homepage, archive, 404, single, footer, and header use cases, where the Site Editor and pattern APIs already support template-oriented insertion and curation.[5][4]
- `generate_overlay`: creates mobile navigation overlays as template parts plus related overlay-only patterns, because overlays are now theme-scoped template parts with special editor behavior.[1]
- `generate_interactivity_module`: emits block configuration and runtime logic for interactive blocks, using the Interactivity API and newer `watch()` behavior where reactive subscriptions are useful.[3][1]

## WordPress 7 guardrails

Your notes change the scaffold in important ways: the assistant must understand `contentOnly` boundaries, collaboration-safe state, iframe-safe UI, and newer metadata forms like viewport-based `blockVisibility`.[1]
In practice, that means the system should treat patterns and template parts as partially constrained editing surfaces rather than assuming every nested block is always directly editable.[1]

- Add a context field like `editingSurface` with values such as `post`, `pattern`, `template-part`, `navigation-overlay`, and `contentOnly-pattern`, because unsynced patterns and template parts now open with different editing expectations.[1]
- Add a `contentEditableMap` to your block manifest index by reading `block.json` attributes marked with `"role": "content"` and `supports.contentOnly`, because those determine what remains editable inside `contentOnly` containers.[1]
- Add `supports.listView` awareness to container recommendations so suggested custom blocks can expose better List View behavior in WordPress 7.0.[1]
- Normalize `blockVisibility` parsing to accept both the old boolean form and the new viewport object, because server-side transforms and validators now need to handle both.[1]
- Keep collaborative editor values in WordPress stores, not mirrored in local `useState`, because the notes explicitly warn that local React copies break live synchronization.[1]
- Never auto-open AI modals or trigger side effects on block insertion, because in real-time collaboration those insertion-time effects would fire for all connected users.[1]
- Avoid DOM-coupled editor hacks and rely on official editor packages and SlotFill-style integrations, because the post editor moves further toward iframe-based behavior and WordPress 7.0 now decides iframe usage based on inserted blocks using block API version 3 or higher.[1]

## Editor surfaces

I would give the plugin three UI surfaces: a sidebar for recommendations, inserter augmentation for ranked patterns, and contextual block actions for transforms and extraction to patterns.[2][4]
For administration and review, I would use DataViews and DataForm instead of custom tables everywhere, because WordPress 7.0 expands grouping, activity timelines, details panels, validation, picker tables, and density controls in that area.[1]

- Sidebar: shows “recommended next blocks,” “matching patterns,” “template suggestions,” and “interactive upgrade ideas,” all driven from selectors like current selection, post type, entity type, and theme context.[4][2]
- Pattern picker: uses a DataViews `pickerTable` for candidate patterns and templates, which is a natural fit for compare-and-approve flows in the new API shape.[1]
- Approval timeline: uses the DataViews `activity` layout to display planned, validated, approved, inserted, and reverted operations as an auditable sequence.[1]
- Advanced controls: use DataForm `details`, validation rules, and `adaptiveSelect` for long model/provider lists, because those APIs are more capable in WordPress 7.0 and reduce custom form code.[1]
- Theme styling assistant: recommend `theme.json` updates for block pseudo-classes like `:hover` and `:focus-visible` instead of hardcoding CSS into generated markup, because WordPress 7.0 now supports these selectors at the block and variation level in `theme.json`.[1]

## First milestone

For the first scaffolded milestone, I would build a narrow vertical slice: recommend an existing pattern, preview it, let the user approve it, insert it, and log the operation in an activity view.[5][4][1]
After that, I would add template-part recommendations, then navigation overlays, then generated interactive blocks, because those features align directly with the WordPress 7.0 editor and theme changes in your notes.[3][1]

- Milestone 1: `recommend_patterns` plus preview/approve/insert, using your custom store and a DataViews-based approval UI.[2][4][1]
- Milestone 2: `transform_selected_blocks` with `contentOnly` awareness and safe block-tree validation before applying changes.[2][1]
- Milestone 3: `recommend_template` and `generate_overlay`, with overlay template parts and overlay-scoped patterns registered in the theme/plugin layer.[5][1]
- Milestone 4: `generate_interactivity_module`, using `watch()` and server-populated router `state.url` where needed, while avoiding deprecated `state.navigation` access.[3][1]
- Milestone 5: optional PHP-only helper blocks for simple server-rendered recommendation widgets or status panels, since WordPress 7.0 adds `supports.autoRegister` for PHP-only blocks with autogenerated inspector controls where possible.[1]

Would you like me to turn this into an actual plugin scaffold next, with folder tree, bootstrap files, REST routes, editor store definitions, and example JSON schemas for each agent operation?

Sources
[1] wordpress7devnotes.md https://ppl-ai-file-upload.s3.amazonaws.com/web/direct-files/attachments/32078708/44a812a3-e74f-4f3e-a21d-217b7e18aa67/wordpress7devnotes.md
[2] MW LLMS Index – WordPress plugin https://wordpress.org/plugins/mw-llm-index/
[3] How I did setup a OpenSource LLM Model in my Wordpress Blog https://www.reddit.com/r/Wordpress/comments/1gk3u05/how_i_did_setup_a_opensource_llm_model_in_my/
[4] LLM Friendly – WordPress plugin https://wordpress.org/plugins/llm-friendly/
[5] How We Built a Block Theme for Sensei LMS https://senseilms.com/how-we-built-a-theme-for-sensei/
[6] Agentic RAG https://www.perplexity.ai/search/33857463-dd9b-4f2d-bc21-823e1924d164


---


# Flavor Agent

LLM-powered recommendation assistant for Gutenberg and the Site Editor. Injects AI-driven suggestions directly into the native Inspector Controls (Settings and Appearance tabs) with full recursive awareness of block capabilities, theme design tokens, and page context.

## How recommendations work

### Context collection

When you select a block, the plugin reads the full editor state:

| Data | Source | Purpose |
|---|---|---|
| Block tree | `core/block-editor.getBlocks()` | Page structure context for the LLM |
| Selected blocks | `getBlock(clientId)` + `serialize()` | What the user is working on |
| Block capabilities | `core/blocks.getBlockType()` recursive | What each block CAN do (supports, attributes, styles) |
| Current values | `getBlockAttributes(clientId)` | What the block IS right now |
| Theme tokens | `getSettings().__experimentalFeatures` | Colors, fonts, spacing, shadows, layout (origin-tagged) |
| Sibling context | `getBlockOrder()` + parent chain | Surrounding blocks for flow reasoning |
| Post metadata | `core/editor.getCurrentPostType()` | Post type, template assignment |
| Pattern inventory | `__experimentalBlockPatterns` | Available patterns with categories and templateTypes |
| Plugin detection | Block namespace heuristic | WooCommerce, Jetpack, ACF, etc. |

### Block capability manifest (recursive)

For every block in the selection (and its children up to depth 8), the introspector builds a structured manifest:

```
core/group (clientId: abc123)
├── settings:
│   ├── align: [left, center, right, wide, full] → current: null
│   ├── layout: constrained, allowSwitching: true → current: null
│   └── position: sticky: true → current: null
├── styles:
│   ├── color: background ✓, text ✓, link ✓, gradients ✓ → current: { backgroundColor: "base" }
│   ├── typography: fontSize ✓, lineHeight ✓, fontFamily ✓ → current: null
│   ├── spacing: margin ✓, padding ✓, blockGap ✓ → current: null
│   ├── border: color ✓, radius ✓, style ✓, width ✓ → current: null
│   └── shadow ✓ → current: null
├── styleVariations: [default, card, rounded] → active: none
├── contentAttributes: [content]
└── innerBlocks:
    ├── core/heading → [manifest]
    ├── core/paragraph → [manifest]
    └── core/buttons → [manifest]
```

### Value suggestion pipeline

Suggestions flow through two stages:

1. **Local (instant, no LLM):** The `suggester.js` module matches the block's capabilities against theme tokens and produces baseline suggestions. A `core/group` with no padding gets "Add padding using theme spacing 40". A heading with no font size gets the theme's heading size preset.

2. **LLM-enhanced (on demand):** The `suggest-values` REST endpoint sends the manifest + tokens + page context to the LLM, which reranks, refines, and adds contextual reasoning. The LLM might say "Use the accent color here because the preceding block uses the base color, creating contrast."

### Inspector injection

Suggestions inject into the **native** Inspector tabs via `editor.BlockEdit` filter + `InspectorControls group="..."`:

- **Settings tab** (`<InspectorControls>`) — alignment, layout, position suggestions
- **Appearance tab** (`<InspectorControls group="styles">`) — color, typography, spacing overview
- **Color sub-panel** (`group="color"`) — inline chips for background/text/gradient
- **Typography sub-panel** (`group="typography"`) — font size, family, line height
- **Dimensions sub-panel** (`group="dimensions"`) — padding, margin, block gap, min-height
- **Border sub-panel** (`group="border"`) — radius, color, width

Each suggestion is a one-click "Apply" button with an "Undo" that restores the previous value.

## Architecture

```
flavor-agent/
├── flavor-agent.php              # Bootstrap, autoloader, asset enqueue
├── package.json                  # @wordpress/scripts
│
├── inc/
│   ├── Editor/
│   │   └── Settings.php          # Injects agent config + block capability index + theme metadata
│   ├── REST/
│   │   └── Agent_Controller.php  # REST endpoints including suggest-values
│   ├── Registry/
│   │   └── Pattern_Registry.php  # Auto-registers bundled patterns
│   ├── Theme/
│   │   └── Overlay_Support.php   # navigation-overlay template-part area
│   └── Agents/
│       ├── Dispatcher.php        # Routes operations, manages approval transients
│       ├── Recommender.php       # Scores + LLM-reranks patterns with full context
│       ├── Generator.php         # Creates patterns/overlays/interactivity with theme awareness
│       ├── Transformer.php       # Block tree transforms respecting contentOnly
│       ├── ValueAdvisor.php      # LLM-enhanced per-block value suggestions
│       ├── Validator.php         # Safety checks on LLM output
│       ├── Executor.php          # Applies approved operations
│       └── LLM.php              # Provider abstraction (Anthropic, OpenAI, Azure)
│
├── src/
│   ├── index.js                  # Entry — registers store, sidebar, inspector injection
│   ├── store/
│   │   ├── index.js              # @wordpress/data store (sessions, approvals, suggestions)
│   │   ├── context.js            # Full context collector (tree, tokens, plugins, patterns)
│   │   ├── introspector.js       # Recursive block capability manifest builder
│   │   └── suggester.js          # Local value suggestion engine (no LLM needed)
│   └── ui/
│       ├── inspector/
│       │   └── InspectorInjection.js  # editor.BlockEdit filter → native Inspector tabs
│       ├── sidebar/
│       │   └── AgentSidebar.js        # Right-hand panel for full LLM interactions
│       └── inserter/
│           └── PatternInserter.js     # Bridges approval → block insertion
│
├── patterns/                     # Bundled PHP pattern files
├── templates/                    # (stub) Template parts
└── assets/
    └── agent-schemas.json        # JSON Schema for all operations
```

## REST endpoints

| Endpoint | Operation | LLM? | Approval? |
|---|---|---|---|
| POST /recommend-patterns | Score + rerank patterns | Optional | Yes |
| POST /recommend-template | Template-scoped patterns | Optional | Yes |
| POST /generate-pattern | Create new pattern markup | Required | Yes |
| POST /transform-blocks | Rewrite selected blocks | Required | Yes |
| POST /generate-overlay | Navigation overlay template part | Required | Yes |
| POST /generate-interactivity | Interactivity API module | Required | Yes |
| POST /suggest-values | Per-block value recommendations | Required | No (immediate) |
| POST /approve | Execute a pending operation | — | — |

## Setup

```bash
npm install && npm start   # dev
npm run build              # production
```

```php
update_option( 'flavor_agent_llm_provider', 'anthropic' );
update_option( 'flavor_agent_llm_api_key', 'sk-ant-...' );
update_option( 'flavor_agent_llm_model', 'claude-sonnet-4-20250514' );
```

## WP 7 guardrails

- Respects `contentOnly` boundaries — only suggests content-role attributes inside locked containers
- Uses `@wordpress/data` stores exclusively — no local `useState` mirrors that break collaboration
- `editor.BlockEdit` filter is iframe-safe (runs in parent frame with official packages)
- Never auto-triggers side effects on block insertion
- Handles `blockVisibility` in boolean and viewport-object forms
- Uses theme preset slugs and CSS custom properties, not raw values
