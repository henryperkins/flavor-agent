# Style And Theme Intelligence

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Surface location: Site Editor Global Styles sidebar while the active complementary area is `edit-site/global-styles`
- Primary mount: portal into `.editor-global-styles-sidebar__panel`
- Fallback mount: `PluginDocumentSettingPanel` named `AI Style Suggestions`
- Scope contract: the current `root/globalStyles` entity resolved from `core.__experimentalGetCurrentGlobalStylesId()` plus `getEditedEntityRecord( 'root', 'globalStyles', id )`
- Shared identifiers: JS surface `global-styles`, localized capability key `globalStyles`, activity scope key `global_styles:<id>`

## Surfacing Conditions

- `GlobalStylesRecommender()` renders only in the Site Editor while the native Styles sidebar is active
- The panel stays visible with a capability notice when `window.flavorAgentData.canRecommendGlobalStyles` is false; readiness uses the same shared `SurfaceCapabilities` payload exposed through localized bootstrap data and `flavor-agent/check-status`
- Requests require an active Flavor Agent chat backend plus `edit_theme_options`
- Apply and undo stay available only while the current Global Styles entity and live user config still match the recorded activity contract

## Shared Interaction Model

- Learned-once sequence: prompt -> suggestions -> explanation -> review where needed -> apply where allowed -> undo and history
- Shared normalized states: `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, `error`
- Global Styles suggestions move `idle -> loading -> advisory-ready` when results arrive, then `preview-ready` only after the user explicitly opens review on an executable suggestion
- Preview uses the shared `AIReviewSection` shell and post-apply / post-undo feedback uses the shared status notice pattern

## End-To-End Flow

1. `GlobalStylesRecommender()` verifies that the current Site Editor complementary area is `edit-site/global-styles`
2. `getGlobalStylesUserConfig()` resolves the current `root/globalStyles` entity id, the current user config, and any available theme style variations
3. `collectThemeTokens()` contributes theme-token source diagnostics and `buildRequestInput()` posts the request through `fetchGlobalStylesRecommendations()` to `POST /flavor-agent/v1/recommend-style`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_style()` adapts the request to `FlavorAgent\Abilities\StyleAbilities::recommend_style()`
5. `StyleAbilities::recommend_style()` folds the Site Editor scope, current configs, available variations, `ServerCollector::for_tokens()`, and supported style paths into the style prompt context
6. `FlavorAgent\LLM\StylePrompt` constrains the response to validated `set_styles` and `set_theme_variation` operations
7. The UI renders advisory or executable suggestion cards; executable cards enter preview before apply
8. `applyGlobalStylesSuggestion()` calls `applyGlobalStyleSuggestionOperations()`, which updates the active `root/globalStyles` entity through `editEntityRecord()`, records before/after user config, and persists activity
9. `undoActivity()` delegates Global Styles undo to `undoGlobalStyleSuggestionOperations()`, which restores the previous user config only while the live post-apply config still matches the recorded activity state

## Flow Diagram

```text
User opens the Site Editor Styles sidebar
  -> GlobalStylesRecommender
  -> getGlobalStylesUserConfig() + collectThemeTokens()
  -> buildRequestInput()
  -> fetchGlobalStylesRecommendations()
  -> POST /flavor-agent/v1/recommend-style
  -> Agent_Controller::handle_recommend_style()
  -> StyleAbilities::recommend_style()
  -> ServerCollector::for_tokens()
  -> StylePrompt::parse_response()
  -> review validated operations
  -> applyGlobalStylesSuggestion()
  -> activity + inline undo
```

## Example Request

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

## Example Response

```json
{
  "suggestions": [
    {
      "label": "Switch to Midnight",
      "description": "The darker variation adds stronger contrast without leaving theme-supported presets.",
      "category": "variation",
      "tone": "executable",
      "operations": [
        {
          "type": "set_theme_variation",
          "variationIndex": 0,
          "variationTitle": "Midnight"
        }
      ]
    }
  ],
  "explanation": "The current theme already exposes a registered variation that moves the site toward the requested tone."
}
```

## What This Surface Can Do

- Recommend preset-backed site-level color, typography, spacing, border, and shadow changes that map to supported Global Styles paths
- Recommend switching to a registered theme style variation
- Keep theme-token source diagnostics attached to the request contract so degraded token sourcing is visible to the backend
- Record applied Global Styles changes in the shared activity system and expose inline undo when the live state still matches the recorded post-apply config

## Supported Executable Operations

- `set_styles`
- `set_theme_variation`

`set_styles` is valid only for supported style paths and must use preset-backed values when the path points at a preset family. `set_theme_variation` is valid only when the referenced variation still exists in the Site Editor runtime.

## Guardrails And Failure Modes

- The surface is hidden outside the Site Editor Styles sidebar
- Raw CSS, `customCSS`, unsupported controls, arbitrary hex values, arbitrary preset-less spacing, width/height transforms, and pseudo-element-only operations are out of scope for this milestone
- Unsupported or ambiguous ideas stay advisory-only instead of entering the apply path
- Undo is unavailable once the active `root/globalStyles` entity changes or the live user config drifts away from the recorded post-apply state

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `GlobalStylesRecommender()` in `src/global-styles/GlobalStylesRecommender.js` | Renders the Site Editor panel, review flow, activity, and undo |
| Scope/runtime helpers | `getGlobalStylesUserConfig()` in `src/utils/style-operations.js` | Resolves the current `root/globalStyles` entity and available variations |
| Input builder | `buildRequestInput()` in `src/global-styles/GlobalStylesRecommender.js` | Normalizes the recommendation request payload |
| Store request | `fetchGlobalStylesRecommendations()` in `src/store/index.js` | Sends the recommendation request |
| Store apply | `applyGlobalStylesSuggestion()` in `src/store/index.js` | Runs deterministic apply and records activity |
| Deterministic executor | `applyGlobalStyleSuggestionOperations()` in `src/utils/style-operations.js` | Validates and executes the structured Global Styles operation set |
| Undo validation | `getGlobalStylesActivityUndoState()` in `src/utils/style-operations.js` | Verifies the live state before undo is exposed |
| REST handler | `Agent_Controller::handle_recommend_style()` | Adapts the REST request to the backend ability |
| Backend ability | `StyleAbilities::recommend_style()` | Builds context and returns validated style suggestions |
| Prompt contract | `StylePrompt::build_system()` / `StylePrompt::build_user()` / `StylePrompt::parse_response()` | Defines and validates the structured Global Styles suggestion format |

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/recommend-style`
- Ability: `flavor-agent/recommend-style`
- Readiness and diagnostics: `flavor-agent/check-status`

## Key Implementation Files

- `src/global-styles/GlobalStylesRecommender.js`
- `src/utils/style-operations.js`
- `src/store/index.js`
- `src/store/activity-history.js`
- `src/components/ActivitySessionBootstrap.js`
- `src/components/AIActivitySection.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `inc/LLM/StylePrompt.php`
- `inc/Context/ServerCollector.php`
