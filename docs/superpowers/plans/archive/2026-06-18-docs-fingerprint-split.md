# Docs Fingerprint Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make docs-grounding content fingerprints explicit for recommendation applicability while preserving runtime freshness metadata for diagnostics.

**Architecture:** `DocsGuidanceResult` remains the single boundary for docs-grounding summaries and fingerprints. Recommendation and review signatures continue to consume a content/applicability fingerprint, while public summaries gain separate content/runtime fingerprint fields for diagnostics.

**Tech Stack:** WordPress plugin PHP, Abilities API output schemas, PHPUnit.

---

### Task 1: Add Explicit Docs Fingerprint Contract

**Files:**
- Modify: `tests/phpunit/DocsGuidanceResultTest.php`
- Modify: `inc/Support/DocsGuidanceResult.php`

- [x] **Step 1: Write the failing unit tests**

Add tests proving runtime-only fields do not change the content fingerprint and content-currentness fields do:

```php
public function test_content_fingerprint_ignores_runtime_fields_and_runtime_fingerprint_tracks_them(): void {
	$base = [
		[
			'url'         => 'https://developer.wordpress.org/block-editor/',
			'sourceType'  => 'developer-docs',
			'excerpt'     => 'Block editor guidance.',
			'contentHash' => 'docs-content-a',
			'publishedAt' => '2026-05-01T00:00:00Z',
			'freshness'   => 'current',
			'retrievedAt' => '2026-05-08T14:00:00Z',
			'score'       => 0.91,
		],
	];
	$runtime_changed = [
		array_merge(
			$base[0],
			[
			'retrievedAt' => '2026-05-09T14:00:00Z',
			'score'       => 0.42,
			]
		),
	];

	$first  = DocsGuidanceResult::from_guidance( $base, 'recommendation', 'best-effort' );
	$second = DocsGuidanceResult::from_guidance( $runtime_changed, 'recommendation', 'best-effort' );

	$this->assertSame( $first['contentFingerprint'], $second['contentFingerprint'] );
	$this->assertSame( $first['fingerprint'], $first['contentFingerprint'] );
	$this->assertSame( $first['contentFingerprint'], DocsGuidanceResult::content_fingerprint( $first ) );
	$this->assertNotSame( $first['runtimeFingerprint'], $second['runtimeFingerprint'] );
	$this->assertSame( $second['runtimeFingerprint'], DocsGuidanceResult::runtime_fingerprint( $second ) );
}

public function test_content_fingerprint_changes_when_docs_currentness_fields_change(): void {
	$base = [
		[
			'url'         => 'https://developer.wordpress.org/block-editor/',
			'sourceType'  => 'developer-docs',
			'excerpt'     => 'Block editor guidance.',
			'contentHash' => 'docs-content-a',
			'publishedAt' => '2026-05-01T00:00:00Z',
			'freshness'   => 'current',
		],
	];
	$changed = [
		array_merge(
			$base[0],
			[
			'freshness' => 'stale',
			]
		),
	];

	$this->assertNotSame(
		DocsGuidanceResult::from_guidance( $base, 'recommendation', 'best-effort' )['contentFingerprint'],
		DocsGuidanceResult::from_guidance( $changed, 'recommendation', 'best-effort' )['contentFingerprint']
	);
}
```

Run: `composer run test:php -- --filter DocsGuidanceResultTest`
Expected: FAIL because `contentFingerprint`, `runtimeFingerprint`, `content_fingerprint()`, and `runtime_fingerprint()` are not implemented.

- [x] **Step 2: Implement the minimal fingerprint split**

In `DocsGuidanceResult::from_guidance()`, compute `$content_fingerprint` and `$runtime_fingerprint`; return both, and keep `fingerprint` as an alias of `contentFingerprint` for backward compatibility. Add public result accessors `content_fingerprint()` and `runtime_fingerprint()`. Rename the existing private `fingerprint()` helper to `build_content_fingerprint()` and include content-owned currentness fields when present: `publishedAt`, `freshness`, `status`, and `coverage`. Add `build_runtime_fingerprint()` with `mode`, `transport`, the content fingerprint, and runtime fields when present: `retrievedAt`, `score`, `runtime`, `cacheStatus`, and `coverageCheckedAt`.

Run: `composer run test:php -- --filter DocsGuidanceResultTest`
Expected: PASS.

### Task 2: Expose Diagnostics Without Changing Applicability Semantics

**Files:**
- Modify: `tests/phpunit/RegistrationTest.php`
- Modify: `tests/phpunit/BlockAbilitiesTest.php`
- Modify: `inc/Abilities/Registration.php`
- Modify: `inc/Abilities/*Abilities.php`

- [x] **Step 1: Write failing schema and ability tests**

Update `RegistrationTest::test_recommendation_output_schemas_expose_docs_grounding_contract()` so `docsGrounding` declares `available`, `sourceTypes`, `count`, `contentFingerprint`, and `runtimeFingerprint`.

Add a block ability regression that primes two docs cache entries with identical content fields and different runtime fields, then asserts `resolvedContextSignature` and `docsGroundingFingerprint` stay stable while `docsGrounding.runtimeFingerprint` changes.

Run: `composer run test:php -- --filter 'RegistrationTest|BlockAbilitiesTest'`
Expected: FAIL because schemas do not expose the new fields and block responses do not yet include runtime fingerprints in `docsGrounding`.

- [x] **Step 2: Use content fingerprints explicitly in ability signatures**

In each recommendation ability, replace direct reads of `$docs_result['fingerprint']` with `DocsGuidanceResult::content_fingerprint( $docs_result )` for `resolvedContextSignature`, `reviewContextSignature`, and top-level `docsGroundingFingerprint`.

Run: `composer run test:php -- --filter 'RegistrationTest|BlockAbilitiesTest'`
Expected: PASS.

### Task 3: Verify and Document Open-Work Closure

**Files:**
- Modify: `docs/reference/current-open-work.md`
- Modify: `improving-levers.md`

- [x] **Step 1: Mark Phase 5 complete from current-source evidence**

Update the open-work row/status text to remove `Docs fingerprint split` from current implementation candidates and record that the split shipped with content/applicability and runtime/diagnostics fingerprints.

Run: `npm run check:docs`
Expected: PASS.

- [x] **Step 2: Run final targeted verification**

Run: `composer run test:php -- --filter 'DocsGuidanceResultTest|RegistrationTest|BlockAbilitiesTest'`
Expected: PASS.

Run: `git diff --check`
Expected: no output.
