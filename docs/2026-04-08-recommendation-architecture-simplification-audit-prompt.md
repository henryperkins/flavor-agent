# Recommendation Architecture Simplification Audit Prompt

Date: 2026-04-08

Purpose: Repo-specific audit prompt for simplifying Flavor Agent's recommendation architecture without regressing shipped behavior.

```text
Audit Flavor Agent’s recommendation architecture and produce a phased plan to simplify it without regressing shipped behavior.

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
Identify where the repo can standardize or consolidate architecture, and where differences are intentional and should remain surface-specific.

Repo constraints:
- This is a WordPress plugin that should feel native to Gutenberg and wp-admin, not a separate AI app shell.
- First-party UI primarily uses JS recommenders + `src/store/index.js` + `inc/REST/Agent_Controller.php`.
- The Abilities API is a parallel public contract, not the main first-party UI runtime.
- Current interaction models are intentionally different:
  - `block` = safe direct apply
  - `navigation` = advisory only
  - `pattern` = retrieval/rerank + browse only
  - `template`, `template-part`, `global-styles`, `style-book` = review-before-apply with validation and undo
- Preserve provider flexibility, pattern embeddings/Qdrant/reranking, docs grounding, server-backed activity, capability gating, freshness guards, and WordPress-native UI constraints.

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

4. Identify high-value simplification seams across:
   - JS request lifecycle and surface state in `src/store/index.js`
   - shared UI shell and recommendation vocabulary
   - context signatures and freshness handling
   - REST handler/meta plumbing
   - ability-layer normalization and overlay helpers
   - prompt parsing boilerplate
   - provider/request-metadata plumbing
   - activity/undo persistence and review plumbing

5. Explicitly call out what should remain distinct, especially:
   - pattern retrieval/reranking
   - navigation’s advisory-only path
   - block’s direct-apply executor
   - template/template-part/style validation and review flows

6. If you propose a shared abstraction, name the exact module that should own it and which surface files should become thin adapters.

7. Prefer low-risk consolidation over a rewrite. If you recommend a big refactor, justify why smaller extractions are insufficient.

8. Use code as source of truth. Use docs only as expected-behavior claims to confirm or falsify.

9. Produce a 3-5 phase plan. For each phase include:
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
- “simplify now” candidates
- “keep distinct” candidates
- recommended phased plan
- validation checklist

Every finding should cite exact files and functions. Favor concrete, repo-specific recommendations over generic architecture advice.
```
