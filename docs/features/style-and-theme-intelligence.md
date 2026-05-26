# Style And Theme Intelligence

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Global Styles location: Site Editor Global Styles sidebar while the active complementary area is `edit-site/global-styles`
- Global Styles mounts: portal into `.editor-global-styles-sidebar__panel`; if the native Styles sidebar mount cannot be resolved, the recommender returns `null`
- Style Book location: Site Editor Styles sidebar while the native Style Book example is active
- Style Book mounts: portal into the same styles sidebar slot; if the native Styles sidebar mount cannot be resolved, the recommender returns `null`
- Global Styles scope contract: `getGlobalStylesUserConfig()` resolves the current `root/globalStyles` entity id, user config, merged config, and available variations
- Style Book scope contract: the same `root/globalStyles` entity plus the active Style Book target block name/title when one resolves; when the Style Book UI is active, the panel can render a scope notice before a valid registered example block is selected
- Shared identifiers: JS surfaces `global-styles` and `style-book`, localized capability keys `globalStyles` and `styleBook`, activity scope keys `global_styles:<id>` and `style_book:<id>:<blockName>`

## Surfacing Conditions

- `GlobalStylesRecommender()` renders only when the native Styles sidebar is active and Style Book is not the current target
- `StyleBookRecommender()` renders when the Site Editor Styles sidebar and Style Book UI are active; recommendation requests stay disabled with a scope notice until the native Style Book example target resolves to a registered block
- Within those active Styles sidebar contexts, both panels always mount the shared `CapabilityNotice`; that component renders nothing while the corresponding localized capability flag is ready and renders the setup/capability notice when it is false. Outside those contexts the recommender components return `null`. Readiness uses the shared `SurfaceCapabilities` payload exposed through localized bootstrap data and `flavor-agent/check-status`
- Requests require an active Flavor Agent chat backend plus `edit_theme_options`
- Apply and undo stay available only while the current scope and live user config still match the recorded activity contract

## Shared Interaction Model

- Learned-once sequence: scope/freshness -> prompt -> featured recommendation -> grouped lanes -> review where needed -> apply where allowed -> undo and history
- Shared normalized states: `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, `error`
- Global Styles suggestions move `idle -> loading -> advisory-ready` when results arrive, then `preview-ready` only after the user explicitly opens review on an executable suggestion
- Preview uses the shared `AIReviewSection` shell and post-apply / post-undo feedback uses the shared status notice pattern
- Global Styles and Style Book now share the same scope badges, prompt shell, featured recommendation hero, `Review first` / `Manual ideas` lanes, compact operation previews, and inline apply/undo notice placement inside the active review shell
- These surfaces intentionally stay native Styles-sidebar only; missing sidebar mount nodes are treated as mount regressions rather than falling back to post-editor document panels

## End-To-End Flow

1. `GlobalStylesRecommender()` verifies that the current Site Editor complementary area is `edit-site/global-styles`
2. `getGlobalStylesUserConfig()` resolves the current `root/globalStyles` entity id, the current user config, and any available theme style variations
3. `collectThemeTokens()`, `buildTemplateStructureSnapshot()`, and `collectViewportVisibilitySummary()` contribute theme-token diagnostics plus the live template structure/visibility snapshot that the active styles surface sends through `fetchGlobalStylesRecommendations()` or `fetchStyleBookRecommendations()` to the `flavor-agent/recommend-style` ability
4. `FlavorAgent\Abilities\RecommendationAbilityExecution` adapts the request to `FlavorAgent\Abilities\StyleAbilities::recommend_style()`
5. `StyleAbilities::recommend_style()` folds the Site Editor scope, current configs, available variations or Style Book target details, live template structure/visibility, design semantics, `ServerCollector::for_tokens()`, and supported style paths into the style context, resolves docs guidance, computes `reviewContextSignature` and `resolvedContextSignature` with the docs-grounding fingerprint, returns early for signature-only revalidation, then calls `ResponsesClient::rank()`
6. `FlavorAgent\LLM\StylePrompt` constrains the response to validated `set_styles`, `set_block_styles`, and Global Styles-only `set_theme_variation` operations
7. The UI renders advisory or executable suggestion cards; executable cards enter preview before apply
8. `applyGlobalStylesSuggestion()` or `applyStyleBookSuggestion()` runs the deterministic executor, records before/after user config, and persists activity
9. `undoActivity()` delegates style-surface undo to `undoGlobalStyleSuggestionOperations()`, which restores the previous user config only while the live post-apply config still matches the recorded activity state

## Flow Diagram

```text
User opens the Site Editor Styles sidebar
  -> GlobalStylesRecommender or StyleBookRecommender
  -> getGlobalStylesUserConfig() + collectThemeTokens()
  -> buildStyleRecommendationRequestInput()
  -> fetchGlobalStylesRecommendations() or fetchStyleBookRecommendations()
  -> flavor-agent/recommend-style ability
  -> RecommendationAbilityExecution
  -> StyleAbilities::recommend_style()
  -> ServerCollector::for_tokens()
  -> StylePrompt::parse_response()
  -> review validated operations
  -> applyGlobalStylesSuggestion() or applyStyleBookSuggestion()
  -> activity + inline undo
```

## Contract Pointers

- Global Styles and Style Book request shapes: `docs/reference/abilities-and-routes.md#style-ability-request` and `docs/reference/abilities-and-routes.md#style-book-ability-request`
- Operation vocabulary and constraints: `docs/reference/template-operations.md#style-operations`
- Activity entry shape and undo lifecycle: `docs/reference/abilities-and-routes.md#activity-entry-shape` and `docs/reference/activity-state-machine.md`

## Request Freshness And Live Context

- Global Styles and Style Book still build a local request signature from `surface + scope + prompt + contextSignature` so stale cards and disabled review/apply state react immediately when the prompt or live style context changes.
- Normal responses also store `reviewContextSignature`, `resolvedContextSignature`, and `docsGroundingFingerprint`. Review freshness uses the review hash, while apply safety uses the resolved hash derived from the server-normalized style apply context plus the sanitized prompt after full theme tokens, Style Book block-manifest context, and docs grounding fingerprint have been resolved.
- `applyGlobalStylesSuggestion()` and `applyStyleBookSuggestion()` keep the local stale guard first, then re-post the same request with `resolveSignatureOnly: true` and only allow the deterministic executor to run when the current `resolvedContextSignature` still matches the stored result. Signature-only docs grounding can mark the stored result stale when trusted grounding is unavailable; stale or degraded trusted grounding remains reviewable with warning metadata.
- The current ready-result prompt is hydrated back into the composer once per result token, which keeps preloaded or restored results fresh on mount and only marks them stale after the user edits the prompt or the live style context changes.
- `styleContext.templateStructure` and `styleContext.templateVisibility` always come from the live editor canvas. They are sent alongside `currentConfig`, `mergedConfig`, design semantics, and supported-path metadata so style prompts and docs grounding stay aligned with the unsaved template the user is actually previewing.
- Global Styles and Style Book docs grounding use the shared cache/fallback collector. Exact, family, and block-scoped entity cache hits are reused immediately; on generic or missing fallback guidance, a full request may perform a foreground docs warm before queuing async warming. Review and apply freshness signatures include a compact docs-grounding fingerprint.
- When a result becomes stale, the surfaces keep the previous cards visible for comparison but disable review/apply until the user refreshes against the live context.

## What This Surface Can Do

- Recommend `theme.json`-safe site-level color, typography, spacing, border, and shadow changes that map to supported Global Styles paths
- Recommend `theme.json`-safe per-block Style Book changes that map to supported `styles.blocks` paths for the active block type
- Recommend switching Global Styles to a registered theme style variation
- Keep theme-token source diagnostics attached to the request contract so degraded token sourcing is visible to the backend
- Record applied Global Styles and Style Book changes in the shared activity system and expose inline undo when the live state still matches the recorded post-apply config

## Operation Contract

Style operation types, surface restrictions, preset requirements, and variation constraints are canonical in `docs/reference/template-operations.md#style-operations`.

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
| Input builder | `buildStyleRecommendationRequestInput()` in `src/style-surfaces/request-input.js` | Normalizes the recommendation request payload shared by Global Styles and Style Book |
| Store request | `fetchGlobalStylesRecommendations()` / `fetchStyleBookRecommendations()` in `src/store/index.js` | Send the recommendation request |
| Store apply | `applyGlobalStylesSuggestion()` / `applyStyleBookSuggestion()` in `src/store/index.js` | Run deterministic apply and record activity |
| Deterministic executor | `applyGlobalStyleSuggestionOperations()` in `src/utils/style-operations.js` | Validates and executes the structured style operation set |
| Undo validation | `getGlobalStylesActivityUndoState()` in `src/utils/style-operations.js` | Verifies the live state before undo is exposed |
| Ability wrapper | `RecommendationAbilityExecution::execute()` | Adapts the ability request to the backend handler |
| Backend ability | `StyleAbilities::recommend_style()` | Builds context and returns validated style suggestions |
| Prompt contract | `StylePrompt::build_system()` / `StylePrompt::build_user()` / `StylePrompt::parse_response()` | Defines and validates the structured style suggestion format |

## Related Abilities

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
- `src/store/abilities-client.js`
- `inc/Abilities/RecommendationAbilityExecution.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `inc/LLM/StylePrompt.php`
- `inc/Context/ServerCollector.php`
