# Tier 2: Read-only preview recommendation abilities — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add five read-only sibling abilities (`flavor-agent/preview-recommend-{block,navigation,style,template,template-part}`) that wrap the executable recommend-* parents, force `resolveSignatureOnly:true` server-side, strip `clientRequest` to suppress request-token transients, and return signature-only output. Always available behind `canonical_contracts_available()`; MCP-public on the default ability bridge only.

**Architecture:** A new abstract `PreviewRecommendationAbility` extends `WordPress\AI\Abstracts\Abstract_Ability`. Five concrete subclasses each declare `ABILITY_NAME`, `PARENT_CLASS`, `PARENT_ABILITY`, and `SIGNATURE_KEYS` constants. The wrapper instantiates its parent lazily for permission delegation, runs `prepare_parent_input` (forces `resolveSignatureOnly:true`, unsets `clientRequest`) before delegation, and filters output to per-surface signature keys. Schemas are derived from the parent at runtime via `Registration::recommendation_input_schema()` so they self-update if parents evolve.

**Tech Stack:** PHP 8.0+, WordPress Abilities API (WP 7.0), WordPress AI plugin's `Abstract_Ability`, PHPUnit 9.6.

**Spec:** `docs/superpowers/specs/2026-05-09-tier-2-preview-recommendation-abilities-design.md`

---

## Reference: per-surface signature keys (already verified during spec-writing)

| Preview ability ID | Wraps | SIGNATURE_KEYS |
|---|---|---|
| `flavor-agent/preview-recommend-block` | `RecommendBlockAbility` | `['resolvedContextSignature']` |
| `flavor-agent/preview-recommend-navigation` | `RecommendNavigationAbility` | `['reviewContextSignature']` |
| `flavor-agent/preview-recommend-style` | `RecommendStyleAbility` | `['reviewContextSignature', 'resolvedContextSignature']` |
| `flavor-agent/preview-recommend-template` | `RecommendTemplateAbility` | `['reviewContextSignature', 'resolvedContextSignature']` |
| `flavor-agent/preview-recommend-template-part` | `RecommendTemplatePartAbility` | `['reviewContextSignature', 'resolvedContextSignature']` |

Source: `Registration::recommendation_output_schema()` in `inc/Abilities/Registration.php` — block at line 1826, navigation 1047, style 1869–1870, template 1101–1102, template-part 1156–1157.

---

## File structure

**Files to create:**

- `inc/AI/Abilities/PreviewRecommendationAbility.php` — abstract base (~110 lines)
- `inc/AI/Abilities/PreviewRecommendBlockAbility.php` (~12 lines)
- `inc/AI/Abilities/PreviewRecommendNavigationAbility.php` (~12 lines)
- `inc/AI/Abilities/PreviewRecommendStyleAbility.php` (~12 lines)
- `inc/AI/Abilities/PreviewRecommendTemplateAbility.php` (~12 lines)
- `inc/AI/Abilities/PreviewRecommendTemplatePartAbility.php` (~12 lines)
- `tests/phpunit/PreviewRecommendationAbilityTest.php` (~120 lines)

**Files to modify:**

- `inc/Abilities/Registration.php` — add `register_preview_recommendation_abilities()`, `preview_recommendation_ability_classes()`, `preview_recommendation_meta()`; call from `register_abilities()` with `canonical_contracts_available()` guard.
- `inc/Abilities/InfraAbilities.php` (lines 109–141) — extend `available_abilities()` with five preview entries.
- `tests/phpunit/RegistrationTest.php` — new test methods, update annotation-coverage loops to include preview IDs.
- `tests/phpunit/InfraAbilitiesTest.php` — assert preview IDs surface in `check-status`.
- `CLAUDE.md` — change "20 abilities" → "25 abilities"; add five preview entries to inventory.
- `docs/reference/abilities-and-routes.md` — add five entries to canonical map.

**Files possibly affected (Task 8 — `npm run check:docs`):** `STATUS.md`, `docs/SOURCE_OF_TRUTH.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/flavor-agent-readme.md`, `docs/reference/wordpress-ai-roadmap-tracking.md`, `.github/copilot-instructions.md`.

---

## Task 1: Build abstract `PreviewRecommendationAbility` with pure helpers (TDD)

**Files:**
- Create: `inc/AI/Abilities/PreviewRecommendationAbility.php`
- Create: `tests/phpunit/PreviewRecommendationAbilityTest.php`

- [ ] **Step 1: Write failing test for `prepare_parent_input` forcing `resolveSignatureOnly:true`**

Create `tests/phpunit/PreviewRecommendationAbilityTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\AI\Abilities\PreviewRecommendationAbility;
use PHPUnit\Framework\TestCase;

final class PreviewRecommendationAbilityTest extends TestCase {

	public function test_prepare_parent_input_forces_resolve_signature_only_true(): void {
		$input = [ 'prompt' => 'hi', 'resolveSignatureOnly' => false ];

		$result = PreviewRecommendationAbility::prepare_parent_input( $input );

		$this->assertTrue( $result['resolveSignatureOnly'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit --filter PreviewRecommendationAbilityTest --no-coverage
```

Expected: `Class "FlavorAgent\AI\Abilities\PreviewRecommendationAbility" not found`.

- [ ] **Step 3: Create the abstract class with `prepare_parent_input` only**

Create `inc/AI/Abilities/PreviewRecommendationAbility.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use WordPress\AI\Abstracts\Abstract_Ability;

abstract class PreviewRecommendationAbility extends Abstract_Ability {

	protected const ABILITY_NAME    = '';
	protected const PARENT_CLASS    = '';
	protected const PARENT_ABILITY  = '';
	protected const SIGNATURE_KEYS  = [];

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function prepare_parent_input( array $input ): array {
		$input['resolveSignatureOnly'] = true;
		return $input;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
vendor/bin/phpunit --filter PreviewRecommendationAbilityTest --no-coverage
```

Expected: `OK (1 test, 1 assertion)`.

- [ ] **Step 5: Add failing test for `prepare_parent_input` stripping `clientRequest`**

Append to `tests/phpunit/PreviewRecommendationAbilityTest.php` (inside the class):

```php
	public function test_prepare_parent_input_strips_client_request(): void {
		$input = [
			'prompt'        => 'hi',
			'clientRequest' => [ 'sessionId' => 'foo', 'requestToken' => 1 ],
		];

		$result = PreviewRecommendationAbility::prepare_parent_input( $input );

		$this->assertArrayNotHasKey( 'clientRequest', $result );
	}
```

- [ ] **Step 6: Run the test to verify it fails**

```bash
vendor/bin/phpunit --filter PreviewRecommendationAbilityTest --no-coverage
```

Expected: `Failed asserting that an array does not have the key 'clientRequest'`.

- [ ] **Step 7: Update `prepare_parent_input` to unset `clientRequest`**

In `inc/AI/Abilities/PreviewRecommendationAbility.php`, replace the body of `prepare_parent_input`:

```php
	public static function prepare_parent_input( array $input ): array {
		$input['resolveSignatureOnly'] = true;
		unset( $input['clientRequest'] );
		return $input;
	}
```

- [ ] **Step 8: Run the test to verify it passes**

```bash
vendor/bin/phpunit --filter PreviewRecommendationAbilityTest --no-coverage
```

Expected: `OK (2 tests, 2 assertions)`.

- [ ] **Step 9: Add failing test for `filter_output_to_signature_keys`**

Append to `tests/phpunit/PreviewRecommendationAbilityTest.php`:

```php
	public function test_filter_output_keeps_only_signature_keys(): void {
		$output = [
			'resolvedContextSignature' => 'sig-abc',
			'reviewContextSignature'   => 'should-stay-when-listed',
			'suggestions'              => [ 'ignored' ],
			'requestMeta'              => [ 'ignored' ],
		];

		$result = PreviewRecommendationAbility::filter_output_to_signature_keys(
			$output,
			[ 'resolvedContextSignature' ]
		);

		$this->assertSame( [ 'resolvedContextSignature' => 'sig-abc' ], $result );
	}

	public function test_filter_output_returns_empty_for_non_array(): void {
		$result = PreviewRecommendationAbility::filter_output_to_signature_keys(
			'not-an-array',
			[ 'resolvedContextSignature' ]
		);

		$this->assertSame( [], $result );
	}

	public function test_filter_output_omits_keys_missing_from_input(): void {
		$output = [ 'reviewContextSignature' => 'rev-sig' ];

		$result = PreviewRecommendationAbility::filter_output_to_signature_keys(
			$output,
			[ 'reviewContextSignature', 'resolvedContextSignature' ]
		);

		$this->assertSame( [ 'reviewContextSignature' => 'rev-sig' ], $result );
	}
```

- [ ] **Step 10: Run the tests to verify they fail**

```bash
vendor/bin/phpunit --filter PreviewRecommendationAbilityTest --no-coverage
```

Expected: 3 errors — method `filter_output_to_signature_keys` does not exist.

- [ ] **Step 11: Implement `filter_output_to_signature_keys`**

Add this method to `inc/AI/Abilities/PreviewRecommendationAbility.php` (below `prepare_parent_input`):

```php
	/**
	 * @param array<int, string> $signature_keys
	 * @return array<string, mixed>
	 */
	public static function filter_output_to_signature_keys( mixed $output, array $signature_keys ): array {
		if ( ! \is_array( $output ) ) {
			return [];
		}

		$filtered = [];
		foreach ( $signature_keys as $key ) {
			if ( isset( $output[ $key ] ) ) {
				$filtered[ $key ] = $output[ $key ];
			}
		}

		return $filtered;
	}
```

- [ ] **Step 12: Run the tests to verify they pass**

```bash
vendor/bin/phpunit --filter PreviewRecommendationAbilityTest --no-coverage
```

Expected: `OK (5 tests, 5 assertions)`.

- [ ] **Step 13: Commit**

```bash
git add inc/AI/Abilities/PreviewRecommendationAbility.php tests/phpunit/PreviewRecommendationAbilityTest.php
git commit -m "$(cat <<'EOF'
Add PreviewRecommendationAbility abstract base with input/output helpers

Pure static helpers prepare_parent_input (forces resolveSignatureOnly:true,
unsets clientRequest to avoid latest_request_token transient writes) and
filter_output_to_signature_keys (keeps only the per-surface signature keys).
Concrete subclasses and registration come in subsequent tasks.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Add `PreviewRecommendBlockAbility` + Registration plumbing (TDD)

**Files:**
- Create: `inc/AI/Abilities/PreviewRecommendBlockAbility.php`
- Modify: `inc/AI/Abilities/PreviewRecommendationAbility.php` (add instance methods that subclasses need: `category`, `meta`, `input_schema`, `output_schema`, `permission_callback`, `execute_callback`)
- Modify: `inc/Abilities/Registration.php` (new helpers + registration call)
- Modify: `tests/phpunit/RegistrationTest.php` (new failing test)

- [ ] **Step 1: Write failing registration test for the Block preview**

Append to `tests/phpunit/RegistrationTest.php` (just before the closing `}` of the class):

```php
	public function test_register_preview_recommendation_abilities_registers_block(): void {
		Registration::register_category();
		Registration::register_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/preview-recommend-block'] ?? null;

		$this->assertIsArray( $ability, 'preview-recommend-block must register through register_abilities().' );
		$this->assertSame( 'flavor-agent', $ability['category'] ?? null );

		$properties = $ability['input_schema']['properties'] ?? [];
		$this->assertArrayNotHasKey( 'resolveSignatureOnly', $properties );
		$this->assertArrayNotHasKey( 'clientRequest', $properties );

		$output_props = $ability['output_schema']['properties'] ?? [];
		$this->assertSame( [ 'resolvedContextSignature' ], array_keys( $output_props ) );

		$this->assertTrue( (bool) ( $ability['meta']['show_in_rest'] ?? false ) );
		$this->assertTrue( (bool) ( $ability['meta']['readonly'] ?? false ) );
		$this->assertTrue( (bool) ( $ability['meta']['annotations']['readonly'] ?? false ) );
		$this->assertTrue( (bool) ( $ability['meta']['annotations']['idempotent'] ?? false ) );
		$this->assertFalse( (bool) ( $ability['meta']['annotations']['destructive'] ?? true ) );
		$this->assertTrue( (bool) ( $ability['meta']['mcp']['public'] ?? false ) );
		$this->assertSame( 'tool', $ability['meta']['mcp']['type'] ?? null );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit --filter test_register_preview_recommendation_abilities_registers_block --no-coverage
```

Expected: `Failed asserting that null is of type "array"` (preview ability not registered yet).

- [ ] **Step 3: Add the instance methods to the abstract class**

Edit `inc/AI/Abilities/PreviewRecommendationAbility.php`. Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\Registration;
use WordPress\AI\Abstracts\Abstract_Ability;

abstract class PreviewRecommendationAbility extends Abstract_Ability {

	protected const ABILITY_NAME    = '';
	protected const PARENT_CLASS    = '';
	protected const PARENT_ABILITY  = '';
	protected const SIGNATURE_KEYS  = [];

	private ?Abstract_Ability $parent_instance = null;

	public function category(): string {
		return 'flavor-agent';
	}

	public function input_schema(): array {
		$schema = Registration::recommendation_input_schema( static::PARENT_ABILITY );

		if ( isset( $schema['properties']['resolveSignatureOnly'] ) ) {
			unset( $schema['properties']['resolveSignatureOnly'] );
		}

		if ( isset( $schema['properties']['clientRequest'] ) ) {
			unset( $schema['properties']['clientRequest'] );
		}

		if ( isset( $schema['required'] ) && \is_array( $schema['required'] ) ) {
			$schema['required'] = \array_values(
				\array_diff( $schema['required'], [ 'resolveSignatureOnly', 'clientRequest' ] )
			);

			if ( [] === $schema['required'] ) {
				unset( $schema['required'] );
			}
		}

		return $schema;
	}

	public function output_schema(): array {
		$properties = [];
		foreach ( static::SIGNATURE_KEYS as $key ) {
			$properties[ $key ] = [ 'type' => 'string' ];
		}

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		];
	}

	public function meta(): array {
		return Registration::preview_recommendation_meta();
	}

	public function permission_callback( mixed $input = null ): bool {
		return $this->parent()->permission_callback( $input );
	}

	public function execute_callback( mixed $input ): mixed {
		$prepared = self::prepare_parent_input( \is_array( $input ) ? $input : [] );
		$result   = $this->parent()->execute_callback( $prepared );

		return self::filter_output_to_signature_keys( $result, static::SIGNATURE_KEYS );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function prepare_parent_input( array $input ): array {
		$input['resolveSignatureOnly'] = true;
		unset( $input['clientRequest'] );
		return $input;
	}

	/**
	 * @param array<int, string> $signature_keys
	 * @return array<string, mixed>
	 */
	public static function filter_output_to_signature_keys( mixed $output, array $signature_keys ): array {
		if ( ! \is_array( $output ) ) {
			return [];
		}

		$filtered = [];
		foreach ( $signature_keys as $key ) {
			if ( isset( $output[ $key ] ) ) {
				$filtered[ $key ] = $output[ $key ];
			}
		}

		return $filtered;
	}

	private function parent(): Abstract_Ability {
		if ( null !== $this->parent_instance ) {
			return $this->parent_instance;
		}

		$parent_class = static::PARENT_CLASS;
		$parent_id    = static::PARENT_ABILITY;
		$catalog      = Registration::recommendation_ability_classes();
		$definition   = $catalog[ $parent_id ] ?? null;

		$properties = [
			'label'         => \is_array( $definition ) ? ( $definition['label'] ?? '' ) : '',
			'description'   => \is_array( $definition ) ? ( $definition['description'] ?? '' ) : '',
			'category'      => 'flavor-agent',
			'ability_class' => $parent_class,
		];

		$this->parent_instance = new $parent_class( $parent_id, $properties );

		return $this->parent_instance;
	}
}
```

- [ ] **Step 4: Create the Block concrete subclass**

Create `inc/AI/Abilities/PreviewRecommendBlockAbility.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendBlockAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-block';
	protected const PARENT_CLASS   = RecommendBlockAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-block';
	protected const SIGNATURE_KEYS = [ 'resolvedContextSignature' ];
}
```

- [ ] **Step 5: Add Registration plumbing**

Edit `inc/Abilities/Registration.php`. At the top of the file, add the import:

```php
use FlavorAgent\AI\Abilities\PreviewRecommendBlockAbility;
```

Inside the `register_abilities()` method (currently lines 35–41), append the preview registration call after the existing calls:

```php
	public static function register_abilities(): void {
		self::register_block_abilities();
		self::register_pattern_abilities();
		self::register_template_abilities();
		self::register_wordpress_docs_abilities();
		self::register_infra_abilities();

		if ( FeatureBootstrap::canonical_contracts_available() ) {
			self::register_preview_recommendation_abilities();
		}
	}
```

Add three new public/private methods to the class (place them next to `recommendation_ability_classes()` for cohesion, around line 102):

```php
	public static function register_preview_recommendation_abilities(): void {
		if ( ! FeatureBootstrap::canonical_contracts_available() ) {
			return;
		}

		foreach ( self::preview_recommendation_ability_classes() as $ability_id => $definition ) {
			wp_register_ability(
				$ability_id,
				[
					'label'         => $definition['label'],
					'description'   => $definition['description'],
					'category'      => 'flavor-agent',
					'ability_class' => $definition['ability_class'],
				]
			);
		}
	}

	/**
	 * @return array<string, array{label: string, description: string, ability_class: class-string}>
	 */
	public static function preview_recommendation_ability_classes(): array {
		return [
			'flavor-agent/preview-recommend-block' => [
				'label'         => __( 'Preview block recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the apply-context signature for a block recommendation request without invoking the AI Connector. Read-only preflight for the Ability Explorer and MCP clients.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendBlockAbility::class,
			],
		];
	}

	public static function preview_recommendation_meta(): array {
		return [
			'show_in_rest' => true,
			'readonly'     => true,
			'mcp'          => [
				'public' => true,
				'type'   => 'tool',
			],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
		];
	}
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
vendor/bin/phpunit --filter test_register_preview_recommendation_abilities_registers_block --no-coverage
```

Expected: `OK (1 test, 9 assertions)`.

- [ ] **Step 7: Run the full RegistrationTest to verify no regressions**

```bash
vendor/bin/phpunit --filter RegistrationTest --no-coverage
```

Expected: `OK (31 tests, ...)`. (Was 30; +1 for the new test.)

- [ ] **Step 8: Run the helper tests to confirm Task 1 still passes**

```bash
vendor/bin/phpunit --filter PreviewRecommendationAbilityTest --no-coverage
```

Expected: `OK (5 tests, 5 assertions)`.

- [ ] **Step 9: Commit**

```bash
git add inc/AI/Abilities/PreviewRecommendationAbility.php \
        inc/AI/Abilities/PreviewRecommendBlockAbility.php \
        inc/Abilities/Registration.php \
        tests/phpunit/RegistrationTest.php
git commit -m "$(cat <<'EOF'
Register preview-recommend-block sibling ability

Adds the abstract instance methods (input/output schema derivation, meta,
permission delegation, execute_callback wiring) plus the first concrete
subclass and Registration plumbing. preview_recommendation_meta() declares
readonly/idempotent annotations and opts into the default MCP bridge.
Registration is guarded by FeatureBootstrap::canonical_contracts_available()
so it stays inert in environments without the AI plugin contracts.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Add remaining four concrete subclasses + catalog entries (TDD)

**Files:**
- Create: `inc/AI/Abilities/PreviewRecommendNavigationAbility.php`
- Create: `inc/AI/Abilities/PreviewRecommendStyleAbility.php`
- Create: `inc/AI/Abilities/PreviewRecommendTemplateAbility.php`
- Create: `inc/AI/Abilities/PreviewRecommendTemplatePartAbility.php`
- Modify: `inc/Abilities/Registration.php` (extend `preview_recommendation_ability_classes()`, add four imports)
- Modify: `tests/phpunit/RegistrationTest.php` (add multi-surface test)

- [ ] **Step 1: Write failing test asserting all five preview abilities register with correct signatures**

Append to `tests/phpunit/RegistrationTest.php`:

```php
	public function test_register_preview_recommendation_abilities_registers_all_five(): void {
		Registration::register_category();
		Registration::register_abilities();

		$expected = [
			'flavor-agent/preview-recommend-block'         => [ 'resolvedContextSignature' ],
			'flavor-agent/preview-recommend-navigation'    => [ 'reviewContextSignature' ],
			'flavor-agent/preview-recommend-style'         => [ 'reviewContextSignature', 'resolvedContextSignature' ],
			'flavor-agent/preview-recommend-template'      => [ 'reviewContextSignature', 'resolvedContextSignature' ],
			'flavor-agent/preview-recommend-template-part' => [ 'reviewContextSignature', 'resolvedContextSignature' ],
		];

		foreach ( $expected as $ability_id => $signature_keys ) {
			$ability = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;

			$this->assertIsArray( $ability, "Expected {$ability_id} to register." );
			$this->assertSame( 'flavor-agent', $ability['category'] ?? null, "{$ability_id} category" );

			$output_props = $ability['output_schema']['properties'] ?? [];
			$this->assertSame(
				$signature_keys,
				array_keys( $output_props ),
				"{$ability_id} output_schema must expose exactly its per-surface signature keys."
			);
		}
	}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit --filter test_register_preview_recommendation_abilities_registers_all_five --no-coverage
```

Expected: First failure — `Expected flavor-agent/preview-recommend-navigation to register.`

- [ ] **Step 3: Create `PreviewRecommendNavigationAbility.php`**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendNavigationAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-navigation';
	protected const PARENT_CLASS   = RecommendNavigationAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-navigation';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature' ];
}
```

- [ ] **Step 4: Create `PreviewRecommendStyleAbility.php`**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendStyleAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-style';
	protected const PARENT_CLASS   = RecommendStyleAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-style';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature', 'resolvedContextSignature' ];
}
```

- [ ] **Step 5: Create `PreviewRecommendTemplateAbility.php`**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendTemplateAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-template';
	protected const PARENT_CLASS   = RecommendTemplateAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-template';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature', 'resolvedContextSignature' ];
}
```

- [ ] **Step 6: Create `PreviewRecommendTemplatePartAbility.php`**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendTemplatePartAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-template-part';
	protected const PARENT_CLASS   = RecommendTemplatePartAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-template-part';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature', 'resolvedContextSignature' ];
}
```

- [ ] **Step 7: Wire all five into `preview_recommendation_ability_classes()`**

Edit `inc/Abilities/Registration.php`. Add imports near the existing `PreviewRecommendBlockAbility` import:

```php
use FlavorAgent\AI\Abilities\PreviewRecommendNavigationAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendStyleAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendTemplateAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendTemplatePartAbility;
```

Replace the body of `preview_recommendation_ability_classes()` with:

```php
	public static function preview_recommendation_ability_classes(): array {
		return [
			'flavor-agent/preview-recommend-block' => [
				'label'         => __( 'Preview block recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the apply-context signature for a block recommendation request without invoking the AI Connector. Read-only preflight for the Ability Explorer and MCP clients.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendBlockAbility::class,
			],
			'flavor-agent/preview-recommend-navigation' => [
				'label'         => __( 'Preview navigation recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the review-freshness signature for a navigation recommendation request without invoking the AI Connector. Read-only preflight.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendNavigationAbility::class,
			],
			'flavor-agent/preview-recommend-style' => [
				'label'         => __( 'Preview style recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the review and apply-context signatures for a style recommendation request without invoking the AI Connector. Read-only preflight.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendStyleAbility::class,
			],
			'flavor-agent/preview-recommend-template' => [
				'label'         => __( 'Preview template recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the review and apply-context signatures for a template recommendation request without invoking the AI Connector. Read-only preflight.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendTemplateAbility::class,
			],
			'flavor-agent/preview-recommend-template-part' => [
				'label'         => __( 'Preview template-part recommendation signatures', 'flavor-agent' ),
				'description'   => __( 'Resolve the review and apply-context signatures for a template-part recommendation request without invoking the AI Connector. Read-only preflight.', 'flavor-agent' ),
				'ability_class' => PreviewRecommendTemplatePartAbility::class,
			],
		];
	}
```

- [ ] **Step 8: Run the test to verify it passes**

```bash
vendor/bin/phpunit --filter test_register_preview_recommendation_abilities_registers_all_five --no-coverage
```

Expected: `OK (1 test, 15 assertions)`.

- [ ] **Step 9: Run the full RegistrationTest to verify no regressions**

```bash
vendor/bin/phpunit --filter RegistrationTest --no-coverage
```

Expected: `OK (32 tests, ...)`.

- [ ] **Step 10: Commit**

```bash
git add inc/AI/Abilities/PreviewRecommendNavigationAbility.php \
        inc/AI/Abilities/PreviewRecommendStyleAbility.php \
        inc/AI/Abilities/PreviewRecommendTemplateAbility.php \
        inc/AI/Abilities/PreviewRecommendTemplatePartAbility.php \
        inc/Abilities/Registration.php \
        tests/phpunit/RegistrationTest.php
git commit -m "$(cat <<'EOF'
Add remaining four preview-recommend-* siblings

Wires up navigation, style, template, and template-part previews. Each
declares its parent class, parent ability ID, and per-surface SIGNATURE_KEYS
so output_schema and filter_output_to_signature_keys produce the right
signature subset (verified against parent recommendation_output_schema).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Lock in schema-strip + permission-delegation invariants (TDD)

**Files:**
- Modify: `tests/phpunit/RegistrationTest.php` (add invariant tests)

These tests assert behavior that already works — the goal is to pin it so a regression breaks the suite.

- [ ] **Step 1: Add invariant test for schema stripping across all five previews**

Append to `tests/phpunit/RegistrationTest.php`:

```php
	public function test_preview_recommendation_input_schemas_strip_resolve_signature_only_and_client_request(): void {
		Registration::register_category();
		Registration::register_abilities();

		foreach ( array_keys( Registration::preview_recommendation_ability_classes() ) as $ability_id ) {
			$ability    = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;
			$properties = $ability['input_schema']['properties'] ?? [];

			$this->assertArrayNotHasKey(
				'resolveSignatureOnly',
				$properties,
				"{$ability_id} input_schema must omit resolveSignatureOnly (forced server-side)."
			);
			$this->assertArrayNotHasKey(
				'clientRequest',
				$properties,
				"{$ability_id} input_schema must omit clientRequest (stripped to suppress request-token transients)."
			);
		}
	}
```

- [ ] **Step 2: Run the test to verify it passes**

```bash
vendor/bin/phpunit --filter test_preview_recommendation_input_schemas_strip --no-coverage
```

Expected: `OK (1 test, 10 assertions)`.

- [ ] **Step 3: Add permission-delegation invariant test**

Append to `tests/phpunit/RegistrationTest.php`:

```php
	public function test_preview_recommendation_permission_callback_delegates_to_parent(): void {
		Registration::register_category();
		Registration::register_abilities();

		$pairs = [
			'flavor-agent/preview-recommend-block'         => 'flavor-agent/recommend-block',
			'flavor-agent/preview-recommend-navigation'    => 'flavor-agent/recommend-navigation',
			'flavor-agent/preview-recommend-style'         => 'flavor-agent/recommend-style',
			'flavor-agent/preview-recommend-template'      => 'flavor-agent/recommend-template',
			'flavor-agent/preview-recommend-template-part' => 'flavor-agent/recommend-template-part',
		];

		// Recommendation abilities also need to be registered so the parent
		// instance can be constructed inside the preview's permission callback.
		Registration::register_recommendation_abilities();

		foreach ( $pairs as $preview_id => $parent_id ) {
			$preview = WordPressTestState::$registered_abilities[ $preview_id ] ?? null;
			$parent  = WordPressTestState::$registered_abilities[ $parent_id ] ?? null;

			$this->assertIsArray( $preview, "Expected {$preview_id} to register." );
			$this->assertIsArray( $parent, "Expected {$parent_id} to register." );

			$preview_callback = $preview['permission_callback'] ?? null;
			$parent_callback  = $parent['permission_callback'] ?? null;

			$this->assertIsCallable( $preview_callback );
			$this->assertIsCallable( $parent_callback );

			$input = [];
			$this->assertSame(
				(bool) $parent_callback( $input ),
				(bool) $preview_callback( $input ),
				"{$preview_id} must return the same permission decision as {$parent_id}."
			);
		}
	}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
vendor/bin/phpunit --filter test_preview_recommendation_permission_callback_delegates_to_parent --no-coverage
```

Expected: `OK (1 test, 15 assertions)`.

- [ ] **Step 5: Add invariant test for "available without recommendation feature gate"**

Append to `tests/phpunit/RegistrationTest.php`:

```php
	public function test_preview_recommendation_abilities_register_without_feature_gate(): void {
		Registration::register_category();
		Registration::register_abilities();

		// register_abilities() is the always-on path; recommendation_feature_enabled() is irrelevant.
		// Verify all 5 previews appear without ever calling register_recommendation_abilities().
		foreach ( array_keys( Registration::preview_recommendation_ability_classes() ) as $ability_id ) {
			$this->assertArrayHasKey(
				$ability_id,
				WordPressTestState::$registered_abilities,
				"{$ability_id} must be available before the Flavor Agent recommendation feature gate is enabled."
			);
		}

		// Sanity: parents stay gated and are NOT registered by register_abilities() alone.
		foreach ( array_keys( Registration::recommendation_ability_classes() ) as $parent_id ) {
			$this->assertArrayNotHasKey(
				$parent_id,
				WordPressTestState::$registered_abilities,
				"{$parent_id} must remain feature-gated and absent without register_recommendation_abilities()."
			);
		}
	}
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
vendor/bin/phpunit --filter test_preview_recommendation_abilities_register_without_feature_gate --no-coverage
```

Expected: `OK (1 test, 12 assertions)`.

- [ ] **Step 7: Commit**

```bash
git add tests/phpunit/RegistrationTest.php
git commit -m "$(cat <<'EOF'
Lock in preview-recommend-* invariants

Three regression guards: input_schema strips both resolveSignatureOnly and
clientRequest, permission_callback delegates to the parent (matching
post-scoped behavior), and previews register through the always-on
register_abilities() path while parents remain gated to the AI feature.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Surface preview IDs in `InfraAbilities::available_abilities()` (TDD)

**Files:**
- Modify: `inc/Abilities/InfraAbilities.php` (lines 109–141)
- Modify: `tests/phpunit/InfraAbilitiesTest.php`

- [ ] **Step 1: Find an existing check-status test to model the new one on**

Run:

```bash
grep -n "availableAbilities\|test_check_status\|preview-recommend" tests/phpunit/InfraAbilitiesTest.php | head -20
```

Use the existing test pattern (anywhere `availableAbilities` is asserted) as the template for the new test.

- [ ] **Step 2: Write failing test that check-status surfaces all 5 preview IDs for an admin user**

Append to `tests/phpunit/InfraAbilitiesTest.php` (just before the closing `}` of the class):

```php
	public function test_check_status_lists_preview_recommendation_abilities_for_editors(): void {
		WordPressTestState::$current_user_capabilities = [ 'edit_posts' => true, 'edit_theme_options' => true ];

		$result = InfraAbilities::check_status( [] );

		$abilities = $result['availableAbilities'] ?? [];

		foreach (
			[
				'flavor-agent/preview-recommend-block',
				'flavor-agent/preview-recommend-navigation',
				'flavor-agent/preview-recommend-style',
				'flavor-agent/preview-recommend-template',
				'flavor-agent/preview-recommend-template-part',
			] as $expected
		) {
			$this->assertContains(
				$expected,
				$abilities,
				"check-status should surface {$expected} so operators can discover it before enabling the recommendation feature."
			);
		}
	}
```

> **Note:** if the test bootstrap exposes capability state under a different field than `WordPressTestState::$current_user_capabilities`, adapt to the existing convention. Run `grep -n "current_user_can\|current_user_capabilities" tests/phpunit/support/WordPressTestState.php tests/phpunit/bootstrap.php | head -10` to find the right entry point.

- [ ] **Step 3: Run the test to verify it fails**

```bash
vendor/bin/phpunit --filter test_check_status_lists_preview_recommendation_abilities --no-coverage
```

Expected: `Failed asserting that array does not contain 'flavor-agent/preview-recommend-block'.` (preview IDs not yet in the list)

- [ ] **Step 4: Extend `available_abilities()`**

Edit `inc/Abilities/InfraAbilities.php`. After the existing `recommend-style` line (line 137), add the five preview entries:

```php
		self::maybe_add_ability( $abilities, 'flavor-agent/preview-recommend-block', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/preview-recommend-navigation', 'edit_theme_options' );
		self::maybe_add_ability( $abilities, 'flavor-agent/preview-recommend-style', 'edit_theme_options' );
		self::maybe_add_ability( $abilities, 'flavor-agent/preview-recommend-template', 'edit_theme_options' );
		self::maybe_add_ability( $abilities, 'flavor-agent/preview-recommend-template-part', 'edit_theme_options' );
```

(No `$enabled` argument — preview abilities have no backend dependency.)

- [ ] **Step 5: Run the test to verify it passes**

```bash
vendor/bin/phpunit --filter test_check_status_lists_preview_recommendation_abilities --no-coverage
```

Expected: `OK (1 test, 5 assertions)`.

- [ ] **Step 6: Run the full InfraAbilitiesTest to verify no regressions**

```bash
vendor/bin/phpunit --filter InfraAbilitiesTest --no-coverage
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add inc/Abilities/InfraAbilities.php tests/phpunit/InfraAbilitiesTest.php
git commit -m "$(cat <<'EOF'
Surface preview-recommend-* abilities in check-status

Adds the five preview IDs to InfraAbilities::available_abilities() so
flavor-agent/check-status reports them. Block uses edit_posts, the other
four use edit_theme_options, matching their parents. No backend dependency
flag is passed because preview execution never reaches the chat backend.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Update annotation-coverage loops in `RegistrationTest`

**Files:**
- Modify: `tests/phpunit/RegistrationTest.php` (existing read-abilities annotation loop at ~line 225)

- [ ] **Step 1: Read the existing annotation loop**

Run:

```bash
grep -n "test_register_abilities_emits_annotations_for_read_abilities\|introspect-block\|list-allowed-blocks" tests/phpunit/RegistrationTest.php | head -10
```

Read 30 lines of context around the read-abilities loop:

```bash
sed -n '214,260p' tests/phpunit/RegistrationTest.php
```

- [ ] **Step 2: Extend the read-abilities loop with the five preview IDs**

In `tests/phpunit/RegistrationTest.php`, locate the array literal inside `test_register_abilities_emits_annotations_for_read_abilities()` (the loop iterates over read abilities like `flavor-agent/introspect-block`, `flavor-agent/list-patterns`, etc.). Add the five preview IDs to that array:

```php
			'flavor-agent/preview-recommend-block',
			'flavor-agent/preview-recommend-navigation',
			'flavor-agent/preview-recommend-style',
			'flavor-agent/preview-recommend-template',
			'flavor-agent/preview-recommend-template-part',
```

(Maintain the existing trailing-comma style.)

- [ ] **Step 3: Run the loop test to verify it still passes with the new entries**

```bash
vendor/bin/phpunit --filter test_register_abilities_emits_annotations_for_read_abilities --no-coverage
```

Expected: PASS, with assertion count up by 5.

- [ ] **Step 4: Run the full RegistrationTest one more time**

```bash
vendor/bin/phpunit --filter RegistrationTest --no-coverage
```

Expected: all green, ~35 tests total.

- [ ] **Step 5: Commit**

```bash
git add tests/phpunit/RegistrationTest.php
git commit -m "$(cat <<'EOF'
Cover preview-recommend-* in read-ability annotation loop

Extends the existing read-ability annotations loop so the readonly /
destructive:false / idempotent:true contract is enforced for all five new
previews alongside the existing helpers.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Doc updates — `CLAUDE.md` + `abilities-and-routes.md`

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/reference/abilities-and-routes.md`

- [ ] **Step 1: Locate the count + inventory line in `CLAUDE.md`**

Run:

```bash
grep -n "20 abilities\|abilities across" CLAUDE.md | head -5
```

Read the surrounding context (the Abilities API bullet under "Key Integration Points"):

```bash
grep -n "Abilities API.*[0-9]\+ abilities\|abilities across.*categories" CLAUDE.md
```

- [ ] **Step 2: Update the count and inventory in `CLAUDE.md`**

In `CLAUDE.md`, change `20 abilities` to `25 abilities`. If the bullet enumerates ability categories, add `, preview` (or extend the description so it covers the new preflight tier).

After the line, add a sub-bullet describing the preview tier:

```markdown
  - 5 read-only preview abilities (`flavor-agent/preview-recommend-{block,navigation,style,template,template-part}`) registered through the always-on helper path. They mirror their parent's input schema (minus `resolveSignatureOnly` and `clientRequest`), force the dry-run path server-side, and return the per-surface freshness signature only. Always available behind `FeatureBootstrap::canonical_contracts_available()`; MCP-public on the default ability bridge; not exposed via the dedicated Flavor Agent MCP server in this tier.
```

- [ ] **Step 3: Locate the canonical map in `abilities-and-routes.md`**

```bash
grep -n "flavor-agent/recommend\|flavor-agent/list\|flavor-agent/check-status" docs/reference/abilities-and-routes.md | head -10
```

- [ ] **Step 4: Add the five preview entries to the canonical map**

Following the established row format for existing read abilities (likely a markdown table with columns for ID, category, capability, description), append five rows. For example:

```markdown
| `flavor-agent/preview-recommend-block` | `flavor-agent` | `edit_posts` (delegated) | Resolves a block recommendation's apply-context signature without calling the model. Read-only preflight. |
| `flavor-agent/preview-recommend-navigation` | `flavor-agent` | `edit_theme_options` (delegated) | Resolves a navigation recommendation's review-freshness signature without calling the model. |
| `flavor-agent/preview-recommend-style` | `flavor-agent` | `edit_theme_options` (delegated) | Resolves both signatures for a style recommendation request without calling the model. |
| `flavor-agent/preview-recommend-template` | `flavor-agent` | `edit_theme_options` (delegated) | Resolves both signatures for a template recommendation request without calling the model. |
| `flavor-agent/preview-recommend-template-part` | `flavor-agent` | `edit_theme_options` (delegated) | Resolves both signatures for a template-part recommendation request without calling the model. |
```

(Match exact column count and column order of the existing table — read the file to confirm before pasting.)

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md docs/reference/abilities-and-routes.md
git commit -m "$(cat <<'EOF'
Document preview-recommend-* abilities in CLAUDE.md and ability map

Updates the count from 20 to 25 abilities, adds an explanatory sub-bullet
describing the preview tier's contract (always-on, dry-run only, default
MCP bridge), and extends the canonical abilities-and-routes map with the
five new entries.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Run `check:docs`, update flagged narrative docs, final verify

**Files:** TBD by `npm run check:docs`. Likely candidates: `STATUS.md`, `docs/SOURCE_OF_TRUTH.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/flavor-agent-readme.md`, `docs/reference/wordpress-ai-roadmap-tracking.md`, `.github/copilot-instructions.md`.

- [ ] **Step 1: Run `check:docs` to surface stale references**

```bash
npm run check:docs 2>&1 | tee /tmp/check-docs-output.txt
```

Expected: Either passes cleanly or names specific files with stale ability counts / surface inventories.

- [ ] **Step 2: For each flagged file, update the references**

For every file the previous step names, open it, find the stale `20 abilities` / surface count / inventory references, and update them. Common patterns:

- `STATUS.md` — feature inventory likely lists each ability surface; add the preview tier as a new bullet.
- `docs/SOURCE_OF_TRUTH.md` — count and inventory; mirror the CLAUDE.md update.
- `docs/FEATURE_SURFACE_MATRIX.md` — likely a table of surfaces; add preview as a new row or column.
- `docs/flavor-agent-readme.md` — narrative; add a paragraph next to the abilities discussion.
- `.github/copilot-instructions.md` — likely mirrors CLAUDE.md; same edits.

After updating each, re-run `npm run check:docs` until it passes.

- [ ] **Step 3: Run targeted PHPUnit suite**

```bash
vendor/bin/phpunit --filter "Registration|PreviewRecommendation|InfraAbilities" --no-coverage
```

Expected: all green.

- [ ] **Step 4: Run the full verify pipeline (minus E2E and plugin-check)**

```bash
node scripts/verify.js --skip-e2e --skip=lint-plugin
```

Expected: `VERIFY_RESULT={"status":"pass",...}`.

- [ ] **Step 5: Commit doc fallout (only if Step 2 made changes)**

```bash
git add -- <each file edited in Step 2>
git commit -m "$(cat <<'EOF'
Update narrative docs for preview-recommend-* abilities

Surfaces the new preview tier in STATUS, SOURCE_OF_TRUTH, FEATURE_SURFACE_MATRIX,
flavor-agent-readme, copilot-instructions, and any other docs flagged by
check:docs. Counts and surface inventories now reflect 25 abilities.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

(Skip if `check:docs` was clean and no further changes were made.)

- [ ] **Step 6: Final sanity — review the commit log**

```bash
git log --oneline master ^origin/master
```

Expected: 7–8 commits on top of the spec commit (`60ae7a2`), each with a clear single-purpose message:

```
- Add PreviewRecommendationAbility abstract base with input/output helpers
- Register preview-recommend-block sibling ability
- Add remaining four preview-recommend-* siblings
- Lock in preview-recommend-* invariants
- Surface preview-recommend-* abilities in check-status
- Cover preview-recommend-* in read-ability annotation loop
- Document preview-recommend-* abilities in CLAUDE.md and ability map
- Update narrative docs for preview-recommend-* abilities    (optional, only if Task 8.5 ran)
```

---

## Self-review

**Spec coverage:**

- ✓ Five preview siblings → Tasks 2 + 3
- ✓ Parent-schema mirror minus `resolveSignatureOnly` + `clientRequest` → Task 1 (helpers) + Task 2 (`input_schema()` method) + Task 4 (invariant test)
- ✓ Output: signature-only, per-surface keys → Task 1 (`filter_output_to_signature_keys`) + Tasks 2 + 3 (registration tests verify per-surface keys)
- ✓ Always-on, AI-contract guarded → Task 2 (`canonical_contracts_available()` guard) + Task 4 (test asserting registration without feature gate)
- ✓ MCP default bridge only, no dedicated server change → Task 2 (`preview_recommendation_meta()` declares `mcp.public:true`); `ServerBootstrap.php` deliberately untouched
- ✓ `permission_callback` delegates to parent instance → Task 2 (`parent()` lazy init + `permission_callback`) + Task 4 (delegation test)
- ✓ `check-status` surfaces previews → Task 5
- ✓ Doc updates (CLAUDE.md + canonical map + narrative docs) → Tasks 7 + 8
- ✓ 20 → 25 ability count update + annotation coverage → Task 6 + Task 7
- ✓ Verification commands per CLAUDE.md → Task 8

**Placeholder scan:** No "TBD" / "implement later" / "add error handling" placeholders found. One legitimate "TBD" in Task 8's file list (the actual files depend on `check:docs` output) — explained inline with likely candidates.

**Type consistency:** Method names match across tasks — `prepare_parent_input`, `filter_output_to_signature_keys`, `parent()`, `recommendation_input_schema()`, `preview_recommendation_ability_classes()`, `preview_recommendation_meta()`, `register_preview_recommendation_abilities()`, `available_abilities()`, `maybe_add_ability()`. Class constants (`ABILITY_NAME`, `PARENT_CLASS`, `PARENT_ABILITY`, `SIGNATURE_KEYS`) used consistently in both abstract definition and concrete subclasses.

**Risk callouts from the spec:**

- **Per-surface signature presence** (spec risk #1): Verified during plan-writing, embedded in the task table at the top, and asserted in Task 3 Step 1 test.
- **Permission delegation via parent instantiation** (spec risk #2): The stub `Abstract_Ability::__construct` in the test bootstrap (line 465) only stores properties, so the lazy parent instantiation in `parent()` works in tests. In production, `Abstract_Ability::__construct` calls `$this->meta()` etc., which is fine because `RecommendationAbility::meta()` returns a static array and has no side effects.
- **Idempotent annotation honesty** (spec risk #3): Not directly tested in this plan. Add a separate regression-test task in a follow-up if needed (the spec marks this as "future" hardening, not Tier 2 scope).

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-09-tier-2-preview-recommendation-abilities.md`. Two execution options:

**1. Subagent-Driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
