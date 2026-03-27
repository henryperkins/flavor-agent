# Activity Log Review Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Address all findings from the AI Activity log/dashboard code review — dead code, ID generation, query bounds, duplicate UI, param flow cleanup, shared sanitization, activity pruning, and CSS color scheme adaptation.

**Architecture:** Eight code-change tasks plus final verification. Behavioral fixes and new lifecycle logic should start with a failing test. Dead-code cleanup and pure refactors may use regression guards when no meaningful failing test exists. No REST/API behavior changes are intended; Repository grows `delete_before()` plus internal pruning lifecycle helpers.

**Tech Stack:** PHP 8.0+ (PHPUnit), JavaScript (Jest), WordPress REST API, `@wordpress/dataviews`, `@wordpress/data`

---

### Task 1: Fix Dead Code in `Permissions::resolve_request_context`

**Why:** `$post_type` and `$entity_id` are initialized to `''`, making the fallback ternaries on lines 88-89 always take the `parse_scope_key` branch. The code should mirror `resolve_entry_context` which correctly reads from request params first.

**Files:**
- Modify: `inc/Activity/Permissions.php:78-99`
- Test: `tests/phpunit/ActivityPermissionsTest.php`

- [ ] **Step 1: Write a focused failing permissions test**

Add `use FlavorAgent\Activity\Permissions as ActivityPermissions;` near the top of `tests/phpunit/ActivityPermissionsTest.php`, then add this test before the closing `}` of the class:

```php
public function test_can_access_activity_request_prefers_explicit_entity_id_over_scope_fallback(): void {
	WordPressTestState::$capabilities = [
		'edit_post:42' => false,
		'edit_post:99' => true,
		'edit_posts'   => false,
	];

	$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
	$request->set_param( 'scopeKey', 'post:42' );
	$request->set_param( 'postType', 'post' );
	$request->set_param( 'entityType', 'block' );
	$request->set_param( 'entityId', '99' );

	$this->assertTrue(
		ActivityPermissions::can_access_activity_request( $request )
	);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter test_can_access_activity_request_prefers_explicit_entity_id_over_scope_fallback`

Expected: FAIL — current code ignores request-level `entityId`, falls back to the `scopeKey` entity (`42`), and therefore returns `false` instead of honoring the explicit request context.

- [ ] **Step 3: Fix `resolve_request_context` to read request params first**

In `inc/Activity/Permissions.php`, replace lines 78-99:

```php
/**
 * @return array{scopeKey: string, surface: string, entityType: string, postType: string, entityId: string}
 */
private static function resolve_request_context( \WP_REST_Request $request ): array {
	$scope_key   = trim( (string) $request->get_param( 'scopeKey' ) );
	$surface     = trim( (string) $request->get_param( 'surface' ) );
	$entity_type = trim( (string) $request->get_param( 'entityType' ) );
	$post_type   = trim( (string) $request->get_param( 'postType' ) );
	$entity_id   = trim( (string) $request->get_param( 'entityId' ) );

	if ( '' === $post_type || '' === $entity_id ) {
		$parsed_scope = self::parse_scope_key( $scope_key );

		$post_type = '' !== $post_type ? $post_type : $parsed_scope['postType'];
		$entity_id = '' !== $entity_id ? $entity_id : $parsed_scope['entityId'];
	}

	return [
		'scopeKey'   => $scope_key,
		'surface'    => $surface,
		'entityType' => $entity_type,
		'postType'   => $post_type,
		'entityId'   => $entity_id,
	];
}
```

- [ ] **Step 4: Run full Permissions test suite to verify no regressions**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter ActivityPermissionsTest`

Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
git add inc/Activity/Permissions.php tests/phpunit/ActivityPermissionsTest.php
git commit -m "fix: resolve request-level postType/entityId in activity permissions

The resolve_request_context method initialized postType and entityId
to empty strings then immediately fell into the scope-key parse
branch, making the request-level params unreachable dead code.
Now reads from the request first and falls back to scope parsing,
matching the pattern used in resolve_entry_context.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 2: Clean Up `global` Param Flow

**Why:** The `global` REST arg's `sanitize_callback` already reduces to `bool`, making the multi-value truthiness checks in `Permissions::is_global_request` dead code. Also, `handle_get_activity` passes `global` into `Repository::query()` which ignores it.

**Files:**
- Modify: `inc/Activity/Permissions.php:198-224`
- Modify: `inc/REST/Agent_Controller.php:507-530`
- Test: `tests/phpunit/ActivityPermissionsTest.php`

- [ ] **Step 1: Write a regression guard test**

Add to `tests/phpunit/ActivityPermissionsTest.php`:

```php
public function test_is_global_request_treats_sanitized_boolean_true_as_global(): void {
	WordPressTestState::$capabilities = [
		'manage_options' => true,
	];

	ActivityRepository::create( $this->build_block_activity_entry( 'activity-global-1', '42' ) );

	$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
	$request->set_param( 'global', true );

	$response = Agent_Controller::handle_get_activity( $request );

	$this->assertInstanceOf( \WP_REST_Response::class, $response );
	$this->assertSame( 200, $response->get_status() );
	$this->assertNotEmpty( $response->get_data()['entries'] ?? [] );
}
```

- [ ] **Step 2: Run test to verify the current behavior before cleanup**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter test_is_global_request_treats_sanitized_boolean_true_as_global`

Expected: PASS.

- [ ] **Step 3: Simplify `is_global_request` in Permissions.php**

Replace the `is_global_request` method (lines 198-224) with:

```php
private static function is_global_request( \WP_REST_Request $request ): bool {
	$activity_id = trim( (string) $request->get_param( 'id' ) );

	if ( '' !== $activity_id ) {
		return false;
	}

	$entry = $request->get_param( 'entry' );

	if ( is_array( $entry ) || is_object( $entry ) ) {
		return false;
	}

	if ( true === $request->get_param( 'global' ) ) {
		return true;
	}

	return '' === trim( (string) $request->get_param( 'scopeKey' ) );
}
```

- [ ] **Step 4: Remove `global` key from the filter array in `handle_get_activity`**

In `inc/REST/Agent_Controller.php`, replace the `handle_get_activity` method body (lines 507-530):

```php
public static function handle_get_activity( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	if ( ! ActivityPermissions::can_access_activity_request( $request ) ) {
		return ActivityPermissions::forbidden_error();
	}

	$entries = ActivityRepository::query(
		[
			'scopeKey'   => $request->get_param( 'scopeKey' ),
			'surface'    => $request->get_param( 'surface' ),
			'entityType' => $request->get_param( 'entityType' ),
			'entityRef'  => $request->get_param( 'entityRef' ),
			'userId'     => $request->get_param( 'userId' ),
			'limit'      => $request->get_param( 'limit' ),
		]
	);

	return new \WP_REST_Response(
		[
			'entries' => $entries,
		],
		200
	);
}
```

- [ ] **Step 5: Run full test suites**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter 'ActivityPermissionsTest|ActivityRepositoryTest'`

Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
git add inc/Activity/Permissions.php inc/REST/Agent_Controller.php tests/phpunit/ActivityPermissionsTest.php
git commit -m "fix: simplify global param flow in activity endpoints

The REST sanitize_callback already reduces global to a bool, so the
multi-value truthiness checks in is_global_request were dead code.
Simplified to a single true === check. Removed the unused global key
from the filter array passed to Repository::query().

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 3: Replace `microtime` Activity ID with UUID

**Why:** `microtime(true)` with a static per-request sequence counter can collide across concurrent PHP requests. `wp_generate_uuid4()` is the canonical WordPress approach.

**Files:**
- Modify: `inc/Activity/Repository.php:456-466`
- Test: `tests/phpunit/ActivityRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/phpunit/ActivityRepositoryTest.php`:

```php
public function test_create_generates_a_uuid_v4_activity_id_when_none_is_provided(): void {
	Repository::install();

	$stored = Repository::create(
		[
			'type'       => 'apply_suggestion',
			'surface'    => 'block',
			'target'     => [
				'clientId'  => 'block-1',
				'blockName' => 'core/paragraph',
			],
			'suggestion' => 'Tighten copy',
			'before'     => [],
			'after'      => [],
			'request'    => [],
			'document'   => [
				'scopeKey' => 'post:42',
				'postType' => 'post',
				'entityId' => '42',
			],
			'timestamp'  => '2026-03-27T10:00:00Z',
		]
	);

	$this->assertIsArray( $stored );
	$this->assertMatchesRegularExpression(
		'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
		$stored['id'] ?? ''
	);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter test_create_generates_a_uuid_v4_activity_id_when_none_is_provided`

Expected: FAIL — current IDs match `activity-DIGITS-N`, not UUID v4.

- [ ] **Step 3: Replace `generate_activity_id` in Repository.php**

Replace the `generate_activity_id` method (lines 456-466):

```php
private static function generate_activity_id(): string {
	if ( function_exists( 'wp_generate_uuid4' ) ) {
		return wp_generate_uuid4();
	}

	return sprintf(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		random_int( 0, 0xffff ), random_int( 0, 0xffff ),
		random_int( 0, 0xffff ),
		random_int( 0, 0x0fff ) | 0x4000,
		random_int( 0, 0x3fff ) | 0x8000,
		random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff )
	);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter test_create_generates_a_uuid_v4_activity_id_when_none_is_provided`

Expected: PASS.

- [ ] **Step 5: Run full Repository test suite**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter ActivityRepositoryTest`

Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
git add inc/Activity/Repository.php tests/phpunit/ActivityRepositoryTest.php
git commit -m "fix: use UUID v4 for server-generated activity IDs

Replaces microtime-based ID generation with wp_generate_uuid4()
to eliminate collision risk across concurrent PHP requests. Falls
back to random_int-based UUID v4 when the WP function is absent.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 4: Remove Duplicate Page Header from ActivityPage

**Why:** `ActivityPage::render_page()` renders an `<h1>` and `<p>` in the PHP wrapper, and the React `ActivityLogApp` renders its own polished header with action buttons. Users see both.

**Files:**
- Modify: `inc/Admin/ActivityPage.php:37-47`

- [ ] **Step 1: Simplify `render_page` to only emit the React mount point**

Replace the `render_page` method in `inc/Admin/ActivityPage.php`:

```php
public static function render_page(): void {
	?>
	<div class="wrap">
		<div id="flavor-agent-activity-log-root"></div>
	</div>
	<?php
}
```

- [ ] **Step 2: Verify the build succeeds**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && npm run build`

Expected: Build completes without errors. The React app provides its own `<h1>` and description.

- [ ] **Step 3: Commit**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
git add inc/Admin/ActivityPage.php
git commit -m "fix: remove duplicate page header from activity log admin

The PHP render_page method rendered an h1 and description that
duplicated the React ActivityLogApp header. The React version has
action buttons and is the intended header. Keep only the .wrap
container and React mount point.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 5: Optimize `is_ordered_undo_eligible` to Avoid Full Hydration

**Why:** The current implementation calls `Serializer::hydrate_row()` for every row matching the entity, which JSON-decodes six LONGTEXT columns per row (`target_json`, `before_state`, `after_state`, `undo_state`, `request_json`, `document_json`). Only `undo_state` and `activity_id` are needed for the eligibility check.

**Files:**
- Modify: `inc/Activity/Repository.php:413-454`
- Test: `tests/phpunit/ActivityRepositoryTest.php`

- [ ] **Step 1: Write a regression guard test for bulk entities**

Add to `tests/phpunit/ActivityRepositoryTest.php`:

```php
public function test_ordered_undo_check_works_with_many_entries_for_same_entity(): void {
	Repository::install();

	for ( $index = 1; $index <= 50; ++$index ) {
		Repository::create(
			$this->build_template_entry(
				'activity-bulk-' . $index,
				sprintf( '2026-03-24T10:%02d:%02dZ', intdiv( $index, 60 ), $index % 60 )
			)
		);
	}

	$blocked = Repository::update_undo_status( 'activity-bulk-1', 'undone' );

	$this->assertInstanceOf( \WP_Error::class, $blocked );
	$this->assertSame(
		'flavor_agent_activity_undo_blocked',
		$blocked->get_error_code()
	);

	$tail = Repository::update_undo_status( 'activity-bulk-50', 'undone' );

	$this->assertIsArray( $tail );
	$this->assertSame( 'undone', $tail['undo']['status'] ?? null );
}
```

- [ ] **Step 2: Run test to verify it passes (baseline before optimization)**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter test_ordered_undo_check_works_with_many_entries_for_same_entity`

Expected: PASS.

- [ ] **Step 3: Optimize `is_ordered_undo_eligible` to only decode `undo_state`**

Replace the `is_ordered_undo_eligible` method in `inc/Activity/Repository.php`:

```php
/**
 * @param array<string, mixed> $row
 */
private static function is_ordered_undo_eligible( array $row ): bool {
	$current_entry = Serializer::hydrate_row( $row );
	$current_undo  = is_array( $current_entry['undo'] ?? null ) ? $current_entry['undo'] : [];

	if ( 'available' !== (string) ( $current_undo['status'] ?? '' ) ) {
		return false;
	}

	global $wpdb;

	if ( ! is_object( $wpdb ) ) {
		return false;
	}

	$sql = $wpdb->prepare(
		'SELECT activity_id, undo_state FROM ' . self::table_name()
		. ' WHERE entity_type = %s AND entity_ref = %s'
		. ' ORDER BY created_at ASC, id ASC',
		(string) ( $row['entity_type'] ?? '' ),
		(string) ( $row['entity_ref'] ?? '' )
	);

	$rows = $wpdb->get_results( $sql, ARRAY_A );

	if ( ! is_array( $rows ) ) {
		return false;
	}

	$current_activity_id = (string) ( $row['activity_id'] ?? '' );

	for ( $index = count( $rows ) - 1; $index >= 0; --$index ) {
		if ( (string) ( $rows[ $index ]['activity_id'] ?? '' ) === $current_activity_id ) {
			return true;
		}

		$undo = Serializer::decode_json(
			isset( $rows[ $index ]['undo_state'] ) ? (string) $rows[ $index ]['undo_state'] : ''
		);

		if ( 'undone' !== (string) ( $undo['status'] ?? '' ) ) {
			return false;
		}
	}

	return false;
}
```

Key changes vs the original:
- Selects only `activity_id` and `undo_state` instead of `*` (documents intent; real DB uses column projection)
- Uses `Serializer::decode_json()` on only the `undo_state` column instead of `Serializer::hydrate_row()` which decodes all six LONGTEXT columns
- Same backward-walk logic: scans from newest to the target row, checking that every newer entry is undone

- [ ] **Step 4: Run full Repository test suite**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter ActivityRepositoryTest`

Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
git add inc/Activity/Repository.php tests/phpunit/ActivityRepositoryTest.php
git commit -m "perf: avoid full row hydration in ordered undo eligibility check

The previous implementation called hydrate_row() on every entity
row, JSON-decoding six LONGTEXT columns each. Now only decodes
undo_state via decode_json() and compares activity_id directly.
Reduces PHP memory and CPU for entities with long activity tails.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 6: Consolidate Recursive Sanitization

**Why:** `Agent_Controller` has a private `normalize_structured_value` and a public `sanitize_structured_value` wrapper that are near-identical to `Serializer::normalize_structured_value`. DRY them up by making the Serializer's version public and deleting the controller's private copy.

**Files:**
- Modify: `inc/REST/Agent_Controller.php:304-338`
- Modify: `inc/Activity/Serializer.php:215`

- [ ] **Step 1: Make `Serializer::normalize_structured_value` public**

In `inc/Activity/Serializer.php`, change the method visibility on line 215 from `private` to `public`:

```php
public static function normalize_structured_value( $value ) {
```

- [ ] **Step 2: Replace `sanitize_structured_value` body and delete private `normalize_structured_value` in Agent_Controller**

In `inc/REST/Agent_Controller.php`, replace the `sanitize_structured_value` method (the one that currently delegates to the private `normalize_structured_value`):

```php
public static function sanitize_structured_value( $value ): array {
	$sanitized = Serializer::normalize_structured_value( $value );

	return is_array( $sanitized ) ? $sanitized : [];
}
```

Then delete the entire `private static function normalize_structured_value` method from `Agent_Controller.php` (lines ~314-338).

Add the use statement at the top of `Agent_Controller.php` if not already present:

```php
use FlavorAgent\Activity\Serializer;
```

- [ ] **Step 3: Run full PHP test suite**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit`

Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
git add inc/REST/Agent_Controller.php inc/Activity/Serializer.php
git commit -m "refactor: consolidate recursive sanitization into Serializer

Agent_Controller::sanitize_structured_value now delegates to
Serializer::normalize_structured_value (made public) and wraps
the result with array coercion. Removes the duplicated private
normalize_structured_value from the controller.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 7: Add Activity Pruning

**Why:** The activity table grows unbounded. Add a `delete_before` method on Repository and a daily WP cron event to prune entries older than 90 days.

**Files:**
- Modify: `inc/Activity/Repository.php`
- Modify: `flavor-agent.php`
- Modify: `tests/phpunit/bootstrap.php`
- Test: `tests/phpunit/ActivityRepositoryTest.php`

Because PHPUnit does not bootstrap `flavor-agent.php` in this repo, verify scheduling behavior through a Repository helper (`ensure_prune_schedule()`). Keep `flavor-agent.php` as a thin wiring layer that delegates to that helper.

- [ ] **Step 1: Write failing tests for safe deletion and recurring schedule wiring**

Add to `tests/phpunit/ActivityRepositoryTest.php`:

```php
public function test_delete_before_removes_entries_older_than_the_cutoff(): void {
	Repository::install();

	Repository::create(
		$this->build_template_entry( 'activity-old', '2026-01-01T10:00:00Z' )
	);
	Repository::create(
		$this->build_template_entry( 'activity-recent', '2026-03-27T10:00:00Z' )
	);

	$deleted = Repository::delete_before( '2026-03-01T00:00:00Z' );

	$this->assertSame( 1, $deleted );

	$entries = Repository::query( [ 'limit' => 100 ] );

	$this->assertCount( 1, $entries );
	$this->assertSame( 'activity-recent', $entries[0]['id'] ?? null );
}

public function test_delete_before_returns_zero_when_nothing_matches(): void {
	Repository::install();

	Repository::create(
		$this->build_template_entry( 'activity-recent', '2026-03-27T10:00:00Z' )
	);

	$deleted = Repository::delete_before( '2026-01-01T00:00:00Z' );

	$this->assertSame( 0, $deleted );
}

public function test_delete_before_returns_zero_when_timestamp_is_invalid(): void {
	Repository::install();

	Repository::create(
		$this->build_template_entry( 'activity-recent', '2026-03-27T10:00:00Z' )
	);

	$deleted = Repository::delete_before( 'not-a-timestamp' );

	$this->assertSame( 0, $deleted );

	$entries = Repository::query( [ 'limit' => 100 ] );

	$this->assertCount( 1, $entries );
	$this->assertSame( 'activity-recent', $entries[0]['id'] ?? null );
}

public function test_ensure_prune_schedule_schedules_a_daily_event_once(): void {
	$this->assertFalse( wp_next_scheduled( Repository::PRUNE_CRON_HOOK ) );

	Repository::ensure_prune_schedule();

	$this->assertSame(
		Repository::PRUNE_CRON_HOOK,
		WordPressTestState::$scheduled_events[ Repository::PRUNE_CRON_HOOK ]['hook'] ?? null
	);
	$this->assertSame(
		'daily',
		WordPressTestState::$scheduled_events[ Repository::PRUNE_CRON_HOOK ]['recurrence'] ?? null
	);

	$first_timestamp = WordPressTestState::$scheduled_events[ Repository::PRUNE_CRON_HOOK ]['timestamp'] ?? null;

	Repository::ensure_prune_schedule();

	$this->assertCount( 1, WordPressTestState::$scheduled_events );
	$this->assertSame(
		$first_timestamp,
		WordPressTestState::$scheduled_events[ Repository::PRUNE_CRON_HOOK ]['timestamp'] ?? null
	);
}
```

- [ ] **Step 2: Add DELETE support and recurring cron support to the PHPUnit harness**

The mock `wpdb::query()` in `tests/phpunit/bootstrap.php` only handles `CREATE TABLE`. Add DELETE support. In the `query` method, after the `CREATE TABLE` block and before the `return 1;`, add:

```php
if ( preg_match( '/DELETE FROM\s+([^\s]+)\s+WHERE\s+created_at\s*<\s*\'([^\']+)\'/i', $query, $matches ) ) {
	$table  = (string) ( $matches[1] ?? '' );
	$cutoff = (string) ( $matches[2] ?? '' );

	if ( isset( WordPressTestState::$db_tables[ $table ] ) ) {
		$before_count = count( WordPressTestState::$db_tables[ $table ] );
		WordPressTestState::$db_tables[ $table ] = array_values(
			array_filter(
				WordPressTestState::$db_tables[ $table ],
				static fn ( array $row ): bool => (string) ( $row['created_at'] ?? '' ) >= $cutoff
			)
		);

		return $before_count - count( WordPressTestState::$db_tables[ $table ] );
	}

	return 0;
}
```

Then add a recurring-cron stub before `wp_schedule_single_event()`:

```php
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [] ): bool {
		WordPressTestState::$scheduled_events[ $hook ] = [
			'hook'       => $hook,
			'timestamp'  => $timestamp,
			'recurrence' => $recurrence,
			'args'       => $args,
		];

		return true;
	}
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter 'test_delete_before|test_ensure_prune_schedule'`

Expected: FAIL — `delete_before()`, `ensure_prune_schedule()`, and `PRUNE_CRON_HOOK` do not exist yet.

- [ ] **Step 4: Implement safe pruning helpers on Repository**

Add these constants near the top of `inc/Activity/Repository.php`, after `TABLE_SUFFIX`:

```php
public const PRUNE_CRON_HOOK        = 'flavor_agent_prune_activity';
public const DEFAULT_RETENTION_DAYS = 90;
```

Then add these public methods to `inc/Activity/Repository.php`, after `update_undo_status()`:

```php
/**
 * Delete activity entries created before the given ISO 8601 timestamp.
 *
 * @return int Number of deleted rows, or 0 on failure.
 */
public static function delete_before( string $before_timestamp ): int {
	global $wpdb;

	if ( ! is_object( $wpdb ) ) {
		return 0;
	}

	$unix_timestamp = strtotime( $before_timestamp );

	if ( false === $unix_timestamp ) {
		return 0;
	}

	$deleted = $wpdb->query(
		$wpdb->prepare(
			'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s',
			gmdate( 'Y-m-d H:i:s', $unix_timestamp )
		)
	);

	return is_int( $deleted ) ? $deleted : 0;
}

public static function prune(): int {
	$retention_days = (int) get_option( 'flavor_agent_activity_retention_days', self::DEFAULT_RETENTION_DAYS );

	if ( $retention_days <= 0 ) {
		return 0;
	}

	$seconds_per_day = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
	$cutoff          = gmdate( 'c', time() - ( $retention_days * $seconds_per_day ) );

	return self::delete_before( $cutoff );
}

public static function ensure_prune_schedule(): void {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}

	if ( false === wp_next_scheduled( self::PRUNE_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'daily', self::PRUNE_CRON_HOOK );
	}
}
```

Important: `delete_before()` should validate the timestamp directly instead of calling `Serializer::mysql_datetime_from_timestamp()`, because that helper falls back to the current time on invalid input and would turn bad data into an unintended destructive delete.

- [ ] **Step 5: Run the targeted pruning tests to verify they pass**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter 'test_delete_before|test_ensure_prune_schedule'`

Expected: PASS.

- [ ] **Step 6: Register upgrade-safe cron wiring in `flavor-agent.php`**

In `flavor-agent.php`, after the existing docs grounding cron hooks (after line 90), add:

```php
// Activity pruning cron hook.
add_action(
	FlavorAgent\Activity\Repository::PRUNE_CRON_HOOK,
	[ FlavorAgent\Activity\Repository::class, 'prune' ]
);
```

Add an init hook near the existing `Repository::maybe_install()` registration so existing installs self-heal the schedule on the next request:

```php
add_action( 'init', [ FlavorAgent\Activity\Repository::class, 'ensure_prune_schedule' ], 6 );
```

In the `register_activation_hook` callback, call the helper immediately after `Repository::install()`:

```php
FlavorAgent\Activity\Repository::ensure_prune_schedule();
```

In the `register_deactivation_hook` callback, after the existing `wp_clear_scheduled_hook()` calls, add:

```php
wp_clear_scheduled_hook( FlavorAgent\Activity\Repository::PRUNE_CRON_HOOK );
```

- [ ] **Step 7: Run Repository tests to verify pruning and scheduling behavior**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit --filter ActivityRepositoryTest`

Expected: All tests PASS.

- [ ] **Step 8: Commit**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
git add inc/Activity/Repository.php flavor-agent.php tests/phpunit/ActivityRepositoryTest.php tests/phpunit/bootstrap.php
git commit -m "feat: add safe daily activity pruning for stale entries

Adds Repository::delete_before() with explicit timestamp validation,
plus Repository::prune() and Repository::ensure_prune_schedule()
for upgrade-safe daily cleanup. Existing installs self-heal the cron
schedule on init, activation seeds it immediately, and deactivation
clears it.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 8: Adapt Admin CSS to WP Admin Color Scheme

**Why:** The activity log CSS uses hardcoded hex colors that don't adapt to dark admin themes or non-default WP color schemes.

**Files:**
- Modify: `src/admin/activity-log.css`

- [ ] **Step 1: Replace hardcoded colors with WP admin CSS custom properties**

Replace the full content of `src/admin/activity-log.css`:

```css
.flavor-agent-activity-log {
	--flavor-agent-activity-log-accent: var(--wp-admin-theme-color, #0b5c7c);
	--flavor-agent-activity-log-accent-darker: var(--wp-admin-theme-color-darker-10, #094a63);
	--flavor-agent-activity-log-border: var(--wp-components-color-gray-300, #d7dee7);
	--flavor-agent-activity-log-muted: var(--wp-components-color-gray-700, #59636e);
	--flavor-agent-activity-log-text: var(--wp-components-color-foreground, #11161d);
	--flavor-agent-activity-log-surface-from: var(--wp-components-color-background, #ffffff);
	--flavor-agent-activity-log-surface-to: var(--wp-components-color-gray-100, #f4f7fb);
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.flavor-agent-activity-log__intro,
.flavor-agent-activity-log__summary-card,
.flavor-agent-activity-log__feed-card,
.flavor-agent-activity-log__sidebar-card,
.flavor-agent-activity-log__empty {
	border: 1px solid var(--flavor-agent-activity-log-border);
	border-radius: 18px;
	box-shadow: none;
}

.flavor-agent-activity-log__intro {
	align-items: flex-end;
	background: linear-gradient(
		135deg,
		var(--flavor-agent-activity-log-surface-from) 0%,
		var(--flavor-agent-activity-log-surface-to) 100%
	);
	display: flex;
	gap: 16px;
	justify-content: space-between;
	padding: 24px;
}

.flavor-agent-activity-log__intro-actions,
.flavor-agent-activity-log__controls,
.flavor-agent-activity-log__controls-main,
.flavor-agent-activity-log__controls-actions,
.flavor-agent-activity-log__sidebar-actions {
	align-items: center;
	display: flex;
	gap: 10px;
}

.flavor-agent-activity-log__intro-actions {
	flex-wrap: wrap;
	justify-content: flex-end;
}

.flavor-agent-activity-log__summary {
	display: grid;
	gap: 12px;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.flavor-agent-activity-log__summary-card .components-card__body {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.flavor-agent-activity-log__summary-label {
	color: var(--flavor-agent-activity-log-muted);
	font-size: 11px;
	font-weight: 600;
	letter-spacing: 0.08em;
	text-transform: uppercase;
}

.flavor-agent-activity-log__page-title,
.flavor-agent-activity-log__section-title {
	color: var(--flavor-agent-activity-log-text);
	margin: 0;
}

.flavor-agent-activity-log__page-title {
	font-size: 30px;
	line-height: 1.1;
	margin-bottom: 8px;
}

.flavor-agent-activity-log__section-title {
	font-size: 18px;
	line-height: 1.2;
}

.flavor-agent-activity-log__copy {
	color: var(--flavor-agent-activity-log-muted);
	margin: 0;
}

.flavor-agent-activity-log__summary-value {
	color: var(--flavor-agent-activity-log-text);
	font-size: 30px;
	font-weight: 600;
	line-height: 1;
}

.flavor-agent-activity-log__content {
	align-items: start;
	display: grid;
	gap: 20px;
	grid-template-columns: minmax(0, 1.7fr) minmax(320px, 0.9fr);
}

.flavor-agent-activity-log__feed,
.flavor-agent-activity-log__sidebar {
	min-width: 0;
}

.flavor-agent-activity-log__feed-card .components-card__body {
	max-height: 72vh;
	overflow: auto;
	padding: 0 20px 16px;
}

.flavor-agent-activity-log__pagination {
	display: flex;
	justify-content: flex-end;
	padding-top: 12px;
}

.flavor-agent-activity-log__sidebar {
	position: sticky;
	top: 32px;
}

.flavor-agent-activity-log__sidebar-card .components-card__header,
.flavor-agent-activity-log__sidebar-card .components-card__body {
	padding: 20px;
}

.flavor-agent-activity-log__sidebar-heading {
	align-items: start;
	display: flex;
	gap: 12px;
	justify-content: space-between;
	width: 100%;
}

.flavor-agent-activity-log__status,
.flavor-agent-activity-log__icon {
	align-items: center;
	border-radius: 999px;
	display: inline-flex;
	font-size: 12px;
	font-weight: 600;
	gap: 6px;
	line-height: 1.2;
}

.flavor-agent-activity-log__status {
	padding: 5px 10px;
}

.flavor-agent-activity-log__status.is-applied {
	background: #e5f2ec;
	color: #14653f;
}

.flavor-agent-activity-log__status.is-undone {
	background: var(--wp-components-color-gray-100, #eef0f5);
	color: var(--wp-components-color-gray-700, #4c5561);
}

.flavor-agent-activity-log__status.is-blocked {
	background: #fff2d8;
	color: #8a5a00;
}

.flavor-agent-activity-log__status.is-failed {
	background: #fde7e5;
	color: #9d2d28;
}

.flavor-agent-activity-log__icon {
	background: var(--wp-components-color-gray-100, #edf4f8);
	color: var(--flavor-agent-activity-log-accent);
	height: 32px;
	justify-content: center;
	width: 32px;
}

.flavor-agent-activity-log__icon.is-blocked,
.flavor-agent-activity-log__icon.is-failed {
	background: #fde7e5;
	color: #9d2d28;
}

.flavor-agent-activity-log__icon.is-undone {
	background: var(--wp-components-color-gray-100, #eef0f5);
	color: var(--wp-components-color-gray-700, #4c5561);
}

.flavor-agent-activity-log__code {
	background: var(--wp-components-color-gray-100, #f6f8fb);
	border: 1px solid var(--wp-components-color-gray-200, #e2e8f0);
	border-radius: 12px;
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	font-size: 12px;
	margin: 0;
	overflow: auto;
	padding: 12px;
	white-space: pre-wrap;
}

.flavor-agent-activity-log__empty .components-card__body {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 28px;
}

.flavor-agent-activity-log .dataviews-filters__container {
	margin-bottom: 16px;
}

@media (max-width: 960px) {
	.flavor-agent-activity-log__intro,
	.flavor-agent-activity-log__controls,
	.flavor-agent-activity-log__sidebar-heading {
		align-items: stretch;
		flex-direction: column;
	}

	.flavor-agent-activity-log__content {
		grid-template-columns: 1fr;
	}

	.flavor-agent-activity-log__sidebar {
		position: static;
		top: auto;
	}

	.flavor-agent-activity-log__controls-main,
	.flavor-agent-activity-log__controls-actions,
	.flavor-agent-activity-log__intro-actions {
		flex-wrap: wrap;
	}
}
```

Note: Status pill semantic colors (green/applied, amber/blocked, red/failed) remain hardcoded because they communicate meaning independent of the admin scheme. Neutral surfaces, text, and borders use WP custom properties with hex fallbacks.

- [ ] **Step 2: Build to verify CSS compiles**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && npm run build`

Expected: Build completes. `build/activity-log.css` is regenerated.

- [ ] **Step 3: Commit**

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
git add src/admin/activity-log.css
git commit -m "fix: adapt activity log CSS to WP admin color scheme

Replaces hardcoded hex colors for text, borders, surfaces, and
neutral pills with WP admin CSS custom properties (--wp-admin-
theme-color, --wp-components-color-*) with fallback values.
Semantic status colors (green/amber/red) remain hardcoded as they
communicate meaning independent of the admin scheme.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 9: Run Full Test Suites and Build

**Why:** Final verification that all changes work together.

**Files:** None (verification only)

- [ ] **Step 1: Run all PHP tests**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && vendor/bin/phpunit`

Expected: All tests PASS.

- [ ] **Step 2: Run all JS tests**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && npm run test:unit -- --runInBand`

Expected: All tests PASS.

- [ ] **Step 3: Run production build**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && npm run build`

Expected: Build completes. `build/activity-log.js`, `build/activity-log.css`, `build/index.js`, `build/admin.js` are all regenerated.

- [ ] **Step 4: Run lint**

Run: `cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent && npm run lint:js && composer lint:php`

Expected: No errors.
