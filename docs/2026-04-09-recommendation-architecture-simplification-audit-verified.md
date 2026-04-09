# Recommendation Architecture Simplification Audit (Verified)

Date: 2026-04-09

## Short Architecture Summary

Flavor Agent does not ship one generic recommendation pipeline. The first-party runtime is a WordPress-native split between:

- JS surface owners and request/apply state in `src/store/index.js`
- thin REST adapters in `inc/REST/Agent_Controller.php`
- surface-specific abilities, collectors, prompt builders, and validators in `inc/Abilities/*`, `inc/Context/*`, and `inc/LLM/*`

The shipped recommendation families are:

- `block`: safe direct apply through `src/inspector/BlockRecommendationsPanel.js`, `src/store/index.js::fetchBlockRecommendations()`, `src/store/index.js::applySuggestion()`, `inc/REST/Agent_Controller.php::handle_recommend_block()`, and `inc/Abilities/BlockAbilities.php::recommend_block()`
- `navigation`: advisory-only guidance through `src/inspector/NavigationRecommendations.js`, `src/store/index.js::fetchNavigationRecommendations()`, `inc/REST/Agent_Controller.php::handle_recommend_navigation()`, and `inc/Abilities/NavigationAbilities.php::recommend_navigation()`
- `pattern`: retrieval/rerank plus inserter patching through `src/patterns/PatternRecommender.js`, `src/store/index.js::fetchPatternRecommendations()`, and `inc/Abilities/PatternAbilities.php::recommend_patterns()`
- `template`, `template-part`, `global-styles`, `style-book`: review-before-apply surfaces that validate deterministic operations before mutate/undo through `src/store/index.js`, `inc/REST/Agent_Controller.php`, `inc/Abilities/TemplateAbilities.php`, `inc/Abilities/StyleAbilities.php`, `inc/LLM/TemplatePrompt.php`, `inc/LLM/TemplatePartPrompt.php`, and `inc/LLM/StylePrompt.php`
- `content`: a scaffolded programmatic lane through `src/store/index.js::fetchContentRecommendations()`, `inc/REST/Agent_Controller.php::handle_recommend_content()`, `inc/Abilities/ContentAbilities.php::recommend_content()`, and `inc/LLM/WritingPrompt.php`; it shares request plumbing but not the executable recommendation runtime

The repo already has a real shared backbone:

- request and apply contracts in `src/store/index.js::SURFACE_INTERACTION_CONTRACT()`, `getNormalizedInteractionState()`, `guardSurfaceApplyFreshness()`, and `guardSurfaceApplyResolvedFreshness()`
- shared request transport in `src/store/index.js::runAbortableRecommendationRequest()`
- shared server freshness hashing in `inc/Support/RecommendationResolvedSignature.php::from_payload()`
- shared request metadata decoration in `inc/REST/Agent_Controller.php::append_request_meta_for_route()` and `append_request_meta_to_error_for_route()`
- shared docs grounding entry point in `inc/Support/CollectsDocsGuidance.php::collect()`
- shared activity persistence and ordered undo in `src/store/activity-history.js`, `inc/Activity/Repository.php`, and the surface-specific activity builders in `src/store/index.js`

The shipped freshness contract is hybrid, not missing:

- immediate stale UI comes from client-owned request/context signatures such as `buildBlockRecommendationRequestSignature()`, `buildTemplateRecommendationRequestSignature()`, `buildTemplatePartRecommendationRequestSignature()`, `buildGlobalStylesRecommendationRequestSignature()`, and `buildStyleBookRecommendationRequestSignature()`
- apply-time server revalidation comes from `resolvedContextSignature` returned by `BlockAbilities::recommend_block()`, `TemplateAbilities::recommend_template()`, `TemplateAbilities::recommend_template_part()`, and `StyleAbilities::recommend_style()`
- apply-time checks re-post the live request with `resolveSignatureOnly: true` through `src/store/index.js::guardSurfaceApplyResolvedFreshness()`
- `block` participates in that same server revalidation even though `handle_recommend_block()` keeps its distinct `{ payload, clientId }` REST envelope
- docs guidance is intentionally not part of `RecommendationResolvedSignature::from_payload()`; tests in `tests/phpunit/BlockAbilitiesTest.php` and `tests/phpunit/StyleAbilitiesTest.php` verify that docs cache churn does not invalidate otherwise identical apply contexts

## Surface-By-Surface Comparison

| Surface | UI/runtime path | Live client context | Server-derived context | Interaction and safety model | Freshness model | Shared infrastructure used |
| --- | --- | --- | --- | --- | --- | --- |
| `block` | `src/inspector/BlockRecommendationsPanel.js` -> `src/store/index.js::fetchBlockRecommendations()` / `applySuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_block()` -> `inc/Abilities/BlockAbilities.php::recommend_block()` | `src/context/collector.js::collectBlockContext()` sends current attributes, inspector panels, bindable attributes, styles, variations, structural identity, siblings, branch summary, editing mode, and token summary | `inc/Context/BlockContextCollector.php::for_block()` adds server block introspection and theme tokens; `BlockAbilities::build_context_from_editor_context()` overlays client-only editor data back onto that server context | direct apply only for safe local attribute updates; advisory items remain manual via `Prompt::enforce_block_context_rules()` and `src/store/update-helpers.js::getBlockSuggestionExecutionInfo()` | client request signature plus apply-time `resolvedContextSignature` revalidation via `resolveSignatureOnly` | request transport, request meta, docs grounding, activity history, ordered undo |
| `navigation` | `src/inspector/NavigationRecommendations.js` -> `src/store/index.js::fetchNavigationRecommendations()` -> `inc/REST/Agent_Controller.php::handle_recommend_navigation()` -> `inc/Abilities/NavigationAbilities.php::recommend_navigation()` | serialized current navigation markup plus slimmed block structural context from `buildNavigationFetchInput()` | `inc/Context/NavigationContextCollector.php::for_navigation()` resolves menu items, target inventory, location details, overlay template parts, structure summary, theme tokens, and editor context | advisory-only; no mutate or undo path; parser validation stays in `inc/LLM/NavigationPrompt.php::validate_suggestions()` | client-only context signature from `buildNavigationContextSignature()`; stale results stay visible but there is no server apply preflight | request transport, request meta, docs grounding, server-backed request-diagnostic activity |
| `pattern` | `src/patterns/PatternRecommender.js` -> `src/store/index.js::fetchPatternRecommendations()` -> `inc/REST/Agent_Controller.php::handle_recommend_patterns()` -> `inc/Abilities/PatternAbilities.php::recommend_patterns()` | inserter root, ancestor stack, nearby siblings, template-part area and slug, container layout, visible pattern names | `PatternAbilities::recommend_patterns()` owns runtime state checks, embedding query build, Qdrant compatibility, two-pass retrieval, ranking hints, LLM rerank, and docs grounding | ranking/browse only; core inserter still owns insertion | no executable freshness contract; runtime staleness belongs to `PatternIndex` and vector backend compatibility | request transport, request meta, docs grounding, server-backed request-diagnostic activity |
| `template` | `src/templates/TemplateRecommender.js` -> `src/store/index.js::fetchTemplateRecommendations()` / `applyTemplateSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_template()` -> `inc/Abilities/TemplateAbilities.php::recommend_template()` | `buildEditorTemplateSlotSnapshot()`, `buildEditorTemplateTopLevelStructureSnapshot()`, visible pattern names, and prompt | `inc/Context/TemplateContextCollector.php::for_template()` resolves assigned parts, empty/allowed areas, top-level structure, insertion anchors, pattern overrides, viewport visibility, structure stats, candidate patterns, and theme tokens; `TemplateAbilities::apply_template_live_slot_context()` and `apply_template_live_structure_context()` overlay live editor state | review-before-apply with validated `assign_template_part`, `replace_template_part`, and `insert_pattern` operations from `TemplatePrompt::validate_template_operations()` | client request signature plus apply-time `resolvedContextSignature` revalidation via `resolveSignatureOnly` | shared request/apply transport, request meta, docs grounding, activity history, ordered undo |
| `template-part` | `src/template-parts/TemplatePartRecommender.js` -> `src/store/index.js::fetchTemplatePartRecommendations()` / `applyTemplatePartSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_template_part()` -> `inc/Abilities/TemplateAbilities.php::recommend_template_part()` | `buildEditorTemplatePartStructureSnapshot()`, visible pattern names, and prompt | `inc/Context/TemplatePartContextCollector.php::for_template_part()` resolves block tree, block counts, structure stats, pattern overrides, operation targets, insertion anchors, structural constraints, candidate patterns, and theme tokens; `TemplateAbilities::apply_template_part_live_structure_context()` overlays live editor state | review-before-apply with validated `insert_pattern`, `replace_block_with_pattern`, and `remove_block` operations from `TemplatePartPrompt::validate_operations()` | client request signature plus apply-time `resolvedContextSignature` revalidation via `resolveSignatureOnly` | shared request/apply transport, request meta, docs grounding, activity history, ordered undo |
| `global-styles` | `src/global-styles/GlobalStylesRecommender.js` -> `src/store/index.js::fetchGlobalStylesRecommendations()` / `applyGlobalStylesSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_style()` -> `inc/Abilities/StyleAbilities.php::recommend_style()` | scope, current and merged config, available variations, template structure, viewport visibility, design semantics, theme token diagnostics, execution contract | `StyleAbilities::build_global_styles_context()` and `build_shared_style_context()` add `themeTokens`, supported theme-backed style paths, and active variation resolution | review-before-apply with validated `set_theme_variation` and `set_styles` operations from `StylePrompt::validate_operations()` | client request signature from `src/utils/style-operations.js::buildGlobalStylesRecommendationContextSignature()` plus apply-time `resolvedContextSignature` revalidation via `resolveSignatureOnly` | shared request/apply transport, request meta, docs grounding, activity history, ordered undo |
| `style-book` | `src/style-book/StyleBookRecommender.js` -> `src/store/index.js::fetchStyleBookRecommendations()` / `applyStyleBookSuggestion()` -> `inc/REST/Agent_Controller.php::handle_recommend_style()` -> `inc/Abilities/StyleAbilities.php::recommend_style()` | scope plus target block identity, current and merged block styles, template structure, viewport visibility, design semantics, theme token diagnostics, execution contract | `StyleAbilities::build_style_book_context()` and `build_shared_style_context()` add block manifest data, supported block-scoped style paths, and theme tokens | review-before-apply with validated `set_block_styles` operations from `StylePrompt::validate_operations()` | same client request signature model as Global Styles, but `buildGlobalStylesRecommendationContextSignature()` intentionally omits variation state when `scope.surface === 'style-book'`; apply still uses `resolvedContextSignature` revalidation | shared request/apply transport, request meta, docs grounding, activity history, ordered undo |
| `content` | `src/store/index.js::fetchContentRecommendations()` -> `inc/REST/Agent_Controller.php::handle_recommend_content()` -> `inc/Abilities/ContentAbilities.php::recommend_content()` | prompt, mode, voice profile, and post context | no shared server collector stage; ability builds its own compact editorial context | advisory draft/edit/critique only | request token only; no executable freshness contract | request transport, request meta, server-backed request-diagnostic activity |

## Findings Ordered By Severity / Opportunity

### 1. Medium-High opportunity: the four review-before-apply surfaces already share the same store contract, but their thunk bodies are still duplicated

Evidence:

- `src/store/index.js::runAbortableRecommendationRequest()` already centralizes abort/retry/request transport
- `src/store/index.js::SURFACE_INTERACTION_CONTRACT()`, `getNormalizedInteractionState()`, `guardSurfaceApplyFreshness()`, and `guardSurfaceApplyResolvedFreshness()` already centralize state and freshness semantics
- `fetchTemplateRecommendations()`, `fetchTemplatePartRecommendations()`, `fetchGlobalStylesRecommendations()`, and `fetchStyleBookRecommendations()` repeat the same request-token, `contextSignature`, `resolvedContextSignature`, and `attachRequestMetaToRecommendationPayload()` pattern
- `applyTemplateSuggestion()`, `applyTemplatePartSuggestion()`, `applyGlobalStylesSuggestion()`, and `applyStyleBookSuggestion()` repeat the same client stale check, server revalidation, apply-state transitions, activity persistence, and success-state transitions

Assessment:

- Classification: targeted extraction
- Expected benefit: high
- Implementation cost: medium
- Confidence: high
- Main regression risk: over-generalizing per-surface selectors and action creators, especially around stored result refs, request-prompt selectors, and activity entry builders

Why this is the best extraction seam:

- it is real duplication in current code, not just conceptual similarity
- it stays entirely in JS
- it preserves current PHP collectors, validators, and surface-owned UI semantics

### 2. Medium opportunity: Global Styles and Style Book still duplicate presentational helpers after the shared component pass

Evidence:

- `src/global-styles/GlobalStylesRecommender.js` and `src/style-book/StyleBookRecommender.js` both define near-identical `getSuggestionKey()`, `formatPath()`, `getCanonicalPresetSlug()`, `isInlineStyleNotice()`, `formatBadgeLabel()`, `getToneLabel()`, `OperationList()`, and large panel render trees
- both surfaces already share `src/utils/style-operations.js::buildGlobalStylesRecommendationContextSignature()`
- both apply through `src/utils/style-operations.js::applyGlobalStyleSuggestionOperations()`
- both use `inc/REST/Agent_Controller.php::handle_recommend_style()` and `inc/Abilities/StyleAbilities.php::recommend_style()`

Assessment:

- Classification: small cleanup
- Expected benefit: medium
- Implementation cost: low to medium
- Confidence: high
- Main regression risk: hiding the few real copy and scope differences behind an over-abstracted panel component

What this should and should not do:

- do extract shared style-card, operation-list, and maybe a style-surface presentation helper
- do not merge `StyleAbilities::build_global_styles_context()` and `StyleAbilities::build_style_book_context()`
- do not remove the portal/fallback mount differences or block-target-specific copy

### 3. Medium-low opportunity: template and template-part snapshot helpers repeat low-level tree and visibility plumbing, but their overlay semantics should remain separate

Evidence:

- `src/templates/template-recommender-helpers.js` and `src/template-parts/template-part-recommender-helpers.js` both define block-tree summarization, stats collection, visible-pattern normalization, path formatting, and request-input assembly
- `inc/Abilities/TemplateAbilities.php::apply_template_live_slot_context()`, `apply_template_live_structure_context()`, and `apply_template_part_live_structure_context()` are intentionally separate because slot semantics and path-target semantics differ
- `inc/Context/TemplateContextCollector.php::for_template()` and `inc/Context/TemplatePartContextCollector.php::for_template_part()` already return different structural contracts

Assessment:

- Classification: small cleanup or narrow targeted extraction
- Expected benefit: medium-low
- Implementation cost: medium
- Confidence: medium
- Main regression risk: flattening template slots/areas and template-part path targets into a fake common structure

Practical conclusion:

- extract only low-level walkers and normalizers if the duplication keeps growing
- keep request builders, operation vocabulary, and overlay logic surface-owned

### 4. Low opportunity: the REST controller is already a thin adapter; a generic route framework is not justified now

Evidence:

- `inc/REST/Agent_Controller.php::handle_recommend_block()`, `handle_recommend_template()`, `handle_recommend_template_part()`, and `handle_recommend_style()` are short sanitize/delegate/decorate handlers
- request metadata is already centralized in `append_request_meta_for_route()` and `append_request_meta_to_error_for_route()`
- request-diagnostic activity for advisory routes is already centralized in `persist_request_diagnostic_activity()` and `persist_request_diagnostic_failure_activity()`

Assessment:

- Classification: not justified now
- Expected benefit: low
- Implementation cost: medium
- Confidence: high
- Main regression risk: moving route-specific input assembly into a config table that hides the `block` envelope exception and advisory-route activity hooks

### 5. Low opportunity: prompt parsing has some common envelope work, but the validators are the real complexity and should stay separate

Evidence:

- `inc/LLM/Prompt.php::parse_response()`, `inc/LLM/TemplatePrompt.php::parse_response()`, `inc/LLM/TemplatePartPrompt.php::parse_response()`, `inc/LLM/StylePrompt.php::parse_response()`, and `inc/LLM/NavigationPrompt.php::parse_response()` all do some JSON/envelope cleanup
- the important behavior lives in surface-specific validators:
  - `Prompt::enforce_block_context_rules()`
  - `TemplatePrompt::validate_template_operations()`
  - `TemplatePartPrompt::validate_operations()`
  - `StylePrompt::validate_operations()`
  - `NavigationPrompt::validate_suggestions()`

Assessment:

- Classification: small cleanup at most
- Expected benefit: low
- Implementation cost: low
- Confidence: medium
- Main regression risk: spending time extracting shared decoding while leaving the real surface-specific complexity untouched

Best reading:

- a tiny JSON decode helper is fine if those files are already being edited
- a broader prompt-base-class refactor is weakly supported by current maintenance evidence

## Docs-Only Or Audit-Accuracy Corrections

- `docs/reference/shared-internals.md` is already accurate about the hybrid freshness contract: local request signatures drive immediate stale UI, and `resolvedContextSignature` plus `resolveSignatureOnly` protect apply-time mutation.
- `docs/reference/recommendation-ui-consistency.md` is already accurate about stale-result preservation and about which surfaces are one-click apply, review-before-apply, or browse-only.
- `docs/FEATURE_SURFACE_MATRIX.md` is already aligned with the shipped interaction models for block, navigation, pattern, template, template-part, and style surfaces.
- The correction needed is to avoid describing the repo as if it still lacks a server freshness spine or as if it should converge toward one generic recommendation pipeline. The current architecture already has the correct high-level boundaries.
- Any architecture note or future audit should explicitly record two details that are easy to miss:
  - docs guidance is intentionally excluded from `RecommendationResolvedSignature::from_payload()`
  - `handle_recommend_block()` keeps the distinct `{ payload, clientId }` REST response shape even for `resolveSignatureOnly` requests

## Candidate Improvements Ranked By Benefit / Cost / Confidence

1. Targeted extraction: move the four review-surface store lifecycles into a helper such as `src/store/executable-surface-runtime.js`
   - Exact owner: new JS store helper
   - Thin adapters that should remain: `fetchTemplateRecommendations()`, `fetchTemplatePartRecommendations()`, `fetchGlobalStylesRecommendations()`, `fetchStyleBookRecommendations()`, `applyTemplateSuggestion()`, `applyTemplatePartSuggestion()`, `applyGlobalStylesSuggestion()`, `applyStyleBookSuggestion()` in `src/store/index.js`
   - Benefit: high
   - Cost: medium
   - Confidence: high
   - Regression risk: moderate if selector/action wiring is over-generalized

2. Small cleanup: extract shared style-surface presentational helpers
   - Exact owner: `src/style-surfaces/` or a small helper colocated with the existing style recommenders
   - Thin adapters that should remain: `src/global-styles/GlobalStylesRecommender.js` and `src/style-book/StyleBookRecommender.js`
   - Benefit: medium
   - Cost: low to medium
   - Confidence: high
   - Regression risk: low

3. Small cleanup: extract low-level template/tree helper primitives only
   - Exact owner: a small shared helper under `src/templates/` or `src/utils/`
   - Thin adapters that should remain: `src/templates/template-recommender-helpers.js` and `src/template-parts/template-part-recommender-helpers.js`
   - Benefit: medium-low
   - Cost: medium
   - Confidence: medium
   - Regression risk: medium if slot/path semantics are flattened

4. Docs-only correction: add or reuse one architecture note that names the current freshness spine
   - Exact owner: architecture docs, not runtime code
   - Benefit: medium
   - Cost: low
   - Confidence: high
   - Regression risk: none

5. Larger refactor proposals are not justified by current evidence
   - Not recommended now: generic controller framework, generic server overlay service, “one recommendation pipeline” rewrite, broad prompt-base-class refactor
   - Benefit: speculative or weakly supported
   - Cost: medium to high
   - Confidence: low

## Keep Distinct Candidates

- Keep `src/patterns/PatternRecommender.js` and `inc/Abilities/PatternAbilities.php::recommend_patterns()` distinct from the preview/apply surfaces. The embedding, Qdrant, and reranking stack is its own architecture.
- Keep `src/inspector/NavigationRecommendations.js` and `inc/Abilities/NavigationAbilities.php::recommend_navigation()` advisory-only. Do not backfit them into the apply/undo runtime.
- Keep `src/store/index.js::applySuggestion()` and `inc/Abilities/BlockAbilities.php::recommend_block()` distinct. Block execution is local safe-attribute mutation, not operation review.
- Keep `inc/LLM/TemplatePrompt.php` and `inc/LLM/TemplatePartPrompt.php` distinct. Template slot assignment and template-part path targeting are not one validator with two labels.
- Keep `StyleAbilities::build_global_styles_context()` and `StyleAbilities::build_style_book_context()` distinct entry points. They share a prompt class, not a scope model.
- Keep `inc/Abilities/ContentAbilities.php::recommend_content()` out of any executable-surface refactor. It shares request plumbing, not interaction semantics.
- Keep `inc/Context/ServerCollector.php` as the current server-context facade. That is already the right boundary for server-derived context assembly.

## Recommended Next Step

Do one targeted JS extraction in `src/store/index.js`, not a cross-repo architecture rewrite.

Specifically:

- extract the shared fetch/apply runtime for `template`, `template-part`, `global-styles`, and `style-book`
- leave `block`, `navigation`, `pattern`, and `content` unchanged
- leave REST handlers, collectors, and prompt validators unchanged

Why this is the smallest justified step:

- it addresses the clearest current duplication hotspot
- it keeps the shipped surface differences intact
- it does not risk inventing a fake generic server pipeline that the repo does not actually need

If no runtime churn is desired right now, the conservative alternative is “no refactor yet” plus docs that keep the existing freshness spine explicit. That is a defensible choice because the current architecture is already coherent.

## Optional Phased Plan

### Phase 1

- Objective: extract shared review-surface request/apply runtime from `src/store/index.js`
- Exact file targets:
  - `src/store/index.js`
  - new helper such as `src/store/executable-surface-runtime.js`
  - `src/store/__tests__/store-actions.test.js`
- Abstractions to extract or boundaries to redraw:
  - request-token/context-signature/resolved-signature fetch wrapper
  - client-stale plus server-revalidation apply wrapper
  - shared success/error transition helpers
- Behavior that must remain unchanged:
  - endpoint paths
  - selectors and action creators
  - stale messages and `staleReason` values
  - request meta attachment
  - activity entry shapes
- Risks/regressions:
  - mismatched selector names across surfaces
  - lost `resolvedContextSignature` persistence
  - accidental inclusion of `block` in the shared wrapper
- Tests/docs that must move with the change:
  - `src/store/__tests__/store-actions.test.js` server-stale apply tests
  - `docs/reference/shared-internals.md` only if helper ownership changes materially
- Why this phase should come before the next one:
  - it captures the highest-value duplication without touching UI copy or PHP contracts

### Phase 2

- Objective: extract shared style-surface presentation helpers only if the diff still leaves meaningful duplication
- Exact file targets:
  - `src/global-styles/GlobalStylesRecommender.js`
  - `src/style-book/StyleBookRecommender.js`
  - new helper under `src/style-surfaces/`
- Abstractions to extract or boundaries to redraw:
  - shared operation list
  - shared suggestion card presentation
  - optional shared style-panel shell props mapper
- Behavior that must remain unchanged:
  - Global Styles scope copy
  - Style Book block-target copy
  - portal vs document-panel mount behavior
- Risks/regressions:
  - losing surface-specific copy and scope details
- Tests/docs that must move with the change:
  - any panel tests that assert stale copy or review CTA text
  - `docs/reference/recommendation-ui-consistency.md` only if component ownership becomes part of the docs contract
- Why this phase should come after Phase 1:
  - it is lower leverage than the store runtime extraction and mostly presentational

## Validation Checklist

- Verify `src/store/index.js::guardSurfaceApplyFreshness()` still blocks client-stale results before any mutate path runs.
- Verify `src/store/index.js::guardSurfaceApplyResolvedFreshness()` still strips `contextSignature`, re-posts with `resolveSignatureOnly: true`, and compares `resolvedContextSignature` before mutation.
- Verify `inc/REST/Agent_Controller.php::handle_recommend_block()` still returns `{ payload, clientId }` for both full and signature-only responses.
- Verify `inc/REST/Agent_Controller.php::handle_recommend_template()`, `handle_recommend_template_part()`, and `handle_recommend_style()` still return top-level `resolvedContextSignature` on signature-only requests.
- Verify `inc/Abilities/BlockAbilities.php::recommend_block()`, `TemplateAbilities::recommend_template()`, `TemplateAbilities::recommend_template_part()`, and `StyleAbilities::recommend_style()` still compute signatures before docs guidance/model execution.
- Verify docs-guidance churn still does not change `resolvedContextSignature` coverage in `tests/phpunit/BlockAbilitiesTest.php` and `tests/phpunit/StyleAbilitiesTest.php`.
- Verify `src/store/__tests__/store-actions.test.js` still covers client-stale and server-stale apply blocking for block, template, template-part, global-styles, and style-book.
- Verify `pattern` remains retrieval/rerank only, `navigation` remains advisory only, and `block` remains direct apply.

