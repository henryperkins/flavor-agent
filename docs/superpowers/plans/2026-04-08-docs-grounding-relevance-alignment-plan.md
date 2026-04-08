# Docs Grounding Relevance And Runtime Contract Alignment Plan

> Scope: improve how trusted WordPress developer docs are selected for recommendation prompts, keep the selection aligned to the current recommendation surface and scope, and reconcile the shipped runtime behavior with the live documentation.

## Goal

Make docs grounding more relevant to the active recommendation surface without weakening current safety, prompt validation, or cache behavior.

The end state should be:

1. Pattern recommendation docs grounding reflects real insertion context, not only post type, template type, and near-block hints.
2. Style recommendation docs grounding reflects surface scope more precisely, especially template type and normalized design-semantic role.
3. The runtime contract for docs grounding is documented accurately.
4. Cache keys stay stable and low-cardinality enough that we do not destroy cache hit rates while improving relevance.
5. Regression tests prove both the new relevance inputs and the intentional runtime fallback behavior.

## Implementation Direction

### 1. Keep the bounded foreground warm path

Do not remove the existing one-shot foreground warm path from `AISearchClient::maybe_search_with_cache_fallbacks()`.

Reasons:

- It is already implemented and tested.
- It improves cold-start relevance when exact, family, and entity caches miss and only a broad generic fallback is available.
- Admin/runtime diagnostics already surface foreground-warm state, so removing it would require a larger contract reset than the user asked for.

The contract change should therefore be documentation-first:

- recommendation-time docs grounding is cache-first,
- exact-query and family/entity caches remain the primary path,
- a bounded foreground warm may run on cold generic or empty fallback paths before the async warm queue is used.

### 2. Prefer low-cardinality family context and richer query text

Use the search query for higher-detail relevance hints, but keep family cache keys constrained to stable, repeatable values.

That means:

- add more scope detail to the query text than to the family context,
- only include normalized enumerations in family context,
- avoid high-churn or free-text fields in family cache keys.

## Findings Addressed

1. Pattern docs grounding currently ignores insertion context even though the pattern pipeline already knows:
   - `rootBlock`
   - `ancestors`
   - `nearbySiblings`
   - `templatePartArea`
   - `templatePartSlug`
   - `containerLayout`

2. Style docs grounding currently does not use template type or design-semantic role when selecting docs, even though that information exists in the style surface context.

3. Live docs describe runtime grounding as cache-only and non-blocking, but the shipped code can do a bounded foreground warm on generic or empty fallback paths.

4. Existing tests cover cache precedence and foreground warm behavior, but they do not yet prove the new surface-relevance inputs or guard against family-cache key explosion.

## Workstream 1: Expand Pattern Docs Grounding Relevance

### Objective

Make pattern docs grounding reflect the same structural scope that the pattern recommendation surface already understands.

### Steps

1. Extend the docs-grounding input built inside `PatternAbilities::recommend_patterns()`.
   Pass the insertion-context fields that already exist in the request flow into the docs query/family builders.

2. Expand `PatternAbilities::build_wordpress_docs_query()`.
   Add scope cues such as:
   - `rootBlock`
   - `templatePartArea`
   - `templatePartSlug`
   - `containerLayout`
   - a capped summary of ancestors or nearby siblings only when they are materially helpful

3. Expand `PatternAbilities::build_wordpress_docs_family_context()`.
   Keep the family context low-cardinality. Start with:
   - `surface`
   - `entityKey`
   - `postType`
   - `templateType`
   - `nearBlock`
   - `rootBlock`
   - `templatePartArea`
   - `containerLayout`

4. Do not add full ancestor lists, nearby sibling lists, or free-form prompt text to the family cache key.
   Those should stay query-only because they are too volatile.

5. Keep pattern retrieval architecture unchanged.
   Docs guidance should continue to influence the LLM reranking input, not Qdrant candidate recall, in this change set.

### Exit Criteria

1. Pattern docs search queries mention insertion context when present.
2. Family cache keys differ for materially different pattern surfaces such as:
   - template-part header vs footer
   - simple container vs complex container layout
   - different root block contexts
3. Pattern docs grounding still falls back safely when the richer keys are cold.

## Workstream 2: Expand Style Docs Grounding Relevance

### Objective

Make Global Styles and Style Book grounding better match the active styling surface and its design role.

### Steps

1. Extend `StyleAbilities::build_wordpress_docs_query()`.
   Add:
   - `templateType` when available
   - normalized design-semantic role hints when confidence is strong enough
   - conditional visibility hints only in query text when they materially shape the recommendation

2. Extend `StyleAbilities::build_wordpress_docs_family_context()`.
   Keep the family key constrained to stable values. Start with:
   - `surface`
   - `entityKey`
   - `templateType`
   - `supportedPathFamilies`
   - `blockName` for Style Book
   - one normalized semantic role token only when it can be reduced to a small allowlist and the signal is high-confidence

3. Define a normalization helper for design semantics before they touch family cache context.
   The helper should:
   - discard low-confidence/noisy role mixtures,
   - collapse equivalent roles to a shared token,
   - return an empty value when the signal is too ambiguous.

4. Keep richer design-semantic detail in the prompt body.
   Do not try to encode every design-semantics field into the family cache key.

### Exit Criteria

1. Global Styles docs selection can vary by template type where that scope exists.
2. Style Book docs selection can vary by normalized role when the role signal is strong.
3. Ambiguous or low-confidence design semantics do not fragment family cache keys.

## Workstream 3: Align Runtime Contract And Documentation

### Objective

Make the docs accurately describe the shipped runtime behavior.

### Steps

1. Update user-facing and internal docs that currently claim recommendation-time grounding is strictly cache-only and non-blocking.

2. Replace that language with an accurate contract:
   - cache-first on the hot path,
   - exact-query cache first,
   - family cache next,
   - entity or generic fallback after that,
   - bounded foreground warm may run when only generic or empty fallback guidance is available,
   - async queue still handles later cache seeding and retries.

3. Update any "source of truth" docs and debugging runbooks that describe stale behavior.

4. Review freshness-check scripts and tests for hard-coded outdated wording.

### Docs To Update

- `docs/features/pattern-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`
- `docs/features/settings-backends-and-sync.md`
- `docs/reference/pattern-recommendation-debugging.md`
- `docs/SOURCE_OF_TRUTH.md`
- `scripts/check-doc-freshness.sh` if wording assertions depend on the old contract

### Exit Criteria

1. No live docs claim recommendation-time grounding is strictly cache-only if foreground warm remains shipped.
2. Documentation consistently describes cache layers and the bounded foreground-warm exception.

## Workstream 4: Regression Coverage

### Objective

Prove the new scope inputs matter and prevent accidental cache-cardinality regressions.

### Steps

1. Extend `DocsGroundingEntityCacheTest` for pattern grounding.
   Add tests proving:
   - exact-query cache still wins over family and entity caches,
   - family cache keys differ when `templatePartArea`, `rootBlock`, or `containerLayout` differ,
   - entity fallback still works when family keys are cold.

2. Extend `DocsGroundingEntityCacheTest` for style grounding.
   Add tests proving:
   - `templateType` can shape family cache selection,
   - normalized semantic role can shape family cache selection,
   - low-confidence semantic context does not create a new family key.

3. Keep `AISearchClientTest` coverage for foreground warm and generic fallback behavior.
   Add or adjust tests only where the documented contract wording or fallback metadata changes.

4. Add direct tests for any new normalization helper used by style semantic family keys.

5. If documentation wording is checked in tests or scripts, update those expectations in the same change set.

### Exit Criteria

1. Tests prove the new pattern and style relevance inputs affect cache selection.
2. Tests prove ambiguous style semantics do not explode cache cardinality.
3. Existing foreground-warm tests still pass and remain intentional.

## Files In Scope

Primary implementation files:

- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Cloudflare/AISearchClient.php` only if helper comments, metadata labels, or fallback bookkeeping need small alignment changes

Primary tests:

- `tests/phpunit/DocsGroundingEntityCacheTest.php`
- `tests/phpunit/AISearchClientTest.php`
- `tests/phpunit/SettingsTest.php` if settings/runtime copy changes surface in diagnostics output

Documentation and doc-freshness files:

- `docs/features/pattern-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`
- `docs/features/settings-backends-and-sync.md`
- `docs/reference/pattern-recommendation-debugging.md`
- `docs/SOURCE_OF_TRUTH.md`
- `scripts/check-doc-freshness.sh`

## Verification Plan

1. Run focused PHPUnit coverage for docs grounding:
   - `tests/phpunit/DocsGroundingEntityCacheTest.php`
   - `tests/phpunit/AISearchClientTest.php`
   - `tests/phpunit/SettingsTest.php` if touched

2. Run the broader PHPUnit suite if the helper or cache behavior changes beyond those tests.

3. Run docs freshness checks:
   - `npm run check:docs` if available in package scripts
   - or `scripts/check-doc-freshness.sh`

4. Spot-check the settings diagnostics output to confirm runtime wording still matches the actual fallback modes.

## Risks And Guardrails

1. Risk: family cache cardinality balloons and cache hit rate drops.
   Guardrail: keep family-context additions limited to normalized enums and short stable tokens.

2. Risk: style semantic role normalization becomes too clever and unstable.
   Guardrail: prefer a tiny allowlist and drop ambiguous values instead of preserving nuance.

3. Risk: docs say one thing while runtime metrics say another.
   Guardrail: update docs and any settings/runtime-copy assertions in the same change set.

4. Risk: pattern docs grounding starts implying structural behavior that retrieval does not actually use.
   Guardrail: keep this work limited to prompt-grounding relevance, not Qdrant retrieval semantics.

## Sequencing

1. Implement pattern docs relevance changes and tests first.
2. Implement style docs relevance changes and tests second.
3. Update runtime-contract docs third.
4. Run focused verification and docs-freshness checks last.

## Out Of Scope

- Changing Qdrant retrieval or ranking architecture for patterns
- Adding new docs sources beyond trusted `developer.wordpress.org`
- Reworking prewarm scheduling strategy
- Broad prompt redesign outside the docs-grounding sections
