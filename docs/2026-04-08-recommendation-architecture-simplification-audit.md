# Recommendation Architecture Simplification Audit

Date: 2026-04-08

## Short Architecture Summary

Flavor Agent does not have one generic recommendation pipeline. The shipped code resolves into four structural recommendation families plus one adjacent content-writing scaffold. They share transport, request metadata, and activity plumbing in some places, but intentionally diverge in context shape, safety model, and execution model:

- `block` is a direct-apply surface for safe local attribute changes driven by `src/inspector/BlockRecommendationsPanel.js`, `src/store/index.js::fetchBlockRecommendations()`, `src/store/index.js::applySuggestion()`, `inc/REST/Agent_Controller.php::handle_recommend_block()`, and `inc/Abilities/BlockAbilities.php::recommend_block()`.
- `navigation` is advisory-only and stops at ranked guidance from `src/inspector/NavigationRecommendations.js`, `inc/REST/Agent_Controller.php::handle_recommend_navigation()`, and `inc/Abilities/NavigationAbilities.php::recommend_navigation()`.
- `pattern` is a retrieval and reranking pipeline that patches the inserter, not an apply or review surface, via `src/patterns/PatternRecommender.js` and `inc/Abilities/PatternAbilities.php::recommend_patterns()`.
- `template`, `template-part`, `global-styles`, and `style-book` are preview and review surfaces that validate executable operations before apply and persist undoable activity through `src/store/index.js`, `inc/REST/Agent_Controller.php`, `inc/Abilities/TemplateAbilities.php`, `inc/Abilities/StyleAbilities.php`, `inc/LLM/TemplatePrompt.php`, `inc/LLM/TemplatePartPrompt.php`, and `inc/LLM/StylePrompt.php`.
- `content` is a separate draft/edit/critique scaffold driven by `src/store/index.js::fetchContentRecommendations()`, `inc/REST/Agent_Controller.php::handle_recommend_content()`, `inc/Abilities/ContentAbilities.php::recommend_content()`, and `inc/LLM/WritingPrompt.php`. It shares request state, request meta, and scoped diagnostic activity plumbing, but not the structural recommendation freshness/apply runtime.

The shared backbone is real, but narrower than the noun "recommendation" suggests:

- JS surface state and interaction contracts live in `src/store/index.js::SURFACE_INTERACTION_CONTRACT`, `src/store/index.js::getNormalizedInteractionState()`, `src/store/index.js::guardSurfaceApplyFreshness()`, and `src/store/index.js::guardSurfaceApplyResolvedFreshness()`.
- Server-resolved freshness is already centralized through `inc/Support/RecommendationResolvedSignature.php::from_payload()` and the `resolveSignatureOnly` request mode handled in `inc/REST/Agent_Controller.php`, with `block` intentionally retaining its `{ payload, clientId }` response envelope even for signature-only requests.
- REST request metadata is centralized in `inc/REST/Agent_Controller.php::append_request_meta_for_route()` and `inc/REST/Agent_Controller.php::append_request_meta_to_error_for_route()`.
- Scoped request-diagnostic activity for advisory routes is already centralized in `inc/REST/Agent_Controller.php::persist_request_diagnostic_activity()` and `inc/REST/Agent_Controller.php::persist_request_diagnostic_failure_activity()`.
- WordPress docs grounding is already centralized in `inc/Support/CollectsDocsGuidance.php::collect()`.
- Activity and undo persistence are already shared through `src/store/index.js`, `src/store/activity-history.js`, and the surface-specific apply helpers.

The biggest simplification opportunity is not "merge all recommenders." It is to move shared lifecycle ownership into clearer modules, and to describe the shipped hybrid freshness contract accurately, while preserving the distinct surface contracts.

## Surface-By-Surface Comparison

| Surface | End-to-end path | Live client context | Server-derived context | Interaction model | Freshness model | Shared infrastructure |
| --- | --- | --- | --- | --- | --- | --- |
| `block` | `src/inspector/BlockRecommendationsPanel.js` -> `src/store/index.js::fetchBlockRecommendations()` / `applySuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_block()` -> `inc/Abilities/BlockAbilities.php::recommend_block()` -> `inc/LLM/Prompt.php` | `src/context/collector.js::collectBlockContext()` sends selected block attributes, structural identity, siblings, branch context, editing mode, and client-only constraints | `inc/Context/BlockContextCollector.php::for_block()` adds block registry introspection and `themeTokens`; `BlockAbilities::build_context_from_editor_context()` overlays client-only fields back on top of server context | Safe direct apply for local block attributes only; advisory ideas still allowed | Immediate stale UI from `src/utils/block-recommendation-context.js::buildBlockRecommendationContextSignature()` and `src/utils/recommendation-request-signature.js::buildBlockRecommendationRequestSignature()`, plus apply-time server revalidation from stored `resolvedContextSignature` via `resolveSignatureOnly` | Shared request state, stale/apply guards, resolved signature revalidation, request meta, activity logging |
| `navigation` | `src/inspector/NavigationRecommendations.js` -> `src/store/index.js::fetchNavigationRecommendations()` -> `inc/REST/Agent_Controller.php::handle_recommend_navigation()` -> `inc/Abilities/NavigationAbilities.php::recommend_navigation()` -> `inc/LLM/NavigationPrompt.php` | Serialized navigation markup, menu ref, structural identity, siblings, and branch metadata from `src/inspector/NavigationRecommendations.js::buildNavigationFetchInput()` | `inc/Context/ServerCollector.php::for_navigation()` resolves structure and overlay-specific context | Advisory-only; no apply or undo | Client-side stringified fetch input from `src/inspector/NavigationRecommendations.js::buildNavigationContextSignature()` | Shared request state, request meta, docs grounding |
| `pattern` | `src/patterns/PatternRecommender.js` -> `src/store/index.js::fetchPatternRecommendations()` -> `inc/REST/Agent_Controller.php::handle_recommend_patterns()` -> `inc/Abilities/PatternAbilities.php::recommend_patterns()` | Inserter root, ancestor stack, nearby siblings, template-part area and slug, container layout, visible pattern names | Pattern index runtime state, embeddings, Qdrant collection compatibility, docs guidance, semantic and structural reranking | Browse and rank only; no preview/apply/undo | No review/apply freshness contract; runtime staleness is owned by `PatternIndex` and backend compatibility checks in `PatternAbilities::recommend_patterns()` | Shared REST, request meta, and docs grounding |
| `template` | `src/templates/TemplateRecommender.js` -> `src/templates/template-recommender-helpers.js` -> `src/store/index.js::fetchTemplateRecommendations()` / `applyTemplateSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_template()` -> `inc/Abilities/TemplateAbilities.php::recommend_template()` -> `inc/LLM/TemplatePrompt.php` | Live slot snapshot, top-level structure snapshot, pattern override summary, viewport visibility summary, visible pattern names | `inc/Context/TemplateContextCollector.php::for_template()` resolves template entity, available parts, allowed and empty areas, candidate patterns, and theme tokens; `TemplateAbilities::apply_template_live_slot_context()` and `apply_template_live_structure_context()` overlay live editor state | Review-before-apply with validated `assign_template_part`, `replace_template_part`, and `insert_pattern` operations | Immediate client stale UI from `buildTemplateRecommendationContextSignature()` plus `buildTemplateRecommendationRequestSignature()`, and apply-time server revalidation from stored `resolvedContextSignature` via `resolveSignatureOnly` | Shared preview/apply store lifecycle, resolved signature revalidation, activity logging, request meta |
| `template-part` | `src/template-parts/TemplatePartRecommender.js` -> `src/template-parts/template-part-recommender-helpers.js` -> `src/store/index.js::fetchTemplatePartRecommendations()` / `applyTemplatePartSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_template_part()` -> `inc/Abilities/TemplateAbilities.php::recommend_template_part()` -> `inc/LLM/TemplatePartPrompt.php` | Live block tree, flat path lookup, operation targets, insertion anchors, structural constraints, visible pattern names | `inc/Context/TemplatePartContextCollector.php::for_template_part()` resolves entity, pattern candidates, theme tokens, and server structure defaults; `TemplateAbilities::apply_template_part_live_structure_context()` overlays live structure | Review-before-apply with validated `insert_pattern`, `replace_block_with_pattern`, and `remove_block` operations | Immediate client stale UI from `buildTemplatePartRecommendationContextSignature()` plus `buildTemplatePartRecommendationRequestSignature()`, and apply-time server revalidation from stored `resolvedContextSignature` via `resolveSignatureOnly` | Shared preview/apply store lifecycle, resolved signature revalidation, activity logging, request meta |
| `global-styles` | `src/global-styles/GlobalStylesRecommender.js` -> `src/store/index.js::fetchGlobalStylesRecommendations()` / `applyGlobalStylesSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_style()` -> `inc/Abilities/StyleAbilities.php::recommend_style()` -> `inc/LLM/StylePrompt.php` -> `src/utils/style-operations.js::applyGlobalStyleSuggestionOperations()` | Live user config, merged config, style variations, template structure, viewport visibility, design semantics, theme token diagnostics, execution contract | `inc/Abilities/StyleAbilities.php::build_global_styles_context()` and `build_shared_style_context()` add `themeTokens`, supported style paths, active variation resolution, and docs guidance | Review-before-apply with validated `set_theme_variation` and `set_styles` operations | Immediate client stale UI from `src/utils/style-operations.js::buildGlobalStylesRecommendationContextSignature()` plus `buildGlobalStylesRecommendationRequestSignature()`, and apply-time server revalidation from stored `resolvedContextSignature` via `resolveSignatureOnly` | Shared preview/apply store lifecycle, resolved signature revalidation, request meta, activity/undo persistence |
| `style-book` | `src/style-book/StyleBookRecommender.js` -> `src/store/index.js::fetchStyleBookRecommendations()` / `applyStyleBookSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_style()` -> `inc/Abilities/StyleAbilities.php::recommend_style()` -> `inc/LLM/StylePrompt.php` -> `src/utils/style-operations.js::applyGlobalStyleSuggestionOperations()` | Live global styles config plus target block branch, template structure, viewport visibility, design semantics, theme token diagnostics, client execution contract | `StyleAbilities::build_style_book_context()` and `build_shared_style_context()` add server `themeTokens`, supported block style paths, and `blockManifest` from `inc/Context/ServerCollector.php::introspect_block_type()` | Review-before-apply with validated `set_block_styles` operations | Immediate client stale UI from `buildGlobalStylesRecommendationContextSignature()` plus `buildStyleBookRecommendationRequestSignature()`, and apply-time server revalidation from stored `resolvedContextSignature` via `resolveSignatureOnly` | Same shared preview/apply runtime and resolved-signature model as `global-styles` |
| `content` | `src/store/index.js::fetchContentRecommendations()` -> `inc/REST/Agent_Controller.php::handle_recommend_content()` -> `inc/Abilities/ContentAbilities.php::recommend_content()` -> `inc/LLM/WritingPrompt.php` | Prompt, mode, voice profile, post context | No shared block/template/style collector stage; no docs grounding collector | Advisory draft/edit/critique scaffold only | Request token only; no review/apply freshness contract | Shares request state and request meta plumbing, but not the shipped structural recommendation runtime |

## Findings Ordered By Severity / Opportunity

### 1. High: The Short Architecture Summary does not describe the shipped freshness contract, and Finding 1 of this document is self-contradictory

The **Short Architecture Summary** (lines 5–24) and the **Freshness model** column of the **Surface-By-Surface Comparison** table (lines 28–37) are in tension. The Summary describes the five surface families without mentioning the hybrid freshness model at all, while the table correctly records it for every executable surface. Finding 1 of this document says "the current audit overstates a freshness gap that the repo has already closed" — but the audit *is* this document, and the table was always accurate. The real problem is that the Summary omits the freshness contract entirely, and the narrative framing in this Finding implies a code gap when only a documentation gap exists.

The shipped code already implements a two-step freshness contract:

- `inc/Support/RecommendationResolvedSignature.php::from_payload()` is the shared server-side signature helper.
- `inc/Abilities/BlockAbilities.php::recommend_block()`, `inc/Abilities/TemplateAbilities.php::recommend_template()`, `TemplateAbilities::recommend_template_part()`, and `inc/Abilities/StyleAbilities.php::recommend_style()` all compute a `resolvedContextSignature` before docs guidance and model execution, and return it in the full payload.
- `resolveSignatureOnly` is a registered REST argument on the executable routes, and `inc/REST/Agent_Controller.php` returns signature-only freshness payloads without rerunning model execution. `block` keeps its existing `{ payload, clientId }` envelope; the other executable routes return a top-level `resolvedContextSignature`.
- `src/store/index.js::guardSurfaceApplyResolvedFreshness()` strips client-only `contextSignature`, calls the route again with `resolveSignatureOnly: true`, and blocks apply when the stored and current server-resolved signatures drift.
- `src/store/index.js::applySuggestion()`, `applyTemplateSuggestion()`, `applyTemplatePartSuggestion()`, `applyGlobalStylesSuggestion()`, and `applyStyleBookSuggestion()` all run both checks: client request signature first, then server-resolved signature revalidation.
- The surface panels merge client request-signature drift with stored server stale reasons in `src/inspector/BlockRecommendationsPanel.js`, `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `src/global-styles/GlobalStylesRecommender.js`, and `src/style-book/StyleBookRecommender.js`.
- Tests cover this contract, including signature-only ability/controller responses and server-stale apply blocking in `tests/phpunit/BlockAbilitiesTest.php`, `tests/phpunit/StyleAbilitiesTest.php`, `tests/phpunit/AgentControllerTest.php`, and `src/store/__tests__/store-actions.test.js`.

Impact:

- The Short Architecture Summary omits the freshness contract entirely, making it appear more complex than the table shows.
- Finding 1's framing ("overstates a freshness gap") implies a missing code feature; the code is present, the description is absent.
- The narrative risks sending future work toward rebuilding the `resolvedContextSignature` / `resolveSignatureOnly` system under a second fingerprint abstraction.

Recommendation (documentation fix only — no code changes needed):

- Add the hybrid freshness contract to the Short Architecture Summary: client request signatures drive immediate stale UI; `resolvedContextSignature` plus `resolveSignatureOnly` own apply-time server revalidation and server-stale error state.
- Rewrite Finding 1 to accurately describe it as a documentation gap, not a code gap.
- Treat `inc/Support/RecommendationResolvedSignature.php`, the executable abilities, `inc/REST/Agent_Controller.php`, and `src/store/index.js::guardSurfaceApplyResolvedFreshness()` as the documented freshness spine.
- Document the `block` route exception explicitly: signature-only freshness requests still return `{ payload: { resolvedContextSignature }, clientId }`.
- If future work is needed, focus on reducing duplicated resolved-signature plumbing or deciding whether proactive server preflight is worth the latency. Do not introduce a second fingerprint helper unless the current contract proves insufficient.

### 2. Medium-High: The store already has a shared executable-surface contract, but the fetch/apply runtime is still duplicated

`src/store/index.js` already knows that `template`, `template-part`, `global-styles`, and `style-book` are the same kind of interaction surface, but the action implementations still repeat nearly the same lifecycle code.

Evidence:

- Shared contract and state normalizers already exist in `src/store/index.js::SURFACE_INTERACTION_CONTRACT`, `getNormalizedInteractionState()`, `isSurfaceApplyAllowedForState()`, `guardSurfaceApplyFreshness()`, and `guardSurfaceApplyResolvedFreshness()`.
- The fetch lifecycles for `fetchTemplateRecommendations()`, `fetchTemplatePartRecommendations()`, `fetchGlobalStylesRecommendations()`, and `fetchStyleBookRecommendations()` repeat the same `runAbortableRecommendationRequest()` shape with surface-local request builders, loading handlers, success handlers, and signature plumbing.
- The apply lifecycles for `applyTemplateSuggestion()`, `applyTemplatePartSuggestion()`, `applyGlobalStylesSuggestion()`, and `applyStyleBookSuggestion()` repeat the same stale guard, apply-state transition, activity recording, and success state updates.

Impact:

- The file stays hard to reason about because lifecycle behavior is shared conceptually but not packaged as a reusable module.
- Any future change to stale handling, request meta propagation, review selection, or activity persistence needs to be patched in four places.

Recommendation:

- Extract a shared executable surface runtime module, for example `src/store/executable-surface-runtime.js`.
- Exact owner responsibilities:
  - shared request builder wrapper around `runAbortableRecommendationRequest()`
  - shared stale-guarded apply wrapper for both client request signatures and server-resolved revalidation
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

### 4. Medium: Global Styles and Style Book should share a stronger JS shell — the lowest-risk extraction candidate

These two surfaces are the cleanest and lowest-risk candidate for UI consolidation. Unlike the template live-structure overlay (Finding 3), this extraction touches only JS UI files and does not require changes to PHP overlay logic or prompt validators.

Evidence:

- Both components build nearly the same request lifecycle and stale UI in `src/global-styles/GlobalStylesRecommender.js` and `src/style-book/StyleBookRecommender.js`.
- Both use the same context signature builder in `src/utils/style-operations.js::buildGlobalStylesRecommendationContextSignature()`.
- Both apply through `src/utils/style-operations.js::applyGlobalStyleSuggestionOperations()` and undo through the same activity state helpers.
- Both route through the same REST endpoint `inc/REST/Agent_Controller.php::handle_recommend_style()` and the same server surface split in `inc/Abilities/StyleAbilities.php::build_context_for_surface()`.

Impact:

- UI maintenance cost is higher than necessary.
- Most changes to review UX, stale UX, activity UX, and prompt-shell copy require parallel edits.

Recommendation:

- Extract a shared style surface shell such as `src/style-surfaces/StyleSurfacePanel.js`.
- If the adapters still feel noisy after Phase 2, add only a tiny presentation helper under `src/style-surfaces/` that maps shared panel props without owning request/apply state.
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

- **Resolved-signature freshness contract cleanup**
  - Current owners: `inc/Support/RecommendationResolvedSignature.php`, `inc/Abilities/BlockAbilities.php`, `inc/Abilities/TemplateAbilities.php`, `inc/Abilities/StyleAbilities.php`, `inc/REST/Agent_Controller.php`, `src/store/index.js`
  - Thin adapters: `src/inspector/BlockRecommendationsPanel.js`, `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `src/global-styles/GlobalStylesRecommender.js`, `src/style-book/StyleBookRecommender.js`

- **Executable surface store runtime**
  - New owner: `src/store/executable-surface-runtime.js`
  - Thin adapters: `src/store/index.js::fetchTemplateRecommendations()`, `fetchTemplatePartRecommendations()`, `fetchGlobalStylesRecommendations()`, `fetchStyleBookRecommendations()`, `applyTemplateSuggestion()`, `applyTemplatePartSuggestion()`, `applyGlobalStylesSuggestion()`, `applyStyleBookSuggestion()`

- **Shared style review shell**
  - New owner: `src/style-surfaces/StyleSurfacePanel.js`
  - Optional helper: a presentation-only prop-mapping helper under `src/style-surfaces/` if the adapters remain noisy after Phase 2
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

### Phase 1: Normalize and document the existing resolved-signature freshness contract

- **Objective**
  - Make the shipped client-plus-server freshness model explicit and easier to evolve before moving runtime code around it.
- **File targets**
  - `inc/Support/RecommendationResolvedSignature.php`
  - `inc/Abilities/BlockAbilities.php`
  - `inc/Abilities/TemplateAbilities.php`
  - `inc/Abilities/StyleAbilities.php`
  - `inc/REST/Agent_Controller.php`
  - `src/store/index.js`
  - `src/inspector/BlockRecommendationsPanel.js`
  - `src/templates/TemplateRecommender.js`
  - `src/template-parts/TemplatePartRecommender.js`
  - `src/global-styles/GlobalStylesRecommender.js`
  - `src/style-book/StyleBookRecommender.js`
- **Abstractions to extract or boundaries to redraw**
  - Keep `RecommendationResolvedSignature::from_payload()` as the only server signature helper unless product requirements force a narrower contract.
  - Keep `resolveSignatureOnly` as the server revalidation path.
  - Make the two-step freshness contract explicit in naming and docs:
    - `src/store/index.js::guardSurfaceApplyFreshness()` for immediate client-side drift
    - `src/store/index.js::guardSurfaceApplyResolvedFreshness()` for server-resolved revalidation
  - If duplication still feels too high, extract only thin helpers around resolved-signature request/response plumbing instead of inventing a second fingerprint abstraction.
- **Behavior that must remain unchanged**
  - Existing permissions, suggestion semantics, and local apply executors.
  - Client-side immediate stale UI when prompt or local editor state changes.
  - Docs guidance cache changes do not alter `resolvedContextSignature` unless the contract is intentionally changed.
- **Risks / regressions**
  - Accidental signature-contract changes would invalidate existing stored results and tests.
  - Over-eager server preflight could add latency if it starts running before apply.
  - Renaming helpers without preserving state wiring could drop `server` stale reasons from panel UI.
- **Tests / docs that must move with the change**
  - Store stale-guard tests for `block`, `template`, `template-part`, `global-styles`, and `style-book`
  - Panel/component tests for block, template, template-part, global-styles, and style-book stale UI
  - Ability and controller tests for signature-only payloads and server-stale revalidation
  - `docs/reference/shared-internals.md`
  - `docs/reference/recommendation-ui-consistency.md`
- **Why this phase comes first**
  - It aligns the architecture write-up with the shipped source of truth before the surrounding runtime is reorganized.

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
  - This module should remain the only new request/apply runtime owner; later UI consolidations should consume it instead of introducing a second hook-level runtime.
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
  - optional tiny prop-mapping helper under `src/style-surfaces/` only if the panel adapters stay noisy after Phase 2
- **Abstractions to extract or boundaries to redraw**
  - Shared panel body
  - Shared review and advisory card rendering
  - Shared activity section and stale hero wiring
  - If a helper hook is introduced here, keep it presentation-only and have it consume Phase 2's store runtime rather than own request/apply state
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
  - Finish the cleanup with low-risk consolidation of route mechanics and prompt envelope decoding while preserving route-specific request-diagnostic side effects.
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
  - Scoped request-diagnostic success/failure activity persistence for `content`, `navigation`, and `pattern`
- **Risks / regressions**
  - Low, but route-helper cleanup can accidentally drop scoped diagnostic activity persistence or flatten the block route envelope
- **Tests / docs that must move with the change**
  - REST controller tests, including scoped diagnostic activity coverage for `content`, `navigation`, and `pattern`
  - Prompt parse error tests
  - `docs/reference/abilities-and-routes.md`
- **Why this phase comes last**
  - It is easier to do once the runtime, freshness, and UI boundaries have stopped moving.

## Validation Checklist

- Verify `block` still applies only safe local attribute changes, and advisory suggestions still refuse direct apply through `src/store/index.js::applySuggestion()`.
- Verify `navigation` remains advisory-only and never exposes review/apply controls.
- Verify `pattern` still honors `PatternIndex` warming and compatibility states in `inc/Abilities/PatternAbilities.php::recommend_patterns()` and still patches only the inserter UI.
- Verify `block`, `template`, `template-part`, `global-styles`, and `style-book` all persist `resolvedContextSignature` from recommendation responses and can return signature-only responses through `resolveSignatureOnly`.
- Verify `block` keeps its existing `{ payload, clientId }` response envelope for both full recommendation responses and `resolveSignatureOnly` freshness responses.
- Verify client-side request signature drift marks results stale immediately on `block`, `template`, `template-part`, `global-styles`, and `style-book`.
- Verify server-resolved drift is rejected at apply time for `block`, `template`, `template-part`, `global-styles`, and `style-book`, and that a failed revalidation persists a `server` stale reason back into panel state.
- Verify stale/fresh UI uses request-signature mismatch plus stored server stale reason on `block`, `template`, `template-part`, `global-styles`, and `style-book`.
- Verify block apply activity entries and undo still preserve `requestMeta`, request prompt, and block-scoped targeting.
- Verify activity entries and undo still preserve `requestMeta`, request prompt, and surface-scoped targeting for `template`, `template-part`, `global-styles`, and `style-book`.
- Verify `content`, `navigation`, and `pattern` still append request meta and persist scoped success/failure diagnostic activity entries through their route handlers.
- Verify docs grounding still comes from `inc/Support/CollectsDocsGuidance.php::collect()`, no surface-specific query builder is lost during refactors, and docs-guidance cache changes do not silently change the resolved-signature contract.
- Update `docs/reference/shared-internals.md`, `docs/reference/abilities-and-routes.md`, `docs/reference/recommendation-ui-consistency.md`, and `STATUS.md` alongside any refactor that changes ownership boundaries.

## Bottom Line

The repo should not be simplified into one generic recommendation engine. The right target is a clearer two-level architecture:

- one shared runtime layer for request state, hybrid freshness (client request signatures plus server-resolved revalidation), review/apply lifecycle, request meta, and activity persistence
- a small number of intentionally separate surface families that keep their own context builders, validators, and execution contracts

That approach reduces maintenance cost without erasing the product decisions that already exist in the code.
