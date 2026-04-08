# Recommendation Freshness And Docs Grounding Complete Implementation Plan

> Scope: implement the complete fix for every verified finding in `docs/2026-04-08-recommendation-context-pipeline-review.md`, including the outstanding Style Book docs-cache contract failure in `tests/phpunit/DocsGroundingEntityCacheTest.php:439`.

> This document is an implementation brief for an LLM. Do not stop at analysis. Make the code changes, wire the UI and store, update tests and docs, and verify the result end to end.

## Goal

Make recommendation freshness mean "the stored result still matches the current resolved backend prompt input" for every review/apply surface, while preserving the current execution safety model and improving docs-grounding relevance where the review found missing inputs.

The end state must be:

1. `template`, `template-part`, `global-styles`, `style-book`, and `block` all store a server-computed `resolvedContextSignature` alongside the existing client `contextSignature`.
2. Apply actions revalidate the current server-resolved signature before mutating editor state whenever the client signature still matches.
3. `template` docs grounding uses bounded `visiblePatternNames` context.
4. `template-part` docs grounding uses bounded `currentPatternOverrides` context.
5. Style Book guidance reuses warm generic Style Book guidance when block-scoped entity cache is cold, instead of forcing a foreground remote search on that specific path.
6. Existing client-side apply validators remain intact and are not weakened.
7. Tests prove the full contract, not just partial server or partial client behavior.

## Findings In Scope

1. Review freshness does not cover all final prompt inputs for `template`, `template-part`, `global-styles`, and `style-book`.
2. `block` freshness is still client-only even though PHP injects server-collected docs guidance into the final prompt.
3. Template docs grounding ignores `visiblePatternNames`.
4. Template-part docs grounding ignores `currentPatternOverrides`.
5. `DocsGroundingEntityCacheTest::test_style_book_docs_guidance_falls_back_to_style_book_guidance_when_block_entity_cache_is_cold` is failing and the intended Style Book fallback contract is not explicit enough in code.

## Non-Goals

1. Do not redesign `pattern` into a review/apply surface.
2. Do not loosen prompt parsing, operation validation, or local apply-time safety checks.
3. Do not replace the existing client `contextSignature` fast path with a server round trip on every render.
4. Do not broaden docs family-cache keys with high-cardinality free text.

## Mandatory Implementation Decisions

These are not open questions. Implement them as written.

### 1. Use dual signatures on every review/apply surface

Keep the existing `contextSignature` as the client-computed live-state signature.

Add a new `resolvedContextSignature` that is computed on the server from the final prompt payload actually sent to the model layer.

Apply this to:

- `block`
- `template`
- `template-part`
- `global-styles`
- `style-book`

Do not add it to `pattern`.

### 2. Compute `resolvedContextSignature` from final prompt inputs, not from a second hand-maintained field list

Add a shared PHP helper, for example:

- `inc/Support/RecommendationResolvedSignature.php`

The helper should compute a deterministic hash from a normalized payload such as:

- `surface`
- `systemPrompt`
- `userPrompt`

Use `hash( 'sha256', ... )` over a stable `wp_json_encode()` payload.

Do not hash:

- timestamps
- request ids
- `requestMeta`
- token usage
- latency

Rationale: hashing the final prompt strings automatically captures server-selected `patterns`, full `themeTokens`, docs guidance, prompt-only `blockManifest` fields, and any future prompt-shaping additions without requiring a second manual field inventory.

### 3. Add `resolveSignatureOnly` mode on existing recommendation endpoints

Do not add new REST routes.

Extend the existing recommendation requests with an optional boolean flag:

- `resolveSignatureOnly`

When that flag is `true`, the server must:

1. perform the same context collection, live overlay, docs-guidance lookup, and prompt construction as a normal recommendation request
2. compute `resolvedContextSignature`
3. return a minimal payload containing that signature
4. skip `ChatClient::chat()` or `ResponsesClient::rank()`

This mode must exist for:

- `/flavor-agent/v1/recommend-block`
- `/flavor-agent/v1/recommend-template`
- `/flavor-agent/v1/recommend-template-part`
- `/flavor-agent/v1/recommend-style`

### 4. Keep the current client stale UI path, then add server revalidation before apply

Do not remove the current client-side stale comparison.

The correct order is:

1. compare current client request signature to stored client request signature
2. if that differs, treat the result as locally stale and stop
3. only if the client signatures still match, call `resolveSignatureOnly`
4. compare stored `resolvedContextSignature` to the current `resolvedContextSignature`
5. if that differs, treat the result as server-stale and stop
6. otherwise proceed with the existing apply path

### 5. Treat Style Book generic guidance fallback as authoritative enough to skip foreground warm on that specific path

Adopt the PHPUnit expectation as the runtime contract for this case:

- if the request surface is `style-book`
- and the block-scoped entity cache is cold
- and the generic `guidance:style-book` cache is warm

then return the generic Style Book guidance immediately, schedule any async warm if needed, and do not run the bounded foreground remote warm before returning.

Keep the existing foreground-warm behavior for other generic-or-empty fallback paths unless a test proves otherwise.

### 6. Keep docs family context coarse

Template and template-part docs changes must use:

- query text for detailed names
- family context for bounded counters and booleans only

Do not add raw visible-pattern lists or raw override block lists to the family cache key.

## Files In Scope

Primary server files:

- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/Registration.php`
- `inc/Support/CollectsDocsGuidance.php` only if small signature/revalidation plumbing makes it cleaner
- `inc/Support/RecommendationResolvedSignature.php` (new)
- `inc/Cloudflare/AISearchClient.php`

Primary client files:

- `src/store/index.js`
- `src/utils/recommendation-request-signature.js`
- `src/utils/block-recommendation-context.js`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/inspector/BlockRecommendationsPanel.js`

Primary docs to update:

- `docs/features/block-recommendations.md`
- `docs/features/template-recommendations.md`
- `docs/features/template-part-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/shared-internals.md`
- `docs/features/settings-backends-and-sync.md` if it mentions docs-grounding runtime semantics

Primary tests:

- `tests/phpunit/AgentControllerTest.php`
- `tests/phpunit/BlockAbilitiesTest.php`
- `tests/phpunit/TemplatePromptTest.php`
- `tests/phpunit/TemplatePartPromptTest.php`
- `tests/phpunit/StyleAbilitiesTest.php`
- `tests/phpunit/StylePromptTest.php`
- `tests/phpunit/DocsGroundingEntityCacheTest.php`
- `tests/phpunit/AISearchClientTest.php`
- `src/store/__tests__/store-actions.test.js`
- `src/templates/__tests__/TemplateRecommender.test.js`
- `src/template-parts/__tests__/TemplatePartRecommender.test.js`
- `src/global-styles/__tests__/GlobalStylesRecommender.test.js`
- `src/style-book/__tests__/StyleBookRecommender.test.js`
- `src/inspector/__tests__/BlockRecommendationsPanel.test.js`

## Workstream 1: Shared Server-Side Resolved Signature

### Objective

Create one deterministic mechanism for hashing the final model input across all review/apply surfaces.

### Steps

1. Add `inc/Support/RecommendationResolvedSignature.php`.
2. Give it a small public API, for example:
   - `from_prompt( string $surface, string $system_prompt, string $user_prompt ): string`
3. Normalize the hash input with stable JSON before hashing.
4. Keep the helper surface-agnostic.
5. Do not duplicate surface-specific normalization logic inside the helper. Prompt builders already encode the surface contract.

### Exit Criteria

1. Any surface can compute a `resolvedContextSignature` with one helper call.
2. The same prompt inputs produce the same signature in repeated runs.

## Workstream 2: Server Integration For All Review/Apply Surfaces

### Objective

Return `resolvedContextSignature` on normal recommendation responses and support `resolveSignatureOnly` on the same endpoints.

### Steps

1. Extend REST schemas in `inc/REST/Agent_Controller.php` and ability registration in `inc/Abilities/Registration.php` to allow `resolveSignatureOnly`.
2. `block`:
   - in `BlockAbilities::recommend_block()`, build docs guidance and prompt exactly once
   - compute `resolvedContextSignature`
   - if `resolveSignatureOnly` is true, return only the signature payload
   - otherwise attach `resolvedContextSignature` to the normal response payload after prompt parsing and rule enforcement
3. `template`:
   - in `TemplateAbilities::recommend_template()`, compute the signature after live overlay, docs guidance collection, and `TemplatePrompt::build_system()` / `build_user()`
   - return the signature in both full and signature-only modes
4. `template-part`:
   - same pattern as template, using `TemplatePartPrompt`
5. `global-styles` and `style-book`:
   - compute the signature from `StylePrompt::build_system()` and `build_user()`
   - return it in both full and signature-only modes
6. Keep `pattern` unchanged.

### Exit Criteria

1. Every review/apply endpoint returns `resolvedContextSignature` on success.
2. Every review/apply endpoint supports a minimal `resolveSignatureOnly` request.
3. No endpoint calls the model layer when `resolveSignatureOnly` is true.

## Workstream 3: Client And Store Revalidation

### Objective

Use the new server signature at the right moment without slowing down normal browsing.

### Steps

1. Keep the existing `contextSignature` request-builder behavior unchanged.
2. Extend store state to keep both:
   - client `contextSignature`
   - server `resolvedContextSignature`
3. Add selectors for `resolvedContextSignature` on:
   - block
   - template
   - template-part
   - global-styles
   - style-book
4. Update fetch-success handlers in `src/store/index.js` to persist the returned server signature.
5. Add a shared async helper in `src/store/index.js` for server revalidation before apply.
   It should:
   - accept endpoint, live request input, stored resolved signature, and stale message callbacks
   - POST `resolveSignatureOnly: true`
   - compare current vs stored server signatures
6. Update apply thunks for:
   - `applySuggestion` (`block`)
   - `applyTemplateSuggestion`
   - `applyTemplatePartSuggestion`
   - `applyGlobalStylesSuggestion`
   - `applyStyleBookSuggestion`

   New flow:

   1. existing client-signature freshness guard
   2. server `resolveSignatureOnly` revalidation if client signatures match
   3. existing apply logic if both checks pass

7. Modify the UI entry points so apply thunks receive the current live request input, not only the current request signature.
   This requires plumbing the latest live request payload from:
   - `BlockRecommendationsPanel`
   - `TemplateRecommender`
   - `TemplatePartRecommender`
   - `GlobalStylesRecommender`
   - `StyleBookRecommender`

8. Add a stale reason concept in store/UI.
   Use a narrow enum such as:
   - `client`
   - `server`
   - `null`

9. Update stale/apply error copy so server drift is distinguishable from local editor drift.

### Exit Criteria

1. Local stale results still short-circuit without a server round trip.
2. Server-only drift is caught before apply on all review/apply surfaces.
3. The UI can distinguish client drift from server drift when an apply is blocked.

## Workstream 4: Template And Template-Part Docs Grounding Inputs

### Objective

Fix the two low-severity docs-grounding relevance gaps without exploding cache cardinality.

### Steps

1. Template query shaping in `TemplateAbilities::build_wordpress_docs_query()`:
   - read `visiblePatternNames`
   - add a bounded summary to the query text:
     - visible pattern count
     - first 3 pattern names only
2. Template family context in `TemplateAbilities::build_wordpress_docs_family_context()`:
   - add `hasVisiblePatternScope`
   - add `visiblePatternCount`
   - do not add raw names
3. Template-part query shaping in `TemplateAbilities::build_template_part_wordpress_docs_query()`:
   - read `currentPatternOverrides`
   - add a bounded summary to the query text:
     - override block count
     - first 3 override block names only
4. Template-part family context in `TemplateAbilities::build_template_part_wordpress_docs_family_context()`:
   - add `hasPatternOverrides`
   - add `patternOverrideCount`
   - do not add raw names or full path data
5. Keep sanitization and ordering stable.

### Exit Criteria

1. Template docs grounding reflects visible pattern scope in query text and bounded family context.
2. Template-part docs grounding reflects override scope in query text and bounded family context.
3. Family cache keys remain coarse.

## Workstream 5: Style Book Docs Fallback Contract

### Objective

Make the failing test pass by choosing and implementing one explicit runtime contract.

### Required Contract

For Style Book only:

- block-specific entity cache is preferred when warm
- family cache is still preferred over entity fallback when warm
- if block-specific entity cache is cold but generic `guidance:style-book` cache is warm, return the generic guidance immediately
- do not do a foreground remote warm before returning in that specific case
- async warm scheduling may still happen after returning

### Steps

1. Update `AISearchClient::maybe_search_with_cache_fallbacks()` to detect this path.
2. Keep the generic fallback lookup itself unchanged if possible; only alter the decision to foreground-warm before return.
3. Do not broaden the exception beyond Style Book unless a test requires it.
4. Update comments and any relevant runtime docs so the exception is explicit.

### Exit Criteria

1. `DocsGroundingEntityCacheTest::test_style_book_docs_guidance_falls_back_to_style_book_guidance_when_block_entity_cache_is_cold` passes.
2. The code clearly documents why this path does not foreground-warm before returning.

## Workstream 6: Regression Coverage

### Objective

Prove the complete contract, not just the happy path.

### Required Tests

1. Server signature tests:
   - block signature changes when docs guidance changes while live block context stays the same
   - template signature changes when server `patterns` or docs guidance change
   - template-part signature changes when server `patterns`, full theme tokens, or docs guidance change
   - global-styles signature changes when prompt-only server inputs change beyond the client execution contract
   - style-book signature changes when prompt-only `blockManifest` or docs guidance change

2. Store/apply tests:
   - apply proceeds when client and server signatures both match
   - apply is blocked on client drift without calling the server
   - apply is blocked on server drift after a successful `resolveSignatureOnly` call
   - stale reason is recorded correctly

3. Docs-grounding tests:
   - template docs query/family context include bounded visible-pattern scope
   - template-part docs query/family context include bounded override scope
   - family cache keys remain low-cardinality

4. Style Book fallback tests:
   - generic Style Book cache is returned without a remote request when block entity cache is cold
   - existing non-Style-Book foreground warm behavior remains intentional unless a test proves a different contract

5. UI tests:
   - stale messaging distinguishes local drift from server drift where surfaced

### Exit Criteria

1. New behavior is covered in both PHP and JS.
2. The existing apply-safety checks still pass.

## Workstream 7: Documentation

### Objective

Document the shipped contract accurately after the code lands.

### Steps

1. Update feature docs to describe:
   - dual freshness model
   - client stale detection
   - server revalidation before apply
2. Update internal docs/reference docs to describe:
   - `resolvedContextSignature`
   - `resolveSignatureOnly`
   - which surfaces use it
3. Update any docs-grounding runtime wording that would be inaccurate after the Style Book exception is implemented.

### Exit Criteria

1. Feature docs match runtime behavior.
2. Internal docs explain the dual-signature model clearly enough for future changes.

## Verification Plan

Run these at minimum:

1. `npm run test:unit -- --runInBand src/store/__tests__/store-actions.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/global-styles/__tests__/GlobalStylesRecommender.test.js src/style-book/__tests__/StyleBookRecommender.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js`
2. `vendor/bin/phpunit --filter 'BlockAbilitiesTest|TemplatePromptTest|TemplatePartPromptTest|StyleAbilitiesTest|StylePromptTest|AgentControllerTest|DocsGroundingEntityCacheTest|AISearchClientTest'`
3. any docs freshness check already used in this repo if touched by the wording changes

Do not stop after partial green tests. Fix the full targeted set.

## Guardrails

1. Do not weaken `src/utils/template-actions.js` or `src/utils/style-operations.js` validation just to make stale handling pass.
2. Do not add a new REST route when `resolveSignatureOnly` can live on the existing endpoints.
3. Do not hash unstable transport metadata.
4. Do not expand docs family context with raw pattern lists, raw override block arrays, or free-form prompt text.
5. Do not change `pattern` surface behavior.

## LLM Execution Instructions

1. Implement Workstreams 1 through 7 in one cohesive change set.
2. Prefer one shared helper for server-resolved signatures rather than surface-specific ad hoc hashes.
3. Wire both the server and the client. A server-only signature field is not a complete fix.
4. Make `block` participate in server-side freshness revalidation. Do not leave Finding 2 as "documented but unfixed."
5. Treat the Style Book cache failure as a code contract issue, not as a test to delete.
6. Update docs in the same change set so the runtime contract and written contract stay aligned.
7. Finish by running the verification plan and only stop when the targeted tests pass or you can name a concrete blocker in code.
