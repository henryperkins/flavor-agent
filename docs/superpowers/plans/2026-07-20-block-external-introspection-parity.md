# Block External Introspection Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close four defects in server-side block introspection so the MCP-public read abilities and the external `selectedBlock` path report the same block styles the editor sees, and so a client's assertion that nothing is registered is honoured rather than discarded.

**Architecture:** Three surgical PHP changes plus their tests. D1 merges `WP_Block_Styles_Registry` into `BlockTypeIntrospector::build_block_manifest()` behind one new private collector. D2 coerces a client-supplied `className` at the single boundary where it enters `BlockContextCollector`. D4 changes three presence guards in `BlockAbilities::build_context_from_editor_context()` from "truthy" to "well-shaped", so `styles: []` reads as an assertion. D3 is a documentation correction only.

**Tech Stack:** PHP 8.2 (PSR-4, `FlavorAgent\`), PHPUnit 9.6, Jest (`@wordpress/scripts`), Playwright (Playground project), WPCS/phpcs.

## Source spec

`docs/superpowers/specs/2026-07-20-block-external-introspection-parity-design.md`.

**The spec is authoritative on intent and wrong on several facts.** Verification against the tree produced 27 anchor corrections and 14 adversarial findings. Every correction is already folded into the tasks below — *use this plan's line numbers and code, not the spec's*. The corrections that changed the design (not just an anchor) are recorded in "Design deltas from the spec" below, and the two decisions the user signed off on are in "Decisions taken".

## Global Constraints

- PHP 8.2+; WordPress 7.0+.
- `inc/Context/BlockTypeIntrospector.php` and `inc/Context/BlockContextCollector.php` both declare `declare(strict_types=1);`.
- Production PHP is **WPCS-spaced**: one space inside every paren/bracket — `( $x )`, `[ $a, $b ]` — hard-tab indent, Yoda comparisons against literals.
- `tests/phpunit/bootstrap.php` uses a **different, non-WPCS local style**: hard tabs, Allman braces on class/method declarations, **no** spaces inside parens (`if (! class_exists('X'))`). New stubs in that file must match the file, not WPCS. New *test cases* in `tests/phpunit/*Test.php` must match WPCS like their neighbours.
- Global WP classes are referenced inline with a leading backslash (`\WP_Block_Type_Registry`), never imported.
- Run PHPUnit as `vendor/bin/phpunit` (or `composer test:php`). **Never** pass `-c` / `--no-configuration`: `phpunit.xml.dist` is auto-discovered and defines `FLAVOR_AGENT_TESTS_RUNNING`, without which `tests/phpunit/bootstrap.php:8` calls `exit`.
- Baseline before any change: `vendor/bin/phpunit` → **OK (1966 tests, 8954 assertions)**. Any task that ends with fewer passing tests than it started with is a failed task.
- Do not change `build_block_manifest()`'s signature; `$block_name` is already in scope at the insertion point.
- Do not widen `extract_active_style()`'s `string $class_name` type. The single-call-site coercion is the fix.

## Decisions taken

1. **D1 is an apply-surface change, and that is accepted.** The spec's "no governed write reads manifest `styles`" is true only of PHP. The editor apply path reads it: `executionContract.registeredStyles` → `resolveExecutionContract` (`src/store/update-helpers.js:2554`) → `isValidStyleVariationSuggestion` (`:2487`) and the `case 'className':` arm of `filterAttributeUpdatesForExecutionContract` (`:1226`) → `updateBlockAttributes` (`src/store/index.js:2522`). So a `register_block_style()` style will now survive both client gates and reach post content. **That is the correct outcome** — a registry-registered style is registered — but it requires JS coverage (Task 5) and a doc sentence (Task 6), neither of which the spec listed.
2. **The client/server contract merge is left alone and recorded as an accepted risk.** At `src/store/update-helpers.js:673-683` the server contract overrides the client-derived mirror for `registeredStyles` (only `contentAttributeKeys` and `configAttributeKeys` get client fallback). So on the `selectedBlock` path a widened server list still wins over a narrower client list; D4 does not contain D1's widening there. Not changed in this slice — changing shared apply merge semantics is a wider blast radius than a parity repair. Task 5 pins the behaviour with a test so the risk is detectable rather than latent.

## Design deltas from the spec

| # | Spec said | Plan does | Why |
|---|---|---|---|
| 1 | D4 gates on `array_key_exists( 'styles', $block )` | Gates `styles`/`variations` on `is_array( ... )` | `normalize_list()` returns `[]` for *any* non-array. Under `array_key_exists`, `{"styles":"outline"}` would set `registeredStyles: []` and silently suppress every style-variation suggestion **and** block every style apply. Today that garbage input is harmless. A shape test keeps `[]` meaningful without weaponising malformed input. |
| 2 | Merge map keyed on the raw `$normalized['name']` | Keyed on `sanitize_key( $name )` | Every downstream consumer sanitizes before comparing (`BlockRecommendationExecutionContract::collect_registered_style_names` `:102-104`, `extract_active_style` `:127`, `Prompt::get_registered_style_variation_lookup` `:2587-2598`). Raw keying emits `outline` *and* `Outline` as two manifest entries while `registeredStyles` collapses to one — falsifying the spec's own "one entry per name" claim. |
| 3 | Bootstrap stub mirrors `WP_Block_Type_Registry` one-for-one | Stub mirrors **core's `WP_Block_Styles_Registry`**: two-level `$registered[block][style]`, `label` backfilled from `name`, `string\|array $block_name`, plus `unregister()` | The type registry stub is flat and has no `unregister()`. A verbatim mirror would not match core semantics, and omitting core's `label` default would make the D1 tests assert a shape real WordPress never produces. |
| 4 | `activeStyle` also gated on `array_key_exists` | Kept on `array_key_exists`, deliberately, unlike its two neighbours | Asymmetry is intentional: a malformed `styles` **suppresses a whole suggestion class**; a malformed `activeStyle` degrades to `null`, which is exactly "no active style" and is what `extract_active_style()` returns for an unmatched class. Shape-gate where the failure is destructive, presence-gate where it is benign. Documented in the code comment. |
| 5 | `build_block_manifest()` at `:52`; `collect_registered_blocks()` at `:200` | `:239-317` and `:179-234` | `:52` and `:200` are *call sites*, not declarations. |
| 6 | Disabled-seam test at `BlockAbilitiesTest.php:562-589` | `:562-594` | `:589` is mid-method. There is also a **second** disabled-seam test at `:825-840` the spec never mentions; both must stay green. |
| 7 | Playground fixture adds editor JS "in matching style" | Adds a **new** classic script + `wp_enqueue_script()` | The loader ships no classic editor JS and calls `wp_enqueue_script()` zero times — only two `wp_register_script_module()` ESM stubs that never touch the `wp` global. There is no matching style to follow. |
| 8 | New Playwright spec asserts the panel | New spec must be **untagged** | `playwright.config.js:34` sets `grepInvert: /@wp70-site-editor/`. Six of seven existing "AI Recommendations" assertions are `@wp70-site-editor`-tagged and do not run under the Playground config. A tagged spec would be grep-inverted out of exactly the suite the spec designates as required. |
| 9 | `is_valid_style_variation_suggestion()` is `:2534-2585` | `:2534-2544`, and it is **private** | `:2546-2585` is a different method. It is never named in any test; all coverage is indirect through `Prompt::enforce_block_context_rules()`. |
| 10 | `Registration.php:2669-2688` is "the validationReasons vocabulary" | It is the JSON-Schema fragment; the vocabulary is `inc/Support/ValidationReason.php:32-80` | Deliberate: `code` is a bounded string, not an enum (design decision OD-1). Out of scope here either way. |
| 11 | E2E asserts "the panel offers no style-variation suggestion" | E2E asserts the **request payload** carries `editorContext.block.styles: []` | The Playground suite's only way to produce suggestions without a live provider is `route.fulfill()`, which bypasses the server — so the proposed assertion would test the client gate against a stub, not D4. The payload assertion tests the precondition D4 honours; Task 4's PHPUnit test covers the server half. |

## File Structure

**Modified — production**
- `inc/Context/BlockTypeIntrospector.php` — D1. One changed local, one collapsed return value, two new private helpers.
- `inc/Context/BlockContextCollector.php` — D2. One argument coerced.
- `inc/Abilities/BlockAbilities.php` — D4. Three guards.

**Modified — harness & tests**
- `tests/phpunit/bootstrap.php` — new `WP_Block_Styles_Registry` stub + reset wiring.
- `tests/phpunit/BlockTypeIntrospectorTest.php` — gains `setUp()` + manifest tests.
- `tests/phpunit/BlockAbilitiesTest.php` — D1/D2/D4 context and contract tests.
- `src/store/__tests__/store-actions.test.js` — D1 apply-path regression.
- `src/store/update-helpers.test.js` — accepted-risk pin for the contract merge.

**Modified — docs**
- `docs/reference/governance-layer.md` — D3.
- `docs/superpowers/specs/2026-07-20-block-external-introspection-parity-design.md` — correct the falsified "Not affected" paragraph.

**Created**
- `tests/e2e/playground-mu-plugin/flavor-agent-playground-block-styles.js` — unregisters a style in the editor.
- A new untagged spec in `tests/e2e/` (Task 7).

---

### Task 1: D2 — coerce a non-string `className` at the collector boundary

Smallest, fully independent, no harness work. Ships first so the type-safety hole is closed even if later tasks stall.

**Files:**
- Modify: `inc/Context/BlockContextCollector.php:40-43`
- Test: `tests/phpunit/BlockAbilitiesTest.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing other tasks depend on.

**Background:** `extract_active_style( string $class_name, array $styles ): ?string` is typed `string`, and `BlockContextCollector` declares `strict_types=1`, which makes the *call* strict. `$attributes` is client-supplied and `NormalizesInput::normalize_value()` is structural only — it passes ints, floats, bools and null through unchanged — and `selectedBlock.attributes` is an open object schema with no per-property types. So `className` arrives as whatever the caller sent, and a non-string raises an uncaught `TypeError` (500, not 400) on **every** path into the collector: `selectedBlock`, `editorContext`, and the MCP-public `flavor-agent/preview-recommend-block` (whose `resolveSignatureOnly` short-circuit runs *after* input preparation, so even the dry run fatals).

- [ ] **Step 1: Write the failing tests**

Append inside `tests/phpunit/BlockAbilitiesTest.php`, before the file's closing `}`:

```php
	/**
	 * @return array<int, array{0: mixed}>
	 */
	public static function non_string_class_name_provider(): array {
		return [
			'int'   => [ 5 ],
			'float' => [ 1.5 ],
			'bool'  => [ true ],
			'array' => [ [ 'is-style-outline' ] ],
			'null'  => [ null ],
		];
	}

	/**
	 * @dataProvider non_string_class_name_provider
	 */
	public function test_selected_block_tolerates_non_string_class_name( mixed $class_name ): void {
		$result = $this->invoke_prepare_recommend_block_input(
			[
				'selectedBlock' => [
					'blockName'  => 'core/paragraph',
					'attributes' => [
						'content'   => 'Hello world',
						'className' => $class_name,
					],
				],
			]
		);

		$this->assertNull( $result['context']['block']['activeStyle'] ?? 'unset' );
	}

	/**
	 * @dataProvider non_string_class_name_provider
	 */
	public function test_editor_context_tolerates_non_string_class_name( mixed $class_name ): void {
		$result = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block' => [
						'name'              => 'core/paragraph',
						'currentAttributes' => [
							'content'   => 'Hello world',
							'className' => $class_name,
						],
					],
				],
			]
		);

		$this->assertNull( $result['context']['block']['activeStyle'] ?? 'unset' );
	}
```

- [ ] **Step 2: Run the tests and verify they fail**

```bash
vendor/bin/phpunit --filter 'tolerates_non_string_class_name' tests/phpunit/BlockAbilitiesTest.php
```

Expected: **failures**, with `TypeError: ...extract_active_style(): Argument #1 ($class_name) must be of type string` for the int/float/bool/array rows. The `null` row may already pass via the `?? ''` fallback — that is fine and is why it is in the provider.

If the `editorContext` rows do not reach the collector at all, confirm the `editorContext` shape against `build_context_from_editor_context()` and adjust the fixture keys — do **not** weaken the assertion.

- [ ] **Step 3: Implement the coercion**

In `inc/Context/BlockContextCollector.php`, replace lines 40-43:

```php
				'activeStyle'         => $this->block_type_introspector->extract_active_style(
					$attributes['className'] ?? '',
					$type_info['styles'] ?? []
				),
```

with:

```php
				'activeStyle'         => $this->block_type_introspector->extract_active_style(
					is_string( $attributes['className'] ?? null ) ? $attributes['className'] : '',
					$type_info['styles'] ?? []
				),
```

Nothing else changes. `extract_active_style( '' )` already returns `null` on its first branch, so a non-string degrades to "no active style" — the same answer as a block with no class.

- [ ] **Step 4: Run the tests and verify they pass**

```bash
vendor/bin/phpunit --filter 'tolerates_non_string_class_name' tests/phpunit/BlockAbilitiesTest.php
```

Expected: `OK (10 tests, ...)`.

- [ ] **Step 5: Run the full suite**

```bash
vendor/bin/phpunit
```

Expected: `OK` with **at least 1976 tests** (1966 baseline + 10 new).

- [ ] **Step 6: Commit**

```bash
git add inc/Context/BlockContextCollector.php tests/phpunit/BlockAbilitiesTest.php
git commit -m "Coerce non-string className at the block context boundary

A client-supplied attributes.className of any non-string type raised an
uncaught TypeError against extract_active_style()'s string parameter,
surfacing as a 500 on every path into BlockContextCollector — including
the MCP-public preview-recommend-block dry run, whose signature-only
short-circuit runs after input preparation.

Coerce at the single call site rather than widening the introspector's
contract. A non-string now degrades to 'no active style', matching what
extract_active_style() already returns for an empty class string."
```

---

### Task 2: D1 harness — stub `WP_Block_Styles_Registry`

Scaffolding for Task 3, split out because it is the one change that can break *existing* tests (by making `class_exists()` newly true process-wide) and so deserves its own green gate.

**Files:**
- Modify: `tests/phpunit/bootstrap.php` — insert after line 2000, and add one line at 445.

**Interfaces:**
- Produces: global class `WP_Block_Styles_Registry` with
  - `public static function get_instance(): self`
  - `public function register( string|array $block_name, array $style_properties ): bool`
  - `public function get_registered_styles_for_block( string $block_name ): array` — name-keyed map
  - `public function get_all_registered(): array`
  - `public function unregister( string $block_name, string $style_name ): bool`
  - `public function reset(): void`

- [ ] **Step 1: Add the stub**

In `tests/phpunit/bootstrap.php`, insert between line 2000 (`	}` closing the `WP_Block_Type_Registry` guard) and line 2002 (`	if (! class_exists('WP_Block_Patterns_Registry')) {`).

**Match bootstrap.php's local style exactly: hard tabs, Allman braces, no spaces inside parens.** This file is not phpcs-linted; the surrounding stubs are the style authority.

```php
	if (! class_exists('WP_Block_Styles_Registry')) {
		class WP_Block_Styles_Registry
		{

			private static ?self $instance = null;

			/**
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			private array $registered = [];

			public static function get_instance(): self
			{
				if (null === self::$instance) {
					self::$instance = new self();
				}

				return self::$instance;
			}

			/**
			 * @param string|string[] $block_name
			 * @param array<string, mixed> $style_properties
			 */
			public function register($block_name, array $style_properties): bool
			{
				$style_name = $style_properties['name'] ?? '';

				if (! is_string($style_name) || '' === $style_name) {
					return false;
				}

				// Core backfills the label from the name when it is absent.
				if (empty($style_properties['label'])) {
					$style_properties['label'] = $style_name;
				}

				$block_names = is_array($block_name) ? $block_name : [$block_name];

				foreach ($block_names as $name) {
					$this->registered[(string) $name][$style_name] = $style_properties;
				}

				return true;
			}

			/**
			 * @return array<string, array<string, mixed>>
			 */
			public function get_registered_styles_for_block(string $block_name): array
			{
				return $this->registered[$block_name] ?? [];
			}

			/**
			 * @return array<string, array<string, array<string, mixed>>>
			 */
			public function get_all_registered(): array
			{
				return $this->registered;
			}

			public function unregister(string $block_name, string $style_name): bool
			{
				if (! isset($this->registered[$block_name][$style_name])) {
					return false;
				}

				unset($this->registered[$block_name][$style_name]);

				return true;
			}

			public function reset(): void
			{
				$this->registered = [];
			}
		}
	}
```

- [ ] **Step 2: Wire the reset**

In `tests/phpunit/bootstrap.php`, after line 445 (`			\WP_Block_Patterns_Registry::get_instance()->reset();`), add:

```php
			\WP_Block_Styles_Registry::get_instance()->reset();
```

Registered styles must not leak between tests. This runs from `WordPressTestState::reset()`, which `BlockAbilitiesTest::setUp()` already calls.

- [ ] **Step 3: Verify the stub breaks nothing**

```bash
vendor/bin/phpunit
```

Expected: `OK`, same test count as after Task 1 (no new tests yet). This step exists precisely to catch a test that depended on `class_exists( 'WP_Block_Styles_Registry' )` being false. If anything goes red here, stop and report it — do not adjust the stub to make an unrelated test pass.

- [ ] **Step 4: Commit**

```bash
git add tests/phpunit/bootstrap.php
git commit -m "Stub WP_Block_Styles_Registry in the PHPUnit bootstrap

Mirrors core's two-level shape (block name -> style name -> properties),
including the label-from-name backfill, string|array first parameter and
unregister(), so tests assert against the shape real WordPress produces
rather than a convenient simplification.

Reset wired into WordPressTestState::reset() beside the block type and
pattern registries so registrations do not leak between tests."
```

---

### Task 3: D1 — merge `register_block_style()` registrations into the manifest

**Files:**
- Modify: `inc/Context/BlockTypeIntrospector.php:248`, `:294-301`, and insert two helpers after `:317`
- Test: `tests/phpunit/BlockTypeIntrospectorTest.php`

**Interfaces:**
- Consumes: `WP_Block_Styles_Registry` from Task 2.
- Produces:
  - `private function collect_block_styles( string $block_name, object $block_type ): array` — returns a list of `['name' => string, 'label' => string, 'isDefault' => bool]`
  - `private function normalize_block_style( mixed $style ): ?array` — same shape or `null`

**Background:** `register_block_style()` writes **only** to `WP_Block_Styles_Registry` and never touches `WP_Block_Type::$styles`. The editor stays complete because `enqueue_editor_block_styles_assets()` emits an explicit `wp.blocks.registerBlockStyle()` call per registration. Core's own block-types REST controller merges the registry into its `styles` field. Flavor Agent's two MCP-public introspection abilities (`introspect-block`, `list-allowed-blocks`) do neither — they read `$block_type->styles` alone, so external agents receive a list missing every `register_block_style()` registration, and `executionContract.registeredStyles` inherits the same gap.

- [ ] **Step 1: Give `BlockTypeIntrospectorTest` state isolation**

The file today has no `setUp`, so the Task 2 `reset()` would never fire for it. Replace lines 1-10 of `tests/phpunit/BlockTypeIntrospectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\BlockTypeIntrospector;
use PHPUnit\Framework\TestCase;

final class BlockTypeIntrospectorTest extends TestCase {
```

with:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\BlockTypeIntrospector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class BlockTypeIntrospectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/phpunit/BlockTypeIntrospectorTest.php`, before the closing `}`. **WPCS spacing** here — this file is linted.

```php
	private function register_block_with_styles( string $block_name, array $styles ): void {
		\WP_Block_Type_Registry::get_instance()->register(
			$block_name,
			[
				'title'  => 'Fixture',
				'styles' => $styles,
			]
		);
	}

	public function test_manifest_includes_registry_registered_styles(): void {
		$this->register_block_with_styles( 'fixture/card', [] );
		\WP_Block_Styles_Registry::get_instance()->register(
			'fixture/card',
			[
				'name'       => 'outline',
				'label'      => 'Outline',
				'is_default' => true,
			]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		$this->assertSame(
			[
				[
					'name'      => 'outline',
					'label'     => 'Outline',
					'isDefault' => true,
				],
			],
			$manifest['styles'] ?? null
		);
	}

	public function test_manifest_backfills_registry_style_label_from_name(): void {
		$this->register_block_with_styles( 'fixture/card', [] );
		\WP_Block_Styles_Registry::get_instance()->register(
			'fixture/card',
			[ 'name' => 'outline' ]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		$this->assertSame( 'outline', $manifest['styles'][0]['label'] ?? null );
		$this->assertFalse( $manifest['styles'][0]['isDefault'] ?? null );
	}

	public function test_manifest_prefers_block_json_style_on_name_collision(): void {
		$this->register_block_with_styles(
			'fixture/card',
			[
				[
					'name'      => 'outline',
					'label'     => 'JSON Outline',
					'isDefault' => false,
				],
			]
		);
		\WP_Block_Styles_Registry::get_instance()->register(
			'fixture/card',
			[
				'name'       => 'outline',
				'label'      => 'Registry Outline',
				'is_default' => true,
			]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		$this->assertCount( 1, $manifest['styles'] ?? [] );
		$this->assertSame( 'JSON Outline', $manifest['styles'][0]['label'] ?? null );
		$this->assertFalse( $manifest['styles'][0]['isDefault'] ?? null );
	}

	public function test_manifest_dedupes_styles_on_the_sanitized_name(): void {
		$this->register_block_with_styles(
			'fixture/card',
			[
				[
					'name'  => 'outline',
					'label' => 'JSON Outline',
				],
			]
		);
		\WP_Block_Styles_Registry::get_instance()->register(
			'fixture/card',
			[
				'name'  => 'Outline',
				'label' => 'Registry Outline',
			]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		// Downstream consumers all sanitize before comparing, so the manifest
		// must collapse case variants the same way registeredStyles does.
		$this->assertCount( 1, $manifest['styles'] ?? [] );
		$this->assertSame( 'JSON Outline', $manifest['styles'][0]['label'] ?? null );
	}

	public function test_manifest_keeps_block_json_styles_when_registry_is_empty(): void {
		$this->register_block_with_styles(
			'fixture/card',
			[
				[
					'name'      => 'plain',
					'label'     => 'Plain',
					'isDefault' => true,
				],
			]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		$this->assertSame(
			[
				[
					'name'      => 'plain',
					'label'     => 'Plain',
					'isDefault' => true,
				],
			],
			$manifest['styles'] ?? null
		);
	}

	public function test_manifest_drops_styles_with_no_usable_name(): void {
		$this->register_block_with_styles(
			'fixture/card',
			[
				[ 'label' => 'Nameless' ],
				'not-an-array',
				[
					'name'  => 'plain',
					'label' => 'Plain',
				],
			]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		$this->assertSame(
			[
				[
					'name'      => 'plain',
					'label'     => 'Plain',
					'isDefault' => false,
				],
			],
			$manifest['styles'] ?? null
		);
	}

	public function test_list_registered_blocks_carries_registry_styles(): void {
		$this->register_block_with_styles( 'fixture/card', [] );
		\WP_Block_Styles_Registry::get_instance()->register(
			'fixture/card',
			[
				'name'  => 'outline',
				'label' => 'Outline',
			]
		);

		$manifests = ( new BlockTypeIntrospector() )->list_registered_blocks();
		$card      = null;

		foreach ( $manifests as $manifest ) {
			if ( 'fixture/card' === ( $manifest['name'] ?? '' ) ) {
				$card = $manifest;
				break;
			}
		}

		$this->assertIsArray( $card, 'fixture/card missing from list_registered_blocks()' );
		$this->assertSame( 'outline', $card['styles'][0]['name'] ?? null );
	}
```

Note the last test locates its block **by name** rather than by index — `list_registered_blocks()` sorts its results.

- [ ] **Step 3: Run the tests and verify they fail**

```bash
vendor/bin/phpunit tests/phpunit/BlockTypeIntrospectorTest.php
```

Expected: the four registry-dependent tests fail with the manifest reporting `[]` styles; `keeps_block_json_styles_when_registry_is_empty` passes already; `drops_styles_with_no_usable_name` fails because today's projection emits `['name' => '', 'label' => 'Nameless', 'isDefault' => false]` and chokes on the string entry.

- [ ] **Step 4: Change the `$styles` local**

In `inc/Context/BlockTypeIntrospector.php`, replace line 248:

```php
		$styles                = $block_type->styles ?? [];
```

with:

```php
		$styles                = $this->collect_block_styles( $block_name, $block_type );
```

The `=` alignment column is unchanged, so no re-padding of the surrounding block is needed.

- [ ] **Step 5: Collapse the inline projection**

`$styles` is now already normalised, so the `array_map` is redundant. Replace lines 294-301:

```php
			'styles'              => array_map(
				static fn( $style ) => [
					'name'      => $style['name'] ?? '',
					'label'     => $style['label'] ?? '',
					'isDefault' => $style['isDefault'] ?? false,
				],
				$styles
			),
```

with:

```php
			'styles'              => $styles,
```

- [ ] **Step 6: Add the collector**

Insert into `inc/Context/BlockTypeIntrospector.php` after line 317 (the `}` closing `build_block_manifest()`) and before line 319 (`	private function matches_registered_block_filters(`), separated by a blank line on each side:

```php
	/**
	 * Merge block.json styles with register_block_style() registrations.
	 *
	 * register_block_style() writes only to WP_Block_Styles_Registry and never
	 * touches WP_Block_Type::$styles. The editor stays complete because core
	 * emits an explicit registerBlockStyle() call per registration, and core's
	 * own block-types REST controller merges the registry into its styles
	 * field — so reading $block_type->styles alone leaves server-derived
	 * introspection short of both.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_block_styles( string $block_name, object $block_type ): array {
		$sources = (array) ( $block_type->styles ?? [] );

		if ( class_exists( '\WP_Block_Styles_Registry' ) ) {
			$registry = \WP_Block_Styles_Registry::get_instance();

			if ( method_exists( $registry, 'get_registered_styles_for_block' ) ) {
				foreach ( $registry->get_registered_styles_for_block( $block_name ) as $style ) {
					$sources[] = $style;
				}
			}
		}

		$styles = [];

		foreach ( $sources as $style ) {
			$normalized = $this->normalize_block_style( $style );

			if ( null === $normalized ) {
				continue;
			}

			// First writer wins, so block.json survives a collision with the
			// registry — matching the editor, whose getUniqueItemsByName keeps
			// the first occurrence and always sees block.json first. Keyed on
			// the sanitized name because every downstream consumer sanitizes
			// before comparing.
			$styles[ sanitize_key( $normalized['name'] ) ] ??= $normalized;
		}

		return array_values( $styles );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function normalize_block_style( mixed $style ): ?array {
		if ( ! is_array( $style ) ) {
			return null;
		}

		$name = is_string( $style['name'] ?? null ) ? $style['name'] : '';

		if ( '' === sanitize_key( $name ) ) {
			return null;
		}

		return [
			'name'      => $name,
			'label'     => is_string( $style['label'] ?? null ) ? $style['label'] : '',
			// block.json uses isDefault; the styles registry uses is_default.
			'isDefault' => (bool) ( $style['isDefault'] ?? $style['is_default'] ?? false ),
		];
	}
```

The `class_exists` / `method_exists` pair is production defence-in-depth and mirrors the existing `method_exists( $registry, 'get_all_registered' )` precedent at `:185-187`. It stays deliberately uncovered: once Task 2's stub exists, `class_exists()` is unconditionally true in-process, and the false branch is unreachable without `@runInSeparateProcess` or an injected resolver — not worth the machinery for a one-line guard.

- [ ] **Step 7: Run the tests and verify they pass**

```bash
vendor/bin/phpunit tests/phpunit/BlockTypeIntrospectorTest.php
```

Expected: `OK (8 tests, ...)`.

- [ ] **Step 8: Run the full suite and phpcs**

```bash
vendor/bin/phpunit
composer lint:php
```

Expected: `OK`, and phpcs clean. `PromptRulesTest` and `BlockAbilitiesTest` both consume `registeredStyles`; a regression there means the merge changed a shape it should not have.

- [ ] **Step 9: Commit**

```bash
git add inc/Context/BlockTypeIntrospector.php tests/phpunit/BlockTypeIntrospectorTest.php
git commit -m "Merge register_block_style() registrations into the block manifest

register_block_style() writes only to WP_Block_Styles_Registry, which
build_block_manifest() never consulted — so the MCP-public introspect-block
and list-allowed-blocks abilities reported a style list missing every such
registration, and executionContract.registeredStyles inherited the gap,
silently dropping legitimate style-variation suggestions.

Core's own block-types REST controller already merges both sources; this
brings server-derived introspection to the same parity. block.json wins a
name collision, matching the editor. Deduped on the sanitized name because
every downstream consumer sanitizes before comparing.

JS-only registerBlockStyle() registrations remain invisible server-side by
construction; that residual gap is documented on the affected abilities."
```

---

### Task 4: D4 — honour a client-asserted empty list

**Files:**
- Modify: `inc/Abilities/BlockAbilities.php:344-352` and `:364-367`
- Test: `tests/phpunit/BlockAbilitiesTest.php`

**Interfaces:**
- Consumes: Task 3's widened server style list (which is what makes the `styles` leak worse).
- Produces: nothing other tasks depend on.

**Background:** The editor **always** sends all three keys — `collectBlockContext` enumerates `styles`, `activeStyle` and `variations` unconditionally — and each has a legitimate empty value (`getBlockStyles()` returns `[]` after the last `unregisterBlockStyle()`; `activeStyle` is `null` whenever nothing matches). `! empty()` cannot distinguish "client omitted the key" from "client asserted nothing is registered", so a stale server value survives. Task 3 widens the server list, so the set that wrongly reappears grows.

`activeStyle` leaks through the same scenario even after `styles` is fixed: with `styles: []` the client sends `activeStyle: null`, which fails `is_string()`, so the server-derived value survives and the prompt carries `Active style: outline` alongside `registeredStyles: []` — a self-contradicting context that re-asserts the removed style by name. Fixing `styles` alone moves the leak rather than closing it.

**Note the pre-existing indentation anomaly.** Lines 344, 349, 354 and 359 carry **three** tabs while their `if` bodies and every sibling guard carry two. The replacements below normalise the two lines they touch to two tabs, matching `:364`, `:373` and `:378`. Lines 354 and 359 are not part of this change and keep their odd indentation.

- [ ] **Step 1: Write the failing tests**

Append to `tests/phpunit/BlockAbilitiesTest.php` before the closing `}`:

```php
	public function test_editor_context_empty_styles_is_an_assertion(): void {
		$result = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block' => [
						'name'   => 'core/paragraph',
						'styles' => [],
					],
				],
			]
		);

		$this->assertSame( [], $result['context']['block']['styles'] ?? null );
	}

	public function test_editor_context_omitted_styles_falls_back_to_the_server_list(): void {
		$result = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block' => [
						'name' => 'core/paragraph',
					],
				],
			]
		);

		$names = array_column( $result['context']['block']['styles'] ?? [], 'name' );

		$this->assertContains( 'outline', $names );
	}

	public function test_editor_context_malformed_styles_is_not_an_assertion(): void {
		$result = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block' => [
						'name'   => 'core/paragraph',
						'styles' => 'outline',
					],
				],
			]
		);

		// A non-array is unusable, not an assertion of emptiness — wiping the
		// server list here would suppress every style-variation suggestion.
		$names = array_column( $result['context']['block']['styles'] ?? [], 'name' );

		$this->assertContains( 'outline', $names );
	}

	public function test_editor_context_null_active_style_is_an_assertion(): void {
		$result = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block' => [
						'name'              => 'core/paragraph',
						'currentAttributes' => [
							'className' => 'is-style-outline',
						],
						'styles'            => [],
						'activeStyle'       => null,
					],
				],
			]
		);

		$this->assertSame( [], $result['context']['block']['styles'] ?? null );
		$this->assertNull( $result['context']['block']['activeStyle'] ?? 'unset' );
	}

	public function test_editor_context_empty_variations_is_an_assertion(): void {
		$result = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block' => [
						'name'       => 'core/paragraph',
						'variations' => [],
					],
				],
			]
		);

		$this->assertSame( [], $result['context']['block']['variations'] ?? null );
	}

	public function test_editor_context_omitted_variations_falls_back_to_the_server_list(): void {
		$result = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block' => [
						'name' => 'core/paragraph',
					],
				],
			]
		);

		$names = array_column( $result['context']['block']['variations'] ?? [], 'name' );

		$this->assertContains( 'intro', $names );
	}

	public function test_execution_contract_registered_styles_follows_asserted_empty_styles(): void {
		$result = BlockAbilities::recommend_block(
			[
				'editorContext' => [
					'block' => [
						'name'        => 'core/paragraph',
						'styles'      => [],
						'editingMode' => 'disabled',
					],
				],
			]
		);

		$this->assertIsArray( $result['executionContract'] ?? null );
		$this->assertSame( [], $result['executionContract']['registeredStyles'] ?? null );
	}
```

The last test reuses the `editingMode: 'disabled'` short-circuit, which returns `executionContract` before any `ChatClient::chat()` call — the same seam `:562` and `:825` already exercise, so no provider is needed.

- [ ] **Step 2: Run the tests and verify they fail**

```bash
vendor/bin/phpunit --filter 'editor_context_(empty|omitted|malformed|null)|execution_contract_registered_styles' tests/phpunit/BlockAbilitiesTest.php
```

Expected: the `empty`/`null` assertion tests fail (server list survives); the `omitted` and `malformed` tests pass already and are the regression guards for the fix.

- [ ] **Step 3: Fix `styles` and `activeStyle`**

In `inc/Abilities/BlockAbilities.php`, replace lines 344-352:

```php
			$styles = self::normalize_list( $block['styles'] ?? [] );
		if ( ! empty( $styles ) ) {
			$normalized['block']['styles'] = $styles;
		}

			$active_style = is_string( $block['activeStyle'] ?? null ) ? sanitize_text_field( $block['activeStyle'] ) : '';
		if ( '' !== $active_style ) {
			$normalized['block']['activeStyle'] = $active_style;
		}
```

with:

```php
		// A well-shaped list is an assertion, including an empty one: the
		// editor always sends this key, and [] is what it sends once the last
		// registered style is unregistered. Shape-gated rather than presence-
		// gated because normalize_list() flattens any non-array to [], which
		// would turn malformed input into a total suppression of style
		// variations — both in the prompt and at the editor apply gate.
		if ( is_array( $block['styles'] ?? null ) ) {
			$normalized['block']['styles'] = self::normalize_list( $block['styles'] );
		}

		// Presence-gated rather than shape-gated, unlike its neighbours: the
		// client sends a string or null, and an unusable value degrades to
		// null, which is exactly "no active style" — the same answer
		// extract_active_style() returns for an unmatched class.
		if ( array_key_exists( 'activeStyle', $block ) ) {
			$active_style = is_string( $block['activeStyle'] ) ? sanitize_text_field( $block['activeStyle'] ) : '';

			$normalized['block']['activeStyle'] = '' !== $active_style ? $active_style : null;
		}
```

- [ ] **Step 4: Fix `variations`**

Replace lines 364-367:

```php
		$variations = self::normalize_list( $block['variations'] ?? [] );
		if ( ! empty( $variations ) ) {
			$normalized['block']['variations'] = $variations;
		}
```

with:

```php
		if ( is_array( $block['variations'] ?? null ) ) {
			$normalized['block']['variations'] = self::normalize_list( $block['variations'] );
		}
```

Do **not** touch `contentAttributes` / `configAttributes` at `:373-381`. They carry the identical guard but feed `contentAttributeKeys` / `configAttributeKeys` and `usesInnerBlocksAsContent`, so flipping them changes suggestion filtering rather than prompt text, and reaching a divergence requires client and server to disagree about the block type's *attributes* — a narrower and less well-understood scenario. Deferred to the ranking spec.

- [ ] **Step 5: Run the tests and verify they pass**

```bash
vendor/bin/phpunit tests/phpunit/BlockAbilitiesTest.php
```

Expected: `OK`. `activeStyle => null` is safe for every reader — the prompt guards on `! empty()` at `Prompt.php:297`, and no schema declares the key.

- [ ] **Step 6: Run the full suite and phpcs**

```bash
vendor/bin/phpunit
composer lint:php
```

Expected: `OK` and clean. Both disabled-seam tests (`:562` and `:825`) must stay green — this change alters the `executionContract` contents inside that payload.

- [ ] **Step 7: Commit**

```bash
git add inc/Abilities/BlockAbilities.php tests/phpunit/BlockAbilitiesTest.php
git commit -m "Honour a client-asserted empty styles/variations list

The editor always sends styles, activeStyle and variations, and each has a
legitimate empty value — [] after the last unregisterBlockStyle(), null for
activeStyle once nothing matches. The ! empty() guards could not tell 'key
omitted' from 'client asserted nothing is registered', so a stale server
value survived and reappeared in the prompt and in registeredStyles.

styles and variations are now shape-gated, so [] is an assertion while a
malformed non-array still falls back to the server list rather than
suppressing every style-variation suggestion. activeStyle stays
presence-gated and resolves to null, closing the leak where an asserted
empty style list still carried 'Active style: outline'.

contentAttributes/configAttributes keep the old guard deliberately; they
gate suggestion filtering rather than prompt text and belong with the
ranking spec."
```

---

### Task 5: JS coverage for the apply-path consequence

The spec claimed no governed write reads manifest `styles`. That is true in PHP and false overall — the editor apply path reads it and it decides what bytes reach post content. This task adds the coverage the spec never listed, and pins the accepted risk from Decision 2.

**Files:**
- Modify: `src/store/__tests__/store-actions.test.js` (near `:8883`)
- Modify: `src/store/update-helpers.test.js` (near `:1133`)

**Interfaces:**
- Consumes: `sanitizeRecommendationsForContext( recommendations, blockContext, executionContract )` — already exported and used throughout `update-helpers.test.js`. Internally calls the module-private `resolveExecutionContract`, which is the merge under test.

- [ ] **Step 1: Write the apply-path test**

In `src/store/__tests__/store-actions.test.js`, add after the test that ends at `:8995`:

```javascript
	test( 'applySelectedSuggestions applies a style variation registered only via register_block_style()', async () => {
		const updateBlockAttributes = jest.fn();
		const registry = createBlockApplyRegistry( {
			attributes: {
				className: 'custom-class',
			},
			updateBlockAttributes,
		} );
		const select = createStoreSelectWithState( {
			blockRequestState: {
				'block-1': {
					status: 'ready',
					requestToken: 1,
					contextSignature: 'client-sig',
					resolvedContextSignature: 'server-sig',
				},
			},
			blockRecommendations: {
				'block-1': {
					prompt: 'Improve this block.',
					blockContext: { name: 'core/group' },
					executionContract: {
						allowedPanels: [ 'styles' ],
						// Present only because build_block_manifest() now merges
						// WP_Block_Styles_Registry into the manifest; this name
						// is absent from the block's own block.json styles.
						registeredStyles: [ 'registry-only' ],
					},
				},
			},
		} );
		const dispatch = jest.fn();
		apiFetch.mockResolvedValueOnce( {
			result: {
				resolvedContextSignature: 'server-sig',
				docsGrounding: { status: 'grounded' },
			},
		} );

		const didApply = await actions.applySelectedSuggestions(
			'block-1',
			[
				{
					label: 'Registry-only style',
					panel: 'styles',
					type: 'style_variation',
					attributeUpdates: { className: 'is-style-registry-only' },
					suggestionKey: 'block:styles:1',
					recommendationOutcome: {
						recommendationSetId: 'block:1:hash_set',
					},
				},
			],
			buildBlockRecommendationRequestSignature( {
				clientId: 'block-1',
				prompt: 'Improve this block.',
				contextSignature: 'client-sig',
			} ),
			{
				clientId: 'block-1',
				editorContext: { name: 'core/group' },
				contextSignature: 'client-sig',
				prompt: 'Improve this block.',
			}
		)( { dispatch, registry, select } );

		expect( didApply ).toBe( true );
		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'block-1', {
			className: 'custom-class is-style-registry-only',
		} );
	} );
```

- [ ] **Step 2: Write the accepted-risk pin**

In `src/store/update-helpers.test.js`, add beside the existing contract tests:

```javascript
	test( 'resolveExecutionContract prefers the server registeredStyles over the client-derived list', () => {
		const recommendations = {
			settings: [],
			styles: [],
			block: [
				{
					label: 'Use outline style',
					type: 'style_variation',
					attributeUpdates: {
						className: 'is-style-outline',
					},
				},
			],
			explanation: 'The server list is authoritative.',
		};

		// ACCEPTED RISK (docs/superpowers/plans/2026-07-20-block-external-introspection-parity.md,
		// Decision 2): the server contract overrides the client-derived mirror
		// for registeredStyles at src/store/update-helpers.js:673-683 — only
		// contentAttributeKeys and configAttributeKeys get client fallback. A
		// style the client no longer knows about therefore still applies.
		expect(
			sanitizeRecommendationsForContext(
				recommendations,
				{ styles: [] },
				{
					allowedPanels: [],
					hasExplicitlyEmptyPanels: false,
					registeredStyles: [ 'outline' ],
				}
			).block
		).toHaveLength( 1 );
	} );
```

If `sanitizeRecommendationsForContext` turns out to drop the suggestion for an unrelated reason (panel gating), adjust `allowedPanels` to match the sibling tests at `:1112-1131` — do **not** change the assertion's intent.

- [ ] **Step 3: Run the JS tests**

```bash
npm run test:unit -- src/store/__tests__/store-actions.test.js src/store/update-helpers.test.js
```

Expected: PASS. Both tests should pass **without** production changes — they are characterisation tests documenting behaviour Task 3 makes reachable, not new behaviour.

- [ ] **Step 4: Commit**

```bash
git add src/store/__tests__/store-actions.test.js src/store/update-helpers.test.js
git commit -m "Cover the apply-path consequence of the block styles merge

executionContract.registeredStyles is not prompt-only: it gates which
is-style-* className values survive isValidStyleVariationSuggestion and
filterAttributeUpdatesForExecutionContract before updateBlockAttributes
writes them. Merging register_block_style() registrations into the manifest
therefore widens what can be applied, not just what can be suggested.

Also pins the accepted risk that the server contract overrides the
client-derived mirror for registeredStyles, so a narrower client list does
not narrow the apply gate."
```

---

### Task 6: D3 — correct the governance documentation

No code changes. The override at `BlockAbilities.php:416-419` is **retained**: the editor's `features` / `__experimentalFeatures` settings reflect per-context resolution — a style variation being previewed in the Site Editor, block-level setting overrides — that `wp_get_global_settings()` cannot observe, so the client value is frequently more accurate. Widening the preset whitelist lets a user obtain suggestions naming values their theme does not define; that same user holds `edit_posts` and can set any attribute by hand. Not privilege escalation. This is documentation drift, not a hole.

**Files:**
- Modify: `docs/reference/governance-layer.md:176`, `:184-188`
- Modify: `docs/superpowers/specs/2026-07-20-block-external-introspection-parity-design.md` — the "Not affected" paragraph under D1

- [ ] **Step 1: Reconcile the exposure bullet**

`governance-layer.md:176` describes the preview siblings without noting they are also on the universal default server, which would contradict the rewritten section. Replace line 176:

```markdown
- the six `preview-recommend-*` siblings — side-effect-free signature dry-runs, registered before the feature gate is enabled so operators can verify wiring
```

with:

```markdown
- the six `preview-recommend-*` siblings — side-effect-free signature dry-runs, also `meta.mcp.public = true` and so reachable on the universal MCP default server, registered before the feature gate is enabled so operators can verify wiring
```

- [ ] **Step 2: Rewrite the trust boundary section**

Replace `governance-layer.md:184-188` in full:

```markdown
### Recommendation context trust boundary

Recommendation context is **caller-supplied advisory input on both paths**. There is no enforced first-party channel.

`flavor-agent/recommend-block` accepts `editorContext` as an open object and selects the context path purely on key presence (`inc/Abilities/BlockAbilities.php:236-237`), with no provenance signal — no nonce class, no origin marker, nothing distinguishing the editor from any other client. Any holder of `edit_posts` can POST a fabricated `editorContext`. The `selectedBlock` path is not sealed either: it re-introspects the block type server-side, but `supportsContentRole` is OR-widened from client input (`inc/Abilities/BlockAbilities.php:462`), so a caller can turn it on though not off.

Server-side re-introspection covers what it re-derives — `inspectorPanels`, `bindableAttributes` and content/config attribute keys are rebuilt from `WP_Block_Type_Registry` rather than trusted from the caller — but it is bounded by what the server can observe, not total. Block styles registered only in JavaScript via `registerBlockStyle()` are unreachable from PHP and are therefore absent from every server-derived manifest.

`themeTokens` is deliberately client-preferred when supplied (`inc/Abilities/BlockAbilities.php:416-419`). The editor's `features` / `__experimentalFeatures` settings reflect per-context resolution — a style variation being previewed in the Site Editor, block-level setting overrides — that `wp_get_global_settings()` cannot observe, so the client value is frequently more accurate than the server's.

This is safe because of what a caller *gains*, not because of how they arrive:

- The **external apply lanes** (style, template, template-part, post-blocks) re-collect and re-validate their target contract server-side at request and again at approval, with no filter seam. No external apply consumes a recommendation-supplied execution contract.
- The **editor apply path does** consume it — `executionContract.registeredStyles` gates which `is-style-*` className values survive (`src/store/update-helpers.js:1226`, `:2487`) before `updateBlockAttributes` writes them. Widening it buys a caller nothing: the same user holds `edit_posts` and can set any attribute by hand, and the apply still runs through the block editor's real `supports`/lock enforcement.

A recommendation's `executionContract` is an advisory shaping and attribution artifact, never an apply authority.

Exposure is not editor-only. `recommend-block` declares no `mcp` meta, so it is not on the universal MCP default server, but it is a first-class tool on the dedicated Flavor Agent MCP server at `/wp-json/mcp/flavor-agent` (`inc/MCP/ServerBootstrap.php`), its `preview-recommend-block` sibling is `mcp.public` and reaches the same input preparation with `resolveSignatureOnly` forced, and the Abilities REST route is reachable directly. The permission gates are identical on every vector.
```

**Freshness-guard hazard:** `governance-layer.md` is in `check-doc-freshness.sh`'s `live_docs` array. Do not write a superseded ability total (the regex `\b(29|30|31|32|33|34) +(WordPress +)?[Aa]bilit(y|ies)\b` fires; the current total is 35), the literal "External applies are limited to Global Styles and Style Book", "five signature-only", or "used by all seven recommendation surfaces". The replacement above avoids all four.

- [ ] **Step 3: Correct the spec's falsified paragraph**

In `docs/superpowers/specs/2026-07-20-block-external-introspection-parity-design.md`, replace the "**Not affected:**" paragraph under D1's Impact with:

```markdown
**Apply-path consequence.** No *PHP* governed write reads manifest `styles` — `StyleApplyExecutor.php:102-118` consumes a `build_block_manifest()` result, but `StyleAbilities::supported_block_style_paths_from_manifest()` reads only `supports` and `title`. The **editor apply path does**: `executionContract.registeredStyles` gates `isValidStyleVariationSuggestion` (`src/store/update-helpers.js:2487`) and the `className` arm of `filterAttributeUpdatesForExecutionContract` (`:1226`) before `updateBlockAttributes` writes to post content (`src/store/index.js:2522`). Widening the manifest therefore widens what can be *applied*, not only what can be suggested. That is the intended outcome — a `register_block_style()` style is registered — but it is an apply-surface change and is covered by a JS regression test.
```

- [ ] **Step 4: Run the docs guard**

```bash
npm run check:docs
```

Expected: pass. If a guard fires, fix the prose — do not relax the guard.

- [ ] **Step 5: Commit**

```bash
git add docs/reference/governance-layer.md docs/superpowers/specs/2026-07-20-block-external-introspection-parity-design.md
git commit -m "Correct the recommendation context trust boundary

The section described a 'trusted first-party surface' that does not exist:
recommend-block accepts editorContext as an open object and selects the
path on key presence alone, with no provenance signal, so any edit_posts
holder can fabricate one — over REST, over the dedicated MCP server, or
through the mcp.public preview sibling.

Reframes both paths as caller-supplied advisory context, states the actual
security property (context shapes suggestions; external applies re-collect
and re-validate server-side), folds in themeTokens with the reason it is
client-preferred, and stops implying server-side re-introspection is total
— JS-only registerBlockStyle() registrations are unreachable from PHP.

Also records that the editor apply path does read the execution contract,
correcting the source spec's claim that no write consumes it."
```

---

### Task 7: Playground E2E fixture and spec

D1–D3 change no editor-path behaviour — the client already overrides those values — but D4 does: a block whose styles were all JS-unregistered will now correctly present no style variations where it previously inherited the server's list. **No existing fixture unregisters a block style**, so running the suite unchanged would go green without exercising the change.

**Files:**
- Modify: `tests/e2e/playground-mu-plugin/flavor-agent-loader.php` (the `enqueue_block_editor_assets` callback at `:122-143`, plus an `init` hook)
- Create: `tests/e2e/playground-mu-plugin/flavor-agent-playground-block-styles.js`
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`

- [ ] **Step 1: Register a style server-side**

Add a `register_block_style()` call on `init` in `flavor-agent-loader.php`, so D1's merge is exercised end to end:

```php
add_action(
	'init',
	static function () {
		register_block_style(
			'core/paragraph',
			[
				'name'  => 'fa-e2e-registry',
				'label' => 'FA E2E Registry Style',
			]
		);
	}
);
```

- [ ] **Step 2: Add the editor script**

The loader ships **no** classic editor JS — it calls `wp_register_script_module()` twice and `wp_enqueue_script()` zero times, and both existing files are ESM import-side stubs that never touch the `wp` global. There is no matching style to follow, so add a new classic script.

Create `tests/e2e/playground-mu-plugin/flavor-agent-playground-block-styles.js`:

```javascript
( function () {
	if ( ! window.wp || ! window.wp.domReady ) {
		return;
	}

	window.wp.domReady( function () {
		var blocks = window.wp.blocks;
		var data = window.wp.data;

		if ( ! blocks || ! data ) {
			return;
		}

		// getBlockStyles is a core/blocks store SELECTOR, not an export of
		// wp.blocks — reading it off wp.blocks yields undefined and this
		// fixture would silently no-op.
		var styles =
			data.select( 'core/blocks' ).getBlockStyles( 'core/quote' ) || [];

		// Remove every registered style from core/quote so the editor sends an
		// empty styles list. Exercises the D4 path where a client-asserted []
		// must override the server's list rather than be discarded.
		styles.forEach( function ( style ) {
			blocks.unregisterBlockStyle( 'core/quote', style.name );
		} );
	} );
} )();
```

`unregisterBlockStyle( blockName, styleVariationName )` **is** exported from `@wordpress/blocks` (`build/api/registration.cjs:259`) and so is available as `wp.blocks.unregisterBlockStyle`. `getBlockStyles` is **not** — it is a `core/blocks` store selector (`build/store/selectors.cjs:68`), which is how the plugin's own code reads it (`src/context/block-inspector.js:195`).

Enqueue it inside the existing `enqueue_block_editor_assets` callback (which spans `:122-143` — the `add_action()` closes at `:143`, not `:137`), following the established `content_url( 'mu-plugins/<file>.js' )` pattern used at `:131` and `:137`:

```php
		wp_enqueue_script(
			'flavor-agent-playground-block-styles',
			content_url( 'mu-plugins/flavor-agent-playground-block-styles.js' ),
			[ 'wp-blocks', 'wp-data', 'wp-dom-ready' ],
			'1',
			true
		);
```

Verify `core/quote` actually has styles registered in the pinned WordPress build before relying on it — `wp.data.select( 'core/blocks' ).getBlockStyles( 'core/quote' )` in the browser console. If it has none, the fixture proves nothing; pick a core block that does and update both the JS and the spec.

- [ ] **Step 3: Write the spec**

**What this test can and cannot assert.** D4 is a *server-side* change, and the Playground suite's only tool for producing suggestions without a live provider is `page.route(...)` + `route.fulfill(...)`, which bypasses the server entirely. So the spec's proposed assertion — "the panel offers no style-variation suggestion" — would test the client gate against a stubbed response, not D4. The assertable and genuinely valuable thing is the **request payload**: that the editor, after unregistering every style, sends `editorContext.block.styles: []`. That is the precondition D4 exists to honour. Paired with Task 4's PHPUnit test that the server honours it, the two halves are complete; neither alone is.

Add to `tests/e2e/flavor-agent.smoke.spec.js`. **Leave it untagged** — `playwright.config.js:34` sets `grepInvert: /@wp70-site-editor/`, and six of the seven existing "AI Recommendations" assertions are `@wp70-site-editor`-tagged, so they never run under the Playground config. A tagged test would be silently excluded from exactly the suite this task exists to satisfy, and a CLI `--grep` does not clear a config-level `grepInvert`.

```javascript
test( 'an unregistered block style is asserted as empty in the recommendation request', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );

	const blockRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-block' ),
		async ( route ) => {
			blockRequests.push(
				getAbilityRequestInput( route.request().postDataJSON() )
			);

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature: 'block-resolved-context',
					settings: [],
					styles: [],
					block: [],
					explanation: 'No suggestions for this fixture.',
				} ),
			} );
		}
	);

	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await waitForBlockEditorApis( page );

	// The mu-plugin fixture unregisters every core/quote style on domReady.
	await expect
		.poll(
			async () =>
				await page.evaluate(
					() =>
						(
							window.wp.data
								.select( 'core/blocks' )
								.getBlockStyles( 'core/quote' ) || []
						).length
				),
			{ timeout: 30_000 }
		)
		.toBe( 0 );

	await page.evaluate( () => {
		const { createBlock } = window.wp.blocks;
		const { dispatch } = window.wp.data;
		const block = createBlock( 'core/quote' );

		dispatch( 'core/block-editor' ).resetBlocks( [ block ] );
		dispatch( 'core/block-editor' ).selectBlock( block.clientId );
	} );

	await ensureSettingsSidebarOpen( page );

	const promptInput = page.getByPlaceholder(
		'Describe the outcome you want for this block.'
	);

	await ensurePanelOpen( page, 'AI Recommendations', promptInput );
	await dismissWelcomeGuide( page );
	await promptInput.fill( 'Improve this quote.' );
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();

	await expect.poll( () => blockRequests.length, { timeout: 30_000 } ).toBeGreaterThan( 0 );

	// The client must assert emptiness rather than omit the key — that is the
	// distinction D4 makes the server honour.
	const context = blockRequests[ 0 ].editorContext || {};

	expect( context.block ).toHaveProperty( 'styles' );
	expect( context.block.styles ).toEqual( [] );
} );
```

If `seedParagraphBlock`'s helper flow is a better fit than the inline `resetBlocks` call, use it — but it seeds a paragraph, and this test needs the block whose styles the fixture unregisters.

- [ ] **Step 4: Run the suite**

```bash
npm run test:e2e:playground
```

Expected: pass, including the new test. Confirm it is actually in the Playground project's list rather than grep-inverted out:

```bash
npx playwright test --list | grep -i "asserted as empty"
```

Expected: one match. An empty result means the test is excluded from the suite and the gate is not met, however green the run looks.

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/playground-mu-plugin/ tests/e2e/flavor-agent.smoke.spec.js
git commit -m "Cover the unregistered-block-style path in the Playground suite

D4 is the one defect in this slice with editor-path consequences: a block
whose styles were all JS-unregistered now correctly presents no style
variations where it previously inherited the server's list. No existing
fixture unregisters a style, so the suite would have gone green without
exercising the change.

Adds a register_block_style() call on init (exercising the D1 merge end to
end) and a classic editor script that unregisters core/quote's styles. The
spec is deliberately untagged: playwright.config.js grep-inverts
@wp70-site-editor, which would exclude it from the Playground project."
```

---

### Task 8: Full verification gates

`docs/reference/cross-surface-validation-gates.md` applies — this touches ability contracts and the shared execution contract.

- [ ] **Step 1: Targeted suites**

```bash
vendor/bin/phpunit tests/phpunit/BlockTypeIntrospectorTest.php tests/phpunit/BlockAbilitiesTest.php tests/phpunit/PromptRulesTest.php
npm run test:unit -- src/store/__tests__/store-actions.test.js src/store/update-helpers.test.js
```

Expected: all pass. `PromptRulesTest` needs no new cases but must be re-run: D1 and D4 both move `registeredStyles`, its primary input for style-variation filtering.

- [ ] **Step 2: Aggregate verification**

```bash
node scripts/verify.js --skip-e2e
```

Then inspect `output/verify/summary.json` and confirm `status` is `pass`. Add `--skip=lint-plugin` only if neither host WP-CLI nor the Docker path is available, and say so in the report rather than skipping silently.

- [ ] **Step 3: Docs guard**

```bash
npm run check:docs
```

- [ ] **Step 4: Playwright**

```bash
npm run test:e2e:playground
```

`wp70` is **not required** — no Site Editor template, template-part, Global Styles or Style Book path is touched. Record that as the reason rather than as a silent skip. If the Playground harness is unavailable, record the blocker or an explicit waiver; do not report the gate as met.

- [ ] **Step 5: Report**

State the actual command output for each gate. If any step failed or was skipped, say so plainly with the reason.

## Explicitly out of scope

Deferred to the follow-on specs, listed so they are not silently dropped:

- `presetBacked` returning a hard `false` for every block attribute suggestion (`inc/Support/RecommendationDesignValidator.php:88`) — fixing it means teaching the validator to read `attributeUpdates`, the core of the ranking spec
- the `contentAttributes` / `configAttributes` half of D4
- style-variation rejection emitting no `validationReasons` code. Note the vocabulary already carries `unavailable_variation` → `SEVERITY_REJECTED` (`inc/Support/ValidationReason.php:61`), but its `surfaces` array does not include `block`, so wiring it is a vocabulary change, not just a call-site change — ranking spec
- the client/server execution-contract merge preferring the server list (Decision 2)
- the duplicated normalizers in `inc/Context/BlockRecommendationExecutionContract.php:186-218` vs `inc/Support/NormalizesInput.php:28-71`, and the JS mirror in `src/utils/block-execution-contract.js`
- the lossy `supports` → `inspectorPanels` projection
- block variations shipped without `attributes` / `innerBlocks`
- `blockInterior` and sibling nodes carrying no text content
- `theme_token_values` prompt priority
- unused server machinery unreachable from the block surface (`PostBlocksContextCollector`, `ViewportVisibilityAnalyzer`, `PatternOverrideAnalyzer`, `SyncedPatternRepository`)
