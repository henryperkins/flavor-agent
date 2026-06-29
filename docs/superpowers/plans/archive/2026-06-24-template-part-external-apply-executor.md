# Template-Part External-Apply Executor Implementation Plan

> Archived 2026-06-28 after the template-part external-apply executor shipped and the remaining page-level/block-surface follow-ups were split back into the current work queue.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the C1 governed external-apply loop from the style lane to the template-part surface, so an external agent can request a drift-checked, human-approved, reversible structural apply (`insert_pattern` / `replace_block_with_pattern` / `remove_block`, ≤3 ops) to a single `wp_template_part`.

**Architecture:** Mirror `inc/Apply/StyleApplyExecutor.php` with a new `TemplatePartApplyExecutor` that reads the live part, re-validates the stored operations against a freshly-collected context, re-resolves each op's `expectedTarget` fingerprint, mutates the parsed block tree atomically through a new `BlockTreeMutator`, and persists via core post APIs (materializing a theme-file part on first apply). A thin `ExternalApplyExecutor` interface lets `PendingApplyDecision` and `ApplyAbilities::undo_activity` dispatch by surface. No attestation for template-part rows.

**Tech Stack:** PHP 8.2 (`FlavorAgent\Apply\`, PSR-4), WordPress block APIs (`parse_blocks`/`serialize_blocks`/`get_block_template`/`wp_insert_post`/`wp_update_post`), `WP_Block_Patterns_Registry`, PHPUnit (`vendor/bin/phpunit`), `@wordpress/scripts` Jest for the admin slice.

## Review revisions (2026-06-24, post-self-review against live code)

These supersede the task bodies where they conflict; each task below carries a `→ Rn` pointer. All nine were grounded against the real `TemplatePartPrompt::validate_operations`, `PendingApplyDecision`, `ApplyAbilities::request_style_apply`, `Registration`, and `tests/phpunit/bootstrap.php`.

**R1 — `apply_operations` ordering is unsound as drafted; replace with one descending pass (Task 5).**
The drafted "removals deepest-first, then insertions frozen" mis-orders any plan that mixes a removal/replacement with an anchored insert, and any multi-insert plan: an earlier edit shifts a later op's frozen path, so the insert lands one slot off — silently, failing **open**. This is the primary external-agent threat model (arbitrary valid `operations` arrays). `validate_operations` (`block_paths_overlap`, TemplatePartPrompt.php:1249) already rejects equal and ancestor/descendant path pairs, leaving only *sibling* relationships between distinct ops. Therefore applying **all** ops in one lexicographic-**descending** pass over their effective target path is correct: editing the higher path first never shifts a still-frozen lower path. Delete `path_order`; implement:

```php
private static function apply_operations( array $blocks, array $operations ): array|\WP_Error {
    // Phase 1 — verify every path-addressed op's expectedTarget against the ORIGINAL tree (fail closed, no mutation).
    foreach ( $operations as $operation ) {
        $path = self::op_path( $operation );
        if ( null !== $path ) {
            $err = self::assert_expected_target( BlockTreeMutator::resolve( $blocks, $path ), $operation );
            if ( is_wp_error( $err ) ) {
                return $err;
            }
        } elseif ( in_array( $operation['type'] ?? '', [ 'remove_block', 'replace_block_with_pattern' ], true ) ) {
            return new \WP_Error( 'flavor_agent_apply_target_changed', 'A targeted operation is missing its path.', [ 'status' => 409 ] );
        }
    }

    // Phase 2 — pre-resolve every pattern (fail closed, no mutation).
    $resolved_patterns = [];
    foreach ( $operations as $i => $operation ) {
        if ( in_array( $operation['type'] ?? '', [ 'insert_pattern', 'replace_block_with_pattern' ], true ) ) {
            $pattern_blocks = self::resolve_pattern_blocks( (string) ( $operation['patternName'] ?? '' ) );
            if ( is_wp_error( $pattern_blocks ) ) {
                return $pattern_blocks;
            }
            $resolved_patterns[ $i ] = $pattern_blocks;
        }
    }

    // Phase 3 — apply ALL ops in one lexicographic-DESCENDING pass over effective paths.
    // Overlap rejection guarantees no two ops are equal/ancestor/descendant, so higher-first never
    // shifts a later op's frozen path; this composes removes, replaces and inserts (incl. mixed/multi-insert).
    $indexed = [];
    foreach ( $operations as $i => $operation ) {
        $indexed[] = [ 'index' => $i, 'operation' => $operation ];
    }
    usort(
        $indexed,
        static fn ( array $a, array $b ): int => self::compare_paths(
            self::effective_order_path( $b['operation'] ),
            self::effective_order_path( $a['operation'] )
        )
    );
    foreach ( $indexed as $item ) {
        $blocks = self::apply_single_operation( $blocks, $item['operation'], $resolved_patterns[ $item['index'] ] ?? [] );
        if ( is_wp_error( $blocks ) ) {
            return $blocks;
        }
    }

    return $blocks;
}

/** @param array<int,array<string,mixed>> $pattern_blocks */
private static function apply_single_operation( array $blocks, array $operation, array $pattern_blocks ): array|\WP_Error {
    switch ( (string) ( $operation['type'] ?? '' ) ) {
        case 'remove_block':
            return BlockTreeMutator::remove( $blocks, self::op_path( $operation ) ?? [] );
        case 'replace_block_with_pattern':
            return BlockTreeMutator::replace( $blocks, self::op_path( $operation ) ?? [], $pattern_blocks );
        case 'insert_pattern':
            return self::apply_insert( $blocks, $operation, $pattern_blocks );
    }
    return new \WP_Error( 'flavor_agent_apply_operations_invalid', 'Unsupported operation type at apply time.', [ 'status' => 409 ] );
}

/** 'end' sorts above every concrete path (applied first); 'start' below (applied last). @return int[] */
private static function effective_order_path( array $operation ): array {
    $placement = (string) ( $operation['placement'] ?? '' );
    if ( 'end' === $placement )   { return [ PHP_INT_MAX ]; }
    if ( 'start' === $placement ) { return [ PHP_INT_MIN ]; }
    return self::op_path( $operation ) ?? [ PHP_INT_MIN ];
}

/** Lexicographic compare of two 0-indexed paths (ancestor/descendant pairs are already rejected). @param int[] $a @param int[] $b */
private static function compare_paths( array $a, array $b ): int {
    $len = max( count( $a ), count( $b ) );
    for ( $i = 0; $i < $len; $i++ ) {
        $av = $a[ $i ] ?? PHP_INT_MIN;
        $bv = $b[ $i ] ?? PHP_INT_MIN;
        if ( $av !== $bv ) {
            return $av <=> $bv;
        }
    }
    return 0;
}
```

Keep `op_path`, `assert_expected_target`, `resolve_pattern_blocks`, and `apply_insert` as drafted (`apply_insert` already derives parent/index from the frozen anchor path, which is valid under descending order). Add the R8 regression tests.

**R2 — Registry/test contradiction (Tasks 1 & 7).** `for_surface('template-part')` returns the class-string `TemplatePartApplyExecutor::class` (resolved at compile time; the class need not exist), so it is **never null**. Fix: in Task 1 **omit** the `'template-part'` match arm entirely — `for_surface` has only the `global-styles`/`style-book` arm + `default => null`, so Task 1's `assertNull('template-part')` passes. Task 7 **adds** the `'template-part' => TemplatePartApplyExecutor::class` arm and flips the assertion to `assertSame`.

**R3 — Drift-gate keys confirmed; do NOT add an attributes gate.** `build_expected_target` emits `name`/`childCount` (verified, TemplatePartPrompt.php:639), so `assert_expected_target`'s reads are correct as drafted. It also emits `attributes`/`slot`, but those come from the analyzer's context summary, not raw parsed `attrs` — an equality gate against live `attrs` would fail **closed** on valid applies. Keep name + childCount only.

**R4 — Registration needs three arms + an explicit schema, not "reuse as-is" (Task 9).** Verified against Registration.php:190–419:
1. Add a `'flavor-agent/request-template-part-apply'` entry to `external_apply_ability_classes()` (+ `use ... RequestTemplatePartApplyAbility;`).
2. Add `'flavor-agent/request-template-part-apply'` to the **`external_apply_meta()` match** alongside `request-style-apply` (`destructive:false, idempotent:false`). Without this it hits `default => readonly:true`, mislabeling a write-queue ability.
3. Add `'flavor-agent/request-template-part-apply'` to the **`external_apply_output_schema()` match** alongside `request-style-apply` (same `activityId/status/expiresAt/requestReference` arm). The plan's "reusable as-is" is wrong — the match keys on `$ability_id`, so template-part would fall to `default => open_object_schema()`.
4. Add a dedicated `template_part_apply_input_schema()` + `structural_operation_schema()` (the ability class calls the former directly). Mirror the style schema's permissive `open_object_schema()` envelope so the strict abilities-bridge ajv accepts `prompt`/`templatePartContext`/`suggestion`/`requestReference`; `required => ['scope','operations','signatures']` (NOT `templatePartContext`). The op item enum: `type ∈ {insert_pattern, replace_block_with_pattern, remove_block}`, `placement ∈ {start, end, before_block_path, after_block_path}`, `targetPath: integer[]`, `expectedTarget: open object`.

**R5 — No filter seams; mirror StyleApplyExecutor via the bootstrap WP stubs (Tasks 4–6).** Drop the three `flavor_agent_*_for_apply` `apply_filters` seams — a public filter that can swap a governed apply's resolved subject or persisted content is a trust-boundary smell for this plugin. `bootstrap.php` already stubs `get_block_template`/`get_block_templates` (2686/2717), `WP_Block_Patterns_Registry` (1929), `parse_blocks` (3366). So: resolve via `ServerCollector::resolve_template_part_for_apply()` (→ `TemplateRepository::resolve_template_part` → `get_block_template`), collect via `ServerCollector::for_template_part()`, persist via `wp_update_post`/`wp_insert_post`. Tests seed the part into the `get_block_template(s)` stub store and register patterns via `WP_Block_Patterns_Registry::get_instance()->register(...)`, asserting against captured post writes — exactly how `StyleApplyExecutorTest` seeds `WordPressTestState::$posts`. **Task-4 step 0:** read those bootstrap stubs; extend them for `wp_template_part` insert/resolve + `serialize_blocks`/`wp_insert_post`/`wp_update_post` if absent, mirroring the `wp_global_styles` handling.

**R6 — Add a nested-insert mutator test (Task 2).** The drafted insert test is top-level only and bypasses `splice_inner_content` entirely — yet that nested branch is what `insert_pattern` relies on. Add: insert a heading at `BlockTreeMutator::insert($blocks, [0], 0, $new)` into a group wrapping one paragraph; assert child order, `count(nulls)===2`, and `serialize_blocks` round-trips.

**R7 — Invalidate caches after persist (Task 5).** Append `clean_post_cache($post_id)` after every write, plus the block-template resolution cache the active WP uses (confirm the exact key against core's `WP_REST_Templates_Controller::update_item` / `clean_block_template`, and against the bootstrap stub). Add a test that `undo()` after `execute()` reads fresh content (guards a false-positive drift on the round-trip).

**R8 — Test matrix for Task 5** (all via real stubbed parts/patterns, no filter seams): remove (nested) + before/after snapshot · replace_block_with_pattern success · insert before/after/start/end success · childCount mismatch → fail-closed-no-write · type/name mismatch → fail-closed-no-write · unregistered pattern → fail-closed-no-write · **mixed** `remove [0]` + `insert after [2]` lands both correctly (R1 guard) · **multi-insert** `after [0]` + `after [2]` lands both at intended gaps (R1 guard) · **replace+insert** `replace [1]` (1→N) + `insert after [3]` correct.

**R9 — Drop the unimplemented free function.** Task 1's Interfaces line mentions `external_apply_executor_for()`; only the static `ExternalApplyExecutorRegistry::for_surface` exists. Remove the free-function phrasing.

---

## Global Constraints

- PHP 8.2+, WP 7.0+; `declare(strict_types=1)` in every new PHP file; namespace `FlavorAgent\`.
- Surface string is exactly `template-part`; activity `type` is exactly `apply_template_part_suggestion`.
- The ≤3-operation cap, path-overlap rejection, and operation vocabulary are owned by `inc/LLM/TemplatePartPrompt.php`; do NOT re-define them — reuse `validate_operations`.
- **No attestation** for template-part: never pass a `template-part` surface to `AttestationService` (its `assert_owned_lane_context()` throws). Leave `external-style-apply-v1`, `Canonicalizer`, and `StatementBuilder` untouched.
- Drift fails closed everywhere. Any re-validation, content-hash, `expectedTarget`, or pattern-resolution failure aborts with zero writes.
- The existing style lane behavior must stay byte-identical after the dispatch-seam refactor (Task 1) — `ExternalApplyLifecycleTest` and `StyleApplyExecutorTest` must stay green.
- Run PHPUnit single-file (`vendor/bin/phpunit --filter` or one path), never ad-hoc multi-file batches (they under-collect).
- Commit after every task. JS formatting only via `npm run lint:js -- --fix`.

---

## Task Group A — Executor core & dispatch seam (foundation)

### Task 1: `ExternalApplyExecutor` interface + style-lane dispatch (behavior-preserving)

**Files:**
- Create: `inc/Apply/ExternalApplyExecutor.php`
- Modify: `inc/Apply/StyleApplyExecutor.php` (add interface methods; keep existing 4-arg `apply` + `undo`)
- Modify: `inc/Apply/PendingApplyDecision.php:74-165` (dispatch via interface for the style surface)
- Modify: `inc/Abilities/ApplyAbilities.php:301-347` (resolve executor via dispatch)
- Test: `tests/phpunit/ExternalApplyLifecycleTest.php` (existing — must stay green)

**Interfaces:**
- Produces: `interface ExternalApplyExecutor { static resolve_baseline(array $entry): string|\WP_Error; static execute(array $entry): array|\WP_Error; static undo(array $entry): array|\WP_Error; }` where `execute` returns `array{target,before,after}` and `undo` returns `array{result:string}`. A static `ExternalApplyExecutorRegistry::for_surface(string $surface): ?class-string<ExternalApplyExecutor>` maps `global-styles`/`style-book` → `StyleApplyExecutor` (and, from Task 7, `template-part` → `TemplatePartApplyExecutor`). No free-function alias (R9).

- [ ] **Step 1: Write the failing test**

Add to `tests/phpunit/ExternalApplyLifecycleTest.php`:

```php
public function test_style_apply_executor_implements_the_external_apply_contract(): void {
    $this->assertInstanceOf(
        \ReflectionClass::class,
        new \ReflectionClass( \FlavorAgent\Apply\StyleApplyExecutor::class )
    );
    $this->assertTrue(
        is_subclass_of( \FlavorAgent\Apply\StyleApplyExecutor::class, \FlavorAgent\Apply\ExternalApplyExecutor::class ),
        'StyleApplyExecutor must implement ExternalApplyExecutor.'
    );
    $this->assertSame(
        \FlavorAgent\Apply\StyleApplyExecutor::class,
        \FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'global-styles' )
    );
    $this->assertNull( \FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'template-part' ) );
}
```

- [ ] **Step 2: Run it; expect FAIL**

Run: `vendor/bin/phpunit --filter test_style_apply_executor_implements_the_external_apply_contract`
Expected: FAIL (`ExternalApplyExecutor` / `ExternalApplyExecutorRegistry` not found).

- [ ] **Step 3: Create the interface and registry**

`inc/Apply/ExternalApplyExecutor.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Contract for a governed external-apply surface executor. The decision
 * service and the undo ability dispatch to an implementation by surface.
 */
interface ExternalApplyExecutor {

    /** Re-resolve the live subject and return the drift baseline string for gate 2. */
    public static function resolve_baseline( array $entry ): string|\WP_Error;

    /** @return array{target: array<string,mixed>, before: array<string,mixed>, after: array<string,mixed>}|\WP_Error */
    public static function execute( array $entry ): array|\WP_Error;

    /** @return array{result: string}|\WP_Error */
    public static function undo( array $entry ): array|\WP_Error;
}

final class ExternalApplyExecutorRegistry {

    /** @return class-string<ExternalApplyExecutor>|null */
    public static function for_surface( string $surface ): ?string {
        return match ( $surface ) {
            'global-styles', 'style-book' => StyleApplyExecutor::class,
            // 'template-part' arm is added in Task 7 — see Review revision R2.
            default                       => null,
        };
    }

    private function __construct() {}
}
```

Note (R2): do **not** add a `'template-part'` arm here. `Foo::class` resolves at compile time even when the class is absent, so an arm would make `for_surface('template-part')` return a non-null class-string and fail Step 1's `assertNull`. The arm — and the matching `assertSame` — land in Task 7.

- [ ] **Step 4: Make `StyleApplyExecutor` implement the interface**

In `inc/Apply/StyleApplyExecutor.php`, change the class declaration and add three adapter methods (keep the existing `apply()`/`undo()`):

```php
final class StyleApplyExecutor implements ExternalApplyExecutor {
    // ...existing constants/methods unchanged...

    public static function resolve_baseline( array $entry ): string|\WP_Error {
        $target   = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
        $resolved = self::resolve_user_global_styles( (string) ( $target['globalStylesId'] ?? '' ) );

        return is_wp_error( $resolved ) ? $resolved : self::comparable_config_hash( $resolved['config'] );
    }

    public static function execute( array $entry ): array|\WP_Error {
        $target     = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
        $apply      = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
        $operations = is_array( $apply['operations'] ?? null ) ? $apply['operations'] : [];

        return self::apply(
            (string) ( $entry['surface'] ?? '' ),
            (string) ( $target['globalStylesId'] ?? '' ),
            $operations,
            (string) ( $target['blockName'] ?? '' )
        );
    }
}
```

`undo( array $entry )` already matches the interface signature — no change.

- [ ] **Step 5: Dispatch in `PendingApplyDecision::decide` (style behavior unchanged)**

In `inc/Apply/PendingApplyDecision.php`, replace the inline `StyleApplyExecutor::resolve_user_global_styles(...)` baseline check and `StyleApplyExecutor::apply(...)` call (lines ~83-111) with executor dispatch, preserving the same `failed`/stale transitions:

```php
$executor = ExternalApplyExecutorRegistry::for_surface( $surface );

if ( null === $executor ) {
    return ActivityRepository::transition_external_apply( $activity_id, [
        'applyStatus'    => 'failed',
        'decidedBy'      => $decided_by,
        'decidedAt'      => $decided_at,
        'decisionNote'   => $note,
        'failureCode'    => 'flavor_agent_apply_surface_unsupported',
        'failureMessage' => 'No external-apply executor is registered for this surface.',
    ] );
}

// Second freshness check: live baseline must still equal the request-time baseline.
$baseline      = (string) ( $signatures['baselineConfigHash'] ?? $signatures['baselineContentHash'] ?? '' );
$live_baseline = $executor::resolve_baseline( $entry );

if ( is_wp_error( $live_baseline ) ) {
    return ActivityRepository::transition_external_apply( $activity_id, [
        'applyStatus' => 'failed', 'decidedBy' => $decided_by, 'decidedAt' => $decided_at,
        'decisionNote' => $note, 'failureCode' => (string) $live_baseline->get_error_code(),
        'failureMessage' => (string) $live_baseline->get_error_message(),
    ] );
}

if ( '' === $baseline || ! hash_equals( $live_baseline, $baseline ) ) {
    return ActivityRepository::transition_external_apply( $activity_id, [
        'applyStatus' => 'failed', 'decidedBy' => $decided_by, 'decidedAt' => $decided_at,
        'decisionNote' => $note, 'failureCode' => 'flavor_agent_apply_stale',
        'failureMessage' => 'The apply target changed after this apply was requested.',
    ] );
}

$result = $executor::execute( $entry );
```

Add `use FlavorAgent\Apply\ExternalApplyExecutorRegistry;` is unnecessary (same namespace). Keep the existing attestation block and the final `available` transition exactly as-is for this task — the attestation branch is Task 7.

- [ ] **Step 6: Dispatch in `ApplyAbilities::undo_activity`**

In `inc/Abilities/ApplyAbilities.php:301-347`, replace the hard-coded surface allowlist + `StyleApplyExecutor::undo( $entry )` with:

```php
$executor = \FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( $surface );

if ( null === $executor ) {
    return new \WP_Error(
        'flavor_agent_undo_surface_unsupported',
        'External undo is not supported for this activity surface.',
        [ 'status' => 400 ]
    );
}
// ...existing non-executed / undo-status / ordered-undo guards unchanged...
$result = $executor::undo( $entry );
```

- [ ] **Step 7: Run the full external-apply suite; expect PASS (style unchanged)**

Run: `vendor/bin/phpunit tests/phpunit/ExternalApplyLifecycleTest.php`
Then: `vendor/bin/phpunit tests/phpunit/StyleApplyExecutorTest.php`
Expected: PASS for both (behavior-preserving refactor) plus the new contract test.

- [ ] **Step 8: Commit**

```bash
git add inc/Apply/ExternalApplyExecutor.php inc/Apply/StyleApplyExecutor.php inc/Apply/PendingApplyDecision.php inc/Abilities/ApplyAbilities.php tests/phpunit/ExternalApplyLifecycleTest.php
git commit -m "refactor: dispatch external applies through an ExternalApplyExecutor seam"
```

---

### Task 2: `BlockTreeMutator` — `innerContent`-correct path mutation

**Files:**
- Create: `inc/Apply/BlockTreeMutator.php`
- Test: `tests/phpunit/BlockTreeMutatorTest.php`

**Interfaces:**
- Produces: `BlockTreeMutator::resolve(array $blocks, array $path): ?array` (returns the block at a 0-indexed path, or null); `BlockTreeMutator::remove(array $blocks, array $path): array`; `BlockTreeMutator::replace(array $blocks, array $path, array $replacement_blocks): array`; `BlockTreeMutator::insert(array $blocks, array $parent_path, int $index, array $new_blocks): array`. All operate on `parse_blocks()` output and keep `innerBlocks`↔`innerContent` null-markers consistent so `serialize_blocks()` round-trips.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/BlockTreeMutatorTest.php`:

```php
<?php
declare(strict_types=1);

use FlavorAgent\Apply\BlockTreeMutator;
use PHPUnit\Framework\TestCase;

final class BlockTreeMutatorTest extends TestCase {

    private function nested(): array {
        // <!-- wp:group --> wrapping a heading and a paragraph.
        return parse_blocks(
            '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
            . '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->'
        );
    }

    public function test_resolve_returns_block_at_path(): void {
        $block = BlockTreeMutator::resolve( $this->nested(), [ 0, 1 ] );
        $this->assertSame( 'core/paragraph', $block['blockName'] );
    }

    public function test_remove_nested_block_round_trips_without_orphan_markers(): void {
        $next = BlockTreeMutator::remove( $this->nested(), [ 0, 0 ] ); // remove heading
        $html = serialize_blocks( $next );
        $this->assertStringNotContainsString( 'wp:heading', $html );
        $this->assertStringContainsString( 'wp:paragraph', $html );
        // Parent group still has exactly one inner block, markers intact.
        $this->assertCount( 1, BlockTreeMutator::resolve( $next, [ 0 ] )['innerBlocks'] );
        $this->assertSame( 1, self::count_nulls( BlockTreeMutator::resolve( $next, [ 0 ] )['innerContent'] ) );
    }

    public function test_replace_nested_block_with_multiple_blocks_round_trips(): void {
        $replacement = parse_blocks( '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->' );
        $next        = BlockTreeMutator::replace( $this->nested(), [ 0, 0 ], $replacement );
        $group       = BlockTreeMutator::resolve( $next, [ 0 ] );
        $this->assertSame( 'core/separator', $group['innerBlocks'][0]['blockName'] );
        $this->assertSame( 2, self::count_nulls( $group['innerContent'] ) ); // separator + paragraph
        $this->assertStringContainsString( 'wp:separator', serialize_blocks( $next ) );
    }

    public function test_insert_at_top_level_index(): void {
        $blocks = parse_blocks( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->' );
        $new    = parse_blocks( '<!-- wp:heading --><h2>New</h2><!-- /wp:heading -->' );
        $next   = BlockTreeMutator::insert( $blocks, [], 0, $new );
        $this->assertSame( 'core/heading', $next[0]['blockName'] );
        $this->assertSame( 'core/paragraph', $next[1]['blockName'] );
    }

    private static function count_nulls( array $inner_content ): int {
        return count( array_filter( $inner_content, static fn ( $chunk ) => null === $chunk ) );
    }
}
```

- [ ] **Step 2: Run; expect FAIL**

Run: `vendor/bin/phpunit tests/phpunit/BlockTreeMutatorTest.php`
Expected: FAIL (`BlockTreeMutator` not found).

- [ ] **Step 3: Implement `BlockTreeMutator`**

`inc/Apply/BlockTreeMutator.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Path-addressed mutation over parse_blocks() output that keeps each parent
 * block's innerBlocks list and innerContent null-markers consistent, so
 * serialize_blocks() reproduces valid markup. Paths are 0-indexed; [] is the
 * top-level list (which has no innerContent wrapper).
 */
final class BlockTreeMutator {

    /** @return array<string,mixed>|null */
    public static function resolve( array $blocks, array $path ): ?array {
        $node = null;
        $list = $blocks;

        foreach ( $path as $index ) {
            if ( ! array_key_exists( $index, $list ) || ! is_array( $list[ $index ] ) ) {
                return null;
            }
            $node = $list[ $index ];
            $list = is_array( $node['innerBlocks'] ?? null ) ? $node['innerBlocks'] : [];
        }

        return $node;
    }

    public static function remove( array $blocks, array $path ): array {
        return self::splice_at_path( $blocks, $path, 1, [] );
    }

    /** @param array<int,array<string,mixed>> $replacement_blocks */
    public static function replace( array $blocks, array $path, array $replacement_blocks ): array {
        return self::splice_at_path( $blocks, $path, 1, array_values( $replacement_blocks ) );
    }

    /** @param array<int,array<string,mixed>> $new_blocks */
    public static function insert( array $blocks, array $parent_path, int $index, array $new_blocks ): array {
        $child_path   = $parent_path;
        $child_path[] = $index;

        return self::splice_at_path( $blocks, $child_path, 0, array_values( $new_blocks ) );
    }

    /**
     * Splice $remove_count blocks at $path with $insert_blocks, adjusting the
     * parent's innerContent null-markers when the path is nested.
     *
     * @param array<int,array<string,mixed>> $insert_blocks
     */
    private static function splice_at_path( array $blocks, array $path, int $remove_count, array $insert_blocks ): array {
        if ( [] === $path ) {
            return $blocks;
        }

        $index = (int) array_pop( $path );

        if ( [] === $path ) {
            array_splice( $blocks, $index, $remove_count, $insert_blocks );

            return $blocks;
        }

        // Walk to the parent block, mutate, and write it back up the path.
        return self::with_node( $blocks, $path, static function ( array $parent ) use ( $index, $remove_count, $insert_blocks ): array {
            $inner = is_array( $parent['innerBlocks'] ?? null ) ? $parent['innerBlocks'] : [];
            array_splice( $inner, $index, $remove_count, $insert_blocks );
            $parent['innerBlocks']  = $inner;
            $parent['innerContent'] = self::splice_inner_content(
                is_array( $parent['innerContent'] ?? null ) ? $parent['innerContent'] : [],
                $index,
                $remove_count,
                count( $insert_blocks )
            );

            return $parent;
        } );
    }

    /**
     * Replace the null-marker run for child $block_index: drop $remove_count
     * null markers and write $insert_count fresh nulls in their place.
     *
     * @param array<int,string|null> $inner_content
     * @return array<int,string|null>
     */
    private static function splice_inner_content( array $inner_content, int $block_index, int $remove_count, int $insert_count ): array {
        $result   = [];
        $null_seen = 0;
        $done     = false;

        foreach ( $inner_content as $chunk ) {
            if ( null !== $chunk ) {
                $result[] = $chunk;
                continue;
            }

            if ( ! $done && $null_seen === $block_index ) {
                for ( $i = 0; $i < $insert_count; $i++ ) {
                    $result[] = null;
                }
                // Skip the removed markers.
                $null_seen += $remove_count;
                $done       = true;
                continue;
            }

            if ( $done && $null_seen > $block_index && $null_seen < $block_index + $remove_count ) {
                ++$null_seen;
                continue; // still inside the removed run
            }

            $result[] = $chunk;
            ++$null_seen;
        }

        // Pure insert at end (block_index === existing null count).
        if ( ! $done && 0 === $remove_count ) {
            for ( $i = 0; $i < $insert_count; $i++ ) {
                $result[] = null;
            }
        }

        return $result;
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutate */
    private static function with_node( array $blocks, array $path, callable $mutate ): array {
        $index = (int) array_shift( $path );

        if ( ! array_key_exists( $index, $blocks ) || ! is_array( $blocks[ $index ] ) ) {
            return $blocks;
        }

        if ( [] === $path ) {
            $blocks[ $index ] = $mutate( $blocks[ $index ] );

            return $blocks;
        }

        $inner = is_array( $blocks[ $index ]['innerBlocks'] ?? null ) ? $blocks[ $index ]['innerBlocks'] : [];
        $blocks[ $index ]['innerBlocks'] = self::with_node( $inner, $path, $mutate );

        return $blocks;
    }

    private function __construct() {}
}
```

- [ ] **Step 4: Run; expect PASS**

Run: `vendor/bin/phpunit tests/phpunit/BlockTreeMutatorTest.php`
Expected: PASS (4 tests). If `test_replace_nested_block_with_multiple_blocks_round_trips` fails on null-count, fix `splice_inner_content` before proceeding — the marker run is the load-bearing invariant.

- [ ] **Step 5: Commit**

```bash
git add inc/Apply/BlockTreeMutator.php tests/phpunit/BlockTreeMutatorTest.php
git commit -m "feat: add innerContent-correct block-tree path mutator"
```

---

### Task 3: `TemplatePartPrompt::validate_operations_for_apply()`

**Files:**
- Modify: `inc/LLM/TemplatePartPrompt.php` (add one public method that builds lookups from a context and calls the existing private `validate_operations`)
- Test: `tests/phpunit/TemplatePartPromptApplyValidationTest.php`

**Interfaces:**
- Consumes: the private `build_block_lookup`/`build_pattern_lookup`/`build_operation_target_lookup`/`build_insertion_anchor_lookup`/`validate_operations` (TemplatePartPrompt.php:421-1127).
- Produces: `TemplatePartPrompt::validate_operations_for_apply( array $operations, array $context ): array{operations: array, reasons: array}` — the template-part analog of `StylePrompt::validate_operations_for_apply`.

- [ ] **Step 1: Write the failing test**

`tests/phpunit/TemplatePartPromptApplyValidationTest.php`:

```php
<?php
declare(strict_types=1);

use FlavorAgent\LLM\TemplatePartPrompt;
use PHPUnit\Framework\TestCase;

final class TemplatePartPromptApplyValidationTest extends TestCase {

    private function context(): array {
        return [
            'blockTree'        => [
                [ 'path' => [ 0 ], 'name' => 'core/navigation', 'label' => 'Navigation', 'attributes' => [], 'childCount' => 0 ],
            ],
            'operationTargets' => [
                [ 'path' => [ 0 ], 'name' => 'core/navigation', 'allowedOperations' => [ 'remove_block' ], 'allowedInsertions' => [] ],
            ],
            'insertionAnchors' => [],
            'patterns'         => [],
        ];
    }

    public function test_valid_remove_block_survives_apply_revalidation(): void {
        $result = TemplatePartPrompt::validate_operations_for_apply(
            [ [ 'type' => 'remove_block', 'targetPath' => [ 0 ], 'expectedBlockName' => 'core/navigation' ] ],
            $this->context()
        );
        $this->assertCount( 1, $result['operations'] );
        $this->assertSame( 'remove_block', $result['operations'][0]['type'] );
    }

    public function test_block_name_mismatch_is_rejected(): void {
        $result = TemplatePartPrompt::validate_operations_for_apply(
            [ [ 'type' => 'remove_block', 'targetPath' => [ 0 ], 'expectedBlockName' => 'core/paragraph' ] ],
            $this->context()
        );
        $this->assertCount( 0, $result['operations'] );
    }
}
```

- [ ] **Step 2: Run; expect FAIL**

Run: `vendor/bin/phpunit tests/phpunit/TemplatePartPromptApplyValidationTest.php`
Expected: FAIL (`validate_operations_for_apply` undefined).

- [ ] **Step 3: Add the public method**

In `inc/LLM/TemplatePartPrompt.php`, after `validate_operations_for_tests` (line ~1127):

```php
/**
 * Apply-time re-validation entry: rebuild the four lookups from a freshly
 * collected live context and run the same generation-time validator the
 * recommendation used. Mirrors StylePrompt::validate_operations_for_apply.
 *
 * @param array<int,mixed>     $operations
 * @param array<string,mixed>  $context  TemplatePartContextCollector::for_template_part() output.
 * @return array{operations: array<int,array<string,mixed>>, reasons: array<int,array{code:string,severity:string,message?:string}>}
 */
public static function validate_operations_for_apply( array $operations, array $context ): array {
    return self::validate_operations(
        $operations,
        self::build_block_lookup( $context ),
        self::build_pattern_lookup( $context ),
        self::build_operation_target_lookup( $context ),
        self::build_insertion_anchor_lookup( $context )
    );
}
```

- [ ] **Step 4: Run; expect PASS**

Run: `vendor/bin/phpunit tests/phpunit/TemplatePartPromptApplyValidationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/TemplatePartPrompt.php tests/phpunit/TemplatePartPromptApplyValidationTest.php
git commit -m "feat: add template-part apply-time operation re-validation entry"
```

---

### Task 4: `TemplatePartApplyExecutor::resolve_baseline()` + part resolution

**Files:**
- Create: `inc/Apply/TemplatePartApplyExecutor.php`
- Test: `tests/phpunit/TemplatePartApplyExecutorTest.php`

**Interfaces:**
- Consumes: `TemplateRepository::resolve_template_part(string $ref): ?object` (returns a `WP_Block_Template` with `->id`, `->wp_id`, `->slug`, `->area`, `->content`).
- Produces: `TemplatePartApplyExecutor::resolve_baseline(array $entry): string|\WP_Error` — sha256 of the parsed→reserialized live `post_content` for the part named by `entry.target.templatePartId`. Also `private static resolve_part(string $ref): object|\WP_Error` and `private static content_hash(string $content): string`.

- [ ] **Step 1: Write the failing test** (uses a stubbed repository via a filter seam)

```php
<?php
declare(strict_types=1);

use FlavorAgent\Apply\TemplatePartApplyExecutor;
use PHPUnit\Framework\TestCase;

final class TemplatePartApplyExecutorTest extends TestCase {

    protected function tearDown(): void {
        remove_all_filters( 'flavor_agent_resolve_template_part_for_apply' );
        parent::tearDown();
    }

    private function stub_part( string $content, int $wp_id = 0 ): void {
        add_filter(
            'flavor_agent_resolve_template_part_for_apply',
            static function () use ( $content, $wp_id ) {
                return (object) [
                    'id' => 'twentytwentyfive//header', 'wp_id' => $wp_id,
                    'slug' => 'header', 'area' => 'header', 'title' => 'Header',
                    'content' => $content,
                ];
            }
        );
    }

    public function test_resolve_baseline_hashes_reserialized_content(): void {
        $content = '<!-- wp:navigation /-->';
        $this->stub_part( $content );
        $hash = TemplatePartApplyExecutor::resolve_baseline(
            [ 'surface' => 'template-part', 'target' => [ 'templatePartId' => 'twentytwentyfive//header' ] ]
        );
        $this->assertSame( hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ), $hash );
    }

    public function test_resolve_baseline_errors_when_part_missing(): void {
        $result = TemplatePartApplyExecutor::resolve_baseline(
            [ 'surface' => 'template-part', 'target' => [ 'templatePartId' => 'no//such' ] ]
        );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }
}
```

- [ ] **Step 2: Run; expect FAIL**

Run: `vendor/bin/phpunit tests/phpunit/TemplatePartApplyExecutorTest.php`
Expected: FAIL (`TemplatePartApplyExecutor` not found).

> **R5:** drop the `flavor_agent_*_for_apply` filter seams. Resolve via `ServerCollector::resolve_template_part_for_apply()` → `TemplateRepository::resolve_template_part` → `get_block_template` (stubbed in `bootstrap.php:2717`); tests seed the part into that stub. No public filter on a governed write path.

- [ ] **Step 3: Create the executor skeleton with resolution + baseline**

`inc/Apply/TemplatePartApplyExecutor.php` (the rest of the methods are added in Tasks 5–6):

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\TemplatePartPrompt;

/**
 * Server-side executor for governed external template-part structural applies.
 * Mirrors StyleApplyExecutor: read the live part, re-validate operations and
 * expectedTarget fingerprints, mutate the parsed block tree atomically through
 * BlockTreeMutator, persist via core post APIs, and snapshot before/after
 * post_content. No attestation. See
 * docs/superpowers/specs/2026-06-24-template-part-external-apply-executor-design.md.
 */
final class TemplatePartApplyExecutor implements ExternalApplyExecutor {

    public static function resolve_baseline( array $entry ): string|\WP_Error {
        $part = self::resolve_part( self::part_ref( $entry ) );

        return is_wp_error( $part ) ? $part : self::content_hash( (string) ( $part->content ?? '' ) );
    }

    private static function part_ref( array $entry ): string {
        $target = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];

        return trim( (string) ( $target['templatePartId'] ?? '' ) );
    }

    /** @return object|\WP_Error */
    private static function resolve_part( string $ref ): object|\WP_Error {
        if ( '' === $ref ) {
            return new \WP_Error( 'flavor_agent_apply_target_unavailable', 'Missing template-part identifier.', [ 'status' => 409 ] );
        }

        // Test seam + indirection over the bound TemplateRepository.
        $part = apply_filters( 'flavor_agent_resolve_template_part_for_apply', null, $ref );

        if ( ! is_object( $part ) ) {
            $part = ServerCollector::resolve_template_part_for_apply( $ref );
        }

        if ( ! is_object( $part ) ) {
            return new \WP_Error( 'flavor_agent_apply_target_unavailable', 'The requested template part is not available on this site.', [ 'status' => 404 ] );
        }

        return $part;
    }

    private static function content_hash( string $content ): string {
        return hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) );
    }

    private function __construct() {}
}
```

Add a passthrough to `inc/Context/ServerCollector.php` (mirrors its other static facades):

```php
public static function resolve_template_part_for_apply( string $ref ): ?object {
    return self::template_repository()->resolve_template_part( $ref );
}
```

(If `ServerCollector` has no `template_repository()` accessor yet, add a private one mirroring its existing collaborator accessors — check `ServerCollector::for_template_part` wiring first.)

- [ ] **Step 4: Run; expect PASS**

Run: `vendor/bin/phpunit tests/phpunit/TemplatePartApplyExecutorTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add inc/Apply/TemplatePartApplyExecutor.php inc/Context/ServerCollector.php tests/phpunit/TemplatePartApplyExecutorTest.php
git commit -m "feat: add template-part executor resolution and drift baseline"
```

---

### Task 5: `TemplatePartApplyExecutor::execute()` — re-validate, resolve, mutate, persist

**Files:**
- Modify: `inc/Apply/TemplatePartApplyExecutor.php`
- Test: `tests/phpunit/TemplatePartApplyExecutorTest.php`

**Interfaces:**
- Consumes: `BlockTreeMutator` (Task 2), `TemplatePartPrompt::validate_operations_for_apply` (Task 3), `TemplatePartContextCollector::for_template_part` (via `ServerCollector::for_template_part`), `WP_Block_Patterns_Registry`.
- Produces: `TemplatePartApplyExecutor::execute(array $entry): array{target,before,after}|\WP_Error`. Persists through `private static persist(object $part, string $content): int|\WP_Error` which materializes a theme-file part (no `wp_id`) via `wp_insert_post` and updates a DB part via `wp_update_post`.

- [ ] **Step 1: Write failing tests for each op type + drift + atomic rollback + materialization**

Add to `tests/phpunit/TemplatePartApplyExecutorTest.php` (showing the two highest-value cases; add `insert_pattern`, `replace_block_with_pattern`, and an unregistered-pattern case the same way):

```php
public function test_execute_removes_block_and_snapshots_before_after(): void {
    $content = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $this->stub_part( $content, 0 );
    $captured = [];
    add_filter( 'flavor_agent_persist_template_part_for_apply', function ( $id, $part, $next ) use ( &$captured ) {
        $captured['content'] = $next; return 4321;
    }, 10, 3 );
    // Live context the executor re-collects: allow remove_block on the heading at [0,0].
    add_filter( 'flavor_agent_collect_template_part_context_for_apply', fn() => self::context_allowing( 'remove_block', [ 0, 0 ], 'core/heading' ) );

    $result = TemplatePartApplyExecutor::execute( self::entry( [
        [ 'type' => 'remove_block', 'targetPath' => [ 0, 0 ], 'expectedBlockName' => 'core/heading',
          'expectedTarget' => [ 'name' => 'core/heading', 'childCount' => 0 ] ],
    ] ) );

    $this->assertSame( $content, $result['before']['content'] );
    $this->assertStringNotContainsString( 'wp:heading', $result['after']['content'] );
    $this->assertStringNotContainsString( 'wp:heading', (string) $captured['content'] );
}

public function test_execute_fails_closed_on_expected_target_mismatch_without_writing(): void {
    $content = '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->';
    $this->stub_part( $content, 99 );
    $wrote = false;
    add_filter( 'flavor_agent_persist_template_part_for_apply', function () use ( &$wrote ) { $wrote = true; return 99; }, 10, 3 );
    add_filter( 'flavor_agent_collect_template_part_context_for_apply', fn() => self::context_allowing( 'remove_block', [ 0 ], 'core/paragraph' ) );

    $result = TemplatePartApplyExecutor::execute( self::entry( [
        [ 'type' => 'remove_block', 'targetPath' => [ 0 ], 'expectedBlockName' => 'core/paragraph',
          'expectedTarget' => [ 'name' => 'core/paragraph', 'childCount' => 7 ] ], // childCount lie
    ] ) );

    $this->assertInstanceOf( \WP_Error::class, $result );
    $this->assertFalse( $wrote, 'No persistence may occur when an operation fails re-resolution.' );
}
```

Add the `self::entry()`, `self::context_allowing()` private helpers to the test class (entry builds `{surface:'template-part', target:{templatePartId}, apply:{operations}}`; `context_allowing` returns a context whose `operationTargets`/`blockTree` permit the given op/path/name).

- [ ] **Step 2: Run; expect FAIL**

Run: `vendor/bin/phpunit tests/phpunit/TemplatePartApplyExecutorTest.php`
Expected: FAIL (`execute` undefined).

> **R1 + R5:** implement `apply_operations` and its ordering helpers from Review revision R1 — the `apply_operations`/`path_order` shown below is **superseded** (it mis-orders mixed and multi-insert plans, failing open). Resolve/collect/persist via real stubbed WP functions, not `apply_filters` seams. Add the R7 cache flush in `persist`. Cover the full R8 test matrix.

- [ ] **Step 3: Implement `execute()` and collaborators**

Add to `inc/Apply/TemplatePartApplyExecutor.php`:

```php
public static function execute( array $entry ): array|\WP_Error {
    $ref  = self::part_ref( $entry );
    $part = self::resolve_part( $ref );

    if ( is_wp_error( $part ) ) {
        return $part;
    }

    $before_content = (string) ( $part->content ?? '' );
    $apply          = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
    $operations     = is_array( $apply['operations'] ?? null ) ? $apply['operations'] : [];

    if ( [] === $operations ) {
        return new \WP_Error( 'flavor_agent_apply_operations_invalid', 'No operations to apply.', [ 'status' => 409 ] );
    }

    // Re-validate against a freshly collected live context.
    $context = apply_filters( 'flavor_agent_collect_template_part_context_for_apply', null, $ref );

    if ( ! is_array( $context ) ) {
        $context = ServerCollector::for_template_part( $ref );
    }

    if ( is_wp_error( $context ) ) {
        return $context;
    }

    $validated = TemplatePartPrompt::validate_operations_for_apply( $operations, $context );

    if ( [] === $validated['operations'] || count( $validated['operations'] ) !== count( $operations ) ) {
        return new \WP_Error(
            'flavor_agent_apply_operations_invalid',
            'One or more template-part operations failed re-validation against the live execution contract.',
            [ 'status' => 409, 'validationReasons' => $validated['reasons'] ]
        );
    }

    $blocks = parse_blocks( $before_content );
    $blocks = self::apply_operations( $blocks, $validated['operations'] );

    if ( is_wp_error( $blocks ) ) {
        return $blocks;
    }

    $after_content = serialize_blocks( $blocks );
    $persisted     = self::persist( $part, $after_content );

    if ( is_wp_error( $persisted ) ) {
        return $persisted;
    }

    return [
        'target' => [
            'templatePartId' => (string) ( $part->id ?? $ref ),
            'slug'           => (string) ( $part->slug ?? '' ),
            'area'           => (string) ( $part->area ?? '' ),
        ],
        'before' => [ 'content' => $before_content ],
        'after'  => [ 'content' => $after_content, 'operations' => $validated['operations'] ],
    ];
}

/**
 * Resolve and verify every target up front, then mutate. Removals/replacements
 * are applied deepest-and-last-sibling first so earlier indices stay valid;
 * insertions are applied last. Any expectedTarget mismatch or unresolved
 * pattern aborts with zero mutation (atomic).
 *
 * @param array<int,array<string,mixed>> $operations
 * @return array<int,array<string,mixed>>|\WP_Error
 */
private static function apply_operations( array $blocks, array $operations ): array|\WP_Error {
    // 1. Verify expectedTarget for every path-addressed op against the parsed tree.
    foreach ( $operations as $operation ) {
        $path = self::op_path( $operation );

        if ( null === $path && in_array( $operation['type'], [ 'remove_block', 'replace_block_with_pattern' ], true ) ) {
            return new \WP_Error( 'flavor_agent_apply_target_changed', 'A targeted operation is missing its path.', [ 'status' => 409 ] );
        }

        if ( null !== $path ) {
            $live = BlockTreeMutator::resolve( $blocks, $path );
            $err  = self::assert_expected_target( $live, $operation );

            if ( is_wp_error( $err ) ) {
                return $err;
            }
        }
    }

    // 2. Pre-resolve pattern blocks (fail closed before any mutation).
    $resolved_patterns = [];

    foreach ( $operations as $i => $operation ) {
        if ( in_array( $operation['type'], [ 'insert_pattern', 'replace_block_with_pattern' ], true ) ) {
            $pattern_blocks = self::resolve_pattern_blocks( (string) ( $operation['patternName'] ?? '' ) );

            if ( is_wp_error( $pattern_blocks ) ) {
                return $pattern_blocks;
            }

            $resolved_patterns[ $i ] = $pattern_blocks;
        }
    }

    // 3. Apply removals/replacements deepest-first, then insertions.
    $removals_replacements = [];
    $insertions            = [];

    foreach ( $operations as $i => $operation ) {
        if ( 'insert_pattern' === $operation['type'] ) {
            $insertions[] = [ $i, $operation ];
        } else {
            $removals_replacements[] = [ $i, $operation ];
        }
    }

    usort(
        $removals_replacements,
        static fn ( array $a, array $b ): int => self::path_order( self::op_path( $b[1] ) ) <=> self::path_order( self::op_path( $a[1] ) )
    );

    foreach ( $removals_replacements as [ $i, $operation ] ) {
        $path = self::op_path( $operation );

        $blocks = 'remove_block' === $operation['type']
            ? BlockTreeMutator::remove( $blocks, $path )
            : BlockTreeMutator::replace( $blocks, $path, $resolved_patterns[ $i ] );
    }

    foreach ( $insertions as [ $i, $operation ] ) {
        $blocks = self::apply_insert( $blocks, $operation, $resolved_patterns[ $i ] );

        if ( is_wp_error( $blocks ) ) {
            return $blocks;
        }
    }

    return $blocks;
}

/** @return int[]|null */
private static function op_path( array $operation ): ?array {
    if ( ! is_array( $operation['targetPath'] ?? null ) || [] === $operation['targetPath'] ) {
        return null;
    }

    return array_map( 'intval', $operation['targetPath'] );
}

/** Bigger = deeper / later sibling, so descending sort applies those first. */
private static function path_order( ?array $path ): int {
    if ( null === $path ) {
        return -1;
    }

    $order = count( $path ) * 1000;

    return $order + (int) end( $path );
}

private static function assert_expected_target( ?array $live, array $operation ): true|\WP_Error {
    $expected = is_array( $operation['expectedTarget'] ?? null ) ? $operation['expectedTarget'] : [];
    $name     = (string) ( $operation['expectedBlockName'] ?? ( $expected['name'] ?? '' ) );

    if ( null === $live ) {
        return new \WP_Error( 'flavor_agent_apply_target_changed', 'A targeted block no longer exists at its path.', [ 'status' => 409 ] );
    }

    if ( '' !== $name && (string) ( $live['blockName'] ?? '' ) !== $name ) {
        return new \WP_Error( 'flavor_agent_apply_target_changed', 'A targeted block changed type after the request.', [ 'status' => 409 ] );
    }

    if ( isset( $expected['childCount'] ) ) {
        $live_children = is_array( $live['innerBlocks'] ?? null ) ? count( $live['innerBlocks'] ) : 0;

        if ( $live_children !== (int) $expected['childCount'] ) {
            return new \WP_Error( 'flavor_agent_apply_target_changed', 'A targeted block changed its inner structure after the request.', [ 'status' => 409 ] );
        }
    }

    return true;
}

/** @return array<int,array<string,mixed>>|\WP_Error */
private static function resolve_pattern_blocks( string $pattern_name ): array|\WP_Error {
    if ( '' === $pattern_name || ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
        return new \WP_Error( 'flavor_agent_apply_pattern_unavailable', 'The requested pattern is not registered on this site.', [ 'status' => 409 ] );
    }

    $registered = \WP_Block_Patterns_Registry::get_instance()->get_registered( $pattern_name );
    $markup     = is_array( $registered ) ? (string) ( $registered['content'] ?? '' ) : '';

    if ( '' === trim( $markup ) ) {
        return new \WP_Error( 'flavor_agent_apply_pattern_unavailable', 'The requested pattern is not registered on this site (synced-only patterns are out of scope for v1).', [ 'status' => 409 ] );
    }

    return array_values( array_filter( parse_blocks( $markup ), static fn ( $b ): bool => is_array( $b ) && null !== ( $b['blockName'] ?? null ) ) );
}

/** @param array<int,array<string,mixed>> $pattern_blocks @return array<int,array<string,mixed>>|\WP_Error */
private static function apply_insert( array $blocks, array $operation, array $pattern_blocks ): array|\WP_Error {
    $placement = (string) ( $operation['placement'] ?? '' );
    $path      = self::op_path( $operation );

    if ( in_array( $placement, [ 'before_block_path', 'after_block_path' ], true ) ) {
        if ( null === $path ) {
            return new \WP_Error( 'flavor_agent_apply_target_changed', 'Insertion anchor path is missing.', [ 'status' => 409 ] );
        }
        $parent = array_slice( $path, 0, -1 );
        $index  = (int) end( $path ) + ( 'after_block_path' === $placement ? 1 : 0 );

        return BlockTreeMutator::insert( $blocks, $parent, $index, $pattern_blocks );
    }

    // start / end at top level.
    $index = 'end' === $placement ? count( $blocks ) : 0;

    return BlockTreeMutator::insert( $blocks, [], $index, $pattern_blocks );
}

/** @return int|\WP_Error post id */
private static function persist( object $part, string $content ): int|\WP_Error {
    $override = apply_filters( 'flavor_agent_persist_template_part_for_apply', null, $part, $content );

    if ( null !== $override ) {
        return is_wp_error( $override ) ? $override : (int) $override;
    }

    $wp_id = (int) ( $part->wp_id ?? 0 );

    if ( $wp_id > 0 ) {
        $updated = wp_update_post( [ 'ID' => $wp_id, 'post_content' => $content ], true );

        return is_wp_error( $updated ) ? $updated : (int) $updated;
    }

    // Materialize a theme-file part into a wp_template_part post (Site Editor parity).
    $slug       = sanitize_key( (string) ( $part->slug ?? '' ) );
    $stylesheet = function_exists( 'get_stylesheet' ) ? get_stylesheet() : '';

    if ( '' === $slug || '' === $stylesheet ) {
        return new \WP_Error( 'flavor_agent_apply_write_failed', 'Cannot materialize a template part without a slug and active theme.', [ 'status' => 500 ] );
    }

    $post_id = wp_insert_post(
        [
            'post_type'    => 'wp_template_part',
            'post_status'  => 'publish',
            'post_name'    => $slug,
            'post_title'   => (string) ( $part->title ?? $slug ),
            'post_content' => $content,
            'tax_input'    => [
                'wp_theme'              => [ $stylesheet ],
                'wp_template_part_area' => [ sanitize_key( (string) ( $part->area ?? 'uncategorized' ) ) ],
            ],
        ],
        true
    );

    return is_wp_error( $post_id ) ? $post_id : (int) $post_id;
}
```

- [ ] **Step 4: Run; expect PASS** (and add the insert/replace/unregistered-pattern cases from Step 1)

Run: `vendor/bin/phpunit tests/phpunit/TemplatePartApplyExecutorTest.php`
Expected: PASS for remove + expectedTarget-mismatch + (added) insert/replace/pattern-missing.

- [ ] **Step 5: Commit**

```bash
git add inc/Apply/TemplatePartApplyExecutor.php tests/phpunit/TemplatePartApplyExecutorTest.php
git commit -m "feat: template-part executor apply with re-validation, drift gates, atomic mutation"
```

---

### Task 6: `TemplatePartApplyExecutor::undo()` — drift-checked content restore

**Files:**
- Modify: `inc/Apply/TemplatePartApplyExecutor.php`
- Test: `tests/phpunit/TemplatePartApplyExecutorTest.php`

**Interfaces:**
- Produces: `TemplatePartApplyExecutor::undo(array $entry): array{result:string}|\WP_Error`. Equality semantics mirror `StyleApplyExecutor::undo`: live==before → `already_undone`; live!=after → `flavor_agent_undo_drift`; else restore `before.content`.

- [ ] **Step 1: Write failing tests**

```php
public function test_undo_restores_before_content_when_live_matches_after(): void {
    $before = '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->';
    $after  = '<!-- wp:paragraph --><p>Changed</p><!-- /wp:paragraph -->';
    $this->stub_part( $after, 55 );
    $captured = [];
    add_filter( 'flavor_agent_persist_template_part_for_apply', function ( $id, $part, $next ) use ( &$captured ) { $captured['content'] = $next; return 55; }, 10, 3 );

    $result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

    $this->assertSame( 'undone', $result['result'] );
    $this->assertSame( serialize_blocks( parse_blocks( $before ) ), serialize_blocks( parse_blocks( (string) $captured['content'] ) ) );
}

public function test_undo_fails_closed_on_drift(): void {
    $this->stub_part( '<!-- wp:heading --><h2>Edited elsewhere</h2><!-- /wp:heading -->', 55 );
    $result = TemplatePartApplyExecutor::undo( self::executed_entry(
        '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Changed</p><!-- /wp:paragraph -->'
    ) );
    $this->assertInstanceOf( \WP_Error::class, $result );
    $this->assertSame( 'flavor_agent_undo_drift', $result->get_error_code() );
}
```

Add `self::executed_entry($before,$after)` returning `{surface:'template-part', target:{templatePartId}, before:{content:$before}, after:{content:$after}}`.

- [ ] **Step 2: Run; expect FAIL.** Run: `vendor/bin/phpunit tests/phpunit/TemplatePartApplyExecutorTest.php` → FAIL (`undo` undefined).

- [ ] **Step 3: Implement `undo()`**

```php
public static function undo( array $entry ): array|\WP_Error {
    $part = self::resolve_part( self::part_ref( $entry ) );

    if ( is_wp_error( $part ) ) {
        return $part;
    }

    $before = is_array( $entry['before'] ?? null ) ? $entry['before'] : [];
    $after  = is_array( $entry['after'] ?? null ) ? $entry['after'] : [];

    if ( ! array_key_exists( 'content', $before ) || ! array_key_exists( 'content', $after ) ) {
        return new \WP_Error( 'flavor_agent_undo_snapshot_unsupported', 'This row lacks the content snapshots needed for a server-side undo.', [ 'status' => 409 ] );
    }

    $live_hash   = self::content_hash( (string) ( $part->content ?? '' ) );
    $before_hash = self::content_hash( (string) $before['content'] );
    $after_hash  = self::content_hash( (string) $after['content'] );

    if ( hash_equals( $live_hash, $before_hash ) ) {
        return [ 'result' => 'already_undone' ];
    }

    if ( ! hash_equals( $live_hash, $after_hash ) ) {
        return new \WP_Error( 'flavor_agent_undo_drift', 'The template part changed after Flavor Agent applied this suggestion and cannot be undone automatically.', [ 'status' => 409 ] );
    }

    $persisted = self::persist( $part, (string) $before['content'] );

    return is_wp_error( $persisted ) ? $persisted : [ 'result' => 'undone' ];
}
```

- [ ] **Step 4: Run; expect PASS.** Run: `vendor/bin/phpunit tests/phpunit/TemplatePartApplyExecutorTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add inc/Apply/TemplatePartApplyExecutor.php tests/phpunit/TemplatePartApplyExecutorTest.php
git commit -m "feat: drift-checked template-part undo with content restore"
```

---

### Task 7: Wire executor into dispatch + branch attestation to style-only

**Files:**
- Modify: `inc/Apply/ExternalApplyExecutor.php` (add the `template-part` registry arm — deferred from Task 1 per R2)
- Modify: `inc/Apply/PendingApplyDecision.php:127-151` (attestation only for style surfaces)
- Test: `tests/phpunit/ExternalApplyLifecycleTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_registry_routes_template_part_to_its_executor(): void {
    $this->assertSame(
        \FlavorAgent\Apply\TemplatePartApplyExecutor::class,
        \FlavorAgent\Apply\ExternalApplyExecutorRegistry::for_surface( 'template-part' )
    );
}

public function test_attestation_is_not_recorded_for_template_part_apply(): void {
    $recorded = [];
    add_filter( 'flavor_agent_attestation_record_failed', function ( $e ) use ( &$recorded ) { $recorded[] = $e; } );
    // Drive a template-part pending row through approval with a stubbed executor success,
    // assert AttestationService recorded nothing (no key configured in tests → record_apply returns null anyway,
    // but the branch must not even call it for template-part). Assert the style path still calls it.
    $this->markTestIncomplete( 'Drive via PendingApplyDecision::decide with a seeded template-part pending row.' );
}
```

Replace the incomplete marker with a concrete seeded-row drive once `ExternalApplyLifecycleTest` helpers for seeding pending rows are reused (they exist for the style lane — mirror them with `surface => 'template-part'`).

- [ ] **Step 2: Run; expect FAIL** (registry returns null for template-part until Task 5/7).

Run: `vendor/bin/phpunit --filter test_registry_routes_template_part_to_its_executor`

- [ ] **Step 3: Branch attestation in `PendingApplyDecision`**

Wrap the existing `AttestationService::record_apply(...)` block (lines ~127-151) in a surface guard:

```php
if ( in_array( $surface, [ 'global-styles', 'style-book' ], true ) ) {
    try {
        \FlavorAgent\Attestation\AttestationService::record_apply( [ /* ...existing args... */ ] );
    } catch ( \Throwable $e ) {
        \FlavorAgent\Attestation\AttestationService::record_failure( $e, [ 'operation' => 'apply', 'activityId' => $activity_id ] );
    }
}
```

Apply the identical `in_array($surface, ['global-styles','style-book'], true)` guard around the `record_revert` block in `ApplyAbilities::undo_activity:369-399`.

- [ ] **Step 4: Add the registry arm + update the assertion (R2)** — add `'template-part' => TemplatePartApplyExecutor::class` to `ExternalApplyExecutorRegistry::for_surface` (deferred from Task 1), then replace Task 1's `assertNull('template-part')` with the `assertSame(...)` test from Step 1.

- [ ] **Step 5: Run; expect PASS**

Run: `vendor/bin/phpunit tests/phpunit/ExternalApplyLifecycleTest.php`
Expected: PASS (style attestation still recorded; template-part attestation never attempted).

- [ ] **Step 6: Commit**

```bash
git add inc/Apply/PendingApplyDecision.php inc/Abilities/ApplyAbilities.php tests/phpunit/ExternalApplyLifecycleTest.php
git commit -m "feat: route template-part applies to their executor; keep attestation style-only"
```

---

## Task Group B — External entry point

### Task 8: `ApplyAbilities::request_template_part_apply()` handler

**Files:**
- Modify: `inc/Abilities/ApplyAbilities.php` (new public static method; reuse pending-cap/TTL helpers)
- Test: `tests/phpunit/ExternalApplyLifecycleTest.php`

**Interfaces:**
- Consumes: `TemplateAbilities::recommend_template_part([... 'resolveSignatureOnly' => true])` (returns `reviewContextSignature`/`resolvedContextSignature`), `TemplatePartApplyExecutor::resolve_baseline`, `TemplatePartPrompt::validate_operations_for_apply`, `ActivityRepository::create`/`count_active_pending_external_applies`.
- Produces: `ApplyAbilities::request_template_part_apply(mixed $input): array{activityId,status,expiresAt,requestReference}|\WP_Error`.

- [ ] **Step 1: Write failing tests** (mirror the style request tests: stale signatures → 409; happy path → pending row with `surface=template-part`, `type=apply_template_part_suggestion`, `baselineContentHash` present).

```php
public function test_request_template_part_apply_rejects_stale_signatures(): void {
    $result = \FlavorAgent\Abilities\ApplyAbilities::request_template_part_apply( [
        'scope'      => [ 'surface' => 'template-part', 'templatePartId' => 'twentytwentyfive//header' ],
        'operations' => [ [ 'type' => 'remove_block', 'targetPath' => [ 0 ], 'expectedBlockName' => 'core/navigation' ] ],
        'signatures' => [ 'resolvedContextSignature' => 'stale', 'reviewContextSignature' => 'stale' ],
    ] );
    $this->assertInstanceOf( \WP_Error::class, $result );
    $this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );
}
```

- [ ] **Step 2: Run; expect FAIL.** Run: `vendor/bin/phpunit --filter test_request_template_part_apply_rejects_stale_signatures`

- [ ] **Step 3: Implement the handler** (mirror `request_style_apply:24-211` exactly, swapping style specifics):

```php
public static function request_template_part_apply( mixed $input ): array|\WP_Error {
    $input             = self::normalize_map( $input );
    $scope             = self::normalize_map( $input['scope'] ?? [] );
    $tp_context        = self::normalize_map( $input['templatePartContext'] ?? [] );
    $prompt            = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';
    $operations        = self::normalize_list( $input['operations'] ?? [] );
    $signatures        = self::normalize_map( $input['signatures'] ?? [] );
    $provided_resolved = sanitize_text_field( (string) ( $signatures['resolvedContextSignature'] ?? '' ) );
    $provided_review   = sanitize_text_field( (string) ( $signatures['reviewContextSignature'] ?? '' ) );
    $surface           = sanitize_key( (string) ( $scope['surface'] ?? '' ) );
    $template_part_id  = sanitize_text_field( (string) ( $scope['templatePartId'] ?? '' ) );

    if ( 'template-part' !== $surface || '' === $template_part_id ) {
        return new \WP_Error( 'invalid_template_part_scope', 'External template-part applies require a template-part scope with a templatePartId.', [ 'status' => 400 ] );
    }
    if ( [] === $operations ) {
        return new \WP_Error( 'flavor_agent_apply_operations_invalid', 'External template-part applies require at least one operation.', [ 'status' => 400 ] );
    }
    if ( '' === $provided_resolved || '' === $provided_review ) {
        return new \WP_Error( 'flavor_agent_apply_stale', 'External template-part applies require resolved and review context signatures.', [ 'status' => 409 ] );
    }

    // Gate 1: recompute signatures via the resolveSignatureOnly path.
    $probe = \FlavorAgent\Abilities\TemplateAbilities::recommend_template_part( [
        'templatePartRef'      => $template_part_id,
        'templatePartContext'  => $tp_context,
        'prompt'               => $prompt,
        'resolveSignatureOnly' => true,
    ] );
    if ( is_wp_error( $probe ) ) {
        return $probe;
    }
    if ( ! hash_equals( (string) ( $probe['resolvedContextSignature'] ?? '' ), $provided_resolved )
        || ! hash_equals( (string) ( $probe['reviewContextSignature'] ?? '' ), $provided_review ) ) {
        return self::stale_error();
    }

    // Gate 2 (request time): live content must hash to the claimed baseline, and ops must re-validate.
    $entry_stub = [ 'surface' => 'template-part', 'target' => [ 'templatePartId' => $template_part_id ] ];
    $baseline   = \FlavorAgent\Apply\TemplatePartApplyExecutor::resolve_baseline( $entry_stub );
    if ( is_wp_error( $baseline ) ) {
        return $baseline;
    }
    $context = \FlavorAgent\Context\ServerCollector::for_template_part( $template_part_id );
    if ( is_wp_error( $context ) ) {
        return $context;
    }
    $validated = \FlavorAgent\LLM\TemplatePartPrompt::validate_operations_for_apply( $operations, $context );
    if ( [] === $validated['operations'] || count( $validated['operations'] ) !== count( $operations ) ) {
        return new \WP_Error( 'flavor_agent_apply_operations_invalid', 'One or more template-part operations failed validation against the current execution contract.', [ 'status' => 400, 'validationReasons' => $validated['reasons'] ] );
    }

    $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
    $cap     = max( 1, (int) apply_filters( self::PENDING_CAP_FILTER, self::DEFAULT_PENDING_CAP ) );
    if ( ActivityRepository::count_active_pending_external_applies( $user_id ) >= $cap ) {
        return new \WP_Error( 'flavor_agent_apply_queue_full', sprintf( 'You already have %d pending external applies awaiting review.', $cap ), [ 'status' => 429 ] );
    }

    $timestamp         = gmdate( 'c' );
    $ttl               = max( 60, (int) apply_filters( self::PENDING_TTL_FILTER, defined( 'DAY_IN_SECONDS' ) ? \DAY_IN_SECONDS : 86400 ) );
    $expires_at        = gmdate( 'c', time() + $ttl );
    $scope_key         = 'template_part:' . $template_part_id;
    $request_reference = sanitize_text_field( (string) ( $input['requestReference'] ?? '' ) );

    $created = ActivityRepository::create( [
        'type'            => 'apply_template_part_suggestion',
        'surface'         => 'template-part',
        'target'          => [ 'templatePartId' => $template_part_id, 'slug' => sanitize_key( (string) ( $scope['slug'] ?? '' ) ), 'area' => sanitize_key( (string) ( $scope['area'] ?? '' ) ) ],
        'suggestion'      => sanitize_text_field( (string) ( self::normalize_map( $input['suggestion'] ?? [] )['label'] ?? 'External template-part apply request' ) ),
        'before'          => [],
        'after'           => [],
        'executionResult' => 'pending',
        'undo'            => [ 'status' => 'not_applicable' ],
        'timestamp'       => $timestamp,
        'request'         => [
            'prompt'      => $prompt,
            'reference'   => '' !== $request_reference ? $request_reference : 'external-apply:' . $scope_key,
            'requestMeta' => [ 'ability' => 'flavor-agent/request-template-part-apply', 'executionTransport' => 'wp-abilities', 'route' => 'wp-abilities:flavor-agent/request-template-part-apply' ],
            'apply'       => [
                'status'           => 'pending',
                'requestedBy'      => $user_id,
                'requestedAt'      => $timestamp,
                'expiresAt'        => $expires_at,
                'operations'       => $validated['operations'],
                'signatures'       => [ 'resolvedContextSignature' => $provided_resolved, 'reviewContextSignature' => $provided_review, 'baselineContentHash' => $baseline ],
                'requestReference' => $request_reference,
            ],
        ],
        'document'        => [ 'scopeKey' => $scope_key, 'postType' => 'wp_template_part', 'entityId' => $template_part_id, 'entityKind' => 'templatePart', 'entityName' => 'templatePart' ],
    ] );
    if ( is_wp_error( $created ) ) {
        return $created;
    }

    return [ 'activityId' => (string) ( $created['id'] ?? '' ), 'status' => 'pending', 'expiresAt' => $expires_at, 'requestReference' => $request_reference ];
}
```

Confirm `TemplateAbilities::recommend_template_part` accepts `templatePartRef` + `resolveSignatureOnly` (TemplateAbilities.php:~161) and returns the two signature keys; adjust the probe input keys to match its actual signature.

- [ ] **Step 4: Run; expect PASS** (add the happy-path test asserting the created row shape).

Run: `vendor/bin/phpunit tests/phpunit/ExternalApplyLifecycleTest.php`

- [ ] **Step 5: Commit**

```bash
git add inc/Abilities/ApplyAbilities.php tests/phpunit/ExternalApplyLifecycleTest.php
git commit -m "feat: add request-template-part-apply handler with two freshness gates"
```

---

### Task 9: `RequestTemplatePartApplyAbility` + registration + undo surface

**Files:**
- Create: `inc/AI/Abilities/RequestTemplatePartApplyAbility.php`
- Modify: `inc/Abilities/Registration.php` (register the ability; bump count 30→31; schema/meta; dedicated MCP roster)
- Test: `tests/phpunit/RegistrationSchemaTest.php`, `tests/phpunit/AbilitySchemaContractTest.php`, `tests/phpunit/MCPServerBootstrapTest.php`

**Interfaces:**
- Consumes: `ApplyAbilities::request_template_part_apply` (Task 8), `Registration::external_apply_meta`.
- Produces: ability `flavor-agent/request-template-part-apply`, `edit_theme_options`, `meta.mcp.public = false`, dedicated-server roster only.

- [ ] **Step 1: Apply Review revision R4** to `inc/Abilities/Registration.php` (shapes verified at lines 190–419): add the `external_apply_ability_classes()` entry; add `request-template-part-apply` to the **`external_apply_meta()` match** (`destructive:false`, else it defaults to `readonly:true`); add it to the **`external_apply_output_schema()` match** (NOT reusable as-is — the match keys on `$ability_id`, so the default open object would apply); and add a dedicated `template_part_apply_input_schema()` + `structural_operation_schema()`. Also locate the dedicated-MCP roster array and the guarded ability-count string (`30`).

- [ ] **Step 2: Write failing tests** (mirror the existing `request-style-apply` rows in each suite): assert the ability registers, schema is valid, count is 31, and it is on the dedicated server but not `meta.mcp.public`.

```php
// RegistrationSchemaTest.php
public function test_request_template_part_apply_is_registered_with_valid_schema(): void {
    $abilities = $this->registered_ability_names();
    $this->assertContains( 'flavor-agent/request-template-part-apply', $abilities );
}
```

- [ ] **Step 3: Run; expect FAIL.** Run: `vendor/bin/phpunit tests/phpunit/RegistrationSchemaTest.php`

- [ ] **Step 4: Create the ability class** (verbatim mirror of `RequestStyleApplyAbility`, swapping the delegate + schema source):

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use WordPress\AI\Abstracts\Abstract_Ability;

final class RequestTemplatePartApplyAbility extends Abstract_Ability {

    public const ABILITY_NAME = 'flavor-agent/request-template-part-apply';

    public function input_schema(): array {
        return Registration::template_part_apply_input_schema( self::ABILITY_NAME );
    }

    public function output_schema(): array {
        return Registration::external_apply_output_schema( self::ABILITY_NAME );
    }

    public function execute_callback( mixed $input ): mixed {
        return ApplyAbilities::request_template_part_apply( $input );
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

- [ ] **Step 5: Register it** in `inc/Abilities/Registration.php` alongside `RequestStyleApplyAbility` (same feature-gate + dedicated-server roster array), add `template_part_apply_input_schema`, and bump the guarded count string `30` → `31` (and its category tally for `apply`). Update the same count in any `check-doc-freshness.sh` pattern.

- [ ] **Step 6: Run; expect PASS**

Run: `vendor/bin/phpunit tests/phpunit/RegistrationSchemaTest.php tests/phpunit/AbilitySchemaContractTest.php` (run each file separately) and `vendor/bin/phpunit tests/phpunit/MCPServerBootstrapTest.php`

- [ ] **Step 7: Commit**

```bash
git add inc/AI/Abilities/RequestTemplatePartApplyAbility.php inc/Abilities/Registration.php tests/phpunit/RegistrationSchemaTest.php tests/phpunit/AbilitySchemaContractTest.php tests/phpunit/MCPServerBootstrapTest.php
git commit -m "feat: register request-template-part-apply ability (30 -> 31)"
```

---

## Task Group C — Admin projection & docs

### Task 10: Template-part structural-operation summary in the admin log

**Files:**
- Modify: `src/admin/activity-log-utils.js` (add a structural-operation summary + target label branch for `surface === 'template-part'`)
- Test: `src/admin/__tests__/activity-log-utils.test.js`

**Interfaces:**
- Consumes: `getGovernanceDetails(entry)` (activity-log-utils.js:~2311), `getOperationsForGovernance`, `formatStyleOperationSummary`.
- Produces: `formatStructuralOperationSummary( operation )` and a surface branch so `proposedOperations`/`executedOperations` use it for `template-part`; `getGovernanceTargetLabel` returns a part label for `template-part`.

- [ ] **Step 1: Write the failing test**

```js
test( 'formats template-part structural operations as readable summaries', () => {
    expect(
        formatStructuralOperationSummary( {
            type: 'remove_block',
            targetPath: [ 0, 1 ],
            expectedBlockName: 'core/navigation',
        } )
    ).toBe( 'Remove block · core/navigation · [0, 1]' );
    expect(
        formatStructuralOperationSummary( {
            type: 'insert_pattern',
            patternName: 'twentytwentyfive/header',
            placement: 'before_block_path',
            targetPath: [ 0 ],
        } )
    ).toBe( 'Insert pattern · twentytwentyfive/header · before [0]' );
} );
```

- [ ] **Step 2: Run; expect FAIL.** Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js`

- [ ] **Step 3: Implement** `formatStructuralOperationSummary` and branch `getGovernanceDetails` so `proposedOperations`/`executedOperations` map through it when `entry.surface === 'template-part'`; export the helper; add a `template-part` arm to `getGovernanceTargetLabel` returning the part slug/area. Keep style behavior unchanged.

- [ ] **Step 4: Run; expect PASS.** Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js`

- [ ] **Step 5: Lint + commit**

```bash
npm run lint:js -- --fix src/admin/activity-log-utils.js
git add src/admin/activity-log-utils.js src/admin/__tests__/activity-log-utils.test.js
git commit -m "feat: template-part structural-operation summary in AI Activity governance evidence"
```

---

### Task 11: Docs + guarded strings

**Files:**
- Modify: `docs/reference/abilities-and-routes.md`, `docs/reference/governance-layer.md`, `docs/reference/activity-state-machine.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/SOURCE_OF_TRUTH.md`, `STATUS.md`, `docs/reference/current-open-work.md`, `CLAUDE.md`, `.github/copilot-instructions.md`
- (CRLF caution: several `docs/*.md` are mixed CRLF — restore HEAD then re-apply with `perl -i -pe` substitution if `git diff --check` flags CRs, per the repo gotcha.)

- [ ] **Step 1:** Add the new ability row + undo-surface note + lifecycle note to `abilities-and-routes.md`; bump every guarded `30` ability-count string to `31` (search: `rg -n "\b30 abilit"`), and update the `apply` category tally.
- [ ] **Step 2:** Update `governance-layer.md` Surface Coverage (external template-part apply now governed), `activity-state-machine.md` (template-part reuses the pre-apply section), `FEATURE_SURFACE_MATRIX.md`, `SOURCE_OF_TRUTH.md`, `STATUS.md`. In `current-open-work.md`, move the template-part executor from open to shipped and keep the **page-level template** executor open.
- [ ] **Step 3:** Update the byte-parity `30 abilities across … categories` string in both `CLAUDE.md` and `.github/copilot-instructions.md`.
- [ ] **Step 4: Verify docs.** Run: `npm run check:docs` → PASS. Run: `git diff --check` (no CR/whitespace errors).
- [ ] **Step 5: Commit**

```bash
git add docs/ CLAUDE.md .github/copilot-instructions.md STATUS.md
git commit -m "docs: record governed template-part external apply (abilities 30 -> 31)"
```

---

## Task Group D — Verification

### Task 12: Full cross-surface gate

- [ ] **Step 1: Targeted suites** (single-file each):

```bash
vendor/bin/phpunit tests/phpunit/BlockTreeMutatorTest.php
vendor/bin/phpunit tests/phpunit/TemplatePartApplyExecutorTest.php
vendor/bin/phpunit tests/phpunit/TemplatePartPromptApplyValidationTest.php
vendor/bin/phpunit tests/phpunit/ExternalApplyLifecycleTest.php
vendor/bin/phpunit tests/phpunit/StyleApplyExecutorTest.php
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js
```

Expected: all PASS.

- [ ] **Step 2: Aggregate gate (cross-surface: ability contracts + activity subsystem + new executor):**

```bash
node scripts/verify.js --skip-e2e
```

Inspect `output/verify/summary.json`: `status: pass`. If `lint-plugin` is unavailable, re-run with `--skip=lint-plugin` and record the waiver.

- [ ] **Step 3: Docs freshness:** `npm run check:docs` → PASS.

- [ ] **Step 4:** E2E WP70 is manual-only (coverage topology). Record in `STATUS.md` that template-part external-apply lifecycle guarantees are covered by PHPUnit; add a thin admin decision Playground spec only if the seeded flow can demonstrate it honestly, else note an explicit waiver.

- [ ] **Step 5: Final commit** (if any verification fixups were needed):

```bash
git add -A
git commit -m "test: verify template-part external-apply executor slice"
```

---

## Self-Review

**Spec coverage:** request ability (Task 8/9) · executor apply/undo with content-hash + expectedTarget drift + atomic mutation (Tasks 2,4,5,6) · pattern resolution incl. synced-out-of-scope fail-closed (Task 5) · theme-part materialization (Task 5) · dispatch seam + style parity (Task 1,7) · attestation style-only branch (Task 7) · admin structural summary (Task 10) · docs + count bump (Task 11) · gates (Task 12). All spec sections map to a task.

**Grounded during review (see Review revisions R1–R9):** operation key shape (`targetPath`/`expectedBlockName`/`patternName`/`placement`/`expectedTarget`) matches `validate_operations`; the four `build_*_lookup(array $context)` signatures and `build_expected_target` keys (`name`/`childCount`) confirmed; `PendingApplyDecision` reads a hoisted top-level `$entry['apply']` so Task 8's `request.apply` storage is correct; `external_apply_meta`/`external_apply_output_schema` key on `$ability_id` and need explicit template-part arms; the bootstrap stubs `get_block_template`/`WP_Block_Patterns_Registry`/`parse_blocks`, so the executor uses real stubbed WP calls (no filter seams).

**Open confirmations for the implementer (resolve at task start, not blockers):**
- `TemplateAbilities::recommend_template_part` exact input keys for the `resolveSignatureOnly` probe (Task 8) — confirm `templatePartRef` vs `templatePartContext` shape, that it returns both signatures, and that it writes no diagnostic row in signature-only mode.
- `ServerCollector` accessors: `for_template_part` plus the new `resolve_template_part_for_apply` passthrough; and whether `bootstrap.php` stubs `wp_insert_post`/`wp_update_post`/`serialize_blocks` for `wp_template_part` (extend per R5 if not).
- The dedicated-MCP roster array location and the guarded ability-count string (`30`→`31`).

**Placeholder scan:** no "TBD/handle errors/similar to" — the one `markTestIncomplete` (Task 7 Step 1) is explicitly replaced in the same step with a seeded-row drive.

**Type consistency:** `execute`/`undo`/`resolve_baseline` signatures match the `ExternalApplyExecutor` interface across StyleApplyExecutor and TemplatePartApplyExecutor; `before/after` use `{content, operations?}` for template-part consistently in Tasks 5/6/8; baseline stored as `baselineContentHash` and read in `PendingApplyDecision` via the `?? baselineConfigHash` fallback (Task 1 Step 5).
