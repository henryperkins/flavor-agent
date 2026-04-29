# Navigation Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Surface location: inside the block `AI Recommendations` panel
- Scope: only for selected `core/navigation` blocks
- UI shape: advisory-only nested subsection with its own prompt and request state, framed as `Recommended Next Changes`, with a featured recommendation first and grouped shared-`Manual ideas` category lanes and per-change detail rows

## Surfacing Conditions

- The selected block must be `core/navigation`
- The section stays visible with a notice when `window.flavorAgentData.canRecommendNavigation` is false; the localized flag comes from the shared surface-capability contract and requires both `edit_theme_options` and a compatible text-generation provider in `Settings > Connectors`
- The request button is only enabled when `buildNavigationFetchInput()` can derive either:
  - a menu ID from `attributes.ref`, or
  - serialized navigation block markup

## Shared Interaction Model

- Learned-once sequence: scope/freshness -> prompt -> status -> featured recommendation -> grouped manual lanes
- Shared normalized states: `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, `error`
- Navigation uses the same advisory/status shell as the executable surfaces but intentionally stops at `advisory-ready`
- The subsection keeps `Recommended Next Changes` as its wrapper title because it is an embedded next-step flow inside block recommendations, but the actual advisory taxonomy uses the shared `Manual ideas` tone
- The first returned suggestion is promoted as the recommended next navigation change; remaining ideas are grouped by category (`Structure`, `Overlay`, `Accessibility`)
- There is no preview or apply path here; the user reviews the grouped changes and edits navigation manually
- When the current navigation context drifts, the subsection keeps the previous result visible as stale reference material and exposes a refresh action instead of silently clearing it
- Because navigation remains advisory-only through v1.0, it does not create executable apply/undo activity entries and does not participate in inline undo. Scoped request diagnostics may still be persisted for the admin audit page when document scope is available; see `docs/features/activity-and-audit.md`.

## End-To-End Flow

1. `NavigationRecommendations()` in `src/inspector/NavigationRecommendations.js` reads the selected block from `core/block-editor`
2. `buildNavigationFetchInput()` extracts the prompt, menu ID, and/or serialized navigation markup
3. `fetchNavigationRecommendations()` in the `flavor-agent` store posts the request to `POST /flavor-agent/v1/recommend-navigation`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_navigation()` adapts the request to `FlavorAgent\Abilities\NavigationAbilities::recommend_navigation()`
5. `NavigationAbilities::recommend_navigation()` gathers navigation context through `ServerCollector::for_navigation()`, including location details, structure summary, a path-based current target inventory, overlay context, and overlay template-part metadata, computes a docs-free `reviewContextSignature`, returns early for signature-only revalidation, and only then builds prompt text, optionally adds WordPress docs grounding, and calls `ResponsesClient::rank()`
6. The parsed response returns advisory suggestion groups, an explanation string, and a `reviewContextSignature`
7. The store caches the result against the current block client ID and the UI renders suggestion cards and per-change rows

## Server Review Freshness

Navigation participates in the server review-freshness contract even though it is advisory-only. This is important because `ref`-based (saved) menus can drift server-side in ways the client cannot observe:

1. When a stored `ready` result is displayed, the UI triggers a background `resolveSignatureOnly` request via `revalidateNavigationReviewFreshness()`
2. The backend resolves the server context that participates in review freshness (menu structure, target inventory, overlay parts, overlay context, and theme tokens) and returns only the `reviewContextSignature` hash — no docs lookup or model call is made
3. If the returned signature differs from the stored result's `reviewContextSignature`, the UI marks the result as stale and exposes a refresh affordance
4. Full recommendation requests still collect docs grounding after the signature-only fast path, but docs churn alone does not make a stored navigation result stale

## What This Surface Can Do

- Suggest navigation structure improvements
- Suggest overlay-related changes when the current block uses or implies overlay behavior
- Suggest accessibility-oriented navigation changes
- Ground those suggestions against current location, overlay, structure summaries, and real current menu target paths instead of only raw menu items
- Reset and re-fetch as the selected navigation block or its serialized context changes

## Guardrails And Failure Modes

- This surface is advisory-only through v1.0; there is no validated apply contract
- Structural change groups are still advisory, but the backend now rejects any structural `changes[].targetPath` that does not map to the current menu inventory
- It does not write executable apply/undo activity entries and does not participate in inline undo; scoped request diagnostics can still be audited
- The store and UI never route navigation suggestions through the template/style apply or undo executors even though they share the same normalized request-state vocabulary
- The panel clears results when the selected block changes to a different navigation scope, but when the same selected navigation block drifts in place it now preserves the previous result as stale reference material and exposes a refresh affordance
- If the block cannot provide either a menu ID or serialized markup, the fetch action stays disabled
- Background `resolveSignatureOnly` revalidation detects server-side drift (e.g., saved menu changes, overlay-part changes, or theme token updates) and marks stored results as stale; this costs a server round-trip and context collection but no model call

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `NavigationRecommendations()` in `src/inspector/NavigationRecommendations.js` | Renders the advisory navigation section inside block recommendations |
| Input builder | `buildNavigationFetchInput()` | Normalizes the request contract from the selected navigation block |
| Store request | `fetchNavigationRecommendations()` in `src/store/index.js` | Sends the request and stores block-scoped results |
| Store revalidation | `revalidateNavigationReviewFreshness()` in `src/store/index.js` | Background `resolveSignatureOnly` check for server-side drift |
| REST handler | `Agent_Controller::handle_recommend_navigation()` | Adapts the REST request to the backend ability |
| Backend ability | `NavigationAbilities::recommend_navigation()` | Builds context, prompt, and parsed advisory output; returns `reviewContextSignature` |
| Prompt contract | `NavigationPrompt::build_user()` / `NavigationPrompt::parse_response()` | Defines the structure of the returned guidance |

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/recommend-navigation`
- Ability: `flavor-agent/recommend-navigation`

## Key Implementation Files

- `src/inspector/NavigationRecommendations.js`
- `src/components/CapabilityNotice.js` — shared backend-unavailable notice; see `docs/reference/shared-internals.md`
- `src/components/AIStatusNotice.js` — shared contextual status feedback; see `docs/reference/shared-internals.md`
- `src/components/RecommendationHero.js` — shared featured next-step shell; see `docs/reference/shared-internals.md`
- `src/components/RecommendationLane.js` — shared grouped-lane shell; see `docs/reference/shared-internals.md`
- `src/store/index.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/LLM/NavigationPrompt.php`
- `inc/Context/ServerCollector.php`
