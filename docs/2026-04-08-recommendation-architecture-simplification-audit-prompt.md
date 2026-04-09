# Recommendation Architecture Simplification Audit Prompt

Date: 2026-04-08

Purpose: Repo-specific audit prompt for assessing Flavor Agent's current recommendation architecture and brainstorming maintainability/efficiency improvements without regressing shipped behavior.

```text
Audit Flavor Agent’s recommendation architecture, assess the current design, and brainstorm concrete ways to make it more maintainable and efficient without regressing shipped behavior.

Do not assume this repo has one generic recommendation pipeline. Trace the actual implementations for:
- `block`
- `navigation`
- `pattern`
- `template`
- `template-part`
- `global-styles`
- `style-book`
- `content`, but only where its scaffolded pipeline shares infrastructure with the shipped surfaces

Goal:
Accurately describe the shipped architecture, identify where the repo can standardize or consolidate, identify where differences are intentional and should remain surface-specific, and rank improvement ideas by benefit, cost, and confidence.

Repo constraints:
- This is a WordPress plugin that should feel native to Gutenberg and wp-admin, not a separate AI app shell.
- First-party UI primarily uses JS recommenders + `src/store/index.js` + `inc/REST/Agent_Controller.php`.
- The Abilities API is a parallel public contract, not the main first-party UI runtime.
- Describe the current repo state, not a hypothetical target state or a stale pre-implementation snapshot.
- If the repo already ships an abstraction or flow, document it as current architecture first. Do not propose rebuilding it under a new name unless you can show a concrete remaining gap in the code.
- It is acceptable to conclude that an existing owner/module is already the right boundary.
- Current interaction models are intentionally different:
  - `block` = safe direct apply
  - `navigation` = advisory only
  - `pattern` = retrieval/rerank + browse only
  - `template`, `template-part`, `global-styles`, `style-book` = review-before-apply with validation and undo
- Preserve provider flexibility, pattern embeddings/Qdrant/reranking, docs grounding, server-backed activity, capability gating, freshness guards, and WordPress-native UI constraints.
- Pay special attention to the shipped freshness contract:
  - immediate stale UI comes from client request/context signatures
  - apply-time server revalidation comes from `resolvedContextSignature` plus `resolveSignatureOnly`
  - `block` participates in that server-resolved revalidation even though its executor stays distinct
- Valid outcomes include:
  - no meaningful architectural change is needed
  - docs-only or audit-accuracy corrections
  - a small targeted cleanup
  - several candidate refactors with tradeoffs
  - a phased implementation plan, but only if a non-trivial change is clearly justified

Primary implementation to inspect:
- `src/store/index.js`
- `src/context/collector.js`
- `src/patterns/PatternRecommender.js`
- `src/templates/TemplateRecommender.js`
- `src/templates/template-recommender-helpers.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/template-parts/template-part-recommender-helpers.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/inspector/BlockRecommendationsPanel.js`
- `src/inspector/NavigationRecommendations.js`
- `src/components/*`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/Context/*`
- `inc/Support/CollectsDocsGuidance.php`
- `inc/Support/RecommendationResolvedSignature.php`
- `inc/OpenAI/Provider.php`
- `inc/LLM/Prompt.php`
- `inc/LLM/TemplatePrompt.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/LLM/StylePrompt.php`

Reference docs to verify against:
- `docs/SOURCE_OF_TRUTH.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/reference/shared-internals.md`
- `docs/reference/recommendation-ui-consistency.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/provider-precedence.md`
- `docs/reference/pattern-recommendation-debugging.md`
- `STATUS.md`

Existing repo-local analysis to build on, not blindly trust:
- `docs/2026-04-08-recommendation-context-pipeline-review.md`
- `docs/CODE_DUPLICATION_AUDIT.md`

Freshness-specific checks you must verify in code before writing findings:
- `src/store/index.js::guardSurfaceApplyFreshness()`
- `src/store/index.js::guardSurfaceApplyResolvedFreshness()`
- `inc/REST/Agent_Controller.php` handling of `resolveSignatureOnly`
- `inc/Abilities/BlockAbilities.php::recommend_block()`
- `inc/Abilities/TemplateAbilities.php::recommend_template()`
- `inc/Abilities/TemplateAbilities.php::recommend_template_part()`
- `inc/Abilities/StyleAbilities.php::recommend_style()`
- tests covering signature-only responses and server-stale apply blocking in `tests/phpunit/*` and `src/store/__tests__/store-actions.test.js`

Audit instructions:
1. Map each surface end-to-end:
   UI/recommender -> store state -> REST route -> ability -> collector/normalizer -> provider/ranking/prompt -> parser/validator -> apply/undo/activity.

2. For each surface, document:
   - live client context
   - server-derived context
   - interaction model
   - safety/validation model
   - freshness model
   - shared infrastructure used

3. Separate intentional divergence from accidental duplication. Do not flatten surfaces just because they share nouns like “recommendation” or “prompt.”

4. Brainstorm and evaluate high-value improvement seams across:
   - JS request lifecycle and surface state in `src/store/index.js`
   - shared UI shell and recommendation vocabulary
   - context signatures and freshness handling
   - REST handler/meta plumbing
   - ability-layer normalization and overlay helpers
   - prompt parsing boilerplate
   - provider/request-metadata plumbing
   - activity/undo persistence and review plumbing

5. For each candidate improvement:
   - classify it as one of: docs-only correction, small cleanup, targeted extraction, or larger refactor
   - state expected benefit, implementation cost, confidence, and main regression risk
   - say explicitly if the benefit is speculative, weakly supported, or not justified by current maintenance evidence

6. Explicitly call out what should remain distinct, especially:
   - pattern retrieval/reranking
   - navigation’s advisory-only path
   - block’s direct-apply executor
   - template/template-part/style validation and review flows

7. If you propose a shared abstraction, name the exact module that should own it and which surface files should become thin adapters. If you do not propose one, say why the current owner should remain.

8. Prefer low-risk consolidation over a rewrite. If you recommend a big refactor, justify why docs-only corrections or smaller extractions are insufficient.

9. Use code as source of truth. Use docs only as expected-behavior claims to confirm or falsify.

10. When discussing freshness:
   - distinguish immediate client stale detection from apply-time server revalidation
   - explicitly say whether the repo already ships each piece
   - treat docs-guidance freshness as a separate contract question; do not assume docs cache changes are already part of `resolvedContextSignature`

11. Recommend the smallest justified next step. A phased plan is optional.
   - If the best recommendation is docs-only or a small targeted cleanup, say so directly and do not invent extra phases.
   - Only include a phased plan if the chosen recommendation is non-trivial and worth doing now.
   - If you include a phased plan, use 1-5 phases. For each phase include:
     - objective
     - exact file targets
     - abstractions to extract or boundaries to redraw
     - behavior that must remain unchanged
     - risks/regressions
     - tests/docs that must move with the change
     - why this phase should come before the next one

Output format:
- short architecture summary
- surface-by-surface comparison
- findings ordered by severity/opportunity
- docs-only or audit-accuracy corrections
- candidate improvements ranked by benefit/cost/confidence
- “keep distinct” candidates
- recommended next step
- optional phased plan
- validation checklist

Every finding should cite exact files and functions. Favor concrete, repo-specific recommendations over generic architecture advice. It is valid for the recommended next step to be “no refactor yet” if the code does not justify one.
```
