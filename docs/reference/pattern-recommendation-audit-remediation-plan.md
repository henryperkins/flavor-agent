# Pattern Recommendation Audit Remediation Plan

Date: 2026-04-29

Status: proposed implementation plan

Scope: Address the confirmed review findings in the `flavor-agent/recommend-patterns` runtime path. This plan keeps the surface browse/rank-only inside the native Gutenberg inserter and avoids adding lane/review/apply, preview, deterministic executor, or Flavor Agent undo/activity semantics to pattern insertion.

## Findings Covered

1. `P1`: `/recommend-patterns` can expose synced patterns outside the caller's read/visibility scope.
2. `P2`: The inserter badge counts unrenderable recommendations.
3. `P2`: Pattern docs grounding is not cache-only on the recommendation request path.

## Target Outcomes

- Synced `wp_block` pattern content is never exposed to a caller that cannot read that pattern.
- Synced pattern payloads sent to the ranker are filtered or rehydrated through current WordPress access checks before the LLM prompt is built.
- Stale Qdrant payloads from older, overly broad syncs cannot leak private synced pattern content during retrieval.
- The first-party inserter shelf continues to use `visiblePatternNames` as the local insertion-scope contract and still fails closed for explicit empty arrays.
- The badge count only reports recommendations that can render in the shelf for the current allowed-pattern selector result.
- Pattern recommendation docs grounding uses cache/fallback guidance only during the foreground recommendation request; live AI Search warming runs asynchronously.
- Existing REST and Abilities permissions remain `edit_posts`; the fix is additional scoped filtering, not a permission bypass or route removal.

## Solution 1: Access-Safe Synced Pattern Recommendations

### Current Evidence

- `POST /flavor-agent/v1/recommend-patterns` requires `edit_posts` and forwards request data to `PatternAbilities::recommend_patterns()`.
- `flavor-agent/recommend-patterns` is registered as a public recommendation ability with the same `edit_posts` permission.
- `PatternIndex::collect_indexable_patterns()` appends synced `wp_block` patterns from `ServerCollector::for_indexable_synced_patterns()`.
- `SyncedPatternRepository::for_indexable_patterns()` calls `for_patterns( 'all', true, null, 0, null, false )`, disabling per-post read access.
- `SyncedPatternRepository::query_posts()` uses `post_status => any`, so private, draft, trashed, partial, and unsynced `wp_block` records can enter the index.
- `PatternAbilities::recommend_patterns()` filters candidates only by caller-supplied `visiblePatternNames` when present, then sends candidate payloads to the ranker and returns title/content metadata from Qdrant payloads.

### Product Decision

Keep the global pattern index for registered and user patterns, but treat indexed synced payloads as untrusted snapshots at request time. Visibility scoping remains client-derived because only Gutenberg knows the active inserter root; sensitive synced pattern access must be enforced separately by WordPress post permissions.

### Implementation Plan

1. Restrict future synced-pattern indexing to public-safe records.
   - Modify `inc/Context/SyncedPatternRepository.php`.
   - Add a public-safe index query path that returns only `post_status = publish` synced `wp_block` records and includes content.
   - Avoid depending on the current cron/admin user for indexability; the global shared Qdrant corpus should not contain private or draft synced pattern content.
   - Update `ServerCollector::for_indexable_synced_patterns()` to use the public-safe path.
   - Keep `list-synced-patterns` and `get-synced-pattern` behavior unchanged; those helper abilities should continue to use per-caller `read_post` checks.

2. Recheck synced pattern access before ranking.
   - Modify `inc/Abilities/PatternAbilities.php`.
   - When processing Qdrant candidates, identify synced candidates by `type === 'user'`, `source === 'synced'`, or a positive `syncedPatternId`.
   - Before adding a synced candidate to `$candidates`, call a server-side helper that resolves the current `wp_block` post and verifies the caller can read it or it is safely public.
   - If access fails, skip the candidate before it reaches `build_candidate_ranking_hint()`, `build_ranking_input()`, or `ResponsesClient::rank()`.
   - If access succeeds, rehydrate title, sync status, and content from the current post record so stale Qdrant payloads do not preserve deleted/private/changed content.

3. Add a small normalization helper for rehydrated synced candidates.
   - Prefer a single helper shared by indexing and request-time rehydration so `core/block/{id}`, `type: user`, `source: synced`, `syncedPatternId`, `syncStatus`, and `wpPatternSyncStatus` stay consistent.
   - The existing `PatternIndex::normalize_synced_pattern_for_index()` is private; either make a narrowly named public static helper or move the normalizer to `SyncedPatternRepository`.
   - Preserve the `core/block/{id}` name exactly. Do not switch to `post_name`, `core/block-flavor-agent-sync`, or any local alias.

4. Fail closed on missing visible scope for this inserter route.
   - The first-party `PatternRecommender` always sends `visiblePatternNames`, including `[]` when the allowed-pattern selector has no results.
   - In `Agent_Controller::handle_recommend_patterns()` and/or `PatternAbilities::recommend_patterns()`, treat an absent `visiblePatternNames` key as unscoped and return an empty recommendation set or a typed `missing_visible_pattern_scope` `WP_Error`.
   - Prefer an empty recommendation set for first-party UI compatibility unless API consumers already depend on a hard error.
   - Keep the explicit empty-array behavior unchanged: `visiblePatternNames: []` returns `['recommendations' => []]` before backend validation.

5. Keep local insertion enforcement unchanged.
   - Do not patch Gutenberg's pattern registry.
   - Do not widen insertion scope based on Pattern Overrides, `blockVisibility`, stale Qdrant hits, or server-ranked names.
   - `PatternRecommender` should still match recommendations against `getAllowedPatterns()` before rendering or inserting.

### Tests

Add or update PHPUnit coverage:

- `tests/phpunit/PatternIndexTest.php`
  - `test_sync_excludes_non_public_synced_pattern_posts_from_global_index`
    - Create published, private, draft, and trashed `wp_block` fixtures.
    - Run `PatternIndex::sync()`.
    - Assert only the published synced pattern appears in upsert payloads.
    - Assert private/draft/trash titles and content are absent from remote request bodies.

- `tests/phpunit/PatternAbilitiesTest.php`
  - `test_recommend_patterns_skips_unreadable_synced_qdrant_candidates_before_ranking`
    - Seed ready index state.
    - Mock Qdrant search returning a `core/block/{privateId}` payload with private content.
    - Set `current_user_can( 'read_post', privateId )` false.
    - Assert `ResponsesClient::rank()` is not called with the private title/content.
    - Assert the response omits that recommendation even if the mocked ranker returns its name.

  - `test_recommend_patterns_rehydrates_readable_synced_candidates_before_ranking`
    - Mock a stale Qdrant payload title/content.
    - Provide a readable current `wp_block` post with updated title/content.
    - Assert the LLM input and final response use the current post data.

  - `test_recommend_patterns_returns_empty_when_visible_pattern_scope_is_absent`
    - Call the ability without `visiblePatternNames`.
    - Assert no remote embedding, Qdrant, or chat calls occur.
    - If a hard error is chosen instead, assert the typed error code and status.

  - Keep existing `test_recommend_patterns_short_circuits_explicitly_empty_visible_patterns_before_backend_validation`.

- `tests/phpunit/AgentControllerTest.php`
  - Add REST adapter coverage for missing `visiblePatternNames`.
  - Add REST adapter coverage preserving `visiblePatternNames: []`.
  - Add a synced private payload regression if the controller test harness already has Qdrant/ranker mocks available.

### Documentation

Update after implementation:

- `docs/features/pattern-recommendations.md`
  - Clarify that the global synced-pattern index only stores public-safe synced patterns.
  - Clarify that request-time synced candidates are rechecked against current post read access before ranking/response.
  - Keep the statement that `core/block/{id}` names are preserved.

- `docs/reference/abilities-and-routes.md`
  - Update the `recommend-patterns` note to say synced/user recommendations are returned only when currently readable and in the supplied visible scope.
  - If missing `visiblePatternNames` becomes a typed error, document the error.

- `docs/reference/pattern-recommendation-debugging.md`
  - Add a troubleshooting note for empty results caused by missing visible scope or unreadable synced candidates.

## Solution 2: Badge Count Matches Renderable Shelf Items

### Current Evidence

- `PatternRecommender` builds `recommendedPatterns` by matching raw store recommendations against the current `allowedPatterns`.
- If raw recommendations exist but none are allowed locally, the inserter shows an explanatory empty notice.
- `InserterBadge` calls `getInserterBadgeState()` with raw `store.getPatternRecommendations()`.
- `getInserterBadgeState()` reports ready state whenever raw recommendation count is greater than zero.

### Product Decision

The badge is a promise that opening the inserter can reveal something actionable in the Flavor Agent shelf. It should count only recommendations currently renderable against the native allowed-pattern selector. Loading and error states still come from request state, not renderable count.

### Implementation Plan

1. Extract shared renderable matching.
   - Move `buildRecommendedPatterns()` from `src/patterns/PatternRecommender.js` to a small reusable helper, for example `src/patterns/recommendation-utils.js`.
   - Keep the helper pure: input raw recommendations and allowed patterns, output matched `{ pattern, recommendation }` pairs.
   - Preserve exact-name matching, including `core/block/{id}` for synced patterns.

2. Use the same helper in the shelf and badge.
   - `PatternRecommender` imports the helper and continues rendering only matched items.
   - `InserterBadge` selects the current block insertion point/root from `@wordpress/block-editor`, reads `getAllowedPatterns()` for that root, and filters raw store recommendations through the helper before calling `getInserterBadgeState()`.
   - Pass only renderable recommendation payloads to `getInserterBadgeState()` for ready counts and reason extraction.

3. Keep error/loading behavior visible.
   - If `patternStatus === 'loading'`, show the loading badge regardless of current renderable count.
   - If `patternStatus === 'error'`, show the error badge regardless of current renderable count.
   - If `patternStatus === 'ready'` and renderable count is zero, hide the badge.

4. Keep anchor failure fail-closed.
   - Do not add new DOM mutation paths.
   - If `findInserterToggle()` fails, `InserterBadge` still renders nothing.

### Tests

Add or update Jest coverage:

- `src/patterns/__tests__/recommendation-utils.test.js`
  - `buildRecommendedPatterns matches server names to allowed pattern names`.
  - `buildRecommendedPatterns preserves synced core/block/{id} matches`.
  - `buildRecommendedPatterns returns empty for ranked names missing from allowed patterns`.

- `src/patterns/__tests__/InserterBadge.test.js`
  - Ready raw recommendations with zero allowed matches render no badge.
  - Ready raw recommendations with one allowed match render a count of `1`.
  - Loading state still renders when allowed matches are zero.
  - Error state still renders when allowed matches are zero.
  - Missing toggle anchor still fails closed.

- `src/patterns/__tests__/PatternRecommender.test.js`
  - Update imports/mocks for the extracted helper if needed.
  - Keep existing coverage for the explanatory "not currently exposing those patterns" message.

## Solution 3: Cache-Only Docs Grounding For Pattern Requests

### Current Evidence

- `PatternAbilities::recommend_patterns()` calls `collect_wordpress_docs_guidance()` before embedding/retrieval/rerank.
- `CollectsDocsGuidance::collect()` calls `AISearchClient::maybe_search_with_cache_fallbacks()`.
- `maybe_search_with_cache_fallbacks()` can call `maybe_foreground_warm_context()` on exact/family/entity cache miss.
- `maybe_foreground_warm_context()` calls `warm_context()`, which performs a live Cloudflare AI Search HTTP request with a foreground timeout.
- `docs/features/pattern-recommendations.md` says WordPress docs grounding is cache-only and non-blocking for this surface.

### Product Decision

Pattern recommendations should never wait on a live AI Search call during the inserter request. They should use exact/family/entity/generic cached guidance when available, then schedule asynchronous context warming for next time.

### Implementation Plan

1. Add a foreground-warm option to docs collection.
   - Modify `inc/Cloudflare/AISearchClient.php`.
   - Add an optional parameter to `maybe_search_with_cache_fallbacks()`, such as `$allow_foreground_warm = true`, preserving current default behavior for other callers.
   - When `$allow_foreground_warm` is false, skip `maybe_foreground_warm_context()` entirely and proceed directly to `schedule_context_warm()`.

2. Thread the option through the shared collector.
   - Modify `inc/Support/CollectsDocsGuidance.php`.
   - Add an optional `$options` array or boolean that is passed to `maybe_search_with_cache_fallbacks()`.
   - Preserve the default behavior for block, template, template-part, navigation, Global Styles, and Style Book until each surface explicitly opts out.

3. Disable foreground warming only for pattern recommendations.
   - Modify `inc/Abilities/PatternAbilities.php`.
   - In `collect_wordpress_docs_guidance()`, call `CollectsDocsGuidance::collect()` with foreground warming disabled.
   - Keep roadmap guidance merging behavior unchanged.
   - Keep async context warming enabled so the cache improves after misses.

4. Keep request behavior resilient.
   - Cache miss should not produce a `WP_Error`.
   - Missing or misconfigured AI Search should still produce an empty docs-guidance array and continue to embedding/retrieval/rerank.
   - Do not log or return Cloudflare tokens, Qdrant keys, or provider API keys.

### Tests

Add or update PHPUnit coverage:

- `tests/phpunit/AISearchClientTest.php`
  - `test_maybe_search_with_cache_fallbacks_can_skip_foreground_warm_and_queue_async_warm`
    - Exact, family, and entity caches miss.
    - Generic fallback may or may not exist.
    - Pass foreground warm disabled.
    - Assert no live `wp_remote_post()` search call is made.
    - Assert context warm queue contains the normalized entry.

- `tests/phpunit/PatternAbilitiesTest.php`
  - `test_recommend_patterns_docs_grounding_does_not_perform_foreground_ai_search_on_cache_miss`
    - Configure AI Search enough that a foreground call would be possible.
    - Exercise `recommend_patterns()` with a ready index and mocked retrieval/rank path.
    - Assert no live Cloudflare search request happens during the recommendation call.
    - Assert retrieval/rerank still proceeds.

- Keep existing AI Search tests that verify foreground warm behavior for callers that still allow it.

### Documentation

Update after implementation:

- `docs/features/pattern-recommendations.md`
  - Keep "cache-only and non-blocking" and note that misses schedule async warming.

- `docs/reference/pattern-recommendation-debugging.md`
  - Clarify that live Cloudflare AI Search calls should not appear in foreground `/recommend-patterns` request traces after the fix.

## Implementation Sequence

1. Add synced-pattern access protections first.
   - This is the only security-sensitive finding.
   - Include tests that prove private synced content is absent from Qdrant upserts, LLM input, and final responses.

2. Fix badge renderable counts.
   - Extract the shared matching helper.
   - Keep shelf behavior unchanged and update badge tests.

3. Make pattern docs grounding cache-only.
   - Add the opt-out parameter without changing other surfaces by default.
   - Opt out from `PatternAbilities` only.

4. Update docs after code and tests pass.
   - Avoid documenting intended behavior before the runtime enforces it.

5. Run targeted verification, then the aggregate non-E2E gate.

## Verification Plan

Run the nearest targeted suites first:

```bash
composer run test:php -- --filter PatternIndexTest
composer run test:php -- --filter PatternAbilitiesTest
composer run test:php -- --filter AgentControllerTest
composer run test:php -- --filter AISearchClientTest
npm run test:unit -- --runTestsByPath src/patterns/__tests__/recommendation-utils.test.js src/patterns/__tests__/InserterBadge.test.js src/patterns/__tests__/PatternRecommender.test.js
```

Then run the cross-surface local gate:

```bash
node scripts/verify.js --skip-e2e
npm run check:docs
```

Before release evidence, run the relevant browser coverage if the local WordPress/Playwright environment is available:

```bash
npm run test:e2e -- tests/e2e/flavor-agent.patterns.spec.js
```

If the browser harness is unavailable or known-red, record that as a blocker or explicit waiver. Do not silently skip it.

## Residual Risk

- `visiblePatternNames` remains a client-derived insertion-scope signal. That is acceptable for the native inserter shelf because the client still filters and inserts only Gutenberg-exposed allowed patterns, but it must not be treated as authorization for synced pattern content.
- Existing Qdrant collections may already contain private synced payloads. Request-time access filtering must ship with the index change so stale collections cannot leak before the next sync completes.
- If non-first-party Abilities consumers depend on omitted `visiblePatternNames` returning global recommendations, the fail-closed scope change is a behavior break. The route is documented here as first-party inserter scoped, so the safer behavior is preferred.
