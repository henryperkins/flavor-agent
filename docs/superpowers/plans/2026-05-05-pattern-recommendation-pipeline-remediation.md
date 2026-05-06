# Pattern Recommendation Pipeline Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the pattern recommendation pipeline so registered patterns are not misclassified as synced patterns in Cloudflare AI Search, post-scoped pattern recommendation permissions honor the active document entity, and Qdrant retrieval enforces `visiblePatternNames` before ranking.

**Architecture:** Keep the existing pattern recommendation contract intact: the editor sends `visiblePatternNames`, the selected retrieval backend returns current allowed candidates, and `PatternAbilities::recommend_patterns()` reranks browse-only suggestions. The remediation tightens identity metadata at sync/upload boundaries, fixes shared recommendation authorization parsing, pushes visibility filters down into Qdrant retrieval, and documents the backend invariants that tests must protect.

**Tech Stack:** WordPress plugin PHP, WordPress Abilities API registration, PHPUnit, Qdrant HTTP payload filters, Cloudflare AI Search item metadata, `@wordpress/scripts` docs checks, repo-native `npm run verify`.

**Execution Status:** Implemented and verified on 2026-05-05. Validation evidence is recorded in `docs/validation/2026-05-05-pattern-recommendation-pipeline-remediation.md`.

---

## Findings Covered

- [ ] **Cloudflare AI Search registered-pattern misclassification:** Registered pattern uploads currently write the stable item UUID into `synced_id`. The retrieval backend then runs `absint()` on that arbitrary UUID, so UUIDs beginning with digits can become bogus `core/block/{id}` synced candidates and get dropped by the visible-pattern allow-list.
- [ ] **Page/custom post document-scope permission undercheck:** Pattern recommendation requests from document scopes such as `page:42` send `document.scopeKey`, `document.postType`, and `document.entityId`; the shared permission callback only recognizes `post:42` or `postId`, so page/custom post requests can fall back to broad `edit_posts`.
- [ ] **Qdrant visible-pattern starvation:** Qdrant retrieval raises search limits based on `visiblePatternNames` but does not send a visibility filter to Qdrant. Allowed patterns that fall below the global top-N window can be starved before PHP post-filtering and reranking.

## Files To Change

- [ ] `inc/Cloudflare/PatternSearchClient.php`
- [ ] `inc/Patterns/Retrieval/CloudflareAISearchPatternRetrievalBackend.php`
- [ ] `inc/AI/Abilities/RecommendationAbility.php`
- [ ] `inc/Embeddings/QdrantClient.php`
- [ ] `inc/Patterns/Retrieval/QdrantPatternRetrievalBackend.php`
- [ ] `tests/phpunit/CloudflarePatternSearchClientTest.php`
- [ ] `tests/phpunit/PatternAbilitiesTest.php`
- [ ] `tests/phpunit/RegistrationTest.php`
- [ ] `tests/phpunit/EmbeddingBackendValidationTest.php`
- [ ] `docs/reference/abilities-and-routes.md`
- [ ] `docs/reference/pattern-recommendation-debugging.md`
- [ ] `docs/validation/2026-05-05-pattern-recommendation-pipeline-remediation.md`

## Task 1: Protect Cloudflare AI Search Pattern Identity

### Tests First

- [ ] Add `CloudflarePatternSearchClientTest::test_upload_registered_pattern_does_not_store_item_uuid_as_synced_id()`.
  - Build a registered pattern payload with `name => 'theme/hero'`.
  - Use `PatternIndex::pattern_uuid( 'theme/hero' )` as the item ID to mirror production sync.
  - Assert the uploaded JSON includes `pattern_name: "theme/hero"` and `candidate_type: "pattern"`.
  - Assert `synced_id` is empty or absent for the registered pattern.
  - Assert the UUID never appears as `synced_id`.
- [ ] Add `CloudflarePatternSearchClientTest::test_upload_synced_pattern_stores_actual_synced_pattern_id()`.
  - Use a synced/user pattern payload with `name => 'core/block/94'`, `type => 'user'`, `source => 'synced'`, and `syncedPatternId => 94`.
  - Assert the uploaded JSON includes `candidate_type: "user"`, `source: "synced"`, and `synced_id: "94"`.
- [ ] Add `PatternAbilitiesTest::test_recommend_patterns_cloudflare_ai_search_keeps_registered_uuid_metadata_in_registered_lane()`.
  - Configure the Cloudflare AI Search backend using the existing helper.
  - Register a visible pattern named `theme/hero`.
  - Return a remote chunk for `theme/hero` whose metadata includes `synced_id => PatternIndex::pattern_uuid( 'theme/hero' )`.
  - Request recommendations with `visiblePatternNames => [ 'theme/hero' ]`.
  - Assert the recommendation survives as `theme/hero`, not `core/block/{absint(uuid)}`.
- [ ] Add or extend a Cloudflare retrieval test for invalid synced metadata.
  - If `source`/`candidate_type` say synced but no positive synced ID can be resolved from `synced_id` or `core/block/{id}`, the backend should drop that candidate instead of inventing an ID.

### Implementation

- [ ] In `PatternSearchClient::build_metadata()`, stop defaulting `synced_id` to `$item_id`.
- [ ] Add a private helper that returns a synced pattern ID only when the pattern is actually a synced/user pattern:

```php
private static function synced_pattern_id_text( array $pattern ): string {
	$id = absint( $pattern['syncedPatternId'] ?? 0 );

	if ( $id <= 0 ) {
		foreach ( [ 'name', 'id' ] as $key ) {
			if ( isset( $pattern[ $key ] ) && is_string( $pattern[ $key ] ) && preg_match( '/^core\/block\/(\d+)$/', $pattern[ $key ], $matches ) ) {
				$id = absint( $matches[1] );
				break;
			}
		}
	}

	$type   = sanitize_key( (string) ( $pattern['type'] ?? '' ) );
	$source = sanitize_key( (string) ( $pattern['source'] ?? '' ) );

	if ( $id <= 0 || ( 'user' !== $type && 'synced' !== $source ) ) {
		return '';
	}

	return (string) $id;
}
```

- [ ] Preserve stable Cloudflare item IDs for upload/delete reconciliation; this change only affects metadata fields exposed to retrieval.
- [ ] In `CloudflareAISearchPatternRetrievalBackend`, replace raw `absint( $metadata['synced_id'] )` with a helper that:
  - reads `source`, `candidate_type`, `synced_id`, and the resolved `pattern_name`;
  - treats a candidate as synced only when the metadata says `source === synced`, `candidate_type === user`, or the name is `core/block/{id}`;
  - accepts numeric `synced_id` or a numeric `core/block/{id}` name;
  - ignores UUID-like or arbitrary non-numeric `synced_id` values for registered patterns;
  - drops candidates that claim to be synced but do not resolve to a positive current synced pattern ID.

### Acceptance Criteria

- [ ] Registered Cloudflare AI Search candidates with UUID metadata are returned by their registered pattern name.
- [ ] Synced Cloudflare AI Search candidates still rehydrate through current `wp_block` posts.
- [ ] No retrieval path fabricates `core/block/{id}` from arbitrary item UUIDs.

## Task 2: Enforce Document-Scoped Pattern Recommendation Permissions

### Tests First

- [ ] Add `RegistrationTest::test_pattern_recommendation_permission_callback_checks_document_entity_scope()`.
  - Register recommendation abilities.
  - Locate `flavor-agent/recommend-patterns` in `WordPressTestState::$registered_abilities`.
  - Configure capabilities so `edit_posts` is true, `edit_post:42` is false, and `edit_post:101` is true.
  - Assert a request with `document => [ 'scopeKey' => 'page:42', 'postType' => 'page', 'entityId' => '42' ]` is denied.
  - Assert a request with `document => [ 'scopeKey' => 'case_study:101', 'postType' => 'case_study', 'entityId' => '101' ]` is allowed.
- [ ] Add a regression assertion for existing `post:101` scope behavior so the new parsing does not break current coverage.

### Implementation

- [ ] In `RecommendationAbility::post_id_from_input()`, inspect normalized `document.entityId` for post-scoped recommendation surfaces.
- [ ] Add a document helper that checks explicit IDs before falling back to scope parsing:

```php
private function post_id_from_document( mixed $document ): int {
	$document = $this->normalize_map( $document );

	if ( [] === $document ) {
		return 0;
	}

	$post_id = $this->post_id_from_context( $document, [ 'postId', 'post_id', 'id', 'entityId' ] );

	if ( $post_id > 0 ) {
		return $post_id;
	}

	return $this->post_id_from_scope_key( $document );
}
```

- [ ] Broaden `post_id_from_scope_key()` from `post:{id}` to any post-type-like prefix with a numeric suffix, for example `page:42` and `case_study:101`.
- [ ] Keep the capability decision unchanged once a post ID is found: post-scoped recommendations must pass `current_user_can( 'edit_post', $post_id )`.
- [ ] Do not apply post-ID extraction to theme/global-style surfaces that are intentionally guarded by theme capabilities.

### Acceptance Criteria

- [ ] Pattern recommendations for pages and custom post types require direct `edit_post` permission on the active entity.
- [ ] Existing post-scope authorization behavior still passes.
- [ ] Theme-scoped recommendation abilities remain guarded by their existing theme capability contracts.

## Task 3: Push `visiblePatternNames` Into Qdrant Retrieval

### Tests First

- [ ] Update `PatternAbilitiesTest::test_recommend_patterns_uses_stale_usable_index_builds_structural_query_and_filters_candidates()`.
  - The semantic Qdrant search request should include a filter limiting `name` to the request's `visiblePatternNames`.
  - The structural Qdrant search request should combine the same visible-name filter with the existing structural `should` clauses.
- [ ] Add a Qdrant regression test for visible-pattern starvation if the current fake client can model it cleanly.
  - Return an invisible high-score candidate and a visible lower-score candidate only when the visible filter is present.
  - Assert the visible candidate reaches the reranker and final response.
- [ ] Update `EmbeddingBackendValidationTest` expectations for Qdrant payload indexes to include a keyword index on `name`.

### Implementation

- [ ] Add `name` to the keyword payload indexes created by `QdrantClient::ensure_payload_indexes()`.
- [ ] In `QdrantPatternRetrievalBackend`, add a visible-name filter builder:

```php
private function build_visible_name_filter( array $visible_pattern_names ): array {
	$names = array_values( array_unique( array_filter( StringArray::sanitize( $visible_pattern_names ) ) ) );

	if ( [] === $names ) {
		return [];
	}

	return [
		'key'   => 'name',
		'match' => [
			'any' => $names,
		],
	];
}
```

- [ ] Add a filter merge helper that appends the visible filter to `must` while preserving existing structural `should` clauses.
- [ ] Pass the visible filter to the semantic Qdrant search instead of an empty filter.
- [ ] Pass the merged visible and structural filter to the structural Qdrant search.
- [ ] During implementation, verify the local Qdrant client/test payload format accepts `match.any`; if not, use an equivalent `should` list of `match.value` clauses while keeping the same behavior and test intent.

### Acceptance Criteria

- [ ] Qdrant never spends its top-N candidate window on patterns outside `visiblePatternNames` when the request provides visible names.
- [ ] Structural search still honors block/template/category/trait hints.
- [ ] Qdrant collection setup creates an indexed `name` payload field for efficient visibility filtering.

## Task 4: Update Operator And Contract Documentation

- [ ] Update `docs/reference/abilities-and-routes.md`.
  - State that `flavor-agent/recommend-patterns` is post-entity scoped for post/page/custom post documents.
  - State that both pattern backends enforce current `visiblePatternNames` before reranking.
  - Clarify that Qdrant uses a `name` payload filter and Cloudflare AI Search uses `filters.pattern_name`.
- [ ] Update `docs/reference/pattern-recommendation-debugging.md`.
  - Add a Cloudflare AI Search metadata check: registered patterns should have empty/non-synced `synced_id`; synced patterns should have numeric IDs that resolve to readable `wp_block` posts.
  - Add a Qdrant retrieval check: search payloads should include a visible-name filter when `visiblePatternNames` is present.
  - Add a permission triage note for page/custom post scopes: verify `document.entityId` and `edit_post:{id}`.
- [ ] Add `docs/validation/2026-05-05-pattern-recommendation-pipeline-remediation.md` after implementation.
  - Record the exact commands, dates, exit codes, and any unavailable live backend checks.

## Task 5: Verification

- [ ] Run focused PHPUnit:

```bash
composer run test:php -- --filter 'CloudflarePatternSearchClientTest|PatternAbilitiesTest|RegistrationTest|EmbeddingBackendValidationTest'
```

Expected result: exit `0`; the new and updated tests pass.

- [ ] Run docs validation because contributor-facing docs changed:

```bash
npm run check:docs
```

Expected result: exit `0`.

- [ ] Run the fast aggregate verifier:

```bash
npm run verify -- --skip-e2e
```

Expected result: `VERIFY_RESULT` reports success for build, JS lint, plugin-check when prerequisites are available, unit, PHP, and skipped E2E by request.

- [ ] If `plugin-check` is unavailable because `WP_PLUGIN_CHECK_PATH`, `wp`, or a local WordPress root is missing, rerun intentionally scoped verification:

```bash
npm run verify -- --skip=lint-plugin --skip-e2e
```

Expected result: `VERIFY_RESULT` reports success with `lint-plugin` and E2E explicitly skipped.

## Completion Checklist

- [ ] All three reviewed findings have failing tests before implementation and passing tests after implementation.
- [ ] Cloudflare registered-pattern identity, synced-pattern rehydration, Qdrant visible filtering, and document-scoped authorization are covered by focused PHPUnit.
- [ ] Documentation explains the repaired contracts and backend-specific debugging signals.
- [ ] Validation evidence is committed as a dated markdown artifact under `docs/validation/`.
- [ ] Existing untracked or unrelated user files are left untouched.
