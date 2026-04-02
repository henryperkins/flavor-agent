# Block Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Primary surface: native block Inspector panel titled `AI Recommendations`
- Injection point: `editor.BlockEdit` filter registered in `src/inspector/InspectorInjector.js`
- Fallback surface: document settings panel titled `AI Recommendations` with the eyebrow `Last Selected Block` when the current selection clears but the last selected block still exists
- Secondary surfaces after a successful request:
  - `SettingsRecommendations` in the default Inspector group
  - `StylesRecommendations` in the `styles` group, with grouped section framing for block-level style rows and registered style variations
  - compact `SuggestionChips` injected into sub-panels such as position, advanced, bindings, list, color, typography, dimensions, border, filter, and background, with lightweight applied feedback rendered directly beside the matching chip group

## Surfacing Conditions

- The selected block must exist and its editing mode must not be `disabled`
- The main panel still renders when recommendations are unavailable, but fetch is disabled when `window.flavorAgentData.canRecommendBlocks` is false
- Content-restricted blocks stay visible and show an informational notice; only content-safe suggestions are allowed
- A selected `core/navigation` block adds the navigation guidance section inside the same panel

## Shared Interaction Model

- Learned-once sequence: prompt -> suggestions -> explanation -> apply where allowed -> undo and history
- Shared normalized states: `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, `error`
- Block recommendations normally move `idle -> loading -> advisory-ready`; safe local attribute updates can then move directly to `success` because only the selected block's local attributes are mutated
- The panel now states that inline apply is the exception for safe local block updates, while structural surfaces keep the same status/history framing but require preview first
- Style suggestions preserve one-click apply in both the Styles tab and delegated native style sub-panels, but success feedback now sits next to the clicked row or chip group instead of relying only on a transient button flash
- `Recent AI Actions` and inline undo use the same shared activity treatment as the template and template-part surfaces

## End-To-End Flow

1. The user selects a block and optionally enters a prompt in `BlockRecommendationsContent`
2. `collectBlockContext()` in `src/context/collector.js` builds the client-side block snapshot, including bindable attributes and native inspector panel availability
3. `fetchBlockRecommendations()` in `src/store/index.js` posts that context to `POST /flavor-agent/v1/recommend-block`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_block()` adapts the request to `FlavorAgent\Abilities\BlockAbilities::recommend_block()`
5. `BlockAbilities::recommend_block()` normalizes the input, gathers server context, and calls `FlavorAgent\LLM\ChatClient::chat()`
6. `ChatClient::chat()` prefers the selected plugin provider when chat is configured and otherwise falls back to the WordPress AI Client / Connectors path
7. `FlavorAgent\LLM\Prompt` builds the prompt, parses the response, and enforces block-context guardrails
8. The store saves the grouped `settings`, `styles`, and `block` suggestions and the Inspector renders the matching cards and chips
9. When the user applies a suggestion, `applySuggestion()` safely merges allowed attribute updates into the current block and records an activity entry
10. Inline undo calls `undoActivity()`, which validates the live block state before restoring the previous attribute snapshot

## Flow Diagram

```text
User selects block + prompt
  -> BlockRecommendationsContent
  -> collectBlockContext()
  -> fetchBlockRecommendations()
  -> POST /flavor-agent/v1/recommend-block
  -> Agent_Controller::handle_recommend_block()
  -> BlockAbilities::recommend_block()
  -> ChatClient::chat()
  -> Prompt::parse_response()
  -> store saves grouped suggestions
  -> Inspector renders cards and chips
  -> applySuggestion() / undoActivity()
```

## Example Request

```json
{
  "editorContext": {
    "block": {
      "name": "core/group",
      "title": "Group",
      "currentAttributes": {
        "layout": {
          "type": "constrained"
        },
        "style": {
          "spacing": {
            "padding": {
              "top": "var:preset|spacing|40",
              "bottom": "var:preset|spacing|40"
            }
          }
        }
      },
      "inspectorPanels": {
        "layout": true,
        "dimensions": true,
        "color": true
      },
      "editingMode": "default",
      "isInsideContentOnly": false,
      "childCount": 2,
      "structuralIdentity": {
        "role": "section",
        "location": "content"
      }
    },
    "siblingsBefore": ["core/heading"],
    "siblingsAfter": ["core/paragraph"],
    "themeTokens": {
      "colors": ["contrast", "base"],
      "spacing": ["40", "50", "60"]
    }
  },
  "prompt": "Make this section feel more spacious and editorial.",
  "clientId": "2b1c4f3f-1234-5678-9abc-def012345678"
}
```

## Example Response

```json
{
  "payload": {
    "settings": [
      {
        "label": "Use a wider layout",
        "description": "Give the section more room so the inner content feels less cramped.",
        "panel": "layout",
        "attributeUpdates": {
          "layout": {
            "type": "constrained",
            "wideSize": "72rem"
          }
        },
        "confidence": 0.84
      }
    ],
    "styles": [
      {
        "label": "Increase vertical padding",
        "description": "More top and bottom spacing helps separate this section from nearby content.",
        "panel": "dimensions",
        "attributeUpdates": {
          "style": {
            "spacing": {
              "padding": {
                "top": "var:preset|spacing|60",
                "bottom": "var:preset|spacing|60"
              }
            }
          }
        },
        "confidence": 0.9
      }
    ],
    "block": [],
    "explanation": "The block already works as a section wrapper, so spacing and layout changes are the lowest-risk improvements."
  },
  "clientId": "2b1c4f3f-1234-5678-9abc-def012345678"
}
```

## Example Activity Entry Shape

```json
{
  "surface": "block",
  "suggestion": "Increase vertical padding",
  "target": {
    "clientId": "2b1c4f3f-1234-5678-9abc-def012345678",
    "blockName": "core/group",
    "blockPath": [3]
  },
  "request": {
    "prompt": "Make this section feel more spacious and editorial.",
    "reference": "block:2b1c4f3f-1234-5678-9abc-def012345678:4"
  },
  "document": {
    "scopeKey": "post:128",
    "postType": "post",
    "entityId": "128"
  },
  "undo": {
    "status": "available"
  }
}
```

## What This Surface Can Do

- Suggest block settings changes, style changes, and broader block-level adjustments
- Route suggestions into the Inspector location where the user would normally make that change
- Apply bounded attribute updates with safe handling for nested `style` and `metadata` keys
- Record the apply action in the shared AI activity system and surface inline undo for the newest valid tail entry

## Guardrails And Failure Modes

- Disabled blocks do not render the surface at all
- Content-only editing mode limits suggestions to content-safe attributes
- Visibility state in `attributes.metadata.blockVisibility` is respected during prompt building and post-parse enforcement
- If no allowed attribute updates remain after validation, the suggestion is not applied
- Undo is blocked if the block moved, disappeared, or changed after the AI apply

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `withAIRecommendations()` in `src/inspector/InspectorInjector.js` | Injects the panel into the native Inspector |
| UI state | `BlockRecommendationsContent()` in `src/inspector/BlockRecommendationsPanel.js` | Renders prompt, notices, activity, and suggestion groups |
| Context collection | `collectBlockContext()` in `src/context/collector.js` | Builds the client snapshot sent to the backend |
| Store request | `fetchBlockRecommendations()` in `src/store/index.js` | Sends the recommendation request and stores the result |
| Store apply | `applySuggestion()` in `src/store/index.js` | Applies bounded attribute updates and records activity |
| REST handler | `Agent_Controller::handle_recommend_block()` | Adapts the REST request to the backend ability |
| Backend ability | `BlockAbilities::recommend_block()` | Normalizes input, gathers context, and runs the prompt pipeline |
| LLM wrapper | `ChatClient::chat()` | Chooses plugin provider first, then WordPress AI Client fallback |
| Prompt contract | `Prompt::build_user()` / `Prompt::parse_response()` | Builds and validates the structured block-suggestion payload |

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/recommend-block`
- Ability: `flavor-agent/recommend-block`
- Helper ability: `flavor-agent/introspect-block`

## Key Implementation Files

- `src/inspector/InspectorInjector.js`
- `src/inspector/BlockRecommendationsPanel.js`
- `src/inspector/SettingsRecommendations.js`
- `src/inspector/StylesRecommendations.js`
- `src/inspector/SuggestionChips.js`
- `src/context/collector.js`
- `src/store/index.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/LLM/ChatClient.php`
- `inc/LLM/Prompt.php`
