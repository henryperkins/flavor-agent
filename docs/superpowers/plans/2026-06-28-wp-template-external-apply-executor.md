# Page-Level `wp_template` External-Apply Executor (v1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a governed external-apply lane for a single `wp_template` that executes one bounded path-addressed `insert_pattern` after human approval, reusing the existing executor-dispatch seam.

**Architecture:** Mirror the shipped template-part lane through the surface-generic seam (`ExternalApplyExecutor` / `ExternalApplyExecutorRegistry` / `PendingApplyDecision` / `ApplyAbilities::undo_activity`). Net-new: a `TemplateApplyExecutor`, an apply-time validator `TemplatePrompt::validate_operations_for_apply`, a governed-write resolver `ServerCollector::resolve_template_for_apply`, a `request-template-apply` ability + handler + schema, and a `'template'` branch in the admin governance projection. Attestation stays frozen to `external-style-apply-v1`.

**Tech Stack:** PHP 8.2 (PSR-4 `FlavorAgent\`), WordPress block templates (`wp_template` post type + `wp_theme` taxonomy), PHPUnit (stubbed WP via `tests/phpunit/bootstrap.php` + `FlavorAgent\Tests\Support\WordPressTestState`), `@wordpress/scripts` + Jest for the admin JS.

## Global Constraints

- **Spec:** `docs/superpowers/specs/2026-06-28-wp-template-external-apply-executor-design.md` — read it first; it is the contract.
- **v1 operation scope:** `insert_pattern` **only** (one insert per request). Reject `assign_template_part` / `replace_template_part` / `remove_block` / `replace_block_with_pattern` fail-closed at the handler, the executor, and the input-schema `type` enum.
- **Placement enum (exact strings):** `start`, `end`, `before_block_path`, `after_block_path`. Anchored (`before_block_path`/`after_block_path`) ops carry `targetPath` + `expectedTarget`; `start`/`end` carry neither.
- **Attestation stays frozen to `external-style-apply-v1`:** do **not** add `'template'` to the `in_array($surface,['global-styles','style-book'],true)` branches in `PendingApplyDecision::decide` or `ApplyAbilities::undo_activity`.
- **Freshness replays the signed recommendation envelope:** Gate-1 forwards `templateRef`/`templateType`/`prompt` plus conditional `visiblePatternNames`, `designSemantics`, `editorSlots`, and `editorStructure` exactly when the caller supplied them.
- **Persist against the freshly re-resolved entity** returned by the final concurrency gate (closes the same-content materialization race) — never the start-of-execute object.
- **Materialize** a theme-file template into a `wp_template` post with the **`wp_theme` term only** (no `wp_template_part_area` term).
- **Ability count 31 → 32.** `scripts/check-doc-freshness.sh` hard-codes the count and must bump in the same change or `npm run check:docs` fails.
- **TDD, single-file PHPUnit runs only** (`vendor/bin/phpunit tests/phpunit/<File>.php`); multi-file batches false-green. Commit after each green task.
- **No write-path filter seams:** governed resolution/validation/persist must not be interceptable.

---

## File Structure

**Create:**
- `inc/Apply/TemplateApplyExecutor.php` — server-side execute/resolve_baseline/undo over a `wp_template` block tree (insert_pattern only).
- `inc/AI/Abilities/RequestTemplateApplyAbility.php` — the ability class (schema/meta wiring + delegate).
- `tests/phpunit/TemplateApplyExecutorTest.php` — executor unit tests.
- `tests/phpunit/TemplatePromptApplyValidationTest.php` — apply-time validator tests.

**Modify:**
- `inc/LLM/TemplatePrompt.php` — add public `validate_operations_for_apply`.
- `inc/Context/ServerCollector.php` — add `resolve_template_for_apply`.
- `inc/Apply/ExternalApplyExecutorRegistry.php` — add the `'template'` arm + refresh docblock.
- `inc/Abilities/ApplyAbilities.php` — add `request_template_apply` + `use` import.
- `inc/Abilities/Registration.php` — `external_apply_ability_classes` + `external_apply_meta` + `external_apply_output_schema` arms, new `template_apply_input_schema` + `template_structural_operation_schema`, `use` import.
- `src/admin/activity-log-utils.js` — `'template'` branch in `getGovernanceApprovalCopy`, `getGovernanceTargetLabel`, and the `formatOperationSummary` selector.
- Tests: `tests/phpunit/ExternalApplyLifecycleTest.php`, `tests/phpunit/RegistrationTest.php`, `tests/phpunit/MCPServerBootstrapTest.php`, `src/admin/__tests__/activity-log-utils.test.js`.
- Docs + guard: `CLAUDE.md`, `.github/copilot-instructions.md`, `docs/reference/abilities-and-routes.md`, `docs/reference/governance-layer.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/SOURCE_OF_TRUTH.md`, `STATUS.md`, `docs/reference/local-environment-setup.md`, `scripts/check-doc-freshness.sh`, `docs/reference/current-open-work.md`.

---

## Task 1: Apply-time validator `TemplatePrompt::validate_operations_for_apply`

**Files:**
- Modify: `inc/LLM/TemplatePrompt.php`
- Test: `tests/phpunit/TemplatePromptApplyValidationTest.php`

**Interfaces:**
- Consumes: the private `TemplatePrompt::validate_template_operations()` and its seven private lookup builders (`build_unused_template_part_lookup`, `build_assigned_template_part_lookup`, `build_allowed_area_lookup`, `build_empty_area_lookup`, `build_pattern_lookup`, `build_template_block_lookup`, `build_insertion_anchor_lookup`).
- Produces: `TemplatePrompt::validate_operations_for_apply( array $operations, array $context ): array{operations: array<int,array<string,mixed>>, reasons: array<int,string>}` — consumed by Task 3 (executor) and Task 4 (handler).

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/TemplatePromptApplyValidationTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\TemplatePrompt;
use PHPUnit\Framework\TestCase;

final class TemplatePromptApplyValidationTest extends TestCase {

	/**
	 * Minimal live for_template() context: one registered candidate pattern and a
	 * top-level block tree with a single group so start/end anchors and a
	 * before/after path are all resolvable.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		return [
			'templateType'             => 'home',
			'patterns'                 => [
				[ 'name' => 'twentytwentyfive/hero', 'title' => 'Hero' ],
			],
			'topLevelBlockTree'        => [
				[ 'path' => [ 0 ], 'blockName' => 'core/group', 'innerBlocks' => [] ],
			],
			'topLevelInsertionAnchors' => [
				'start' => [ 'placement' => 'start' ],
				'end'   => [ 'placement' => 'end' ],
			],
		];
	}

	public function test_valid_start_insert_pattern_passes_with_no_expected_target(): void {
		$result = TemplatePrompt::validate_operations_for_apply(
			[
				[ 'type' => 'insert_pattern', 'patternName' => 'twentytwentyfive/hero', 'placement' => 'start' ],
			],
			$this->context()
		);

		$this->assertCount( 1, $result['operations'] );
		$this->assertSame( 'insert_pattern', $result['operations'][0]['type'] );
		$this->assertArrayNotHasKey( 'targetPath', $result['operations'][0] );
		$this->assertSame( [], $result['reasons'] );
	}

	public function test_unknown_pattern_is_rejected_with_a_reason(): void {
		$result = TemplatePrompt::validate_operations_for_apply(
			[
				[ 'type' => 'insert_pattern', 'patternName' => 'nope/missing', 'placement' => 'start' ],
			],
			$this->context()
		);

		$this->assertSame( [], $result['operations'] );
		$this->assertNotEmpty( $result['reasons'] );
		$this->assertSame( 'unknown_pattern', $result['reasons'][0] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/phpunit/TemplatePromptApplyValidationTest.php`
Expected: FAIL with `Call to undefined method ...::validate_operations_for_apply()`.

- [ ] **Step 3: Add the public method**

In `inc/LLM/TemplatePrompt.php`, immediately after the existing private `validate_template_operations()` method (the one ending around the `unknown_operation_type` default branch), add:

```php
	/**
	 * Apply-time re-validation. Rebuilds the eight generation-time lookups from a
	 * freshly collected live for_template() context, re-runs the operation
	 * validator, and normalizes to the {operations, reasons} shape the external
	 * executor and request handler consume (parity with
	 * TemplatePartPrompt::validate_operations_for_apply).
	 *
	 * @param array<int, array<string, mixed>> $operations
	 * @param array<string, mixed>             $context Live ServerCollector::for_template() context.
	 * @return array{operations: array<int, array<string, mixed>>, reasons: array<int, string>}
	 */
	public static function validate_operations_for_apply( array $operations, array $context ): array {
		$result = self::validate_template_operations(
			$operations,
			self::build_unused_template_part_lookup( $context ),
			self::build_assigned_template_part_lookup( $context ),
			self::build_allowed_area_lookup( $context ),
			self::build_empty_area_lookup( $context ),
			self::build_pattern_lookup( $context ),
			self::build_template_block_lookup( $context ),
			self::build_insertion_anchor_lookup( $context )
		);

		return [
			'operations' => $result['operations'],
			'reasons'    => $result['invalid'] ? [ $result['code'] ] : [],
		];
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/phpunit/TemplatePromptApplyValidationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/TemplatePrompt.php tests/phpunit/TemplatePromptApplyValidationTest.php
git commit -m "feat(template-apply): add TemplatePrompt::validate_operations_for_apply"
```

---

## Task 2: Governed-write resolver `ServerCollector::resolve_template_for_apply`

**Files:**
- Modify: `inc/Context/ServerCollector.php`
- Test: `tests/phpunit/TemplateApplyExecutorTest.php` (created here; exercised fully in Task 3)

**Interfaces:**
- Consumes: the private `ServerCollector::template_repository()` helper and `TemplateRepository::resolve_template( string $ref ): ?object`.
- Produces: `ServerCollector::resolve_template_for_apply( string $ref ): ?object` — consumed by Task 3.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/TemplateApplyExecutorTest.php` with just the resolver test for now:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class TemplateApplyExecutorTest extends TestCase {

	private const TEMPLATE_REF = 'twentytwentyfive//home';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$active_theme = [ 'stylesheet' => 'twentytwentyfive' ];
	}

	/**
	 * Seed the live template into the get_block_template(s) store so the bound
	 * TemplateRepository::resolve_template resolves it. When $wp_id > 0 also seed a
	 * wp_template post as the wp_update_post target.
	 */
	private function seed_template( string $content, int $wp_id = 0, string $slug = 'home' ): void {
		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => $wp_id,
				'slug'    => $slug,
				'title'   => 'Home',
				'content' => $content,
			],
		];

		if ( $wp_id > 0 ) {
			WordPressTestState::$posts[ $wp_id ] = new \WP_Post(
				[
					'ID'           => $wp_id,
					'post_type'    => 'wp_template',
					'post_content' => $content,
				]
			);
		}
	}

	public function test_resolve_template_for_apply_returns_the_live_template(): void {
		$this->seed_template( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );

		$template = ServerCollector::resolve_template_for_apply( self::TEMPLATE_REF );

		$this->assertIsObject( $template );
		$this->assertSame( self::TEMPLATE_REF, $template->id );
	}

	public function test_resolve_template_for_apply_returns_null_when_missing(): void {
		$this->assertNull( ServerCollector::resolve_template_for_apply( 'twentytwentyfive//does-not-exist' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/phpunit/TemplateApplyExecutorTest.php`
Expected: FAIL with `Call to undefined method ...::resolve_template_for_apply()`.

- [ ] **Step 3: Add the resolver**

In `inc/Context/ServerCollector.php`, directly after `resolve_template_part_for_apply()`, add:

```php
	/**
	 * Re-resolve the live template for a governed external apply. No public filter
	 * seam: a governed-write resolution path must not be interceptable. Mirrors
	 * resolve_template_part_for_apply.
	 */
	public static function resolve_template_for_apply( string $ref ): ?object {
		return self::template_repository()->resolve_template( $ref );
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/phpunit/TemplateApplyExecutorTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add inc/Context/ServerCollector.php tests/phpunit/TemplateApplyExecutorTest.php
git commit -m "feat(template-apply): add ServerCollector::resolve_template_for_apply"
```

---

## Task 3: `TemplateApplyExecutor` + registry arm

**Files:**
- Create: `inc/Apply/TemplateApplyExecutor.php`
- Modify: `inc/Apply/ExternalApplyExecutorRegistry.php`
- Test: `tests/phpunit/TemplateApplyExecutorTest.php`

**Interfaces:**
- Consumes: `ServerCollector::resolve_template_for_apply` (Task 2), `ServerCollector::for_template`, `TemplatePrompt::validate_operations_for_apply` (Task 1), `BlockTreeMutator`, `WP_Block_Patterns_Registry`.
- Produces: `TemplateApplyExecutor` implementing `ExternalApplyExecutor` (`resolve_baseline`/`execute`/`undo`). Entry shape: `surface => 'template'`, `target => { templateRef, templateType, slug, title }`, `apply => { operations: [...] }`, `before/after => { content }`. `ExternalApplyExecutorRegistry::for_surface('template')` returns `TemplateApplyExecutor::class`.

- [ ] **Step 1: Create the executor by copying the template-part mirror**

```bash
cp inc/Apply/TemplatePartApplyExecutor.php inc/Apply/TemplateApplyExecutor.php
```

- [ ] **Step 2: Apply the class-level + read deltas**

In `inc/Apply/TemplateApplyExecutor.php`:

- Rename the class: `final class TemplatePartApplyExecutor` → `final class TemplateApplyExecutor`.
- Change the `use` line `use FlavorAgent\LLM\TemplatePartPrompt;` → `use FlavorAgent\LLM\TemplatePrompt;`.
- Replace the file docblock's "template-part" references with "page-level template" and keep the "No attestation" line.
- Replace `private static function part_ref( array $entry ): string` with a template-ref reader and add a type reader:

```php
	/**
	 * @param array<string, mixed> $entry
	 */
	private static function template_ref( array $entry ): string {
		$target = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];

		return trim( (string) ( $target['templateRef'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function template_type( array $entry ): string {
		$target = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];

		return sanitize_key( (string) ( $target['templateType'] ?? '' ) );
	}
```

- Replace `resolve_part()` with `resolve_template()` (delegates to the Task-2 resolver):

```php
	/**
	 * @return object|\WP_Error A WP_Block_Template-shaped object, or a fail-closed error.
	 */
	private static function resolve_template( string $ref ): object {
		if ( '' === $ref ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'Missing template identifier.',
				[ 'status' => 409 ]
			);
		}

		$template = ServerCollector::resolve_template_for_apply( $ref );

		if ( ! is_object( $template ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'The requested template is not available on this site.',
				[ 'status' => 404 ]
			);
		}

		return $template;
	}
```

- [ ] **Step 3: Apply the `resolve_baseline`, `execute`, `undo`, concurrency-gate, and `persist` deltas**

Replace `resolve_baseline()`:

```php
	public static function resolve_baseline( array $entry ): string|\WP_Error {
		$template = self::resolve_template( self::template_ref( $entry ) );

		return is_wp_error( $template )
			? $template
			: self::content_hash( (string) ( $template->content ?? '' ) );
	}
```

Replace `execute()` (note: re-collect via `for_template`, validate via `TemplatePrompt`, **v1 insert_pattern-only guard**, concurrency gate **returns the fresh entity**, persist **against the fresh entity**, template target shape):

```php
	public static function execute( array $entry ): array|\WP_Error {
		$ref      = self::template_ref( $entry );
		$type     = self::template_type( $entry );
		$template = self::resolve_template( $ref );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$before_content = (string) ( $template->content ?? '' );
		$before_hash    = self::content_hash( $before_content );
		$apply          = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
		$operations     = is_array( $apply['operations'] ?? null ) ? $apply['operations'] : [];

		if ( [] === $operations ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'No operations to apply.',
				[ 'status' => 409 ]
			);
		}

		// v1 guard: this lane executes insert_pattern only.
		foreach ( $operations as $operation ) {
			if ( 'insert_pattern' !== ( is_array( $operation ) ? ( $operation['type'] ?? '' ) : '' ) ) {
				return new \WP_Error(
					'flavor_agent_apply_operations_invalid',
					'External template applies support insert_pattern only in v1.',
					[ 'status' => 409 ]
				);
			}
		}

		$context = ServerCollector::for_template( $ref, '' !== $type ? $type : null );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$validated = TemplatePrompt::validate_operations_for_apply( $operations, $context );

		if (
			[] === $validated['operations']
			|| count( $validated['operations'] ) !== count( $operations )
		) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'One or more template operations failed re-validation against the live execution contract.',
				[
					'status'            => 409,
					'validationReasons' => $validated['reasons'],
				]
			);
		}

		$blocks = self::apply_operations( parse_blocks( $before_content ), $validated['operations'] );

		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		$after_content = serialize_blocks( $blocks );

		// Final concurrency gate: re-resolve and fail closed if the live content
		// moved; RETURN the fresh entity so persist writes against the current wp_id.
		$fresh = self::assert_template_unchanged( $ref, $before_hash );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$persisted = self::persist( $fresh, $after_content );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return [
			'target' => [
				'templateRef'  => (string) ( $template->id ?? $ref ),
				'templateType' => $type,
				'slug'         => (string) ( $template->slug ?? '' ),
				'title'        => (string) ( $template->title ?? '' ),
			],
			'before' => [ 'content' => $before_content ],
			'after'  => [
				'content'    => $after_content,
				'operations' => $validated['operations'],
			],
		];
	}
```

Replace `undo()` — same equality semantics, template ref, and the concurrency gate now returns the fresh entity:

```php
	public static function undo( array $entry ): array|\WP_Error {
		$ref      = self::template_ref( $entry );
		$template = self::resolve_template( $ref );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$before = is_array( $entry['before'] ?? null ) ? $entry['before'] : [];
		$after  = is_array( $entry['after'] ?? null ) ? $entry['after'] : [];

		if ( ! array_key_exists( 'content', $before ) || ! array_key_exists( 'content', $after ) ) {
			return new \WP_Error(
				'flavor_agent_undo_snapshot_unsupported',
				'This activity row does not record the before/after content snapshots needed for a server-side undo.',
				[ 'status' => 409 ]
			);
		}

		$live_hash   = self::content_hash( (string) ( $template->content ?? '' ) );
		$before_hash = self::content_hash( (string) $before['content'] );
		$after_hash  = self::content_hash( (string) $after['content'] );

		if ( hash_equals( $live_hash, $before_hash ) ) {
			return [ 'result' => 'already_undone' ];
		}

		if ( ! hash_equals( $live_hash, $after_hash ) ) {
			return new \WP_Error(
				'flavor_agent_undo_drift',
				'The template changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
				[ 'status' => 409 ]
			);
		}

		$fresh = self::assert_template_unchanged( $ref, $live_hash );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$persisted = self::persist( $fresh, (string) $before['content'] );

		return is_wp_error( $persisted ) ? $persisted : [ 'result' => 'undone' ];
	}
```

Replace `assert_part_unchanged()` with `assert_template_unchanged()` that **returns the fresh entity**:

```php
	/**
	 * Final concurrency gate: re-resolve the live template immediately before a
	 * write, fail closed if its parsed -> reserialized content hash moved since
	 * $expected_hash, and otherwise RETURN the fresh entity so the caller persists
	 * against the current wp_id (closing the same-content materialization race).
	 *
	 * @return object|\WP_Error
	 */
	private static function assert_template_unchanged( string $ref, string $expected_hash ): object {
		$current = self::resolve_template( $ref );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( ! hash_equals( self::content_hash( (string) ( $current->content ?? '' ) ), $expected_hash ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_changed',
				'The template changed before Flavor Agent could persist this operation. Regenerate the request and try again.',
				[ 'status' => 409 ]
			);
		}

		return $current;
	}
```

Replace `persist()` — write a `wp_template` post with the `wp_theme` term only, and guard the materialize branch against an existing row for the same slug+theme:

```php
	/**
	 * Persist the mutated content: update a DB-backed template in place, or
	 * materialize a theme-file template into a wp_template post on first apply.
	 * Receives the entity re-resolved by the concurrency gate, so a same-content
	 * materialization by another actor between read and write updates in place
	 * rather than inserting a duplicate. Fails closed; invalidates caches.
	 *
	 * @return int|\WP_Error The persisted post id.
	 */
	private static function persist( object $template, string $content ): int|\WP_Error {
		$wp_id = (int) ( $template->wp_id ?? 0 );

		if ( $wp_id > 0 ) {
			$updated = wp_update_post(
				[
					'ID'           => $wp_id,
					'post_content' => $content,
				],
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			if ( 0 === (int) $updated ) {
				return new \WP_Error(
					'flavor_agent_apply_write_failed',
					'Flavor Agent could not write the template entity.',
					[ 'status' => 500 ]
				);
			}

			self::invalidate_template_cache( (int) $updated );

			return (int) $updated;
		}

		$slug       = sanitize_key( (string) ( $template->slug ?? '' ) );
		$stylesheet = function_exists( 'get_stylesheet' ) ? sanitize_key( (string) get_stylesheet() ) : '';

		if ( '' === $slug || '' === $stylesheet ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Cannot materialize a template without a slug and active theme.',
				[ 'status' => 500 ]
			);
		}

		// Duplicate-row guard: if a wp_template post already exists for this
		// slug + theme (a concurrent materialization), update it in place.
		$existing = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template' );

		foreach ( $existing as $candidate ) {
			$candidate_wp_id = (int) ( $candidate->wp_id ?? 0 );

			if ( $candidate_wp_id > 0 ) {
				return self::persist( (object) [ 'wp_id' => $candidate_wp_id ], $content );
			}
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => (string) ( $template->title ?? $slug ),
				'post_content' => $content,
				'tax_input'    => [
					'wp_theme' => [ $stylesheet ],
				],
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( 0 === (int) $post_id ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Flavor Agent could not materialize the template entity.',
				[ 'status' => 500 ]
			);
		}

		self::invalidate_template_cache( (int) $post_id );

		return (int) $post_id;
	}

	private static function invalidate_template_cache( int $post_id ): void {
		if ( $post_id > 0 && function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
	}
```

Delete the copied `invalidate_part_cache()` method (replaced by `invalidate_template_cache()`). Leave `apply_operations`, `apply_single_operation`, `effective_order_path`, `compare_paths`, `op_path`, `assert_expected_target`, `resolve_pattern_blocks`, `apply_insert`, and `content_hash` **verbatim** from the copy — they are surface-agnostic and already handle insert_pattern (including start/end with no `expectedTarget`).

- [ ] **Step 4: Add the registry arm**

In `inc/Apply/ExternalApplyExecutorRegistry.php`, update the docblock to describe the generalized seam (drop "from Task 7") and add the arm:

```php
	public static function for_surface( string $surface ): ?string {
		return match ( $surface ) {
			'global-styles', 'style-book' => StyleApplyExecutor::class,
			'template-part'               => TemplatePartApplyExecutor::class,
			'template'                    => TemplateApplyExecutor::class,
			default                       => null,
		};
	}
```

- [ ] **Step 5: Append the executor behavior tests**

Add these methods to `tests/phpunit/TemplateApplyExecutorTest.php` (reuse the `seed_template` helper from Task 2). Helpers:

```php
	private function entry( array $operations ): array {
		return [
			'surface' => 'template',
			'target'  => [ 'templateRef' => self::TEMPLATE_REF, 'templateType' => 'home' ],
			'apply'   => [ 'operations' => $operations ],
		];
	}

	private function register_pattern( string $name, string $content ): void {
		\WP_Block_Patterns_Registry::get_instance()->register(
			$name,
			[ 'title' => $name, 'content' => $content ]
		);
	}

	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}
```

Tests:

```php
	public function test_execute_inserts_pattern_at_start_and_persists_in_place(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 9100 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry( [
				[ 'type' => 'insert_pattern', 'patternName' => 'tt5/hero', 'placement' => 'start' ],
			] )
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Hero', $result['after']['content'] );
		$this->assertStringContainsString( 'Body', $result['after']['content'] );
		$this->assertStringStartsWith( '<!-- wp:paragraph --><p>Hero', $result['after']['content'] );
		$this->assertSame( $this->paragraph( 'Body' ), $result['before']['content'] );
	}

	public function test_execute_rejects_non_insert_pattern_ops_fail_closed(): void {
		$this->seed_template( $this->paragraph( 'Body' ), 9100 );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry( [
				[ 'type' => 'remove_block', 'targetPath' => [ 0 ] ],
			] )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		// No write: the seeded post is unchanged.
		$this->assertSame( $this->paragraph( 'Body' ), WordPressTestState::$posts[ 9100 ]->post_content );
	}

	public function test_execute_materializes_a_theme_file_template_once(): void {
		// wp_id = 0 (pristine theme-file template).
		$this->seed_template( $this->paragraph( 'Body' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry( [
				[ 'type' => 'insert_pattern', 'patternName' => 'tt5/hero', 'placement' => 'start' ],
			] )
		);

		$this->assertIsArray( $result );
		$inserted = array_filter(
			WordPressTestState::$posts,
			static fn ( $p ) => 'wp_template' === $p->post_type
		);
		$this->assertCount( 1, $inserted, 'Exactly one wp_template row may be created.' );
	}

	public function test_undo_restores_the_before_snapshot(): void {
		$before = $this->paragraph( 'Body' );
		$after  = $this->paragraph( 'Hero' ) . $before;
		$this->seed_template( $after, 9100 );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::undo(
			[
				'surface' => 'template',
				'target'  => [ 'templateRef' => self::TEMPLATE_REF, 'templateType' => 'home' ],
				'before'  => [ 'content' => $before ],
				'after'   => [ 'content' => $after ],
			]
		);

		$this->assertSame( [ 'result' => 'undone' ], $result );
		$this->assertSame( $before, WordPressTestState::$posts[ 9100 ]->post_content );
	}

	public function test_executor_implements_the_contract_and_registry_routes_template(): void {
		$this->assertInstanceOf(
			\FlavorAgent\Apply\ExternalApplyExecutor::class,
			new class() extends \FlavorAgent\Apply\TemplateApplyExecutor {} // ensure it is instantiable as the interface type
		);
	}

	public function test_registry_routes_template_to_its_executor(): void {
		$this->assertSame(
			\FlavorAgent\Apply\TemplateApplyExecutor::class,
			\FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'template' )
		);
	}
```

> Note: `TemplateApplyExecutor` has a private constructor (copied from the mirror); if the anonymous-subclass assertion in `test_executor_implements_the_contract...` fails to compile, replace that test body with `$this->assertTrue( is_subclass_of( \FlavorAgent\Apply\TemplateApplyExecutor::class, \FlavorAgent\Apply\ExternalApplyExecutor::class ) );` (a reflection check, no instantiation).

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/phpunit/TemplateApplyExecutorTest.php`
Expected: PASS (all executor tests + the two Task-2 resolver tests).

- [ ] **Step 7: Commit**

```bash
git add inc/Apply/TemplateApplyExecutor.php inc/Apply/ExternalApplyExecutorRegistry.php tests/phpunit/TemplateApplyExecutorTest.php
git commit -m "feat(template-apply): add TemplateApplyExecutor + registry arm (insert_pattern v1)"
```

---

## Task 4: Request handler `ApplyAbilities::request_template_apply`

**Files:**
- Modify: `inc/Abilities/ApplyAbilities.php`
- Test: `tests/phpunit/ExternalApplyLifecycleTest.php` (request-gate tests added here)

**Interfaces:**
- Consumes: `TemplateAbilities::recommend_template` (signature probe), `TemplateApplyExecutor::resolve_baseline` (Task 3), `ServerCollector::for_template`, `TemplatePrompt::validate_operations_for_apply` (Task 1), `ActivityRepository`.
- Produces: `ApplyAbilities::request_template_apply( mixed $input ): array|\WP_Error` returning `{ activityId, status:'pending', expiresAt, requestReference }`; writes a pending row with `surface:'template'`, `type:'apply_template_suggestion'`, `document.scopeKey:'wp_template:'+ref`, `document.postType:'wp_template'`, `document.entityKind:'template'`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/phpunit/ExternalApplyLifecycleTest.php` (it already exercises `request_template_part_apply` stale handling — mirror that). Add:

```php
	public function test_request_template_apply_rejects_invalid_scope(): void {
		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'      => [ 'surface' => 'template' ], // missing templateRef
				'operations' => [ [ 'type' => 'insert_pattern', 'placement' => 'start', 'patternName' => 'x' ] ],
				'signatures' => [ 'resolvedContextSignature' => 'a', 'reviewContextSignature' => 'b' ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_template_scope', $result->get_error_code() );
	}

	public function test_request_template_apply_rejects_non_insert_pattern_ops(): void {
		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'      => [ 'surface' => 'template', 'templateRef' => 'tt5//home', 'templateType' => 'home' ],
				'operations' => [ [ 'type' => 'remove_block', 'targetPath' => [ 0 ] ] ],
				'signatures' => [ 'resolvedContextSignature' => 'a', 'reviewContextSignature' => 'b' ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
	}

	public function test_request_template_apply_rejects_stale_signatures(): void {
		$result = \FlavorAgent\Abilities\ApplyAbilities::request_template_apply(
			[
				'scope'      => [ 'surface' => 'template', 'templateRef' => 'tt5//home', 'templateType' => 'home' ],
				'operations' => [ [ 'type' => 'insert_pattern', 'placement' => 'start', 'patternName' => 'x' ] ],
				'signatures' => [ 'resolvedContextSignature' => 'stale', 'reviewContextSignature' => 'stale' ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );
	}
```

> If the existing `ExternalApplyLifecycleTest` seeds a template world for signature recompute (mirroring `seed_template_part`), reuse it; otherwise the stale test passes because the recomputed signature never equals `'stale'`. Keep the scope/op-type tests (they fail before the signature probe) as the load-bearing gate checks.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/phpunit/ExternalApplyLifecycleTest.php`
Expected: FAIL with `Call to undefined method ...::request_template_apply()`.

- [ ] **Step 3: Add the `use` import and the handler**

In `inc/Abilities/ApplyAbilities.php`, add to the `use` block:

```php
use FlavorAgent\Apply\TemplateApplyExecutor;
use FlavorAgent\LLM\TemplatePrompt;
```

Add the handler method after `request_template_part_apply()`:

```php
	/**
	 * Template analog of request_template_part_apply: two fail-closed freshness
	 * gates, a per-user pending cap, then a PENDING activity row the admin
	 * approval surface later approves/rejects. v1 = insert_pattern only. No
	 * attestation (frozen-lane).
	 */
	public static function request_template_apply( mixed $input ): array|\WP_Error {
		$input             = self::normalize_map( $input );
		$scope             = self::normalize_map( $input['scope'] ?? [] );
		$prompt            = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';
		$operations        = self::normalize_list( $input['operations'] ?? [] );
		$signatures        = self::normalize_map( $input['signatures'] ?? [] );
		$provided_resolved = sanitize_text_field( (string) ( $signatures['resolvedContextSignature'] ?? '' ) );
		$provided_review   = sanitize_text_field( (string) ( $signatures['reviewContextSignature'] ?? '' ) );
		$surface           = sanitize_key( (string) ( $scope['surface'] ?? '' ) );
		$template_ref      = sanitize_text_field( (string) ( $scope['templateRef'] ?? '' ) );
		$template_type     = sanitize_key( (string) ( $scope['templateType'] ?? '' ) );

		if ( 'template' !== $surface || '' === $template_ref ) {
			return new \WP_Error(
				'invalid_template_scope',
				'External template applies require a template scope with a templateRef.',
				[ 'status' => 400 ]
			);
		}

		if ( [] === $operations ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'External template applies require at least one executable operation.',
				[ 'status' => 400 ]
			);
		}

		// v1 guard: insert_pattern only.
		foreach ( $operations as $operation ) {
			if ( 'insert_pattern' !== ( is_array( $operation ) ? ( $operation['type'] ?? '' ) : '' ) ) {
				return new \WP_Error(
					'flavor_agent_apply_operations_invalid',
					'External template applies support insert_pattern only in v1.',
					[ 'status' => 400 ]
				);
			}
		}

		if ( '' === $provided_resolved || '' === $provided_review ) {
			return new \WP_Error(
				'flavor_agent_apply_stale',
				'External template applies require the resolved and review context signatures from the recommendation response.',
				[ 'status' => 409 ]
			);
		}

		// First freshness gate: recompute both signatures through the same
		// signature-only path the editor uses. Replay every optional field that
		// can participate in the signed recommend-template envelope, but only when
		// the caller actually supplied it so absent keys never shift signatures.
		$probe_input = [
			'templateRef'          => $template_ref,
			'prompt'               => $prompt,
			'resolveSignatureOnly' => true,
		];

		if ( '' !== $template_type ) {
			$probe_input['templateType'] = $template_type;
		}

		if ( array_key_exists( 'visiblePatternNames', $input ) ) {
			$probe_input['visiblePatternNames'] = self::normalize_list( $input['visiblePatternNames'] );
		}

		if ( array_key_exists( 'designSemantics', $input ) ) {
			$probe_input['designSemantics'] = $input['designSemantics'];
		}

		$signature_probe = TemplateAbilities::recommend_template( $probe_input );

		if ( is_wp_error( $signature_probe ) ) {
			return $signature_probe;
		}

		$recomputed_resolved = (string) ( $signature_probe['resolvedContextSignature'] ?? '' );
		$recomputed_review   = (string) ( $signature_probe['reviewContextSignature'] ?? '' );

		if (
			! hash_equals( $recomputed_resolved, $provided_resolved )
			|| ! hash_equals( $recomputed_review, $provided_review )
		) {
			return self::stale_error();
		}

		// Second freshness gate (request time): capture the live content baseline,
		// then re-validate every operation against the live contract.
		$baseline = TemplateApplyExecutor::resolve_baseline(
			[
				'surface' => 'template',
				'target'  => [ 'templateRef' => $template_ref, 'templateType' => $template_type ],
			]
		);

		if ( is_wp_error( $baseline ) ) {
			return $baseline;
		}

		$context = ServerCollector::for_template( $template_ref, '' !== $template_type ? $template_type : null );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$validated = TemplatePrompt::validate_operations_for_apply( $operations, $context );

		if ( [] === $validated['operations'] || count( $validated['operations'] ) !== count( $operations ) ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'One or more proposed template operations failed validation against the current execution contract.',
				[
					'status'            => 400,
					'validationReasons' => $validated['reasons'],
				]
			);
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$cap     = max( 1, (int) apply_filters( self::PENDING_CAP_FILTER, self::DEFAULT_PENDING_CAP ) );

		if ( ActivityRepository::count_active_pending_external_applies( $user_id ) >= $cap ) {
			return new \WP_Error(
				'flavor_agent_apply_queue_full',
				sprintf(
					'You already have %d pending external applies awaiting review. Wait for a decision or expiry before requesting more.',
					$cap
				),
				[ 'status' => 429 ]
			);
		}

		$suggestion        = self::normalize_map( $input['suggestion'] ?? [] );
		$timestamp         = gmdate( 'c' );
		$day_in_seconds    = defined( 'DAY_IN_SECONDS' ) ? \DAY_IN_SECONDS : 86400;
		$ttl               = max( 60, (int) apply_filters( self::PENDING_TTL_FILTER, $day_in_seconds ) );
		$expires_at        = gmdate( 'c', time() + $ttl );
		$scope_key         = 'wp_template:' . $template_ref;
		$request_reference = sanitize_text_field( (string) ( $input['requestReference'] ?? '' ) );

		$created = ActivityRepository::create(
			[
				'type'            => 'apply_template_suggestion',
				'surface'         => 'template',
				'target'          => [
					'templateRef'  => $template_ref,
					'templateType' => $template_type,
					'slug'         => sanitize_key( (string) ( $scope['slug'] ?? '' ) ),
					'title'        => sanitize_text_field( (string) ( $scope['title'] ?? '' ) ),
				],
				'suggestion'      => sanitize_text_field( (string) ( $suggestion['label'] ?? 'External template apply request' ) ),
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'timestamp'       => $timestamp,
				'request'         => [
					'prompt'      => $prompt,
					'reference'   => '' !== $request_reference ? $request_reference : 'external-apply:' . $scope_key,
					'requestMeta' => [
						'ability'            => 'flavor-agent/request-template-apply',
						'executionTransport' => 'wp-abilities',
						'route'              => 'wp-abilities:flavor-agent/request-template-apply',
					],
					'apply'       => [
						'status'           => 'pending',
						'requestedBy'      => $user_id,
						'requestedAt'      => $timestamp,
						'expiresAt'        => $expires_at,
						'operations'       => $validated['operations'],
						'signatures'       => [
							'resolvedContextSignature' => $provided_resolved,
							'reviewContextSignature'   => $provided_review,
							'baselineContentHash'      => $baseline,
						],
						'requestReference' => $request_reference,
					],
				],
				'document'        => [
					'scopeKey'   => $scope_key,
					'postType'   => 'wp_template',
					'entityId'   => $template_ref,
					'entityKind' => 'template',
					'entityName' => 'template',
				],
			]
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return [
			'activityId'       => (string) ( $created['id'] ?? '' ),
			'status'           => 'pending',
			'expiresAt'        => $expires_at,
			'requestReference' => $request_reference,
		];
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/phpunit/ExternalApplyLifecycleTest.php`
Expected: PASS (new request-gate tests green; existing tests still green).

- [ ] **Step 5: Commit**

```bash
git add inc/Abilities/ApplyAbilities.php tests/phpunit/ExternalApplyLifecycleTest.php
git commit -m "feat(template-apply): add ApplyAbilities::request_template_apply with both freshness gates"
```

---

## Task 5: Ability class + Registration wiring + roster/count tests

**Files:**
- Create: `inc/AI/Abilities/RequestTemplateApplyAbility.php`
- Modify: `inc/Abilities/Registration.php`
- Test: `tests/phpunit/RegistrationTest.php`, `tests/phpunit/MCPServerBootstrapTest.php`

**Interfaces:**
- Consumes: `ApplyAbilities::request_template_apply` (Task 4).
- Produces: ability `flavor-agent/request-template-apply` registered via `Registration::external_apply_ability_classes()`; `Registration::template_apply_input_schema()`, `Registration::template_structural_operation_schema()`, `external_apply_meta()` + `external_apply_output_schema()` arms; the dedicated MCP roster auto-grows to 13 (it is `array_merge(recommendation_ability_classes, external_apply_ability_classes)`).

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/MCPServerBootstrapTest.php`, add an assertion and bump the count in `test_register_creates_dedicated_server_with_recommendation_tools`:

```php
		$this->assertContains( 'flavor-agent/request-template-apply', $call[9] );
```

and change `$this->assertCount( 12, $call[9] );` → `$this->assertCount( 13, $call[9] );`.

In `tests/phpunit/RegistrationTest.php`, add:

```php
	public function test_request_template_apply_is_a_registered_external_apply_ability(): void {
		$this->assertArrayHasKey(
			'flavor-agent/request-template-apply',
			\FlavorAgent\Abilities\Registration::external_apply_ability_classes()
		);
	}

	public function test_template_apply_input_schema_restricts_operations_to_insert_pattern(): void {
		$schema = \FlavorAgent\Abilities\Registration::template_apply_input_schema( 'flavor-agent/request-template-apply' );
		$op     = $schema['properties']['operations']['items'];

		$this->assertSame( [ 'insert_pattern' ], $op['properties']['type']['enum'] );
		$this->assertSame(
			[ 'start', 'end', 'before_block_path', 'after_block_path' ],
			$op['properties']['placement']['enum']
		);
	}
```

> If `RegistrationTest` asserts a total ability count elsewhere, update that expectation from 31 to 32 in the same step. Grep first: `grep -n "31\|thirty-one" tests/phpunit/RegistrationTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/phpunit/RegistrationTest.php tests/phpunit/MCPServerBootstrapTest.php`
Expected: FAIL (missing ability key / undefined `template_apply_input_schema` / count mismatch).

- [ ] **Step 3: Create the ability class**

Create `inc/AI/Abilities/RequestTemplateApplyAbility.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use WordPress\AI\Abstracts\Abstract_Ability;

final class RequestTemplateApplyAbility extends Abstract_Ability {

	public const ABILITY_NAME = 'flavor-agent/request-template-apply';

	public function input_schema(): array {
		return Registration::template_apply_input_schema( self::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::external_apply_output_schema( self::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		return ApplyAbilities::request_template_apply( $input );
	}

	public function permission_callback( mixed $input = null ): bool {
		unset( $input );

		return \current_user_can( 'edit_theme_options' );
	}

	public function meta(): array {
		return Registration::external_apply_meta( self::ABILITY_NAME );
	}

	public function category(): string {
		return 'flavor-agent';
	}
}
```

- [ ] **Step 4: Wire it in Registration**

In `inc/Abilities/Registration.php`:

Add the `use` import near `RequestTemplatePartApplyAbility`:

```php
use FlavorAgent\AI\Abilities\RequestTemplateApplyAbility;
```

In `external_apply_ability_classes()`, add after the `request-template-part-apply` entry:

```php
				'flavor-agent/request-template-apply'      => [
					'label'         => __( 'Request a governed template apply', 'flavor-agent' ),
					'description'   => __( 'Queue a reviewed page-level template structural apply (a single bounded pattern insertion) from a recommend-template result. Validates the operation and freshness signatures, then creates a pending approval row a site administrator decides in Settings > AI Activity. Mutates nothing until approved.', 'flavor-agent' ),
					'ability_class' => RequestTemplateApplyAbility::class,
				],
```

In `external_apply_meta()`, add `'flavor-agent/request-template-apply'` to the `destructive:false / idempotent:false` match arm:

```php
			'flavor-agent/request-style-apply',
			'flavor-agent/request-template-part-apply',
			'flavor-agent/request-template-apply' => [
				'destructive' => false,
				'idempotent'  => false,
			],
```

In `external_apply_output_schema()`, add `'flavor-agent/request-template-apply'` to the request-* match arm:

```php
			'flavor-agent/request-style-apply',
			'flavor-agent/request-template-part-apply',
			'flavor-agent/request-template-apply' => [
				'type'       => 'object',
				'properties' => [
					'activityId'       => [ 'type' => 'string' ],
					'status'           => [ 'type' => 'string' ],
					'expiresAt'        => [ 'type' => 'string' ],
					'requestReference' => [ 'type' => 'string' ],
				],
			],
```

Add the new schema builders (place next to `template_part_apply_input_schema` / `structural_operation_schema`):

```php
	/**
	 * One proposed template structural operation. v1 is insert_pattern-only, so
	 * the type enum is restricted here (NOT the template-part structural schema,
	 * which also allows replace_block_with_pattern / remove_block). Placement uses
	 * the live template contract's strings.
	 *
	 * @return array<string, mixed>
	 */
	private static function template_structural_operation_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'type'           => [
					'type' => 'string',
					'enum' => [ 'insert_pattern' ],
				],
				'patternName'    => [ 'type' => 'string' ],
				'placement'      => [
					'type' => 'string',
					'enum' => [ 'start', 'end', 'before_block_path', 'after_block_path' ],
				],
				'targetPath'     => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'expectedTarget' => self::open_object_schema(),
			],
		];
	}

	/**
	 * Permissive request-template-apply envelope (mirrors template_part_apply_input_schema)
	 * with the template-specific operation schema and scope keys.
	 *
	 * @return array<string, mixed>
	 */
	public static function template_apply_input_schema( string $ability_id ): array {
		return match ( $ability_id ) {
			'flavor-agent/request-template-apply' => [
				'type'       => 'object',
				'properties' => [
					'scope'               => self::open_object_schema(
						[
							'surface'      => [ 'type' => 'string' ],
							'templateRef'  => [ 'type' => 'string' ],
							'templateType' => [ 'type' => 'string' ],
							'slug'         => [ 'type' => 'string' ],
							'title'        => [ 'type' => 'string' ],
						],
						'Template scope: the same scope shape sent to recommend-template.'
					),
					'prompt'              => [
						'type'        => 'string',
						'description' => 'The prompt sent to recommend-template, byte-identical, so the resolved signature recomputes.',
					],
					'visiblePatternNames' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'designSemantics'     => self::open_object_schema(),
					'operations'          => [
						'type'  => 'array',
						'items' => self::template_structural_operation_schema(),
					],
					'signatures'          => [
						'type'       => 'object',
						'properties' => [
							'resolvedContextSignature' => [ 'type' => 'string' ],
							'reviewContextSignature'   => [ 'type' => 'string' ],
						],
						'required'   => [ 'resolvedContextSignature', 'reviewContextSignature' ],
					],
					'suggestion'          => self::open_object_schema(
						[
							'label'       => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
						],
						'Human-readable label shown to the approver.'
					),
					'requestReference'    => [
						'type'        => 'string',
						'description' => 'Optional opaque agent-side reference echoed back on reads.',
					],
				],
				'required'   => [ 'scope', 'operations', 'signatures' ],
			],
			default => self::open_object_schema(),
		};
	}
```

(Optional polish: update the MCP server description string in `inc/MCP/ServerBootstrap.php:38` to mention "template" alongside "template-part" — not asserted by any test.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/phpunit/RegistrationTest.php tests/phpunit/MCPServerBootstrapTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add inc/AI/Abilities/RequestTemplateApplyAbility.php inc/Abilities/Registration.php tests/phpunit/RegistrationTest.php tests/phpunit/MCPServerBootstrapTest.php
git commit -m "feat(template-apply): register request-template-apply ability + schema (count 31->32)"
```

---

## Task 6: Lifecycle integration — approve / execute / undo / attestation guard

**Files:**
- Test: `tests/phpunit/ExternalApplyLifecycleTest.php`

**Interfaces:**
- Consumes: `PendingApplyDecision::decide`, `ApplyAbilities::undo_activity`, the Task-3 executor via the registry, and the Task-4 pending-row shape.
- Produces: end-to-end proof that a `'template'` row approves → executes → is undoable, and that **no attestation** is recorded.

- [ ] **Step 1: Write the failing tests**

Add to `tests/phpunit/ExternalApplyLifecycleTest.php`, mirroring `test_attestation_is_not_recorded_for_template_part_apply` and the seeding helpers. Add a template seeder mirroring `seed_template_part`:

```php
	private function seed_template( string $content, int $wp_id ): void {
		WordPressTestState::$active_theme = [ 'stylesheet' => 'twentytwentyfive' ];
		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => 'twentytwentyfive//home',
				'wp_id'   => $wp_id,
				'slug'    => 'home',
				'title'   => 'Home',
				'content' => $content,
			],
		];
		WordPressTestState::$posts[ $wp_id ] = new \WP_Post(
			[ 'ID' => $wp_id, 'post_type' => 'wp_template', 'post_content' => $content ]
		);
	}

	/**
	 * Build a pending template row directly in the repository (bypassing the
	 * request gate) so decide()/undo can be exercised in isolation.
	 *
	 * @return array<string, mixed>
	 */
	private function create_template_pending_entry( string $content ): array {
		return \FlavorAgent\Activity\Repository::create(
			[
				'type'            => 'apply_template_suggestion',
				'surface'         => 'template',
				'target'          => [ 'templateRef' => 'twentytwentyfive//home', 'templateType' => 'home', 'slug' => 'home', 'title' => 'Home' ],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'timestamp'       => gmdate( 'c' ),
				'request'         => [
					'apply' => [
						'status'     => 'pending',
						'operations' => [
							[ 'type' => 'insert_pattern', 'patternName' => 'tt5/hero', 'placement' => 'start' ],
						],
						'signatures' => [
							'baselineContentHash' => \FlavorAgent\Apply\TemplateApplyExecutor::resolve_baseline(
								[ 'surface' => 'template', 'target' => [ 'templateRef' => 'twentytwentyfive//home' ] ]
							),
						],
					],
				],
				'document'        => [ 'scopeKey' => 'wp_template:twentytwentyfive//home', 'postType' => 'wp_template', 'entityId' => 'twentytwentyfive//home', 'entityKind' => 'template' ],
			]
		);
	}

	public function test_registry_routes_template_to_its_executor(): void {
		$this->assertSame(
			\FlavorAgent\Apply\TemplateApplyExecutor::class,
			\FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'template' )
		);
	}

	public function test_template_apply_approves_executes_and_is_not_attested(): void {
		$secret_key = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $secret_key );
		\FlavorAgent\Attestation\Repository::install();

		$failures = [];
		add_action(
			'flavor_agent_attestation_record_failed',
			static function ( array $event ) use ( &$failures ): void {
				$failures[] = $event;
			}
		);

		$content = '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->';
		$this->seed_template( $content, 9300 );
		\WP_Block_Patterns_Registry::get_instance()->register(
			'tt5/hero',
			[ 'title' => 'Hero', 'content' => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->' ]
		);
		$row = $this->create_template_pending_entry( $content );

		$decided = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $row['id'], 'approve' );

		$this->assertIsArray( $decided );
		$this->assertSame( 'available', $decided['apply']['status'] );
		$this->assertNull(
			\FlavorAgent\Attestation\Repository::find_by_related_activity( (string) $row['id'] ),
			'No attestation may be recorded for a template apply.'
		);
		$this->assertSame( [], $failures, 'record_apply must never be attempted for the template surface.' );
	}
```

- [ ] **Step 2: Run tests to verify they fail, then pass**

Run: `vendor/bin/phpunit tests/phpunit/ExternalApplyLifecycleTest.php`
Expected: the new tests are GREEN with no production changes (the seam already dispatches `'template'` via Task 3's registry arm and the attestation gate already excludes non-style surfaces). If `test_template_apply_approves_executes_and_is_not_attested` fails because the attestation branch is somehow reached, that is a real regression — re-check that Task 3 did **not** add `'template'` to any attestation `in_array` branch.

- [ ] **Step 3: Commit**

```bash
git add tests/phpunit/ExternalApplyLifecycleTest.php
git commit -m "test(template-apply): lifecycle approve/execute + frozen-attestation guard"
```

---

## Task 7: Admin governance projection (`'template'` branch)

**Files:**
- Modify: `src/admin/activity-log-utils.js`
- Test: `src/admin/__tests__/activity-log-utils.test.js`

**Interfaces:**
- Consumes: the entry shape from Task 4 (`surface:'template'`, `target.templateRef`, structural `apply.operations`).
- Produces: `'template'` branches in `getGovernanceApprovalCopy`, `getGovernanceTargetLabel`, and the `formatOperationSummary` selector so template rows render structural (not style-shaped) approval copy, label, and operation summaries.

- [ ] **Step 1: Write the failing tests**

In `src/admin/__tests__/activity-log-utils.test.js`, add (import the functions the file already exports/uses; mirror existing template-part assertions):

```js
describe( 'template surface governance projection', () => {
	it( 'uses structural approval copy for template rows', () => {
		const details = getGovernanceDetails( {
			surface: 'template',
			apply: {
				status: 'pending',
				operations: [ { type: 'insert_pattern', placement: 'start', patternName: 'tt5/hero' } ],
			},
			target: { templateRef: 'tt5//home' },
		} );
		expect( details.approvalCopy.decision ).toMatch( /structural change/i );
		expect( details.approvalCopy.decision ).not.toMatch( /style change/i );
	} );

	it( 'labels the template target by ref', () => {
		const details = getGovernanceDetails( {
			surface: 'template',
			apply: { status: 'pending', operations: [] },
			target: { templateRef: 'tt5//home' },
		} );
		expect( details.targetLabel ).toMatch( /tt5\/\/home/ );
	} );
} );
```

> Confirm the exact accessor (`details.approvalCopy` vs `details.governanceApprovalCopy`) by reading how `getGovernanceDetails` returns the copy near `targetLabel: getGovernanceTargetLabel( entry )`; align the assertion to the real key.

- [ ] **Step 2: Run tests to verify they fail**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js`
Expected: FAIL (template falls through to style copy / generic label).

- [ ] **Step 3: Add the `'template'` branches**

In `src/admin/activity-log-utils.js`:

`getGovernanceApprovalCopy( surface )` — add before the `template-part` branch:

```js
	if ( surface === 'template' ) {
		return {
			reviewIntro: __(
				'An external agent requested this bounded template apply. Review the operation, provenance, and freshness evidence before deciding.',
				'flavor-agent'
			),
			retained: __(
				'This external template apply row is retained for approval, provenance, freshness, and undo review.',
				'flavor-agent'
			),
			decision: __(
				'AI proposes; WordPress approves. Approving applies this bounded structural change from WordPress; rejecting keeps the site unchanged.',
				'flavor-agent'
			),
		};
	}
```

`getGovernanceTargetLabel( entry )` — add before the `template-part` branch:

```js
	if ( entry?.surface === 'template' ) {
		return sprintf(
			/* translators: %s: template identifier. */
			__( 'Template %s', 'flavor-agent' ),
			entry?.target?.slug || entry?.target?.templateRef || EMPTY_VALUE
		);
	}
```

The `formatOperationSummary` selector inside `getGovernanceDetails` — broaden it to include `'template'`:

```js
	const formatOperationSummary = [ 'template-part', 'template' ].includes(
		entry?.surface
	)
		? formatStructuralOperationSummary
		: formatStyleOperationSummary;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js`
Expected: PASS.

- [ ] **Step 5: Lint + commit**

```bash
npx wp-scripts lint-js src/admin/activity-log-utils.js --fix
git add src/admin/activity-log-utils.js src/admin/__tests__/activity-log-utils.test.js
git commit -m "feat(template-apply): template branch in admin governance approval/label/summary"
```

---

## Task 8: Docs, freshness guard, ability count, work-queue close-out

**Files:**
- Modify: `CLAUDE.md`, `.github/copilot-instructions.md`, `docs/reference/abilities-and-routes.md`, `docs/reference/governance-layer.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/SOURCE_OF_TRUTH.md`, `STATUS.md`, `docs/reference/local-environment-setup.md`, `scripts/check-doc-freshness.sh`, `docs/reference/current-open-work.md`

- [ ] **Step 1: Bump the count + add the route/ability everywhere it is inventoried**

Update each doc's "31 abilities" / "31 ability contracts" / "five external-apply abilities" wording to **32** / **six external-apply abilities**, and add `flavor-agent/request-template-apply` (capability `edit_theme_options`) plus the `wp_template` external-apply lane to the surface/governance maps. Grep to find every count-bearing string first:

```bash
grep -rn "31 abilit\|thirty-one\|five external-apply\|29 abilit\|full list to 31" \
  CLAUDE.md .github/copilot-instructions.md docs/reference/abilities-and-routes.md \
  docs/reference/governance-layer.md docs/FEATURE_SURFACE_MATRIX.md docs/SOURCE_OF_TRUTH.md \
  STATUS.md docs/reference/local-environment-setup.md
```

Update `docs/reference/local-environment-setup.md` Explorer note ("bringing the full list to 31 abilities" → 32; add `request-template-apply` to the external-apply list).

- [ ] **Step 2: Bump the hard-coded count in the freshness guard**

In `scripts/check-doc-freshness.sh`, update the assertion strings (the "thirty-one ability contracts" / "`inc/Abilities/Registration.php` defines 31 ability contracts" guards around lines 214-215) to **32**. Grep first:

```bash
grep -n "thirty-one\|defines 31\|31 ability" scripts/check-doc-freshness.sh
```

- [ ] **Step 3: Move the work-queue row**

In `docs/reference/current-open-work.md`, move page-level `wp_template` from open → shipped (add a dated Status entry mirroring the template-part shipped entry, and drop the page-level row from Current Implementation Candidates), and **keep the block-surface row open**.

- [ ] **Step 4: Run the docs guard**

Run: `npm run check:docs`
Expected: exit 0. If it fails, the guard's own count string was missed — fix and re-run.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md .github/copilot-instructions.md docs/reference/abilities-and-routes.md docs/reference/governance-layer.md docs/FEATURE_SURFACE_MATRIX.md docs/SOURCE_OF_TRUTH.md STATUS.md docs/reference/local-environment-setup.md scripts/check-doc-freshness.sh docs/reference/current-open-work.md
git commit -m "docs(template-apply): record wp_template external-apply lane (count 31->32)"
```

---

## Task 9: Full verification gate

**Files:** none (verification only)

- [ ] **Step 1: Targeted PHPUnit (single-file each)**

```bash
vendor/bin/phpunit tests/phpunit/TemplatePromptApplyValidationTest.php
vendor/bin/phpunit tests/phpunit/TemplateApplyExecutorTest.php
vendor/bin/phpunit tests/phpunit/ExternalApplyLifecycleTest.php
vendor/bin/phpunit tests/phpunit/RegistrationTest.php
vendor/bin/phpunit tests/phpunit/MCPServerBootstrapTest.php
```
Expected: all PASS.

- [ ] **Step 2: Targeted JS**

```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js
```
Expected: PASS.

- [ ] **Step 3: Aggregate verify (no E2E) + docs**

```bash
node scripts/verify.js --skip-e2e
npm run check:docs
```
Expected: `output/verify/summary.json` `status: pass`; `check:docs` exit 0. Inspect `output/verify/summary.json` and the `lint-plugin` step (the strict-table gate must stay green).

- [ ] **Step 4: Cross-surface gate note**

E2E is manual-only in the dev container for this surface (per `docs/reference/cross-surface-validation-gates.md` and the E2E coverage topology). Record the WP70 Site Editor manual check as a follow-up or an explicit waiver — do not silently skip.

- [ ] **Step 5: Final commit (if any verify-driven fixes landed)**

```bash
git add -A
git commit -m "chore(template-apply): verification fixes"
```

---

## Self-Review

**Spec coverage:**
- New ability + handler + schema → Tasks 4, 5. ✓
- `TemplateApplyExecutor` (execute/resolve_baseline/undo/persist, materialization, concurrency-gate-returns-fresh-entity) → Task 3. ✓
- Apply-time validator → Task 1. ✓ Governed-write resolver → Task 2. ✓
- Registry arm + generic decide/undo dispatch → Task 3 (arm) + Task 6 (lifecycle proof). ✓
- Freshness gates: Gate-1 (designSemantics/visiblePatternNames/editor overlays replayed only when supplied), Gate-2a capture, Gate-2b approval re-check (generic, unchanged), re-validation, concurrency gate → Tasks 3, 4. ✓
- Attestation frozen → Task 3 (no attestation branch added) + Task 6 (guard test). ✓
- Admin three-helper `template` branch → Task 7. ✓
- Docs + freshness-guard count + count-bearing refs + work-queue move → Task 8. ✓
- Tests incl. start/end no-expectedTarget, materialization race, designSemantics replay, MCP roster 13, RegistrationTest 32 → Tasks 1, 3, 5, 6. ✓ (The same-content materialization-race test is covered by the duplicate-row guard in Task 3's persist + `test_execute_materializes_a_theme_file_template_once`; add an explicit "second materialization updates in place" assertion if the stub store supports simulating a concurrent insert.)

**Placeholder scan:** No TBD/TODO; every code step shows complete code. Two "confirm the exact accessor/count" notes (Task 5 RegistrationTest count, Task 7 copy accessor) are verification instructions with a grep, not placeholders.

**Type consistency:** `validate_operations_for_apply` returns `{operations, reasons}` consistently (Tasks 1, 3, 4). Entry/target keys `templateRef`/`templateType` consistent across executor, handler, lifecycle test, admin label. `resolve_template_for_apply( string $ref )` signature consistent (Tasks 2, 3). `assert_template_unchanged` returns `object|\WP_Error` and is consumed as the fresh entity in both `execute` and `undo` (Task 3).
