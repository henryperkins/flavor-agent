# WordPress Docs Grounding Audit Prompt

Date: 2026-04-09

Purpose: Repo-specific audit prompt for Flavor Agent's current WordPress docs-grounding, cache fallback, and docs-free freshness contracts.

```text
Audit Flavor Agent's current WordPress developer-doc grounding pipeline for recommendation relevance, scope fidelity, cache behavior, and actual downstream influence.

Target repo: Flavor Agent (WordPress plugin)

Primary goal:
For the shipped recommendation surfaces that currently use docs grounding, determine:
- how live recommendation scope is constructed
- how that scope becomes docs query / entity / family inputs
- how AI Search cache fallback and warm behavior serves guidance
- where retrieved docs guidance actually enters the final prompt or ranking input
- where docs retrieval is narrower than the real recommendation input
- whether docs grounding materially improves decisions, or mainly acts as a secondary aid layered on top of stronger structural constraints

Required surfaces:
- `block`
- `pattern`
- `template`
- `template-part`
- `global-styles`
- `style-book`

Optional surface:
- Include `navigation` only if it helps explain shared docs-grounding or docs-free review freshness behavior relevant to the required surfaces.

Current repo realities you must preserve:
- Do not treat this repo as having one generic recommendation pipeline.
- `block` uses direct structured chat through `Prompt` + `ChatClient::chat()`.
- `pattern` is a retrieval/rerank surface: embedding query -> Qdrant passes -> LLM reranking. It is browse-only and does not use `resolveSignatureOnly`, `reviewContextSignature`, or `resolvedContextSignature`.
- `template` and `template-part` are review-before-apply surfaces built through `TemplatePrompt` / `TemplatePartPrompt`.
- `global-styles` and `style-book` are review-before-apply surfaces built through `StylePrompt` plus validated `theme.json`-safe operations.
- Docs grounding is best-effort, cache-first, and must be analyzed separately from hard validation, parser rules, and deterministic execution safety.
- `block` has server `resolvedContextSignature` for apply freshness only; it does not have a server `reviewContextSignature`.
- `template`, `template-part`, `global-styles`, and `style-book` have docs-free server `reviewContextSignature` and docs-free server `resolvedContextSignature`.
- `resolveSignatureOnly` on supported surfaces should be treated as a docs-free freshness path that returns before docs lookup and model calls.
- Do not report “docs guidance churn makes results stale” as a bug unless current code actually couples docs guidance into freshness signatures or signature-only work.
- `flavor-agent/search-wordpress-docs` uses the same AI Search backend, but may not share every cache layer or warming path. Verify the exact behavior from code.

Code and contract precedence:
1. repo code
2. tests that lock current behavior
3. repo docs as expected-behavior claims to confirm or falsify

Do not:
- infer behavior from naming alone
- assume docs are fetched live on every request
- assume docs materially influence recommendations unless you can prove where they enter the final prompt or ranking input
- assume docs should participate in freshness unless current code proves that they do

Primary files to inspect

Shared grounding and backend:
- `inc/Support/CollectsDocsGuidance.php`
- `inc/Cloudflare/AISearchClient.php`
- `inc/Abilities/WordPressDocsAbilities.php`

Freshness contracts and shared runtime:
- `inc/Support/RecommendationResolvedSignature.php`
- `inc/Support/RecommendationReviewSignature.php`
- `src/store/executable-surface-runtime.js`
- `src/store/index.js`
- `src/utils/recommendation-request-signature.js`
- `src/utils/block-recommendation-context.js`
- `src/utils/style-operations.js`

Surface entry points and server-side recommendation flows:
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/NavigationAbilities.php` (optional/shared-pattern reference only)

Prompt / ranking builders that consume grounded docs:
- `inc/LLM/Prompt.php`
- `inc/LLM/TemplatePrompt.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/LLM/StylePrompt.php`

Server collectors and canonical context builders:
- `inc/Context/ServerCollector.php`
- `inc/Context/BlockContextCollector.php`
- `inc/Context/TemplateContextCollector.php`
- `inc/Context/TemplatePartContextCollector.php`
- `inc/Context/ThemeTokenCollector.php`
- `inc/Context/PatternOverrideAnalyzer.php`
- `inc/Context/ViewportVisibilityAnalyzer.php`

Client-side live scope builders:
- `src/context/collector.js`
- `src/patterns/PatternRecommender.js`
- `src/templates/TemplateRecommender.js`
- `src/templates/template-recommender-helpers.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/template-parts/template-part-recommender-helpers.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/style-book/dom.js`
- `src/utils/visible-patterns.js`
- `src/utils/live-structure-snapshots.js`
- `src/utils/editor-context-metadata.js`

Reference docs to verify against:
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/shared-internals.md`
- `docs/features/block-recommendations.md`
- `docs/features/pattern-recommendations.md`
- `docs/features/template-recommendations.md`
- `docs/features/template-part-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`
- `docs/2026-04-08-recommendation-context-pipeline-review.md`
- `context-retrieval-and-prompt-pipeline-prompt.md`

Useful contract tests:
- `tests/phpunit/DocsGroundingEntityCacheTest.php`
- `tests/phpunit/BlockAbilitiesTest.php`
- `tests/phpunit/TemplateAbilitiesTest.php`
- `tests/phpunit/StyleAbilitiesTest.php`
- `tests/phpunit/NavigationAbilitiesTest.php` (optional/shared-pattern reference only)

What to trace for each required surface

1. Client-side live scope construction
   - Which JS files build the request payload?
   - Which fields come from live editor state, DOM state, inserter state, or derived helpers?
   - Which fields are client-only metadata versus actually POSTed?

2. Server-side request normalization and merge
   - Which REST handler and ability receive the request?
   - Which fields are preserved, normalized, expanded, or dropped?
   - Which canonical server context is added?
   - Where does PHP overlay live editor slices onto canonical server context?

3. Docs grounding input construction
   - Where is `CollectsDocsGuidance::collect()` invoked?
   - What exact query string is built?
   - What entity key is built?
   - What family context is built?
   - Which query inputs are only bounded samples/counts/markers versus full live-scope fields?

4. Retrieval path and cache semantics
   - Verify the exact behavior of `AISearchClient::maybe_search_with_cache_fallbacks()`.
   - For each surface, determine whether docs are served from:
     - exact-query cache
     - family cache
     - entity cache
     - generic entity fallback
     - one-shot foreground warm
     - async context warm scheduling
   - State what is:
     - cache-only
     - blocking best-effort
     - non-blocking
   - Distinguish recommendation-time grounding from the operator-facing `flavor-agent/search-wordpress-docs` ability:
     - same backend?
     - same exact-query cache?
     - same entity cache?
     - same family cache / context warm path, or not?

5. Downstream consumption
   - Show exactly where docs guidance is injected into:
     - `Prompt::build_user()`
     - `PatternAbilities::build_ranking_input()`
     - `TemplatePrompt::build_user()`
     - `TemplatePartPrompt::build_user()`
     - `StylePrompt::build_user()`
   - Distinguish docs guidance from:
     - embedding query construction
     - structural should-clauses
     - pattern candidate filtering
     - hard validation
     - structural or path constraints
     - response parsing rules
     - operation/path validation

6. Freshness and drift protections
   - For each surface, separate:
     - client request/context signature
     - server review signature (if any)
     - server resolved/apply signature (if any)
   - Verify whether docs guidance is intentionally excluded from server freshness signatures.
   - Verify whether `resolveSignatureOnly` returns before docs lookup and model calls on supported surfaces.
   - For `pattern`, explicitly note the absence of server freshness signatures.
   - For `block`, explicitly note the absence of server `reviewContextSignature`.
   - It is valid to conclude that docs relevance can drift without freshness changing, if the current contract intentionally excludes docs from freshness.

Surface-specific questions you must answer

### Block

Audit whether docs grounding reflects the actual live block recommendation scope:
- `block.name`
- `block.title`
- `inspectorPanels`
- `editingMode`
- `structuralIdentity`
- `blockVisibility`
- `siblingsBefore` / `siblingsAfter`
- `structuralAncestors`
- `structuralBranch`
- `themeTokens`

Then compare those docs-grounding inputs with:
- what is actually sent into `Prompt::build_user()`
- what is later constrained by `Prompt::enforce_block_context_rules()`
- what participates in client freshness vs server `resolvedContextSignature`

### Pattern

Audit whether docs grounding is aligned with the inserter context actually used for retrieval and ranking:
- `postType`
- `templateType`
- `blockContext.blockName`
- `insertionContext.rootBlock`
- `insertionContext.ancestors`
- `insertionContext.nearbySiblings`
- `insertionContext.templatePartArea`
- `insertionContext.templatePartSlug`
- `insertionContext.containerLayout`
- `visiblePatternNames`

Then compare docs-grounding inputs against:
- embedding query construction
- structural should-clauses
- visible-pattern filtering
- candidate diversification / ranking hints
- ranking input assembly

Be explicit about whether docs guidance affects:
- candidate retrieval
- reranking only
- both
- or neither

### Template

Audit whether docs grounding follows the live Site Editor scope rather than only generic template guidance:
- `templateType`
- `assignedParts`
- `emptyAreas`
- `allowedAreas`
- `topLevelBlockTree`
- `topLevelInsertionAnchors`
- `structureStats`
- `currentPatternOverrides`
- `currentViewportVisibility`
- `visiblePatternNames`

Compare the docs query / family context with:
- the final `TemplatePrompt::build_user()` inputs
- server-selected `patterns`
- server `themeTokens`
- docs-free `reviewContextSignature` and `resolvedContextSignature`

Call out any live or server-added structure data that materially shapes recommendations but is absent or only coarsely represented in docs retrieval.

### Template-part

Audit whether docs grounding reflects executable, local template-part scope rather than only generic `core/template-part` guidance:
- `area`
- `slug`
- `topLevelBlocks`
- `blockCounts`
- `currentPatternOverrides`
- `operationTargets`
- `insertionAnchors`
- `structuralConstraints`
- `visiblePatternNames` if present directly, or if it only influences upstream server-selected `patterns`
- `allBlockPaths` where relevant to validation/freshness but not prompt text

Compare the docs query / family context with:
- the final `TemplatePartPrompt::build_user()` inputs
- server-selected `patterns`
- docs-free `reviewContextSignature` and `resolvedContextSignature`

### Global Styles / Style Book

Audit whether style docs grounding matches the actual styling scope:
- `scope.surface`
- `scope.templateType`
- `scope.templateSlug`
- `scope.blockName`
- `scope.blockTitle`
- `styleBookTarget`
- `supportedStylePaths`
- `availableVariations`
- `activeVariationTitle` / `activeVariationIndex`
- `templateStructure`
- `templateVisibility`
- `designSemantics`
- `themeTokens`
- `themeTokenDiagnostics`
- `blockManifest`

Compare docs-grounding inputs with:
- `StylePrompt::build_user()`
- `build_review_context_signature()` and `resolvedContextSignature`
- later style-operation parsing and validation

Distinguish:
- “docs help the model reason better”
from
- “hard rules prevent invalid operations”

Explicit weak points to verify from current code

Do not assume these are bugs; verify them:

- whether docs grounding is shared but not equally scope-rich across surfaces
- whether some live request-builder fields influence the final prompt or ranking input but never influence docs retrieval
- whether `pattern` docs grounding is rerank-only and does not influence embedding or Qdrant retrieval
- whether `template` docs grounding uses bounded `visiblePatternNames` while family cache context only keeps coarse scope markers
- whether `template-part` docs grounding captures override/executable context but underuses or omits `visiblePatternNames` even though server-selected `patterns` may be filtered by it
- whether style docs grounding underrepresents full `supportedStylePaths`, `blockManifest`, template structure, or design semantics in family cache context
- whether docs guidance is intentionally excluded from `reviewContextSignature` / `resolvedContextSignature`
- whether `resolveSignatureOnly` is truly cheap and docs-free on the supported surfaces
- whether `flavor-agent/search-wordpress-docs` shares the same backend and some caches as recommendation-time grounding, but bypasses family-context warming

Required output format

Start with a short architecture summary, then provide a per-surface matrix with these columns:

- Surface
- Client request builder(s)
- Live scope fields sent from JS
- Server-added canonical fields
- Docs query inputs
- Entity key
- Family context
- Cache / fallback path
- Final docs consumer
- Freshness contract (client / review / resolved)
- Do docs affect freshness?
- Important prompt / ranking inputs not reflected in docs retrieval
- Verdict on scope relevance
- Risk / severity

After the matrix, include:

1. Findings ordered by severity
   - Each finding must cite exact file paths and functions / symbols
   - Each finding must explain why recommendation quality, scope fidelity, or operator understanding could drift

2. Verified non-findings
   - Things that looked suspicious but are actually correct in the current implementation

3. Prioritized remediation list
   - Focus on improving docs relevance to the live recommendation scope
   - Prefer precise fixes to query/entity/family construction, request shaping, cache fallback usage, or downstream consumption clarity
   - Do not recommend “add more docs” unless you can show a specific retrieval or consumption gap

4. Final judgment
   - For each required surface, is docs grounding materially improving recommendations, or is it mostly a best-effort secondary aid layered on top of stronger structural constraints?
   - Also state whether any current docs-grounding gaps are quality gaps only, or freshness / safety gaps too.

Quality bar

This audit is only complete if it proves:
- where live scope comes from
- how docs retrieval is keyed
- how cache fallback and warming work
- where docs guidance is consumed
- where docs relevance diverges from the actual recommendation inputs
- and which freshness contracts intentionally exclude docs guidance in the current repo

It is valid to conclude that:
- docs grounding is helpful but secondary
- some surfaces intentionally keep docs out of freshness
- some divergences are deliberate and correct
as long as the conclusion is grounded in current code.
```
