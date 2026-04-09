# Docs Grounding And Freshness Decoupling Plan

> Scope: implement the product and architecture decision that WordPress docs grounding should remain part of normal recommendation generation, but docs retrieval churn should not by itself mark stored results stale or block deterministic apply.

> This plan is repo-specific. It assumes the current `resolvedContextSignature` contract is already the apply-freshness spine for executable surfaces, and it corrects the newer direction that folds docs guidance into `reviewContextSignature` on template, template-part, style, and navigation flows.

## Goal

Keep docs grounding where it helps model quality, and remove it from the signatures that decide whether a stored result is still safe or still meaningfully aligned with current server state.

The end state must be:

1. Normal recommendation requests still collect docs guidance for surfaces that already use it.
2. `resolvedContextSignature` continues to mean "server-normalized apply context plus sanitized prompt," not "final grounded prompt text."
3. Review-freshness signatures for executable surfaces also stop depending on docs guidance.
4. `resolveSignatureOnly` returns signatures without doing docs lookup or model calls.
5. Pattern and content remain outside this freshness contract.
6. Navigation follows the same docs-decoupled freshness rule by default, even though it is advisory-only.

## Why This Plan Exists

The repo now has two separate concerns that were starting to blur together:

1. Recommendation quality.
   Docs grounding improves the prompt sent to `ResponsesClient::rank()` or `ChatClient::chat()`.

2. Staleness and apply safety.
   Freshness signatures decide whether the current editor/server context still matches the stored result closely enough to review or apply it.

Those are not the same contract.

If docs excerpts, cache fallbacks, or result ordering are hashed into freshness signatures, the UI can show a stale result even when the site itself did not change. That creates false stale states for template, template-part, Global Styles, Style Book, and potentially navigation.

## Current Repo State To Correct

The current code already has the right idea for `resolvedContextSignature`, but review freshness is drifting back toward docs-sensitive behavior.

### Already aligned

- `inc/Support/RecommendationResolvedSignature.php` hashes a structured payload, not final prompt text.
- `inc/Abilities/BlockAbilities.php` computes `resolvedContextSignature` before docs/model work and can return it through `resolveSignatureOnly`.
- `src/store/index.js` and `src/store/executable-surface-runtime.js` already separate client request-signature drift from server signature revalidation.

### Currently misaligned with this plan

- `inc/Abilities/TemplateAbilities.php::build_template_review_context_signature()` includes normalized `docsGuidance`.
- `inc/Abilities/TemplateAbilities.php::build_template_part_review_context_signature()` includes normalized `docsGuidance`.
- `inc/Abilities/StyleAbilities.php::build_review_context_signature()` includes normalized `docsGuidance`.
- `inc/Abilities/NavigationAbilities.php::build_review_context_signature()` includes normalized `docsGuidance`.
- Because of that, `recommend_template()`, `recommend_template_part()`, `recommend_style()`, and `recommend_navigation()` still do docs work before returning from `resolveSignatureOnly`.

That is the behavior this plan should reverse.

## Surface Decisions

| Surface | Keep docs in normal recommendation? | Docs affect freshness? | Recommended signature contract |
| --- | --- | --- | --- |
| `block` | Yes | No | Keep current docs-free `resolvedContextSignature` |
| `template` | Yes | No | `reviewContextSignature` and `resolvedContextSignature` both derived from docs-free merged server context |
| `template-part` | Yes | No | Same as template |
| `global-styles` | Yes | No | `reviewContextSignature` and `resolvedContextSignature` both derived from docs-free style context |
| `style-book` | Yes | No | Same as global-styles |
| `navigation` | Yes | No by default | `reviewContextSignature` derived from docs-free server navigation context |
| `pattern` | Yes | No freshness contract | No signature work added |
| `content` | No current docs grounding | No freshness contract | No change |

## Mandatory Decisions

These are implementation decisions, not open questions.

### 1. Do not remove docs grounding from normal recommendation requests

For `block`, `template`, `template-part`, `global-styles`, `style-book`, `navigation`, and `pattern`, keep docs collection exactly where it improves the recommendation payload.

This plan changes freshness behavior, not prompt-grounding behavior.

### 2. Keep `resolvedContextSignature` semantics narrow and stable

`resolvedContextSignature` should continue to hash only:

- surface id
- server-normalized executable/apply context
- sanitized prompt

It must not hash:

- docs excerpts
- docs result ids or ordering
- prompt strings assembled from grounded docs
- request ids, timestamps, request meta, or model output

### 3. Stop treating docs guidance as review freshness on executable surfaces

For `template`, `template-part`, `global-styles`, and `style-book`, `reviewContextSignature` should no longer include docs guidance.

Recommended default:

- keep the separate `reviewContextSignature` field for now to minimize store/UI churn
- compute it from the same docs-free server context family as `resolvedContextSignature`
- allow its payload to stay review-oriented if needed, but do not make docs part of it

### 4. Apply the same rule to navigation by default

Even though navigation is advisory-only, docs churn is still not the same as site-state drift.

Recommended default:

- keep `reviewContextSignature` for navigation
- exclude docs guidance from that hash
- make `resolveSignatureOnly` skip docs work there too

If product later wants docs-aware advisory invalidation, add a separate non-blocking `guidanceContextSignature` or equivalent. Do not overload stale/apply freshness with that concern.

### 5. `resolveSignatureOnly` must stay cheap

For every surface that supports `resolveSignatureOnly`, the signature-only path must:

1. normalize input
2. collect the same server-owned context needed for the signature
3. compute the signature payload(s)
4. return the minimal response
5. skip docs lookup
6. skip prompt building if not needed
7. skip model calls

### 6. Do not add freshness work to `pattern` or `content`

- `pattern` remains ranking/browse only.
- `content` remains advisory/editorial with request-token-only state.

## Workstream 1: Lock The Contract In Docs And Code Comments

### Objective

Make the contract explicit before more runtime changes land in the wrong direction.

### Steps

1. Update internal docs so they say:
   - docs grounding happens on full recommendation requests
   - freshness signatures intentionally exclude docs guidance
   - `resolveSignatureOnly` is a server-freshness path, not a grounded-prompt path
2. Update comments around signature helpers and review-freshness thunks to reinforce the same rule.
3. Treat the current docs-coupled review signature code as temporary, not as the intended architecture.

### Files

- `docs/reference/abilities-and-routes.md`
- `docs/reference/shared-internals.md`
- `docs/features/navigation-recommendations.md`
- `docs/features/template-recommendations.md`
- `docs/features/template-part-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`

## Workstream 2: Decouple Review Signatures From Docs Guidance

### Objective

Ensure background review revalidation reflects real server context drift, not docs-cache churn.

### Steps

1. `TemplateAbilities`
   - remove `docsGuidance` from `build_template_review_context_signature()`
   - remove `docsGuidance` from `build_template_part_review_context_signature()`
   - keep patterns, theme-token summaries, and other review-relevant server context that actually reflects site/editor state

2. `StyleAbilities`
   - remove `docsGuidance` from `build_review_context_signature()`
   - keep supported style paths, theme tokens, block manifest details, variation state, and other real server/style context

3. `NavigationAbilities`
   - remove `docsGuidance` from `build_review_context_signature()`
   - keep menu structure, target inventory, overlay parts, location details, theme-token constraints, and other server-owned navigation context

4. Remove helper constants and normalizers that only exist to hash docs guidance into review signatures if they are no longer needed.

### Recommended Implementation Detail

Do not rebuild review signatures from prompt text.

Keep using structured payload hashing through `RecommendationReviewSignature::from_payload()`, but make the payload docs-free.

### Files

- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/Support/RecommendationReviewSignature.php` only if small helper cleanup is useful

## Workstream 3: Make Signature-Only Requests Skip Docs Work Everywhere

### Objective

Make `resolveSignatureOnly` cheap and semantically consistent.

### Steps

1. `TemplateAbilities::recommend_template()`
   - compute docs-free review/apply signatures immediately after context merge
   - return early for `resolveSignatureOnly`
   - only then collect docs and build/rank prompts

2. `TemplateAbilities::recommend_template_part()`
   - follow the same sequence

3. `StyleAbilities::recommend_style()`
   - compute docs-free review/apply signatures immediately after `build_context_for_surface()`
   - return early for `resolveSignatureOnly`
   - only then collect docs and call `StylePrompt`

4. `NavigationAbilities::recommend_navigation()`
   - compute the docs-free review signature immediately after server context collection
   - return early for `resolveSignatureOnly`
   - only then collect docs and rank

5. `BlockAbilities::recommend_block()`
   - verify the current cheap behavior still holds and do not regress it

### Files

- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/REST/Agent_Controller.php` only if comments or route notes need cleanup

## Workstream 4: Keep Store/UI Semantics Coherent

### Objective

Make the user-facing stale contract match the new docs-free freshness semantics.

### Steps

1. Keep the existing client request-signature stale check first.
2. Keep server review freshness as the background check for surfaces that already use it.
3. Keep server apply freshness as the apply-time guard.
4. Update stale copy where needed so it talks about server context drift, not docs or guidance drift.
5. If any current UI copy explicitly says guidance changes can make a result stale, remove that claim for executable surfaces and for navigation if this plan's default is used.

### Recommended Default

Do not introduce a new store field in this pass unless a separate guidance-only signal becomes a product requirement.

The store can keep:

- `reviewContextSignature`
- `resolvedContextSignature`
- `server-review` stale reason
- `server-apply` stale reason

Only the meaning of the review signature changes.

### Files

- `src/store/executable-surface-runtime.js`
- `src/store/index.js`
- `src/utils/recommendation-stale-reasons.js`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/inspector/NavigationRecommendations.js`

## Workstream 5: Add Regression Coverage For The Real Contract

### Objective

Prove that docs churn no longer changes freshness, while real server context drift still does.

### PHPUnit Coverage

1. `tests/phpunit/TemplateAbilitiesTest.php`
   - template review signature does not change when only docs guidance changes
   - template-part review signature does not change when only docs guidance changes
   - signature-only responses skip remote/model work

2. `tests/phpunit/StyleAbilitiesTest.php`
   - review signature does not change when only docs guidance changes
   - resolved signature still changes when supported paths, block manifest, variation state, or theme tokens change appropriately
   - signature-only responses skip remote/model work

3. `tests/phpunit/NavigationAbilitiesTest.php`
   - review signature does not change when only docs guidance changes
   - review signature does change when saved menu structure, target inventory, overlay parts, or theme-token constraints change
   - signature-only path skips remote/model work

4. `tests/phpunit/AgentControllerTest.php`
   - signature-only navigation/template/style responses remain minimal
   - no signature-only path triggers model calls or docs remote fetches

### JS Coverage

1. `src/store/__tests__/store-actions.test.js`
   - review revalidation still posts `resolveSignatureOnly: true`
   - changed docs payload alone is no longer represented as stale in test fixtures

2. Surface UI tests
   - stale copy continues to appear for real server-review drift
   - stale copy no longer claims docs/guidance shifts as the cause unless product explicitly wants that for navigation later

## Verification Commands

Run the smallest focused set first, then the broader suite if the touched areas pass.

```bash
vendor/bin/phpunit --filter "(TemplateAbilitiesTest|StyleAbilitiesTest|NavigationAbilitiesTest|AgentControllerTest|RegistrationTest)"
npm run test:unit -- --runInBand src/store/__tests__/store-actions.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/global-styles/__tests__/GlobalStylesRecommender.test.js src/style-book/__tests__/StyleBookRecommender.test.js src/inspector/__tests__/NavigationRecommendations.test.js
npm run check:docs
```

## Non-Goals

1. Do not remove docs grounding from the real recommendation prompts.
2. Do not redesign pattern recommendations into a review/apply surface.
3. Do not add new freshness contracts to content recommendations.
4. Do not introduce a second apply-freshness field name.
5. Do not treat docs-cache churn as a hidden proxy for model-quality drift.

## Risks And Tradeoffs

1. A docs retrieval improvement will no longer automatically stale an old result.
   - This is intentional.
   - The benefit is fewer false stale states when the site itself did not change.

2. Advisory navigation may keep showing an older result even if docs grounding would now produce slightly different wording.
   - Recommended acceptance: that is better than background docs churn making the panel look unstable.
   - Follow-up option: separate non-blocking guidance fingerprint if product really wants that signal.

3. Review and apply signatures may converge conceptually on the executable surfaces.
   - That is acceptable.
   - Keep both wire fields for now if removing one would create avoidable JS churn.

## Recommended Rollout Order

1. Update the plan/docs language so the intended contract is explicit.
2. Fix server review signatures and signature-only short-circuits.
3. Update stale copy and any UI wording that still blames docs/guidance drift.
4. Land PHPUnit and JS regression coverage.
5. Revisit whether `reviewContextSignature` and `resolvedContextSignature` should stay separate after the behavior is stable.
