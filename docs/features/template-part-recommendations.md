# Template-Part Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Surface location: Site Editor document settings panel titled `AI Template Part Recommendations`
- Scope: only while editing a `wp_template_part` entity
- UI shape: settings-backed capability notice when unavailable, otherwise a prompt field, slug and area badges, explanation text, focus-block links, suggested-pattern links, preview-before-apply, recent activity, and inline undo

## Surfacing Conditions

- `TemplatePartRecommender()` must resolve a current template-part reference through the shared edited-entity resolver, preferring `core/editor` and falling back to `core/edit-site`
- The `wp_template_part` entity view config must be available so the panel can align its title and area labels with the current WordPress template-part contract
- The panel stays visible with a notice when `window.flavorAgentData.canRecommendTemplateParts` is false; the localized flag is driven by `Provider::chat_configured()`
- The panel clears stale recommendations when the template part changes or when the visible pattern context changes

## Shared Interaction Model

- Learned-once sequence: prompt -> suggestions -> explanation -> review where needed -> apply where allowed -> undo and history
- Shared normalized states: `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, `error`
- Template-part recommendations move `idle -> loading -> advisory-ready` after results arrive, then `preview-ready` only when the user explicitly opens preview on a validated suggestion
- Preview uses the shared `AIReviewSection` shell and post-apply / post-undo feedback uses the same shared status notice pattern as block and template
- Suggestions that fail deterministic validation stay visible in the shared advisory shell so the user can still review focus blocks and pattern ideas without getting an apply affordance

## End-To-End Flow

1. `TemplatePartRecommender()` resolves the current template-part reference through the shared edited-entity resolver, then derives slug and area using both the template-part area lookup and the current `wp_template_part` view-config contract
2. The component builds the request through `buildTemplatePartFetchInput()`, including `visiblePatternNames`
3. `fetchTemplatePartRecommendations()` in the store posts the request to `POST /flavor-agent/v1/recommend-template-part`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_template_part()` adapts the request to `FlavorAgent\Abilities\TemplateAbilities::recommend_template_part()`
5. `TemplateAbilities::recommend_template_part()` gathers template-part context through `ServerCollector::for_template_part()`, including executable targets, insertion anchors, and structural constraints, adds docs guidance, and calls `ResponsesClient::rank()` through `FlavorAgent\LLM\TemplatePartPrompt`
6. The parsed response returns explanation text, advisory `blockHints`, advisory `patternSuggestions`, and optional structured `operations`
7. `buildTemplatePartSuggestionViewModel()` validates the operation sequence before the UI offers preview or apply controls
8. The user can jump to focus blocks through path-based selection links, browse suggested patterns in the inserter, preview the validated operations, and confirm apply
9. `applyTemplatePartSuggestion()` runs the deterministic executor, records activity, and exposes inline undo for the newest valid tail entry

## Flow Diagram

```text
User prompt in wp_template_part editor
  -> TemplatePartRecommender
  -> buildTemplatePartFetchInput()
  -> fetchTemplatePartRecommendations()
  -> POST /flavor-agent/v1/recommend-template-part
  -> Agent_Controller::handle_recommend_template_part()
  -> TemplateAbilities::recommend_template_part()
  -> ServerCollector::for_template_part()
  -> TemplatePartPrompt::parse_response()
  -> validateTemplatePartOperationSequence()
  -> preview exact operations
  -> applyTemplatePartSuggestion()
  -> activity + inline undo
```

## Example Request

```json
{
  "templatePartRef": "theme//header",
  "prompt": "Create a stronger utility row above the main navigation.",
  "visiblePatternNames": [
    "core/header-with-utility-row",
    "core/site-branding-minimal"
  ]
}
```

## Example Response

```json
{
  "suggestions": [
    {
      "label": "Add a utility row before navigation",
      "description": "A compact utility row can hold secondary links without crowding the main menu.",
      "blockHints": [
        {
          "path": [0, 1],
          "label": "Navigation block",
          "blockName": "core/navigation",
          "reason": "This is the main anchor for the header's current structure."
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
  "explanation": "The current header already has a clear navigation anchor, so inserting a utility pattern before that block is a deterministic way to expand the layout."
}
```

## Example Preview Contract

```text
Review Before Apply
  Insert `core/header-with-utility-row`
  -> Before target block (Path 0 > 1)
```

## What This Surface Can Do

- Suggest focus blocks inside the current template part
- Suggest relevant patterns to browse in the inserter
- Offer preview-confirm-apply only for operation sets that survive deterministic validation against executable paths and anchors
- Record template-part apply actions in the shared activity system and expose inline undo

## Supported Executable Operations

- `insert_pattern`
- `replace_block_with_pattern`
- `remove_block`

Supported placement modes today:

- `start`
- `end`
- `before_block_path`
- `after_block_path`

Replace and remove operations only stay executable when their `targetPath` is listed in the prompt's executable operation targets. Insert operations only stay executable when their placement and `targetPath` match the prompt's executable insertion anchors.

## Guardrails And Failure Modes

- Unsupported or ambiguous suggestions stay advisory-only and never receive apply controls
- Only the exact validated operations shown in preview can be applied
- There is no free-form template-part rewrite path
- Undo is blocked if the live document no longer matches the persisted post-apply snapshot or if newer still-applied AI actions remain in the tail

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `TemplatePartRecommender()` in `src/template-parts/TemplatePartRecommender.js` | Renders the panel, advisory links, preview flow, activity, and undo |
| Input builder | `buildTemplatePartFetchInput()` | Normalizes the recommendation request |
| Validation | `validateTemplatePartOperationSequence()` in `src/utils/template-operation-sequence.js` | Rejects unsupported or ambiguous operation sets before apply UI appears |
| Store request | `fetchTemplatePartRecommendations()` in `src/store/index.js` | Sends the recommendation request |
| Store apply | `applyTemplatePartSuggestion()` in `src/store/index.js` | Runs deterministic apply and records activity |
| Deterministic executor | `applyTemplatePartSuggestionOperations()` in `src/utils/template-actions.js` | Executes validated operations against the live editor state |
| Focus helpers | `selectBlockByPath()` and `openInserterForPattern()` | Drive the advisory links inside suggestion cards |
| REST handler | `Agent_Controller::handle_recommend_template_part()` | Adapts the REST request to the backend ability |
| Backend ability | `TemplateAbilities::recommend_template_part()` | Builds context and returns template-part suggestions |
| Prompt contract | `TemplatePartPrompt::build_user()` / `TemplatePartPrompt::parse_response()` | Defines and validates the structured output |

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/recommend-template-part`
- Ability: `flavor-agent/recommend-template-part`
- Helper ability: `flavor-agent/list-template-parts`

## Key Implementation Files

- `src/template-parts/TemplatePartRecommender.js`
- `src/utils/template-actions.js`
- `src/utils/template-operation-sequence.js`
- `src/utils/template-part-areas.js`
- `src/utils/editor-entity-contracts.js`
- `src/utils/visible-patterns.js`
- `src/store/index.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/Context/ServerCollector.php`
