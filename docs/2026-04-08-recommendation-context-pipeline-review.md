# Recommendation Context Pipeline Review

Date: 2026-04-08

Scope reviewed:

- `block`
- `pattern`
- `template`
- `template-part`
- `global-styles`
- `style-book`

Primary code entry points reviewed:

- `src/context/collector.js`
- `src/patterns/PatternRecommender.js`
- `src/templates/TemplateRecommender.js`
- `src/templates/template-recommender-helpers.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/template-parts/template-part-recommender-helpers.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Context/ServerCollector.php`
- `inc/Context/BlockContextCollector.php`
- `inc/Context/TemplateContextCollector.php`
- `inc/Context/TemplatePartContextCollector.php`
- `inc/Support/CollectsDocsGuidance.php`
- `inc/LLM/Prompt.php`
- `inc/LLM/TemplatePrompt.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/LLM/StylePrompt.php`

Reference docs reviewed:

- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/features/block-recommendations.md`
- `docs/features/pattern-recommendations.md`
- `docs/features/template-recommendations.md`
- `docs/features/template-part-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/shared-internals.md`
- `docs/superpowers/plans/2026-04-08-context-retrieval-and-prompt-grounding-remediation-plan.md`

## 1. Architecture Summary

Flavor Agent does not have one generic recommendation pipeline. Within the scope reviewed here, the shipped surfaces split into four materially different backend input shapes:

- `block` uses a direct structured chat prompt through `Prompt::build_user()` and `ChatClient::chat()`.
- `pattern` uses embedding query construction, Qdrant retrieval, and LLM reranking through `PatternAbilities::recommend_patterns()`.
- `template` and `template-part` use review-first ranked prompts through `TemplatePrompt` and `TemplatePartPrompt`.
- `global-styles` and `style-book` use `StylePrompt` with theme-safe `theme.json` style operations and surface-specific scope handling.

Navigation is a separate shipped recommendation surface with its own advisory-only pipeline through `NavigationAbilities` and `NavigationPrompt`, but it is out of scope for this document because it does not participate in the executable review/apply contract covered below.

Across the executable review surfaces, the same broad pattern exists:

- JS collects mutable live editor state.
- REST routes validate and sanitize transport payloads.
- PHP collectors build canonical server metadata.
- ability-layer overlay helpers merge the live editor slices over the canonical context.
- docs guidance is retrieved from the merged context.
- the final prompt or model input is built from that merged context.
- the response is parsed and validated against surface-specific constraints before the UI can review or apply it.

The April 2026 live-context remediation is mostly present. I did not find a current case where saved template or template-part structure still leaks into the final prompt after a live snapshot is provided. The template overlay helpers now replace live slot and structure slices atomically in `inc/Abilities/TemplateAbilities.php:379-455`, and the JS request builders now send explicit live snapshots, including empty-state structure, from `src/templates/TemplateRecommender.js:357-376` and `src/template-parts/TemplatePartRecommender.js:446-462`.

The remaining defect class is not saved-state leakage. It is freshness drift between the client request signature and the final prompt input. The largest gap is on `template` and `template-part`, where PHP still adds server-selected `patterns`, full `themeTokens`, and docs-guidance payloads after the client signature is computed. The style surfaces are narrower: they already hash a client-side `executionContract`, so supported style path and preset-slug drift is mostly covered, but PHP still adds richer prompt-shaping data such as full token payloads, docs guidance, and style-book `blockManifest` fields that are not mirrored in the signature. `block` still has a smaller version of the same problem through server-collected docs guidance.

## 2. Per-Surface Breakdown

### Block

- Entry points:
  `src/context/collector.js` builds the editor snapshot, the data store sends `POST /flavor-agent/v1/recommend-block`, `inc/REST/Agent_Controller.php` validates `editorContext`, and `inc/Abilities/BlockAbilities.php` normalizes the request before calling `inc/LLM/Prompt.php`.
- Client context:
  `collectBlockContext()` sends live block data from the selected editor node: `block.currentAttributes`, `inspectorPanels`, `bindableAttributes`, `styles`, `activeStyle`, `variations`, `supportsContentRole`, `contentAttributes`, `configAttributes`, `editingMode`, `isInsideContentOnly`, `blockVisibility`, `childCount`, `structuralIdentity`, plus `siblingsBefore`, `siblingsAfter`, `structuralAncestors`, `structuralBranch`, and a summarized `themeTokens` snapshot.
- Server context:
  `BlockContextCollector::for_block()` rebuilds canonical block metadata from the registered block type and server theme tokens in `inc/Context/BlockContextCollector.php`. `BlockAbilities::build_context_from_selected_block()` uses that canonical context as the base.
- Merge/overlay points:
  `BlockAbilities::build_context_from_editor_context()` overlays client-only live fields back onto the server block context, preserving editor-only state the server cannot infer reliably, including `editingMode`, `title`, `inspectorPanels`, `bindableAttributes`, `styles`, `activeStyle`, `childCount`, `structuralIdentity`, `structuralAncestors`, `structuralBranch`, `blockVisibility`, and the client token summary.
- Docs grounding path:
  `BlockAbilities::collect_wordpress_docs_guidance()` calls `CollectsDocsGuidance::collect()` with a block-specific docs query and entity key.
- Final prompt/model input builder:
  `Prompt::build_system()` plus `Prompt::build_user()` build a direct structured chat prompt. `Prompt::parse_response()` validates suggestion groups, and `Prompt::enforce_block_context_rules()` strips suggestions that violate the current block contract.
- Response/apply contract:
  This matches the shipped UI contract in `docs/FEATURE_SURFACE_MATRIX.md`: direct apply for safe local block updates.
- Stale/context signature inputs:
  `buildBlockRecommendationContextSignature()` hashes the live block snapshot. The signature is used only by the JS store to mark freshness and request identity; the server uses `editorContext` itself.

### Pattern

- Entry points:
  `src/patterns/PatternRecommender.js` builds the inserter request, `POST /flavor-agent/v1/recommend-patterns` is validated by `inc/REST/Agent_Controller.php`, and `inc/Abilities/PatternAbilities.php` runs retrieval and reranking.
- Client context:
  The request includes `postType`, optional `templateType`, optional `prompt`, optional `blockContext`, `visiblePatternNames`, and insertion context from `buildInsertionContext()`: `rootBlock`, `ancestors`, `nearbySiblings`, `templatePartArea`, `templatePartSlug`, and `containerLayout`.
- Server context:
  `PatternAbilities::recommend_patterns()` adds pattern-index runtime state, embedding backend readiness, Qdrant collection validation, candidate payload hydration, docs guidance, and ranking hints derived from pattern metadata.
- Merge/overlay points:
  This surface does not merge saved document structure over a live editable document slice. The client inserter context narrows retrieval and reranking; `visiblePatternNames` constrains the server candidate pool instead of being advisory only.
- Docs grounding path:
  `PatternAbilities::collect_wordpress_docs_guidance()` builds a docs query from `postType`, `templateType`, `blockContext`, `insertionContext`, and `visiblePatternNames`.
- Final prompt/model input builder:
  `PatternAbilities::build_embedding_query()` produces the embedding query, Qdrant performs two-pass retrieval, then `build_ranking_input()` creates the LLM reranking input with editing context, docs guidance, and candidate payloads.
- Response/apply contract:
  This matches the shipped contract in `docs/features/pattern-recommendations.md`: browse and rank only. Flavor Agent never directly applies the pattern.
- Stale/context signature inputs:
  There is no shared review signature here. Freshness follows the current inserter state and request params, which is appropriate because the surface has no review/apply/undo contract.

### Template

- Entry points:
  `src/templates/TemplateRecommender.js` assembles the request with `buildEditorTemplateSlotSnapshot()`, `buildEditorTemplateTopLevelStructureSnapshot()`, and `buildTemplateFetchInput()`. `POST /flavor-agent/v1/recommend-template` is validated in `inc/REST/Agent_Controller.php`, then normalized and executed by `TemplateAbilities::recommend_template()`.
- Client context:
  The request carries `templateRef`, `templateType`, optional `prompt`, `visiblePatternNames`, `editorSlots`, and `editorStructure`. The live template slice includes `assignedParts`, `emptyAreas`, `topLevelBlockTree`, `structureStats`, `currentPatternOverrides`, and `currentViewportVisibility`.
- Server context:
  `TemplateContextCollector::for_template()` adds canonical template metadata: `title`, server-derived `assignedParts`, `emptyAreas`, `allowedAreas`, `topLevelBlockTree`, `topLevelInsertionAnchors`, `structureStats`, `availableParts`, server-selected `patterns`, and server `themeTokens` in `inc/Context/TemplateContextCollector.php:70-91`.
- Merge/overlay points:
  `apply_template_live_slot_context()` and `apply_template_live_structure_context()` replace the mutable template slot and structure slices in `inc/Abilities/TemplateAbilities.php:379-427`. The overlay keeps `availableParts`, server-selected `patterns`, and `themeTokens` from PHP, but live `assignedParts`, `emptyAreas`, `allowedAreas`, `topLevelBlockTree`, `topLevelInsertionAnchors`, `structureStats`, `currentPatternOverrides`, and `currentViewportVisibility` take precedence when provided.
- Docs grounding path:
  `TemplateAbilities::collect_wordpress_docs_guidance()` calls `CollectsDocsGuidance::collect()` with template-specific query and family builders in `inc/Abilities/TemplateAbilities.php:1353-1677`. The current query shaping includes bounded `visiblePatternNames`, while the family context keeps that scope coarse through markers such as `hasVisiblePatternScope` and `visiblePatternCount`.
- Final prompt/model input builder:
  `TemplatePrompt::build_system()` and `TemplatePrompt::build_user()` build the review-first prompt, then `ResponsesClient::rank()` sends it to the backend.
- Response parsing/review constraints:
  `TemplatePrompt::parse_response()` validates template-part references, pattern suggestions, allowed areas, insertion anchors, and executable operations against the merged context before the UI can preview or apply them.
- Response/apply contract:
  This matches the shipped contract in `docs/FEATURE_SURFACE_MATRIX.md`: review before apply, with validated template and pattern operations.
- Stale/context signature inputs:
  `buildTemplateRecommendationContextSignature()` hashes `assignedParts`, `emptyAreas`, `topLevelBlockTree`, `structureStats`, `currentPatternOverrides`, `currentViewportVisibility`, and `visiblePatternNames` in `src/templates/template-recommender-helpers.js:239-265`. `buildTemplateFetchInput()` includes that signature in JS only, and the store strips it before REST in `src/store/index.js:3216-3222`.

### Template-Part

- Entry points:
  `src/template-parts/TemplatePartRecommender.js` builds the request through `buildEditorTemplatePartStructureSnapshot()`, `buildTemplatePartRecommendationContextSignature()`, and `buildTemplatePartFetchInput()`. `POST /flavor-agent/v1/recommend-template-part` is validated in `inc/REST/Agent_Controller.php`, then executed by `TemplateAbilities::recommend_template_part()`.
- Client context:
  The request carries `templatePartRef`, optional `prompt`, `visiblePatternNames`, and a full live `editorStructure` slice: `blockTree`, `allBlockPaths`, `topLevelBlocks`, `blockCounts`, `structureStats`, `currentPatternOverrides`, `operationTargets`, `insertionAnchors`, and `structuralConstraints`.
- Server context:
  `TemplatePartContextCollector::for_template_part()` adds canonical `slug`, `title`, `area`, saved `blockTree`, `topLevelBlocks`, `blockCounts`, `structureStats`, `currentPatternOverrides`, `operationTargets`, `insertionAnchors`, `structuralConstraints`, server-selected `patterns`, and server `themeTokens` in `inc/Context/TemplatePartContextCollector.php:64-95`.
- Merge/overlay points:
  `apply_template_part_live_structure_context()` atomically replaces the whole mutable structure slice in `inc/Abilities/TemplateAbilities.php:435-455`. That includes `allBlockPaths`, so target-path validation can stay live even when the prompt-facing `blockTree` is summarized.
- Docs grounding path:
  `TemplateAbilities::collect_template_part_wordpress_docs_guidance()` uses the template-part docs query and family builders in `inc/Abilities/TemplateAbilities.php:1457-1710`. The current query shaping includes `currentPatternOverrides`, while the family context records only bounded override markers such as `hasPatternOverrides` and `patternOverrideCount`.
- Final prompt/model input builder:
  `TemplatePartPrompt::build_system()` and `TemplatePartPrompt::build_user()` build the review-first prompt, then `ResponsesClient::rank()` sends it.
- Response parsing/review constraints:
  `TemplatePartPrompt::parse_response()` validates block hints, pattern suggestions, operation targets, insertion anchors, and operation sequences. `allBlockPaths` backs the lookup used by `build_block_lookup()` so deep target paths remain executable against the live tree.
- Response/apply contract:
  This matches the shipped contract in `docs/features/template-part-recommendations.md`: review before apply, with executable target and anchor constraints.
- Stale/context signature inputs:
  `buildTemplatePartRecommendationContextSignature()` hashes `blockTree`, `allBlockPaths`, `topLevelBlocks`, `blockCounts`, `structureStats`, `operationTargets`, `insertionAnchors`, `structuralConstraints`, `currentPatternOverrides`, and `visiblePatternNames` in `src/template-parts/template-part-recommender-helpers.js:460-492`. `buildTemplatePartFetchInput()` includes that signature in JS only, and the store strips it before REST in `src/store/index.js:3270-3277`.

### Global Styles

- Entry points:
  `src/global-styles/GlobalStylesRecommender.js` builds the request through `buildRequestInput()`. `POST /flavor-agent/v1/recommend-style` is validated in `inc/REST/Agent_Controller.php`, and `StyleAbilities::recommend_style()` dispatches to the `global-styles` surface builder.
- Client context:
  The request carries a global-styles scope plus `styleContext.currentConfig`, `mergedConfig`, `availableVariations`, `templateStructure`, `templateVisibility`, `designSemantics`, and `themeTokenDiagnostics`. The live mutable style state comes from the editor UI, not from PHP.
- Server context:
  `StyleAbilities::build_shared_style_context()` normalizes the client style config, injects server `themeTokens`, computes `supportedStylePaths`, resolves the active variation, and carries through `templateStructure`, `templateVisibility`, and `designSemantics` in `inc/Abilities/StyleAbilities.php:230-278`.
- Merge/overlay points:
  There is no saved-style overlay over the live editor config. The mutable config, structure, visibility, and design-semantics slices come from the client; PHP adds canonical constraints such as `themeTokens`, supported style paths, and active variation metadata.
- Docs grounding path:
  `StyleAbilities::collect_wordpress_docs_guidance()` uses the merged style scope and style context to query WordPress docs.
- Final prompt/model input builder:
  `StylePrompt::build_system()` and `StylePrompt::build_user()` build the style prompt. The prompt consumes `themeTokens`, `supportedStylePaths`, `templateStructure`, `designSemantics`, and `templateVisibility` from the merged style context in `inc/LLM/StylePrompt.php:87-96`.
- Response parsing/review constraints:
  `StylePrompt::parse_response()` and `validate_operations()` enforce supported style paths, preset existence in `themeTokens`, and surface-safe operation types.
- Response/apply contract:
  This matches the shipped contract in `docs/features/style-and-theme-intelligence.md`: review before apply, with validated theme-safe `theme.json` operations.
- Stale/context signature inputs:
  `buildGlobalStylesRecommendationContextSignature()` hashes scope, `currentConfig`, `mergedConfig`, `availableVariations`, `templateStructure`, `templateVisibility`, `designSemantics`, `themeTokenDiagnostics`, and a client-side `executionContract` in `src/utils/style-operations.js:243-283`. The request includes the signature only in JS, and the store strips it before REST in `src/store/index.js:3325-3331`.

### Style Book

- Entry points:
  `src/style-book/StyleBookRecommender.js` builds the style-book request through its `buildRequestInput()`. `POST /flavor-agent/v1/recommend-style` is validated in `inc/REST/Agent_Controller.php`, and `StyleAbilities::recommend_style()` dispatches to the `style-book` surface builder.
- Client context:
  The request carries style-book scope plus `styleContext.currentConfig`, `mergedConfig`, `themeTokenDiagnostics`, `styleBookTarget`, `templateStructure`, `templateVisibility`, and `designSemantics`. The target block's live styles come from the client snapshot.
- Server context:
  `StyleAbilities::build_style_book_context()` resolves the target block manifest through `ServerCollector::introspect_block_type()`, derives `supportedStylePaths` from that manifest, injects server `themeTokens`, and adds normalized `blockManifest` context in `inc/Abilities/StyleAbilities.php:170-278`.
- Merge/overlay points:
  The mutable block style snapshot comes from the client. PHP adds the canonical block-support contract through `blockManifest` and supported block style paths. I did not find a case where saved template structure or saved global styles were incorrectly overriding the live style-book state.
- Docs grounding path:
  `StyleAbilities::collect_wordpress_docs_guidance()` runs against the merged style-book scope and target-block context.
- Final prompt/model input builder:
  `StylePrompt::build_user()` consumes `themeTokens`, `supportedStylePaths`, `styleBookTarget`, `blockManifest`, `templateStructure`, `designSemantics`, and `templateVisibility` in `inc/LLM/StylePrompt.php:87-96`.
- Response parsing/review constraints:
  `StylePrompt::parse_response()` validates `set_block_styles` operations against the merged style-book contract, including supported paths and preset safety.
- Response/apply contract:
  This matches the shipped contract in `docs/features/style-and-theme-intelligence.md`: review before apply with validated style operations constrained to the active Style Book target.
- Stale/context signature inputs:
  The UI uses the same signature builder as global styles, but without variations. The signature covers the client snapshot and local execution contract only; the final backend input still depends on server `themeTokens` and block-manifest-derived `supportedStylePaths` and `blockManifest`.

## 3. Findings Ordered By Severity

### Medium

1. Review freshness does not cover all final prompt inputs for `template`, `template-part`, `global-styles`, and `style-book`, but the style-surface gap is narrower than the template gap.

Evidence:

- the template and template-part surfaces compute freshness only from client-known live fields in `src/templates/template-recommender-helpers.js:239-265` and `src/template-parts/template-part-recommender-helpers.js:460-492`
- the style surfaces compute freshness from the client snapshot plus a client-side `executionContract` in `src/utils/style-operations.js:243-283`; current regression coverage already proves stale handling when diagnostics, supported style paths, or preset slugs change in `src/global-styles/__tests__/GlobalStylesRecommender.test.js:1057-1208`
- the store explicitly strips `contextSignature` before REST in `src/store/index.js:3216-3222`, `src/store/index.js:3270-3277`, `src/store/index.js:3325-3331`, and `src/store/index.js:3379-3385`
- PHP still adds prompt-shaping server fields after that point:
  `patterns` and `themeTokens` for templates in `inc/Context/TemplateContextCollector.php:86-91`
  `patterns` and `themeTokens` for template parts in `inc/Context/TemplatePartContextCollector.php:91-95`
  full `themeTokens` and docs guidance for styles in `inc/Abilities/StyleAbilities.php:230-285`
  prompt-facing `blockManifest` data for style-book in `inc/Abilities/StyleAbilities.php:272-275`
- the final prompt builders actually consume those server fields:
  `TemplatePrompt::build_user()` reads `patterns` and `themeTokens` in `inc/LLM/TemplatePrompt.php:156-252`
  `StylePrompt::build_user()` reads `themeTokens`, `supportedStylePaths`, and `blockManifest` in `inc/LLM/StylePrompt.php:87-96`
  `TemplatePartPrompt::build_user()` reads `currentPatternOverrides` and `patterns` in `inc/LLM/TemplatePartPrompt.php:196-203`

Impact:

- a template review can still appear fresh after the server pattern candidate set, full theme tokens, or docs guidance change
- a template-part review can still appear fresh after server pattern, full theme-token, or docs-guidance drift
- a global-styles review can still appear fresh after full token or docs-guidance drift that is not represented by the client `executionContract`
- a style-book review can still appear fresh after prompt-only `blockManifest` drift or docs-guidance drift, even when executable style paths remain the same

This is a freshness bug, not an execute-anything safety break. The problem is that the UI freshness gate does not reflect the full resolved backend input. The existing safety model still relies on live client-side executors and validators, including template path/anchor checks in `src/utils/template-actions.js` and style execution-contract validation in `src/utils/style-operations.js`.

2. `block` still relies on a client-only freshness signature even though the final prompt includes server-collected docs guidance.

Evidence:

- `buildBlockRecommendationContextSignature()` hashes only the client snapshot in `src/utils/block-recommendation-context.js:22-36`
- block apply freshness is enforced from that stored signature in `src/store/index.js:1958-1973`
- `BlockAbilities::recommend_block()` still injects server-collected docs guidance immediately before prompt assembly in `inc/Abilities/BlockAbilities.php:32-37`
- the docs guidance payload itself is resolved in PHP through `CollectsDocsGuidance::collect()` and `AISearchClient::maybe_search_with_cache_fallbacks()` in `inc/Abilities/BlockAbilities.php:295-302` and `inc/Support/CollectsDocsGuidance.php:18-29`

Impact:

- a block suggestion can still appear fresh after docs-guidance cache or fallback drift changes the backend prompt without changing the live editor snapshot
- this is narrower than the template/style issue because the block client snapshot already carries theme tokens and most block metadata

This is still a freshness-model gap rather than an apply-safety break. The local attribute executor remains guarded by block-context validation; the problem is that the stored freshness proof is not fully aligned with the resolved backend prompt inputs.

### Resolved Since The Original Review

- Template docs grounding now uses bounded `visiblePatternNames` in `TemplateAbilities::build_wordpress_docs_query()`, and the family cache context records only coarse scope markers such as `hasVisiblePatternScope` and `visiblePatternCount`.
- Template-part docs grounding now uses `currentPatternOverrides` in `TemplateAbilities::build_template_part_wordpress_docs_query()`, and the family cache context records only bounded override markers such as `hasPatternOverrides` and `patternOverrideCount`.
- The current feature docs match that implementation in `docs/features/template-recommendations.md` and `docs/features/template-part-recommendations.md`.

Verified non-findings:

- I did not find a current case where saved template or template-part structure leaks into a supposedly live recommendation when a live snapshot is present.
- I did not find a current case where an explicit live empty template state is treated as missing instead of authoritative.
- I did not find a contract mismatch where `pattern` behaves like a review/apply surface. It remains retrieval and browse only, which matches the shipped docs.

## 4. Gaps In Tests And Current Regression Coverage

Current regression coverage present in the repo:

- `npm run test:unit -- --runInBand src/templates/__tests__/template-recommender-helpers.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/template-part-recommender-helpers.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/global-styles/__tests__/GlobalStylesRecommender.test.js src/style-book/__tests__/StyleBookRecommender.test.js`
- `vendor/bin/phpunit --filter 'TemplatePromptTest|TemplatePartPromptTest|AgentControllerTest|DocsGroundingEntityCacheTest'`

What the passing coverage already proves:

- template and template-part JS request builders now emit live structure snapshots and signatures
- the template and template-part recommender UIs still respect the review-first flow
- the global-styles and style-book recommenders still preserve the current review/apply behavior
- the global-styles freshness UI already invalidates results when theme token diagnostics, supported style paths, or preset slugs drift through the client execution contract
- `DocsGroundingEntityCacheTest` now covers related PHP docs-grounding behavior for template structure summaries, template-part executable context, and style-book query-cache precedence

What is still missing:

- there is no regression test that compares the client freshness signature with the actual resolved server input and proves staleness when template or template-part server `patterns`, full `themeTokens`, or docs guidance change
- there is no regression test that proves block freshness changes when server docs guidance changes while the live editor snapshot stays identical
- there is no dedicated PHPUnit assertion that template docs grounding query and family context preserve the bounded visible-pattern markers now used in production
- there is no dedicated PHPUnit assertion that template-part docs grounding query and family context preserve the override-aware markers derived from `currentPatternOverrides`
- there is no style-book regression with parity to the global-styles coverage that proves prompt-only `blockManifest` drift or server docs-guidance drift invalidates a stored review result

## 5. Actionable Checklist

This checklist now focuses on the remaining freshness findings above and the narrower regression gaps that are still open:

- freshness needs a client/store revalidation path, not only a server-returned hash
- docs-family cache keys must stay coarse enough to preserve fallback reuse

### A. Define the freshness contract

- [ ] Decide per surface whether freshness must include server-only drift:
  - `template` and `template-part`: yes
  - `global-styles` and `style-book`: yes for prompt-only server inputs that are not already mirrored by the client `executionContract`
  - `block`: either yes, or explicitly document that freshness remains client-only and excludes docs-guidance drift
- [ ] Document the two signatures separately in code and docs:
  - `clientContextSignature`: client-computed snapshot of live editor state
  - `resolvedContextSignature`: server-computed digest of the merged backend input actually used for prompt/model assembly
- [ ] Write down the exact fields each surface includes in `resolvedContextSignature` so future changes do not silently widen or narrow freshness.

### B. Add server-side resolved-input fingerprinting

- [x] Add a deterministic PHP helper that computes `resolvedContextSignature` after server collection, live overlay, and prompt-shaping context resolution such as patterns, theme tokens, supported style paths, and style-book block-manifest data.
- [x] Compute and return `resolvedContextSignature` for `template`, `template-part`, `global-styles`, `style-book`, and `block`, and persist it separately from the existing client signature.
- [ ] Expand the resolved signature to include docs-guidance preparation, or a stable digest of the final assembled prompt input, so server-only docs drift invalidates review results before apply.
- [ ] For `block`, either compute the same fully prompt-sensitive server signature or explicitly document that its hybrid freshness contract still excludes docs-guidance drift.

### C. Wire client/store freshness correctly

- [x] Keep the current client signature as the fast local invalidation signal for prompt changes and live editor changes.
- [x] Add a pre-apply freshness revalidation path through `resolveSignatureOnly` on the executable recommendation endpoints.
- [x] On apply for `template`, `template-part`, `global-styles`, and `style-book`, compare live and stored client signatures first, then compare the stored and revalidated server `resolvedContextSignature`.
- [x] Distinguish local client drift from server-side drift in apply-time stale handling.
- [ ] If review freshness itself must reflect server-only prompt shapers before apply, add a server-issued review signature or smaller prompt-shaper fingerprints to recommendation responses and fold them into the UI freshness model.

### D. Tighten docs grounding inputs without fragmenting cache keys

- [x] Extend `TemplateAbilities::build_wordpress_docs_query()` to include a bounded visible-pattern scope summary.
- [x] Extend `TemplateAbilities::build_template_part_wordpress_docs_query()` to include a bounded override summary.
- [x] Keep the added query shaping bounded:
  - count of visible patterns or overrides
  - first N names only when needed
  - booleans or normalized markers for scope families
- [x] Keep `build_wordpress_docs_family_context()` and `build_template_part_wordpress_docs_family_context()` coarse and stable. Prefer counts, booleans, and normalized markers over raw name lists so family-cache reuse remains effective.
- [ ] Add focused regression assertions that lock the bounded visible-pattern and override-aware docs-grounding markers in place.

### E. Add regression coverage at the right boundary

- [ ] Add PHP tests proving `resolvedContextSignature` changes when template `patterns` change.
- [ ] Add PHP tests proving `resolvedContextSignature` changes when full style `themeTokens` or docs guidance change beyond the client `executionContract`.
- [ ] Add PHP tests proving `resolvedContextSignature` changes when style-book prompt-only `blockManifest` fields change.
- [ ] Add PHP docs-grounding tests for template visible-pattern query shaping.
- [ ] Add PHP docs-grounding tests for template-part override-aware query shaping.
- [ ] Add PHP cache tests proving new family-context fields stay coarse enough to reuse family fallback caches.
- [ ] Add JS store or integration tests covering the new pre-apply revalidation flow and stale handling for server drift.
- [ ] Add end-to-end or editor integration coverage that a review becomes stale when:
  - template candidate patterns change server-side
  - template or style docs guidance changes server-side
  - full style tokens change server-side without a corresponding client execution-contract change
  - style-book prompt-only `blockManifest` context changes server-side
  - block docs guidance changes, if `block` is moved onto the server-sensitive freshness model

### F. Keep the current guarantees intact

- [ ] Do not loosen prompt parsing or operation validation to solve freshness.
- [ ] Keep the current ownership split:
  - client owns mutable live editor state
  - server owns canonical metadata, docs grounding, and validation
- [ ] Do not present resolved-signature work as the only safety mechanism; live JS-side apply validation remains the last line of defense after a result is generated.
- [ ] Keep `pattern` as browse/rank only.
- [ ] Keep `template`, `template-part`, `global-styles`, and `style-book` review-first.

### G. Reconcile the current style-book docs-cache failure separately

- [ ] Decide the intended behavior for style-book docs fallback when the block-scoped entity cache is cold but the generic style-book cache is warm.
- [ ] Update either the implementation or `DocsGroundingEntityCacheTest::test_style_book_docs_guidance_falls_back_to_style_book_guidance_when_block_entity_cache_is_cold` so the contract is explicit.
- [ ] Add a short follow-up note in this document once that cache contract is settled, because it is adjacent to the docs-grounding work but not the same defect class as the live-context freshness findings.
