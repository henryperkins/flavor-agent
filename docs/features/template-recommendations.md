# Template Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Surface location: Site Editor document settings panel titled `AI Template Recommendations`
- Scope: only while editing a `wp_template` entity
- UI shape: prompt field, explanation text with linked entities, suggestion cards, preview-before-apply, recent activity, and inline undo

## Surfacing Conditions

- `window.flavorAgentData.canRecommendTemplates` must be true; this localized flag is driven by `Provider::chat_configured()`
- `TemplateRecommender()` must resolve a current template reference through `core/editor` or `core/edit-site`
- The panel clears stale recommendations when the template changes or when the recommendation context changes, including editor slot state or the visible pattern set

## End-To-End Flow

1. `TemplateRecommender()` resolves the current `wp_template` reference and template type
2. The component derives editor slot state through `buildEditorTemplateSlotSnapshot()` and captures the current `visiblePatternNames` from the insertion root
3. `buildTemplateFetchInput()` creates the request payload and `fetchTemplateRecommendations()` posts it to `POST /flavor-agent/v1/recommend-template`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_template()` adapts the request to `FlavorAgent\Abilities\TemplateAbilities::recommend_template()`
5. `TemplateAbilities::recommend_template()` gathers template context through `ServerCollector::for_template()`, folds in editor slot overrides, adds docs guidance, and calls `ResponsesClient::rank()` through `FlavorAgent\LLM\TemplatePrompt`
6. The parsed response returns up to three suggestion cards with validated structured operations
7. The UI builds an entity map so template-part slugs, areas, and pattern names inside descriptions and explanations become clickable actions
8. Preview mode shows the exact validated operations that would run if the user confirms apply
9. `applyTemplateSuggestion()` in the store calls the deterministic executor, records activity, and exposes inline undo for the newest valid tail entry

## Flow Diagram

```text
User prompt in wp_template editor
  -> TemplateRecommender
  -> buildEditorTemplateSlotSnapshot() + visiblePatternNames
  -> buildTemplateFetchInput()
  -> fetchTemplateRecommendations()
  -> POST /flavor-agent/v1/recommend-template
  -> Agent_Controller::handle_recommend_template()
  -> TemplateAbilities::recommend_template()
  -> ServerCollector::for_template()
  -> TemplatePrompt::parse_response()
  -> preview validated operations
  -> applyTemplateSuggestion()
  -> activity + inline undo
```

## Example Request

```json
{
  "templateRef": "theme//single-post",
  "templateType": "single",
  "prompt": "Make the article template feel more magazine-like.",
  "editorSlots": {
    "assignedParts": [
      {
        "slug": "header",
        "area": "header"
      },
      {
        "slug": "footer",
        "area": "footer"
      }
    ],
    "emptyAreas": ["sidebar"],
    "allowedAreas": ["header", "footer", "sidebar"]
  },
  "visiblePatternNames": [
    "core/query-offset-feature",
    "core/post-meta-two-column"
  ]
}
```

## Example Response

```json
{
  "suggestions": [
    {
      "label": "Use the empty sidebar slot",
      "description": "Introduce supporting article context without disturbing the main reading flow.",
      "operations": [
        {
          "type": "assign_template_part",
          "slug": "single-sidebar",
          "area": "sidebar"
        },
        {
          "type": "insert_pattern",
          "patternName": "core/post-meta-two-column"
        }
      ],
      "templateParts": [
        {
          "slug": "single-sidebar",
          "area": "sidebar",
          "reason": "This keeps related metadata and supporting blocks out of the article body."
        }
      ],
      "patternSuggestions": ["core/post-meta-two-column"]
    }
  ],
  "explanation": "The template already has stable header and footer assignments, so the open sidebar area is the safest structural opportunity."
}
```

## Example Preview Contract

```text
Review Before Apply
  1 operation: assign `single-sidebar` -> `sidebar`
  1 operation: insert `core/post-meta-two-column`
```

## What This Surface Can Do

- Recommend template-part assignment and replacement
- Recommend pattern insertion into the current template
- Turn mentioned template parts, areas, and patterns into clickable editor actions
- Preview the exact operation list before any mutation happens
- Record template apply actions in the shared activity system and expose inline undo

## Supported Executable Operations

- `assign_template_part`
- `replace_template_part`
- `insert_pattern`

Pattern insertion uses the current editor insertion point. The preview UI tells the user to change block selection before confirmation if they want the insertion to land somewhere else.

## Guardrails And Failure Modes

- The surface is hidden outside `wp_template` editing
- Any invalid operation causes the entire apply to fail before mutation; there is no partial apply path
- Free-form template rewrites are intentionally out of scope
- Only the newest valid tail entry is undoable; older entries are blocked until newer still-applied actions are undone

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `TemplateRecommender()` in `src/templates/TemplateRecommender.js` | Renders the panel, entity links, preview flow, activity, and undo |
| Input builder | `buildTemplateFetchInput()` in `src/templates/template-recommender-helpers.js` | Normalizes the request payload |
| Context helpers | `buildEditorTemplateSlotSnapshot()` and `buildTemplateRecommendationContextSignature()` | Capture slot state and invalidation boundaries |
| Store request | `fetchTemplateRecommendations()` in `src/store/index.js` | Sends the recommendation request |
| Store apply | `applyTemplateSuggestion()` in `src/store/index.js` | Runs deterministic apply and records activity |
| Deterministic executor | `applyTemplateSuggestionOperations()` in `src/utils/template-actions.js` | Validates and executes the structured operation set |
| REST handler | `Agent_Controller::handle_recommend_template()` | Adapts the REST request to the backend ability |
| Backend ability | `TemplateAbilities::recommend_template()` | Builds context and returns validated template suggestions |
| Prompt contract | `TemplatePrompt::build_user()` / `TemplatePrompt::parse_response()` | Defines and validates the structured template suggestion format |

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/recommend-template`
- Ability: `flavor-agent/recommend-template`
- Helper ability: `flavor-agent/list-template-parts`

## Key Implementation Files

- `src/templates/TemplateRecommender.js`
- `src/templates/template-recommender-helpers.js`
- `src/utils/template-actions.js`
- `src/utils/template-operation-sequence.js`
- `src/utils/visible-patterns.js`
- `src/store/index.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/LLM/TemplatePrompt.php`
- `inc/Context/ServerCollector.php`
