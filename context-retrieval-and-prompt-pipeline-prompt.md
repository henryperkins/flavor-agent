Review Flavor Agent’s current context-retrieval and prompt-input pipeline for the shipped recommendation surfaces:

- `block`
- `pattern`
- `template`
- `template-part`
- `navigation`
- `content`
- `styles`, meaning both:
  - `global-styles`
  - `style-book`

Goal:
Explain, for each surface, how live editor context is collected, what canonical server context is added, how docs guidance is retrieved, and exactly what data becomes part of the final model input that drives relevant recommendations. Focus on the current repo state, not a hypothetical or pre-remediation architecture.

Important repo realities to preserve:

- This is a WordPress plugin that should feel native to Gutenberg and wp-admin.
- Do **not** assume one generic recommendation pipeline across all surfaces.
- Current surface shapes are intentionally different:
  - `block` = direct structured chat via `Prompt` + `ChatClient::chat()`
  - `pattern` = retrieval/rerank pipeline using docs guidance + embedding query + Qdrant + `ResponsesClient::rank()`
  - `template` and `template-part` = review-before-apply pipelines using `TemplatePrompt` / `TemplatePartPrompt`
  - `navigation` = advisory pipeline using `NavigationPrompt` + `ResponsesClient::rank()` with docs grounding
  - `content` = advisory pipeline using `WritingPrompt` + `ChatClient::chat()` without docs grounding
  - `global-styles` and `style-book` = review-before-apply pipelines using `StylePrompt` and validated theme-safe operations
- `pattern` is **not** a review/apply surface. It does **not** use `resolvedContextSignature` or `resolveSignatureOnly`.
- `navigation` and `content` are advisory-only surfaces (`advisoryOnly: true`). They use client-side freshness but **not** `resolvedContextSignature` or `resolveSignatureOnly`.
- For executable surfaces (`block`, `template`, `template-part`, `global-styles`, `style-book`):
  - immediate stale UI is driven by client request/context signatures
  - apply-time server freshness is driven by `resolvedContextSignature` + `resolveSignatureOnly`
- Docs grounding is best-effort/cache-first and should be analyzed separately from hard validation/execution rules.

Primary implementation to inspect:

- `src/context/collector.js`
- `src/patterns/PatternRecommender.js`
- `src/templates/TemplateRecommender.js`
- `src/templates/template-recommender-helpers.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/template-parts/template-part-recommender-helpers.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/inspector/NavigationRecommendations.js`
- `src/content/ContentRecommender.js`
- `src/store/index.js`
- `src/store/executable-surface-runtime.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/Abilities/ContentAbilities.php`
- `inc/Context/ServerCollector.php`
- `inc/Context/BlockContextCollector.php`
- `inc/Context/TemplateContextCollector.php`
- `inc/Context/TemplatePartContextCollector.php`
- `inc/Context/NavigationContextCollector.php`
- `inc/Support/CollectsDocsGuidance.php`
- `inc/Support/RecommendationResolvedSignature.php`
- `inc/Cloudflare/AISearchClient.php`
- `inc/LLM/ChatClient.php`
- `inc/LLM/Prompt.php`
- `inc/LLM/TemplatePrompt.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/LLM/StylePrompt.php`
- `inc/LLM/NavigationPrompt.php`
- `inc/LLM/WritingPrompt.php`

Reference docs to verify against:

- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/features/block-recommendations.md`
- `docs/features/pattern-recommendations.md`
- `docs/features/template-recommendations.md`
- `docs/features/template-part-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/shared-internals.md`
- `docs/features/navigation-recommendations.md`
- `docs/features/content-recommendations.md`
- `docs/2026-04-08-recommendation-context-pipeline-review.md`

Audit instructions:

1. Trace each surface end-to-end:
   UI/request builder -> store action -> REST handler -> ability -> server collector/normalizer -> docs-guidance builder -> prompt/ranking builder -> response parser/validator.

2. For each surface, document:

   - live client context collected
   - canonical server context added
   - merge/overlay point between live editor data and server-derived data
   - docs-grounding path:
     - query text
     - entity key
     - family context
     - whether it goes through `CollectsDocsGuidance::collect()` and `AISearchClient::maybe_search_with_cache_fallbacks()`
   - final prompt/model input path:
     - exact fields used by `Prompt::build_user()`, `TemplatePrompt::build_user()`, `TemplatePartPrompt::build_user()`, `NavigationPrompt::build_user()`, `WritingPrompt::build_user()`, `StylePrompt::build_user()`, or pattern ranking input builders
   - response parsing and validation rules
   - freshness model:
     - client-only request/context signature
     - server `resolvedContextSignature` where applicable
     - whether freshness affects prompt relevance only, or also apply safety

3. Be explicit about the differences between surfaces:

   - `block` uses a direct prompt contract and local safe attribute apply
   - `pattern` uses retrieval/reranking and browse-only insertion through core editor UX
   - `template` and `template-part` build executable review-first suggestions
   - `navigation` uses docs-grounded advisory recommendations via `ResponsesClient::rank()`
   - `content` uses direct chat for content generation without docs grounding
   - `global-styles` and `style-book` build validated `theme.json`-safe operations

4. Compare docs-grounding inputs with final prompt/ranking inputs.
   Call out any fields that shape the final prompt or ranking input but do not influence docs retrieval.

5. Explicitly verify these repo-specific contract points instead of assuming them:

   - `collectBlockContext()` sends live block/editor-only state that PHP overlays onto canonical block context
   - template docs grounding uses bounded `visiblePatternNames`
   - template-part docs grounding uses bounded `currentPatternOverrides`
   - `BlockAbilities::recommend_block()` computes `resolvedContextSignature` before docs/model work and returns early for `resolveSignatureOnly` and disabled-block requests
   - `pattern` intentionally does not implement the server-resolved freshness flow
   - style surfaces add server `themeTokens`, supported style paths, and for style-book, prompt-shaping `blockManifest` context
   - `src/store/executable-surface-runtime.js` strips client-only `contextSignature` before REST transport and persists `resolvedContextSignature` from responses
   - `navigation` uses `CollectsDocsGuidance` and `ResponsesClient::rank()` but is `advisoryOnly` with no server-resolved freshness
   - `content` uses `ChatClient::chat()` without docs grounding and is `advisoryOnly` with no server-resolved freshness

6. Use code as source of truth.
   Use docs only as expected-behavior claims to confirm or falsify.

7. Do not drift into a generic architecture refactor review.
   Focus on:

   - context retrieval quality
   - prompt/ranking input relevance
   - docs-grounding relevance
   - live-vs-server context merge behavior
   - freshness contracts only where they affect prompt/input correctness or review/apply safety

8. If you find a gap, classify it clearly:
   - missing live context
   - server-only context not reflected in prompt/ranking input
   - docs grounding too generic for the live scope
   - prompt/ranking input richer than docs query
   - stale/freshness mismatch
   - intentional surface-specific divergence that should remain distinct

Output format:

- short architecture summary
- per-surface breakdown
- cross-surface comparison table
- findings ordered by severity
- verified non-findings
- prioritized repo-specific improvements
- every finding must cite exact files and function names

Keep the analysis concrete and repo-specific. It is valid to conclude that some differences are intentional and correct.
