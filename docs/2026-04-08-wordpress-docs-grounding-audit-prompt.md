# WordPress Docs Grounding Audit Prompt

Date: 2026-04-08

Purpose: Repo-specific audit prompt for Flavor Agent's WordPress docs grounding and recommendation pipelines.

```text
Audit this repo’s WordPress developer-doc grounding pipeline for recommendation quality and scope relevance.

Do not treat recommendations as one generic flow. Trace the actual implementation for these shipped surfaces:
- `block`
- `pattern`
- `template`
- `template-part`
- `global-styles`
- `style-book`

Goal:
Determine how WordPress developer docs are retrieved with high relevance to the current recommendation surface and live scope, and then how that guidance is used to improve recommendation quality. Separate docs grounding from hard safety/validation logic.

Repository scope:
- Primary implementation:
  - `src/context/collector.js`
  - `src/patterns/PatternRecommender.js`
  - `src/templates/TemplateRecommender.js`
  - `src/templates/template-recommender-helpers.js`
  - `src/template-parts/TemplatePartRecommender.js`
  - `src/template-parts/template-part-recommender-helpers.js`
  - `src/global-styles/GlobalStylesRecommender.js`
  - `src/style-book/StyleBookRecommender.js`
  - `src/store/index.js`
  - `inc/REST/Agent_Controller.php`
  - `inc/Context/BlockContextCollector.php`
  - `inc/Context/TemplateContextCollector.php`
  - `inc/Context/TemplatePartContextCollector.php`
  - `inc/Support/CollectsDocsGuidance.php`
  - `inc/Cloudflare/AISearchClient.php`
  - `inc/Abilities/WordPressDocsAbilities.php`
  - `inc/Abilities/BlockAbilities.php`
  - `inc/Abilities/PatternAbilities.php`
  - `inc/Abilities/TemplateAbilities.php`
  - `inc/Abilities/StyleAbilities.php`
  - `inc/LLM/Prompt.php`
  - `inc/LLM/TemplatePrompt.php`
  - `inc/LLM/TemplatePartPrompt.php`
  - `inc/LLM/StylePrompt.php`
- Reference docs to verify against:
  - `docs/FEATURE_SURFACE_MATRIX.md`
  - `docs/features/block-recommendations.md`
  - `docs/features/pattern-recommendations.md`
  - `docs/features/template-recommendations.md`
  - `docs/features/template-part-recommendations.md`
  - `docs/features/style-and-theme-intelligence.md`
  - `docs/reference/abilities-and-routes.md`
  - `docs/reference/pattern-recommendation-debugging.md`
  - `docs/2026-04-08-recommendation-context-pipeline-review.md`

Audit instructions:
1. For each surface, trace the full path:
   JS request builder -> REST route -> server collector/normalizer -> docs query/entity/family context builder -> `CollectsDocsGuidance::collect()` -> `AISearchClient::maybe_search_with_cache_fallbacks()` -> prompt or ranking builder -> response parser/validator.

2. For each surface, document:
   - what live client context is sent
   - what canonical server context is added
   - what fields are used to build the docs query
   - what entity key and family context are used
   - whether retrieval uses exact-query cache, family cache, entity cache, generic entity fallback, foreground warm, or async warm
   - where the returned docs guidance is consumed in the final recommendation pipeline

3. Judge whether docs retrieval is truly relevant to the live recommendation scope.
   Pay specific attention to whether the docs query reflects scope-shaping fields such as:
   - block: `block.name`, `title`, `inspectorPanels`, `editingMode`, `structuralIdentity`, `themeTokens`
   - pattern: `postType`, `templateType`, `blockContext`, insertion `ancestors`, `nearbySiblings`, `templatePartArea`, `containerLayout`, `visiblePatternNames`
   - template: `templateType`, `allowedAreas`, `assignedParts`, `emptyAreas`, `topLevelBlockTree`, `structureStats`, `currentPatternOverrides`, `currentViewportVisibility`, `visiblePatternNames`
   - template-part: `area`, `slug`, `topLevelBlocks`, `blockCounts`, `operationTargets`, `insertionAnchors`, `structuralConstraints`, `currentPatternOverrides`
   - styles: `supportedStylePaths`, `styleBookTarget`, `templateStructure`, `templateVisibility`, `designSemantics`, `themeTokens`, `blockManifest`

4. Compare docs-grounding inputs with final prompt/ranking inputs.
   Explicitly call out any fields that influence the final prompt, reranking input, or validation rules but do not influence docs retrieval.

5. Distinguish the surface-specific architectures:
   - `block` uses direct structured chat via `Prompt`
   - `pattern` uses embedding query construction, Qdrant retrieval, and LLM reranking
   - `template` and `template-part` use review-first ranked prompts via `TemplatePrompt` and `TemplatePartPrompt`
   - `global-styles` and `style-book` use `StylePrompt` and validated style operations

6. Verify whether docs grounding is best-effort and non-blocking, or required for the surface to function.
   Do not assume docs guidance materially drives recommendations if the code shows it is secondary to structural constraints, prompt context, or server-derived metadata.

7. Explicitly verify these likely weak points instead of assuming them:
   - whether template docs grounding ignores `visiblePatternNames`
   - whether template-part docs grounding ignores `currentPatternOverrides`
   - whether freshness tracking omits server-derived prompt-shaping inputs such as `patterns`, `themeTokens`, `supportedStylePaths`, or `blockManifest`

8. Include how the explicit helper ability `flavor-agent/search-wordpress-docs` / `WordPressDocsAbilities::search_wordpress_docs()` relates to recommendation-time grounding.
   Clarify whether it shares the same backend and caches, or is only a separate operator-facing entry point.

9. Use repo code as source of truth.
   Use repo docs only as expected-behavior claims to confirm or falsify.

Output format:
- Short architecture summary
- Per-surface breakdown
- Findings ordered by severity
- Verified non-findings
- Prioritized remediation list focused on improving docs relevance to the live recommendation scope, not just adding more docs
- Every finding must include exact file references and the specific function or code path involved
```
