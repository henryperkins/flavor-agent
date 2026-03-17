# Flavor Agent

LLM-powered recommendation assistant for Gutenberg and the Site Editor. Injects AI suggestions directly into the native Inspector sidebar tabs (Settings + Appearance) with full block capability awareness and theme-specific value recommendations.

## How it works

**Per-block (Inspector injection):** Select any block → AI analyzes its supports, attributes, registered styles, and your theme's design tokens → suggestions appear in the native Settings and Appearance tabs alongside WordPress's own controls. Click "Apply" on any suggestion to update the block instantly.

**Page-level (sidebar):** Open the AI Assistant sidebar → describe what you need → the system ranks registered patterns against your page context, post type, template, and theme → approve to insert.

## Architecture

```
flavor-agent/
├── flavor-agent.php              # Bootstrap, autoloader, editor asset enqueue
├── package.json                  # @wordpress/scripts build
│
├── inc/                          # PHP — server side
│   ├── Editor/Settings.php       # Injects agent config + contentOnly hints into editor settings
│   ├── REST/Agent_Controller.php # REST routes: recommend-block, recommend-patterns, approve, etc.
│   ├── Registry/Pattern_Registry.php  # Auto-registers bundled patterns from /patterns
│   ├── Theme/Overlay_Support.php      # WP 7 navigation-overlay template-part area
│   └── Agents/
│       ├── Dispatcher.php        # Routes operations to handlers, manages approval transients
│       ├── Block_Recommender.php # ★ Core: produces per-block, per-tab suggestions
│       ├── Recommender.php       # Ranks patterns/templates against page context
│       ├── Generator.php         # Creates new patterns, overlays, interactivity via LLM
│       ├── Transformer.php       # Proposes block-tree transforms (contentOnly-aware)
│       ├── Validator.php         # Validates LLM output (markup safety, schema)
│       ├── Executor.php          # Applies approved operations
│       └── LLM.php              # Pluggable provider (Anthropic, OpenAI, Azure)
│
├── src/                          # JS — editor side
│   ├── index.js                  # Entry: registers store, Inspector filter, sidebar, inserter
│   │
│   ├── introspection/            # ★ Block + theme analysis engine
│   │   ├── index.js
│   │   ├── block-introspector.js # Recursive block tree introspection, capability manifests
│   │   └── theme-tokens.js      # Design token collection from theme.json + global styles
│   │
│   ├── inspector/                # ★ Native Inspector tab injection
│   │   ├── InspectorInjector.js  # editor.BlockEdit filter HOC — injects into all blocks
│   │   ├── SettingsRecommendations.js  # Settings tab: layout, position, advanced
│   │   └── StylesRecommendations.js    # Appearance tab: colors, type, spacing, variations
│   │
│   ├── store/
│   │   ├── index.js              # @wordpress/data store (per-block, per-tab state)
│   │   └── context.js            # Context collector: block tree, tokens, selection, patterns
│   │
│   └── ui/
│       ├── sidebar/AgentSidebar.js    # Page-level pattern/template recommendations
│       └── inserter/PatternInserter.js # Bridges approve flow → block insertion
│
├── patterns/hero-section.php     # Bundled starter pattern
└── assets/agent-schemas.json     # JSON Schema for all operation types
```

## What the LLM sees

When you click "Get Suggestions" on a block, the system sends the LLM:

**Block manifest:**

- Block name, title, category
- `inspectorPanels` — which panels this block exposes (color, typography, dimensions, border, shadow, layout, position, advanced)
- `currentAttributes` — every attribute and its current value
- `styles` — registered style variations with active/default flags
- `contentAttributes` / `configAttributes` — separated by `role: content`
- `editingMode` — whether contentOnly constraints apply
- `isInsideContentOnly` — derived lock state from ancestor containers
- `blockVisibility` — mirrored current visibility state from `currentAttributes.metadata.blockVisibility`

**Theme design tokens:**

- Color palette with hex values and CSS vars (`accent: #4f46e5`)
- Font sizes with fluid values (`large: clamp(1.25rem, 2vw, 1.75rem)`)
- Font families with full stacks (`heading: 'Inter', sans-serif`)
- Spacing scale with preset references (`40: 1.5rem`)
- Shadow presets, border settings, layout constraints
- Which features are enabled/disabled (custom colors, line height, drop cap, etc.)

**Surrounding context:**

- Sibling blocks before and after
- Page context (post type, template, title)

The LLM returns suggestions scoped to specific Inspector panels with exact attribute updates using theme preset slugs and CSS variables — not raw values.

## Inspector injection detail

The plugin uses the `editor.BlockEdit` filter with `createHigherOrderComponent` to wrap every block's edit component. It injects `<InspectorControls>` with different `group` props to target specific tabs and panels:

| Target           | `group` prop   | What renders                                          |
| ---------------- | -------------- | ----------------------------------------------------- |
| Settings tab     | _(default)_    | AI Recommendations panel + settings suggestions       |
| Appearance tab   | `"styles"`     | Style variation pills + general style suggestions     |
| Color panel      | `"color"`      | Color preset chips inside the native Color ToolsPanel |
| Typography panel | `"typography"` | Font size/family chips inside Typography ToolsPanel   |
| Dimensions panel | `"dimensions"` | Spacing chips inside Dimensions ToolsPanel            |
| Border panel     | `"border"`     | Border chips inside Border ToolsPanel                 |

Sub-panel chips use `grid-column: 1 / -1` to span the full width of the ToolsPanel CSS grid.

## Suggestion types

Each suggestion from the LLM or heuristic engine includes:

```json
{
  "label": "Use theme accent background",
  "description": "The accent color matches your site's primary brand color.",
  "panel": "color",
  "type": "attribute_change",
  "attributeUpdates": { "backgroundColor": "accent" },
  "preview": "#4f46e5",
  "presetSlug": "accent",
  "cssVar": "var(--wp--preset--color--accent)",
  "currentValue": null,
  "suggestedValue": "accent",
  "confidence": 0.85
}
```

When the user clicks "Apply", the store dispatches `updateBlockAttributes(clientId, attributeUpdates)` directly — no approval flow needed for per-block changes.

## Heuristic fallback

Without an LLM provider configured, Block_Recommender produces basic suggestions:

- Unset backgrounds → suggest theme accent/primary color
- No font size set → suggest theme medium/large preset
- Multiple style variations → list them with current/recommended flags
- No layout set → suggest constrained layout with theme content width
- No padding → suggest mid-range spacing preset

These are intentionally conservative (confidence 0.4–0.6) and clearly labeled as heuristic.

## Setup

```bash
npm install
npm start        # dev build with watch
npm run build    # production
```

Configure LLM (optional — heuristic fallback works without it):

```php
update_option( 'flavor_agent_llm_provider', 'anthropic' );
update_option( 'flavor_agent_llm_api_key', 'sk-ant-...' );
update_option( 'flavor_agent_llm_model', 'claude-sonnet-4-20250514' );
```

## WP 7 compatibility

- Enforces `contentOnly` editing boundaries before rendering and before applying suggestions
- Reads `role: content` attributes via introspection
- Uses `@wordpress/data` stores only — no local state mirrors
- Relies on `editor.BlockEdit` filter + `InspectorControls` (SlotFill-safe)
- Uses `attributes.metadata.blockVisibility` as the canonical visibility state and preserves both boolean and viewport-object forms
- iframe-compatible (no direct `window`/`document` access)
