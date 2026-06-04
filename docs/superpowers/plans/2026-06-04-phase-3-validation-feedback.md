# Phase 3: Validation Feedback & Diagnostics — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce a stable, consistent, joinable validation-reason signal across the four executable recommendation parsers (block, style, template, template-part), captured on the records the live learning loop already join-keys and ranking-stamps.

**Architecture:** Approach B — an additive normalized `validationReasons` projection. A new canonical vocabulary (`shared/validation-reasons.json` → `ValidationReason.php` + a JS module) is the foundation; each parser attaches `validationReasons` to its suggestions; the scorer, outcome records, metrics, and audit diagnostic consume it. Block is pure pass-through from its existing `rejectedOperations` (zero regression). Generation reasons ride `shown.rankingSet` and a new sibling `outcome.validationReason` on engaged outcomes; apply-time blocks ride `validation_blocked.reason`.

**Tech Stack:** PHP 8.0+ (PSR-4 `FlavorAgent\`, PHPUnit), JavaScript (`@wordpress/scripts`, Jest), WordPress Abilities API.

**Source spec:** `docs/superpowers/specs/2026-06-04-phase-3-validation-feedback-design.md` (rounds 4–5 resolved). Read it before starting.

**Conventions for every task:**
- TDD: write the failing test first, watch it fail, implement minimally, watch it pass, commit.
- PHP single-file run: `vendor/bin/phpunit --filter <TestClass>` (or `composer run test:php -- --filter '<TestClass>'`).
- JS single-file run: `npm run test:unit -- --runInBand <path>`.
- After each phase, run `node scripts/verify.js --skip-e2e` and inspect `output/verify/summary.json`.
- All commits end with the repo's `Co-Authored-By` trailer (see `CLAUDE.md` / environment). Branch off `master` before the first commit: `git checkout -b feat/phase-3-validation-feedback`.
- `build/` is gitignored — never commit it. Run `npm run build` only when manually verifying in WP.

**Vocabulary version:** `validation-reasons-v1`. Severities: `rejected` (op dropped from executable set), `downgraded` (suggestion kept advisory-only), `no_op` (proposed change equals current state).

---

## Phase 0 — Vocabulary foundation

Everything downstream depends on this phase. Do it first.

### Task 1: Canonical vocabulary JSON + `ValidationReason` PHP class

**Files:**
- Create: `shared/validation-reasons.json`
- Create: `inc/Support/ValidationReason.php`
- Test: `tests/phpunit/ValidationReasonTest.php`

- [ ] **Step 1: Create the canonical vocabulary file**

Create `shared/validation-reasons.json`. Codes name what existing validators detect; block's 15 are adopted verbatim. `surfaces` is informational (the metric filters on outcome surfaces, not this).

```json
{
  "version": "validation-reasons-v1",
  "reasons": {
    "block_structural_actions_disabled": { "severity": "rejected", "surfaces": ["block"] },
    "multi_operation_unsupported": { "severity": "rejected", "surfaces": ["block"] },
    "invalid_operation_payload": { "severity": "rejected", "surfaces": ["block"] },
    "unknown_operation_type": { "severity": "rejected", "surfaces": ["block", "template", "template-part"] },
    "missing_pattern_name": { "severity": "rejected", "surfaces": ["block"] },
    "pattern_not_available": { "severity": "rejected", "surfaces": ["block"] },
    "missing_target_client_id": { "severity": "rejected", "surfaces": ["block"] },
    "stale_target": { "severity": "rejected", "surfaces": ["block"] },
    "cross_surface_target": { "severity": "rejected", "surfaces": ["block"] },
    "invalid_target_type": { "severity": "rejected", "surfaces": ["block"] },
    "locked_target": { "severity": "rejected", "surfaces": ["block"] },
    "content_only_target": { "severity": "rejected", "surfaces": ["block"] },
    "invalid_insertion_position": { "severity": "rejected", "surfaces": ["block"] },
    "action_not_allowed": { "severity": "rejected", "surfaces": ["block"] },
    "client_server_operation_mismatch": { "severity": "rejected", "surfaces": ["block"] },
    "unsupported_scope": { "severity": "rejected", "surfaces": ["style"] },
    "unsupported_path": { "severity": "rejected", "surfaces": ["style"] },
    "failed_contrast": { "severity": "downgraded", "surfaces": ["style"] },
    "preset_required": { "severity": "rejected", "surfaces": ["style"] },
    "preset_metadata_mismatch": { "severity": "rejected", "surfaces": ["style"] },
    "preset_reference_mismatch": { "severity": "rejected", "surfaces": ["style"] },
    "preset_unavailable": { "severity": "rejected", "surfaces": ["style"] },
    "invalid_freeform_value": { "severity": "rejected", "surfaces": ["style"] },
    "missing_style_book_target": { "severity": "rejected", "surfaces": ["style"] },
    "unavailable_variation": { "severity": "rejected", "surfaces": ["style"] },
    "no_executable_operations": { "severity": "rejected", "surfaces": ["style", "template", "template-part"] },
    "invalid_template_area": { "severity": "rejected", "surfaces": ["template", "template-part"] },
    "no_assigned_part": { "severity": "rejected", "surfaces": ["template"] },
    "duplicate_area_mutation": { "severity": "rejected", "surfaces": ["template"] },
    "area_mismatch": { "severity": "rejected", "surfaces": ["template"] },
    "same_slug_no_op": { "severity": "no_op", "surfaces": ["template"] },
    "invalid_anchor": { "severity": "rejected", "surfaces": ["template", "template-part"] },
    "invalid_placement": { "severity": "rejected", "surfaces": ["template", "template-part"] },
    "unknown_pattern": { "severity": "rejected", "surfaces": ["template", "template-part"] },
    "repeated_pattern_insert": { "severity": "rejected", "surfaces": ["template", "template-part"] },
    "malformed_operation": { "severity": "rejected", "surfaces": ["template", "template-part"] },
    "overlapping_block_paths": { "severity": "rejected", "surfaces": ["template-part"] },
    "too_many_operations": { "severity": "rejected", "surfaces": ["template-part"] },
    "advisory_only": { "severity": "downgraded", "surfaces": ["block"] },
    "missing_structural_context": { "severity": "rejected", "surfaces": ["block"] },
    "operation_validation_failed": { "severity": "rejected", "surfaces": ["block", "style", "template", "template-part"] },
    "no_op": { "severity": "no_op", "surfaces": ["block", "style", "template", "template-part"] }
  }
}
```

- [ ] **Step 2: Write the failing test for `ValidationReason`**

Create `tests/phpunit/ValidationReasonTest.php`:

```php
<?php
declare(strict_types=1);

use FlavorAgent\Support\ValidationReason;
use PHPUnit\Framework\TestCase;

final class ValidationReasonTest extends TestCase {

	public function test_version_constant(): void {
		$this->assertSame( 'validation-reasons-v1', ValidationReason::VERSION );
	}

	public function test_normalize_keeps_known_codes_and_assigns_default_severity(): void {
		$out = ValidationReason::normalize( [
			[ 'code' => 'Unsupported Scope', 'message' => 'x' ],
		] );

		$this->assertSame( 'unsupported_scope', $out[0]['code'] );
		$this->assertSame( 'rejected', $out[0]['severity'] );
		$this->assertSame( 'x', $out[0]['message'] );
	}

	public function test_normalize_respects_explicit_severity_when_valid(): void {
		$out = ValidationReason::normalize( [
			[ 'code' => 'failed_contrast', 'severity' => 'downgraded' ],
		] );
		$this->assertSame( 'downgraded', $out[0]['severity'] );
	}

	public function test_normalize_drops_blank_codes_and_bounds_message(): void {
		$out = ValidationReason::normalize( [
			[ 'code' => '', 'message' => 'dropped' ],
			[ 'code' => 'no_op', 'message' => str_repeat( 'a', 500 ) ],
		] );

		$this->assertCount( 1, $out );
		$this->assertSame( 'no_op', $out[0]['code'] );
		$this->assertLessThanOrEqual( 191, strlen( $out[0]['message'] ) );
	}

	public function test_primary_picks_highest_severity_then_first(): void {
		$primary = ValidationReason::primary( [
			[ 'code' => 'failed_contrast', 'severity' => 'downgraded' ],
			[ 'code' => 'unsupported_path', 'severity' => 'rejected' ],
			[ 'code' => 'no_op', 'severity' => 'no_op' ],
		] );

		$this->assertSame( 'unsupported_path', $primary['code'] );
	}

	public function test_primary_returns_empty_array_for_no_reasons(): void {
		$this->assertSame( [], ValidationReason::primary( [] ) );
	}
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ValidationReasonTest`
Expected: FAIL — `Class "FlavorAgent\Support\ValidationReason" not found`.

- [ ] **Step 4: Implement `ValidationReason`**

Create `inc/Support/ValidationReason.php`. Severity ranking: `rejected` (2) > `downgraded` (1) > `no_op` (0).

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class ValidationReason {

	public const VERSION = 'validation-reasons-v1';

	public const SEVERITY_REJECTED   = 'rejected';
	public const SEVERITY_DOWNGRADED = 'downgraded';
	public const SEVERITY_NO_OP      = 'no_op';

	private const SEVERITY_RANK = [
		self::SEVERITY_REJECTED   => 2,
		self::SEVERITY_DOWNGRADED => 1,
		self::SEVERITY_NO_OP      => 0,
	];

	private const MAX_MESSAGE_LENGTH = 191;

	/**
	 * Embedded copy of shared/validation-reasons.json. A parity test
	 * (ValidationReasonParityTest) asserts this matches the JSON byte-for-code.
	 *
	 * @var array<string, string> code => default severity
	 */
	private const VOCABULARY = [
		'block_structural_actions_disabled' => self::SEVERITY_REJECTED,
		'multi_operation_unsupported'       => self::SEVERITY_REJECTED,
		'invalid_operation_payload'         => self::SEVERITY_REJECTED,
		'unknown_operation_type'            => self::SEVERITY_REJECTED,
		'missing_pattern_name'              => self::SEVERITY_REJECTED,
		'pattern_not_available'             => self::SEVERITY_REJECTED,
		'missing_target_client_id'          => self::SEVERITY_REJECTED,
		'stale_target'                      => self::SEVERITY_REJECTED,
		'cross_surface_target'              => self::SEVERITY_REJECTED,
		'invalid_target_type'               => self::SEVERITY_REJECTED,
		'locked_target'                     => self::SEVERITY_REJECTED,
		'content_only_target'               => self::SEVERITY_REJECTED,
		'invalid_insertion_position'        => self::SEVERITY_REJECTED,
		'action_not_allowed'                => self::SEVERITY_REJECTED,
		'client_server_operation_mismatch'  => self::SEVERITY_REJECTED,
		'unsupported_scope'                 => self::SEVERITY_REJECTED,
		'unsupported_path'                  => self::SEVERITY_REJECTED,
		'failed_contrast'                   => self::SEVERITY_DOWNGRADED,
		'preset_required'                   => self::SEVERITY_REJECTED,
		'preset_metadata_mismatch'          => self::SEVERITY_REJECTED,
		'preset_reference_mismatch'         => self::SEVERITY_REJECTED,
		'preset_unavailable'                => self::SEVERITY_REJECTED,
		'invalid_freeform_value'            => self::SEVERITY_REJECTED,
		'missing_style_book_target'         => self::SEVERITY_REJECTED,
		'unavailable_variation'             => self::SEVERITY_REJECTED,
		'no_executable_operations'          => self::SEVERITY_REJECTED,
		'invalid_template_area'             => self::SEVERITY_REJECTED,
		'no_assigned_part'                  => self::SEVERITY_REJECTED,
		'duplicate_area_mutation'           => self::SEVERITY_REJECTED,
		'area_mismatch'                     => self::SEVERITY_REJECTED,
		'same_slug_no_op'                   => self::SEVERITY_NO_OP,
		'invalid_anchor'                    => self::SEVERITY_REJECTED,
		'invalid_placement'                 => self::SEVERITY_REJECTED,
		'unknown_pattern'                   => self::SEVERITY_REJECTED,
		'repeated_pattern_insert'           => self::SEVERITY_REJECTED,
		'malformed_operation'               => self::SEVERITY_REJECTED,
		'overlapping_block_paths'           => self::SEVERITY_REJECTED,
		'too_many_operations'               => self::SEVERITY_REJECTED,
		'advisory_only'                     => self::SEVERITY_DOWNGRADED,
		'missing_structural_context'        => self::SEVERITY_REJECTED,
		'operation_validation_failed'       => self::SEVERITY_REJECTED,
		'no_op'                             => self::SEVERITY_NO_OP,
	];

	/**
	 * @return array<string, string>
	 */
	public static function vocabulary(): array {
		return self::VOCABULARY;
	}

	private static function normalize_code( mixed $value ): string {
		$code = strtolower( (string) $value );
		$code = (string) preg_replace( '/[^a-z0-9_-]+/', '_', $code );

		return substr( trim( $code, '_' ), 0, 64 );
	}

	private static function normalize_severity( string $code, mixed $explicit ): string {
		$explicit = is_string( $explicit ) ? $explicit : '';

		if ( isset( self::SEVERITY_RANK[ $explicit ] ) ) {
			return $explicit;
		}

		return self::VOCABULARY[ $code ] ?? self::SEVERITY_REJECTED;
	}

	/**
	 * @param array<int, mixed> $raw
	 * @return array<int, array{code: string, severity: string, message?: string}>
	 */
	public static function normalize( array $raw ): array {
		$out = [];

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$code = self::normalize_code( $entry['code'] ?? '' );

			if ( '' === $code ) {
				continue;
			}

			$reason = [
				'code'     => $code,
				'severity' => self::normalize_severity( $code, $entry['severity'] ?? null ),
			];

			$message = isset( $entry['message'] ) ? sanitize_text_field( (string) $entry['message'] ) : '';
			if ( '' !== $message ) {
				$reason['message'] = substr( $message, 0, self::MAX_MESSAGE_LENGTH );
			}

			$out[] = $reason;
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $reasons
	 * @return array{code: string, severity: string}|array{}
	 */
	public static function primary( array $reasons ): array {
		$normalized = self::normalize( $reasons );

		if ( [] === $normalized ) {
			return [];
		}

		usort(
			$normalized,
			static fn( array $a, array $b ): int =>
				( self::SEVERITY_RANK[ $b['severity'] ] ?? 0 ) <=> ( self::SEVERITY_RANK[ $a['severity'] ] ?? 0 )
		);

		return [
			'code'     => $normalized[0]['code'],
			'severity' => $normalized[0]['severity'],
		];
	}
}
```

> Note: `usort` is not stable across all PHP versions, but ties only occur within the same severity and the test asserts highest-severity-then-first; if a strict stable order is required, decorate-sort-undecorate by index. Keep it simple unless the test fails.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ValidationReasonTest`
Expected: PASS (6 assertions green). If `test_primary_picks_highest_severity_then_first` is flaky, switch `usort` to a stable index-decorated sort.

- [ ] **Step 6: Commit**

```bash
git add shared/validation-reasons.json inc/Support/ValidationReason.php tests/phpunit/ValidationReasonTest.php
git commit -m "feat: add ValidationReason vocabulary + normalizer (validation-reasons-v1)"
```

---

### Task 2: JS vocabulary module + cross-language parity tests

**Files:**
- Create: `src/utils/validation-reasons.js`
- Create: `tests/phpunit/ValidationReasonParityTest.php`
- Test (JS): `src/utils/__tests__/validation-reasons.test.js`

- [ ] **Step 1: Write the failing JS test**

Create `src/utils/__tests__/validation-reasons.test.js`:

```javascript
import {
	VALIDATION_REASONS_VERSION,
	getValidationReasonSeverity,
	primaryValidationReason,
} from '../validation-reasons';
import {
	BLOCK_OPERATION_ERROR_STALE_TARGET,
	BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED,
} from '../block-operation-catalog';

describe( 'validation-reasons vocabulary', () => {
	it( 'exposes the v1 version', () => {
		expect( VALIDATION_REASONS_VERSION ).toBe( 'validation-reasons-v1' );
	} );

	it( 'contains every block catalog code (cross-language parity)', () => {
		// The vocabulary must be a superset of the block codes that the
		// client re-validation can emit on validation_blocked outcomes.
		expect(
			getValidationReasonSeverity( BLOCK_OPERATION_ERROR_STALE_TARGET )
		).toBe( 'rejected' );
		expect(
			getValidationReasonSeverity( BLOCK_OPERATION_ERROR_ACTION_NOT_ALLOWED )
		).toBe( 'rejected' );
	} );

	it( 'resolves the primary reason by highest severity then first', () => {
		expect(
			primaryValidationReason( [
				{ code: 'failed_contrast', severity: 'downgraded' },
				{ code: 'unsupported_path' },
			] )?.code
		).toBe( 'unsupported_path' );
	} );

	it( 'returns null primary for an empty list', () => {
		expect( primaryValidationReason( [] ) ).toBeNull();
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/utils/__tests__/validation-reasons.test.js`
Expected: FAIL — cannot resolve `../validation-reasons`.

- [ ] **Step 3: Implement the JS module (imports the JSON — single source, no JS copy to drift)**

Create `src/utils/validation-reasons.js`:

```javascript
import vocabulary from '../../shared/validation-reasons.json';

export const VALIDATION_REASONS_VERSION = vocabulary.version;

const SEVERITY_RANK = { rejected: 2, downgraded: 1, no_op: 0 };

/**
 * @param {string} code Reason code.
 * @return {string} Severity, defaulting to 'rejected' for unknown codes.
 */
export function getValidationReasonSeverity( code ) {
	return vocabulary.reasons?.[ code ]?.severity || 'rejected';
}

/**
 * @param {Array<{code: string, severity?: string}>} reasons Reason list.
 * @return {{code: string, severity: string}|null} Highest-severity reason, else null.
 */
export function primaryValidationReason( reasons = [] ) {
	if ( ! Array.isArray( reasons ) || reasons.length === 0 ) {
		return null;
	}

	const ranked = reasons
		.filter( ( r ) => r && typeof r.code === 'string' && r.code )
		.map( ( r ) => ( {
			code: r.code,
			severity: r.severity || getValidationReasonSeverity( r.code ),
		} ) );

	if ( ranked.length === 0 ) {
		return null;
	}

	return ranked.reduce( ( best, current ) =>
		( SEVERITY_RANK[ current.severity ] ?? 0 ) >
		( SEVERITY_RANK[ best.severity ] ?? 0 )
			? current
			: best
	);
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `npm run test:unit -- --runInBand src/utils/__tests__/validation-reasons.test.js`
Expected: PASS.

- [ ] **Step 5: Write the PHP parity test**

Create `tests/phpunit/ValidationReasonParityTest.php`. It asserts the embedded PHP vocabulary equals the JSON, and that every `BlockOperationValidator` error code is in the vocabulary.

```php
<?php
declare(strict_types=1);

use FlavorAgent\Context\BlockOperationValidator;
use FlavorAgent\Support\ValidationReason;
use PHPUnit\Framework\TestCase;

final class ValidationReasonParityTest extends TestCase {

	/** @return array<string, string> */
	private function jsonReasons(): array {
		$path = dirname( __DIR__, 2 ) . '/shared/validation-reasons.json';
		$data = json_decode( (string) file_get_contents( $path ), true );

		$map = [];
		foreach ( (array) ( $data['reasons'] ?? [] ) as $code => $meta ) {
			$map[ (string) $code ] = (string) ( $meta['severity'] ?? '' );
		}
		ksort( $map );

		return $map;
	}

	public function test_php_vocabulary_matches_json(): void {
		$php = ValidationReason::vocabulary();
		ksort( $php );

		$this->assertSame( $this->jsonReasons(), $php );
	}

	public function test_version_matches_json(): void {
		$path = dirname( __DIR__, 2 ) . '/shared/validation-reasons.json';
		$data = json_decode( (string) file_get_contents( $path ), true );

		$this->assertSame( $data['version'], ValidationReason::VERSION );
	}

	public function test_block_codes_are_a_subset_of_the_vocabulary(): void {
		$reflection = new ReflectionClass( BlockOperationValidator::class );
		foreach ( $reflection->getConstants() as $name => $value ) {
			if ( str_starts_with( $name, 'ERROR_' ) ) {
				$this->assertArrayHasKey(
					(string) $value,
					ValidationReason::vocabulary(),
					"Block code {$value} missing from validation-reasons vocabulary"
				);
			}
		}
	}
}
```

- [ ] **Step 6: Run the parity test**

Run: `vendor/bin/phpunit --filter ValidationReasonParityTest`
Expected: PASS. If `test_php_vocabulary_matches_json` fails, reconcile `ValidationReason::VOCABULARY` and `shared/validation-reasons.json` until identical.

- [ ] **Step 7: Commit**

```bash
git add src/utils/validation-reasons.js src/utils/__tests__/validation-reasons.test.js tests/phpunit/ValidationReasonParityTest.php
git commit -m "feat: add JS validation-reasons module + PHP/JS vocabulary parity tests"
```

---

## Phase 1 — Block pass-through + schema exposure (zero regression)

### Task 3: Derive `validationReasons` on block suggestions (pass-through)

**Files:**
- Modify: `inc/LLM/Prompt.php:1195-1201` (`enforce_block_operation_context_rules`)
- Test: `tests/phpunit/BlockAbilitiesTest.php` (add a case) or `tests/phpunit/PromptTest.php` if present

- [ ] **Step 1: Write the failing test**

Add to the block prompt test suite (use the existing test class that exercises `Prompt::enforce_block_context_rules`; if none, create `tests/phpunit/PromptValidationReasonsTest.php`):

```php
public function test_block_suggestion_derives_validation_reasons_from_rejected_operations(): void {
	$payload = [
		'block' => [
			[
				'label'              => 'Insert hero',
				'proposedOperations' => [
					[ 'type' => 'insert_pattern', 'patternName' => 'x/y', 'targetClientId' => 'abc', 'position' => 'insert_before' ],
				],
			],
		],
	];

	// Structural actions disabled => the op is rejected with that code.
	$result = \FlavorAgent\LLM\Prompt::enforce_block_context_rules(
		$payload,
		[ 'name' => 'core/group' ],
		[],
		[ 'enableBlockStructuralActions' => false, 'targetClientId' => 'abc' ]
	);

	$suggestion = $result['block'][0];
	$this->assertSame(
		[ 'block_structural_actions_disabled' ],
		array_column( $suggestion['validationReasons'], 'code' )
	);
	$this->assertSame( 'rejected', $suggestion['validationReasons'][0]['severity'] );
	// rejectedOperations is untouched (zero regression).
	$this->assertNotEmpty( $suggestion['rejectedOperations'] );
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter test_block_suggestion_derives_validation_reasons_from_rejected_operations`
Expected: FAIL — `validationReasons` key not set.

- [ ] **Step 3: Implement the pass-through derive**

In `inc/LLM/Prompt.php`, locate `enforce_block_operation_context_rules` (`:1195-1201`) where `operations`/`rejectedOperations` are set after `BlockOperationValidator::validate_sequence()`. Add the derive immediately after:

```php
$suggestion['operations']         = $validation['operations'];
$suggestion['rejectedOperations'] = $validation['rejectedOperations'];
// Pass-through: block codes ARE the vocabulary (zero regression).
$suggestion['validationReasons']  = \FlavorAgent\Support\ValidationReason::normalize(
	array_map(
		static fn( array $rejection ): array => [
			'code'    => $rejection['code'] ?? '',
			'message' => $rejection['message'] ?? '',
		],
		$validation['rejectedOperations']
	)
);
```

Add `use FlavorAgent\Support\ValidationReason;` to the file header if not already imported (then call `ValidationReason::normalize(...)`).

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter test_block_suggestion_derives_validation_reasons_from_rejected_operations`
Expected: PASS.

- [ ] **Step 5: Regression-guard the existing block suite**

Run: `vendor/bin/phpunit --filter BlockAbilitiesTest`
Expected: PASS (no existing assertion changes).

- [ ] **Step 6: Commit**

```bash
git add inc/LLM/Prompt.php tests/phpunit/
git commit -m "feat: derive validationReasons on block suggestions from rejectedOperations"
```

---

### Task 4: Expose `validationReasons` on output schemas (bounded string)

**Files:**
- Modify: `inc/Abilities/Registration.php` (block schema near `:2071`; the shared `$suggestion_schema` used by style/template/template-part)
- Test: `tests/phpunit/RegistrationSchemaTest.php` (create if absent)

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RegistrationSchemaTest extends TestCase {

	/** @return array<string, mixed> */
	private function styleOutputSchema(): array {
		// Use the public accessor the abilities use to build their output schema.
		// (If Registration exposes a private builder, expose a test seam or call
		// the ability's get_output_schema().)
		return \FlavorAgent\Abilities\Registration::style_output_schema_for_tests();
	}

	public function test_validation_reasons_is_a_bounded_string_code_array(): void {
		$schema = $this->styleOutputSchema();
		$item   = $schema['properties']['styles']['items']['properties']['validationReasons']['items'];

		$this->assertSame( 'array', $schema['properties']['styles']['items']['properties']['validationReasons']['type'] );
		$this->assertSame( 'string', $item['properties']['code']['type'] );
		$this->assertSame( 64, $item['properties']['code']['maxLength'] );
		$this->assertArrayNotHasKey( 'enum', $item['properties']['code'] ); // bounded string, NOT enum
	}
}
```

> If `Registration` builds schemas in private methods, add a thin static `*_for_tests()` accessor (or assert via the registered ability's `get_output_schema()`). Keep the accessor minimal.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter RegistrationSchemaTest`
Expected: FAIL — `validationReasons` not in schema.

- [ ] **Step 3: Implement the schema fragment**

In `inc/Abilities/Registration.php`, define a reusable fragment and add it to each executable suggestion schema (the shared `$suggestion_schema` and `$block_suggestion_schema`). `code` is a **bounded string, not an enum** (strict ajv must never fail the whole payload on a new code):

```php
$validation_reasons_schema = [
	'type'  => 'array',
	'items' => [
		'type'       => 'object',
		'properties' => [
			'code'     => [ 'type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z0-9_-]+$' ],
			'severity' => [ 'type' => 'string', 'enum' => [ 'rejected', 'downgraded', 'no_op' ] ],
			'message'  => [ 'type' => 'string' ],
		],
	],
];

// Add to each executable suggestion schema:
$suggestion_schema['properties']['validationReasons']       = $validation_reasons_schema;
$block_suggestion_schema['properties']['validationReasons'] = $validation_reasons_schema;
```

(`severity` may safely be an enum — it is a fixed 3-value set that never grows.)

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter RegistrationSchemaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add inc/Abilities/Registration.php tests/phpunit/RegistrationSchemaTest.php
git commit -m "feat: expose validationReasons (bounded-string code) on executable output schemas"
```

---

## Phase 2 — Generation-time parsers (return-contract changes)

> These three tasks change validators from "return survivors" to "return survivors + reasons." Each parser's caller must thread the reasons onto the suggestion `entry`. Only one reason per branch is needed (the outcome keeps a single primary code), but **every deterministic branch must map to a specific code** — `operation_validation_failed` is reserved for genuinely unmappable failures.

### Task 5: StylePrompt — per-branch codes + contrast merge

**Files:**
- Modify: `inc/LLM/StylePrompt.php` — `validate_operations()` (`:1014-1163`) and `validate_suggestions()` (`:773-807`)
- Test: `tests/phpunit/StyleAbilitiesTest.php` (or `StylePromptValidationReasonsTest.php`)

- [ ] **Step 1: Write the failing test (data-provider covers every branch)**

```php
/**
 * @dataProvider styleRejectionBranches
 */
public function test_each_style_validation_branch_maps_to_a_specific_code( array $operation, array $context, string $expectedCode ): void {
	$result = \FlavorAgent\LLM\StylePrompt::validate_operations_for_tests( [ $operation ], $context );
	$codes  = array_column( $result['reasons'], 'code' );

	$this->assertContains( $expectedCode, $codes );
	$this->assertNotContains( 'operation_validation_failed', $codes );
}

public function styleRejectionBranches(): array {
	$globalScope = [ 'scope' => [ 'surface' => 'global-styles' ], 'styleContext' => [ 'supportedStylePaths' => [ [ 'path' => [ 'color', 'text' ], 'valueSource' => 'color' ] ] ] ];

	return [
		'set_block_styles on global-styles' => [
			[ 'type' => 'set_block_styles', 'blockName' => 'core/group', 'path' => [ 'color', 'text' ] ],
			$globalScope,
			'unsupported_scope',
		],
		'unsupported path' => [
			[ 'type' => 'set_styles', 'path' => [ 'spacing', 'blockGap' ], 'value' => '1rem' ],
			$globalScope,
			'unsupported_path',
		],
		'preset required but freeform given' => [
			[ 'type' => 'set_styles', 'path' => [ 'color', 'text' ], 'value' => '#abcdef', 'valueType' => 'freeform' ],
			$globalScope,
			'preset_required',
		],
		// ...one row per branch: preset_metadata_mismatch, preset_reference_mismatch,
		// preset_unavailable, invalid_freeform_value, missing_style_book_target,
		// unavailable_variation, unsupported_scope (style-book set_styles).
	];
}
```

> Fill the data provider with one row per rejection branch in `validate_operations()`. The branches and their codes (see spec Change 3) are below in Step 3 — every `continue` becomes a coded reason.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter test_each_style_validation_branch_maps_to_a_specific_code`
Expected: FAIL — `validate_operations_for_tests` undefined / no `reasons` key.

- [ ] **Step 3: Change `validate_operations()` to return `{operations, reasons}`**

Replace each bare `continue;` in `validate_operations()` (`:1027-1163`) with a coded-reason push, then `continue`. Add a `$reasons = []` accumulator and change the return. Branch→code map (split the compound `set_block_styles` continues):

| Line (HEAD) | Condition | Code |
| --- | --- | --- |
| `:1036` | `set_styles` on `style-book` | `unsupported_scope` |
| `:1041` | `set_block_styles` not on `style-book` | `unsupported_scope` |
| `:1047` | block-name missing/mismatch (Style Book) | `missing_style_book_target` |
| `:1065` | path not in `supportedStylePaths` | `unsupported_path` |
| `:1076` | freeform value invalid | `invalid_freeform_value` |
| `:1098` | not preset / no slug where preset required | `preset_required` |
| `:1102` | value source ≠ preset type | `preset_metadata_mismatch` |
| `:1108` | preset value unparseable | `preset_reference_mismatch` |
| `:1112` | parsed type mismatch | `preset_reference_mismatch` |
| `:1116` | parsed slug mismatch | `preset_reference_mismatch` |
| `:1120` | preset not in theme | `preset_unavailable` |
| variation (`:1136`) | `set_theme_variation` on style-book | `unsupported_scope` |
| variation (`:1142`) | variation not resolvable | `unavailable_variation` |

Example transform (the path branch at `:1064`):

```php
if ( [] === $path || [] === $path_entry ) {
	$reasons[] = [ 'code' => 'unsupported_path' ];
	continue;
}
```

Apply the same pattern to every branch above. At the end:

```php
$styles = [] !== $validated_variation
	? array_merge( [ $validated_variation ], $validated_styles )
	: $validated_styles;

return [ 'operations' => $styles, 'reasons' => ValidationReason::normalize( $reasons ) ];
```

Add a test seam and keep the private signature internal:

```php
public static function validate_operations_for_tests( array $operations, array $context ): array {
	return self::validate_operations( $operations, $context );
}
```

Add `use FlavorAgent\Support\ValidationReason;` to the file header.

- [ ] **Step 4: Update `validate_suggestions()` to consume the new shape + merge contrast**

In `validate_suggestions()` (`:782-807`), `validate_operations()` now returns an array with `operations`/`reasons`. Update the call and add a `failed_contrast` reason when contrast fails, then attach to the entry:

```php
$operations_result    = self::validate_operations( $input_operations, $context );
$operations           = $operations_result['operations'];
$operation_dropped    = count( $input_operations ) !== count( $operations );
$contrast_result      = StyleContrastValidator::evaluate( $operations, $context );
$contrast_failed      = ! $contrast_result['passed'];

$reasons = $operations_result['reasons'];
if ( $contrast_failed ) {
	$reasons[] = [ 'code' => 'failed_contrast', 'severity' => 'downgraded' ];
}
if ( [] === $operations && [] !== $input_operations && [] === $reasons ) {
	$reasons[] = [ 'code' => 'no_executable_operations' ];
}

// ...existing $entry assembly at :795-807...
$entry['validationReasons'] = ValidationReason::normalize( $reasons );
```

- [ ] **Step 5: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter 'StyleAbilitiesTest|test_each_style_validation_branch'`
Expected: PASS. Add any missing data-provider rows until every branch is covered.

- [ ] **Step 6: Commit**

```bash
git add inc/LLM/StylePrompt.php tests/phpunit/
git commit -m "feat: emit per-branch validationReasons from StylePrompt (generation + contrast)"
```

---

### Task 6: TemplatePrompt — per-branch codes in `validate_template_operations()`

**Files:**
- Modify: `inc/LLM/TemplatePrompt.php` — `validate_template_operations()` (`:1128-1324`), `invalid_template_operations_result()` (`:1110`)
- Test: `tests/phpunit/TemplatePromptTest.php` (create if absent)

- [ ] **Step 1: Write the failing data-provider test**

```php
/**
 * @dataProvider templateRejectionBranches
 */
public function test_each_template_operation_branch_maps_to_a_specific_code( array $operations, string $expectedCode ): void {
	$result = \FlavorAgent\LLM\TemplatePrompt::validate_template_operations_for_tests(
		$operations,
		$this->lookups() // unused/assigned/allowed/empty/pattern/block/anchor lookups
	);

	$this->assertTrue( $result['invalid'] );
	$this->assertSame( $expectedCode, $result['code'] );
	$this->assertNotSame( 'operation_validation_failed', $result['code'] );
}
```

Provide one row per branch (codes below).

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter test_each_template_operation_branch_maps_to_a_specific_code`
Expected: FAIL — `validate_template_operations_for_tests` undefined / no `code` key.

- [ ] **Step 3: Change the return shape to `{operations, invalid, code}` with per-branch codes**

Update `invalid_template_operations_result()` to accept a code:

```php
private static function invalid_template_operations_result( string $code = 'operation_validation_failed' ): array {
	return [ 'operations' => [], 'invalid' => true, 'code' => $code ];
}
```

Then replace each early return in `validate_template_operations()` (`:1142-1316`) with the specific code. **Split the compound conditions** flagged in the spec:

| Line (HEAD) | Branch | Code |
| --- | --- | --- |
| `:1143` | non-array operation | `malformed_operation` |
| `:1153` | assign: area already mutated | `duplicate_area_mutation` |
| `:1157-1163` (split) | assign: not a valid unused part / area not in allowed | `invalid_template_area` |
| `:1157-1163` (split) | assign: area not empty **or** already assigned | `duplicate_area_mutation` |
| `:1194` | replace: no assigned part / empty currentSlug | `no_assigned_part` |
| `:1202` | replace: area empty after derivation | `invalid_template_area` |
| `:1206` | replace: area already mutated | `duplicate_area_mutation` |
| `:1210` | replace: not a valid unused part | `invalid_template_area` |
| `:1217` | replace: assigned area ≠ op area | `area_mismatch` |
| `:1221` | replace: currentSlug === slug | `same_slug_no_op` |
| `:1248-1254` (split) | insert: empty pattern name | `unknown_pattern` |
| `:1248-1254` (split) | insert: empty placement | `invalid_placement` |
| `:1248-1254` (split) | insert: pattern not in lookup | `unknown_pattern` |
| `:1264` | insert: placement not allowed | `invalid_placement` |
| `:1268` | insert: malformed targetPath | `invalid_anchor` |
| `:1278` | insert: anchored path missing/unknown | `invalid_anchor` |
| `:1285` | insert: start/end anchor missing | `invalid_anchor` |
| `:1289` | insert: second pattern insert | `repeated_pattern_insert` |
| `:1316` | unknown operation type | `unknown_operation_type` |

Example (the compound assign branch split):

```php
case self::TEMPLATE_OPERATION_ASSIGN:
	$slug = sanitize_key( (string) ( $operation['slug'] ?? '' ) );
	$area = sanitize_key( (string) ( $operation['area'] ?? '' ) );

	if ( isset( $state['mutatedAreas'][ $area ] ) ) {
		return self::invalid_template_operations_result( 'duplicate_area_mutation' );
	}
	if ( ! self::is_valid_unused_template_part( $slug, $area, $unused_part_lookup, $allowed_area_lookup ) ) {
		return self::invalid_template_operations_result( 'invalid_template_area' );
	}
	if ( ! isset( $state['emptyAreas'][ $area ] ) || isset( $state['byArea'][ $area ] ) ) {
		return self::invalid_template_operations_result( 'duplicate_area_mutation' );
	}
	// ...existing success path...
```

Update the success return to include `'code' => ''`:

```php
return [ 'operations' => $valid, 'invalid' => false, 'code' => '' ];
```

Add the test seam:

```php
public static function validate_template_operations_for_tests( array $operations, array $lookups ): array {
	return self::validate_template_operations(
		$operations,
		$lookups['unused'], $lookups['assigned'], $lookups['allowed'],
		$lookups['empty'], $lookups['pattern'], $lookups['block'], $lookups['anchor']
	);
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter test_each_template_operation_branch_maps_to_a_specific_code`
Expected: PASS for all data-provider rows.

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/TemplatePrompt.php tests/phpunit/TemplatePromptTest.php
git commit -m "feat: per-branch validationReasons in TemplatePrompt::validate_template_operations"
```

---

### Task 7: TemplatePrompt — fix the survivorship discard + code-bearing derive

**Files:**
- Modify: `inc/LLM/TemplatePrompt.php` — `validate_template_suggestions()` (`:601-675`), `derive_template_operations()` (`:1332-1382`)
- Test: `tests/phpunit/TemplatePromptTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_invalid_operations_keep_advisory_remnant_with_reasons(): void {
	// A suggestion with an invalid operation but valid templateParts must be KEPT
	// as an advisory remnant carrying validationReasons (not discarded).
	$suggestions = [
		[
			'label'         => 'Footer composition',
			'operations'    => [ [ 'type' => 'assign_template_part', 'slug' => 'unknown', 'area' => 'footer' ] ],
			'templateParts' => [ [ 'slug' => 'footer-minimal', 'area' => 'footer', 'reason' => 'fills footer' ] ],
		],
	];

	$out = \FlavorAgent\LLM\TemplatePrompt::validate_template_suggestions_for_tests( $suggestions, $this->lookups() );

	$this->assertCount( 1, $out ); // NOT discarded
	$this->assertSame( [], $out[0]['operations'] ); // operations emptied
	$this->assertNotEmpty( $out[0]['templateParts'] ); // advisory remnant kept
	$this->assertNotEmpty( $out[0]['validationReasons'] ); // reason recorded
}

public function test_derive_duplicate_area_returns_code(): void {
	$result = \FlavorAgent\LLM\TemplatePrompt::derive_template_operations_for_tests(
		[ [ 'slug' => 'a', 'area' => 'header' ], [ 'slug' => 'b', 'area' => 'header' ] ],
		$this->assignedLookup(),
		$this->emptyLookup()
	);
	$this->assertTrue( $result['invalid'] );
	$this->assertSame( 'duplicate_area_mutation', $result['code'] );
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter 'test_invalid_operations_keep_advisory_remnant_with_reasons|test_derive_duplicate_area_returns_code'`
Expected: FAIL — suggestion discarded / no `code`.

- [ ] **Step 3a: Make `derive_template_operations()` code-bearing**

In `derive_template_operations()` (`:1350`), return the code:

```php
if ( isset( $seen_areas[ $area ] ) ) {
	return [ 'operations' => [], 'invalid' => true, 'code' => 'duplicate_area_mutation' ];
}
// ...
return [ 'operations' => array_slice( $operations, 0, 4 ), 'invalid' => false, 'code' => '' ];
```

- [ ] **Step 3b: Fix the discard in `validate_template_suggestions()`**

Replace the two `continue;` discards (`:637-639` and `:648-650`) so an invalid-operations suggestion keeps its advisory remnant and records the reason, instead of vanishing:

```php
$validated_operations = $validated_operations_result['operations'];
$reasons              = [];

if ( ! empty( $validated_operations_result['invalid'] ) ) {
	// Survivorship fix: keep the advisory remnant, record the reason.
	$reasons[]            = [ 'code' => $validated_operations_result['code'] ?: 'operation_validation_failed' ];
	$validated_operations = [];
} elseif ( count( $validated_operations ) === 0 ) {
	$derived_operations = self::derive_template_operations(
		$validated_template_parts, $assigned_part_lookup, $empty_area_lookup
	);
	if ( ! empty( $derived_operations['invalid'] ) ) {
		$reasons[]            = [ 'code' => $derived_operations['code'] ?: 'operation_validation_failed' ];
		$validated_operations = [];
	} else {
		$validated_operations = $derived_operations['operations'];
	}
}

if ( count( $validated_operations ) > 0 ) {
	$entry['operations']         = $validated_operations;
	$entry['templateParts']      = self::summarize_template_parts_from_operations( $validated_operations, $validated_template_parts );
	$entry['patternSuggestions'] = self::summarize_pattern_suggestions_from_operations( $validated_operations );
} else {
	$entry['templateParts']      = $validated_template_parts;
	$entry['patternSuggestions'] = $validated_pattern_suggestions;
}

$entry['validationReasons'] = ValidationReason::normalize( $reasons );

// Existing fully-empty skip stays (no ops AND no advisory):
if (
	count( $entry['operations'] ) === 0
	&& count( $entry['templateParts'] ) === 0
	&& count( $entry['patternSuggestions'] ) === 0
) {
	continue;
}
```

Add the two test seams (`validate_template_suggestions_for_tests`, `derive_template_operations_for_tests`) mirroring Task 6's seam pattern. Ensure `use FlavorAgent\Support\ValidationReason;` is present.

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter 'TemplatePromptTest'`
Expected: PASS (both new tests + Task 6's branch tests).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/TemplatePrompt.php tests/phpunit/TemplatePromptTest.php
git commit -m "fix: keep template advisory remnant + record validationReasons instead of discarding"
```

---

### Task 8: TemplatePartPrompt — reasons + `too_many_operations` cap

**Files:**
- Modify: `inc/LLM/TemplatePartPrompt.php` — `validate_operations()` (`:869-1000`) and its caller `validate_suggestions()` (`:666-735`)
- Test: `tests/phpunit/TemplatePartPromptTest.php`

- [ ] **Step 1: Write the failing data-provider test**

```php
/**
 * @dataProvider templatePartRejectionBranches
 */
public function test_each_template_part_branch_maps_to_a_specific_code( array $operations, string $expectedCode ): void {
	$result = \FlavorAgent\LLM\TemplatePartPrompt::validate_operations_for_tests( $operations, $this->lookups() );
	$this->assertContains( $expectedCode, array_column( $result['reasons'], 'code' ) );
	$this->assertNotContains( 'operation_validation_failed', array_column( $result['reasons'], 'code' ) );
}

public function templatePartRejectionBranches(): array {
	return [
		'too many operations' => [ array_fill( 0, 4, [ 'type' => 'insert_pattern', 'patternName' => 'a/b', 'placement' => 'start' ] ), 'too_many_operations' ],
		'unknown pattern'     => [ [ [ 'type' => 'insert_pattern', 'patternName' => 'nope', 'placement' => 'start' ] ], 'unknown_pattern' ],
		'invalid placement'   => [ [ [ 'type' => 'insert_pattern', 'patternName' => 'a/b', 'placement' => 'sideways' ] ], 'invalid_placement' ],
		'invalid anchor'      => [ [ [ 'type' => 'insert_pattern', 'patternName' => 'a/b', 'placement' => 'before_block_path' ] ], 'invalid_anchor' ],
		'overlapping paths'   => [ /* two ops with overlapping targetPath */ [], 'overlapping_block_paths' ],
		'unknown op type'     => [ [ [ 'type' => 'frobnicate' ] ], 'unknown_operation_type' ],
	];
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter test_each_template_part_branch_maps_to_a_specific_code`
Expected: FAIL — `validate_operations_for_tests` undefined / no `reasons` key.

- [ ] **Step 3: Change `validate_operations()` to return `{operations, reasons}`**

Replace the `> 3` early return (`:876-878`) and each `continue 2` / `return []` with coded reasons. Branch→code:

| Line (HEAD) | Branch | Code |
| --- | --- | --- |
| `:876` | more than 3 operations | `too_many_operations` |
| `:909` | insert: empty name / unknown pattern / bad placement (split) | `unknown_pattern` (name/lookup) or `invalid_placement` (placement) |
| `:921` | insert: anchored path missing/unknown | `invalid_anchor` |
| `:928` | insert: start/end anchor missing | `invalid_anchor` |
| `:938` | insert: overlapping path | `overlapping_block_paths` |
| replace/remove (`~:962`, `~:984`) | missing name/path/lookup | `unknown_pattern` / `invalid_anchor` |
| overlap (replace/remove) | overlapping path | `overlapping_block_paths` |
| `default` | unknown operation type | `unknown_operation_type` |

Accumulate `$reasons = []`, push the code before each `continue 2`/`return`, and change both early `return []` and the final `return $valid;`:

```php
// e.g. the >3 cap:
if ( count( $operations ) > 3 ) {
	return [ 'operations' => [], 'reasons' => ValidationReason::normalize( [ [ 'code' => 'too_many_operations' ] ] ) ];
}
// ...inside the loop, for the overlap return:
if ( self::has_overlapping_template_part_operation_path( $targeted_paths, $target_path ) ) {
	$reasons[] = [ 'code' => 'overlapping_block_paths' ];
	return [ 'operations' => [], 'reasons' => ValidationReason::normalize( $reasons ) ];
}
// ...final:
return [ 'operations' => $valid, 'reasons' => ValidationReason::normalize( $reasons ) ];
```

Add the `validate_operations_for_tests` seam and `use FlavorAgent\Support\ValidationReason;`.

- [ ] **Step 4: Update `validate_suggestions()` to attach `entry['validationReasons']`**

Where `validate_suggestions()` consumes `validate_operations()` (around `:680-730`), read the new shape and attach reasons to the entry (advisory-first behavior is preserved — operations only populate when valid):

```php
$operations_result          = self::validate_operations( $input_operations, $block_lookup, $pattern_lookup, $operation_target_lookup, $insertion_anchor_lookup );
$entry['operations']        = $operations_result['operations'];
$entry['validationReasons'] = ValidationReason::normalize( $operations_result['reasons'] );
```

- [ ] **Step 5: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter TemplatePartPromptTest`
Expected: PASS.

- [ ] **Step 6: Phase 2 gate**

Run: `node scripts/verify.js --skip-e2e` and inspect `output/verify/summary.json` (`status: pass`).

- [ ] **Step 7: Commit**

```bash
git add inc/LLM/TemplatePartPrompt.php tests/phpunit/TemplatePartPromptTest.php
git commit -m "feat: emit per-branch validationReasons from TemplatePartPrompt (incl. too_many_operations)"
```

---

## Phase 3 — Scorer

### Task 9: `has_validation_risk()` reads structured reasons; retire the prose scan

**Files:**
- Modify: `inc/Support/RecommendationContextScorer.php` — `has_validation_risk()` (`:367-377`)
- Test: `tests/phpunit/RecommendationContextScorerTest.php` (or the existing scorer test file)

- [ ] **Step 1: Write the failing tests**

```php
public function test_validation_reasons_trigger_validation_risk_across_surfaces(): void {
	$suggestion = [ 'validationReasons' => [ [ 'code' => 'unsupported_path', 'severity' => 'rejected' ] ] ];
	$this->assertTrue( $this->invokeHasValidationRisk( $suggestion ) );
}

public function test_no_op_severity_does_not_trigger_validation_risk(): void {
	$suggestion = [ 'validationReasons' => [ [ 'code' => 'no_op', 'severity' => 'no_op' ] ] ];
	$this->assertFalse( $this->invokeHasValidationRisk( $suggestion ) );
}

public function test_prose_only_text_no_longer_triggers_validation_risk(): void {
	// Regression guard: "invalid" in a description must NOT set the penalty.
	$suggestion = [ 'label' => 'Avoid invalid contrast pairings', 'description' => 'rejected ideas' ];
	$this->assertFalse( $this->invokeHasValidationRisk( $suggestion ) );
}

public function test_rejected_operations_still_trigger_for_block_compat(): void {
	$suggestion = [ 'rejectedOperations' => [ [ 'code' => 'stale_target' ] ] ];
	$this->assertTrue( $this->invokeHasValidationRisk( $suggestion ) );
}
```

(`invokeHasValidationRisk` uses reflection to call the private method, or asserts via the public `score()` output `contextPenalties.validation_risk`.)

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter RecommendationContextScorerTest`
Expected: FAIL — `test_prose_only_text_no_longer_triggers_validation_risk` fails (prose scan still fires); `test_validation_reasons_trigger...` fails (reasons not read).

- [ ] **Step 3: Rewrite `has_validation_risk()`**

Replace the body (`:367-377`) — read structured `validationReasons` (severity `rejected`/`downgraded`), keep `rejectedOperations` as block compat, **delete the prose `str_contains` scan**:

```php
private static function has_validation_risk( array $suggestion ): bool {
	$reasons = self::list_value( $suggestion['validationReasons'] ?? [] );
	foreach ( $reasons as $reason ) {
		$severity = is_array( $reason ) ? (string) ( $reason['severity'] ?? '' ) : '';
		if ( 'rejected' === $severity || 'downgraded' === $severity ) {
			return true;
		}
	}

	// Block compatibility: rejectedOperations predates validationReasons.
	return [] !== self::list_value( $suggestion['rejectedOperations'] ?? [] );
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter RecommendationContextScorerTest`
Expected: PASS (all four, including the prose-no-trigger guard).

- [ ] **Step 5: Commit**

```bash
git add inc/Support/RecommendationContextScorer.php tests/phpunit/RecommendationContextScorerTest.php
git commit -m "feat: scorer reads structured validationReasons; retire prose validation-risk heuristic"
```

---

## Phase 4 — Outcome persistence, metrics, audit

### Task 10: `RecommendationOutcome` — rankingSet extension, reason slot, sibling field

**Files:**
- Modify: `inc/Activity/RecommendationOutcome.php` — `normalize_entry()` (`:58-170`), `normalize_ranking_set()` (`:208-240`)
- Test: `tests/phpunit/RecommendationOutcomeTest.php`

- [ ] **Step 1: Write the failing tests**

```php
public function test_ranking_set_item_carries_validation_reason_and_version(): void {
	$entry = $this->shownEntry( [
		'rankingSet' => [
			[ 'suggestionKey' => 'block:block:1', 'ranking' => [ 'blendedScore' => 0.5 ], 'validationReason' => 'failed_contrast', 'validationVocabularyVersion' => 'validation-reasons-v1' ],
		],
	] );

	$out  = \FlavorAgent\Activity\RecommendationOutcome::normalize_entry( $entry );
	$item = $out['after']['outcome']['rankingSet'][0];

	$this->assertSame( 'failed_contrast', $item['validationReason'] );
	$this->assertSame( 'validation-reasons-v1', $item['validationVocabularyVersion'] );
}

public function test_validation_blocked_keeps_primary_reason_and_version(): void {
	$entry = $this->outcomeEntry( 'validation_blocked', [ 'reason' => 'unsupported_path', 'validationVocabularyVersion' => 'validation-reasons-v1' ] );
	$out   = \FlavorAgent\Activity\RecommendationOutcome::normalize_entry( $entry );

	$this->assertSame( 'unsupported_path', $out['after']['outcome']['reason'] );
	$this->assertSame( 'validation-reasons-v1', $out['after']['outcome']['validationVocabularyVersion'] );
}

public function test_selected_for_review_carries_sibling_validation_reason_without_touching_reason_slot(): void {
	$entry = $this->outcomeEntry( 'selected_for_review', [ 'reason' => 'review_opened', 'validationReason' => 'failed_contrast' ] );
	$out   = \FlavorAgent\Activity\RecommendationOutcome::normalize_entry( $entry );

	$this->assertSame( 'review_opened', $out['after']['outcome']['reason'] ); // dedupe-bearing slot unchanged
	$this->assertSame( 'failed_contrast', $out['after']['outcome']['validationReason'] ); // sibling
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter RecommendationOutcomeTest`
Expected: FAIL — fields stripped by normalizers.

- [ ] **Step 3a: Extend `normalize_ranking_set()` (`:208-240`)**

Carry the two new optional fields on each item:

```php
$item_out = [
	'suggestionKey' => $suggestion_key,
	'ranking'       => $ranking,
];

$validation_reason = sanitize_key( (string) ( $item['validationReason'] ?? '' ) );
if ( '' !== $validation_reason ) {
	$item_out['validationReason'] = $validation_reason;
}
$vocab_version = sanitize_text_field( (string) ( $item['validationVocabularyVersion'] ?? '' ) );
if ( '' !== $vocab_version ) {
	$item_out['validationVocabularyVersion'] = substr( $vocab_version, 0, 64 );
}

$items[] = $item_out;
```

> Note: an item with `validationReason` but no `ranking` should still drop today's `[] === $ranking` guard only if you want reason-without-ranking items. Keep the existing guard (reason rides alongside ranking on `shown`); reasons for engaged outcomes use the sibling field (Step 3c), not rankingSet.

- [ ] **Step 3b: Persist `validationVocabularyVersion` + reason for `validation_blocked`**

In `normalize_entry()` (`:107-129`), add the vocabulary version to `$normalized_outcome` and (for `validation_blocked`) ensure the `reason` slot already carries the primary code (it does via `normalize_reason`). Add:

```php
$vocab_version = sanitize_text_field( (string) ( $outcome['validationVocabularyVersion'] ?? '' ) );
if ( '' !== $vocab_version ) {
	$normalized_outcome['validationVocabularyVersion'] = substr( $vocab_version, 0, 64 );
}
```

- [ ] **Step 3c: Sibling `validationReason` on engaged outcomes**

Still in `normalize_entry()`, for engaged events (`selected_for_review` and the apply-derived outcomes), carry the sibling field WITHOUT touching `reason`:

```php
if ( in_array( $event, [ 'selected_for_review' ], true ) ) {
	$sibling = sanitize_key( (string) ( $outcome['validationReason'] ?? '' ) );
	if ( '' !== $sibling ) {
		$normalized_outcome['validationReason'] = $sibling;
	}
}
```

> `apply_*_suggestion` rows are NOT outcome rows — they are handled in Task 11 (Serializer), not here.

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter RecommendationOutcomeTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add inc/Activity/RecommendationOutcome.php tests/phpunit/RecommendationOutcomeTest.php
git commit -m "feat: persist validationReason on rankingSet + validation_blocked + selected_for_review sibling"
```

---

### Task 11: Serializer round-trips apply-row `request.recommendation.validationReason`

**Files:**
- Test only: `tests/phpunit/SerializerTest.php` (no production change expected — `normalize_structured_value` deep-preserves `request`)

- [ ] **Step 1: Write the failing/guard test**

```php
public function test_apply_row_request_recommendation_validation_reason_round_trips(): void {
	$entry = [
		'type'      => 'apply_template_suggestion',
		'surface'   => 'template',
		'request'   => [
			'recommendation' => [
				'recommendationSetId' => 'template:0:hash_x',
				'suggestionKey'       => 'suggestion:1',
				'validationReason'    => 'failed_contrast',
			],
		],
	];

	$out = \FlavorAgent\Activity\Serializer::normalize_entry( $entry );

	$this->assertSame(
		'failed_contrast',
		$out['request']['recommendation']['validationReason']
	);
}
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit --filter test_apply_row_request_recommendation_validation_reason_round_trips`
Expected: **PASS immediately** — `Serializer::normalize_entry` (`:32`) deep-preserves `request` via `normalize_structured_value`. If it FAILS, `normalize_structured_value` is allow-listing keys; in that case add `validationReason` to the preserved set and re-run.

- [ ] **Step 3: Commit**

```bash
git add tests/phpunit/SerializerTest.php
git commit -m "test: assert apply-row request.recommendation.validationReason survives serialization"
```

---

### Task 12: `RecommendationOutcomeMetrics` — surface-scoped per-reason breakdown

**Files:**
- Modify: `inc/Activity/RecommendationOutcomeMetrics.php` — `evaluate()` (`:13-115`)
- Test: `tests/phpunit/RecommendationEvaluationTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_validation_blocked_breakdown_is_per_reason_and_excludes_pattern(): void {
	$entries = [
		$this->outcome( 'template', 'validation_blocked', 'set_x', 'invalid_template_area' ),
		$this->outcome( 'global-styles', 'validation_blocked', 'set_y', 'failed_contrast' ),
		$this->outcome( 'pattern', 'validation_blocked', 'set_z', 'empty_pattern_blocks' ), // EXCLUDED
	];

	$metrics = \FlavorAgent\Activity\RecommendationOutcomeMetrics::evaluate( $entries );

	$this->assertSame(
		[ 'invalid_template_area' => 1, 'failed_contrast' => 1 ],
		$metrics['validationBlockedByReason']
	);
	$this->assertArrayNotHasKey( 'empty_pattern_blocks', $metrics['validationBlockedByReason'] );
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter test_validation_blocked_breakdown_is_per_reason_and_excludes_pattern`
Expected: FAIL — `validationBlockedByReason` not in output.

- [ ] **Step 3: Implement the surface-scoped breakdown**

In `evaluate()`, add an accumulator and populate it inside the existing `validation_blocked` branch (`:68-71`), scoped to the five executable **outcome** surfaces (NOT `pattern`):

```php
private const EXECUTABLE_OUTCOME_SURFACES = [ 'block', 'template', 'template-part', 'global-styles', 'style-book' ];
```

```php
// near the other accumulators (:19):
$validation_blocked_by_reason = [];

// inside the validation_blocked branch (:68):
if ( 'validation_blocked' === $event ) {
	$validation_blocked_events[ $event_key ] = true;
	$attempted_events[ $event_key ]          = true;

	if ( in_array( $surface, self::EXECUTABLE_OUTCOME_SURFACES, true ) ) {
		$reason = (string) ( $outcome['reason'] ?? '' );
		if ( '' !== $reason ) {
			$validation_blocked_by_reason[ $reason ] = ( $validation_blocked_by_reason[ $reason ] ?? 0 ) + 1;
		}
	}
}

// in the return array (:105-114):
'validationBlockedByReason' => $validation_blocked_by_reason,
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter RecommendationEvaluationTest`
Expected: PASS (new test + existing metric tests unchanged).

- [ ] **Step 5: Commit**

```bash
git add inc/Activity/RecommendationOutcomeMetrics.php tests/phpunit/RecommendationEvaluationTest.php
git commit -m "feat: per-reason validation_blocked breakdown scoped to executable outcome surfaces"
```

---

### Task 13: `RecommendationAbilityExecution` — audit-only reason aggregate

**Files:**
- Modify: `inc/Abilities/RecommendationAbilityExecution.php` — add a new executable-surface helper; call it from `persist_request_diagnostic_activity()` (`:620-676`)
- Test: `tests/phpunit/RecommendationAbilityExecutionTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_request_diagnostic_aggregates_validation_reasons_for_executable_surface(): void {
	$payload = [
		'styles' => [
			[ 'label' => 'a', 'validationReasons' => [ [ 'code' => 'failed_contrast', 'severity' => 'downgraded' ] ] ],
			[ 'label' => 'b', 'validationReasons' => [ [ 'code' => 'unsupported_path', 'severity' => 'rejected' ] ] ],
		],
	];

	$aggregate = \FlavorAgent\Abilities\RecommendationAbilityExecution::aggregate_validation_reasons_for_tests( 'global-styles', $payload );

	$this->assertSame(
		[ 'failed_contrast' => 1, 'unsupported_path' => 1 ],
		$aggregate['reasonCounts']
	);
	$this->assertSame( 'validation-reasons-v1', $aggregate['validationVocabularyVersion'] );
}

public function test_aggregate_is_empty_for_clean_pass(): void {
	$aggregate = \FlavorAgent\Abilities\RecommendationAbilityExecution::aggregate_validation_reasons_for_tests( 'template', [ 'suggestions' => [ [ 'label' => 'clean' ] ] ] );
	$this->assertSame( [], $aggregate );
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter RecommendationAbilityExecutionTest`
Expected: FAIL — helper undefined.

- [ ] **Step 3: Implement the new helper (do NOT route through the pattern-only `build_request_diagnostic_pipeline_drop_reasons`)**

Add a private helper + a test seam, and call it from `persist_request_diagnostic_activity()`:

```php
/**
 * Audit-only: count validationReasons across an executable surface's suggestions.
 * Identified by requestRef on the request_diagnostic row; never the loop join.
 *
 * @param array<string, mixed> $payload
 * @return array{reasonCounts: array<string, int>, validationVocabularyVersion: string}|array{}
 */
private static function aggregate_validation_reasons( string $surface, array $payload ): array {
	if ( ! in_array( $surface, [ 'block', 'style', 'template', 'template-part', 'global-styles', 'style-book' ], true ) ) {
		return [];
	}

	$counts = [];
	foreach ( [ 'settings', 'styles', 'block', 'suggestions' ] as $list_key ) {
		$list = is_array( $payload[ $list_key ] ?? null ) ? $payload[ $list_key ] : [];
		foreach ( $list as $suggestion ) {
			$reasons = is_array( $suggestion['validationReasons'] ?? null ) ? $suggestion['validationReasons'] : [];
			foreach ( ValidationReason::normalize( $reasons ) as $reason ) {
				$counts[ $reason['code'] ] = ( $counts[ $reason['code'] ] ?? 0 ) + 1;
			}
		}
	}

	if ( [] === $counts ) {
		return [];
	}

	return [ 'reasonCounts' => $counts, 'validationVocabularyVersion' => ValidationReason::VERSION ];
}

public static function aggregate_validation_reasons_for_tests( string $surface, array $payload ): array {
	return self::aggregate_validation_reasons( $surface, $payload );
}
```

In `persist_request_diagnostic_activity()` (after the existing `pipeline_drop_reasons` block, `:648`):

```php
$validation_aggregate = self::aggregate_validation_reasons( $surface, $payload );
if ( [] !== $validation_aggregate ) {
	$after['validationReasons'] = $validation_aggregate;
}
```

Add `use FlavorAgent\Support\ValidationReason;` to the file header.

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter RecommendationAbilityExecutionTest`
Expected: PASS.

- [ ] **Step 5: Phase 4 gate + commit**

```bash
node scripts/verify.js --skip-e2e   # status: pass
git add inc/Abilities/RecommendationAbilityExecution.php tests/phpunit/RecommendationAbilityExecutionTest.php
git commit -m "feat: audit-only validationReasons aggregate on request_diagnostic (executable surfaces)"
```

---

## Phase 5 — Client

### Task 14: Decorate outcomes — rankingSet reason, sibling field, key guard

**Files:**
- Modify: `src/store/recommendation-outcomes.js` — `buildRankingSetFromSuggestions()` (`:279-304`), `decorateRecommendationPayload()` (`:306-394`), `buildRecommendationOutcomeEntry()` (`:557-690`), `getRecommendationIdentityForApply()` (`:692-706`)
- Test: `src/store/__tests__/recommendation-outcomes.test.js`, `src/inspector/__tests__/suggestion-keys.test.js`

- [ ] **Step 1: Write the failing tests**

```javascript
import {
	buildRankingSetFromSuggestions,
	getRecommendationIdentityForApply,
} from '../recommendation-outcomes';
import { getSuggestionKey } from '../../inspector/suggestion-keys';

describe( 'validationReason on outcomes', () => {
	it( 'carries the primary reason + version into rankingSet items', () => {
		const set = buildRankingSetFromSuggestions( [
			{
				suggestionKey: 'style:styles:1',
				ranking: { blendedScore: 0.4 },
				validationReasons: [ { code: 'failed_contrast', severity: 'downgraded' } ],
			},
		] );
		expect( set[ 0 ].validationReason ).toBe( 'failed_contrast' );
		expect( set[ 0 ].validationVocabularyVersion ).toBe( 'validation-reasons-v1' );
	} );

	it( 'carries the primary reason into the apply identity', () => {
		const identity = getRecommendationIdentityForApply( {
			recommendationOutcome: { recommendationSetId: 's:0:h', suggestionKey: 'k' },
			validationReasons: [ { code: 'unsupported_path' } ],
		} );
		expect( identity.validationReason ).toBe( 'unsupported_path' );
	} );
} );

describe( 'getSuggestionKey exclusion guard', () => {
	it( 'ignores validationReasons when computing the key', () => {
		const base = { panel: 'styles', label: 'x', operations: [] };
		const withReasons = { ...base, validationReasons: [ { code: 'failed_contrast' } ] };
		expect( getSuggestionKey( withReasons ) ).toBe( getSuggestionKey( base ) );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js src/inspector/__tests__/suggestion-keys.test.js`
Expected: FAIL — fields undefined (key guard passes already since the fingerprint doesn't include `validationReasons`, but assert it explicitly).

- [ ] **Step 3a: Extend `buildRankingSetFromSuggestions()` (`:279-304`)**

```javascript
import { primaryValidationReason, VALIDATION_REASONS_VERSION } from '../utils/validation-reasons';

// inside the .map(), after computing `ranking` and `suggestionKey`:
const primary = primaryValidationReason( suggestion?.validationReasons );

return {
	suggestionKey,
	ranking,
	...( primary
		? {
				validationReason: primary.code,
				validationVocabularyVersion: VALIDATION_REASONS_VERSION,
		  }
		: {} ),
};
```

- [ ] **Step 3b: Sibling field in `getRecommendationIdentityForApply()` (`:692-706`)**

```javascript
const primary = primaryValidationReason( suggestion?.validationReasons );

return {
	recommendationSetId: identity.recommendationSetId,
	suggestionKey: identity.suggestionKey || getSuggestionOutcomeKey( suggestion, '' ),
	sourceRequestSignature: identity.sourceRequestSignature,
	rank: identity.rank,
	...( primary ? { validationReason: primary.code } : {} ),
};
```

- [ ] **Step 3c: Sibling field on `selected_for_review` in `buildRecommendationOutcomeEntry()`**

In the `after.outcome` object (`:648-662`), add the sibling field for non-`shown` engaged events from `suggestion.validationReasons`, without touching `reason`:

```javascript
const engagedPrimary =
	safeEvent !== 'shown' ? primaryValidationReason( suggestion?.validationReasons ) : null;

// within after.outcome { ... }:
...( engagedPrimary ? { validationReason: engagedPrimary.code } : {} ),
```

- [ ] **Step 3d: Decorate the payload root** — in `decorateRecommendationPayload()` the per-suggestion `recommendationOutcome` already exists; no reason needed there (reasons ride rankingSet + sibling). No change required beyond confirming `suggestion.validationReasons` survives the spread (it does — `...suggestion`).

- [ ] **Step 4: Run it to verify it passes**

Run: `npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js src/inspector/__tests__/suggestion-keys.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/store/recommendation-outcomes.js src/store/__tests__/recommendation-outcomes.test.js src/inspector/__tests__/suggestion-keys.test.js
git commit -m "feat: carry validationReason into rankingSet, apply identity, and selected_for_review sibling"
```

---

### Task 15: Apply-time codes — runtime, validators, thunk threading

**Files:**
- Modify: `src/store/executable-surface-runtime.js:561`, `src/utils/template-operation-sequence.js`, `src/utils/style-operations.js`, `src/utils/template-actions.js`, `src/store/executable-surfaces.js`
- Test: `src/store/__tests__/executable-surface-runtime.test.js`, `src/utils/__tests__/template-operation-sequence.test.js`, `src/utils/__tests__/style-operations.test.js`

- [ ] **Step 1: Write the failing tests**

```javascript
// executable-surface-runtime.test.js
it( 'records the validator code on validation_blocked, not the generic fallback', async () => {
	const recordOutcomeAction = jest.fn( ( o ) => ( { type: 'REC', ...o } ) );
	const executeSuggestion = jest.fn().mockResolvedValue( {
		ok: false,
		error: 'overlap',
		code: 'overlapping_block_paths',
	} );
	// ...build the apply action with recordOutcomeAction + executeSuggestion...
	await applyAction( suggestion )( { dispatch, registry, select } );

	expect( recordOutcomeAction ).toHaveBeenCalledWith(
		expect.objectContaining( { event: 'validation_blocked', reason: 'overlapping_block_paths' } )
	);
} );
```

```javascript
// template-operation-sequence.test.js
it( 'returns overlapping_block_paths code on overlap', () => {
	const result = validateTemplatePartOperationSequence( [ /* two overlapping ops */ ] );
	expect( result.ok ).toBe( false );
	expect( result.code ).toBe( 'overlapping_block_paths' );
} );
```

- [ ] **Step 2: Run them to verify they fail**

Run: `npm run test:unit -- --runInBand src/store/__tests__/executable-surface-runtime.test.js src/utils/__tests__/template-operation-sequence.test.js`
Expected: FAIL — no `code` on results / hard-coded reason.

- [ ] **Step 3a: `executable-surface-runtime.js:561`** — use the validator code:

```javascript
recordBlockedOutcome(
	'validation_blocked',
	result.code || 'operation_validation_failed'
);
```

- [ ] **Step 3b: `template-operation-sequence.js`** — add a `code` to every `{ ok: false, error }` return. Map (these mirror the PHP codes):

```javascript
// empty operations
return { ok: false, error: '...', code: 'no_executable_operations' };
// overlap (getTemplatePartTargetPathConflict)
return { ok: false, error: '...', code: 'overlapping_block_paths' };
// duplicate area
return { ok: false, error: '...', code: 'duplicate_area_mutation' };
// unknown type (default)
return { ok: false, error: '...', code: 'unknown_operation_type' };
// >3 cap (template-part)
return { ok: false, error: '...', code: 'too_many_operations' };
// bad/empty placement
return { ok: false, error: '...', code: 'invalid_placement' };
// anchored without targetPath
return { ok: false, error: '...', code: 'invalid_anchor' };
// missing pattern name
return { ok: false, error: '...', code: 'unknown_pattern' };
```

- [ ] **Step 3c: `style-operations.js`** — add `code` to each `{ ok: false, error }` in `validatePresetStyleOperation` (`:1008`) and the contrast/variation/style-book branches:

```javascript
// valueType !== 'preset' (:1019)
return { ok: false, error: '...', code: 'preset_required' };
// preset metadata mismatch (:1031)
return { ok: false, error: '...', code: 'preset_metadata_mismatch' };
// preset reference mismatch (:1044)
return { ok: false, error: '...', code: 'preset_reference_mismatch' };
// preset unavailable (:1058)
return { ok: false, error: '...', code: 'preset_unavailable' };
// unsupported path (:1096)
return { ok: false, error: '...', code: 'unsupported_path' };
// style-book target mismatch/unregistered (:1250/:1259)
return { ok: false, error: '...', code: 'missing_style_book_target' };
// variation unavailable (:1295)
return { ok: false, error: '...', code: 'unavailable_variation' };
// contrast unavailable/low (:359/:430/:462/:503)
return { ok: false, error: '...', code: 'failed_contrast' };
```

- [ ] **Step 3d: `template-actions.js`** — where it returns apply errors, propagate any `code` from the sequence validators (pass `result.code` through unchanged).

- [ ] **Step 3e: `executable-surfaces.js`** — ensure `executeSuggestion` results propagate `code` to the runtime (the runtime reads `result.code`; confirm the per-surface `executeSuggestion` returns the validator result verbatim, including `code`).

- [ ] **Step 4: Run them to verify they pass**

Run: `npm run test:unit -- --runInBand src/store/__tests__/executable-surface-runtime.test.js src/utils/__tests__/template-operation-sequence.test.js src/utils/__tests__/style-operations.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/store/executable-surface-runtime.js src/utils/template-operation-sequence.js src/utils/style-operations.js src/utils/template-actions.js src/store/executable-surfaces.js src/store/__tests__/ src/utils/__tests__/
git commit -m "feat: apply-time validation codes thread through runtime to validation_blocked outcomes"
```

---

### Task 16: Advisory rendering beyond block

**Files:**
- Modify: `src/utils/recommendation-actionability.js`, `src/utils/block-operation-catalog.js` (reason→label helper)
- Test: `src/utils/__tests__/recommendation-actionability.test.js`

- [ ] **Step 1: Write the failing test**

```javascript
it( 'surfaces a concise reason for rejected-but-advisory style/template suggestions', () => {
	const tier = classifyRecommendationActionability( {
		surface: 'global-styles',
		operations: [],
		validationReasons: [ { code: 'failed_contrast', severity: 'downgraded' } ],
	} );
	expect( tier.tier ).toBe( 'advisory' );
	expect( tier.reasonCode ).toBe( 'failed_contrast' );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/utils/__tests__/recommendation-actionability.test.js`
Expected: FAIL — `reasonCode` undefined for non-block.

- [ ] **Step 3: Implement**

In `recommendation-actionability.js`, when a suggestion has `validationReasons` and no executable operations, classify `advisory` and expose `reasonCode = primaryValidationReason(...)?.code`. Reuse `primaryValidationReason` from `../utils/validation-reasons`. Add a small `getValidationReasonLabel(code)` map in `block-operation-catalog.js` (or `validation-reasons.js`) for human-readable strings, defaulting to a humanized code.

- [ ] **Step 4: Run it to verify it passes**

Run: `npm run test:unit -- --runInBand src/utils/__tests__/recommendation-actionability.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/utils/recommendation-actionability.js src/utils/block-operation-catalog.js src/utils/__tests__/recommendation-actionability.test.js
git commit -m "feat: surface validation reason on rejected-but-advisory style/template suggestions"
```

---

## Phase 6 — Integration, signature boundary, gates

### Task 17: Signature-boundary tests + full verification

**Files:**
- Test: `tests/phpunit/SignatureBoundaryTest.php` (or extend the relevant abilities tests)

- [ ] **Step 1: Write the signature-boundary test**

Assert the **context inputs** the abilities build for the resolved/review signatures contain no `validationReasons`/`validationVocabularyVersion`. Use the actual context-builder call sites (`TemplateAbilities`, `StyleAbilities`, `BlockAbilities`):

```php
public function test_resolved_signature_context_inputs_contain_no_validation_annotations(): void {
	$context = \FlavorAgent\Abilities\StyleAbilities::build_resolved_context_for_tests( $sampleRequest );
	$json    = wp_json_encode( $context );

	$this->assertStringNotContainsString( 'validationReasons', (string) $json );
	$this->assertStringNotContainsString( 'validationVocabularyVersion', (string) $json );
}
```

> This asserts the call-site boundary (no response suggestions enter signature inputs). Do NOT add key-stripping to `RecommendationSignature::from_payload()` — that would wrongly broaden the signature contract (spec round-4 Finding 2).

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit --filter SignatureBoundaryTest`
Expected: PASS (the keys are response-side; they never enter request-side context). If a builder needs a `*_for_tests` seam, add a thin one.

- [ ] **Step 3: Full verification + metrics gate**

Run the spec's verification block:

```bash
composer run test:php -- --filter 'ValidationReasonTest|ValidationReasonParityTest|RecommendationEvaluationTest|BlockAbilitiesTest|StyleAbilitiesTest|TemplatePromptTest|TemplatePartPromptTest|RecommendationOutcomeTest|RecommendationAbilityExecutionTest|RecommendationContextScorerTest|SerializerTest|SignatureBoundaryTest'
npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js src/store/__tests__/executable-surface-runtime.test.js src/utils/__tests__/validation-reasons.test.js
node scripts/verify.js --skip-e2e   # inspect output/verify/summary.json -> status: pass
npm run check:docs
git diff --check
```

Expected: all green; `summary.json` `status: pass`. The metrics gate (`invalidOperationRate` flat or down; advisory remnants do NOT raise it; rejected executable ops lower the blended score) is only "passed" with `RecommendationEvaluationTest` in this run.

- [ ] **Step 4: Update docs**

Update `docs/reference/abilities-and-routes.md` (suggestion output now includes `validationReasons`) and `docs/FEATURE_SURFACE_MATRIX.md` if it enumerates suggestion fields. Re-run `npm run check:docs`.

- [ ] **Step 5: Final commit**

```bash
git add tests/phpunit/SignatureBoundaryTest.php docs/
git commit -m "test: signature-boundary assertions + docs for validationReasons"
```

---

## Self-review (run before handoff)

**Spec coverage** — every spec Change maps to a task:
- Change 1 (ValidationReason) → Task 1; OD-1 shared JSON → Tasks 1–2.
- Change 2 (block derive) → Task 3.
- Change 3 (StylePrompt) → Task 5.
- Change 4 (TemplatePrompt discard + per-branch + derive) → Tasks 6–7.
- Change 5 (TemplatePartPrompt) → Task 8.
- Change 6 (scorer + prose-scan retirement) → Task 9.
- Change 7 (audit aggregate) → Task 13.
- Change 8 (RecommendationOutcome) → Task 10.
- Change 9 (metrics, surface-scoped) → Task 12.
- Change 10 (client) → Tasks 14–16.
- Change 11 (schema, bounded string) → Task 4.
- OD-2 sibling field → Tasks 10 (PHP), 14 (JS); Serializer apply-row → Task 11.
- Signature boundary + `getSuggestionKey` guard → Tasks 14, 17.

**Type/name consistency:** `validationReasons` (list, on suggestions/payload), `validationReason` (single primary code, on rankingSet items, `validation_blocked.reason` is the existing `reason` slot, sibling `outcome.validationReason` on engaged), `validationVocabularyVersion` (= `validation-reasons-v1`). Metric key: `validationBlockedByReason`. PHP `ValidationReason::normalize()/primary()/VERSION/vocabulary()`; JS `primaryValidationReason()/getValidationReasonSeverity()/VALIDATION_REASONS_VERSION`.

**Open risks for the executor:** several tasks add `*_for_tests` seams — keep them minimal and private-delegating. If `normalize_structured_value` allow-lists keys (Task 11 fails), add `validationReason` to its preserved set. The PHPUnit harness must provide `sanitize_text_field`/`sanitize_key`; if a pure-class test can't see them, follow the repo's existing stub pattern (see how `StylePromptTest`/`RecommendationEvaluationTest` bootstrap).
