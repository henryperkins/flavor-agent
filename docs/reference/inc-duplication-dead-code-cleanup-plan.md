# Inc Duplication and Dead-Code Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` for inline execution, or `superpowers:subagent-driven-development` only if the user explicitly asks for parallel agent work. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove confirmed dead code and consolidate duplicated PHP helper logic in `inc/` while preserving public class names, WordPress runtime behavior, activity/history contracts, and recommendation payload signatures.

**Architecture:** Keep public APIs stable and extract duplicated private helper logic into focused support classes under `inc/Support/`. Prefer thin delegating wrappers where existing class names or method names are already part of internal call sites. Do not restructure recommendation flows, REST routes, provider clients, or activity persistence while performing this cleanup.

**Tech Stack:** WordPress plugin PHP, Composer PSR-4 autoloading, PHPUnit, WordPress Coding Standards via PHPCS/PHPCBF, existing `npm run verify` aggregate gate.

---

## Current Cleanup Baseline

The first cleanup slice has already been applied in the current working tree:

- [x] Removed unused private `Repository::query_scope_surfaces()` from `inc/Activity/Repository.php`.
- [x] Removed unused private `AISearchClient::resolve_config_value()` from `inc/Cloudflare/AISearchClient.php`.
- [x] Removed unused private `ResponseSchema::nullable_number()` from `inc/LLM/ResponseSchema.php`.
- [x] Added `FlavorAgent\Support\RecommendationSignature` and delegated `RecommendationResolvedSignature` / `RecommendationReviewSignature` through it.
- [x] Added `FlavorAgent\Support\NonNegativeInteger` and delegated `BlockAbilities::normalize_non_negative_int()` / `PatternAbilities::normalize_non_negative_int()` through it.
- [x] Ran targeted `php -l` across touched files: passed.
- [x] Ran targeted `vendor/bin/phpcs` across touched files after `phpcbf`: passed.

This plan covers the remaining duplication and dead-code candidates from the `inc/` audit.

---

## Findings To Address

### Confirmed Duplication

- Template prompt structural helpers repeat between `inc/LLM/TemplatePrompt.php` and `inc/LLM/TemplatePartPrompt.php`.
- WordPress docs guidance collection wrappers repeat across `inc/Abilities/BlockAbilities.php`, `inc/Abilities/StyleAbilities.php`, and `inc/Abilities/TemplateAbilities.php`.
- Recursive mixed-value normalization repeats across `inc/Abilities/BlockAbilities.php`, `inc/Abilities/NavigationAbilities.php`, and `inc/Context/BlockRecommendationExecutionContract.php`.
- Editing mode normalization repeats across `inc/Abilities/BlockAbilities.php`, `inc/Context/BlockRecommendationExecutionContract.php`, and `inc/LLM/Prompt.php`.
- Content/writing mode normalization repeats across `inc/Abilities/ContentAbilities.php` and `inc/LLM/WritingPrompt.php`.
- Admin user-label resolution repeats across `inc/Activity/Repository.php` and `inc/Activity/Serializer.php`.
- Admin page-hook matching repeats across `inc/Admin/ActivityPage.php` and `inc/Admin/Settings/Assets.php`.
- List-array detection repeats across `inc/Context/BlockRecommendationExecutionContract.php`, `inc/Context/BlockTypeIntrospector.php`, `inc/Context/ThemeTokenCollector.php`, and `inc/LLM/WordPressAIClient.php`.
- Target/path label formatting repeats across navigation, style, template, and template-part prompt code.

### Previously Flagged Dead-Code Candidates Now Known Used

- `StyleAbilities::normalize_variation()` in `inc/Abilities/StyleAbilities.php` is used by the `availableVariations` normalization callback.
- `StylePrompt::sanitize_path_segment()` in `inc/LLM/StylePrompt.php` is used as an `array_map()` callback while validating style operation paths.
- `CoreRoadmapGuidance::compare_items()` in `inc/Support/CoreRoadmapGuidance.php` is used as the `usort()` comparator for roadmap items.

Do not delete these three methods unless a fresh search after other refactors proves the call sites have been removed.

---

## File Structure

### Create

- `inc/Support/TemplatePromptStructuralHelpers.php`: shared template/template-part helper methods for block path sanitization, pattern lookup, expected target construction, and insertion-anchor formatting. Preserve the current `null` vs array return semantics exactly.
- `inc/Support/CanonicalValue.php`: recursive object/array/scalar normalization used by ability/context classes.
- `inc/Support/EditingMode.php`: shared normalization for Gutenberg editing modes.
- `inc/Support/WritingMode.php`: shared normalization for writing/content recommendation modes (`draft`, `edit`, `critique`).
- `inc/Support/UserLabels.php`: shared admin/user display label resolution.
- `inc/Admin/PageHookMatcher.php`: shared exact known-page-hook matching for Flavor Agent admin pages.
- `inc/Support/ListArrays.php`: shared sequential-list detection.
- `inc/Support/PathLabels.php`: shared path/target label formatting only for exact behavior clusters confirmed in Task 5.

### Modify

- `inc/LLM/TemplatePrompt.php`: delegate duplicated structural helpers to `TemplatePromptStructuralHelpers`.
- `inc/LLM/TemplatePartPrompt.php`: delegate duplicated structural helpers to `TemplatePromptStructuralHelpers`.
- `inc/Abilities/BlockAbilities.php`: delegate canonical value and editing mode helpers.
- `inc/Abilities/NavigationAbilities.php`: delegate canonical value helper.
- `inc/Context/BlockRecommendationExecutionContract.php`: delegate canonical value, editing mode, and list-array helpers.
- `inc/LLM/Prompt.php`: delegate editing mode helper.
- `inc/Abilities/ContentAbilities.php`: delegate writing mode helper.
- `inc/LLM/WritingPrompt.php`: delegate writing mode helper.
- `inc/Activity/Repository.php`: delegate admin user-label helper.
- `inc/Activity/Serializer.php`: delegate admin user-label helper.
- `inc/Admin/ActivityPage.php`: delegate page-hook matching helper.
- `inc/Admin/Settings/Assets.php`: delegate page-hook matching helper.
- `inc/Context/BlockTypeIntrospector.php`: delegate list-array helper.
- `inc/Context/ThemeTokenCollector.php`: delegate list-array helper.
- `inc/Context/NavigationContextCollector.php`: delegate canonical value helper.
- `inc/LLM/WordPressAIClient.php`: delegate list-array helper.
- `inc/Context/NavigationParser.php`: delegate exact matching path sanitizer only if behavior matches `PathLabels`.
- `inc/LLM/NavigationPrompt.php`: delegate exact matching path sanitizer only if behavior matches `PathLabels`.
- `inc/LLM/StylePrompt.php`: delegate `format_visibility_path()` only after confirming behavior parity with the template path label helpers. Do not delegate `sanitize_path_segment()` to `PathLabels`; it sanitizes style path string segments and has different behavior.

### Test

- Add focused PHPUnit coverage under `tests/phpunit/` for each shared support class that carries behavior previously duplicated in private methods.
- Update existing tests only when they already cover the affected behavior and provide a better home than a new support-class test.

---

## Task 1: Add Regression Coverage For Already-Extracted Helpers

**Files:**

- Create: `tests/phpunit/RecommendationSignatureTest.php`
- Create: `tests/phpunit/NonNegativeIntegerTest.php`
- Existing source: `inc/Support/RecommendationSignature.php`
- Existing source: `inc/Support/NonNegativeInteger.php`

- [ ] **Step 1: Add signature regression tests**

Create `tests/phpunit/RecommendationSignatureTest.php` with tests that prove resolved and review signatures remain identical for equivalent nested payloads with different object key order.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\RecommendationResolvedSignature;
use FlavorAgent\Support\RecommendationReviewSignature;
use FlavorAgent\Support\RecommendationSignature;
use PHPUnit\Framework\TestCase;

final class RecommendationSignatureTest extends TestCase {

	public function test_signature_is_stable_for_nested_assoc_order(): void {
		$left = [
			'b' => [ 'two' => 2, 'one' => 1 ],
			'a' => 'value',
		];
		$right = [
			'a' => 'value',
			'b' => [ 'one' => 1, 'two' => 2 ],
		];

		$this->assertSame(
			RecommendationSignature::from_payload( 'block', $left ),
			RecommendationSignature::from_payload( 'block', $right )
		);
	}

	public function test_existing_signature_wrappers_delegate_to_same_hash(): void {
		$payload = [
			'name' => 'core/paragraph',
			'operations' => [ [ 'type' => 'attribute_change' ] ],
		];

		$this->assertSame(
			RecommendationSignature::from_payload( 'block', $payload ),
			RecommendationResolvedSignature::from_payload( 'block', $payload )
		);
		$this->assertSame(
			RecommendationSignature::from_payload( 'block', $payload ),
			RecommendationReviewSignature::from_payload( 'block', $payload )
		);
	}
}
```

- [ ] **Step 2: Add non-negative integer regression tests**

Create `tests/phpunit/NonNegativeIntegerTest.php` with numeric edge cases matching the old private method behavior.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\NonNegativeInteger;
use PHPUnit\Framework\TestCase;

final class NonNegativeIntegerTest extends TestCase {

	/**
	 * @dataProvider provide_normalized_values
	 */
	public function test_normalizes_non_negative_integer_inputs( mixed $input, ?int $expected ): void {
		$this->assertSame( $expected, NonNegativeInteger::normalize( $input ) );
	}

	public static function provide_normalized_values(): array {
		return [
			'int zero' => [ 0, 0 ],
			'int positive' => [ 7, 7 ],
			'int negative' => [ -1, null ],
			'numeric string' => [ '42', 42 ],
			'numeric float string truncates like old code' => [ '4.9', 4 ],
			'float truncates like old code' => [ 3.8, 3 ],
			'negative numeric string' => [ '-2', null ],
			'empty string' => [ '', null ],
			'non numeric string' => [ 'abc', null ],
			'array' => [ [ 1 ], null ],
		];
	}
}
```

- [ ] **Step 3: Run focused tests**

Run:

```bash
vendor/bin/phpunit tests/phpunit/RecommendationSignatureTest.php tests/phpunit/NonNegativeIntegerTest.php
```

Expected: both test files pass.

- [ ] **Step 4: Run focused PHP lint and coding standards**

Run:

```bash
php -l tests/phpunit/RecommendationSignatureTest.php && php -l tests/phpunit/NonNegativeIntegerTest.php && vendor/bin/phpcs inc/Support/RecommendationSignature.php inc/Support/NonNegativeInteger.php tests/phpunit/RecommendationSignatureTest.php tests/phpunit/NonNegativeIntegerTest.php
```

Expected: syntax lint passes and PHPCS exits `0`.

---

## Task 2: Consolidate Template Prompt Structural Helpers

**Files:**

- Create: `inc/Support/TemplatePromptStructuralHelpers.php`
- Modify: `inc/LLM/TemplatePrompt.php`
- Modify: `inc/LLM/TemplatePartPrompt.php`
- Test: `tests/phpunit/TemplatePromptStructuralHelpersTest.php`

- [ ] **Step 1: Add shared helper tests before changing prompts**

Create `tests/phpunit/TemplatePromptStructuralHelpersTest.php`.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\TemplatePromptStructuralHelpers;
use PHPUnit\Framework\TestCase;

final class TemplatePromptStructuralHelpersTest extends TestCase {

	public function test_sanitize_block_path_keeps_non_negative_integer_segments(): void {
		$this->assertSame(
			[ 0, 2, 5 ],
			TemplatePromptStructuralHelpers::sanitize_block_path( [ '0', 2, '5' ] )
		);
		$this->assertNull( TemplatePromptStructuralHelpers::sanitize_block_path( [ 0, -1 ] ) );
		$this->assertNull( TemplatePromptStructuralHelpers::sanitize_block_path( [ 0, 'bad' ] ) );
		$this->assertNull( TemplatePromptStructuralHelpers::sanitize_block_path( [] ) );
	}

	public function test_build_pattern_lookup_indexes_pattern_names(): void {
		$lookup = TemplatePromptStructuralHelpers::build_pattern_lookup(
			[ 'patterns' => [
				[ 'name' => 'theme/hero', 'title' => 'Hero' ],
				[ 'name' => '', 'title' => 'Ignored' ],
			] ]
		);

		$this->assertArrayHasKey( 'theme/hero', $lookup );
		$this->assertTrue( $lookup['theme/hero'] );
		$this->assertArrayNotHasKey( '', $lookup );
	}

	public function test_format_insertion_anchors_returns_readable_labels(): void {
		$formatted = TemplatePromptStructuralHelpers::format_insertion_anchors(
			[
				[ 'targetPath' => [ 0 ], 'placement' => 'before', 'label' => 'Header' ],
				[ 'targetPath' => [ 1, 2 ], 'placement' => 'after', 'label' => '' ],
			]
		);

		$this->assertStringContainsString( 'Header', $formatted );
		$this->assertStringContainsString( '`after`', $formatted );
		$this->assertStringContainsString( '[1, 2]', $formatted );
	}
}
```

- [ ] **Step 2: Implement shared helper with copied behavior**

Create `inc/Support/TemplatePromptStructuralHelpers.php` by moving the exact bodies of the duplicated helpers from `TemplatePrompt` / `TemplatePartPrompt` into public static support methods.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class TemplatePromptStructuralHelpers {

	/**
	 * @param mixed $path
 * @return array<int, int>
 */
public static function sanitize_block_path( mixed $path ): ?array {
	if ( ! is_array( $path ) || 0 === count( $path ) ) {
		return null;
	}

	$sanitized = [];
	foreach ( $path as $segment ) {
		if ( ! is_int( $segment ) && ! is_numeric( $segment ) ) {
			return null;
		}

		$segment = (int) $segment;

		if ( $segment < 0 ) {
			return null;
		}

		$sanitized[] = $segment;
	}

	return $sanitized;
}

	/**
 * @param array<string, mixed> $context
 * @return array<string, true>
 */
public static function build_pattern_lookup( array $context ): array {
	$lookup = [];

	foreach ( is_array( $context['patterns'] ?? null ) ? $context['patterns'] : [] as $pattern ) {
		if ( ! is_array( $pattern ) ) {
			continue;
		}

		$name = sanitize_text_field( (string) ( $pattern['name'] ?? '' ) );
		if ( '' !== $name ) {
			$lookup[ $name ] = true;
		}
	}

	return $lookup;
}

/**
 * @param array<string, mixed> $target_node
 * @return array<string, mixed>
 */
public static function build_expected_target( array $target_node ): array {
	$expected = [
		'name'       => sanitize_text_field( (string) ( $target_node['name'] ?? '' ) ),
		'label'      => sanitize_text_field( (string) ( $target_node['label'] ?? '' ) ),
		'attributes' => is_array( $target_node['attributes'] ?? null ) ? $target_node['attributes'] : [],
		'childCount' => isset( $target_node['childCount'] ) ? (int) $target_node['childCount'] : 0,
	];

	$slot = is_array( $target_node['slot'] ?? null ) ? $target_node['slot'] : [];
	if ( count( $slot ) > 0 ) {
		$expected['slot'] = [
			'slug'    => sanitize_key( (string) ( $slot['slug'] ?? '' ) ),
			'area'    => sanitize_key( (string) ( $slot['area'] ?? '' ) ),
			'isEmpty' => ! empty( $slot['isEmpty'] ),
		];
	}

	return $expected;
	}

	/**
	 * @param array<int, array<string, mixed>> $anchors
	 */
	public static function format_insertion_anchors( array $anchors ): string {
		$lines = [];

		foreach ( $anchors as $anchor ) {
		if ( ! is_array( $anchor ) ) {
			continue;
		}

		$placement = sanitize_key( (string) ( $anchor['placement'] ?? '' ) );
		$label     = sanitize_text_field( (string) ( $anchor['label'] ?? '' ) );
		$path      = self::sanitize_block_path( $anchor['targetPath'] ?? null );
		$line      = '- ' . ( '' !== $label ? $label : $placement );

		if ( '' !== $placement ) {
			$line .= " (`{$placement}`)";
		}

		if ( null !== $path ) {
			$line .= ' -> [' . implode( ', ', $path ) . ']';
		}

		$lines[] = $line;
	}

	return implode( "\n", $lines );
}
}
```

- [ ] **Step 3: Delegate duplicated methods in template prompt classes**

In `inc/LLM/TemplatePrompt.php` and `inc/LLM/TemplatePartPrompt.php`, keep the old private method names where they have many local callers, but replace their bodies with calls to `TemplatePromptStructuralHelpers`.

```php
private static function sanitize_block_path( mixed $path ): ?array {
	return \FlavorAgent\Support\TemplatePromptStructuralHelpers::sanitize_block_path( $path );
}
```

Apply the same delegation pattern for `build_pattern_lookup()`, `build_expected_target()`, and `format_insertion_anchors()`.

- [ ] **Step 4: Run focused tests and lint**

Run:

```bash
vendor/bin/phpunit tests/phpunit/TemplatePromptStructuralHelpersTest.php
php -l inc/Support/TemplatePromptStructuralHelpers.php && php -l inc/LLM/TemplatePrompt.php && php -l inc/LLM/TemplatePartPrompt.php
vendor/bin/phpcs inc/Support/TemplatePromptStructuralHelpers.php inc/LLM/TemplatePrompt.php inc/LLM/TemplatePartPrompt.php tests/phpunit/TemplatePromptStructuralHelpersTest.php
```

Expected: PHPUnit passes, syntax lint passes, PHPCS exits `0`.

---

## Task 3: Consolidate Canonical Value, Editing Mode, Writing Mode, And List Helpers

**Files:**

- Create: `inc/Support/CanonicalValue.php`
- Create: `inc/Support/EditingMode.php`
- Create: `inc/Support/WritingMode.php`
- Create: `inc/Support/ListArrays.php`
- Modify: `inc/Abilities/BlockAbilities.php`
- Modify: `inc/Abilities/NavigationAbilities.php`
- Modify: `inc/Context/BlockRecommendationExecutionContract.php`
- Modify: `inc/LLM/Prompt.php`
- Modify: `inc/Abilities/ContentAbilities.php`
- Modify: `inc/LLM/WritingPrompt.php`
- Modify: `inc/Context/NavigationContextCollector.php`
- Modify: `inc/Context/BlockTypeIntrospector.php`
- Modify: `inc/Context/ThemeTokenCollector.php`
- Modify: `inc/LLM/WordPressAIClient.php`
- Test: `tests/phpunit/SupportNormalizationTest.php`

- [ ] **Step 1: Add support normalization tests**

Create `tests/phpunit/SupportNormalizationTest.php`.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\CanonicalValue;
use FlavorAgent\Support\EditingMode;
use FlavorAgent\Support\ListArrays;
use FlavorAgent\Support\WritingMode;
use PHPUnit\Framework\TestCase;

final class SupportNormalizationTest extends TestCase {

	public function test_canonical_value_recursively_keeps_scalar_values_and_nulls_unknowns(): void {
		$input = (object) [
			'a' => 'text',
			'b' => [ 'nested' => true ],
			'c' => fopen( 'php://memory', 'r' ),
		];

		$normalized = CanonicalValue::normalize( $input );

		$this->assertSame( 'text', $normalized['a'] );
		$this->assertSame( true, $normalized['b']['nested'] );
		$this->assertNull( $normalized['c'] );
	}

	public function test_canonical_value_map_and_list_helpers(): void {
		$this->assertSame( [ 'a' => 'b' ], CanonicalValue::map( [ 'a' => 'b' ] ) );
		$this->assertSame( [ 'first', 'second' ], CanonicalValue::list( [ 3 => 'first', 7 => 'second' ] ) );
		$this->assertSame( [], CanonicalValue::map( 'not-array' ) );
	}

	/**
	 * @dataProvider provide_editing_modes
	 */
	public function test_editing_mode_normalization( mixed $input, string $expected ): void {
		$this->assertSame( $expected, EditingMode::normalize( $input ) );
	}

	public static function provide_editing_modes(): array {
		return [
			'content only camel' => [ 'contentOnly', 'contentOnly' ],
			'content only dashed' => [ 'content-only', 'contentOnly' ],
			'disabled' => [ 'disabled', 'disabled' ],
			'unknown' => [ 'all', 'default' ],
			'non-string' => [ null, 'default' ],
		];
	}

	/**
	 * @dataProvider provide_recommendation_modes
	 */
	public function test_writing_mode_normalization( mixed $input, string $expected ): void {
		$this->assertSame( $expected, WritingMode::normalize( $input ) );
	}

	public static function provide_recommendation_modes(): array {
		return [
			'draft' => [ 'draft', 'draft' ],
			'edit' => [ 'edit', 'edit' ],
			'critique' => [ 'critique', 'critique' ],
			'empty' => [ '', 'draft' ],
			'unknown' => [ 'delete', 'draft' ],
		];
	}

	public function test_list_array_detection(): void {
		$this->assertTrue( ListArrays::is_list( [] ) );
		$this->assertTrue( ListArrays::is_list( [ 'a', 'b' ] ) );
		$this->assertFalse( ListArrays::is_list( [ 1 => 'a' ] ) );
		$this->assertFalse( ListArrays::is_list( [ 'a' => 'b' ] ) );
	}
}
```

- [ ] **Step 2: Implement shared support classes**

Create `inc/Support/CanonicalValue.php`.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class CanonicalValue {

	public static function normalize( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = [];
			foreach ( $value as $key => $entry ) {
				$normalized[ $key ] = self::normalize( $entry );
			}

			return $normalized;
		}

		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}

		return null;
	}

	public static function map( mixed $value ): array {
		$normalized = self::normalize( $value );

		return is_array( $normalized ) ? $normalized : [];
	}

	public static function list( mixed $value ): array {
		$normalized = self::map( $value );

		return array_values( $normalized );
	}
}
```

Create `inc/Support/EditingMode.php`.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class EditingMode {

	public static function normalize( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return 'default';
		}

		$value = strtolower( preg_replace( '/[^a-z]/i', '', $value ) ?? '' );

		return match ( $value ) {
			'contentonly' => 'contentOnly',
			'disabled' => 'disabled',
			default => 'default',
		};
	}
}
```

Create `inc/Support/WritingMode.php`.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class WritingMode {

	public static function normalize( mixed $value ): string {
		$mode = sanitize_key( (string) $value );

		return in_array( $mode, [ 'draft', 'edit', 'critique' ], true ) ? $mode : 'draft';
	}
}
```

Create `inc/Support/ListArrays.php`.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class ListArrays {

	public static function is_list( array $value ): bool {
		$expected_index = 0;

		foreach ( $value as $key => $_entry ) {
			if ( $key !== $expected_index ) {
				return false;
			}

			++$expected_index;
		}

		return true;
	}
}
```

- [ ] **Step 3: Replace private method bodies with delegations**

Use these body replacements without changing callers in the same classes.

```php
private static function normalize_value( mixed $value ): mixed {
	return \FlavorAgent\Support\CanonicalValue::normalize( $value );
}

private static function normalize_map( mixed $value ): array {
	return \FlavorAgent\Support\CanonicalValue::map( $value );
}

private static function normalize_list( mixed $value ): array {
	return \FlavorAgent\Support\CanonicalValue::list( $value );
}

private static function normalize_editing_mode( mixed $value ): string {
	return \FlavorAgent\Support\EditingMode::normalize( $value );
}

private static function normalize_mode( mixed $value ): string {
	return \FlavorAgent\Support\WritingMode::normalize( $value );
}

private static function is_list_array( array $value ): bool {
	return \FlavorAgent\Support\ListArrays::is_list( $value );
}
```

For instance methods such as `NavigationContextCollector::normalize_value()` or `ThemeTokenCollector::is_list_array()`, keep the existing method visibility and staticness and delegate from the existing instance method body.

- [ ] **Step 4: Run focused tests and lint**

Run:

```bash
vendor/bin/phpunit tests/phpunit/SupportNormalizationTest.php
php -l inc/Support/CanonicalValue.php && php -l inc/Support/EditingMode.php && php -l inc/Support/WritingMode.php && php -l inc/Support/ListArrays.php
vendor/bin/phpcs inc/Support/CanonicalValue.php inc/Support/EditingMode.php inc/Support/WritingMode.php inc/Support/ListArrays.php inc/Abilities/BlockAbilities.php inc/Abilities/NavigationAbilities.php inc/Context/BlockRecommendationExecutionContract.php inc/LLM/Prompt.php inc/Abilities/ContentAbilities.php inc/LLM/WritingPrompt.php inc/Context/NavigationContextCollector.php inc/Context/BlockTypeIntrospector.php inc/Context/ThemeTokenCollector.php inc/LLM/WordPressAIClient.php tests/phpunit/SupportNormalizationTest.php
```

Expected: focused PHPUnit passes, syntax lint passes, PHPCS exits `0`.

---

## Task 4: Consolidate Admin User Labels And Admin Page Hook Matching

**Files:**

- Create: `inc/Support/UserLabels.php`
- Create: `inc/Admin/PageHookMatcher.php`
- Modify: `inc/Activity/Repository.php`
- Modify: `inc/Activity/Serializer.php`
- Modify: `inc/Admin/ActivityPage.php`
- Modify: `inc/Admin/Settings/Assets.php`
- Test: `tests/phpunit/AdminSharedHelpersTest.php`

- [ ] **Step 1: Add shared helper tests**

Create `tests/phpunit/AdminSharedHelpersTest.php`.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\PageHookMatcher;
use FlavorAgent\Support\UserLabels;
use PHPUnit\Framework\TestCase;

final class AdminSharedHelpersTest extends TestCase {

	public function test_user_label_returns_empty_for_zero_user(): void {
		$this->assertSame( '', UserLabels::admin_user_label( 0 ) );
	}

	public function test_page_hook_matcher_matches_known_suffixes(): void {
		$this->assertTrue( PageHookMatcher::matches_known_page_hook( 'settings_page_flavor-agent', [ 'settings_page_flavor-agent' ] ) );
		$this->assertTrue( PageHookMatcher::matches_known_page_hook( 'settings_page_flavor-agent-activity', [ 'settings_page_flavor-agent-activity' ] ) );
		$this->assertFalse( PageHookMatcher::matches_known_page_hook( 'settings_page_flavor-agent', [ 'settings_page_flavor-agent-activity' ] ) );
	}
}
```

- [ ] **Step 2: Implement shared user label helper**

Create `inc/Support/UserLabels.php`.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class UserLabels {

	public static function admin_user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'get_userdata' ) ) {
			$user = \get_userdata( $user_id );

			if ( is_object( $user ) && isset( $user->display_name ) ) {
				$display_name = trim( (string) $user->display_name );

				if ( '' !== $display_name ) {
					return $display_name;
				}
			}
		}

		return sprintf( 'User #%d', $user_id );
	}
}
```

- [ ] **Step 3: Implement shared page hook matcher**

Create `inc/Admin/PageHookMatcher.php`.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Admin;

final class PageHookMatcher {

	/**
	 * @param array<int, string> $known_page_hooks
	 */
	public static function matches_known_page_hook( string $page_hook, array $known_page_hooks ): bool {
		if ( '' === $page_hook ) {
			return false;
		}

		return in_array( $page_hook, $known_page_hooks, true );
	}
}
```

- [ ] **Step 4: Delegate existing private helpers**

In `inc/Activity/Repository.php` and `inc/Activity/Serializer.php`, delegate user-label private helpers.

```php
private static function resolve_admin_user_label( int $user_id ): string {
	return \FlavorAgent\Support\UserLabels::admin_user_label( $user_id );
}
```

In `inc/Admin/ActivityPage.php` and `inc/Admin/Settings/Assets.php`, delegate page-hook matching.

```php
private static function matches_known_page_hook( string $page_hook, string $registered_hook ): bool {
	return \FlavorAgent\Admin\PageHookMatcher::matches_known_page_hook(
		$page_hook,
		self::get_known_page_hooks( $registered_hook )
	);
}
```

- [ ] **Step 5: Run focused tests and lint**

Run:

```bash
vendor/bin/phpunit tests/phpunit/AdminSharedHelpersTest.php
php -l inc/Support/UserLabels.php && php -l inc/Admin/PageHookMatcher.php
vendor/bin/phpcs inc/Support/UserLabels.php inc/Admin/PageHookMatcher.php inc/Activity/Repository.php inc/Activity/Serializer.php inc/Admin/ActivityPage.php inc/Admin/Settings/Assets.php tests/phpunit/AdminSharedHelpersTest.php
```

Expected: focused PHPUnit passes, syntax lint passes, PHPCS exits `0`.

---

## Task 5: Review Path-Formatting Duplication Conservatively

**Files:**

- Create only if exact behavior parity is confirmed: `inc/Support/PathLabels.php`
- Modify only matching behavior: `inc/Context/NavigationParser.php`, `inc/LLM/NavigationPrompt.php`, `inc/LLM/StylePrompt.php`, `inc/LLM/TemplatePartPrompt.php`, `inc/LLM/TemplatePrompt.php`
- Test only if helper is extracted: `tests/phpunit/PathLabelsTest.php`

- [ ] **Step 1: Compare behavior before extracting**

Manually compare each existing path/target formatter and sanitizer. Extract only helpers whose accepted input, sanitization, and output format are identical.

Current review notes:

- `NavigationParser::sanitize_target_path()` and `NavigationPrompt::sanitize_target_path()` match each other. They return `null` for non-array, empty, or any non-numeric segment, and coerce negative numeric segments to `0`.
- `TemplatePartPrompt::format_block_path_label()`, `TemplatePrompt::format_block_path()`, and `StylePrompt::format_visibility_path()` match each other. They return an empty string for non-array or empty paths and otherwise format one-based labels as `Path 1 > 2`.
- These two clusters do not match each other. Do not share one helper between navigation target sanitization and human-readable path labels.

The exact duplicate candidates from the scan are:

```text
NavigationParser::sanitize_target_path()
NavigationPrompt::sanitize_target_path()
StylePrompt::format_visibility_path()
TemplatePartPrompt::format_block_path_label()
TemplatePrompt::format_block_path()
```

- [ ] **Step 2: If exact parity exists, add focused tests**

Create `tests/phpunit/PathLabelsTest.php` only for helper methods that replace exact duplicates.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\PathLabels;
use PHPUnit\Framework\TestCase;

final class PathLabelsTest extends TestCase {

	public function test_sanitizes_target_path_segments(): void {
		$this->assertSame( [ 0, 3, 0 ], PathLabels::sanitize_target_path( [ '0', '3', -1 ] ) );
		$this->assertNull( PathLabels::sanitize_target_path( [ '0', 'bad' ] ) );
		$this->assertNull( PathLabels::sanitize_target_path( [] ) );
	}

	public function test_formats_block_path_label(): void {
		$this->assertSame( 'Path 1 > 3 > 5', PathLabels::format_block_path_label( [ 0, 2, 4 ] ) );
		$this->assertSame( '', PathLabels::format_block_path_label( [] ) );
	}
}
```

- [ ] **Step 3: Implement exact shared helper only for matching behavior**

Create `inc/Support/PathLabels.php` only if Step 1 confirms exact parity.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class PathLabels {

	/**
	 * @return array<int, int>|null
	 */
	public static function sanitize_target_path( mixed $path ): ?array {
		if ( ! is_array( $path ) || [] === $path ) {
			return null;
		}

		$sanitized = [];
		foreach ( $path as $segment ) {
			if ( ! is_numeric( $segment ) ) {
				return null;
			}

			$sanitized[] = max( 0, (int) $segment );
		}

		return $sanitized;
	}

	public static function format_block_path_label( mixed $path ): string {
		if ( ! is_array( $path ) || [] === $path ) {
			return '';
		}

		$segments = array_map(
			static fn( mixed $segment ): int => (int) $segment + 1,
			$path
		);

		return 'Path ' . implode( ' > ', $segments );
	}
}
```

- [ ] **Step 4: Leave non-identical helpers alone**

If any helper includes domain-specific labels, target metadata, area fallback, or different empty-path behavior, do not extract it. Record the decision in the task summary instead of forcing abstraction.

- [ ] **Step 5: Run focused tests and lint if extraction occurred**

Run:

```bash
vendor/bin/phpunit tests/phpunit/PathLabelsTest.php
php -l inc/Support/PathLabels.php
vendor/bin/phpcs inc/Support/PathLabels.php inc/Context/NavigationParser.php inc/LLM/NavigationPrompt.php inc/LLM/StylePrompt.php inc/LLM/TemplatePartPrompt.php inc/LLM/TemplatePrompt.php tests/phpunit/PathLabelsTest.php
```

Expected: focused PHPUnit passes, syntax lint passes, PHPCS exits `0`.

---

## Task 6: Reconfirm Previously Flagged Dead-Code Candidates Stay Used

**Files:**

- Review: `inc/Abilities/StyleAbilities.php`
- Review: `inc/LLM/StylePrompt.php`
- Review: `inc/Support/CoreRoadmapGuidance.php`
- Modify only if a later refactor removes all call sites: same files
- Test existing nearest suites for style abilities, style prompt, and roadmap guidance if removals occur

- [ ] **Step 1: Search for direct and indirect call sites**

Run:

```bash
rg -n "normalize_variation|sanitize_path_segment|compare_items" inc tests flavor-agent.php
rg -n "\[\s*self::class\s*,\s*'normalize_variation'|'normalize_variation'|\[\s*self::class\s*,\s*'sanitize_path_segment'|'sanitize_path_segment'|\[\s*self::class\s*,\s*'compare_items'|'compare_items'" inc tests flavor-agent.php
```

Expected in the current tree: all three methods have runtime call sites. Leave them in place unless the search output changes after earlier cleanup work.

Known current call sites:

- `StyleAbilities::normalize_variation()` is used by the `availableVariations` normalization callback.
- `StylePrompt::sanitize_path_segment()` is used as an `array_map()` callback while validating style operation paths.
- `CoreRoadmapGuidance::compare_items()` is used as the `usort()` comparator for roadmap items.

- [ ] **Step 2: Remove only confirmed unused private methods**

Remove the full private method body and immediately preceding docblock only for a candidate that Step 1 proves unused in the updated tree.

Do not remove similarly named public methods, callables, or methods that are covered by dynamic callback references.

- [ ] **Step 3: Run syntax lint and coding standards on touched files**

Run:

```bash
php -l inc/Abilities/StyleAbilities.php && php -l inc/LLM/StylePrompt.php && php -l inc/Support/CoreRoadmapGuidance.php
vendor/bin/phpcs inc/Abilities/StyleAbilities.php inc/LLM/StylePrompt.php inc/Support/CoreRoadmapGuidance.php
```

Expected: syntax lint passes and PHPCS exits `0`.

- [ ] **Step 4: Run nearest behavior tests**

Run the nearest existing PHPUnit suites that cover style recommendations, style prompts, and roadmap guidance. If no focused test exists for a touched file, run the full PHP suite.

```bash
vendor/bin/phpunit tests/phpunit
```

Expected: PHPUnit exits `0`.

---

## Task 7: Final Cross-Surface Validation And Docs Closeout

**Files:**

- Existing plan artifact: `docs/reference/inc-duplication-dead-code-cleanup-plan.md`
- No contributor docs changes are required unless the cleanup changes public behavior, which it should not.

- [ ] **Step 1: Run aggregate PHP checks**

Run:

```bash
composer run lint:php
composer run test:php
```

Expected: both commands exit `0`.

- [ ] **Step 2: Run fast verifier without browser suites**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: final `VERIFY_RESULT={...}` reports success, or reports only an explicitly understood environment blocker unrelated to this refactor.

- [ ] **Step 3: Run full verifier if preparing release or PR closeout**

Run:

```bash
npm run verify
```

Expected: final `VERIFY_RESULT={...}` reports success. If browser prerequisites are unavailable, record the exact incomplete/blocking step and do not describe the run as passing.

- [ ] **Step 4: Prepare change summary**

Use this summary format for the final handoff:

```text
Summary:
- Removed only private helper code reconfirmed unused in inc/.
- Consolidated duplicate recommendation, numeric, template prompt, writing mode, normalization, admin helper, and list-array logic into focused support classes.
- Preserved existing public class names and local private wrapper method names where call sites depended on them.

Validation:
- php -l ...
- vendor/bin/phpcs ...
- vendor/bin/phpunit ...
- npm run verify -- --skip-e2e
```

---

## Risk Controls

- Keep existing public class names and public methods stable.
- Prefer delegation wrappers over sweeping call-site rewrites in large prompt classes.
- Extract only exact duplicate behavior; do not invent a generic abstraction for helpers that differ by domain.
- Add focused support-class tests before changing private helper bodies.
- Use PHPCBF only on files already touched by the cleanup.
- Treat browser or plugin-check unavailability as incomplete evidence, not as a pass.

## Recommended Execution Order

1. Task 1: cover the helper extractions already applied.
2. Task 2: consolidate template prompt structural helpers.
3. Task 3: consolidate normalization/list helpers.
4. Task 4: consolidate admin helper duplication.
5. Task 6: reconfirm previously flagged dead-code candidates remain used.
6. Task 5: handle path-formatting only if exact parity is confirmed.
7. Task 7: run closeout validation.

Task 5 is intentionally late because path-formatting helpers are more likely to have small domain-specific differences than the other exact duplicate clusters.
