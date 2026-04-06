# Abilities And Routes Reference

This document is the contract reference for Flavor Agent's programmatic surfaces.

Use it when you need to answer:

- which ability or route owns a feature
- what capability and backend gates apply
- whether a first-party UI uses REST, abilities, or both

## How First-Party UI And Abilities Relate

- The shipped Gutenberg editor UI uses the plugin REST routes plus the `flavor-agent` data store, while the wp-admin audit page is a separate `apiFetch` + `DataViews` app and does not use that store
- The Abilities API exposes closely related contracts for external AI agents on supported WordPress 7.0+ installs
- Activity persistence and manual pattern sync are REST-only today; they do not have matching registered abilities
- `POST /flavor-agent/v1/recommend-block` is the main response-shape exception: the REST route wraps the ability payload in `{ payload, clientId }`
- Pattern, template, and template-part first-party surfaces also read the shared post-type entity contract from `src/utils/editor-entity-contracts.js`, which normalizes built-in field metadata and safe fallbacks when no live WordPress view config is exposed, so panel visibility, title-field expectations, template-part area labels, and the patched pattern category stay aligned with the current entity contract

## Registered Abilities

| Ability | Permission | Extra gate | What it returns or does | First-party surface |
|---|---|---|---|---|
| `flavor-agent/recommend-block` | `edit_posts` | Meaningful output requires `ChatClient::is_supported()` | Block recommendation payload with `settings`, `styles`, `block`, and `explanation` | Block Inspector recommendations |
| `flavor-agent/recommend-content` | `edit_posts` | Active provider chat configured | Draft, edit, or critique payload for blog posts, essays, and site copy in Henry Perkins's voice, with notes and line-level rewrites | No direct first-party UI yet; programmatic scaffold for a future post-editor lane |
| `flavor-agent/introspect-block` | `edit_posts` | None beyond capability | Block registry manifest: supports, Inspector panels, attributes, styles, and variations | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/recommend-patterns` | `edit_posts` | Active provider embeddings + chat, Qdrant configured, usable pattern index | Ranked registered patterns for the current editing context | Pattern inserter recommendations |
| `flavor-agent/list-patterns` | `edit_posts` | None beyond capability | Registered block patterns with optional filters | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/recommend-template` | `edit_theme_options` | Active provider chat configured | Template suggestions plus validated template-part operations and bounded pattern insertions with explicit placement and optional anchor metadata | Site Editor template panel |
| `flavor-agent/recommend-template-part` | `edit_theme_options` | Active provider chat configured | Template-part suggestions, focus blocks, patterns, and validated bounded operations constrained by executable paths and anchors | Site Editor template-part panel |
| `flavor-agent/recommend-style` | `edit_theme_options` | Active provider chat configured | Shared style suggestions for Global Styles and Style Book, constrained to validated `theme.json` paths, theme-backed values, and Global Styles-only theme variations | Site Editor Global Styles and Style Book panels |
| `flavor-agent/list-template-parts` | `edit_theme_options` | None beyond capability | Registered template parts, optionally filtered by area | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/recommend-navigation` | `edit_theme_options` | Active provider chat configured for useful output | Advisory navigation suggestion groups plus explanation | Navigation guidance inside the block panel |
| `flavor-agent/search-wordpress-docs` | `manage_options` | Cloudflare AI Search configured | Trusted WordPress developer-doc guidance, optionally warming entity cache | No direct first-party editor UI; admin and external-agent surface |
| `flavor-agent/get-theme-tokens` | `edit_posts` | None beyond capability | Theme token snapshot: colors, typography, spacing, layout, and related feature flags | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/check-status` | `edit_posts` | None beyond capability | Backend inventory, active model hint, and currently available ability list | Settings diagnostics and external-agent surface |

## Ability Notes

- All thirteen abilities are registered in `inc/Abilities/Registration.php`
- On supported WordPress 7.0+ admin screens, core hydrates these server-registered abilities into the client-side abilities store
- The seven AI recommendation abilities (`recommend-block`, `recommend-content`, `recommend-patterns`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, and `recommend-style`) also opt into the Abilities API default MCP server via `meta.mcp.public = true`
- `flavor-agent/recommend-block` accepts different input shapes depending on the caller: the REST route passes `editorContext` (with nested `block`, `siblingsBefore`, `siblingsAfter`, `themeTokens`), while the Abilities API registers `selectedBlock` (with `structuralIdentity`, `structuralAncestors`, `structuralBranch`, `childCount`, and `blockVisibility`). `BlockAbilities::recommend_block()` normalizes both paths into a single prompt context
- `flavor-agent/check-status` now reports the runtime-gated `availableAbilities` list plus a `surfaces` map that explains per-surface ready / unavailable state for block, pattern, template, template-part, navigation, Global Styles, and Style Book UIs

## REST Routes

| Route | Permission | First-party caller | Backend owner | Notes |
|---|---|---|---|---|
| `POST /flavor-agent/v1/recommend-block` | `edit_posts` | `fetchBlockRecommendations()` | `BlockAbilities::recommend_block()` | Wraps the response as `{ payload, clientId }` |
| `POST /flavor-agent/v1/recommend-content` | `edit_posts` | No first-party caller yet | `ContentAbilities::recommend_content()` | Thin REST adapter over the content-lane scaffold |
| `POST /flavor-agent/v1/recommend-patterns` | `edit_posts` | `fetchPatternRecommendations()` | `PatternAbilities::recommend_patterns()` | Thin REST adapter over the ability |
| `POST /flavor-agent/v1/recommend-navigation` | `edit_theme_options` | `fetchNavigationRecommendations()` | `NavigationAbilities::recommend_navigation()` | Thin REST adapter over the ability |
| `POST /flavor-agent/v1/recommend-template` | `edit_theme_options` | `fetchTemplateRecommendations()` | `TemplateAbilities::recommend_template()` | Thin REST adapter over the ability |
| `POST /flavor-agent/v1/recommend-template-part` | `edit_theme_options` | `fetchTemplatePartRecommendations()` | `TemplateAbilities::recommend_template_part()` | Thin REST adapter over the ability |
| `POST /flavor-agent/v1/recommend-style` | `edit_theme_options` | `fetchGlobalStylesRecommendations()` and `fetchStyleBookRecommendations()` | `StyleAbilities::recommend_style()` | Thin REST adapter over the shared style ability |
| `GET /flavor-agent/v1/activity` | Contextual editor/theme capability; `manage_options` for global reads | `loadActivitySession()` and admin activity log | `ActivityRepository::query()` for scoped reads; `ActivityRepository::query_admin()` for global admin reads | Scoped queries power editor/theme history; global admin reads return pagination, summary, and filter-option metadata for the audit page |
| `POST /flavor-agent/v1/activity` | Contextual editor/theme capability | Store-side activity persistence | `ActivityRepository::create()` | Persists server-backed activity entries |
| `POST /flavor-agent/v1/activity/{id}/undo` | Contextual editor/theme capability | `undoActivity()` | `ActivityRepository::update_undo_status()` | Persists undo-status transitions |
| `POST /flavor-agent/v1/sync-patterns` | `manage_options` | `src/admin/sync-button.js` | `PatternIndex::sync()` | Manual admin-only pattern catalog rebuild |

## Example Contracts

### Block Recommendation REST Request

```json
{
  "editorContext": {
    "block": {
      "name": "core/group",
      "currentAttributes": {
        "layout": {
          "type": "constrained"
        }
      },
      "editingMode": "default",
      "isInsideContentOnly": false
    },
    "siblingsBefore": ["core/heading"],
    "siblingsAfter": ["core/paragraph"],
    "themeTokens": {
      "colors": ["contrast"],
      "spacing": ["40", "50", "60"]
    }
  },
  "prompt": "Give this section more breathing room.",
  "clientId": "block-client-id"
}
```

### Template Ability Request

```json
{
  "templateRef": "theme//single-post",
  "templateType": "single",
  "prompt": "Introduce a supporting sidebar.",
  "visiblePatternNames": ["core/post-meta-two-column"],
  "editorSlots": {
    "assignedParts": [
      {
        "slug": "header",
        "area": "header"
      }
    ],
    "emptyAreas": ["sidebar"],
    "allowedAreas": ["header", "sidebar"]
  },
  "editorStructure": {
    "topLevelBlockTree": [
      {
        "path": [0],
        "name": "core/template-part",
        "label": "Header",
        "attributes": { "slug": "header", "area": "header" },
        "childCount": 3,
        "slot": { "slug": "header", "area": "header", "isEmpty": false }
      },
      {
        "path": [1],
        "name": "core/group",
        "label": "Content",
        "attributes": {},
        "childCount": 2
      }
    ]
  }
}
```

### Template Ability Response Shape

```json
{
  "suggestions": [
    {
      "label": "Lead with the hero before the header",
      "description": "Use the validated top-level anchor so the intro lands ahead of the current header slot.",
      "operations": [
        {
          "type": "insert_pattern",
          "patternName": "core/post-meta-two-column",
          "placement": "before_block_path",
          "targetPath": [1],
          "expectedTarget": {
            "name": "core/group",
            "label": "Content",
            "attributes": {},
            "childCount": 2
          }
        }
      ],
      "templateParts": [],
      "patternSuggestions": ["core/post-meta-two-column"]
    }
  ],
  "explanation": "The top-level template structure exposes a safe anchor before the existing header block."
}
```

### Style Ability Request

```json
{
  "scope": {
    "surface": "global-styles",
    "scopeKey": "global_styles:17",
    "globalStylesId": "17",
    "postType": "global_styles",
    "entityId": "17",
    "entityKind": "root",
    "entityName": "globalStyles"
  },
  "styleContext": {
    "currentConfig": {
      "settings": {},
      "styles": {}
    },
    "mergedConfig": {
      "settings": {},
      "styles": {}
    },
    "availableVariations": [
      {
        "title": "Midnight"
      }
    ],
    "themeTokenDiagnostics": {
      "source": "server",
      "settingsKey": "wp_get_global_settings",
      "reason": "server-global-settings"
    }
  },
  "prompt": "Make the site feel more editorial."
}
```

### Style Book Ability Request

```json
{
  "scope": {
    "surface": "style-book",
    "scopeKey": "style_book:17:core/group",
    "globalStylesId": "17",
    "entityKind": "block",
    "entityName": "styleBook",
    "entityId": "core/group",
    "blockName": "core/group",
    "blockTitle": "Group"
  },
  "styleContext": {
    "currentConfig": {
      "settings": {},
      "styles": {}
    },
    "mergedConfig": {
      "settings": {},
      "styles": {}
    },
    "styleBookTarget": {
      "blockName": "core/group",
      "blockTitle": "Group",
      "currentStyles": {},
      "mergedStyles": {}
    }
  },
  "prompt": "Make this block feel more editorial."
}
```

### Template-Part Ability Response Shape

```json
{
  "suggestions": [
    {
      "label": "Add a utility row before navigation",
      "description": "Strengthen secondary navigation without crowding the main row.",
      "blockHints": [
        {
          "path": [0, 1],
          "label": "Navigation block",
          "blockName": "core/navigation",
          "reason": "This is the existing structural anchor."
        }
      ],
      "patternSuggestions": ["core/header-with-utility-row"],
      "operations": [
        {
          "type": "insert_pattern",
          "patternName": "core/header-with-utility-row",
          "placement": "before_block_path",
          "targetPath": [0, 1]
        }
      ]
    }
  ],
  "explanation": "The current header supports a bounded insertion before the existing navigation block."
}
```

### Navigation Response Shape

```json
{
  "suggestions": [
    {
      "label": "Flatten low-value nesting",
      "description": "Reducing one submenu level makes key destinations easier to scan.",
      "category": "structure",
      "changes": [
        {
          "type": "flatten",
          "targetPath": [2],
          "target": "Resources submenu",
          "detail": "Promote the two most-used links to the top level."
        }
      ]
    }
  ],
  "explanation": "The current menu depth is heavier than the observed information hierarchy needs."
}
```

### Pattern Sync Response Shape

```json
{
  "indexed": 6,
  "removed": 2,
  "fingerprint": "1b52d1f7c8a7e3f1",
  "status": "ready"
}
```

### Activity Entry Shape

```json
{
  "surface": "template-part",
  "suggestion": "Add a utility row before navigation",
  "request": {
    "prompt": "Create a stronger utility row above the main navigation.",
    "reference": "template-part:theme//header:3"
  },
  "document": {
    "scopeKey": "wp_template_part:theme//header",
    "postType": "wp_template_part",
    "entityId": "theme//header"
  },
  "undo": {
    "status": "available"
  }
}
```

## Sequence Cheatsheet

```text
Block UI -> store -> /recommend-block -> BlockAbilities -> ChatClient -> Prompt -> UI
Pattern UI -> store -> /recommend-patterns -> PatternAbilities -> Qdrant + Responses -> inserter patch
Navigation UI -> store -> /recommend-navigation -> NavigationAbilities -> NavigationPrompt -> advisory UI
Template UI -> store -> /recommend-template -> TemplateAbilities -> TemplatePrompt -> preview/apply/undo
Template-part UI -> store -> /recommend-template-part -> TemplateAbilities -> TemplatePartPrompt -> preview/apply/undo
Global Styles / Style Book UI -> store -> /recommend-style -> StyleAbilities -> StylePrompt -> preview/apply/undo
Apply flow -> activity create -> inline activity UI -> undo -> activity/{id}/undo
```

## Route Notes

- The recommendation routes sanitize and normalize structured inputs before handing them to the ability layer
- `POST /flavor-agent/v1/recommend-patterns` does not accept `editorStructure`; the current pattern route contract ignores it
- Template recommendation requests carry an editor-collected `editorStructure` with the top-level block tree; the server normalizes it and derives insertion anchors for validated operations
- Template-part requests also accept `editorStructure` for editor-collected override context, then combine it with server-collected executable targets, insertion anchors, and structural constraints before any apply affordance is shown
- Navigation response groups now validate structural `changes[].targetPath` values against the current menu target inventory instead of trusting free-form target text alone
- Activity permissions are contextual: post-like scopes use `edit_posts` or `edit_post`, while template and template-part scopes use `edit_theme_options`
- Manual sync is intentionally admin-only because it mutates shared vector-index state

## Primary Source Files

- `inc/Abilities/Registration.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/Abilities/WordPressDocsAbilities.php`
- `inc/Abilities/InfraAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `inc/REST/Agent_Controller.php`
- `src/store/index.js`

## Contract Verification Checklist

When updating any ability or REST contract, keep these sources aligned:

1. Ability registrations and schemas in `inc/Abilities/Registration.php`
2. REST route registrations, permission callbacks, and sanitization in `inc/REST/Agent_Controller.php`
3. First-party callers and payload shaping in `src/store/index.js` and the relevant surface module
