# Recommendation Architecture Simplification Audit

Date: 2026-04-08

## Short Architecture Summary

Flavor Agent does not have one generic recommendation pipeline. The shipped code resolves into four distinct families that share a transport and activity backbone, but intentionally diverge in context shape, safety model, and execution model:

- `block` is a direct-apply surface for safe local attribute changes driven by `src/inspector/BlockRecommendationsPanel.js`, `src/store/index.js::fetchBlockRecommendations()`, `src/store/index.js::applySuggestion()`, `inc/REST/Agent_Controller.php::handle_recommend_block()`, and `inc/Abilities/BlockAbilities.php::recommend_block()`.
- `navigation` is advisory-only and stops at ranked guidance from `src/inspector/NavigationRecommendations.js`, `inc/REST/Agent_Controller.php::handle_recommend_navigation()`, and `inc/Abilities/NavigationAbilities.php::recommend_navigation()`.
- `pattern` is a retrieval and reranking pipeline that patches the inserter, not an apply or review surface, via `src/patterns/PatternRecommender.js` and `inc/Abilities/PatternAbilities.php::recommend_patterns()`.
- `template`, `template-part`, `global-styles`, and `style-book` are preview and review surfaces that validate executable operations before apply and persist undoable activity through `src/store/index.js`, `inc/REST/Agent_Controller.php`, `inc/Abilities/TemplateAbilities.php`, `inc/Abilities/StyleAbilities.php`, `inc/LLM/TemplatePrompt.php`, `inc/LLM/TemplatePartPrompt.php`, and `inc/LLM/StylePrompt.php`.

The shared backbone is real, but narrower than the noun "recommendation" suggests:

- JS surface state and interaction contracts live in `src/store/index.js::SURFACE_INTERACTION_CONTRACT`, `src/store/index.js::getNormalizedInteractionState()`, and `src/store/index.js::guardSurfaceApplyFreshness()`.
- REST request metadata is centralized in `inc/REST/Agent_Controller.php::append_request_meta_for_route()` and `inc/REST/Agent_Controller.php::append_request_meta_to_error_for_route()`.
- WordPress docs grounding is already centralized in `inc/Support/CollectsDocsGuidance.php::collect()`.
- Activity and undo persistence are already shared through `src/store/index.js`, `src/store/activity-history.js`, and the surface-specific apply helpers.

The biggest simplification opportunity is not "merge all recommenders." It is to move shared lifecycle ownership into clearer modules while preserving the distinct surface contracts.

## Surface-By-Surface Comparison

| Surface | End-to-end path | Live client context | Server-derived context | Interaction model | Freshness model | Shared infrastructure |
| --- | --- | --- | --- | --- | --- | --- |
| `block` | `src/inspector/BlockRecommendationsPanel.js` -> `src/store/index.js::fetchBlockRecommendations()` / `applySuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_block()` -> `inc/Abilities/BlockAbilities.php::recommend_block()` -> `inc/LLM/Prompt.php` | `src/context/collector.js::collectBlockContext()` sends selected block attributes, structural identity, siblings, branch context, editing mode, and client-only constraints | `inc/Context/BlockContextCollector.php::for_block()` adds block registry introspection and `themeTokens`; `BlockAbilities::build_context_from_editor_context()` overlays client-only fields back on top of server context | Safe direct apply for local block attributes only; advisory ideas still allowed | Client-only signature from `src/utils/block-recommendation-context.js::buildBlockRecommendationContextSignature()` and request signature from `src/utils/recommendation-request-signature.js::buildBlockRecommendationRequestSignature()` | Shared request state, stale apply guard, request meta, activity logging |
| `navigation` | `src/inspector/NavigationRecommendations.js` -> `src/store/index.js::fetchNavigationRecommendations()` -> `inc/REST/Agent_Controller.php::handle_recommend_navigation()` -> `inc/Abilities/NavigationAbilities.php::recommend_navigation()` -> `inc/LLM/NavigationPrompt.php` | Serialized navigation markup, menu ref, structural identity, siblings, and branch metadata from `src/inspector/NavigationRecommendations.js::buildNavigationFetchInput()` | `inc/Context/ServerCollector.php::for_navigation()` resolves structure and overlay-specific context | Advisory-only; no apply or undo | Client-side stringified fetch input from `src/inspector/NavigationRecommendations.js::buildNavigationContextSignature()` | Shared request state, request meta, docs grounding |
| `pattern` | `src/patterns/PatternRecommender.js` -> `src/store/index.js::fetchPatternRecommendations()` -> `inc/REST/Agent_Controller.php::handle_recommend_patterns()` -> `inc/Abilities/PatternAbilities.php::recommend_patterns()` | Inserter root, ancestor stack, nearby siblings, template-part area and slug, container layout, visible pattern names | Pattern index runtime state, embeddings, Qdrant collection compatibility, docs guidance, semantic and structural reranking | Browse and rank only; no preview/apply/undo | No review/apply freshness contract; runtime staleness is owned by `PatternIndex` and backend compatibility checks in `PatternAbilities::recommend_patterns()` | Shared REST and request meta only |
| `template` | `src/templates/TemplateRecommender.js` -> `src/templates/template-recommender-helpers.js` -> `src/store/index.js::fetchTemplateRecommendations()` / `applyTemplateSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_template()` -> `inc/Abilities/TemplateAbilities.php::recommend_template()` -> `inc/LLM/TemplatePrompt.php` | Live slot snapshot, top-level structure snapshot, pattern override summary, viewport visibility summary, visible pattern names | `inc/Context/TemplateContextCollector.php::for_template()` resolves template entity, available parts, allowed and empty areas, candidate patterns, and theme tokens; `TemplateAbilities::apply_template_live_slot_context()` and `apply_template_live_structure_context()` overlay live editor state | Review-before-apply with validated `assign_template_part`, `replace_template_part`, and `insert_pattern` operations | Client-only signature from `buildTemplateRecommendationContextSignature()` plus request signature from `buildTemplateRecommendationRequestSignature()` | Shared preview/apply store lifecycle, activity logging, request meta |
| `template-part` | `src/template-parts/TemplatePartRecommender.js` -> `src/template-parts/template-part-recommender-helpers.js` -> `src/store/index.js::fetchTemplatePartRecommendations()` / `applyTemplatePartSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_template_part()` -> `inc/Abilities/TemplateAbilities.php::recommend_template_part()` -> `inc/LLM/TemplatePartPrompt.php` | Live block tree, flat path lookup, operation targets, insertion anchors, structural constraints, visible pattern names | `inc/Context/TemplatePartContextCollector.php::for_template_part()` resolves entity, pattern candidates, theme tokens, and server structure defaults; `TemplateAbilities::apply_template_part_live_structure_context()` overlays live structure | Review-before-apply with validated `insert_pattern`, `replace_block_with_pattern`, and `remove_block` operations | Client-only signature from `buildTemplatePartRecommendationContextSignature()` plus request signature from `buildTemplatePartRecommendationRequestSignature()` | Shared preview/apply store lifecycle, activity logging, request meta |
| `global-styles` | `src/global-styles/GlobalStylesRecommender.js` -> `src/store/index.js::fetchGlobalStylesRecommendations()` / `applyGlobalStylesSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_style()` -> `inc/Abilities/StyleAbilities.php::recommend_style()` -> `inc/LLM/StylePrompt.php` -> `src/utils/style-operations.js::applyGlobalStyleSuggestionOperations()` | Live user config, merged config, style variations, template structure, viewport visibility, design semantics, theme token diagnostics, execution contract | `inc/Abilities/StyleAbilities.php::build_global_styles_context()` and `build_shared_style_context()` add `themeTokens`, supported style paths, active variation resolution, and docs guidance | Review-before-apply with validated `set_theme_variation` and `set_styles` operations | Client-only signature from `src/utils/style-operations.js::buildGlobalStylesRecommendationContextSignature()` plus request signature from `buildGlobalStylesRecommendationRequestSignature()` | Shared preview/apply store lifecycle, request meta, activity/undo persistence |
| `style-book` | `src/style-book/StyleBookRecommender.js` -> `src/store/index.js::fetchStyleBookRecommendations()` / `applyStyleBookSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_style()` -> `inc/Abilities/StyleAbilities.php::recommend_style()` -> `inc/LLM/StylePrompt.php` -> `src/utils/style-operations.js::applyGlobalStyleSuggestionOperations()` | Live global styles config plus target block branch, template structure, viewport visibility, design semantics, theme token diagnostics, client execution contract | `StyleAbilities::build_style_book_context()` and `build_shared_style_context()` add server `themeTokens`, supported block style paths, and `blockManifest` from `inc/Context/ServerCollector.php::introspect_block_type()` | Review-before-apply with validated `set_block_styles` operations | Client-only signature from `buildGlobalStylesRecommendationContextSignature()` plus request signature from `buildStyleBookRecommendationRequestSignature()` | Same shared preview/apply runtime as `global-styles` |
| `content` | `src/store/index.js::fetchContentRecommendations()` -> `inc/REST/Agent_Controller.php::handle_recommend_content()` -> `inc/Abilities/ContentAbilities.php::recommend_content()` -> `inc/LLM/WritingPrompt.php` | Prompt, mode, voice profile, post context | No shared block/template/style collector stage; no docs grounding collector | Advisory draft/edit/critique scaffold only | Request token only; no review/apply freshness contract | Shares request state and request meta plumbing, but not the shipped structural recommendation runtime |

## Findings Ordered By Severity / Opportunity

### 1. High: Server-derived freshness drift is real, but the proposed stale-guard fix needs a revalidation path

The executable surfaces already capture a large amount of live editor context on the client. The remaining gap is narrower than "freshness is client-side" suggests: some prompt-shaping inputs are still resolved only on the server, while both stale UI and apply guards are currently computed entirely from client-built request signatures.

Evidence:

- JS request signatures are built on the client in `src/utils/recommendation-request-signature.js::buildTemplateRecommendationRequestSignature()`, `buildTemplatePartRecommendationRequestSignature()`, `buildGlobalStylesRecommendationRequestSignature()`, and `buildStyleBookRecommendationRequestSignature()`.
- Template and template-part signatures already include live editor slots, structure, anchors, constraints, and visible patterns through `src/templates/template-recommender-helpers.js::buildTemplateRecommendationContextSignature()` and `src/template-parts/template-part-recommender-helpers.js::buildTemplatePartRecommendationContextSignature()`.
- Style signatures already include client execution contracts and token diagnostics in `src/utils/style-operations.js::buildGlobalStylesRecommendationContextSignature()`, so supported style paths are already covered by the current client freshness model.
- The store removes `contextSignature` before the REST request body is sent in `src/store/index.js::fetchTemplateRecommendations()`, `fetchTemplatePartRecommendations()`, `fetchGlobalStylesRecommendations()`, and `fetchStyleBookRecommendations()`. The signature is stored locally for stale checks, not consumed server-side.
- `src/store/index.js::guardSurfaceApplyFreshness()` compares only stored and current client request signatures, and the same comparison drives stale/fresh UI in `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `src/global-styles/GlobalStylesRecommender.js`, and `src/style-book/StyleBookRecommender.js`.
- The server still injects prompt-shaping data in `inc/Context/TemplateContextCollector.php::for_template()`, `inc/Context/TemplatePartContextCollector.php::for_template_part()`, `inc/Abilities/StyleAbilities.php::build_shared_style_context()`, `inc/Abilities/StyleAbilities.php::build_style_book_context()`, `inc/Context/BlockContextCollector.php::for_block()`, and `inc/Support/CollectsDocsGuidance.php::collect()`.

Impact:

- A result can still be treated as fresh after true server-derived inputs drift, including candidate patterns, available template parts, server token manifests, style-book block manifests, provider-resolved docs guidance, or backend capability changes.
- This is most important on `template`, `template-part`, `global-styles`, and `style-book`, because those surfaces allow later review/apply decisions based on the stored result.
- The originally proposed "stored server fingerprint vs current server fingerprint" comparison cannot become the source of truth by itself, because the current apply path is local-only after the stale check and has no way to obtain a current server fingerprint.

Recommendation:

- Keep the current client request signatures as the immediate stale UI and optimistic invalidation baseline.
- If server-backed freshness is needed on review-before-apply surfaces, add both:
  - a PHP helper such as `inc/Support/RecommendationRequestFingerprint.php` that fingerprints only unresolved server-derived inputs in the resolved request context
  - a lightweight revalidation path in `inc/REST/Agent_Controller.php` that can return the current fingerprint for a surface/scope before apply or before treating a stored result as fresh
- Update `src/store/index.js` and the surface panels so stale UI and apply guards consume the same stored-vs-current comparison source.
- Keep the scope narrow in the first rollout: `template`, `template-part`, `global-styles`, and `style-book`. `block` can stay on the current client-signature model unless product requirements show a real server-side freshness problem there.

### 2. Medium-High: The store already has a shared executable-surface contract, but the fetch/apply runtime is still duplicated

`src/store/index.js` already knows that `template`, `template-part`, `global-styles`, and `style-book` are the same kind of interaction surface, but the action implementations still repeat nearly the same lifecycle code.

Evidence:

- Shared contract and state normalizers already exist in `src/store/index.js::SURFACE_INTERACTION_CONTRACT`, `getNormalizedInteractionState()`, `isSurfaceApplyAllowedForState()`, and `guardSurfaceApplyFreshness()`.
- The fetch lifecycles for `fetchTemplateRecommendations()`, `fetchTemplatePartRecommendations()`, `fetchGlobalStylesRecommendations()`, and `fetchStyleBookRecommendations()` repeat the same `runAbortableRecommendationRequest()` shape with surface-local request builders, loading handlers, success handlers, and signature plumbing.
- The apply lifecycles for `applyTemplateSuggestion()`, `applyTemplatePartSuggestion()`, `applyGlobalStylesSuggestion()`, and `applyStyleBookSuggestion()` repeat the same stale guard, apply-state transition, activity recording, and success state updates.

Impact:

- The file stays hard to reason about because lifecycle behavior is shared conceptually but not packaged as a reusable module.
- Any future change to stale handling, request meta propagation, review selection, or activity persistence needs to be patched in four places.

Recommendation:

- Extract a shared executable surface runtime module, for example `src/store/executable-surface-runtime.js`.
- Exact owner responsibilities:
  - shared request builder wrapper around `runAbortableRecommendationRequest()`
  - shared stale-guarded apply wrapper
  - shared activity record success path
  - shared recommendation payload storage conventions
- Thin adapters should remain in `src/store/index.js` for:
  - request shape and endpoint
  - surface-specific selectors and action creators
  - the actual executor: `applyTemplateSuggestionOperations()`, `applyTemplatePartSuggestionOperations()`, or `applyGlobalStyleSuggestionOperations()`

Do not include `block`, `navigation`, or `pattern` in this extraction. Their interaction models are materially different.

### 3. Medium: Template and template-part live-structure overlays are converging on the same pattern with different entity vocabularies

The template surfaces already share the same architectural move: server-derived default context is overlaid with live editor structure before prompting and validation. The pattern is sound, but the overlay utilities are split across two JS helper files and one large PHP ability class.

Evidence:

- Template JS snapshot and signature helpers live in `src/templates/template-recommender-helpers.js::buildEditorTemplateSlotSnapshot()`, `buildEditorTemplateTopLevelStructureSnapshot()`, `buildTemplateRecommendationContextSignature()`, and `buildTemplateFetchInput()`.
- Template-part JS snapshot and signature helpers live in `src/template-parts/template-part-recommender-helpers.js::buildEditorTemplatePartStructureSnapshot()`, `buildTemplatePartRecommendationContextSignature()`, and `buildTemplatePartFetchInput()`.
- PHP overlay logic lives in `inc/Abilities/TemplateAbilities.php::normalize_template_editor_structure()`, `normalize_template_part_editor_structure()`, `apply_template_live_slot_context()`, `apply_template_live_structure_context()`, and `apply_template_part_live_structure_context()`.

Impact:

- The architecture is correct but scattered. It is harder than necessary to verify that client live snapshots, server normalization, and parser validation are still describing the same entity graph.
- The duplication is structural, not semantic. The entity vocabularies should stay separate, but the overlay machinery can be cleaner.

Recommendation:

- Extract shared live-structure overlay helpers, not a generic "template recommender."
- Suggested ownership:
  - JS: new `src/templates/live-structure-snapshots.js` for common path and block-node normalization primitives
  - PHP: new `inc/Support/LiveStructureOverlay.php` or a focused helper under `inc/Abilities/Support/`
- Keep thin surface adapters in:
  - `src/templates/template-recommender-helpers.js`
  - `src/template-parts/template-part-recommender-helpers.js`
  - `inc/Abilities/TemplateAbilities.php`

What must remain distinct:

- Template slot and area semantics
- Template-part block target and insertion anchor semantics
- The separate operation vocabularies validated in `inc/LLM/TemplatePrompt.php::parse_response()` and `inc/LLM/TemplatePartPrompt.php::parse_response()`

### 4. Medium: Global Styles and Style Book should share a stronger JS shell, because they already share the same style operation core

These two surfaces are the cleanest candidate for UI consolidation.

Evidence:

- Both components build nearly the same request lifecycle and stale UI in `src/global-styles/GlobalStylesRecommender.js` and `src/style-book/StyleBookRecommender.js`.
- Both use the same context signature builder in `src/utils/style-operations.js::buildGlobalStylesRecommendationContextSignature()`.
- Both apply through `src/utils/style-operations.js::applyGlobalStyleSuggestionOperations()` and undo through the same activity state helpers.
- Both route through the same REST endpoint `inc/REST/Agent_Controller.php::handle_recommend_style()` and the same server surface split in `inc/Abilities/StyleAbilities.php::build_context_for_surface()`.

Impact:

- UI maintenance cost is higher than necessary.
- Most changes to review UX, stale UX, activity UX, and prompt-shell copy require parallel edits.

Recommendation:

- Extract a shared style surface shell such as `src/style-surfaces/StyleSurfacePanel.js` plus a small `useStyleSurfaceRuntime()` hook.
- Thin adapters should remain in:
  - `src/global-styles/GlobalStylesRecommender.js` for scope resolution, theme variation support, and portal suppression when Style Book is active
  - `src/style-book/StyleBookRecommender.js` for target block resolution, block title and description wiring, and Style Book UI activation rules

Do not merge the server context builders. `StyleAbilities::build_global_styles_context()` and `build_style_book_context()` should remain separate entry points.

### 5. Medium-Low: REST route handlers are already thin adapters, but the input assembly and request-meta plumbing can be formalized

The REST controller repeats a stable pattern across the shipped recommendation routes.

Evidence:

- `inc/REST/Agent_Controller.php::handle_recommend_block()`, `handle_recommend_template()`, `handle_recommend_template_part()`, and `handle_recommend_style()` are mostly "sanitize input -> call ability -> append request meta -> return response," with `handle_recommend_block()` adding a small response envelope.
- `handle_recommend_content()`, `handle_recommend_patterns()`, and `handle_recommend_navigation()` do the same pattern plus activity persistence.
- Request-meta augmentation is already centralized in `append_request_meta_for_route()` and `append_request_meta_to_error_for_route()`.

Impact:

- The controller is not broken, but it is longer than necessary and easy to drift as more surfaces add diagnostics or activity hooks.

Recommendation:

- Keep route registration explicit, but extract a private route executor helper or a small route config table for handler assembly.
- Best owner: stay inside `inc/REST/Agent_Controller.php`; this does not need a framework-level abstraction yet.
- Only extract the repeated mechanics:
  - optional input map assembly
  - ability callback invocation
  - request-meta decoration
  - optional response mapping
  - optional activity persistence hooks

### 6. Low: Prompt parsing has shared envelope work, but validation should stay surface-specific

The prompt classes all repeat JSON cleanup and decoding, but the real complexity is in surface-specific validators.

Evidence:

- `inc/LLM/Prompt.php::parse_response()`, `inc/LLM/TemplatePrompt.php::parse_response()`, `inc/LLM/TemplatePartPrompt.php::parse_response()`, and `inc/LLM/StylePrompt.php::parse_response()` all strip code fences, decode JSON, and normalize an envelope.
- The important validation logic differs materially:
  - block context enforcement in `Prompt::enforce_block_context_rules()`
  - template operation validation in `TemplatePrompt::validate_template_operations()`
  - template-part target and anchor validation in `TemplatePartPrompt::validate_suggestions()`
  - style-path and variation validation in `StylePrompt::validate_operations()`

Recommendation:

- Extract only a small shared JSON decode helper, for example `inc/LLM/JsonResponseDecoder.php`.
- Leave all operation validators and derived-operation logic in their current surface prompt classes.

## Simplify Now Candidates

- **Server-backed freshness fingerprint + revalidation path**
  - New owners: `inc/Support/RecommendationRequestFingerprint.php` and a small revalidation hook inside `inc/REST/Agent_Controller.php`
  - Thin adapters: `TemplateAbilities::recommend_template()`, `TemplateAbilities::recommend_template_part()`, `StyleAbilities::recommend_style()`, `src/store/index.js`, `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `src/global-styles/GlobalStylesRecommender.js`, `src/style-book/StyleBookRecommender.js`

- **Executable surface store runtime**
  - New owner: `src/store/executable-surface-runtime.js`
  - Thin adapters: `src/store/index.js::fetchTemplateRecommendations()`, `fetchTemplatePartRecommendations()`, `fetchGlobalStylesRecommendations()`, `fetchStyleBookRecommendations()`, `applyTemplateSuggestion()`, `applyTemplatePartSuggestion()`, `applyGlobalStylesSuggestion()`, `applyStyleBookSuggestion()`

- **Shared style review shell**
  - New owner: `src/style-surfaces/StyleSurfacePanel.js` and `src/style-surfaces/useStyleSurfaceRuntime.js`
  - Thin adapters: `src/global-styles/GlobalStylesRecommender.js`, `src/style-book/StyleBookRecommender.js`

- **Live structure overlay primitives**
  - New owner: `src/templates/live-structure-snapshots.js` and `inc/Support/LiveStructureOverlay.php`
  - Thin adapters: `src/templates/template-recommender-helpers.js`, `src/template-parts/template-part-recommender-helpers.js`, `inc/Abilities/TemplateAbilities.php`

- **REST handler assembly helper**
  - New owner: private helper inside `inc/REST/Agent_Controller.php`
  - Thin adapters: `handle_recommend_block()`, `handle_recommend_content()`, `handle_recommend_navigation()`, `handle_recommend_patterns()`, `handle_recommend_template()`, `handle_recommend_template_part()`, `handle_recommend_style()`

## Keep Distinct Candidates

- **Pattern retrieval and reranking**
  - Keep `inc/Abilities/PatternAbilities.php::recommend_patterns()` and `src/patterns/PatternRecommender.js` distinct. The embedding, Qdrant, and runtime-state lifecycle is not the same problem as the review/apply surfaces.

- **Navigation advisory-only flow**
  - Keep `src/inspector/NavigationRecommendations.js` and `inc/Abilities/NavigationAbilities.php::recommend_navigation()` distinct. There is no apply contract, and that is intentional.

- **Block direct-apply executor**
  - Keep `src/store/index.js::applySuggestion()`, `src/store/update-helpers.js`, and `inc/Abilities/BlockAbilities.php::recommend_block()` distinct. The local-attribute safety model is unique.

- **Template vs template-part operation vocabularies**
  - Keep `inc/LLM/TemplatePrompt.php` and `inc/LLM/TemplatePartPrompt.php` distinct. Template slot assignment and template-part path operations are not interchangeable.

- **Global Styles vs Style Book scope builders**
  - Keep `inc/Abilities/StyleAbilities.php::build_global_styles_context()` and `build_style_book_context()` distinct. The former is theme-wide and variation-aware; the latter is target-block and manifest-aware.

- **Content scaffold**
  - Keep `inc/Abilities/ContentAbilities.php::recommend_content()` out of any shared executable-surface refactor. It only shares generic store and request plumbing.

## Recommended Phased Plan

### Phase 1: Add a server-backed freshness fingerprint plus a revalidation path

- **Objective**
  - Close the remaining server-derived freshness gap on the review-before-apply surfaces without losing the current immediate client-side stale behavior.
- **File targets**
  - `inc/Abilities/TemplateAbilities.php`
  - `inc/Abilities/StyleAbilities.php`
  - `inc/REST/Agent_Controller.php`
  - `src/store/index.js`
  - `src/templates/TemplateRecommender.js`
  - `src/template-parts/TemplatePartRecommender.js`
  - `src/global-styles/GlobalStylesRecommender.js`
  - `src/style-book/StyleBookRecommender.js`
  - new `inc/Support/RecommendationRequestFingerprint.php`
- **Abstractions to extract or boundaries to redraw**
  - Add a PHP helper that fingerprints only unresolved server-derived inputs from the resolved context, prompt, and surface identity.
  - Return the stored fingerprint in the recommendation payload, ideally alongside existing `requestMeta`.
  - Add a lightweight revalidation path that can return the current fingerprint for a surface/scope before apply or before marking a stored result as fresh.
  - Make `src/store/index.js::guardSurfaceApplyFreshness()` and the panel-level stale/fresh UI consume the same stored-vs-current comparison source.
- **Behavior that must remain unchanged**
  - Existing permissions, suggestion semantics, and local apply executors.
  - Client-side immediate stale UI when prompt or local editor state changes.
- **Risks / regressions**
  - Older stored results may not carry the new fingerprint.
  - Overly broad hashing could mark results stale too often.
  - A pre-apply revalidation request can add latency or transient failure modes if it is required for confirm/apply.
- **Tests / docs that must move with the change**
  - Store stale-guard tests
  - Panel/component tests for template, template-part, global-styles, and style-book stale UI
  - Controller tests for fingerprint payloads and the revalidation path
  - `docs/reference/shared-internals.md`
  - `docs/reference/recommendation-ui-consistency.md`
- **Why this phase comes first**
  - It fixes the highest-risk seam and establishes one freshness source of truth before the surrounding runtime is reorganized.

### Phase 2: Extract a shared executable-surface store runtime

- **Objective**
  - Remove repeated fetch and apply lifecycle code for the four preview-required executable surfaces.
- **File targets**
  - `src/store/index.js`
  - new `src/store/executable-surface-runtime.js`
- **Abstractions to extract or boundaries to redraw**
  - Shared request wrapper
  - Shared stale-guarded apply wrapper
  - Shared activity recording success helper
  - Shared surface result persistence conventions
- **Behavior that must remain unchanged**
  - Public action names and selectors
  - Surface-specific stale copy
  - Activity entry shapes and undo behavior
- **Risks / regressions**
  - Silent state regressions around selected review items or request token handling
- **Tests / docs that must move with the change**
  - Store unit tests for `template`, `template-part`, `global-styles`, and `style-book`
  - `docs/reference/shared-internals.md`
- **Why this phase comes before the next one**
  - The style and template UI layers are easier to simplify once the runtime beneath them is uniform.

### Phase 3: Consolidate the Global Styles and Style Book JS shell

- **Objective**
  - Collapse near-identical review UI, stale UI, and request shell logic for the style surfaces while keeping target resolution separate.
- **File targets**
  - `src/global-styles/GlobalStylesRecommender.js`
  - `src/style-book/StyleBookRecommender.js`
  - new `src/style-surfaces/StyleSurfacePanel.js`
  - new `src/style-surfaces/useStyleSurfaceRuntime.js`
- **Abstractions to extract or boundaries to redraw**
  - Shared panel body
  - Shared review and advisory card rendering
  - Shared activity section and stale hero wiring
- **Behavior that must remain unchanged**
  - Global Styles portal fallback
  - Style Book target-block gating and sidebar activation behavior
  - Distinct copy for theme-wide vs target-block scope
- **Risks / regressions**
  - Sidebar mount timing regressions
  - Incorrect scope labels or wrong request payload fields
- **Tests / docs that must move with the change**
  - Style surface UI tests
  - E2E coverage for stale, review, apply, and undo
  - `docs/features/style-and-theme-intelligence.md`
- **Why this phase comes before the next one**
  - It removes the highest duplication left after the store runtime is shared.

### Phase 4: Consolidate template live-structure overlay primitives

- **Objective**
  - Make the template-family overlay model easier to reason about without merging the distinct operation vocabularies.
- **File targets**
  - `src/templates/template-recommender-helpers.js`
  - `src/template-parts/template-part-recommender-helpers.js`
  - `inc/Abilities/TemplateAbilities.php`
  - new `src/templates/live-structure-snapshots.js`
  - new `inc/Support/LiveStructureOverlay.php`
- **Abstractions to extract or boundaries to redraw**
  - Shared path normalization
  - Shared flat and tree lookup builders
  - Shared live-structure overlay primitives
- **Behavior that must remain unchanged**
  - Template slot semantics
  - Template-part operation targets and structural constraints
  - Existing response validators
- **Risks / regressions**
  - Path-lookup mismatches that would invalidate executable operations
- **Tests / docs that must move with the change**
  - Template and template-part helper tests
  - Operation validation tests
  - `docs/reference/template-operations.md`
- **Why this phase comes before the next one**
  - It stabilizes the largest remaining surface-specific overlay logic before controller cleanup.

### Phase 5: Formalize REST handler assembly and shared parse-envelope helpers

- **Objective**
  - Finish the cleanup with low-risk consolidation of route mechanics and prompt envelope decoding.
- **File targets**
  - `inc/REST/Agent_Controller.php`
  - `inc/LLM/Prompt.php`
  - `inc/LLM/TemplatePrompt.php`
  - `inc/LLM/TemplatePartPrompt.php`
  - `inc/LLM/StylePrompt.php`
  - new `inc/LLM/JsonResponseDecoder.php`
- **Abstractions to extract or boundaries to redraw**
  - Common response-envelope decode helper
  - Private route executor helper for ability calls, metadata decoration, optional response mapping, and optional activity persistence
- **Behavior that must remain unchanged**
  - Surface-specific validators
  - HTTP status behavior
  - The block route response envelope
  - Request meta schema
- **Risks / regressions**
  - Low; mostly maintenance-oriented
- **Tests / docs that must move with the change**
  - REST controller tests
  - Prompt parse error tests
  - `docs/reference/abilities-and-routes.md`
- **Why this phase comes last**
  - It is easier to do once the runtime, freshness, and UI boundaries have stopped moving.

## Validation Checklist

- Verify `block` still applies only safe local attribute changes, and advisory suggestions still refuse direct apply through `src/store/index.js::applySuggestion()`.
- Verify `navigation` remains advisory-only and never exposes review/apply controls.
- Verify `pattern` still honors `PatternIndex` warming and compatibility states in `inc/Abilities/PatternAbilities.php::recommend_patterns()` and still patches only the inserter UI.
- Verify `template` results go stale when template slots, top-level structure, prompt, visible pattern set, or server candidate inputs change.
- Verify `template-part` results go stale when live path targets, locks, content-only constraints, prompt, or server candidate inputs change.
- Verify `global-styles` results go stale when current config, merged config, active variations, prompt, client execution contract, or unresolved server token inputs change.
- Verify `style-book` results go stale when target block, current block styles, prompt, client execution contract, or unresolved server manifest inputs change.
- Verify stale/fresh UI and apply guards use the same comparison source on `template`, `template-part`, `global-styles`, and `style-book`.
- Verify activity entries and undo still preserve `requestMeta`, request prompt, and surface-scoped targeting for `template`, `template-part`, `global-styles`, and `style-book`.
- Verify docs grounding still comes from `inc/Support/CollectsDocsGuidance.php::collect()` and no surface-specific query builder is lost during refactors.
- Update `docs/reference/shared-internals.md`, `docs/reference/abilities-and-routes.md`, `docs/reference/recommendation-ui-consistency.md`, and `STATUS.md` alongside any refactor that changes ownership boundaries.

## Bottom Line

The repo should not be simplified into one generic recommendation engine. The right target is a clearer two-level architecture:

- one shared runtime layer for request state, stale guarding, review/apply lifecycle, request meta, and activity persistence
- a small number of intentionally separate surface families that keep their own context builders, validators, and execution contracts

That approach reduces maintenance cost without erasing the product decisions that already exist in the code.
