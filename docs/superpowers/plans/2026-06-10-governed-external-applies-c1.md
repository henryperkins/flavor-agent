# Governed External Applies (C1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an external agent that received a `recommend-style` result request a style apply through four new feature-gated Abilities; a `manage_options` + `edit_theme_options` human approves it in `Settings > AI Activity`; the server executes it with double freshness revalidation, records it as a normal attributed/undoable activity row, and supports server-side undo.

**Architecture:** A new `FlavorAgent\Apply\` namespace hosts a server-side style executor (porting `src/utils/style-operations.js` apply/undo semantics) and the approval decision service. The pending lifecycle lives in the existing one-row `flavor_agent_activity` table: `request.apply` carries the logical lifecycle (hydrated as top-level `entry.apply`), and `execution_result` mirrors it for SQL filtering (`pending`/`rejected`/`expired`/`failed`, becoming `applied` on approved execution). Four new abilities (`request-style-apply`, `get-activity`, `list-activity`, `undo-activity`) register behind the recommendation feature gate and join the dedicated MCP server roster (7 → 11 tools, 25 → 29 abilities). Approval is REST-only (`POST /flavor-agent/v1/activity/{id}/decision`) — never an ability.

**Tech Stack:** PHP 8.2 (PSR-4 `FlavorAgent\`, WPCS style: tabs, snake_case, yoda), PHPUnit with the stub-based bootstrap at `tests/phpunit/bootstrap.php` (no live WordPress), `@wordpress/dataviews` admin app, Jest with `src/test-utils/wp-components.js` mocks.

**Spec:** `docs/superpowers/specs/2026-06-10-governed-external-applies-c1-design.md`

---

## Context for the implementer (read once, before Task 1)

Facts about this codebase you need and might not guess:

1. **PHPUnit runs with NO WordPress.** `tests/phpunit/bootstrap.php` stubs everything: `WordPressTestState` (static container) holds `$options`, `$capabilities` (map of capability → bool), `$posts` (id → `WP_Post` stub), `$db_tables` (a fake `wpdb` stores rows per table and regex-parses a limited set of SQL shapes), `$registered_abilities`, `$rest_routes`. `WordPressTestState::reset()` runs in every `setUp()`. Permission stubs read `WordPressTestState::$capabilities['edit_theme_options'] = true`.
2. **The fake `wpdb`** (`bootstrap.php` ≈ lines 1077–1744) parses only specific `WHERE` shapes in `get_results()` (`document_scope_key = '…'`, `surface = '…'`, `entity_type/entity_ref` pairs, `user_id = N`, `activity_type = '…'` when the query has no `AS` alias). Any new SQL the Repository emits must either reuse those shapes or get a new regex branch in the fake. The fake also reimplements the admin status CASE in `resolve_activity_admin_status()` — extend it when you change the real CASE.
3. **`execution_result` column values today:** `applied` (default), `review` (request diagnostics), `diagnostic` (outcome rows). No code anywhere writes `pending`/`rejected`/`expired`/`failed` — those are free for the new lifecycle, and `execution_result IN ('pending','rejected','expired','failed')` unambiguously means "non-executed external apply row".
4. **Activity entries** are arrays normalized by `Serializer::normalize_entry()`; `Repository::create()` requires `surface` + `document.scopeKey`. Editor style rows use types `apply_global_styles_suggestion` / `apply_style_book_suggestion`, target `{globalStylesId}` (+ `blockName`, `blockTitle` for style-book), snapshots `before.userConfig` / `after.userConfig` (+ `after.operations`). Style Book snapshots are trimmed to `{styles:{blocks:{<blockName>: branch}}}` (`src/store/activity-undo.js:391`). External rows reuse the SAME types so executed rows are indistinguishable from editor rows.
5. **Scope keys** are canonical server-side: `global_styles:<id>` and `style_book:<id>:<blockName>` (`StyleAbilities::canonical_scope_key()`). `Activity\Permissions::parse_scope_key()` splits on the FIRST `:` so these resolve to post types `global_styles` / `style_book`, both in `THEME_POST_TYPES` → `edit_theme_options`.
6. **Signatures:** `StyleAbilities::recommend_style()` with `resolveSignatureOnly: true` is side-effect-free and returns `reviewContextSignature` + `resolvedContextSignature` computed from the caller's `scope`/`styleContext`/`prompt` plus live server state (theme tokens via `ServerCollector::for_tokens()`, supported style paths, block manifest, docs-grounding fingerprint). Same input + unchanged server state ⇒ identical signature. That is the request-time freshness gate. Entity-content drift is covered separately by comparing the claimed `styleContext.currentConfig` against the live `wp_global_styles` CPT.
7. **Operation vocabulary:** `set_styles` (global-styles only), `set_block_styles` (style-book only, `blockName` must equal scope block), `set_theme_variation` (global-styles only, at most one, ordered first). `StylePrompt::validate_operations()` (private; public seam `validate_operations_for_tests`) validates against a context of `{scope:{surface, blockName}, styleContext:{supportedStylePaths, availableVariations, themeTokens, styleBookTarget}}` and returns `{operations: <canonicalized>, reasons: []}`. `StyleContrastValidator::evaluate($operations, $context)` (WCAG AA 4.5) reads `$context['styleContext']['themeTokens']` and `['mergedConfig']` and returns `{passed, kind, reason, ratio}`.
8. **The user Global Styles entity** is the `wp_global_styles` CPT; `globalStylesId` from the editor is its numeric post ID. `post_content` is JSON `{version, isGlobalStylesUserThemeJSON, settings, styles}`. The test bootstrap has `get_post()` + `WordPressTestState::$posts` but **no `wp_update_post()`** — Task 4 adds the stub.
9. **Ordered undo** (`Repository::is_ordered_undo_eligible()`) walks rows for the same `entity_type`/`entity_ref` newest→oldest, skipping review-only rows; any newer non-undone row blocks. The admin SQL mirror is `get_admin_sql_newer_active_exists()` + `get_admin_sql_status_case()`. All three (PHP walker, SQL, fake-wpdb simulation) must learn to skip non-executed rows.
10. **`npm run check:docs`** (`scripts/check-doc-freshness.sh`) hard-fails on guarded strings. Two must change in lockstep with code: `` `inc/Abilities/Registration.php` defines 25 ability contracts `` (in `docs/reference/abilities-and-routes.md`, guard line ≈215) and `25 abilities across block, pattern, template, navigation, docs, infra, content, and style categories` (byte-identical in `CLAUDE.md` AND `.github/copilot-instructions.md`, guard line ≈277). The `18 always-on` string is untouched (all four new abilities are feature-gated).
11. **Style rows in the editor** persist activity via the same REST `POST /flavor-agent/v1/activity`; the `merge_existing_entry()` retry path and one-way `available → undone/failed` rules in `update_undo_status()` are already enforced and must stay untouched.
12. **PHPCS:** every direct `$wpdb` call needs the repo's standard `// phpcs:ignore WordPress.DB...` comment (copy the neighboring pattern). Run `composer lint:php` before each commit if in doubt.

## File structure

**New files**

| Path | Responsibility |
| --- | --- |
| `inc/Apply/StyleApplyExecutor.php` | Server-side style apply/undo: entity resolve, live validation context, operation application, contrast, CPT write, snapshot shaping, drift checks |
| `inc/Apply/PendingApplyDecision.php` | Approve/reject service: lazy expiry, baseline freshness recheck, executor invocation, one-row transitions |
| `inc/Abilities/ApplyAbilities.php` | Ability handlers: `request_style_apply`, `get_activity`, `list_activity`, `undo_activity` |
| `inc/AI/Abilities/RequestStyleApplyAbility.php` | Ability class `flavor-agent/request-style-apply` (`edit_theme_options`) |
| `inc/AI/Abilities/GetActivityAbility.php` | Ability class `flavor-agent/get-activity` (contextual) |
| `inc/AI/Abilities/ListActivityAbility.php` | Ability class `flavor-agent/list-activity` (contextual scoped) |
| `inc/AI/Abilities/UndoActivityAbility.php` | Ability class `flavor-agent/undo-activity` (contextual, destructive) |
| `tests/phpunit/ExternalApplyLifecycleTest.php` | Repository/Serializer pending lifecycle, transitions, expiry, caps, ordered-undo + admin-status exclusion |
| `tests/phpunit/StyleApplyExecutorTest.php` | Executor apply/undo/drift/contrast/branch/variation coverage |
| `tests/phpunit/ApplyAbilitiesTest.php` | The four ability handlers + `PendingApplyDecision` |
| `tests/e2e/flavor-agent.approvals.spec.js` | Playground browser coverage of the admin approval surface |

**Modified files**

| Path | Change |
| --- | --- |
| `inc/Activity/Serializer.php` | Hydrate `request.apply` as top-level `entry.apply` |
| `inc/Activity/Repository.php` | `transition_external_apply`, `maybe_expire_pending_apply`, `expire_overdue_pending_applies` (+ call in `prune()`), `count_active_pending_external_applies`, `can_perform_ordered_undo`, `is_non_executed_apply_row` skip in ordered undo, SQL status CASE + newer-active exclusion, summary keys, status labels |
| `inc/Activity/Permissions.php` | `can_access_context_values()`, `can_decide_activity_request()` |
| `inc/LLM/StylePrompt.php` | Public `validate_operations_for_apply()` seam |
| `inc/Abilities/StyleAbilities.php` | Public `supported_style_paths_for_block()` + `canonical_scope_key_for()` seams |
| `inc/Abilities/Registration.php` | `external_apply_ability_classes()`, `register_external_apply_abilities()`, schemas + meta, `style_operation_schema()` extraction |
| `inc/AI/FeatureBootstrap.php` | Register external-apply abilities behind the feature gate |
| `inc/MCP/ServerBootstrap.php` | Add the four abilities to the dedicated server roster |
| `inc/REST/Agent_Controller.php` | `/activity/{id}/decision` route + handler |
| `inc/Abilities/InfraAbilities.php` | Advertise the four abilities in `check-status` `availableAbilities` |
| `inc/Admin/ActivityPage.php` | Localize `canApproveStyleApplies` |
| `src/admin/activity-log-utils.js` | Status vocabulary (`pending`/`rejected`/`expired`), `entry.apply` passthrough, `isPendingExternalApply`, `getExternalApplyDetails`, `buildDecisionRequest` |
| `src/admin/activity-log.js` | Pending summary card, status filter options, `PendingApplyDecisionSection` in the details sidebar, refresh-after-decision |
| `tests/phpunit/bootstrap.php` | `wp_update_post()` stub + `$updated_posts`, fake-wpdb `execution_result` filter, fake admin-status simulation for non-executed rows |
| `tests/phpunit/{ActivitySerializerTest,AgentRoutesTest,MCPServerBootstrapTest,RegistrationTest,AbilitySchemaContractTest,InfraAbilitiesTest}.php` | Extend |
| `src/admin/__tests__/{activity-log-utils.test.js,activity-log.test.js}` | Extend |
| Docs: `docs/reference/abilities-and-routes.md`, `docs/reference/governance-layer.md`, `docs/reference/activity-state-machine.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/SOURCE_OF_TRUTH.md`, `STATUS.md`, `CLAUDE.md`, `.github/copilot-instructions.md`, `scripts/check-doc-freshness.sh` | Lifecycle + ability contract documentation, guarded-string updates |

## Shared contract reference (single source of truth for all tasks)

**`request.apply` shape** (persisted inside `request_json`, hydrated as top-level `entry.apply`):

```json
{
  "status": "pending | available | rejected | expired | failed",
  "requestedBy": 7,
  "requestedAt": "2026-06-10T01:00:00+00:00",
  "expiresAt": "2026-06-11T01:00:00+00:00",
  "operations": [ { "type": "set_styles", "path": ["color","text"], "value": "var:preset|color|accent", "valueType": "preset", "presetType": "color", "presetSlug": "accent", "cssVar": "var(--wp--preset--color--accent)", "blockName": "" } ],
  "signatures": {
    "resolvedContextSignature": "<sha256 from recommend-style>",
    "reviewContextSignature": "<sha256 from recommend-style>",
    "baselineConfigHash": "<sha256 of comparable live user config at request time>"
  },
  "requestReference": "agent-supplied opaque ref",
  "decidedBy": 3, "decidedAt": "…", "decisionNote": "…",
  "failureCode": "…", "failureMessage": "…", "executedAt": "…"
}
```

**`execution_result` mirror:** `pending` → `rejected` | `expired` | `failed` (pre-execution) | `applied` (approved + executed; public `apply.status` is then `available`).

**Pre-execution rows:** `undo.status: not_applicable`, `before: {}`, `after: {}`. Proposed operations live ONLY in `request.apply.operations`. On approved execution the row gains editor-shaped `before`/`after`/`target`, `undo.status: available`, `execution_result: applied`.

**Error codes** (all `WP_Error`, `status` in data): `flavor_agent_apply_stale` 409 · `flavor_agent_apply_queue_full` 429 · `flavor_agent_apply_operations_invalid` 400 (request) / 409 (approval) · `flavor_agent_apply_target_unavailable` 404/409 · `flavor_agent_apply_contrast_failed` 409 · `flavor_agent_apply_write_failed` 500 · `flavor_agent_apply_invalid_transition` 409 · `flavor_agent_apply_not_pending` 409 · `flavor_agent_apply_expired` 410 · `flavor_agent_apply_invalid_decision` 400 · `flavor_agent_undo_drift` 409 · `flavor_agent_undo_snapshot_unsupported` 409 · `flavor_agent_undo_surface_unsupported` 400 · `flavor_agent_activity_not_undoable` 409.

**Filters:** `flavor_agent_external_apply_pending_ttl` (default `DAY_IN_SECONDS`) · `flavor_agent_external_apply_pending_cap` (default 10) · `flavor_agent_external_apply_theme_variations` (test seam over `WP_Theme_JSON_Resolver::get_style_variations()`).

**Ability IDs:** `flavor-agent/request-style-apply` · `flavor-agent/get-activity` · `flavor-agent/list-activity` · `flavor-agent/undo-activity`. All category `flavor-agent`, feature-gated, on the dedicated MCP roster, **never** `meta.mcp.public`.

---

### Task 1: Hydrate `request.apply` as top-level `entry.apply`

**Files:**
- Modify: `inc/Activity/Serializer.php` (inside `hydrate_row()`, after the `$hydrated` array literal)
- Test: `tests/phpunit/ActivitySerializerTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/phpunit/ActivitySerializerTest.php` (inside the existing class):

```php
	public function test_hydrate_row_exposes_request_apply_as_top_level_apply(): void {
		$apply = [
			'status'           => 'pending',
			'requestedBy'      => 7,
			'requestedAt'      => '2026-06-10T01:00:00+00:00',
			'expiresAt'        => '2026-06-11T01:00:00+00:00',
			'operations'       => [
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'text' ],
					'value' => 'var:preset|color|accent',
				],
			],
			'signatures'       => [
				'resolvedContextSignature' => str_repeat( 'a', 64 ),
				'reviewContextSignature'   => str_repeat( 'b', 64 ),
				'baselineConfigHash'       => str_repeat( 'c', 64 ),
			],
			'requestReference' => 'agent-req-1',
		];
		$row   = [
			'activity_id'      => 'apply-row-1',
			'surface'          => 'global-styles',
			'activity_type'    => 'apply_global_styles_suggestion',
			'request_json'     => (string) wp_json_encode(
				[
					'prompt' => 'darker',
					'apply'  => $apply,
				]
			),
			'execution_result' => 'pending',
			'undo_state'       => (string) wp_json_encode( [ 'status' => 'not_applicable' ] ),
			'created_at'       => '2026-06-10 01:00:00',
		];

		$entry = Serializer::hydrate_row( $row );

		$this->assertSame( $apply, $entry['apply'] );
		$this->assertSame( 'pending', $entry['executionResult'] );
		$this->assertSame( 'not_applicable', $entry['undo']['status'] );
	}

	public function test_hydrate_row_omits_apply_when_request_has_none(): void {
		$entry = Serializer::hydrate_row(
			[
				'activity_id'  => 'plain-row-1',
				'surface'      => 'global-styles',
				'request_json' => (string) wp_json_encode( [ 'prompt' => 'darker' ] ),
				'created_at'   => '2026-06-10 01:00:00',
			]
		);

		$this->assertArrayNotHasKey( 'apply', $entry );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter ActivitySerializerTest`
Expected: FAIL — `test_hydrate_row_exposes_request_apply_as_top_level_apply` with "Failed asserting that an array has the key 'apply'" (or undefined index).

- [ ] **Step 3: Implement the hydration**

In `inc/Activity/Serializer.php`, inside `hydrate_row()`, immediately after the `$hydrated = [ … ];` array assignment and BEFORE the `recommendation_outcome` diagnostic block, add:

```php
		$request_apply = is_array( $hydrated['request']['apply'] ?? null )
			? $hydrated['request']['apply']
			: [];

		if ( [] !== $request_apply ) {
			$hydrated['apply'] = $request_apply;
		}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ActivitySerializerTest`
Expected: PASS (all existing + 2 new).

- [ ] **Step 5: Commit**

```bash
git add inc/Activity/Serializer.php tests/phpunit/ActivitySerializerTest.php
git commit -m "Hydrate external-apply lifecycle as top-level entry.apply"
```

---

### Task 2: Pending-row lifecycle in the Repository

**Files:**
- Modify: `inc/Activity/Repository.php`
- Modify: `tests/phpunit/bootstrap.php` (fake-wpdb `execution_result` filter)
- Create: `tests/phpunit/ExternalApplyLifecycleTest.php`

- [ ] **Step 1: Add the fake-wpdb `execution_result` filter (test infrastructure)**

In `tests/phpunit/bootstrap.php`, inside the fake `wpdb::get_results()`, locate the alias-guarded block that starts `if (! preg_match('/\bFROM\s+\S+\s+AS\s+/i', $query)) {` (it contains the `activity_type = '…'` parsing). Inside that same block, after the `activity_type <> '…'` branch, add:

```php
					if (preg_match("/execution_result\s*=\s*'([^']*)'/i", $query, $matches)) {
						$execution_result = stripslashes((string) ($matches[1] ?? ''));
						$rows             = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => (string) ($row['execution_result'] ?? '') === $execution_result
							)
						);
					}
```

(The alias guard matters: the admin status CASE contains `execution_result IN (…)` fragments inside aliased queries; keeping the `=` parser inside the non-aliased block prevents misfiltering. All new Repository queries in this task are non-aliased.)

- [ ] **Step 2: Write the failing lifecycle tests**

Create `tests/phpunit/ExternalApplyLifecycleTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ExternalApplyLifecycleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		Repository::install();
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function create_pending_entry( array $overrides = [] ): array {
		$entry = array_replace_recursive(
			[
				'type'            => 'apply_global_styles_suggestion',
				'surface'         => 'global-styles',
				'target'          => [ 'globalStylesId' => '17' ],
				'suggestion'      => 'Darken the palette',
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'request'         => [
					'prompt'    => 'darker',
					'reference' => 'external-apply:global_styles:17',
					'apply'     => [
						'status'           => 'pending',
						'requestedBy'      => 7,
						'requestedAt'      => gmdate( 'c' ),
						'expiresAt'        => gmdate( 'c', time() + 3600 ),
						'operations'       => [
							[
								'type'       => 'set_styles',
								'path'       => [ 'color', 'text' ],
								'value'      => 'var:preset|color|accent',
								'valueType'  => 'preset',
								'presetType' => 'color',
								'presetSlug' => 'accent',
								'cssVar'     => 'var(--wp--preset--color--accent)',
							],
						],
						'signatures'       => [
							'resolvedContextSignature' => str_repeat( 'a', 64 ),
							'reviewContextSignature'   => str_repeat( 'b', 64 ),
							'baselineConfigHash'       => str_repeat( 'c', 64 ),
						],
						'requestReference' => 'agent-req-1',
					],
				],
				'document'        => [
					'scopeKey' => 'global_styles:17',
					'postType' => 'global_styles',
					'entityId' => '17',
				],
			],
			$overrides
		);

		$created = Repository::create( $entry );
		$this->assertIsArray( $created );

		return $created;
	}

	public function test_pending_row_round_trips_with_apply_lifecycle(): void {
		$created = $this->create_pending_entry();

		$this->assertSame( 'pending', $created['executionResult'] );
		$this->assertSame( 'pending', $created['apply']['status'] );
		$this->assertSame( 'not_applicable', $created['undo']['status'] );
		$this->assertSame( [], $created['before'] );
		$this->assertSame( [], $created['after'] );
		$this->assertSame( 7, $created['apply']['requestedBy'] );
	}

	public function test_transition_to_available_writes_snapshots_and_unlocks_undo(): void {
		$created = $this->create_pending_entry();

		$updated = Repository::transition_external_apply(
			(string) $created['id'],
			[
				'applyStatus'  => 'available',
				'decidedBy'    => 3,
				'decidedAt'    => '2026-06-10T02:00:00+00:00',
				'decisionNote' => 'Looks safe',
				'executedAt'   => '2026-06-10T02:00:01+00:00',
				'before'       => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
				'after'        => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
					'operations' => $created['apply']['operations'],
				],
				'target'       => [ 'globalStylesId' => '17' ],
			]
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'available', $updated['apply']['status'] );
		$this->assertSame( 'applied', $updated['executionResult'] );
		$this->assertSame( 'available', $updated['undo']['status'] );
		$this->assertTrue( $updated['undo']['canUndo'] );
		$this->assertSame( 3, $updated['apply']['decidedBy'] );
		$this->assertSame( 'Looks safe', $updated['apply']['decisionNote'] );
		$this->assertSame( '2026-06-10T02:00:01+00:00', $updated['apply']['executedAt'] );
		$this->assertSame(
			[ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			$updated['after']['userConfig']['styles']
		);
	}

	public function test_transition_to_rejected_keeps_snapshots_empty_and_undo_not_applicable(): void {
		$created = $this->create_pending_entry();

		$updated = Repository::transition_external_apply(
			(string) $created['id'],
			[
				'applyStatus'  => 'rejected',
				'decidedBy'    => 3,
				'decidedAt'    => '2026-06-10T02:00:00+00:00',
				'decisionNote' => 'Not now',
			]
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'rejected', $updated['apply']['status'] );
		$this->assertSame( 'rejected', $updated['executionResult'] );
		$this->assertSame( 'not_applicable', $updated['undo']['status'] );
		$this->assertSame( [], $updated['before'] );
		$this->assertSame( [], $updated['after'] );
	}

	public function test_transition_to_failed_records_failure_metadata(): void {
		$created = $this->create_pending_entry();

		$updated = Repository::transition_external_apply(
			(string) $created['id'],
			[
				'applyStatus'    => 'failed',
				'decidedBy'      => 3,
				'decidedAt'      => '2026-06-10T02:00:00+00:00',
				'failureCode'    => 'flavor_agent_apply_stale',
				'failureMessage' => 'The Global Styles entity changed after this apply was requested.',
			]
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'failed', $updated['apply']['status'] );
		$this->assertSame( 'failed', $updated['executionResult'] );
		$this->assertSame( 'flavor_agent_apply_stale', $updated['apply']['failureCode'] );
		$this->assertSame( 'not_applicable', $updated['undo']['status'] );
	}

	public function test_transitions_are_one_way_out_of_pending(): void {
		$created = $this->create_pending_entry();
		Repository::transition_external_apply( (string) $created['id'], [ 'applyStatus' => 'rejected' ] );

		$second = Repository::transition_external_apply( (string) $created['id'], [ 'applyStatus' => 'available' ] );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'flavor_agent_apply_invalid_transition', $second->get_error_code() );
	}

	public function test_transition_rejects_unknown_target_status(): void {
		$created = $this->create_pending_entry();

		$result = Repository::transition_external_apply( (string) $created['id'], [ 'applyStatus' => 'pending' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_invalid_transition', $result->get_error_code() );
	}

	public function test_maybe_expire_pending_apply_expires_overdue_rows_and_persists(): void {
		$created = $this->create_pending_entry(
			[ 'request' => [ 'apply' => [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] ] ]
		);

		$expired = Repository::maybe_expire_pending_apply( $created );

		$this->assertSame( 'expired', $expired['apply']['status'] );
		$this->assertSame( 'expired', $expired['executionResult'] );

		$stored = Repository::find( (string) $created['id'] );
		$this->assertSame( 'expired', $stored['apply']['status'] );
	}

	public function test_maybe_expire_pending_apply_leaves_unexpired_rows_untouched(): void {
		$created = $this->create_pending_entry();

		$result = Repository::maybe_expire_pending_apply( $created );

		$this->assertSame( 'pending', $result['apply']['status'] );
	}

	public function test_prune_sweeps_overdue_pending_applies(): void {
		$created = $this->create_pending_entry(
			[ 'request' => [ 'apply' => [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] ] ]
		);

		Repository::prune();

		$stored = Repository::find( (string) $created['id'] );
		$this->assertSame( 'expired', $stored['apply']['status'] );
	}

	public function test_count_active_pending_external_applies_counts_only_unexpired_rows_for_the_user(): void {
		$this->create_pending_entry( [ 'id' => 'mine-active' ] );
		$this->create_pending_entry(
			[
				'id'      => 'mine-overdue',
				'request' => [ 'apply' => [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] ],
			]
		);

		WordPressTestState::$current_user_id = 9;
		$this->create_pending_entry( [ 'id' => 'theirs-active' ] );
		WordPressTestState::$current_user_id = 7;

		$this->assertSame( 1, Repository::count_active_pending_external_applies( 7 ) );

		$overdue = Repository::find( 'mine-overdue' );
		$this->assertSame( 'expired', $overdue['apply']['status'] );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter ExternalApplyLifecycleTest`
Expected: FAIL — `Call to undefined method FlavorAgent\Activity\Repository::transition_external_apply()` (first failing test), and similar for the other new methods.

- [ ] **Step 4: Implement the Repository lifecycle methods**

In `inc/Activity/Repository.php`, add these public methods (place them after `update_undo_status()`):

```php
	/**
	 * One-way transition of an external-apply row out of the pending state.
	 *
	 * @param array<string, mixed> $changes {applyStatus, decidedBy?, decidedAt?, decisionNote?, failureCode?, failureMessage?, executedAt?, before?, after?, target?}
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function transition_external_apply( string $activity_id, array $changes ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return new \WP_Error(
				'flavor_agent_activity_storage_unavailable',
				'Flavor Agent activity storage is unavailable.',
				[ 'status' => 500 ]
			);
		}

		$row = self::find_row( $activity_id );

		if ( ! is_array( $row ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_found',
				'Flavor Agent could not find that activity entry.',
				[ 'status' => 404 ]
			);
		}

		$entry = Serializer::hydrate_row( $row );
		$apply = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];

		if ( 'pending' !== (string) ( $apply['status'] ?? '' ) ) {
			return new \WP_Error(
				'flavor_agent_apply_invalid_transition',
				'Flavor Agent external applies only transition out of the pending state.',
				[ 'status' => 409 ]
			);
		}

		$next_status = (string) ( $changes['applyStatus'] ?? '' );

		if ( ! in_array( $next_status, [ 'available', 'rejected', 'expired', 'failed' ], true ) ) {
			return new \WP_Error(
				'flavor_agent_apply_invalid_transition',
				'Flavor Agent external applies only accept available, rejected, expired, or failed transitions.',
				[ 'status' => 409 ]
			);
		}

		$timestamp       = gmdate( 'c' );
		$apply['status'] = $next_status;

		foreach ( [ 'decidedBy', 'decidedAt', 'decisionNote', 'failureCode', 'failureMessage', 'executedAt' ] as $field ) {
			if ( array_key_exists( $field, $changes ) ) {
				$apply[ $field ] = $changes[ $field ];
			}
		}

		$request          = is_array( $entry['request'] ?? null ) ? $entry['request'] : [];
		$request['apply'] = $apply;
		$update           = [
			'request_json'     => Serializer::encode_json( $request ),
			'execution_result' => 'available' === $next_status ? 'applied' : $next_status,
			'updated_at'       => Serializer::mysql_datetime_from_timestamp( $timestamp ),
		];

		if ( 'available' === $next_status ) {
			$update['before_state'] = Serializer::encode_json( $changes['before'] ?? [] );
			$update['after_state']  = Serializer::encode_json( $changes['after'] ?? [] );
			$update['target_json']  = Serializer::encode_json( $changes['target'] ?? ( $entry['target'] ?? [] ) );
			$update['undo_state']   = Serializer::encode_json(
				Serializer::normalize_undo_for_storage( [ 'status' => 'available' ], $timestamp )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin-owned activity log table must execute immediately.
		$updated = $wpdb->update( self::table_name(), $update, [ 'activity_id' => $activity_id ] );

		if ( false === $updated ) {
			return new \WP_Error(
				'flavor_agent_activity_update_failed',
				'Flavor Agent could not update the activity entry.',
				[ 'status' => 500 ]
			);
		}

		$stored_row = self::find_row( $activity_id );

		if ( is_array( $stored_row ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Refreshes derived admin projection columns for the transitioned row.
			$wpdb->update(
				self::table_name(),
				self::build_admin_projection_from_row( $stored_row ),
				[ 'activity_id' => $activity_id ]
			);
		}

		$stored = self::find( $activity_id );

		if ( is_array( $stored ) ) {
			return $stored;
		}

		return new \WP_Error(
			'flavor_agent_activity_not_found',
			'Flavor Agent could not find that activity entry.',
			[ 'status' => 404 ]
		);
	}

	/**
	 * Lazily expire a hydrated pending external apply that is past its expiresAt.
	 *
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	public static function maybe_expire_pending_apply( array $entry ): array {
		$apply = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];

		if ( 'pending' !== (string) ( $apply['status'] ?? '' ) ) {
			return $entry;
		}

		$expires_at = strtotime( (string) ( $apply['expiresAt'] ?? '' ) );

		if ( false === $expires_at || $expires_at > time() ) {
			return $entry;
		}

		$expired = self::transition_external_apply(
			(string) ( $entry['id'] ?? '' ),
			[ 'applyStatus' => 'expired' ]
		);

		return is_array( $expired ) ? $expired : $entry;
	}

	/**
	 * Sweep every overdue pending external apply to expired. Runs from prune().
	 */
	public static function expire_overdue_pending_applies(): int {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return 0;
		}

		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE execution_result = %s',
			self::table_name(),
			'pending'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Reads pending external-apply rows from the plugin-owned activity table; prepared above.
		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$expired = 0;

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$entry   = Serializer::hydrate_row( $row );
			$updated = self::maybe_expire_pending_apply( $entry );

			if ( 'expired' === (string) ( $updated['apply']['status'] ?? '' ) ) {
				++$expired;
			}
		}

		return $expired;
	}

	/**
	 * Count unexpired pending external applies requested by the given user.
	 */
	public static function count_active_pending_external_applies( int $user_id ): int {
		global $wpdb;

		if ( ! is_object( $wpdb ) || $user_id <= 0 ) {
			return 0;
		}

		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE execution_result = %s AND user_id = %d',
			self::table_name(),
			'pending',
			$user_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Counts the requesting user's pending external applies; prepared above.
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		$count = 0;

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$entry = self::maybe_expire_pending_apply( Serializer::hydrate_row( $row ) );

			if ( 'pending' === (string) ( $entry['apply']['status'] ?? '' ) ) {
				++$count;
			}
		}

		return $count;
	}
```

Then add the sweep to `prune()` — its first line becomes:

```php
	public static function prune(): int {
		self::expire_overdue_pending_applies();

		$retention_days = (int) get_option(
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ExternalApplyLifecycleTest`
Expected: PASS (all 10).
Then run the full suite to catch regressions: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add inc/Activity/Repository.php tests/phpunit/bootstrap.php tests/phpunit/ExternalApplyLifecycleTest.php
git commit -m "Add pending external-apply lifecycle to the activity repository"
```

---

### Task 3: Ordered undo and admin status integration for non-executed rows

Non-executed rows (`execution_result` ∈ pending/rejected/expired/failed) must (a) never block ordered undo of older executed rows, (b) never count as "newer active" in admin status, (c) surface their own lifecycle status in admin reads, summaries, and labels.

**Files:**
- Modify: `inc/Activity/Repository.php`
- Modify: `tests/phpunit/bootstrap.php` (fake admin-status simulation)
- Test: `tests/phpunit/ExternalApplyLifecycleTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/phpunit/ExternalApplyLifecycleTest.php`:

```php
	public function test_non_executed_rows_do_not_block_ordered_undo_of_older_executed_rows(): void {
		$executed = Repository::create(
			[
				'type'       => 'apply_global_styles_suggestion',
				'surface'    => 'global-styles',
				'target'     => [ 'globalStylesId' => '17' ],
				'suggestion' => 'Editor apply',
				'before'     => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
				'after'      => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
				'undo'       => [ 'status' => 'available' ],
				'timestamp'  => '2026-06-10T01:00:00+00:00',
				'document'   => [ 'scopeKey' => 'global_styles:17' ],
			]
		);
		$this->assertIsArray( $executed );

		// A newer pending external apply on the same entity must not block undo.
		$this->create_pending_entry( [ 'timestamp' => '2026-06-10T02:00:00+00:00' ] );

		$this->assertTrue( Repository::can_perform_ordered_undo( (string) $executed['id'] ) );

		$undone = Repository::update_undo_status( (string) $executed['id'], 'undone' );
		$this->assertIsArray( $undone );
		$this->assertSame( 'undone', $undone['undo']['status'] );
	}

	public function test_executed_external_apply_blocks_ordered_undo_like_an_editor_row(): void {
		$executed = Repository::create(
			[
				'type'       => 'apply_global_styles_suggestion',
				'surface'    => 'global-styles',
				'target'     => [ 'globalStylesId' => '17' ],
				'suggestion' => 'Editor apply',
				'before'     => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
				'after'      => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
				'undo'       => [ 'status' => 'available' ],
				'timestamp'  => '2026-06-10T01:00:00+00:00',
				'document'   => [ 'scopeKey' => 'global_styles:17' ],
			]
		);
		$pending  = $this->create_pending_entry( [ 'timestamp' => '2026-06-10T02:00:00+00:00' ] );
		Repository::transition_external_apply(
			(string) $pending['id'],
			[
				'applyStatus' => 'available',
				'executedAt'  => '2026-06-10T02:00:01+00:00',
				'before'      => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
				'after'       => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
				'target'      => [ 'globalStylesId' => '17' ],
			]
		);

		$this->assertFalse( Repository::can_perform_ordered_undo( (string) $executed['id'] ) );

		$blocked = Repository::update_undo_status( (string) $executed['id'], 'undone' );
		$this->assertInstanceOf( \WP_Error::class, $blocked );
		$this->assertSame( 'flavor_agent_activity_undo_blocked', $blocked->get_error_code() );
	}

	public function test_admin_query_reports_lifecycle_statuses_and_summary_counts(): void {
		$this->create_pending_entry( [ 'id' => 'row-pending' ] );
		$rejected = $this->create_pending_entry( [ 'id' => 'row-rejected' ] );
		Repository::transition_external_apply( 'row-rejected', [ 'applyStatus' => 'rejected' ] );
		$expired = $this->create_pending_entry(
			[
				'id'      => 'row-expired',
				'request' => [ 'apply' => [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] ],
			]
		);
		Repository::maybe_expire_pending_apply( Repository::find( 'row-expired' ) );

		$result   = Repository::query_admin( [] );
		$statuses = [];

		foreach ( $result['entries'] as $entry ) {
			$statuses[ (string) $entry['id'] ] = (string) ( $entry['status'] ?? '' );
		}

		$this->assertSame( 'pending', $statuses['row-pending'] );
		$this->assertSame( 'rejected', $statuses['row-rejected'] );
		$this->assertSame( 'expired', $statuses['row-expired'] );
		$this->assertSame( 1, $result['summary']['pending'] );
		$this->assertSame( 1, $result['summary']['rejected'] );
		$this->assertSame( 1, $result['summary']['expired'] );
		unset( $rejected, $expired );
	}

	public function test_admin_query_reports_pre_execution_failed_rows_as_failed(): void {
		$failed = $this->create_pending_entry( [ 'id' => 'row-failed' ] );
		Repository::transition_external_apply(
			'row-failed',
			[
				'applyStatus'    => 'failed',
				'failureCode'    => 'flavor_agent_apply_stale',
				'failureMessage' => 'Drifted before approval.',
			]
		);
		unset( $failed );

		$result = Repository::query_admin( [] );
		$entry  = null;

		foreach ( $result['entries'] as $candidate ) {
			if ( 'row-failed' === (string) ( $candidate['id'] ?? '' ) ) {
				$entry = $candidate;
			}
		}

		$this->assertIsArray( $entry );
		$this->assertSame( 'failed', $entry['status'] );
	}

	public function test_pending_rows_project_operation_metadata_from_the_apply_payload(): void {
		$created = $this->create_pending_entry( [ 'id' => 'row-projected' ] );
		unset( $created );

		$table = Repository::table_name();
		$row   = null;

		foreach ( WordPressTestState::$db_tables[ $table ] ?? [] as $candidate ) {
			if ( 'row-projected' === (string) ( $candidate['activity_id'] ?? '' ) ) {
				$row = $candidate;
			}
		}

		$this->assertIsArray( $row );
		$this->assertNotSame(
			'',
			trim( (string) ( $row['admin_operation_type'] ?? '' ) ),
			'Pending rows must derive admin operation metadata from request.apply.operations.'
		);
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter ExternalApplyLifecycleTest`
Expected: FAIL — `Call to undefined method …::can_perform_ordered_undo()`, then (after stubbing nothing else) the admin-status test failing with `'applied'` instead of `'pending'`.

- [ ] **Step 3: Implement the Repository changes**

In `inc/Activity/Repository.php`:

3a. Add the row classifier next to `is_review_only_row()` (≈ line 3081):

```php
	/**
	 * @param array<string, mixed> $row
	 */
	private static function is_non_executed_apply_row( array $row ): bool {
		return in_array(
			trim( (string) ( $row['execution_result'] ?? '' ) ),
			[ 'pending', 'rejected', 'expired', 'failed' ],
			true
		);
	}
```

3b. In `is_ordered_undo_eligible()` (≈ line 1130), extend the skip condition:

```php
			if ( self::is_review_only_row( $rows[ $index ] ) || self::is_non_executed_apply_row( $rows[ $index ] ) ) {
				continue;
			}
```

3c. Add the public eligibility probe (after `update_undo_status()`):

```php
	public static function can_perform_ordered_undo( string $activity_id ): bool {
		$row = self::find_row( $activity_id );

		return is_array( $row ) && self::is_ordered_undo_eligible( $row );
	}
```

3d. In `get_admin_sql_status_case()` (≈ line 1895), report lifecycle statuses first. Replace the method body with:

```php
	private static function get_admin_sql_status_case(): string {
		$review_condition       = self::get_admin_sql_review_condition( 't' );
		$failed_condition       = self::get_admin_sql_undo_status_condition( 't.undo_state', 'failed' );
		$undone_condition       = self::get_admin_sql_undo_status_condition( 't.undo_state', 'undone' );
		$newer_active_condition = self::get_admin_sql_newer_active_exists();

		return '(CASE'
			. " WHEN t.execution_result IN ('pending') THEN 'pending'"
			. " WHEN t.execution_result IN ('rejected') THEN 'rejected'"
			. " WHEN t.execution_result IN ('expired') THEN 'expired'"
			. " WHEN t.execution_result IN ('failed') THEN 'failed'"
			. ' WHEN ' . $review_condition . ' THEN CASE WHEN ' . $failed_condition . " THEN 'failed' ELSE 'review' END"
			. ' WHEN ' . $undone_condition . " THEN 'undone'"
			. ' WHEN ' . $newer_active_condition . " THEN 'blocked'"
			. ' WHEN ' . $failed_condition . " THEN 'failed'"
			. " ELSE 'applied' END)";
	}
```

(`IN ('pending')` instead of `= 'pending'` is deliberate: the fake wpdb's `execution_result = '…'` regex from Task 2 must not match fragments of this CASE.)

3e. In `get_admin_sql_newer_active_exists()` (≈ line 1909), exclude non-executed rows — add one line before the closing `')'`:

```php
			. ' AND NOT ' . self::get_admin_sql_undo_status_condition( 'newer.undo_state', 'undone' )
			. " AND newer.execution_result NOT IN ('pending','rejected','expired','failed')"
			. ')';
```

3f. In `resolve_admin_row_statuses()` (≈ line 2155), classify non-executed rows up front and exclude them from the newer-active walk. In the first `foreach`, after the `is_review_only_row` branch, add:

```php
			if ( self::is_non_executed_apply_row( $row ) ) {
				$activity_id                = trim( (string) ( $row['activity_id'] ?? '' ) );
				$status_map[ $activity_id ] = trim( (string) ( $row['execution_result'] ?? '' ) );
				$review_indexes[ $index ]   = true;
			}
```

3g. In `resolve_admin_records()` (≈ line 2234), extend the fallback default so a non-executed row never defaults to `applied`:

```php
			$status      = $status_map[ $activity_id ] ?? (
				self::is_non_executed_apply_row( $row )
					? trim( (string) ( $row['execution_result'] ?? '' ) )
					: (
						'failed' === self::get_admin_row_undo_status( $row ) && self::is_review_only_row( $row )
							? 'failed'
							: ( self::is_review_only_row( $row ) ? 'review' : 'applied' )
					)
			);
```

3h. Add the three keys to BOTH summary builders. In `query_admin_sql_summary()` (≈ line 1462) and `build_admin_summary()` (≈ line 2458), extend the initial `$summary` array:

```php
		$summary = [
			'total'    => 0,
			'applied'  => 0,
			'undone'   => 0,
			'review'   => 0,
			'blocked'  => 0,
			'failed'   => 0,
			'pending'  => 0,
			'rejected' => 0,
			'expired'  => 0,
		];
```

In `build_admin_summary()` also extend the per-status counting `if`/`elseif` chain (mirror how `review` is counted) with `pending`, `rejected`, and `expired` branches.

3i. In `format_status_label()` (≈ line 3876), add to the map:

```php
			'pending'  => 'Pending approval',
			'rejected' => 'Rejected',
			'expired'  => 'Expired',
```

3j. Derive pending operation metadata from `request.apply.operations` (executed rows keep deriving from `after.operations`). Add this private helper near `build_admin_projection_from_entry()`:

```php
	/**
	 * Pre-execution external-apply rows keep their proposed operations under
	 * request.apply.operations; surface them to the admin operation-metadata
	 * derivation that otherwise reads after.operations.
	 *
	 * @param array<string, mixed> $after
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>
	 */
	private static function operation_metadata_after( array $after, array $request ): array {
		if ( is_array( $after['operations'] ?? null ) && [] !== $after['operations'] ) {
			return $after;
		}

		$apply_operations = $request['apply']['operations'] ?? null;

		if ( is_array( $apply_operations ) && [] !== $apply_operations ) {
			$after['operations'] = $apply_operations;
		}

		return $after;
	}
```

Then, in `build_admin_projection_from_entry()`, change the `derive_admin_operation_metadata` call to pass the augmented after:

```php
		$operation              = self::derive_admin_operation_metadata(
			$before,
			self::operation_metadata_after( $after, $request ),
			$surface,
			$activity_type
		);
```

and make the same substitution at the `derive_admin_operation_metadata` call inside `build_admin_record_from_row()` (≈ line 2266), which already has `$request` decoded in scope.

- [ ] **Step 4: Extend the fake wpdb admin-status simulation**

In `tests/phpunit/bootstrap.php`, in `resolve_activity_admin_status()` (≈ line 1694):

After the `$is_review = …;` assignment, add:

```php
				$non_executed = in_array(
					(string) ($row['execution_result'] ?? ''),
					['pending', 'rejected', 'expired', 'failed'],
					true
				);

				if ($non_executed) {
					return (string) $row['execution_result'];
				}
```

And in the newer-candidate loop, extend the `$candidate_review` assignment so non-executed candidates never block:

```php
					$candidate_review = 'request_diagnostic' === (string) ($candidate['activity_type'] ?? '')
						|| 'review' === (string) ($candidate['execution_result'] ?? '')
						|| in_array(
							(string) ($candidate['execution_result'] ?? ''),
							['pending', 'rejected', 'expired', 'failed'],
							true
						);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ExternalApplyLifecycleTest`
Expected: PASS.
Run: `vendor/bin/phpunit`
Expected: PASS — pay attention to `ActivityRepositoryTest` and `ActivityPageTest` (status-CASE and summary consumers).

- [ ] **Step 6: Commit**

```bash
git add inc/Activity/Repository.php tests/phpunit/bootstrap.php tests/phpunit/ExternalApplyLifecycleTest.php
git commit -m "Exclude non-executed external applies from ordered undo and surface lifecycle statuses in admin reads"
```

---

### Task 4: StyleApplyExecutor — apply path

Ports the apply half of `src/utils/style-operations.js` (`applyGlobalStyleSuggestionOperations`) to PHP, reusing the server's existing generation-time validators.

**Files:**
- Create: `inc/Apply/StyleApplyExecutor.php`
- Modify: `inc/LLM/StylePrompt.php` (public seam)
- Modify: `inc/Abilities/StyleAbilities.php` (two public seams)
- Modify: `tests/phpunit/bootstrap.php` (`wp_update_post` stub)
- Create: `tests/phpunit/StyleApplyExecutorTest.php`

- [ ] **Step 1: Add the `wp_update_post` stub**

In `tests/phpunit/bootstrap.php`:

1a. In `WordPressTestState`, next to the other arrays, add:

```php
		/** @var array<int, array<string, mixed>> */
		public static array $updated_posts = [];
```

and in `WordPressTestState::reset()` add `self::$updated_posts = [];`.

1b. Next to the `get_post()` stub (≈ line 3043), add:

```php
	if (! function_exists('wp_update_post')) {
		function wp_update_post(array $postarr)
		{
			$id = (int) ($postarr['ID'] ?? 0);

			if ($id <= 0 || ! isset(WordPressTestState::$posts[$id])) {
				return 0;
			}

			foreach ($postarr as $key => $value) {
				if ('ID' !== $key && property_exists(WordPressTestState::$posts[$id], $key)) {
					WordPressTestState::$posts[$id]->{$key} = $value;
				}
			}

			WordPressTestState::$updated_posts[] = $postarr;

			return $id;
		}
	}
```

- [ ] **Step 2: Write the failing executor apply tests**

Create `tests/phpunit/StyleApplyExecutorTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Apply\StyleApplyExecutor;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class StyleApplyExecutorTest extends TestCase {

	private const GLOBAL_STYLES_ID = '17';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		$this->seed_global_styles_post( [ 'settings' => [], 'styles' => [] ] );
		$this->seed_theme_contract();
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function seed_global_styles_post( array $config ): void {
		WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ] = new \WP_Post(
			[
				'ID'           => (int) self::GLOBAL_STYLES_ID,
				'post_type'    => 'wp_global_styles',
				'post_content' => (string) wp_json_encode(
					array_merge(
						[
							'version'                     => 3,
							'isGlobalStylesUserThemeJSON' => true,
						],
						$config
					)
				),
			]
		);
	}

	/**
	 * Theme tokens that make color.text/color.background supported preset
	 * paths with an accent (#111111) and base (#fefefe) palette, mirroring the
	 * shapes ServerCollector::for_tokens() derives from wp_get_global_settings().
	 */
	private function seed_theme_contract(): void {
		WordPressTestState::$global_settings = [
			'color'      => [
				'palette' => [
					'theme' => [
						[
							'slug'  => 'accent',
							'name'  => 'Accent',
							'color' => '#111111',
						],
						[
							'slug'  => 'base',
							'name'  => 'Base',
							'color' => '#fefefe',
						],
					],
				],
				'background' => true,
				'text'       => true,
			],
		];
		WordPressTestState::$global_styles = [];
	}

	public function test_apply_writes_validated_preset_operations_and_returns_editor_shaped_snapshots(): void {
		$result = StyleApplyExecutor::apply(
			'global-styles',
			self::GLOBAL_STYLES_ID,
			[
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'text' ],
					'value'      => 'var:preset|color|accent',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'accent',
					'cssVar'     => 'var(--wp--preset--color--accent)',
				],
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|base',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'base',
					'cssVar'     => 'var(--wp--preset--color--base)',
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 'globalStylesId' => self::GLOBAL_STYLES_ID ], $result['target'] );
		$this->assertSame( [], $result['before']['userConfig']['styles'] );
		$this->assertSame(
			'var:preset|color|accent',
			$result['after']['userConfig']['styles']['color']['text']
		);
		$this->assertCount( 2, $result['after']['operations'] );
		$this->assertNull( $result['after']['operations'][0]['beforeValue'] );

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( 'var:preset|color|accent', $written['styles']['color']['text'] );
		$this->assertTrue( $written['isGlobalStylesUserThemeJSON'] );
	}

	public function test_apply_rejects_operations_that_fail_the_live_execution_contract(): void {
		$result = StyleApplyExecutor::apply(
			'global-styles',
			self::GLOBAL_STYLES_ID,
			[
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'text' ],
					'value'      => 'var:preset|color|missing-slug',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'missing-slug',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'Invalid operations must not write.' );
	}

	public function test_apply_blocks_failing_contrast_pairs(): void {
		// Near-white text on near-white background fails WCAG AA 4.5.
		WordPressTestState::$global_settings['color']['palette']['theme'][0]['color'] = '#fdfdfd';

		$result = StyleApplyExecutor::apply(
			'global-styles',
			self::GLOBAL_STYLES_ID,
			[
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'text' ],
					'value'      => 'var:preset|color|accent',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'accent',
				],
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|base',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'base',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_contrast_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_apply_resolves_theme_variations_and_replaces_the_user_config(): void {
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#000000' ] ],
			]
		);
		add_filter(
			'flavor_agent_external_apply_theme_variations',
			static fn(): array => [
				[
					'title'    => 'Midnight',
					'settings' => [ 'custom' => [ 'mood' => 'dark' ] ],
					'styles'   => [ 'color' => [ 'background' => '#101010' ] ],
				],
			]
		);

		$result = StyleApplyExecutor::apply(
			'global-styles',
			self::GLOBAL_STYLES_ID,
			[
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 0,
					'variationTitle' => 'Midnight',
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[ 'color' => [ 'background' => '#101010' ] ],
			$result['after']['userConfig']['styles']
		);
		$this->assertSame(
			[ 'color' => [ 'text' => '#000000' ] ],
			$result['before']['userConfig']['styles']
		);
	}

	public function test_style_book_apply_targets_the_block_branch_and_trims_snapshots(): void {
		WordPressTestState::$registered_block_types['core/paragraph'] = [
			'title'    => 'Paragraph',
			'supports' => [
				'color' => [
					'background' => true,
					'text'       => true,
				],
			],
		];

		$result = StyleApplyExecutor::apply(
			'style-book',
			self::GLOBAL_STYLES_ID,
			[
				[
					'type'       => 'set_block_styles',
					'blockName'  => 'core/paragraph',
					'path'       => [ 'color', 'text' ],
					'value'      => 'var:preset|color|accent',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'accent',
				],
				[
					'type'       => 'set_block_styles',
					'blockName'  => 'core/paragraph',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|base',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'base',
				],
			],
			'core/paragraph'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'core/paragraph', $result['target']['blockName'] );
		$this->assertSame( [], $result['before']['userConfig'], 'Absent branch trims to an empty before snapshot.' );
		$this->assertSame(
			'var:preset|color|accent',
			$result['after']['userConfig']['styles']['blocks']['core/paragraph']['color']['text']
		);
		$this->assertArrayNotHasKey(
			'color',
			$result['after']['userConfig']['styles'],
			'Style Book snapshots must contain only the block branch.'
		);

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame(
			'var:preset|color|base',
			$written['styles']['blocks']['core/paragraph']['color']['background']
		);
	}

	public function test_apply_fails_when_the_entity_is_missing(): void {
		$result = StyleApplyExecutor::apply( 'global-styles', '999', [ [ 'type' => 'set_styles' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}
}
```

Note: if `WordPressTestState` has no `$registered_block_types` map consumed by the `WP_Block_Type_Registry` stub, check how `StyleAbilitiesTest` registers block types for `ServerCollector::introspect_block_type()` and register `core/paragraph` the same way — the assertion stays identical.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter StyleApplyExecutorTest`
Expected: FAIL — `Class "FlavorAgent\Apply\StyleApplyExecutor" not found`.

- [ ] **Step 4: Add the two public validator seams**

4a. In `inc/LLM/StylePrompt.php`, directly above `validate_operations_for_tests()` (≈ line 1282), add:

```php
	/**
	 * Public seam for server-side apply paths (external applies). Same
	 * contract as the private generation-time validator.
	 *
	 * @param array<int, mixed>    $operations Raw operations to validate.
	 * @param array<string, mixed> $context    Style validation context.
	 * @return array{operations: array<int, array<string, mixed>>, reasons: array<int, array{code: string, severity: string, message?: string}>}
	 */
	public static function validate_operations_for_apply( array $operations, array $context ): array {
		return self::validate_operations( $operations, $context );
	}
```

4b. In `inc/Abilities/StyleAbilities.php`, after `supported_style_paths()`, add:

```php
	/**
	 * Public seam exposing the per-block supported style paths for server-side
	 * apply validation.
	 *
	 * @param array<string, mixed> $block_manifest
	 * @return array<int, array<string, mixed>>
	 */
	public static function supported_style_paths_for_block( array $block_manifest ): array {
		return self::supported_block_style_paths_from_manifest( $block_manifest );
	}

	/**
	 * Public seam exposing the canonical style scope key for external applies.
	 */
	public static function canonical_scope_key_for( string $surface, string $global_styles_id, string $block_name = '' ): string {
		return self::canonical_scope_key(
			self::SURFACE_STYLE_BOOK === $surface ? self::SURFACE_STYLE_BOOK : self::SURFACE_GLOBAL_STYLES,
			$global_styles_id,
			$block_name
		);
	}
```

- [ ] **Step 5: Implement the executor (apply half)**

Create `inc/Apply/StyleApplyExecutor.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\StyleContrastValidator;
use FlavorAgent\LLM\StylePrompt;

/**
 * Server-side executor for Global Styles / Style Book apply and undo.
 *
 * Ports the client pipeline in src/utils/style-operations.js
 * (applyGlobalStyleSuggestionOperations / undoGlobalStyleSuggestionOperations):
 * validate against the live execution contract, apply to the user config,
 * enforce WCAG AA contrast, write the wp_global_styles CPT through core APIs,
 * and snapshot in the editor-compatible before/after shapes.
 */
final class StyleApplyExecutor {

	public const SURFACE_GLOBAL_STYLES = 'global-styles';
	public const SURFACE_STYLE_BOOK    = 'style-book';

	/**
	 * @return array{postId: int, config: array{settings: array<string, mixed>, styles: array<string, mixed>}, raw: array<string, mixed>}|\WP_Error
	 */
	public static function resolve_user_global_styles( string $global_styles_id ): array|\WP_Error {
		$post_id = (int) $global_styles_id;
		$post    = $post_id > 0 && function_exists( 'get_post' ) ? get_post( $post_id ) : null;

		if ( ! is_object( $post ) || 'wp_global_styles' !== (string) ( $post->post_type ?? '' ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'The requested Global Styles entity is not available on this site.',
				[ 'status' => 404 ]
			);
		}

		$decoded = json_decode( (string) ( $post->post_content ?? '' ), true );
		$raw     = is_array( $decoded ) ? $decoded : [];

		return [
			'postId' => $post_id,
			'config' => [
				'settings' => is_array( $raw['settings'] ?? null ) ? $raw['settings'] : [],
				'styles'   => is_array( $raw['styles'] ?? null ) ? $raw['styles'] : [],
			],
			'raw'    => $raw,
		];
	}

	/**
	 * Comparable view of a user config: settings + styles only, keys sorted
	 * deep, matching the client's getComparableGlobalStylesConfig().
	 *
	 * @param array<string, mixed> $config
	 * @return array{settings: mixed, styles: mixed}
	 */
	public static function comparable_config( array $config ): array {
		return [
			'settings' => self::sort_keys_deep( is_array( $config['settings'] ?? null ) ? $config['settings'] : [] ),
			'styles'   => self::sort_keys_deep( is_array( $config['styles'] ?? null ) ? $config['styles'] : [] ),
		];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function comparable_config_hash( array $config ): string {
		return hash( 'sha256', (string) wp_json_encode( self::comparable_config( $config ) ) );
	}

	/**
	 * Live validation context with the same shape StylePrompt::validate_operations()
	 * consumes at generation time.
	 *
	 * @return array{scope: array<string, mixed>, styleContext: array<string, mixed>}|\WP_Error
	 */
	public static function build_validation_context( string $surface, string $block_name = '' ): array|\WP_Error {
		$theme_tokens = ServerCollector::for_tokens();

		if ( self::SURFACE_STYLE_BOOK === $surface ) {
			$block_manifest = ServerCollector::introspect_block_type( $block_name );

			if ( ! is_array( $block_manifest ) ) {
				return new \WP_Error(
					'flavor_agent_apply_target_unavailable',
					'The Style Book target block is no longer registered on this site.',
					[ 'status' => 409 ]
				);
			}

			return [
				'scope'        => [
					'surface'   => self::SURFACE_STYLE_BOOK,
					'blockName' => $block_name,
				],
				'styleContext' => [
					'supportedStylePaths' => StyleAbilities::supported_style_paths_for_block( $block_manifest ),
					'availableVariations' => [],
					'themeTokens'         => $theme_tokens,
					'styleBookTarget'     => [
						'blockName'  => $block_name,
						'blockTitle' => sanitize_text_field( (string) ( $block_manifest['title'] ?? '' ) ),
					],
				],
			];
		}

		return [
			'scope'        => [ 'surface' => self::SURFACE_GLOBAL_STYLES ],
			'styleContext' => [
				'supportedStylePaths' => StyleAbilities::supported_style_paths(),
				'availableVariations' => self::theme_style_variations(),
				'themeTokens'         => $theme_tokens,
			],
		];
	}

	/**
	 * Validate and execute operations against the live entity.
	 *
	 * @param array<int, array<string, mixed>> $operations
	 * @return array{target: array<string, mixed>, before: array<string, mixed>, after: array<string, mixed>}|\WP_Error
	 */
	public static function apply( string $surface, string $global_styles_id, array $operations, string $block_name = '' ): array|\WP_Error {
		$surface  = self::SURFACE_STYLE_BOOK === $surface ? self::SURFACE_STYLE_BOOK : self::SURFACE_GLOBAL_STYLES;
		$resolved = self::resolve_user_global_styles( $global_styles_id );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$context = self::build_validation_context( $surface, $block_name );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$validated = StylePrompt::validate_operations_for_apply( $operations, $context );

		if ( [] === $validated['operations'] || count( $validated['operations'] ) !== count( $operations ) ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'One or more proposed style operations failed validation against the current execution contract.',
				[
					'status'            => 409,
					'validationReasons' => $validated['reasons'],
				]
			);
		}

		$before_config = $resolved['config'];
		$after_config  = $before_config;
		$applied       = [];
		$variations    = is_array( $context['styleContext']['availableVariations'] ?? null )
			? $context['styleContext']['availableVariations']
			: [];

		foreach ( $validated['operations'] as $operation ) {
			$type = (string) ( $operation['type'] ?? '' );

			if ( 'set_theme_variation' === $type ) {
				$variation = self::resolve_variation_payload( $operation, $variations );

				if ( null === $variation ) {
					return new \WP_Error(
						'flavor_agent_apply_operations_invalid',
						'The suggested theme variation is no longer available.',
						[ 'status' => 409 ]
					);
				}

				$after_config = [
					'settings' => is_array( $variation['settings'] ?? null ) ? $variation['settings'] : [],
					'styles'   => is_array( $variation['styles'] ?? null ) ? $variation['styles'] : [],
				];
				$applied[]    = $operation;
				continue;
			}

			$path        = is_array( $operation['path'] ?? null ) ? array_values( $operation['path'] ) : [];
			$config_path = 'set_block_styles' === $type
				? array_merge( [ 'blocks', (string) ( $operation['blockName'] ?? '' ) ], $path )
				: $path;
			$applied[]   = array_merge(
				$operation,
				[ 'beforeValue' => self::read_path( $before_config['styles'], $config_path ) ]
			);

			$after_config['styles'] = self::write_path( $after_config['styles'], $config_path, $operation['value'] ?? null );
		}

		$contrast = StyleContrastValidator::evaluate(
			$applied,
			[
				'styleContext' => [
					'themeTokens'  => is_array( $context['styleContext']['themeTokens'] ?? null )
						? $context['styleContext']['themeTokens']
						: [],
					'mergedConfig' => self::merged_config_with_user_overrides( $after_config ),
				],
			]
		);

		if ( empty( $contrast['passed'] ) ) {
			return new \WP_Error(
				'flavor_agent_apply_contrast_failed',
				(string) ( $contrast['reason'] ?? 'The proposed style changes fail the WCAG AA contrast requirement.' ),
				[ 'status' => 409 ]
			);
		}

		$write = self::write_user_global_styles( $resolved['postId'], $resolved['raw'], $after_config );

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		if ( self::SURFACE_STYLE_BOOK === $surface ) {
			$style_book_target = is_array( $context['styleContext']['styleBookTarget'] ?? null )
				? $context['styleContext']['styleBookTarget']
				: [];

			return [
				'target' => [
					'globalStylesId' => $global_styles_id,
					'blockName'      => $block_name,
					'blockTitle'     => (string) ( $style_book_target['blockTitle'] ?? '' ),
				],
				'before' => [ 'userConfig' => self::trim_config_to_block_branch( $before_config, $block_name ) ],
				'after'  => [
					'userConfig' => self::trim_config_to_block_branch( $after_config, $block_name ),
					'operations' => $applied,
				],
			];
		}

		return [
			'target' => [ 'globalStylesId' => $global_styles_id ],
			'before' => [ 'userConfig' => $before_config ],
			'after'  => [
				'userConfig' => $after_config,
				'operations' => $applied,
			],
		];
	}

	/**
	 * The live merged config already contains the current user layer, and the
	 * set-operation vocabulary never deletes keys, so overlaying the post-apply
	 * user config over the current merged data yields the same complements the
	 * client computes from theme-base + after-config. Variation switches (which
	 * CAN drop keys) never reach contrast grouping because variation + readable
	 * color combinations are rejected upstream.
	 *
	 * @param array{settings: array<string, mixed>, styles: array<string, mixed>} $after_config
	 * @return array{settings: array<string, mixed>, styles: array<string, mixed>}
	 */
	private static function merged_config_with_user_overrides( array $after_config ): array {
		$merged_settings = function_exists( 'wp_get_global_settings' ) ? (array) wp_get_global_settings() : [];
		$merged_styles   = function_exists( 'wp_get_global_styles' ) ? (array) wp_get_global_styles() : [];

		return [
			'settings' => self::merge_deep( $merged_settings, $after_config['settings'] ),
			'styles'   => self::merge_deep( $merged_styles, $after_config['styles'] ),
		];
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function write_user_global_styles( int $post_id, array $raw, array $after_config ): true|\WP_Error {
		$content = array_merge(
			$raw,
			[
				'isGlobalStylesUserThemeJSON' => true,
				'settings'                    => $after_config['settings'],
				'styles'                      => $after_config['styles'],
			]
		);

		if ( ! isset( $content['version'] ) ) {
			$content['version'] = class_exists( '\WP_Theme_JSON' ) && defined( '\WP_Theme_JSON::LATEST_SCHEMA' )
				? \WP_Theme_JSON::LATEST_SCHEMA
				: 3;
		}

		$updated = function_exists( 'wp_update_post' )
			? wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => (string) wp_json_encode( $content ),
				]
			)
			: 0;

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( 0 === (int) $updated ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Flavor Agent could not write the Global Styles entity.',
				[ 'status' => 500 ]
			);
		}

		if ( class_exists( '\WP_Theme_JSON_Resolver' ) && method_exists( '\WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}

		return true;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function theme_style_variations(): array {
		$variations = class_exists( '\WP_Theme_JSON_Resolver' ) && method_exists( '\WP_Theme_JSON_Resolver', 'get_style_variations' )
			? (array) \WP_Theme_JSON_Resolver::get_style_variations()
			: [];

		return (array) apply_filters( 'flavor_agent_external_apply_theme_variations', $variations );
	}

	/**
	 * @param array<string, mixed>              $operation
	 * @param array<int, array<string, mixed>>  $variations
	 * @return array<string, mixed>|null
	 */
	private static function resolve_variation_payload( array $operation, array $variations ): ?array {
		$index   = isset( $operation['variationIndex'] ) && is_numeric( $operation['variationIndex'] )
			? (int) $operation['variationIndex']
			: -1;
		$title   = trim( (string) ( $operation['variationTitle'] ?? '' ) );
		$indexed = $variations[ $index ] ?? null;

		if ( is_array( $indexed ) && ( '' === $title || trim( (string) ( $indexed['title'] ?? '' ) ) === $title ) ) {
			return $indexed;
		}

		if ( '' === $title ) {
			return null;
		}

		foreach ( $variations as $variation ) {
			if ( is_array( $variation ) && trim( (string) ( $variation['title'] ?? '' ) ) === $title ) {
				return $variation;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	private static function trim_config_to_block_branch( array $config, string $block_name ): array {
		$branch = self::read_path(
			is_array( $config['styles'] ?? null ) ? $config['styles'] : [],
			[ 'blocks', $block_name ]
		);

		if ( null === $branch ) {
			return [];
		}

		return [
			'styles' => [
				'blocks' => [
					$block_name => $branch,
				],
			],
		];
	}

	/**
	 * @param array<int, int|string> $path
	 */
	private static function read_path( mixed $value, array $path ): mixed {
		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * @param array<int, int|string> $path
	 */
	private static function write_path( mixed $value, array $path, mixed $next ): mixed {
		if ( [] === $path ) {
			return $next;
		}

		$value          = is_array( $value ) ? $value : [];
		$head           = array_shift( $path );
		$value[ $head ] = self::write_path( $value[ $head ] ?? null, $path, $next );

		return $value;
	}

	/**
	 * @param array<int, int|string> $path
	 */
	private static function remove_path( mixed $value, array $path ): mixed {
		if ( ! is_array( $value ) || [] === $path ) {
			return $value;
		}

		$head = $path[0];

		if ( ! array_key_exists( $head, $value ) ) {
			return $value;
		}

		if ( 1 === count( $path ) ) {
			unset( $value[ $head ] );

			return $value;
		}

		$branch = self::remove_path( $value[ $head ], array_slice( $path, 1 ) );

		if ( null === $branch || ( is_array( $branch ) && [] === $branch ) ) {
			unset( $value[ $head ] );
		} else {
			$value[ $head ] = $branch;
		}

		return $value;
	}

	private static function sort_keys_deep( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( [] === $value ) {
			return [];
		}

		if ( array_is_list( $value ) ) {
			return array_map( [ self::class, 'sort_keys_deep' ], $value );
		}

		ksort( $value );

		$sorted = [];

		foreach ( $value as $key => $entry ) {
			$sorted[ $key ] = self::sort_keys_deep( $entry );
		}

		return $sorted;
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $override
	 * @return array<string, mixed>
	 */
	private static function merge_deep( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			$base[ $key ] = is_array( $value ) && is_array( $base[ $key ] ?? null ) && ! array_is_list( $value )
				? self::merge_deep( $base[ $key ], $value )
				: $value;
		}

		return $base;
	}
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter StyleApplyExecutorTest`
Expected: PASS (6 tests). If `seed_theme_contract()` doesn't produce supported `color.text`/`color.background` paths, inspect how `StyleAbilitiesTest` seeds `WordPressTestState::$global_settings` for `supported_style_paths()` and mirror that exact seed; the executor assertions stay unchanged.

- [ ] **Step 7: Commit**

```bash
git add inc/Apply/StyleApplyExecutor.php inc/LLM/StylePrompt.php inc/Abilities/StyleAbilities.php tests/phpunit/bootstrap.php tests/phpunit/StyleApplyExecutorTest.php
git commit -m "Add server-side style apply executor with live-contract validation and contrast enforcement"
```

---

### Task 5: StyleApplyExecutor — undo path

Mirrors `getGlobalStylesActivityUndoState` + `undoGlobalStyleSuggestionOperations`: already-undone short-circuit, after-mismatch drift failure, full-config restore for Global Styles, branch-only restore for Style Book, scoped failure for legacy/unknown snapshot shapes.

**Files:**
- Modify: `inc/Apply/StyleApplyExecutor.php`
- Test: `tests/phpunit/StyleApplyExecutorTest.php`

- [ ] **Step 1: Write the failing undo tests**

Append to `tests/phpunit/StyleApplyExecutorTest.php`:

```php
	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function global_styles_entry( array $overrides = [] ): array {
		return array_replace_recursive(
			[
				'surface' => 'global-styles',
				'target'  => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
				'before'  => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
				'after'   => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
				],
			],
			$overrides
		);
	}

	public function test_undo_restores_the_full_before_config_for_global_styles(): void {
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);

		$result = StyleApplyExecutor::undo( $this->global_styles_entry() );

		$this->assertSame( [ 'result' => 'undone' ], $result );

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( [], $written['styles'] );
	}

	public function test_undo_reports_already_undone_when_live_config_matches_before(): void {
		$this->seed_global_styles_post( [ 'settings' => [], 'styles' => [] ] );

		$result = StyleApplyExecutor::undo( $this->global_styles_entry() );

		$this->assertSame( [ 'result' => 'already_undone' ], $result );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'Already-undone must not write.' );
	}

	public function test_undo_fails_closed_on_drift_when_live_config_matches_neither_snapshot(): void {
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#333333' ] ],
			]
		);

		$result = StyleApplyExecutor::undo( $this->global_styles_entry() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_drift', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_undo_restores_only_the_block_branch_for_style_book_rows(): void {
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [
					'color'  => [ 'text' => '#222222' ],
					'blocks' => [
						'core/paragraph' => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
				],
			]
		);

		$result = StyleApplyExecutor::undo(
			[
				'surface' => 'style-book',
				'target'  => [
					'globalStylesId' => self::GLOBAL_STYLES_ID,
					'blockName'      => 'core/paragraph',
				],
				'before'  => [ 'userConfig' => [] ],
				'after'   => [
					'userConfig' => [
						'styles' => [
							'blocks' => [
								'core/paragraph' => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
							],
						],
					],
				],
			]
		);

		$this->assertSame( [ 'result' => 'undone' ], $result );

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertArrayNotHasKey( 'blocks', $written['styles'], 'The branch is removed when before had none.' );
		$this->assertSame( '#222222', $written['styles']['color']['text'], 'Untargeted styles stay untouched.' );
	}

	public function test_style_book_undo_supports_legacy_full_config_snapshots(): void {
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [
					'blocks' => [
						'core/paragraph' => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
				],
			]
		);

		// Legacy style-book rows stored the FULL user config; readers route
		// through the branch path so both shapes undo identically.
		$result = StyleApplyExecutor::undo(
			[
				'surface' => 'style-book',
				'target'  => [
					'globalStylesId' => self::GLOBAL_STYLES_ID,
					'blockName'      => 'core/paragraph',
				],
				'before'  => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [
							'blocks' => [
								'core/paragraph' => [ 'color' => [ 'text' => '#000000' ] ],
							],
						],
					],
				],
				'after'   => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [
							'blocks' => [
								'core/paragraph' => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
							],
						],
					],
				],
			]
		);

		$this->assertSame( [ 'result' => 'undone' ], $result );

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame(
			'#000000',
			$written['styles']['blocks']['core/paragraph']['color']['text']
		);
	}

	public function test_undo_rejects_rows_without_recorded_snapshots(): void {
		$result = StyleApplyExecutor::undo(
			[
				'surface' => 'global-styles',
				'target'  => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
				'before'  => [],
				'after'   => [],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_snapshot_unsupported', $result->get_error_code() );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter StyleApplyExecutorTest`
Expected: FAIL — `Call to undefined method FlavorAgent\Apply\StyleApplyExecutor::undo()`.

- [ ] **Step 3: Implement `undo()`**

Add to `inc/Apply/StyleApplyExecutor.php` (after `apply()`):

```php
	/**
	 * Server-side undo with the exact equality semantics the client uses:
	 * live == before → already undone; live != after → drift failure; else
	 * restore before (full config for Global Styles, block branch for Style Book).
	 *
	 * @param array<string, mixed> $entry Hydrated activity entry.
	 * @return array{result: string}|\WP_Error
	 */
	public static function undo( array $entry ): array|\WP_Error {
		$surface          = (string) ( $entry['surface'] ?? '' );
		$target           = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
		$global_styles_id = trim( (string) ( $target['globalStylesId'] ?? '' ) );

		if ( '' === $global_styles_id ) {
			return new \WP_Error(
				'flavor_agent_undo_snapshot_unsupported',
				'This activity row does not record a Global Styles target and cannot be undone server-side.',
				[ 'status' => 409 ]
			);
		}

		$before = is_array( $entry['before'] ?? null ) ? $entry['before'] : [];
		$after  = is_array( $entry['after'] ?? null ) ? $entry['after'] : [];

		if ( ! array_key_exists( 'userConfig', $before ) || ! array_key_exists( 'userConfig', $after ) ) {
			return new \WP_Error(
				'flavor_agent_undo_snapshot_unsupported',
				'This activity row does not record the before/after Global Styles snapshots needed for a server-side undo.',
				[ 'status' => 409 ]
			);
		}

		$before_config = is_array( $before['userConfig'] ?? null ) ? $before['userConfig'] : [];
		$after_config  = is_array( $after['userConfig'] ?? null ) ? $after['userConfig'] : [];
		$resolved      = self::resolve_user_global_styles( $global_styles_id );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$live = $resolved['config'];

		if ( self::SURFACE_STYLE_BOOK === $surface ) {
			return self::undo_style_book_branch( $resolved, $live, $before_config, $after_config, $target );
		}

		if ( self::comparable_config( $live ) === self::comparable_config( $before_config ) ) {
			return [ 'result' => 'already_undone' ];
		}

		if ( self::comparable_config( $live ) !== self::comparable_config( $after_config ) ) {
			return new \WP_Error(
				'flavor_agent_undo_drift',
				'Global Styles changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
				[ 'status' => 409 ]
			);
		}

		$write = self::write_user_global_styles(
			$resolved['postId'],
			$resolved['raw'],
			[
				'settings' => is_array( $before_config['settings'] ?? null ) ? $before_config['settings'] : [],
				'styles'   => is_array( $before_config['styles'] ?? null ) ? $before_config['styles'] : [],
			]
		);

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		return [ 'result' => 'undone' ];
	}

	/**
	 * @param array{postId: int, config: array<string, mixed>, raw: array<string, mixed>} $resolved
	 * @param array<string, mixed> $live
	 * @param array<string, mixed> $before_config
	 * @param array<string, mixed> $after_config
	 * @param array<string, mixed> $target
	 * @return array{result: string}|\WP_Error
	 */
	private static function undo_style_book_branch( array $resolved, array $live, array $before_config, array $after_config, array $target ): array|\WP_Error {
		$block_name = trim( (string) ( $target['blockName'] ?? '' ) );

		if ( '' === $block_name ) {
			return new \WP_Error(
				'flavor_agent_undo_snapshot_unsupported',
				'The Style Book target block for this AI action is missing.',
				[ 'status' => 409 ]
			);
		}

		$branch_path   = [ 'blocks', $block_name ];
		$live_styles   = is_array( $live['styles'] ?? null ) ? $live['styles'] : [];
		$before_styles = is_array( $before_config['styles'] ?? null ) ? $before_config['styles'] : [];
		$after_styles  = is_array( $after_config['styles'] ?? null ) ? $after_config['styles'] : [];
		$live_branch   = self::sort_keys_deep( self::read_path( $live_styles, $branch_path ) );
		$before_branch = self::sort_keys_deep( self::read_path( $before_styles, $branch_path ) );
		$after_branch  = self::sort_keys_deep( self::read_path( $after_styles, $branch_path ) );

		if ( $live_branch === $before_branch ) {
			return [ 'result' => 'already_undone' ];
		}

		if ( $live_branch !== $after_branch ) {
			return new \WP_Error(
				'flavor_agent_undo_drift',
				'Style Book target styles changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
				[ 'status' => 409 ]
			);
		}

		$previous_branch = self::read_path( $before_styles, $branch_path );
		$next_styles     = null === $previous_branch
			? self::remove_path( $live_styles, $branch_path )
			: self::write_path( $live_styles, $branch_path, $previous_branch );
		$write           = self::write_user_global_styles(
			$resolved['postId'],
			$resolved['raw'],
			[
				'settings' => is_array( $live['settings'] ?? null ) ? $live['settings'] : [],
				'styles'   => is_array( $next_styles ) ? $next_styles : [],
			]
		);

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		return [ 'result' => 'undone' ];
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter StyleApplyExecutorTest`
Expected: PASS (12 tests).

- [ ] **Step 5: Commit**

```bash
git add inc/Apply/StyleApplyExecutor.php tests/phpunit/StyleApplyExecutorTest.php
git commit -m "Add server-side style undo with drift checks and Style Book branch restores"
```

---

### Task 6: `ApplyAbilities::request_style_apply`

The agent-facing request: double freshness gate (signature recompute + live-config equality), queue cap, live-contract operation validation, pending-row creation, `stale_blocked` outcome diagnostic on staleness.

**Files:**
- Create: `inc/Abilities/ApplyAbilities.php`
- Create: `tests/phpunit/ApplyAbilitiesTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/phpunit/ApplyAbilitiesTest.php`. The fixture builds a real signature pair by calling `StyleAbilities::recommend_style()` in signature-only mode with the same input the "agent" submits — so signature matching exercises the genuine pipeline, not canned hashes.

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ApplyAbilitiesTest extends TestCase {

	private const GLOBAL_STYLES_ID = '17';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		WordPressTestState::$capabilities    = [
			'edit_theme_options' => true,
			'edit_posts'         => true,
		];
		Repository::install();
		$this->seed_global_styles_post( [ 'settings' => [], 'styles' => [] ] );
		WordPressTestState::$global_settings = [
			'color' => [
				'palette'    => [
					'theme' => [
						[
							'slug'  => 'accent',
							'name'  => 'Accent',
							'color' => '#111111',
						],
						[
							'slug'  => 'base',
							'name'  => 'Base',
							'color' => '#fefefe',
						],
					],
				],
				'background' => true,
				'text'       => true,
			],
		];
		// A resolvable merged background complement so a solo text-color
		// operation can pass the executor's contrast check at approval time.
		WordPressTestState::$global_styles = [
			'color' => [ 'background' => '#fefefe' ],
		];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function seed_global_styles_post( array $config ): void {
		WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ] = new \WP_Post(
			[
				'ID'           => (int) self::GLOBAL_STYLES_ID,
				'post_type'    => 'wp_global_styles',
				'post_content' => (string) wp_json_encode(
					array_merge(
						[
							'version'                     => 3,
							'isGlobalStylesUserThemeJSON' => true,
						],
						$config
					)
				),
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function agent_request_input( array $overrides = [] ): array {
		$scope         = [
			'surface'        => 'global-styles',
			'globalStylesId' => self::GLOBAL_STYLES_ID,
		];
		$style_context = [
			'currentConfig' => [
				'settings' => [],
				'styles'   => [],
			],
			'mergedConfig'  => [
				'settings' => [],
				'styles'   => [],
			],
		];
		$signatures    = StyleAbilities::recommend_style(
			[
				'scope'                => $scope,
				'styleContext'         => $style_context,
				'prompt'               => 'darker',
				'resolveSignatureOnly' => true,
			]
		);
		$this->assertIsArray( $signatures );

		return array_replace_recursive(
			[
				'scope'            => $scope,
				'styleContext'     => $style_context,
				'prompt'           => 'darker',
				'operations'       => [
					[
						'type'       => 'set_styles',
						'path'       => [ 'color', 'text' ],
						'value'      => 'var:preset|color|accent',
						'valueType'  => 'preset',
						'presetType' => 'color',
						'presetSlug' => 'accent',
					],
				],
				'signatures'       => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
				'suggestion'       => [ 'label' => 'Use the accent text preset' ],
				'requestReference' => 'agent-req-1',
			],
			$overrides
		);
	}

	public function test_request_style_apply_creates_a_pending_row_with_lifecycle_payload(): void {
		$result = ApplyAbilities::request_style_apply( $this->agent_request_input() );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertNotSame( '', (string) $result['activityId'] );
		$this->assertNotSame( '', (string) $result['expiresAt'] );

		$entry = Repository::find( (string) $result['activityId'] );
		$this->assertIsArray( $entry );
		$this->assertSame( 'apply_global_styles_suggestion', $entry['type'] );
		$this->assertSame( 'global-styles', $entry['surface'] );
		$this->assertSame( 'pending', $entry['executionResult'] );
		$this->assertSame( 'not_applicable', $entry['undo']['status'] );
		$this->assertSame( [], $entry['before'] );
		$this->assertSame( 7, $entry['apply']['requestedBy'] );
		$this->assertSame( 'agent-req-1', $entry['apply']['requestReference'] );
		$this->assertSame( 'global_styles:17', $entry['document']['scopeKey'] );
		$this->assertCount( 1, $entry['apply']['operations'] );
		$this->assertSame( 64, strlen( (string) $entry['apply']['signatures']['baselineConfigHash'] ) );
	}

	public function test_request_style_apply_rejects_mismatched_signatures_and_records_stale_blocked(): void {
		$input                                            = $this->agent_request_input();
		$input['signatures']['resolvedContextSignature'] = str_repeat( 'f', 64 );

		$result = ApplyAbilities::request_style_apply( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );

		$diagnostics = Repository::query(
			[
				'scopeKey'           => 'global_styles:17',
				'includeDiagnostics' => true,
			]
		);
		$outcomes    = array_values(
			array_filter(
				$diagnostics,
				static fn ( array $entry ): bool => 'recommendation_outcome' === (string) ( $entry['type'] ?? '' )
			)
		);
		$this->assertCount( 1, $outcomes );
		$this->assertSame( 'stale_blocked', $outcomes[0]['after']['outcome']['event'] );
	}

	public function test_request_style_apply_rejects_when_the_live_entity_drifted_from_the_claimed_config(): void {
		$input = $this->agent_request_input();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#444444' ] ],
			]
		);

		$result = ApplyAbilities::request_style_apply( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );
	}

	public function test_request_style_apply_enforces_the_per_user_pending_cap(): void {
		add_filter( 'flavor_agent_external_apply_pending_cap', static fn(): int => 1 );

		$first = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $first );

		$second = ApplyAbilities::request_style_apply( $this->agent_request_input() );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'flavor_agent_apply_queue_full', $second->get_error_code() );
	}

	public function test_request_style_apply_rejects_operations_failing_the_live_contract_without_creating_a_row(): void {
		$result = ApplyAbilities::request_style_apply(
			$this->agent_request_input(
				[
					'operations' => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'text' ],
							'value'      => 'var:preset|color|nope',
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'nope',
						],
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame(
			[],
			Repository::query( [ 'scopeKey' => 'global_styles:17' ] ),
			'Invalid operations must not enqueue a pending row.'
		);
	}

	public function test_request_style_apply_requires_a_supported_scope(): void {
		$result = ApplyAbilities::request_style_apply(
			$this->agent_request_input( [ 'scope' => [ 'surface' => 'navigation' ] ] )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_style_scope', $result->get_error_code() );
	}
}
```

Note: `agent_request_input()` calls `recommend_style` in signature-only mode, which routes through the docs-grounding signature path. `StyleAbilitiesTest` already exercises `build_signature_payloads_for_tests()` in this harness, so the bootstrap supports it; if a docs transient/option needs seeding, copy the exact seed from `StyleAbilitiesTest::setUp()`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter ApplyAbilitiesTest`
Expected: FAIL — `Class "FlavorAgent\Abilities\ApplyAbilities" not found`.

- [ ] **Step 3: Implement the handler**

Create `inc/Abilities/ApplyAbilities.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Activity\RecommendationOutcome;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Apply\StyleApplyExecutor;
use FlavorAgent\LLM\StylePrompt;
use FlavorAgent\Support\NormalizesInput;

/**
 * Handlers for the external-apply abilities: request-style-apply,
 * get-activity, list-activity, undo-activity.
 */
final class ApplyAbilities {
	use NormalizesInput;

	private const PENDING_CAP_FILTER  = 'flavor_agent_external_apply_pending_cap';
	private const PENDING_TTL_FILTER  = 'flavor_agent_external_apply_pending_ttl';
	private const DEFAULT_PENDING_CAP = 10;

	public static function request_style_apply( mixed $input ): array|\WP_Error {
		$input             = self::normalize_map( $input );
		$scope             = self::normalize_map( $input['scope'] ?? [] );
		$style_context     = self::normalize_map( $input['styleContext'] ?? [] );
		$prompt            = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';
		$operations        = self::normalize_list( $input['operations'] ?? [] );
		$signatures        = self::normalize_map( $input['signatures'] ?? [] );
		$provided_resolved = sanitize_text_field( (string) ( $signatures['resolvedContextSignature'] ?? '' ) );
		$provided_review   = sanitize_text_field( (string) ( $signatures['reviewContextSignature'] ?? '' ) );
		$surface           = sanitize_key( (string) ( $scope['surface'] ?? '' ) );
		$global_styles_id  = sanitize_text_field( (string) ( $scope['globalStylesId'] ?? '' ) );
		$block_name        = sanitize_text_field( (string) ( $scope['blockName'] ?? '' ) );

		if ( ! in_array( $surface, [ 'global-styles', 'style-book' ], true ) || '' === $global_styles_id ) {
			return new \WP_Error(
				'invalid_style_scope',
				'External style applies require a global-styles or style-book scope with a Global Styles entity id.',
				[ 'status' => 400 ]
			);
		}

		if ( 'style-book' === $surface && '' === $block_name ) {
			return new \WP_Error(
				'invalid_style_scope',
				'External Style Book applies require a target block name.',
				[ 'status' => 400 ]
			);
		}

		if ( [] === $operations ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'External style applies require at least one executable operation.',
				[ 'status' => 400 ]
			);
		}

		if ( '' === $provided_resolved || '' === $provided_review ) {
			return new \WP_Error(
				'flavor_agent_apply_stale',
				'External style applies require the resolved and review context signatures from the recommendation response.',
				[ 'status' => 409 ]
			);
		}

		// First freshness gate: recompute both signatures from the provided
		// request input through the same signature-only path the editor uses.
		$signature_probe = StyleAbilities::recommend_style(
			[
				'scope'                => $scope,
				'styleContext'         => $style_context,
				'prompt'               => $prompt,
				'resolveSignatureOnly' => true,
			]
		);

		if ( is_wp_error( $signature_probe ) ) {
			return $signature_probe;
		}

		$recomputed_resolved = (string) ( $signature_probe['resolvedContextSignature'] ?? '' );
		$recomputed_review   = (string) ( $signature_probe['reviewContextSignature'] ?? '' );

		if (
			! hash_equals( $recomputed_resolved, $provided_resolved )
			|| ! hash_equals( $recomputed_review, $provided_review )
		) {
			self::persist_stale_blocked_outcome( $surface, $global_styles_id, $block_name, $provided_resolved, 'external_apply_signature_stale' );

			return self::stale_error();
		}

		// Second freshness gate: the claimed current config must equal the live entity.
		$resolved_entity = StyleApplyExecutor::resolve_user_global_styles( $global_styles_id );

		if ( is_wp_error( $resolved_entity ) ) {
			return $resolved_entity;
		}

		$claimed_config = self::normalize_map( $style_context['currentConfig'] ?? [] );

		if ( StyleApplyExecutor::comparable_config( $claimed_config ) !== StyleApplyExecutor::comparable_config( $resolved_entity['config'] ) ) {
			self::persist_stale_blocked_outcome( $surface, $global_styles_id, $block_name, $provided_resolved, 'external_apply_config_drift' );

			return self::stale_error();
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

		$validation_context = StyleApplyExecutor::build_validation_context( $surface, $block_name );

		if ( is_wp_error( $validation_context ) ) {
			return $validation_context;
		}

		$validated = StylePrompt::validate_operations_for_apply( $operations, $validation_context );

		if ( [] === $validated['operations'] || count( $validated['operations'] ) !== count( $operations ) ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'One or more proposed style operations failed validation against the current execution contract.',
				[
					'status'            => 400,
					'validationReasons' => $validated['reasons'],
				]
			);
		}

		$suggestion        = self::normalize_map( $input['suggestion'] ?? [] );
		$timestamp         = gmdate( 'c' );
		$day_in_seconds    = defined( 'DAY_IN_SECONDS' ) ? \DAY_IN_SECONDS : 86400;
		$ttl               = max( 60, (int) apply_filters( self::PENDING_TTL_FILTER, $day_in_seconds ) );
		$expires_at        = gmdate( 'c', time() + $ttl );
		$scope_key         = StyleAbilities::canonical_scope_key_for( $surface, $global_styles_id, $block_name );
		$request_reference = sanitize_text_field( (string) ( $input['requestReference'] ?? '' ) );

		$created = ActivityRepository::create(
			[
				'type'            => 'style-book' === $surface ? 'apply_style_book_suggestion' : 'apply_global_styles_suggestion',
				'surface'         => $surface,
				'target'          => 'style-book' === $surface
					? [
						'globalStylesId' => $global_styles_id,
						'blockName'      => $block_name,
						'blockTitle'     => sanitize_text_field( (string) ( $scope['blockTitle'] ?? '' ) ),
					]
					: [ 'globalStylesId' => $global_styles_id ],
				'suggestion'      => sanitize_text_field( (string) ( $suggestion['label'] ?? 'External style apply request' ) ),
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'timestamp'       => $timestamp,
				'request'         => [
					'prompt'      => $prompt,
					'reference'   => '' !== $request_reference ? $request_reference : 'external-apply:' . $scope_key,
					'requestMeta' => [
						'ability'            => 'flavor-agent/request-style-apply',
						'executionTransport' => 'wp-abilities',
						'route'              => 'wp-abilities:flavor-agent/request-style-apply',
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
							'baselineConfigHash'       => StyleApplyExecutor::comparable_config_hash( $resolved_entity['config'] ),
						],
						'requestReference' => $request_reference,
					],
				],
				'document'        => [
					'scopeKey'   => $scope_key,
					'postType'   => 'global_styles',
					'entityId'   => $global_styles_id,
					'entityKind' => 'style-book' === $surface ? 'block' : 'root',
					'entityName' => 'style-book' === $surface ? 'styleBook' : 'globalStyles',
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

	private static function stale_error(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_stale',
			'The style recommendation context is stale. Re-run flavor-agent/recommend-style and request the apply again with fresh signatures.',
			[ 'status' => 409 ]
		);
	}

	private static function persist_stale_blocked_outcome( string $surface, string $global_styles_id, string $block_name, string $source_signature, string $reason ): void {
		$created = ActivityRepository::create(
			[
				'type'     => RecommendationOutcome::TYPE,
				'surface'  => $surface,
				'target'   => 'style-book' === $surface ? [ 'blockName' => $block_name ] : [],
				'after'    => [
					'outcome' => [
						'event'                  => 'stale_blocked',
						'reason'                 => $reason,
						'sourceRequestSignature' => $source_signature,
					],
				],
				'document' => [
					'scopeKey' => StyleAbilities::canonical_scope_key_for( $surface, $global_styles_id, $block_name ),
					'postType' => 'global_styles',
					'entityId' => $global_styles_id,
				],
			]
		);

		unset( $created ); // Diagnostic persistence is best-effort, mirroring the editor.
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ApplyAbilitiesTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add inc/Abilities/ApplyAbilities.php tests/phpunit/ApplyAbilitiesTest.php
git commit -m "Add request-style-apply handler with double freshness gate and pending queue"
```

---

### Task 7: `get_activity` and `list_activity` handlers + Permissions seam

**Files:**
- Modify: `inc/Abilities/ApplyAbilities.php`
- Modify: `inc/Activity/Permissions.php`
- Test: `tests/phpunit/ApplyAbilitiesTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/phpunit/ApplyAbilitiesTest.php`:

```php
	public function test_get_activity_returns_the_entry_and_lazily_expires_overdue_pending_rows(): void {
		$result = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $result );

		$fetched = ApplyAbilities::get_activity( [ 'activityId' => (string) $result['activityId'] ] );
		$this->assertIsArray( $fetched );
		$this->assertSame( 'pending', $fetched['entry']['apply']['status'] );

		// An overdue pending row (seeded directly, past its expiresAt) must
		// lazily expire on read — the agent observes 'expired', persisted.
		$overdue = Repository::create(
			[
				'type'            => 'apply_global_styles_suggestion',
				'surface'         => 'global-styles',
				'target'          => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
				'suggestion'      => 'Overdue request',
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'request'         => [
					'apply' => [
						'status'    => 'pending',
						'expiresAt' => gmdate( 'c', time() - 60 ),
					],
				],
				'document'        => [ 'scopeKey' => 'global_styles:17' ],
			]
		);
		$this->assertIsArray( $overdue );

		$expired = ApplyAbilities::get_activity( [ 'activityId' => (string) $overdue['id'] ] );
		$this->assertSame( 'expired', $expired['entry']['apply']['status'] );
		$this->assertSame(
			'expired',
			Repository::find( (string) $overdue['id'] )['apply']['status'],
			'Lazy expiry must persist.'
		);
	}

	public function test_get_activity_returns_not_found_for_unknown_ids(): void {
		$result = ApplyAbilities::get_activity( [ 'activityId' => 'missing' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_not_found', $result->get_error_code() );
	}

	public function test_list_activity_requires_a_scope_key(): void {
		$result = ApplyAbilities::list_activity( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_invalid_entry', $result->get_error_code() );
	}

	public function test_list_activity_filters_by_scope_and_status(): void {
		$first = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $first );
		$second = ApplyAbilities::request_style_apply(
			$this->agent_request_input( [ 'requestReference' => 'agent-req-2' ] )
		);
		$this->assertIsArray( $second );
		\FlavorAgent\Activity\Repository::transition_external_apply(
			(string) $second['activityId'],
			[ 'applyStatus' => 'rejected' ]
		);

		$pending = ApplyAbilities::list_activity(
			[
				'scopeKey' => 'global_styles:17',
				'status'   => 'pending',
			]
		);
		$this->assertIsArray( $pending );
		$this->assertCount( 1, $pending['entries'] );
		$this->assertSame( (string) $first['activityId'], $pending['entries'][0]['id'] );

		$rejected = ApplyAbilities::list_activity(
			[
				'scopeKey' => 'global_styles:17',
				'status'   => 'rejected',
			]
		);
		$this->assertCount( 1, $rejected['entries'] );

		$all = ApplyAbilities::list_activity( [ 'scopeKey' => 'global_styles:17' ] );
		$this->assertCount( 2, $all['entries'] );
	}
```

Append a Permissions test to `tests/phpunit/ActivityPermissionsTest.php` (inside the existing class — mirror its `WordPressTestState::$capabilities` setup conventions):

```php
	public function test_can_access_context_values_requires_theme_capability_for_style_scopes(): void {
		WordPressTestState::$capabilities = [ 'edit_posts' => true ];

		$this->assertFalse(
			Permissions::can_access_context_values( 'global_styles:17', 'global-styles' )
		);

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];

		$this->assertTrue(
			Permissions::can_access_context_values( 'global_styles:17', 'global-styles' )
		);
		$this->assertTrue(
			Permissions::can_access_context_values( 'style_book:17:core/paragraph', 'style-book' )
		);
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter "ApplyAbilitiesTest|ActivityPermissionsTest"`
Expected: FAIL — undefined methods `get_activity` / `list_activity` / `can_access_context_values`.

- [ ] **Step 3: Implement**

3a. In `inc/Activity/Permissions.php`, add after `can_access_entry()`:

```php
	/**
	 * Contextual capability check from raw scope values, for ability callers
	 * that have no WP_REST_Request to hand.
	 */
	public static function can_access_context_values(
		string $scope_key,
		string $surface = '',
		string $entity_type = '',
		string $post_type = '',
		string $entity_id = ''
	): bool {
		$context = self::resolve_canonical_context(
			$scope_key,
			$surface,
			$entity_type,
			$post_type,
			$entity_id
		);

		return self::can_access_context(
			[
				'scopeKey'     => $scope_key,
				'surface'      => $surface,
				'entityType'   => $entity_type,
				'postType'     => $context['postType'],
				'entityId'     => $context['entityId'],
				'contextValid' => $context['contextValid'],
			]
		);
	}
```

3b. In `inc/Abilities/ApplyAbilities.php`, add after `request_style_apply()`:

```php
	public static function get_activity( mixed $input ): array|\WP_Error {
		$input       = self::normalize_map( $input );
		$activity_id = sanitize_text_field( (string) ( $input['activityId'] ?? '' ) );

		if ( '' === $activity_id ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_entry',
				'getActivity requires an activityId.',
				[ 'status' => 400 ]
			);
		}

		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_found',
				'Flavor Agent could not find that activity entry.',
				[ 'status' => 404 ]
			);
		}

		return [ 'entry' => ActivityRepository::maybe_expire_pending_apply( $entry ) ];
	}

	public static function list_activity( mixed $input ): array|\WP_Error {
		$input     = self::normalize_map( $input );
		$scope_key = sanitize_text_field( (string) ( $input['scopeKey'] ?? '' ) );

		if ( '' === $scope_key ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_entry',
				'listActivity requires a scopeKey; admin-global reads stay on the REST activity route.',
				[ 'status' => 400 ]
			);
		}

		$status  = sanitize_key( (string) ( $input['status'] ?? '' ) );
		$entries = ActivityRepository::query(
			[
				'scopeKey' => $scope_key,
				'surface'  => sanitize_key( (string) ( $input['surface'] ?? '' ) ),
				'limit'    => $input['limit'] ?? ActivityRepository::DEFAULT_PER_PAGE,
			]
		);
		$entries = array_map( [ ActivityRepository::class, 'maybe_expire_pending_apply' ], $entries );

		if ( '' !== $status ) {
			$entries = array_values(
				array_filter(
					$entries,
					static fn ( array $entry ): bool => self::entry_matches_status( $entry, $status )
				)
			);
		}

		return [ 'entries' => $entries ];
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function entry_matches_status( array $entry, string $status ): bool {
		$execution_result = (string) ( $entry['executionResult'] ?? '' );
		$undo_status      = (string) ( $entry['undo']['status'] ?? '' );

		return match ( $status ) {
			'pending', 'rejected', 'expired' => $status === $execution_result,
			'failed' => 'failed' === $execution_result || 'failed' === $undo_status,
			'undone' => 'undone' === $undo_status,
			'applied' => 'applied' === $execution_result && ! in_array( $undo_status, [ 'undone', 'failed' ], true ),
			default => true,
		};
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter "ApplyAbilitiesTest|ActivityPermissionsTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add inc/Abilities/ApplyAbilities.php inc/Activity/Permissions.php tests/phpunit/ApplyAbilitiesTest.php tests/phpunit/ActivityPermissionsTest.php
git commit -m "Add scoped get/list activity handlers for external agents"
```

---

### Task 8: `undo_activity` handler

Server-side undo for executed style rows (external- AND editor-created), with ordered-undo enforcement, idempotent already-undone reporting, persisted drift failure, and non-executed-row rejection.

**Files:**
- Modify: `inc/Abilities/ApplyAbilities.php`
- Test: `tests/phpunit/ApplyAbilitiesTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/phpunit/ApplyAbilitiesTest.php`:

```php
	/**
	 * Persist an executed editor-shaped Global Styles row whose snapshots
	 * match the seeded entity state.
	 *
	 * @return array<string, mixed>
	 */
	private function create_executed_style_row(): array {
		$created = \FlavorAgent\Activity\Repository::create(
			[
				'type'       => 'apply_global_styles_suggestion',
				'surface'    => 'global-styles',
				'target'     => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
				'suggestion' => 'Accent text',
				'before'     => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
				'after'      => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
					'operations' => [],
				],
				'undo'       => [ 'status' => 'available' ],
				'document'   => [ 'scopeKey' => 'global_styles:17' ],
			]
		);
		$this->assertIsArray( $created );

		return $created;
	}

	public function test_undo_activity_restores_the_entity_and_persists_undone(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( 'undone', $result['entry']['undo']['status'] );

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( [], $written['styles'] );
	}

	public function test_undo_activity_persists_failed_on_drift(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#999999' ] ],
			]
		);

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['result'] );
		$this->assertSame( 'failed', $result['entry']['undo']['status'] );
		$this->assertNotSame( '', (string) $result['entry']['undo']['error'] );
	}

	public function test_undo_activity_reports_persisted_undone_rows_idempotently_without_rewrite(): void {
		$row = $this->create_executed_style_row();
		\FlavorAgent\Activity\Repository::update_undo_status( (string) $row['id'], 'undone' );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'already_undone', $result['result'] );
		$this->assertSame( 'undone', $result['entry']['undo']['status'] );
	}

	public function test_undo_activity_rejects_non_executed_rows(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $pending['activityId'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_not_undoable', $result->get_error_code() );
	}

	public function test_undo_activity_enforces_the_ordered_undo_rule(): void {
		$older = $this->create_executed_style_row();
		$newer = $this->create_executed_style_row();
		$this->assertNotSame( $older['id'], $newer['id'] );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $older['id'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_undo_blocked', $result->get_error_code() );
	}

	public function test_undo_activity_rejects_unsupported_surfaces(): void {
		$created = \FlavorAgent\Activity\Repository::create(
			[
				'type'       => 'apply_suggestion',
				'surface'    => 'block',
				'target'     => [ 'blockName' => 'core/paragraph' ],
				'suggestion' => 'Block apply',
				'before'     => [ 'attributes' => [] ],
				'after'      => [ 'attributes' => [] ],
				'undo'       => [ 'status' => 'available' ],
				'document'   => [ 'scopeKey' => 'post:5' ],
			]
		);
		$this->assertIsArray( $created );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $created['id'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_surface_unsupported', $result->get_error_code() );
	}

	public function test_undo_activity_works_for_approved_external_rows(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );
		\FlavorAgent\Activity\Repository::transition_external_apply(
			(string) $pending['activityId'],
			[
				'applyStatus' => 'available',
				'executedAt'  => gmdate( 'c' ),
				'before'      => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
				'after'       => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
					'operations' => [],
				],
				'target'      => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
			]
		);
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $pending['activityId'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter ApplyAbilitiesTest`
Expected: FAIL — `Call to undefined method …::undo_activity()`.

- [ ] **Step 3: Implement `undo_activity()`**

Add to `inc/Abilities/ApplyAbilities.php` (after `list_activity()`):

```php
	public static function undo_activity( mixed $input ): array|\WP_Error {
		$input       = self::normalize_map( $input );
		$activity_id = sanitize_text_field( (string) ( $input['activityId'] ?? '' ) );

		if ( '' === $activity_id ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_entry',
				'undoActivity requires an activityId.',
				[ 'status' => 400 ]
			);
		}

		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_found',
				'Flavor Agent could not find that activity entry.',
				[ 'status' => 404 ]
			);
		}

		$entry   = ActivityRepository::maybe_expire_pending_apply( $entry );
		$surface = (string) ( $entry['surface'] ?? '' );

		if ( ! in_array( $surface, [ 'global-styles', 'style-book' ], true ) ) {
			return new \WP_Error(
				'flavor_agent_undo_surface_unsupported',
				'External undo currently supports Global Styles and Style Book activity rows.',
				[ 'status' => 400 ]
			);
		}

		if ( self::is_non_executed_apply_entry( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_undoable',
				'Pending, rejected, expired, and approval-failed external applies never executed and cannot be undone.',
				[ 'status' => 409 ]
			);
		}

		$undo_status = (string) ( $entry['undo']['status'] ?? '' );

		if ( 'undone' === $undo_status ) {
			// Idempotent success report without rewriting the terminal row.
			return [
				'entry'  => $entry,
				'result' => 'already_undone',
				'error'  => null,
			];
		}

		if ( 'available' !== $undo_status ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_undo_transition',
				'Flavor Agent only allows undo status changes from the available state.',
				[ 'status' => 409 ]
			);
		}

		if ( ! ActivityRepository::can_perform_ordered_undo( $activity_id ) ) {
			return new \WP_Error(
				'flavor_agent_activity_undo_blocked',
				'Undo blocked by newer AI actions.',
				[ 'status' => 409 ]
			);
		}

		$result = StyleApplyExecutor::undo( $entry );

		if ( is_wp_error( $result ) ) {
			if ( 'flavor_agent_undo_drift' === $result->get_error_code() ) {
				$failed = ActivityRepository::update_undo_status( $activity_id, 'failed', $result->get_error_message() );

				return [
					'entry'  => is_array( $failed ) ? $failed : $entry,
					'result' => 'failed',
					'error'  => $result->get_error_message(),
				];
			}

			return $result;
		}

		$updated = ActivityRepository::update_undo_status( $activity_id, 'undone' );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return [
			'entry'  => $updated,
			'result' => 'already_undone' === (string) ( $result['result'] ?? '' ) ? 'already_undone' : 'undone',
			'error'  => null,
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function is_non_executed_apply_entry( array $entry ): bool {
		$execution_result = (string) ( $entry['executionResult'] ?? '' );

		if ( in_array( $execution_result, [ 'pending', 'rejected', 'expired' ], true ) ) {
			return true;
		}

		return 'failed' === $execution_result
			&& '' === (string) ( $entry['apply']['executedAt'] ?? '' );
	}
```

(When the executor reports `already_undone` on a row that is still persisted `available` — a manual revert happened — ordered-undo eligibility was already verified, so persisting `undone` makes the row truthful; the response still reports `already_undone`.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ApplyAbilitiesTest`
Expected: PASS (13 tests).

- [ ] **Step 5: Commit**

```bash
git add inc/Abilities/ApplyAbilities.php tests/phpunit/ApplyAbilitiesTest.php
git commit -m "Add server-side undo-activity handler with ordered-undo and drift persistence"
```

---

### Task 9: Approval decision service + REST decision route

Approval is deliberately NOT an ability — it is an admin REST action: `POST /flavor-agent/v1/activity/{id}/decision` requiring `manage_options` AND the row's mutation capability.

**Files:**
- Create: `inc/Apply/PendingApplyDecision.php`
- Modify: `inc/REST/Agent_Controller.php`
- Modify: `inc/Activity/Permissions.php`
- Test: `tests/phpunit/ApplyAbilitiesTest.php` (service), `tests/phpunit/AgentRoutesTest.php` (route)

- [ ] **Step 1: Write the failing service tests**

Append to `tests/phpunit/ApplyAbilitiesTest.php`:

```php
	public function test_decision_approve_executes_and_transitions_to_available(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'approve',
			'Reviewed and safe'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'available', $entry['apply']['status'] );
		$this->assertSame( 'applied', $entry['executionResult'] );
		$this->assertSame( 'available', $entry['undo']['status'] );
		$this->assertSame( 'Reviewed and safe', $entry['apply']['decisionNote'] );
		$this->assertSame( 7, $entry['apply']['decidedBy'] );
		$this->assertNotSame( '', (string) $entry['apply']['executedAt'] );
		$this->assertSame(
			'var:preset|color|accent',
			$entry['after']['userConfig']['styles']['color']['text']
		);

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( 'var:preset|color|accent', $written['styles']['color']['text'] );
	}

	public function test_decision_approve_fails_closed_when_the_entity_drifted_after_the_request(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		// Simulate a Site Editor session changing Global Styles before approval.
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#abcdef' ] ],
			]
		);

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'approve'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'failed', $entry['apply']['status'] );
		$this->assertSame( 'failed', $entry['executionResult'] );
		$this->assertSame( 'flavor_agent_apply_stale', $entry['apply']['failureCode'] );
		$this->assertSame( 'not_applicable', $entry['undo']['status'] );
	}

	public function test_decision_reject_records_provenance_without_executing(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'reject',
			'Not aligned with brand'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'rejected', $entry['apply']['status'] );
		$this->assertSame( 'Not aligned with brand', $entry['apply']['decisionNote'] );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'Reject must not write the entity.' );
	}

	public function test_decision_rejects_non_pending_rows_and_expired_rows(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );
		\FlavorAgent\Apply\PendingApplyDecision::decide( (string) $pending['activityId'], 'reject' );

		$again = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $pending['activityId'], 'approve' );
		$this->assertInstanceOf( \WP_Error::class, $again );
		$this->assertSame( 'flavor_agent_apply_not_pending', $again->get_error_code() );

		$expired = ApplyAbilities::request_style_apply(
			$this->agent_request_input( [ 'requestReference' => 'agent-req-3' ] )
		);
		$this->assertIsArray( $expired );
		\FlavorAgent\Activity\Repository::transition_external_apply(
			(string) $expired['activityId'],
			[ 'applyStatus' => 'expired' ]
		);
		$late = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $expired['activityId'], 'approve' );
		$this->assertInstanceOf( \WP_Error::class, $late );
		$this->assertSame( 'flavor_agent_apply_expired', $late->get_error_code() );
	}

	public function test_decision_validates_the_decision_value(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$result = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $pending['activityId'], 'maybe' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_invalid_decision', $result->get_error_code() );
	}
```

- [ ] **Step 2: Write the failing route tests**

In `tests/phpunit/AgentRoutesTest.php`:

2a. In `test_register_routes_exposes_the_expected_contract()`, the expected sorted route list gains one entry:

```php
		$this->assertSame(
			[
				'/flavor-agent/v1/activity',
				'/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/decision',
				'/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/undo',
				'/flavor-agent/v1/sync-patterns',
			],
			$registered_routes
		);
```

and add below the other `assertRouteMethods` calls:

```php
		$this->assertRouteMethods( '/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/decision', [ 'POST' ] );
```

2b. Append a permission test (mirror this file's existing `dispatch_route`/`assertForbidden` helpers):

```php
	public function test_decision_route_requires_manage_options_and_the_row_capability(): void {
		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertForbidden(
			$this->dispatch_route(
				'POST',
				'/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/decision',
				[
					'id'       => 'any-row',
					'decision' => 'reject',
				]
			)
		);

		WordPressTestState::$capabilities = [ 'manage_options' => true ];
		$response = $this->dispatch_route(
			'POST',
			'/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/decision',
			[
				'id'       => 'missing-row',
				'decision' => 'reject',
			]
		);
		$this->assertNotForbidden( $response );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'flavor_agent_activity_not_found', $response->get_error_code() );
	}
```

(If `dispatch_route` takes parameters differently in this file, follow its existing call sites — e.g. how the undo-route tests pass `id`. Keep the assertions identical.)

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter "ApplyAbilitiesTest|AgentRoutesTest"`
Expected: FAIL — `Class "FlavorAgent\Apply\PendingApplyDecision" not found` and route-list mismatch.

- [ ] **Step 4: Implement the decision service**

Create `inc/Apply/PendingApplyDecision.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Activity\Repository as ActivityRepository;

/**
 * Admin approval service for pending external applies. The human gate:
 * re-checks freshness against the live entity, executes through the style
 * executor on approve, and persists the one-row transition either way.
 */
final class PendingApplyDecision {

	/**
	 * @return array<string, mixed>|\WP_Error The transitioned activity entry.
	 */
	public static function decide( string $activity_id, string $decision, string $note = '' ): array|\WP_Error {
		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_found',
				'Flavor Agent could not find that activity entry.',
				[ 'status' => 404 ]
			);
		}

		$entry  = ActivityRepository::maybe_expire_pending_apply( $entry );
		$apply  = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
		$status = (string) ( $apply['status'] ?? '' );

		if ( 'expired' === $status ) {
			return new \WP_Error(
				'flavor_agent_apply_expired',
				'This external apply request expired before a decision was recorded.',
				[ 'status' => 410 ]
			);
		}

		if ( 'pending' !== $status ) {
			return new \WP_Error(
				'flavor_agent_apply_not_pending',
				'Only pending external applies accept decisions.',
				[ 'status' => 409 ]
			);
		}

		if ( ! in_array( $decision, [ 'approve', 'reject' ], true ) ) {
			return new \WP_Error(
				'flavor_agent_apply_invalid_decision',
				'External apply decisions must be approve or reject.',
				[ 'status' => 400 ]
			);
		}

		$decided_by = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$decided_at = gmdate( 'c' );
		$note       = sanitize_textarea_field( $note );

		if ( 'reject' === $decision ) {
			return ActivityRepository::transition_external_apply(
				$activity_id,
				[
					'applyStatus'  => 'rejected',
					'decidedBy'    => $decided_by,
					'decidedAt'    => $decided_at,
					'decisionNote' => $note,
				]
			);
		}

		$surface          = (string) ( $entry['surface'] ?? '' );
		$target           = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
		$global_styles_id = (string) ( $target['globalStylesId'] ?? '' );
		$block_name       = (string) ( $target['blockName'] ?? '' );
		$signatures       = is_array( $apply['signatures'] ?? null ) ? $apply['signatures'] : [];
		$baseline         = (string) ( $signatures['baselineConfigHash'] ?? '' );

		// Second freshness check: the live entity must still match the
		// baseline recorded at request time. Drift fails closed.
		$resolved     = StyleApplyExecutor::resolve_user_global_styles( $global_styles_id );
		$stale_reason = '';

		if ( is_wp_error( $resolved ) ) {
			$stale_reason = $resolved->get_error_message();
		} elseif (
			'' === $baseline
			|| ! hash_equals( StyleApplyExecutor::comparable_config_hash( $resolved['config'] ), $baseline )
		) {
			$stale_reason = 'The Global Styles entity changed after this apply was requested.';
		}

		if ( '' !== $stale_reason ) {
			return ActivityRepository::transition_external_apply(
				$activity_id,
				[
					'applyStatus'    => 'failed',
					'decidedBy'      => $decided_by,
					'decidedAt'      => $decided_at,
					'decisionNote'   => $note,
					'failureCode'    => 'flavor_agent_apply_stale',
					'failureMessage' => $stale_reason,
				]
			);
		}

		$operations = is_array( $apply['operations'] ?? null ) ? $apply['operations'] : [];
		$result     = StyleApplyExecutor::apply( $surface, $global_styles_id, $operations, $block_name );

		if ( is_wp_error( $result ) ) {
			return ActivityRepository::transition_external_apply(
				$activity_id,
				[
					'applyStatus'    => 'failed',
					'decidedBy'      => $decided_by,
					'decidedAt'      => $decided_at,
					'decisionNote'   => $note,
					'failureCode'    => (string) $result->get_error_code(),
					'failureMessage' => (string) $result->get_error_message(),
				]
			);
		}

		return ActivityRepository::transition_external_apply(
			$activity_id,
			[
				'applyStatus'  => 'available',
				'decidedBy'    => $decided_by,
				'decidedAt'    => $decided_at,
				'decisionNote' => $note,
				'executedAt'   => gmdate( 'c' ),
				'before'       => $result['before'],
				'after'        => $result['after'],
				'target'       => $result['target'],
			]
		);
	}
}
```

- [ ] **Step 5: Implement the route + permission**

5a. In `inc/Activity/Permissions.php`, add after `can_access_activity_request()`:

```php
	/**
	 * Decision route gate: page-level manage_options AND the row's own
	 * contextual mutation capability (edit_theme_options for style rows).
	 */
	public static function can_decide_activity_request( \WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$activity_id = trim( (string) $request->get_param( 'id' ) );

		if ( '' === $activity_id ) {
			return false;
		}

		$entry = Repository::find( $activity_id );

		// Missing rows pass the capability gate so the handler returns its 404.
		return ! is_array( $entry ) || self::can_access_entry( $entry );
	}
```

5b. In `inc/REST/Agent_Controller.php`:

Add `use FlavorAgent\Apply\PendingApplyDecision;` to the imports. In `register_routes()`, after the undo route registration, add:

```php
		\register_rest_route(
			self::NAMESPACE,
			'/activity/(?P<id>[A-Za-z0-9._:-]+)/decision',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_activity_decision' ],
				'permission_callback' => [ ActivityPermissions::class, 'can_decide_activity_request' ],
				'args'                => [
					'id'       => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'decision' => [
						'required'          => true,
						'type'              => 'string',
						'enum'              => [ 'approve', 'reject' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'note'     => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);
```

And add the handler after `handle_update_activity_undo()`:

```php
	public static function handle_activity_decision( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_decide_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$result = PendingApplyDecision::decide(
			(string) $request->get_param( 'id' ),
			(string) $request->get_param( 'decision' ),
			(string) ( $request->get_param( 'note' ) ?? '' )
		);

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( [ 'entry' => $result ], 200 );
	}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter "ApplyAbilitiesTest|AgentRoutesTest"`
Expected: PASS.
Run the full suite: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add inc/Apply/PendingApplyDecision.php inc/REST/Agent_Controller.php inc/Activity/Permissions.php tests/phpunit/ApplyAbilitiesTest.php tests/phpunit/AgentRoutesTest.php
git commit -m "Add admin decision route that approves or rejects pending external applies"
```

---

### Task 10: Ability classes, registration, schemas, and feature gating (25 → 29)

**Files:**
- Create: `inc/AI/Abilities/RequestStyleApplyAbility.php`, `inc/AI/Abilities/GetActivityAbility.php`, `inc/AI/Abilities/ListActivityAbility.php`, `inc/AI/Abilities/UndoActivityAbility.php`
- Modify: `inc/Abilities/Registration.php`
- Modify: `inc/AI/FeatureBootstrap.php`
- Modify: `inc/Abilities/InfraAbilities.php`
- Test: `tests/phpunit/RegistrationTest.php`, `tests/phpunit/AbilitySchemaContractTest.php`, `tests/phpunit/InfraAbilitiesTest.php`

- [ ] **Step 1: Write the failing registration tests**

Append to `tests/phpunit/RegistrationTest.php` (follow the file's existing setup conventions — most tests enable the feature options and call the registration entry points, then inspect `WordPressTestState::$registered_abilities`):

```php
	public function test_external_apply_abilities_register_behind_the_feature_gate(): void {
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		\FlavorAgent\AI\FeatureBootstrap::register_global_helper_abilities();

		foreach ( [
			'flavor-agent/request-style-apply',
			'flavor-agent/get-activity',
			'flavor-agent/list-activity',
			'flavor-agent/undo-activity',
		] as $ability_id ) {
			$this->assertArrayHasKey(
				$ability_id,
				WordPressTestState::$registered_abilities,
				"{$ability_id} should register when the Flavor Agent feature is enabled."
			);
		}
	}

	public function test_external_apply_abilities_do_not_register_without_the_feature_gate(): void {
		WordPressTestState::$options = [];

		\FlavorAgent\AI\FeatureBootstrap::register_global_helper_abilities();

		$this->assertArrayNotHasKey(
			'flavor-agent/request-style-apply',
			WordPressTestState::$registered_abilities
		);
	}

	public function test_external_apply_abilities_are_never_mcp_public_and_declare_expected_annotations(): void {
		$expectations = [
			'flavor-agent/request-style-apply' => [
				'destructive' => false,
				'idempotent'  => false,
			],
			'flavor-agent/get-activity'        => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'flavor-agent/list-activity'       => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'flavor-agent/undo-activity'       => [
				'destructive' => true,
				'idempotent'  => false,
			],
		];

		foreach ( $expectations as $ability_id => $annotations ) {
			$meta = \FlavorAgent\Abilities\Registration::external_apply_meta( $ability_id );

			$this->assertTrue( $meta['show_in_rest'] );
			$this->assertArrayNotHasKey(
				'mcp',
				$meta,
				"{$ability_id} must stay off the universal MCP server — activity rows can carry prompts."
			);

			foreach ( $annotations as $key => $value ) {
				$this->assertSame( $value, $meta['annotations'][ $key ], "{$ability_id} annotation {$key}" );
			}
		}
	}
```

Append to `tests/phpunit/AbilitySchemaContractTest.php` — extend the ability list the draft-04 sweep covers. Locate the class constant listing ability ids (`RECOMMENDATION_ABILITIES`) and the test(s) that iterate it; add a sibling constant and include it in the iteration:

```php
	private const EXTERNAL_APPLY_ABILITIES = [
		'flavor-agent/request-style-apply',
		'flavor-agent/get-activity',
		'flavor-agent/list-activity',
		'flavor-agent/undo-activity',
	];
```

with a new test that runs the file's existing draft-04 keyword assertion helper over `Registration::external_apply_input_schema( $id )` and `Registration::external_apply_output_schema( $id )` for each id.

Append to `tests/phpunit/InfraAbilitiesTest.php` (mirror how the existing `availableAbilities` tests build `$status` via `InfraAbilities::check_status` with seeded capabilities):

```php
	public function test_check_status_advertises_external_apply_abilities_to_theme_capability_users(): void {
		WordPressTestState::$options      = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];
		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];

		$status = \FlavorAgent\Abilities\InfraAbilities::check_status( [] );

		$this->assertContains( 'flavor-agent/request-style-apply', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/undo-activity', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/get-activity', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/list-activity', $status['availableAbilities'] );
	}
```

Also extend `test_register_abilities_annotation_expectations_cover_every_registered_ability` in `RegistrationTest.php`: that test enumerates an expectations map for every registered ability — add the four new ids with the annotation sets from the table above (copy the map-entry shape used by its neighbors).

And add the permission matrix to `tests/phpunit/ApplyAbilitiesTest.php` (the ability classes are instantiable exactly like `PreviewRecommendationAbility::build_parent_instance()` does — `new <Class>( <Class>::ABILITY_NAME, [] )`; the bootstrap stubs `Abstract_Ability`):

```php
	public function test_request_style_apply_ability_requires_edit_theme_options(): void {
		$ability = new \FlavorAgent\AI\Abilities\RequestStyleApplyAbility(
			\FlavorAgent\AI\Abilities\RequestStyleApplyAbility::ABILITY_NAME,
			[]
		);

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertFalse( $ability->permission_callback( [] ) );

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue( $ability->permission_callback( [] ) );
	}

	public function test_undo_activity_ability_enforces_the_row_capability_contextually(): void {
		$row     = $this->create_executed_style_row();
		$ability = new \FlavorAgent\AI\Abilities\UndoActivityAbility(
			\FlavorAgent\AI\Abilities\UndoActivityAbility::ABILITY_NAME,
			[]
		);
		$input   = [ 'activityId' => (string) $row['id'] ];

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertFalse(
			$ability->permission_callback( $input ),
			'Style rows resolve to edit_theme_options through the contextual check.'
		);

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue( $ability->permission_callback( $input ) );
	}

	public function test_list_activity_ability_gates_on_the_scope_context(): void {
		$ability = new \FlavorAgent\AI\Abilities\ListActivityAbility(
			\FlavorAgent\AI\Abilities\ListActivityAbility::ABILITY_NAME,
			[]
		);

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertFalse(
			$ability->permission_callback( [ 'scopeKey' => 'global_styles:17' ] )
		);
		$this->assertFalse( $ability->permission_callback( [] ), 'A scopeKey is required.' );

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue(
			$ability->permission_callback( [ 'scopeKey' => 'global_styles:17' ] )
		);
	}
```

(These live in `ApplyAbilitiesTest` because they reuse its row fixtures; run them with this task.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter "RegistrationTest|AbilitySchemaContractTest|InfraAbilitiesTest"`
Expected: FAIL — undefined `Registration::external_apply_meta()`, missing registrations.

- [ ] **Step 3: Implement the four ability classes**

Create `inc/AI/Abilities/RequestStyleApplyAbility.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use WordPress\AI\Abstracts\Abstract_Ability;

final class RequestStyleApplyAbility extends Abstract_Ability {

	public const ABILITY_NAME = 'flavor-agent/request-style-apply';

	public function input_schema(): array {
		return Registration::external_apply_input_schema( self::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::external_apply_output_schema( self::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		return ApplyAbilities::request_style_apply( $input );
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

Create `inc/AI/Abilities/GetActivityAbility.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use FlavorAgent\Activity\Permissions;
use FlavorAgent\Activity\Repository;
use WordPress\AI\Abstracts\Abstract_Ability;

final class GetActivityAbility extends Abstract_Ability {

	public const ABILITY_NAME = 'flavor-agent/get-activity';

	public function input_schema(): array {
		return Registration::external_apply_input_schema( self::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::external_apply_output_schema( self::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		return ApplyAbilities::get_activity( $input );
	}

	public function permission_callback( mixed $input = null ): bool {
		$input       = \is_array( $input ) ? $input : ( \is_object( $input ) ? \get_object_vars( $input ) : [] );
		$activity_id = \sanitize_text_field( (string) ( $input['activityId'] ?? '' ) );
		$entry       = '' !== $activity_id ? Repository::find( $activity_id ) : null;

		if ( \is_array( $entry ) ) {
			return Permissions::can_access_entry( $entry );
		}

		// Missing rows pass the gate so execution can 404 without leaking
		// whether the id exists to capability-less callers.
		return \current_user_can( 'edit_posts' ) || \current_user_can( 'edit_theme_options' );
	}

	public function meta(): array {
		return Registration::external_apply_meta( self::ABILITY_NAME );
	}

	public function category(): string {
		return 'flavor-agent';
	}
}
```

Create `inc/AI/Abilities/ListActivityAbility.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use FlavorAgent\Activity\Permissions;
use WordPress\AI\Abstracts\Abstract_Ability;

final class ListActivityAbility extends Abstract_Ability {

	public const ABILITY_NAME = 'flavor-agent/list-activity';

	public function input_schema(): array {
		return Registration::external_apply_input_schema( self::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::external_apply_output_schema( self::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		return ApplyAbilities::list_activity( $input );
	}

	public function permission_callback( mixed $input = null ): bool {
		$input     = \is_array( $input ) ? $input : ( \is_object( $input ) ? \get_object_vars( $input ) : [] );
		$scope_key = \sanitize_text_field( (string) ( $input['scopeKey'] ?? '' ) );

		if ( '' === $scope_key ) {
			return false;
		}

		return Permissions::can_access_context_values(
			$scope_key,
			\sanitize_key( (string) ( $input['surface'] ?? '' ) )
		);
	}

	public function meta(): array {
		return Registration::external_apply_meta( self::ABILITY_NAME );
	}

	public function category(): string {
		return 'flavor-agent';
	}
}
```

Create `inc/AI/Abilities/UndoActivityAbility.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use FlavorAgent\Activity\Permissions;
use FlavorAgent\Activity\Repository;
use WordPress\AI\Abstracts\Abstract_Ability;

final class UndoActivityAbility extends Abstract_Ability {

	public const ABILITY_NAME = 'flavor-agent/undo-activity';

	public function input_schema(): array {
		return Registration::external_apply_input_schema( self::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::external_apply_output_schema( self::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		return ApplyAbilities::undo_activity( $input );
	}

	public function permission_callback( mixed $input = null ): bool {
		$input       = \is_array( $input ) ? $input : ( \is_object( $input ) ? \get_object_vars( $input ) : [] );
		$activity_id = \sanitize_text_field( (string) ( $input['activityId'] ?? '' ) );
		$entry       = '' !== $activity_id ? Repository::find( $activity_id ) : null;

		if ( \is_array( $entry ) ) {
			// Style rows resolve to edit_theme_options through the contextual check.
			return Permissions::can_access_entry( $entry );
		}

		return \current_user_can( 'edit_posts' ) || \current_user_can( 'edit_theme_options' );
	}

	public function meta(): array {
		return Registration::external_apply_meta( self::ABILITY_NAME );
	}

	public function category(): string {
		return 'flavor-agent';
	}
}
```

- [ ] **Step 4: Implement registration, schemas, and meta**

In `inc/Abilities/Registration.php`:

4a. Add the four `use` imports next to the existing ability-class imports:

```php
use FlavorAgent\AI\Abilities\GetActivityAbility;
use FlavorAgent\AI\Abilities\ListActivityAbility;
use FlavorAgent\AI\Abilities\RequestStyleApplyAbility;
use FlavorAgent\AI\Abilities\UndoActivityAbility;
```

4b. After `recommendation_ability_classes()`, add:

```php
	/**
	 * External-apply abilities: the governed apply/read/undo loop for agents.
	 * Approval itself is deliberately NOT an ability — it is an admin REST action.
	 *
	 * @return array<string, array{label: string, description: string, ability_class: class-string}>
	 */
	public static function external_apply_ability_classes(): array {
		return [
			'flavor-agent/request-style-apply' => [
				'label'         => __( 'Request a governed style apply', 'flavor-agent' ),
				'description'   => __( 'Queue a reviewed Global Styles or Style Book apply from a recommend-style result. Validates operations and freshness signatures, then creates a pending approval row a site administrator decides in Settings > AI Activity. Mutates nothing until approved.', 'flavor-agent' ),
				'ability_class' => RequestStyleApplyAbility::class,
			],
			'flavor-agent/get-activity'        => [
				'label'         => __( 'Get one AI activity entry', 'flavor-agent' ),
				'description'   => __( 'Return a single Flavor Agent activity entry by id, including external-apply lifecycle status, decision provenance, and undo state. The polling read for agents awaiting an approval decision.', 'flavor-agent' ),
				'ability_class' => GetActivityAbility::class,
			],
			'flavor-agent/list-activity'       => [
				'label'         => __( 'List scoped AI activity', 'flavor-agent' ),
				'description'   => __( 'Return Flavor Agent activity entries for one scope key with optional surface and status filters. Admin-global reads stay on the REST activity route.', 'flavor-agent' ),
				'ability_class' => ListActivityAbility::class,
			],
			'flavor-agent/undo-activity'       => [
				'label'         => __( 'Undo an applied AI activity entry', 'flavor-agent' ),
				'description'   => __( 'Server-side undo of an executed Global Styles or Style Book activity row: enforces ordered undo, verifies the recorded after-state still matches the live entity, restores the before snapshot, and persists the one-way undone/failed transition.', 'flavor-agent' ),
				'ability_class' => UndoActivityAbility::class,
			],
		];
	}

	public static function register_external_apply_abilities(): void {
		if ( ! FeatureBootstrap::canonical_contracts_available() ) {
			return;
		}

		foreach ( self::external_apply_ability_classes() as $ability_id => $definition ) {
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
	 * @return array<string, mixed>
	 */
	public static function external_apply_meta( string $ability_id ): array {
		$annotations = match ( $ability_id ) {
			'flavor-agent/request-style-apply' => [
				'destructive' => false,
				'idempotent'  => false,
			],
			'flavor-agent/undo-activity' => [
				'destructive' => true,
				'idempotent'  => false,
			],
			default => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
		};

		$meta = [
			'show_in_rest' => true,
			'annotations'  => $annotations,
		];

		if ( ! empty( $annotations['readonly'] ) ) {
			$meta['readonly'] = true;
		}

		return $meta;
	}
```

4c. Add the schema builders (near `recommendation_input_schema()`); first extract the operation item schema from `style_recommendation_output_schema()` into a reusable private helper, then use it in both places:

```php
	/**
	 * @return array<string, mixed>
	 */
	private static function style_operation_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'type'           => [ 'type' => 'string' ],
				'blockName'      => [ 'type' => 'string' ],
				'path'           => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'value'          => [ 'type' => [ 'string', 'number', 'boolean', 'object', 'array', 'null' ] ],
				'valueType'      => [ 'type' => 'string' ],
				'presetType'     => [ 'type' => 'string' ],
				'presetSlug'     => [ 'type' => 'string' ],
				'cssVar'         => [ 'type' => 'string' ],
				'variationIndex' => [ 'type' => 'integer' ],
				'variationTitle' => [ 'type' => 'string' ],
			],
		];
	}
```

In `style_recommendation_output_schema()`, replace the inline `operations.items` object with `'items' => self::style_operation_schema(),`.

Then add:

```php
	/**
	 * @return array<string, mixed>
	 */
	public static function external_apply_input_schema( string $ability_id ): array {
		return match ( $ability_id ) {
			'flavor-agent/request-style-apply' => [
				'type'       => 'object',
				'properties' => [
					'scope'            => self::open_object_schema(
						[
							'surface'        => [ 'type' => 'string' ],
							'globalStylesId' => [ 'type' => 'string' ],
							'blockName'      => [ 'type' => 'string' ],
							'blockTitle'     => [ 'type' => 'string' ],
						],
						'Style surface scope: the same scope shape sent to recommend-style.'
					),
					'styleContext'     => self::open_object_schema(
						[
							'currentConfig'       => self::open_object_schema(),
							'mergedConfig'        => self::open_object_schema(),
							'availableVariations' => [
								'type'  => 'array',
								'items' => self::open_object_schema(),
							],
							'styleBookTarget'     => self::open_object_schema(),
						],
						'The same styleContext sent to recommend-style; used to recompute the freshness signatures.'
					),
					'prompt'           => [
						'type'        => 'string',
						'description' => 'The prompt sent to recommend-style, byte-identical, so the resolved signature recomputes.',
					],
					'operations'       => [
						'type'  => 'array',
						'items' => self::style_operation_schema(),
					],
					'signatures'       => [
						'type'       => 'object',
						'properties' => [
							'resolvedContextSignature' => [ 'type' => 'string' ],
							'reviewContextSignature'   => [ 'type' => 'string' ],
						],
						'required'   => [ 'resolvedContextSignature', 'reviewContextSignature' ],
					],
					'suggestion'       => self::open_object_schema(
						[
							'label'       => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
						],
						'Human-readable label shown to the approver.'
					),
					'requestReference' => [
						'type'        => 'string',
						'description' => 'Optional opaque agent-side reference echoed back on reads.',
					],
				],
				'required'   => [ 'scope', 'styleContext', 'operations', 'signatures' ],
			],
			'flavor-agent/get-activity' => [
				'type'       => 'object',
				'properties' => [
					'activityId' => [ 'type' => 'string' ],
				],
				'required'   => [ 'activityId' ],
			],
			'flavor-agent/list-activity' => [
				'type'       => 'object',
				'properties' => [
					'scopeKey' => [
						'type'        => 'string',
						'description' => 'Required activity scope key, e.g. global_styles:17.',
					],
					'surface'  => [ 'type' => 'string' ],
					'status'   => [
						'type' => 'string',
						'enum' => [ 'pending', 'applied', 'rejected', 'expired', 'failed', 'undone' ],
					],
					'limit'    => [ 'type' => 'integer' ],
				],
				'required'   => [ 'scopeKey' ],
			],
			'flavor-agent/undo-activity' => [
				'type'       => 'object',
				'properties' => [
					'activityId' => [ 'type' => 'string' ],
				],
				'required'   => [ 'activityId' ],
			],
			default => self::open_object_schema(),
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function external_apply_output_schema( string $ability_id ): array {
		return match ( $ability_id ) {
			'flavor-agent/request-style-apply' => [
				'type'       => 'object',
				'properties' => [
					'activityId'       => [ 'type' => 'string' ],
					'status'           => [ 'type' => 'string' ],
					'expiresAt'        => [ 'type' => 'string' ],
					'requestReference' => [ 'type' => 'string' ],
				],
			],
			'flavor-agent/get-activity' => [
				'type'       => 'object',
				'properties' => [
					'entry' => self::open_object_schema(),
				],
			],
			'flavor-agent/list-activity' => [
				'type'       => 'object',
				'properties' => [
					'entries' => [
						'type'  => 'array',
						'items' => self::open_object_schema(),
					],
				],
			],
			'flavor-agent/undo-activity' => [
				'type'       => 'object',
				'properties' => [
					'entry'  => self::open_object_schema(),
					'result' => [ 'type' => 'string' ],
					'error'  => [ 'type' => [ 'string', 'null' ] ],
				],
			],
			default => self::open_object_schema(),
		};
	}
```

- [ ] **Step 5: Wire the feature gate and check-status**

5a. In `inc/AI/FeatureBootstrap.php`, `register_global_helper_abilities()`:

```php
		if ( self::canonical_contracts_available() && self::recommendation_feature_enabled() ) {
			Registration::register_recommendation_abilities();
			Registration::register_external_apply_abilities();
		}
```

5b. In `inc/Abilities/InfraAbilities.php`, in `available_abilities()` (≈ line 119), after the `recommend-style` line, add:

```php
		$external_applies_available = \FlavorAgent\AI\FeatureBootstrap::recommendation_feature_enabled();
		self::maybe_add_ability( $abilities, 'flavor-agent/request-style-apply', 'edit_theme_options', $external_applies_available );
		self::maybe_add_ability( $abilities, 'flavor-agent/get-activity', 'edit_posts', $external_applies_available );
		self::maybe_add_ability( $abilities, 'flavor-agent/list-activity', 'edit_posts', $external_applies_available );
		self::maybe_add_ability( $abilities, 'flavor-agent/undo-activity', 'edit_theme_options', $external_applies_available );
```

(Use the file's existing import style for `FeatureBootstrap` if one exists; `get-activity`/`list-activity` advertise at `edit_posts` because their real gate is contextual per row/scope.)

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter "RegistrationTest|AbilitySchemaContractTest|InfraAbilitiesTest"`
Expected: PASS. If `test_register_abilities_annotation_expectations_cover_every_registered_ability` fails, it enumerates EVERY registered ability — add the four ids to its expectations map exactly as Step 1 described.
Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add inc/AI/Abilities/RequestStyleApplyAbility.php inc/AI/Abilities/GetActivityAbility.php inc/AI/Abilities/ListActivityAbility.php inc/AI/Abilities/UndoActivityAbility.php inc/Abilities/Registration.php inc/AI/FeatureBootstrap.php inc/Abilities/InfraAbilities.php tests/phpunit/RegistrationTest.php tests/phpunit/AbilitySchemaContractTest.php tests/phpunit/InfraAbilitiesTest.php
git commit -m "Register the four external-apply abilities behind the recommendation feature gate"
```

---

### Task 11: Dedicated MCP server roster (7 → 11 tools)

**Files:**
- Modify: `inc/MCP/ServerBootstrap.php`
- Test: `tests/phpunit/MCPServerBootstrapTest.php`

- [ ] **Step 1: Update the roster test**

In `tests/phpunit/MCPServerBootstrapTest.php`, `test_register_creates_dedicated_server_with_recommendation_tools()`, replace the roster assertions:

```php
		$this->assertContains( 'flavor-agent/recommend-block', $call[9] );
		$this->assertContains( 'flavor-agent/recommend-template', $call[9] );
		$this->assertContains( 'flavor-agent/recommend-style', $call[9] );
		$this->assertContains( 'flavor-agent/request-style-apply', $call[9] );
		$this->assertContains( 'flavor-agent/get-activity', $call[9] );
		$this->assertContains( 'flavor-agent/list-activity', $call[9] );
		$this->assertContains( 'flavor-agent/undo-activity', $call[9] );
		$this->assertCount( 11, $call[9] );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter MCPServerBootstrapTest`
Expected: FAIL — roster contains 7 tools, missing the apply ids.

- [ ] **Step 3: Implement**

In `inc/MCP/ServerBootstrap.php`, replace the roster argument in `create_server()`:

```php
			\array_merge(
				\array_keys( Registration::recommendation_ability_classes() ),
				\array_keys( Registration::external_apply_ability_classes() )
			),
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter MCPServerBootstrapTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add inc/MCP/ServerBootstrap.php tests/phpunit/MCPServerBootstrapTest.php
git commit -m "Expose external-apply abilities on the dedicated MCP server"
```

---

### Task 12: Admin activity-log utils — lifecycle vocabulary and decision helpers

**Files:**
- Modify: `src/admin/activity-log-utils.js`
- Test: `src/admin/__tests__/activity-log-utils.test.js`

- [ ] **Step 1: Write the failing tests**

Append to `src/admin/__tests__/activity-log-utils.test.js` (top-level `describe` blocks, following the file's import style — add the new names to the existing import from `../activity-log-utils`):

```js
describe( 'external apply helpers', () => {
	test( 'isPendingExternalApply requires pending status and an apply payload', () => {
		expect(
			isPendingExternalApply( {
				status: 'pending',
				apply: { status: 'pending' },
			} )
		).toBe( true );
		expect( isPendingExternalApply( { status: 'applied' } ) ).toBe( false );
		expect( isPendingExternalApply( { status: 'pending' } ) ).toBe( false );
		expect( isPendingExternalApply( null ) ).toBe( false );
	} );

	test( 'getExternalApplyDetails normalizes the lifecycle payload', () => {
		const details = getExternalApplyDetails( {
			apply: {
				status: 'pending',
				requestedBy: 7,
				requestedAt: '2026-06-10T01:00:00+00:00',
				expiresAt: '2026-06-11T01:00:00+00:00',
				operations: [ { type: 'set_styles', path: [ 'color', 'text' ] } ],
				requestReference: 'agent-req-1',
				decisionNote: 'note',
				failureCode: '',
			},
		} );

		expect( details.status ).toBe( 'pending' );
		expect( details.requestedBy ).toBe( 7 );
		expect( details.operations ).toHaveLength( 1 );
		expect( details.requestReference ).toBe( 'agent-req-1' );
		expect( getExternalApplyDetails( {} ).operations ).toEqual( [] );
	} );

	test( 'buildDecisionRequest shapes the REST call for apiFetch', () => {
		const request = buildDecisionRequest(
			{ restUrl: 'https://example.test/wp-json/', nonce: 'abc123' },
			'activity-9',
			'approve',
			'Looks safe'
		);

		expect( request.url ).toBe(
			'https://example.test/wp-json/flavor-agent/v1/activity/activity-9/decision'
		);
		expect( request.method ).toBe( 'POST' );
		expect( request.headers[ 'X-WP-Nonce' ] ).toBe( 'abc123' );
		expect( request.data ).toEqual( { decision: 'approve', note: 'Looks safe' } );
	} );

	test( 'status labels cover the external-apply lifecycle', () => {
		expect( getActivityStatusLabel( 'pending' ) ).toBe( 'Pending approval' );
		expect( getActivityStatusLabel( 'rejected' ) ).toBe( 'Rejected' );
		expect( getActivityStatusLabel( 'expired' ) ).toBe( 'Expired' );
	} );

	test( 'normalizeActivityEntries passes the apply payload through', () => {
		const [ normalized ] = normalizeActivityEntries(
			[
				{
					id: 'activity-9',
					surface: 'global-styles',
					status: 'pending',
					timestamp: '2026-06-10T01:00:00+00:00',
					apply: { status: 'pending', operations: [] },
				},
			],
			{}
		);

		expect( normalized.apply ).toEqual( { status: 'pending', operations: [] } );
	} );
} );
```

Note: if `getActivityStatusLabel` is not currently exported (it may be file-internal — check), export it; the existing internal call sites are unaffected.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js`
Expected: FAIL — `isPendingExternalApply is not a function` (and siblings).

- [ ] **Step 3: Implement the utils**

In `src/admin/activity-log-utils.js`:

3a. Locate `getActivityStatusLabel` (the status → label resolver used by entry normalization; grep `getActivityStatusLabel`). Add the three labels to its map/switch, and `export` the function if it isn't already:

```js
	pending: __( 'Pending approval', 'flavor-agent' ),
	rejected: __( 'Rejected', 'flavor-agent' ),
	expired: __( 'Expired', 'flavor-agent' ),
```

(If it is a `switch`, add three equivalent `case` branches returning those strings.)

3b. In `normalizeActivityEntries`'s per-entry builder (the object literal that already maps `status`, `statusLabel`, `undoReason`, …), add one property:

```js
		apply:
			entry?.apply && typeof entry.apply === 'object' ? entry.apply : null,
```

3c. Add the new exported helpers (near the other entry helpers):

```js
export function isPendingExternalApply( entry ) {
	return (
		entry?.status === 'pending' &&
		Boolean( entry?.apply ) &&
		typeof entry.apply === 'object'
	);
}

export function getExternalApplyDetails( entry ) {
	const apply =
		entry?.apply && typeof entry.apply === 'object' ? entry.apply : {};

	return {
		status: typeof apply.status === 'string' ? apply.status : '',
		requestedBy: Number.isFinite( Number( apply.requestedBy ) )
			? Number( apply.requestedBy )
			: 0,
		requestedAt:
			typeof apply.requestedAt === 'string' ? apply.requestedAt : '',
		expiresAt: typeof apply.expiresAt === 'string' ? apply.expiresAt : '',
		decidedBy: Number.isFinite( Number( apply.decidedBy ) )
			? Number( apply.decidedBy )
			: 0,
		decidedAt: typeof apply.decidedAt === 'string' ? apply.decidedAt : '',
		decisionNote:
			typeof apply.decisionNote === 'string' ? apply.decisionNote : '',
		failureCode:
			typeof apply.failureCode === 'string' ? apply.failureCode : '',
		failureMessage:
			typeof apply.failureMessage === 'string' ? apply.failureMessage : '',
		executedAt: typeof apply.executedAt === 'string' ? apply.executedAt : '',
		operations: Array.isArray( apply.operations ) ? apply.operations : [],
		requestReference:
			typeof apply.requestReference === 'string'
				? apply.requestReference
				: '',
	};
}

export function buildDecisionRequest( bootData, activityId, decision, note ) {
	return {
		url: `${ bootData?.restUrl || '' }flavor-agent/v1/activity/${ encodeURIComponent(
			activityId
		) }/decision`,
		method: 'POST',
		headers: { 'X-WP-Nonce': bootData?.nonce || '' },
		data: {
			decision,
			note: typeof note === 'string' ? note : '',
		},
	};
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js`
Expected: PASS.
Run: `npm run lint:js`
Expected: clean (use `npx wp-scripts lint-js --fix src/admin/activity-log-utils.js` for formatting — never raw prettier).

- [ ] **Step 5: Commit**

```bash
git add src/admin/activity-log-utils.js src/admin/__tests__/activity-log-utils.test.js
git commit -m "Add external-apply lifecycle vocabulary and decision helpers to the activity-log utils"
```

---

### Task 13: Admin approvals UI — summary card, status filters, decision section

**Files:**
- Modify: `src/admin/activity-log.js`
- Modify: `inc/Admin/ActivityPage.php`
- Test: `src/admin/__tests__/activity-log.test.js`

- [ ] **Step 1: Write the failing app tests**

Append to `src/admin/__tests__/activity-log.test.js`, using the file's existing `renderApp`/`createEntry`/`getRoot` helpers and `apiFetch` mock. The boot data constant in that file (`BOOT_DATA`) gains `canApproveStyleApplies: true` — update it where it is defined.

```js
	test( 'renders a pending-approval summary card', async () => {
		await renderApp(
			[ createEntry( { id: 'activity-1' } ) ],
			{ summary: { total: 1, pending: 1 } }
		);

		expect(
			document.body.textContent.includes( 'Pending approval' )
		).toBe( true );
	} );

	test( 'shows approve and reject actions for pending external applies and posts the decision', async () => {
		const apiFetch = require( '@wordpress/api-fetch' );
		await renderApp( [
			createEntry( {
				id: 'activity-9',
				status: 'pending',
				statusLabel: 'Pending approval',
				apply: {
					status: 'pending',
					requestedBy: 7,
					expiresAt: '2026-06-11T01:00:00+00:00',
					operations: [
						{
							type: 'set_styles',
							path: [ 'color', 'text' ],
							value: 'var:preset|color|accent',
						},
					],
				},
			} ),
		] );

		// Select the pending row so the details sidebar shows it (mirror how
		// the existing details tests select an entry), then:
		const approveButton = Array.from(
			document.querySelectorAll( 'button' )
		).find( ( button ) =>
			button.textContent.includes( 'Approve and apply' )
		);
		expect( approveButton ).toBeTruthy();

		apiFetch.mockResolvedValueOnce( { entry: { id: 'activity-9' } } );

		await act( async () => {
			approveButton.click();
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				url: expect.stringContaining(
					'flavor-agent/v1/activity/activity-9/decision'
				),
				data: expect.objectContaining( { decision: 'approve' } ),
			} )
		);
	} );

	test( 'hides decision actions when the user cannot approve style applies', async () => {
		await renderApp(
			[
				createEntry( {
					id: 'activity-9',
					status: 'pending',
					apply: { status: 'pending', operations: [] },
				} ),
			],
			{ bootData: { canApproveStyleApplies: false } }
		);

		const approveButton = Array.from(
			document.querySelectorAll( 'button' )
		).find( ( button ) =>
			button.textContent.includes( 'Approve and apply' )
		);
		expect( approveButton ).toBeFalsy();
	} );
```

Adapt the `renderApp` second-argument plumbing to however the file's helper accepts response/summary/bootData overrides — if it accepts only entries, extend the helper with an options parameter that merges `summary` into the mocked response payload and spreads `bootData` over `BOOT_DATA`. The assertions stay as written. If row selection is needed before the sidebar renders the entry, copy the selection idiom from the existing "details" tests in this file.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js`
Expected: FAIL — no "Pending approval" card, no approve button.

- [ ] **Step 3: Implement the app changes**

In `src/admin/activity-log.js`:

3a. **Summary card** — in `getSummaryCards()` (≈ line 91), after the `review` card, add:

```js
		{
			id: 'pending',
			label: __( 'Pending approval', 'flavor-agent' ),
			value: summary?.pending || 0,
			description: '',
		},
```

3b. **Status filter options** — in the `status` field definition (≈ line 1325, `id: 'status'`), extend `elements` after the `applied` entry:

```js
				{
					value: 'pending',
					label: __( 'Pending approval', 'flavor-agent' ),
				},
				{ value: 'rejected', label: __( 'Rejected', 'flavor-agent' ) },
				{ value: 'expired', label: __( 'Expired', 'flavor-agent' ) },
```

3c. **Decision section** — import the new helpers and presentation component at the top:

```js
import {
	buildDecisionRequest,
	getExternalApplyDetails,
	isPendingExternalApply,
	// …existing imports from './activity-log-utils' merge here
} from './activity-log-utils';
import { StyleOperationList } from '../style-surfaces/presentation';
```

Extend the `@wordpress/components` import with `TextareaControl`, `Notice`, and `Flex` if not already present. Then add the component (above `ActivityEntryDetails`):

```js
export function PendingApplyDecisionSection( { entry, bootData, onDecided } ) {
	const [ note, setNote ] = useState( '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ decisionError, setDecisionError ] = useState( '' );

	if ( ! isPendingExternalApply( entry ) || ! bootData?.canApproveStyleApplies ) {
		return null;
	}

	const details = getExternalApplyDetails( entry );

	const submitDecision = async ( decision ) => {
		setIsSubmitting( true );
		setDecisionError( '' );

		try {
			await apiFetch(
				buildDecisionRequest( bootData, entry.id, decision, note )
			);
			onDecided?.();
		} catch ( error ) {
			setDecisionError(
				error?.message ||
					__( 'The decision could not be recorded.', 'flavor-agent' )
			);
		} finally {
			setIsSubmitting( false );
		}
	};

	return (
		<section className="flavor-agent-activity-log__decision">
			<h3>{ __( 'Approval required', 'flavor-agent' ) }</h3>
			<p>
				{ sprintf(
					/* translators: %s: expiry timestamp. */
					__(
						'An external agent requested this style apply. It expires %s.',
						'flavor-agent'
					),
					details.expiresAt
				) }
			</p>
			<StyleOperationList operations={ details.operations } />
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Decision note (optional)', 'flavor-agent' ) }
				value={ note }
				onChange={ setNote }
			/>
			{ decisionError && (
				<Notice status="error" isDismissible={ false }>
					{ decisionError }
				</Notice>
			) }
			<Flex justify="flex-start" gap={ 2 }>
				<Button
					variant="primary"
					isBusy={ isSubmitting }
					disabled={ isSubmitting }
					onClick={ () => submitDecision( 'approve' ) }
				>
					{ __( 'Approve and apply', 'flavor-agent' ) }
				</Button>
				<Button
					variant="secondary"
					isDestructive
					disabled={ isSubmitting }
					onClick={ () => submitDecision( 'reject' ) }
				>
					{ __( 'Reject', 'flavor-agent' ) }
				</Button>
			</Flex>
		</section>
	);
}
```

3d. **Wire it into the sidebar and refetch** — in `ActivityLogApp`, add a refresh token next to the other `useState` calls:

```js
	const [ refreshToken, setRefreshToken ] = useState( 0 );
```

Add `refreshToken` to the dependency array of the `useEffect` that fetches activity data (the one keyed on the effective view / request URL). Then render the decision section above `ActivityEntryDetails` in the sidebar JSX (≈ line 1718):

```jsx
				<div className="flavor-agent-activity-log__sidebar">
					<PendingApplyDecisionSection
						entry={ selectedEntry }
						bootData={ bootData }
						onDecided={ () =>
							setRefreshToken( ( token ) => token + 1 )
						}
					/>
					<ActivityEntryDetails
						entry={ selectedEntry }
						bootData={ bootData }
					/>
				</div>
```

For decided (non-pending) external rows, surface provenance inside `ActivityEntryDetails`: where it renders other read-only labeled values, add rows for `Requested by`, `Decided by`, `Decision note`, and `Failure reason` from `getExternalApplyDetails( entry )` when `entry.apply` exists (render each only when non-empty, matching the surrounding labeled-value idiom).

3e. **Localize the capability** — in `inc/Admin/ActivityPage.php`, find the `wp_localize_script( …, 'flavorAgentActivityLog', [ … ] )` array and add:

```php
				'canApproveStyleApplies' => current_user_can( 'edit_theme_options' ),
```

(Client reads it as a boolean off `bootData`; `wp_localize_script` stringifies top-level booleans to `"1"`/`""`, both truthy/falsy as needed — match how the app already coerces `bootData` flags if it does; otherwise read with `Boolean( bootData?.canApproveStyleApplies )`.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js`
Expected: PASS.
Run: `npm run lint:js && npm run build`
Expected: clean lint, successful build of all three entries.

- [ ] **Step 5: Commit**

```bash
git add src/admin/activity-log.js src/admin/__tests__/activity-log.test.js inc/Admin/ActivityPage.php
git commit -m "Add the approvals view and decision actions to the AI Activity admin app"
```

---

### Task 14: Documentation and freshness guards (same change, not follow-ups)

**Files:**
- Modify: `scripts/check-doc-freshness.sh`, `docs/reference/abilities-and-routes.md`, `CLAUDE.md`, `.github/copilot-instructions.md`, `docs/reference/governance-layer.md`, `docs/reference/activity-state-machine.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/SOURCE_OF_TRUTH.md`, `STATUS.md`

**CRLF warning:** `STATUS.md` and `docs/flavor-agent-readme.md` are mixed-CRLF files that `.gitattributes` does not normalize. If `git diff --check` flags CR churn after editing `STATUS.md`, restore it (`git checkout -- STATUS.md`) and re-apply the insertion with `perl -i -pe` / `awk` instead of a whole-file rewrite.

- [ ] **Step 1: Run the guard to see the current baseline**

Run: `npm run check:docs`
Expected: PASS (pre-change). After the doc edits below it must pass again — that's this task's regression test.

- [ ] **Step 2: Update the guarded strings and their guard patterns together**

2a. `scripts/check-doc-freshness.sh` (≈ line 215): change the check string to

```
'`inc/Abilities/Registration.php` defines 29 ability contracts' \
```

2b. `scripts/check-doc-freshness.sh` (≈ line 277): change the byte-parity string to

```
'29 abilities across block, pattern, template, navigation, docs, infra, content, style, and apply categories' \
```

2c. `docs/reference/abilities-and-routes.md` "Ability Notes" (≈ line 50): rewrite the counting sentence to match the new guard exactly and describe the new group. Replace the first sentence of that bullet with:

```
- `inc/Abilities/Registration.php` defines 29 ability contracts. The 13 helper/read abilities register during `wp_abilities_api_init` when `wp_register_ability()` is available; the `flavor-agent` category registers separately during `wp_abilities_api_categories_init` when `wp_register_ability_category()` is available. The five `preview-recommend-*` siblings register on the same always-on path but are gated on `FeatureBootstrap::canonical_contracts_available()` (Abilities API plus the WP AI plugin's `Abstract_Ability` contract) because they extend that abstract class. The seven AI recommendation abilities (`recommend-block`, `recommend-content`, `recommend-patterns`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, and `recommend-style`) and the four external-apply abilities (`request-style-apply`, `get-activity`, `list-activity`, and `undo-activity`) register only when the WordPress AI plugin feature contracts are available and the Flavor Agent AI feature is enabled in `Settings > AI`. The preview siblings are deliberately available BEFORE the feature gate is flipped so operators can use the Abilities Explorer to verify wiring without first enabling recommendations.
```

2d. Still in `docs/reference/abilities-and-routes.md`:

- **"Registered Abilities" table** (≈ line 18): add four rows following the table's existing column shape (ability id, capability, gating, MCP exposure, purpose):
  - `flavor-agent/request-style-apply` — `edit_theme_options` — feature-gated — dedicated server only — queue a review-gated Global Styles / Style Book apply from a `recommend-style` result; mutates nothing until approved.
  - `flavor-agent/get-activity` — contextual (`Activity\Permissions`) — feature-gated — dedicated server only — one activity entry by id; the agent's status-polling and attribution read.
  - `flavor-agent/list-activity` — contextual, scoped — feature-gated — dedicated server only — scoped activity list with surface/status filters; admin-global reads stay REST-only.
  - `flavor-agent/undo-activity` — contextual per row (style rows: `edit_theme_options`) — feature-gated — dedicated server only — server-side ordered undo with drift checks for executed style rows.
- **"REST Routes" section** (≈ line 79): add `POST /flavor-agent/v1/activity/{id}/decision` — `{ decision: approve|reject, note? }` — permission: `manage_options` AND the row's mutation capability — approves or rejects a pending external apply; approve re-checks freshness and executes server-side.
- **"Activity Route Notes" section** (≈ line 89): add a lifecycle note: pending external-apply rows carry `entry.apply` (`status`, `requestedBy`, `expiresAt`, `operations`, `signatures`, decision provenance); `execution_result` mirrors `pending`/`rejected`/`expired`/`failed` and becomes `applied` on approved execution; non-executed rows never participate in ordered undo.

2e. `CLAUDE.md` AND `.github/copilot-instructions.md` (byte-identical in both): replace

```
25 abilities across block, pattern, template, navigation, docs, infra, content, and style categories
```

with

```
29 abilities across block, pattern, template, navigation, docs, infra, content, style, and apply categories
```

While in `CLAUDE.md`, also: (i) add to the Architecture PHP list, after the `Settings` façade bullet's neighbor `Support\` bullet is fine — insert a new bullet after the `Guidelines` bullet:

```
- `Apply\` — governed external applies: `StyleApplyExecutor` (server-side Global Styles / Style Book apply + undo with live-contract validation, WCAG AA contrast, and drift checks) and `PendingApplyDecision` (admin approve/reject with a second freshness check). `Abilities\ApplyAbilities` hosts the `request-style-apply` / `get-activity` / `list-activity` / `undo-activity` handlers; pending rows live in the activity table with `execution_result` mirroring the `request.apply` lifecycle.
```

and (ii) in **Key Integration Points → REST API**, extend the routes sentence: `activity` (GET/POST), `activity/{id}/undo` (POST), and `activity/{id}/decision` (POST, external-apply approval, `manage_options` + row capability). Make the equivalent edits in `.github/copilot-instructions.md` if it carries the same sections (only the guarded string must be byte-identical).

- [ ] **Step 3: Add the pre-apply lifecycle to `docs/reference/activity-state-machine.md`**

Insert this new section between "Activity Entry Lifecycle" and "Undo States":

```markdown
## External Apply Lifecycle (pre-apply)

External agents can request style applies through `flavor-agent/request-style-apply`. That creates a **pending** row in the same activity table; the post-apply undo machine below is unchanged and only begins once the row is executed.

`apply.status`: `pending → available (approved + executed) | rejected | expired | failed`

- One row throughout: the pending row carries the proposed operations, requester provenance (`apply.requestedBy`, `apply.requestReference`), and freshness signatures under `request.apply`, hydrated as top-level `entry.apply`. On approval (`POST /flavor-agent/v1/activity/{id}/decision`, `manage_options` + the row's mutation capability) the server re-validates freshness and operations, executes through `FlavorAgent\Apply\StyleApplyExecutor`, records execution-time `before`/`after` snapshots, sets `decidedBy`/`decidedAt`, and the row becomes a normal undoable apply row (`apply.status: available`, `undo.status: available`, ordered undo applies).
- `execution_result` mirrors the lifecycle for SQL filtering: `pending`, `rejected`, `expired`, `failed` before execution; `applied` once executed.
- Freshness is checked twice: at request (signature recompute plus claimed-config equality against the live entity) and again at approval (live config must still match the `baselineConfigHash` recorded at request). Drift at approval transitions the row to `failed` with a stale reason the agent observes via `flavor-agent/get-activity`.
- Expiry: default 24 h (`flavor_agent_external_apply_pending_ttl` filter), enforced lazily on reads and swept by the existing `flavor_agent_prune_activity` cron. A per-user pending cap (default 10, `flavor_agent_external_apply_pending_cap` filter) bounds queue abuse.
- Pending, rejected, expired, and approval-failed rows use `undo.status: not_applicable`, keep `before`/`after` empty, never participate in ordered undo, and never block undo of older executed rows. Executed rows with `undo.status: failed` keep the existing blocking semantics because the mutation may still be live.
- Operator guidance: an open Site Editor session editing Global Styles will make approvals fail closed (the live entity no longer matches the request-time baseline) — re-request the apply after the editing session saves. The Site Editor also does not live-refresh when an external apply lands; the new row appears on the next activity hydration.
```

Also extend the "Undo States" intro table's surrounding prose with one sentence after the `not_applicable` row description: `Pending/rejected/expired/approval-failed external-apply rows also use `not_applicable` — they never executed.`

- [ ] **Step 4: Rewrite the governance-layer parity boundary**

In `docs/reference/governance-layer.md`, "External-Agent Parity": add a fourth bullet to the abilities list:

```markdown
- the four external-apply abilities (feature-gated, dedicated server only): `request-style-apply` queues a review-gated style apply, `get-activity`/`list-activity` are the agent's attribution and status reads, and `undo-activity` is the server-side reverse path with ordered-undo and drift checks
```

and replace the boundary paragraph ("The boundary, stated plainly: …") with:

```markdown
The boundary, stated plainly: external agents can now request style applies, read their attribution, and undo executed style rows — but approval is never exposed to agents. Every external style apply is review-gated through `POST /flavor-agent/v1/activity/{id}/decision` (`manage_options` plus the row's mutation capability) in `Settings > AI Activity`, with freshness re-verified at request and again at approval. AI proposes; WordPress approves. Template, template-part, and block applies remain editor-owned (C2+), and admin-global activity reads stay REST-only.
```

Also add a Surface Coverage note for external style applies: in the section of `governance-layer.md` that maps surface loop coverage (the per-surface apply/record/reverse map), note on the Global Styles and Style Book entries that the full loop now also runs server-side for external agents — request (`request-style-apply`) → human approval (decision route) → server execute with re-validation → attributed row → server-side undo (`undo-activity`) — and that an open Site Editor session does not live-refresh when an external apply lands; activity hydration shows it on the next load.

Update the "Update Triggers" section only if its trigger list doesn't already cover "the set of abilities and MCP tools exposed externally" (it does — no edit needed).

- [ ] **Step 5: Matrix, source of truth, status log**

5a. `docs/FEATURE_SURFACE_MATRIX.md`: in the programmatic/agent-facing table (the one listing ability exposure per surface), update the Global Styles and Style Book rows' external-agent column (or the matrix's equivalent cell) to note: `external apply via request-style-apply → admin approval → server execute; server-side undo via undo-activity`. Follow the table's existing cell phrasing style. The `check:docs` regex `AI activity and undo.*Style Book` must keep matching — do not reword that row's header.

5b. `docs/SOURCE_OF_TRUTH.md`: in the architecture/inventory section where abilities are counted or listed, mirror the 29-ability reality and add `inc/Apply/` to the namespace inventory with a one-line description. Keep the guarded string `Eight first-party recommendation surfaces exist today` untouched (surface count is unchanged — external applies add a loop, not a surface).

5c. `STATUS.md`: add a feature-inventory line under the most recent entries (respect CRLF handling):

```
- 2026-06-10 — Governed external applies (C1): external agents can request review-gated Global Styles / Style Book applies via four new feature-gated abilities (29 total); site admins approve or reject in Settings > AI Activity; approved applies execute server-side with double freshness checks and are undoable via ability or editor. Verified: ExternalApplyLifecycleTest, StyleApplyExecutorTest, ApplyAbilitiesTest, AgentRoutesTest, MCPServerBootstrapTest, admin app Jest suites.
```

- [ ] **Step 6: Verify the guards**

Run: `npm run check:docs`
Expected: PASS — all guarded strings consistent.
Run: `git diff --check`
Expected: no whitespace/CR errors.

- [ ] **Step 7: Commit**

```bash
git add scripts/check-doc-freshness.sh docs/reference/abilities-and-routes.md CLAUDE.md .github/copilot-instructions.md docs/reference/governance-layer.md docs/reference/activity-state-machine.md docs/FEATURE_SURFACE_MATRIX.md docs/SOURCE_OF_TRUTH.md STATUS.md
git commit -m "Document the governed external-apply loop and update ability-count guards to 29"
```

---

### Task 15: Playground browser coverage of the approval surface

The PHPUnit suite covers the approve-executes happy path; the browser spec covers the human gate end-to-end: a pending row is visible, Reject records provenance, and Approve on a drifted request **fails closed**. (Seeding an approvable-with-real-signatures row from a spec is not feasible — signatures hash live server state — so the approve path asserts the fail-closed transition, which is the governance property the browser owns.)

**Files:**
- Create: `tests/e2e/flavor-agent.approvals.spec.js`

- [ ] **Step 1: Study the harness conventions**

Read `tests/e2e/flavor-agent.activity.spec.js` for: how specs log in / land in wp-admin, how they call the plugin REST API from the page context, and shared helpers/fixtures. Reuse those helpers verbatim where they exist (auth, base URL, REST nonce access).

- [ ] **Step 2: Write the spec**

Create `tests/e2e/flavor-agent.approvals.spec.js`:

```js
const { test, expect } = require( '@playwright/test' );

const ACTIVITY_PAGE =
	'/wp-admin/options-general.php?page=flavor-agent-activity';

async function seedPendingExternalApply( page, overrides = {} ) {
	return await page.evaluate( async ( entryOverrides ) => {
		const entry = {
			type: 'apply_global_styles_suggestion',
			surface: 'global-styles',
			target: { globalStylesId: '999999' },
			suggestion: 'External: use the accent text preset',
			before: {},
			after: {},
			executionResult: 'pending',
			undo: { status: 'not_applicable' },
			request: {
				prompt: 'darker',
				reference: 'external-apply:e2e',
				apply: {
					status: 'pending',
					requestedBy: 1,
					requestedAt: new Date().toISOString(),
					expiresAt: new Date( Date.now() + 86400000 ).toISOString(),
					operations: [
						{
							type: 'set_styles',
							path: [ 'color', 'text' ],
							value: 'var:preset|color|accent',
							valueType: 'preset',
							presetType: 'color',
							presetSlug: 'accent',
						},
					],
					signatures: {
						resolvedContextSignature: 'e'.repeat( 64 ),
						reviewContextSignature: 'e'.repeat( 64 ),
						baselineConfigHash: 'e'.repeat( 64 ),
					},
					requestReference: 'e2e-req-1',
				},
			},
			document: {
				scopeKey: 'global_styles:999999',
				postType: 'global_styles',
				entityId: '999999',
			},
			...entryOverrides,
		};

		const response = await window.wp.apiFetch( {
			path: '/flavor-agent/v1/activity',
			method: 'POST',
			data: { entry },
		} );

		return response?.entry?.id || '';
	}, overrides );
}

test.describe( 'external apply approvals', () => {
	test( 'pending external applies appear and can be rejected with a note', async ( {
		page,
	} ) => {
		await page.goto( ACTIVITY_PAGE );
		const activityId = await seedPendingExternalApply( page );
		expect( activityId ).not.toBe( '' );

		await page.reload();

		await expect(
			page.getByText( 'Pending approval' ).first()
		).toBeVisible();

		await page
			.getByText( 'External: use the accent text preset' )
			.first()
			.click();

		await expect( page.getByText( 'Approval required' ) ).toBeVisible();
		await page
			.getByLabel( 'Decision note (optional)' )
			.fill( 'Rejected from the browser spec' );
		await page.getByRole( 'button', { name: 'Reject' } ).click();

		await expect( page.getByText( 'Rejected' ).first() ).toBeVisible();
	} );

	test( 'approving a drifted request fails closed instead of mutating', async ( {
		page,
	} ) => {
		await page.goto( ACTIVITY_PAGE );
		// globalStylesId 999999 does not exist, so the approval-time freshness
		// check must fail closed and transition the row to failed.
		const activityId = await seedPendingExternalApply( page );
		expect( activityId ).not.toBe( '' );

		await page.reload();
		await page
			.getByText( 'External: use the accent text preset' )
			.first()
			.click();
		await page
			.getByRole( 'button', { name: 'Approve and apply' } )
			.click();

		await expect(
			page.getByText( 'Failed or unavailable' ).first()
		).toBeVisible();
	} );
} );
```

Adapt only the login/bootstrap preamble and selector granularity to match `flavor-agent.activity.spec.js` (e.g. if specs share an authenticated state fixture or a `loginAsAdmin` helper, use it before `page.goto`; if the feed renders entries as rows with specific test ids, prefer those selectors over text). Keep the seeded entry payload and both assertions semantically identical. If `window.wp.apiFetch` is unavailable on the page, seed via `page.request.post` with the REST nonce the existing spec uses.

- [ ] **Step 3: Run the spec**

Run: `npm run test:e2e:playground -- flavor-agent.approvals.spec.js` (after `npm run build`)
Expected: PASS (2 tests).

**If the Playground harness is unavailable or known-red:** do NOT silently skip. Record an explicit waiver in `STATUS.md` next to the Task 14 entry — `Approvals browser coverage waived on <date>: <blocker>; PHPUnit covers approve/reject/fail-closed transitions` — and surface the waiver in the final report.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/flavor-agent.approvals.spec.js
git commit -m "Add Playground coverage for the external-apply approval surface"
```

---

### Task 16: Cross-surface validation gates

This change touches ability contracts and the shared activity subsystem, so the additive gates from `docs/reference/cross-surface-validation-gates.md` apply.

- [ ] **Step 1: Targeted suites (fast confirmation)**

Run: `vendor/bin/phpunit`
Expected: PASS — all suites including the five extended ones.
Run: `npm run test:unit -- --runInBand`
Expected: PASS.

- [ ] **Step 2: Aggregate verify without E2E**

Run: `node scripts/verify.js --skip-e2e`
Expected: exit 0, final line `VERIFY_RESULT={"status":"pass",…}`.
Then inspect the artifact:

Run: `cat output/verify/summary.json`
Expected: `"status": "pass"`, every executed step `"status": "pass"`, skips limited to the two E2E steps. If `lint-plugin` lacks prerequisites in this environment, re-run with `--skip=lint-plugin` and note that in the final report.

- [ ] **Step 3: Docs freshness gate**

Run: `npm run check:docs`
Expected: PASS.

- [ ] **Step 4: Browser harnesses**

- Playground: Task 15's spec plus the existing suite — `npm run test:e2e:playground`. Expected: PASS (or the recorded waiver).
- wp70: the design notes Site Editor style-undo parity is already covered by the existing wp70 suite; no new wp70 spec is required. If running it is cheap in this environment (`npm run test:e2e:wp70` after `npm run wp:e2e:wp70:bootstrap`), run it; otherwise record "wp70 not re-run; no wp70-covered path changed" in the final report.

- [ ] **Step 5: Final review**

Run: `git log --oneline master..HEAD` (or the task-branch equivalent) and confirm every task committed. Re-read `docs/superpowers/specs/2026-06-10-governed-external-applies-c1-design.md` § "Docs and guard updates" and § "Testing" as a checklist against the diff. Then use the superpowers:requesting-code-review skill before merge per the repo's workflow.

---

## Design-decision notes the implementer should not re-litigate

1. **Same activity types as editor rows** (`apply_global_styles_suggestion` / `apply_style_book_suggestion`): the design requires executed external rows to BE normal apply rows (one-row lifecycle); provenance lives in `entry.apply` + `request.requestMeta.ability`, not in a new type.
2. **`baselineConfigHash` instead of storing the request `styleContext`:** the design's row shape carries `signatures`, not the full request input. A sha256 of the comparable live config (settings+styles, keys sorted) is sufficient for the approval-time equality check, keeps rows small, and fails closed on any entity drift. Full-config baseline is used for BOTH surfaces at approval (strictest, per the design's "resolves full config for … drift checks").
3. **Signature recompute happens at request time only** (where the agent supplies the input); approval-time freshness = baseline equality + live-contract re-validation + contrast inside the executor. Together these are the design's "freshness checked twice".
4. **`IN ('pending')` SQL spelling** in the status CASE is load-bearing for the fake-wpdb regexes — do not "simplify" to `=`.
5. **Approval is REST-only.** Do not add a decision ability, even as a convenience.
6. **The `18 always-on` guarded string is untouched** — all four new abilities are feature-gated, so the always-on count stays 18.

## Out of scope (do not build)

Template/template-part/block executors, editor-side pending-apply visibility, inline-safe direct-apply fast path, approval notifications (all C2+). No new cron events — expiry rides `flavor_agent_prune_activity`.

