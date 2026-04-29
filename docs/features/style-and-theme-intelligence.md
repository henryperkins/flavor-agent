# Style And Theme Intelligence

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Global Styles location: Site Editor Global Styles sidebar while the active complementary area is `edit-site/global-styles`
- Global Styles mounts: portal into `.editor-global-styles-sidebar__panel`, with `PluginDocumentSettingPanel` fallback named `AI Style Suggestions`
- Style Book location: Site Editor Styles sidebar while the native Style Book example is active
- Style Book mounts: portal into the same styles sidebar slot, with `PluginDocumentSettingPanel` fallback named `AI Style Book Suggestions`
- Global Styles scope contract: the current `root/globalStyles` entity resolved from `core.__experimentalGetCurrentGlobalStylesId()` plus `getEditedEntityRecord( 'root', 'globalStyles', id )`
- Style Book scope contract: the same `root/globalStyles` entity plus the active Style Book target block name/title
- Shared identifiers: JS surfaces `global-styles` and `style-book`, localized capability keys `globalStyles` and `styleBook`, activity scope keys `global_styles:<id>` and `style_book:<id>:<blockName>`

## Surfacing Conditions

- `GlobalStylesRecommender()` renders only when the native Styles sidebar is active and Style Book is not the current target
- `StyleBookRecommender()` renders only when the native Style Book example target is active
- Both panels stay visible with a capability notice when the corresponding localized capability flag is false; readiness uses the shared `SurfaceCapabilities` payload exposed through localized bootstrap data and `flavor-agent/check-status`
- Requests require an active Flavor Agent chat backend plus `edit_theme_options`
- Apply and undo stay available only while the current scope and live user config still match the recorded activity contract

## Shared Interaction Model

- Learned-once sequence: scope/freshness -> prompt -> featured recommendation -> grouped lanes -> review where needed -> apply where allowed -> undo and history
- Shared normalized states: `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, `error`
- Global Styles suggestions move `idle -> loading -> advisory-ready` when results arrive, then `preview-ready` only after the user explicitly opens review on an executable suggestion
- Preview uses the shared `AIReviewSection` shell and post-apply / post-undo feedback uses the shared status notice pattern
- Global Styles and Style Book now share the same scope badges, prompt shell, featured recommendation hero, `Review first` / `Manual ideas` lanes, compact operation previews, and inline apply/undo notice placement inside the active review shell
- These surfaces intentionally stay portal-first when the native Styles sidebar is available and only fall back to document panels when that sidebar mount point cannot be resolved

## End-To-End Flow

1. `GlobalStylesRecommender()` verifies that the current Site Editor complementary area is `edit-site/global-styles`
2. `getGlobalStylesUserConfig()` resolves the current `root/globalStyles` entity id, the current user config, and any available theme style variations
3. `collectThemeTokens()`, `buildTemplateStructureSnapshot()`, and `collectViewportVisibilitySummary()` contribute theme-token diagnostics plus the live template structure/visibility snapshot that the active styles surface posts through `fetchGlobalStylesRecommendations()` or `fetchStyleBookRecommendations()` to `POST /flavor-agent/v1/recommend-style`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_style()` adapts the request to `FlavorAgent\Abilities\StyleAbilities::recommend_style()`
5. `StyleAbilities::recommend_style()` folds the Site Editor scope, current configs, available variations or Style Book target details, live template structure/visibility, design semantics, `ServerCollector::for_tokens()`, and supported style paths into the style context, computes a docs-free `reviewContextSignature` from the review-oriented server context and `resolvedContextSignature` from the apply context plus the sanitized prompt, returns early for signature-only revalidation, and only then resolves docs guidance before calling `ResponsesClient::rank()`
6. `FlavorAgent\LLM\StylePrompt` constrains the response to validated `set_styles`, `set_block_styles`, and Global Styles-only `set_theme_variation` operations
7. The UI renders advisory or executable suggestion cards; executable cards enter preview before apply
8. `applyGlobalStylesSuggestion()` or `applyStyleBookSuggestion()` runs the deterministic executor, records before/after user config, and persists activity
9. `undoActivity()` delegates style-surface undo to `undoGlobalStyleSuggestionOperations()`, which restores the previous user config only while the live post-apply config still matches the recorded activity state

## Flow Diagram

```text
User opens the Site Editor Styles sidebar
  -> GlobalStylesRecommender or StyleBookRecommender
  -> getGlobalStylesUserConfig() + collectThemeTokens()
  -> buildRequestInput()
  -> fetchGlobalStylesRecommendations() or fetchStyleBookRecommendations()
  -> POST /flavor-agent/v1/recommend-style
  -> Agent_Controller::handle_recommend_style()
  -> StyleAbilities::recommend_style()
  -> ServerCollector::for_tokens()
  -> StylePrompt::parse_response()
  -> review validated operations
  -> applyGlobalStylesSuggestion() or applyStyleBookSuggestion()
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
    },
    "templateStructure": [
      {
        "name": "core/template-part",
        "innerBlocks": [{ "name": "core/site-title" }]
      },
      {
        "name": "core/group",
        "innerBlocks": [{ "name": "core/query-title" }]
      }
    ],
    "templateVisibility": {
      "hasVisibilityRules": false,
      "blockCount": 0,
      "blocks": []
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
  "explanation": "The current theme already exposes a registered variation that moves the site toward the requested tone.",
  "reviewContextSignature": "sha256-of-surface-review-context-and-prompt",
  "resolvedContextSignature": "sha256-of-surface-apply-context-and-prompt"
}
```

## Example Style Book Request

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
    },
    "templateStructure": [
      {
        "name": "core/template-part",
        "innerBlocks": [{ "name": "core/heading" }]
      },
      {
        "name": "core/group",
        "innerBlocks": [{ "name": "core/paragraph" }]
      }
    ],
    "templateVisibility": {
      "hasVisibilityRules": false,
      "blockCount": 0,
      "blocks": []
    }
  },
  "prompt": "Make this block feel more editorial."
}
```

## Request Freshness And Live Context

- Global Styles and Style Book still build a local request signature from `surface + scope + prompt + contextSignature` so stale cards and disabled review/apply state react immediately when the prompt or live style context changes.
- Normal responses also store docs-free `reviewContextSignature` and a server `resolvedContextSignature`. Review freshness uses the review hash, while apply safety uses the resolved hash derived from the server-normalized style apply context plus the sanitized prompt after full theme tokens and Style Book block-manifest context have been resolved.
- `applyGlobalStylesSuggestion()` and `applyStyleBookSuggestion()` keep the local stale guard first, then re-post the same request with `resolveSignatureOnly: true` and only allow the deterministic executor to run when the current `resolvedContextSignature` still matches the stored result; docs grounding changes alone do not stale review or apply state.
- The current ready-result prompt is hydrated back into the composer once per result token, which keeps preloaded or restored results fresh on mount and only marks them stale after the user edits the prompt or the live style context changes.
- `styleContext.templateStructure` and `styleContext.templateVisibility` always come from the live editor canvas. They are sent alongside `currentConfig`, `mergedConfig`, design semantics, and supported-path metadata so style prompts and docs grounding stay aligned with the unsaved template the user is actually previewing.
- Style Book docs grounding uses the shared cache/fallback collector. Exact, family, and block-scoped entity cache hits are reused immediately; on generic or missing fallback guidance, a full request may perform a foreground docs warm before queuing async warming. Docs churn still stays out of the review and apply freshness signatures.
- When a result becomes stale, the surfaces keep the previous cards visible for comparison but disable review/apply until the user refreshes against the live context.

## What This Surface Can Do

- Recommend `theme.json`-safe site-level color, typography, spacing, border, and shadow changes that map to supported Global Styles paths
- Recommend `theme.json`-safe per-block Style Book changes that map to supported `styles.blocks` paths for the active block type
- Recommend switching Global Styles to a registered theme style variation
- Keep theme-token source diagnostics attached to the request contract so degraded token sourcing is visible to the backend
- Record applied Global Styles and Style Book changes in the shared activity system and expose inline undo when the live state still matches the recorded post-apply config

## Supported Executable Operations

- `set_styles`
- `set_block_styles`
- `set_theme_variation`

`set_styles` is valid only for supported site-level style paths and must use theme-backed values when the path points at a preset family. `set_block_styles` is valid only for supported Style Book block paths and must stay inside the validated `styles.blocks[ blockName ]` contract. `set_theme_variation` is valid only on the `global-styles` surface when the referenced variation still exists in the Site Editor runtime, and Style Book suggestions reject it outright.

## Guardrails And Failure Modes

- The style surfaces are hidden outside the Site Editor Styles sidebar
- Executable changes are limited to validated `theme.json` channels; raw CSS, `customCSS`, nested `style.css`, unsupported controls, width/height transforms, and pseudo-element-only operations are out of scope
- Unsupported or ambiguous ideas stay advisory-only instead of entering the apply path
- Apply is also blocked when the stored `resolvedContextSignature` no longer matches the current server-resolved apply context
- Undo is unavailable once the active scope changes or the live user config drifts away from the recorded post-apply state

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `GlobalStylesRecommender()` in `src/global-styles/GlobalStylesRecommender.js` | Renders the Global Styles panel, prompt, featured recommendation, grouped lanes, review flow, activity, and undo |
| UI shell | `StyleBookRecommender()` in `src/style-book/StyleBookRecommender.js` | Renders the Style Book panel, prompt, featured recommendation, grouped lanes, review flow, activity, and undo |
| Scope/runtime helpers | `getGlobalStylesUserConfig()` in `src/utils/style-operations.js` | Resolves the current `root/globalStyles` entity and available variations |
| Input builder | `buildRequestInput()` in `src/global-styles/GlobalStylesRecommender.js` and `src/style-book/StyleBookRecommender.js` | Normalizes the recommendation request payload |
| Store request | `fetchGlobalStylesRecommendations()` / `fetchStyleBookRecommendations()` in `src/store/index.js` | Send the recommendation request |
| Store apply | `applyGlobalStylesSuggestion()` / `applyStyleBookSuggestion()` in `src/store/index.js` | Run deterministic apply and record activity |
| Deterministic executor | `applyGlobalStyleSuggestionOperations()` in `src/utils/style-operations.js` | Validates and executes the structured style operation set |
| Undo validation | `getGlobalStylesActivityUndoState()` in `src/utils/style-operations.js` | Verifies the live state before undo is exposed |
| REST handler | `Agent_Controller::handle_recommend_style()` | Adapts the REST request to the backend ability |
| Backend ability | `StyleAbilities::recommend_style()` | Builds context and returns validated style suggestions |
| Prompt contract | `StylePrompt::build_system()` / `StylePrompt::build_user()` / `StylePrompt::parse_response()` | Defines and validates the structured style suggestion format |

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/recommend-style`
- Ability: `flavor-agent/recommend-style`
- Readiness and diagnostics: `flavor-agent/check-status`

## Key Implementation Files

- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/utils/style-operations.js`
- `src/context/theme-tokens.js` — design token extraction and execution contract builders; see `docs/reference/shared-internals.md`
- `src/utils/editor-context-metadata.js` — viewport visibility summaries for LLM context; see `docs/reference/shared-internals.md`
- `src/utils/template-types.js` — template slug normalization for scope derivation; see `docs/reference/shared-internals.md`
- `src/components/CapabilityNotice.js` — shared backend-unavailable notice; see `docs/reference/shared-internals.md`
- `src/components/AIStatusNotice.js` — shared contextual status feedback; see `docs/reference/shared-internals.md`
- `src/components/AIReviewSection.js` — shared review-before-apply panel; see `docs/reference/shared-internals.md`
- `src/store/index.js`
- `src/store/activity-history.js` — scope resolution for Global Styles and Style Book; see `docs/reference/shared-internals.md`
- `src/components/ActivitySessionBootstrap.js`
- `src/components/AIActivitySection.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `inc/LLM/StylePrompt.php`
- `inc/Context/ServerCollector.php`
