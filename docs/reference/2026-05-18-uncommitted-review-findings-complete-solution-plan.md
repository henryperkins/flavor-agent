# Uncommitted Review Findings Complete Solution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Address the confirmed uncommitted-review findings by restoring docs-grounding cache/fallback regression coverage and aligning shared-internals documentation with the current JavaScript export surface.

**Architecture:** Keep the production refactor intact: ability classes should keep using `CollectsDocsGuidance::collect_result()` and local implementation helpers should stay private unless there is a real consumer. Restore the deleted PHPUnit protection at the shared collector boundary instead of reintroducing removed private `collect_wordpress_docs_guidance()` wrappers. Treat the documentation fix as a precise `docs/reference/shared-internals.md` export-list correction.

**Tech Stack:** PHP 8.0-compatible PHPUnit tests, WordPress test shims in `tests/phpunit/bootstrap.php`, `@wordpress/scripts` JS tooling, repository docs freshness checker.

---

## Findings Covered

1. `tests/phpunit/DocsGroundingEntityCacheTest.php` lost coverage for exact-query, family, entity, and generic docs-grounding cache precedence across recommendation surfaces.
2. `docs/reference/shared-internals.md` still lists `buildGlobalStylesExecutionContract()` and `buildBlockStyleExecutionContract()` as exported functions even though `src/context/theme-tokens.js` now keeps them module-local.

## Files

- Modify: `tests/phpunit/DocsGroundingEntityCacheTest.php`
- Modify: `docs/reference/shared-internals.md`
- Reference only: `inc/Support/CollectsDocsGuidance.php`
- Reference only: `inc/Cloudflare/AISearchClient.php`
- Reference only: `src/context/theme-tokens.js`

---

### Task 1: Restore Docs-Grounding Cache Contract Tests

**Files:**
- Modify: `tests/phpunit/DocsGroundingEntityCacheTest.php`

- [x] **Step 1: Add collector-facing test helpers**

Add private helpers near the existing reflection helpers. These helpers keep the tests pointed at the current shared production path while still using each surface's real query/entity/family builders.

```php
/**
 * @param array<string, mixed> $context
 * @return array<int, array<string, mixed>>
 */
private function collect_block_docs_guidance( array $context, string $prompt ): array {
	return CollectsDocsGuidance::collect(
		fn( array $request_context, string $request_prompt ): string => $this->invoke_private_string_method( BlockAbilities::class, 'build_wordpress_docs_query', [ $request_context, $request_prompt ] ),
		fn( array $request_context ): string => $this->invoke_private_string_method( BlockAbilities::class, 'build_wordpress_docs_entity_key', [ $request_context ] ),
		fn( array $request_context ): array => $this->invoke_private_array_method( BlockAbilities::class, 'build_wordpress_docs_family_context', [ $request_context ] ),
		$context,
		$prompt
	);
}

/**
 * @param array<string, mixed> $context
 * @return array<int, array<string, mixed>>
 */
private function collect_template_docs_guidance( array $context, string $prompt ): array {
	return CollectsDocsGuidance::collect(
		fn( array $request_context, string $request_prompt ): string => $this->invoke_private_string_method( TemplateAbilities::class, 'build_wordpress_docs_query', [ $request_context, $request_prompt ] ),
		fn( array $request_context ): string => $this->invoke_private_string_method( TemplateAbilities::class, 'build_wordpress_docs_entity_key', [ $request_context ] ),
		fn( array $request_context ): array => $this->invoke_private_array_method( TemplateAbilities::class, 'build_wordpress_docs_family_context', [ $request_context ] ),
		$context,
		$prompt
	);
}

/**
 * @param array<string, mixed> $context
 * @return array<int, array<string, mixed>>
 */
private function collect_pattern_docs_guidance( array $context, string $prompt ): array {
	return CollectsDocsGuidance::collect(
		fn( array $request_context, string $request_prompt ): string => $this->invoke_private_string_method( PatternAbilities::class, 'build_wordpress_docs_query', [ $request_context, $request_prompt ] ),
		fn( array $request_context ): string => $this->invoke_private_string_method( PatternAbilities::class, 'build_wordpress_docs_entity_key', [ $request_context ] ),
		fn( array $request_context, string $request_prompt, string $entity_key ): array => $this->invoke_private_array_method( PatternAbilities::class, 'build_wordpress_docs_family_context', [ $request_context, $entity_key ] ),
		$context,
		$prompt,
		[
			'allowForegroundWarm' => true,
		]
	);
}

/**
 * @param array<string, mixed> $context
 * @return array<int, array<string, mixed>>
 */
private function collect_style_docs_guidance( array $context, string $prompt ): array {
	return CollectsDocsGuidance::collect(
		fn( array $request_context, string $request_prompt ): string => $this->invoke_private_string_method( StyleAbilities::class, 'build_wordpress_docs_query', [ $request_context, $request_prompt ] ),
		fn( array $request_context ): string => $this->invoke_private_string_method( StyleAbilities::class, 'build_wordpress_docs_entity_key', [ $request_context ] ),
		fn( array $request_context ): array => $this->invoke_private_array_method( StyleAbilities::class, 'build_wordpress_docs_family_context', [ $request_context ] ),
		$context,
		$prompt
	);
}

/**
 * @param array<string, mixed> $context
 * @return array<int, array<string, mixed>>
 */
private function collect_template_part_docs_guidance( array $context, string $prompt ): array {
	return CollectsDocsGuidance::collect(
		fn( array $request_context, string $request_prompt ): string => $this->invoke_private_string_method( TemplateAbilities::class, 'build_template_part_wordpress_docs_query', [ $request_context, $request_prompt ] ),
		static fn( array $request_context, string $query ): string => AISearchClient::resolve_entity_key( 'core/template-part', $query ),
		fn( array $request_context ): array => $this->invoke_private_array_method( TemplateAbilities::class, 'build_template_part_wordpress_docs_family_context', [ $request_context ] ),
		$context,
		$prompt
	);
}
```

- [x] **Step 2: Restore exact-query-before-entity assertions**

Re-add the deleted tests for these cases, replacing calls to removed private ability wrappers with the helpers from Step 1:

- `test_block_docs_guidance_uses_query_cache_before_entity_cache()`
- `test_template_docs_guidance_uses_query_cache_before_entity_cache()`
- `test_pattern_docs_guidance_uses_query_cache_before_entity_cache()`
- `test_style_book_docs_guidance_uses_query_cache_before_entity_cache()`
- `test_template_part_docs_guidance_uses_query_cache_before_entity_cache()`

Each test should seed both the exact-query transient and entity transient, call the matching helper, assert the exact-query guidance wins, and assert `WordPressTestState::$last_remote_post` remains empty.

- [x] **Step 3: Restore fallback-order assertions**

Re-add the deleted fallback tests, also routed through the helpers:

- `test_block_docs_guidance_falls_back_to_entity_cache_on_query_miss()`
- `test_block_docs_guidance_uses_family_cache_before_entity_cache()`
- `test_global_styles_docs_guidance_falls_back_to_global_styles_guidance_on_query_miss()`
- `test_style_book_docs_guidance_falls_back_to_style_book_guidance_when_block_entity_cache_is_cold()`

For the style-book generic fallback case, keep the existing assertions that a foreground warm attempts the configured 5-second timeout and that `AISearchClient::CONTEXT_WARM_CRON_HOOK` is scheduled after the fallback is served.

- [x] **Step 4: Run targeted PHPUnit**

Run:

```bash
composer run test:php -- --filter DocsGroundingEntityCacheTest
```

Expected: all `DocsGroundingEntityCacheTest` tests pass, with the test count increased from the current 9-test baseline.

---

### Task 2: Correct Shared Internals Export Documentation

**Files:**
- Modify: `docs/reference/shared-internals.md`

- [x] **Step 1: Remove stale key-export rows**

In the `src/context/theme-tokens.js` "Key exports" table, remove the rows for:

```markdown
| `buildGlobalStylesExecutionContract(tokens)`                   | Combines supported style paths with sorted preset slug maps for the LLM to validate style writes        |
| `buildBlockStyleExecutionContract(tokens, blockType)`          | Same as above but scoped to a specific block type's supports                                            |
```

Keep the existing exported `buildGlobalStylesExecutionContractFromSettings(settings)` and `buildBlockStyleExecutionContractFromSettings(settings, type)` rows because those remain public exports in `src/context/theme-tokens.js`.

- [x] **Step 2: Add a short internal-helper note**

Immediately after the table, add:

```markdown
`buildGlobalStylesExecutionContract(tokens)` and `buildBlockStyleExecutionContract(tokens, blockType)` are module-local helpers behind the `*FromSettings` exports.
```

- [x] **Step 3: Run docs checks**

Run:

```bash
npm run check:docs
git diff --check -- docs/reference/shared-internals.md
```

Expected: both commands exit 0.

---

### Task 3: Final Verification

**Files:**
- Verify all modified files.

- [x] **Step 1: Run the narrow gates**

Run:

```bash
composer run test:php -- --filter DocsGroundingEntityCacheTest
npm run check:docs
git diff --check
```

Expected: all commands exit 0.

- [x] **Step 2: Run repo-level non-browser gates**

Run:

```bash
composer run lint:php
composer run test:php
npm run lint:js
npm run test:unit
npm run build
```

Expected: lint and tests pass. `npm run build` may continue to print the existing webpack asset-size warnings for `index.js` and `activity-log.js`; those warnings are not part of this remediation unless a new build error appears.

- [x] **Step 3: Optional aggregate verifier**

Run when local WordPress and Plugin Check prerequisites are available:

```bash
npm run verify -- --skip-e2e
```

Expected: verifier exits 0 or reports an environment-specific incomplete step with logs under `output/verify/`. If incomplete, record the exact failing step and do not claim aggregate verifier success.

## Acceptance Criteria

- Docs-grounding cache precedence is protected by tests at the shared collector boundary.
- Exact-query cache continues to beat family/entity fallbacks.
- Family cache continues to beat broad entity fallback where applicable.
- Generic global-style/style-book fallback behavior remains covered, including foreground warm timeout and async warm scheduling.
- `docs/reference/shared-internals.md` lists only actual exports for `src/context/theme-tokens.js`.
- No production wrappers are reintroduced just to satisfy old tests.
- Verification evidence includes at least targeted PHPUnit, docs check, and `git diff --check`.
