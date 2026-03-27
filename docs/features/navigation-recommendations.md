# Navigation Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Surface location: inside the block `AI Recommendations` panel
- Scope: only for selected `core/navigation` blocks
- UI shape: prompt field, advisory-only badge, grouped recommendation cards, and per-change detail rows

## Surfacing Conditions

- `window.flavorAgentData.canRecommendNavigation` must be true; the localized flag requires the active chat provider and `edit_theme_options`
- The selected block must be `core/navigation`
- The request button is only enabled when `buildNavigationFetchInput()` can derive either:
  - a menu ID from `attributes.ref`, or
  - serialized navigation block markup

## End-To-End Flow

1. `NavigationRecommendations()` in `src/inspector/NavigationRecommendations.js` reads the selected block from `core/block-editor`
2. `buildNavigationFetchInput()` extracts the prompt, menu ID, and/or serialized navigation markup
3. `fetchNavigationRecommendations()` in the `flavor-agent` store posts the request to `POST /flavor-agent/v1/recommend-navigation`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_navigation()` adapts the request to `FlavorAgent\Abilities\NavigationAbilities::recommend_navigation()`
5. `NavigationAbilities::recommend_navigation()` gathers navigation context through `ServerCollector::for_navigation()`, builds prompt text through `FlavorAgent\LLM\NavigationPrompt`, optionally adds WordPress docs grounding, and calls `ResponsesClient::rank()`
6. The parsed response returns advisory suggestion groups plus an explanation string
7. The store caches the result against the current block client ID and the UI renders suggestion cards and per-change rows

## What This Surface Can Do

- Suggest navigation structure improvements
- Suggest overlay-related changes when the current block uses or implies overlay behavior
- Suggest accessibility-oriented navigation changes
- Reset and re-fetch as the selected navigation block or its serialized context changes

## Guardrails And Failure Modes

- This surface is advisory-only today; there is no validated apply contract
- It does not write activity entries and does not participate in inline undo
- The panel clears stale results when the selected block changes or when the navigation context signature changes
- If the block cannot provide either a menu ID or serialized markup, the fetch action stays disabled

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `NavigationRecommendations()` in `src/inspector/NavigationRecommendations.js` | Renders the advisory navigation section inside block recommendations |
| Input builder | `buildNavigationFetchInput()` | Normalizes the request contract from the selected navigation block |
| Store request | `fetchNavigationRecommendations()` in `src/store/index.js` | Sends the request and stores block-scoped results |
| REST handler | `Agent_Controller::handle_recommend_navigation()` | Adapts the REST request to the backend ability |
| Backend ability | `NavigationAbilities::recommend_navigation()` | Builds context, prompt, and parsed advisory output |
| Prompt contract | `NavigationPrompt::build_user()` / `NavigationPrompt::parse_response()` | Defines the structure of the returned guidance |

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/recommend-navigation`
- Ability: `flavor-agent/recommend-navigation`

## Key Implementation Files

- `src/inspector/NavigationRecommendations.js`
- `src/store/index.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/LLM/NavigationPrompt.php`
- `inc/Context/ServerCollector.php`
