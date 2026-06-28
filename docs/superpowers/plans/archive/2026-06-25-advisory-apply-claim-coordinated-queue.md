# Advisory Apply-Claim — Coordinated Approval Queue (v1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the pending external-apply approval queue in `Settings > AI Activity` legible to multiple administrators at once, via an advisory auto-expiring "being reviewed by X" claim and a legible race-loss that pins the terminal row state instead of showing a generic error.

**Architecture:** A transient-backed `ApplyClaim` service sits **above** the existing write-boundary decision guard — it never gates a decision. Two REST routes (`POST`/`DELETE /activity/{id}/claim`) acquire/release claims, both gated by the existing `can_decide_activity_request`. The admin feed enriches pending rows with `apply.claim`; the admin app auto-claims on opening a decision, releases on abandon, shows a passive badge, and pins the terminal entry on any of the three terminal decision codes so the row stays legible after it drops out of the pending-only filter.

**Tech Stack:** PHP 8.2 (PSR-4 `FlavorAgent\`), WordPress transients, WP REST API; JS (`@wordpress/element`/`@wordpress/components`/`@wordpress/api-fetch`/`@wordpress/date`), Jest, PHPUnit (stub-based bootstrap).

**Spec:** `docs/superpowers/specs/2026-06-25-advisory-apply-claim-coordinated-queue-design.md`

## Global Constraints

- **The claim is advisory and never gates a decision.** `ApplyClaim` is never read by `PendingApplyDecision::decide`. The only single-execution enforcement remains `Repository::transition_external_apply`'s write-boundary pending guard. The decision path's behavior must stay byte-identical.
- **No new ability; no ability-count change.** Claims are REST routes only. The guarded `30/31` ability count string must stay untouched.
- **No schema migration / no new column.** Claims are per-row WordPress transients; `ADMIN_PROJECTION_SELECT_SQL` is unchanged.
- **Claim TTL = 5 minutes** = `5 * MINUTE_IN_SECONDS`.
- **No claim-steal in v1.** A foreign live claim is reported, never overwritten; release only deletes the caller's own claim.
- **Claims attach only to pending external-apply rows** (`apply.status === 'pending'`).
- **Expiry is an action-path concern, never a feed-read side effect.** `ApplyClaim::claim` and the decision path call `Repository::maybe_expire_pending_apply`, which **persists** a transition; the admin-feed enrichment and `ApplyClaim::release` deliberately do **not** — `query_admin` is a read projection of stored status and has always shown overdue-but-unswept rows as `pending` (the daily `flavor_agent_prune_activity` cron and any claim/decision action materialize the expiry). Enrichment applies a **pure-read** `expiresAt` guard so an overdue row never advertises a stale review claim (the claim transient's own 5-min TTL bounds that staleness anyway), without itself writing. Acting on an overdue row returns the terminal `flavor_agent_apply_expired` code, which the client pins as a legible terminal state. `release()` likewise does not expire; its `entry` is consumed only to refresh the claim field, and a row decided/expired elsewhere still pins correctly because `find()` returns the committed terminal `apply.status`.
- **The three terminal decision codes** that pin a terminal entry are exactly: `flavor_agent_apply_invalid_transition` (the genuine simultaneous loss), `flavor_agent_apply_not_pending`, `flavor_agent_apply_expired`. The two **retryable** codes that keep the claim and show an inline error are `flavor_agent_activity_storage_unavailable` and `flavor_agent_activity_update_failed` (HTTP 500).
- **Display names are not stored.** The UI resolves `userId → "User #<id>"` at render via the existing `formatUserIdLabel`.
- **PHPUnit runs single-file** (`vendor/bin/phpunit tests/phpunit/XTest.php`); never trust ad-hoc multi-file batches.
- **JS formatting** is applied only via `npm run lint:js -- --fix` (never `npx prettier` / `wp-scripts format`).
- **`wp_localize_script` stringifies scalars** — `currentUserId` arrives in JS as a string; always coerce with `Number(...)`, never `=== `-compare to a number.

---

## File Structure

**Net-new files:**
- `inc/Apply/ApplyClaim.php` — transient-backed claim store (`get`/`claim`/`release`/`clear` + `TTL` + key hashing).
- `tests/phpunit/ApplyClaimTest.php` — unit tests for the claim store.

**Modified PHP:**
- `tests/phpunit/bootstrap.php` — define `MINUTE_IN_SECONDS` (currently undefined; first plugin use).
- `inc/REST/Agent_Controller.php` — register the two claim routes; add `handle_activity_claim` / `handle_activity_claim_release`; enrich admin entries before the global-feed response.
- `inc/Activity/Repository.php` — `enrich_admin_entries_with_apply_claims()` helper; `ApplyClaim::clear()` on the committed transition path.
- `inc/Admin/ActivityPage.php` — add `currentUserId` to the `flavorAgentActivityLog` localize array.
- `tests/phpunit/AgentRoutesTest.php` — extend the route-contract assertion.
- `tests/phpunit/ExternalApplyLifecycleTest.php` — regression tests for claim clear-on-commit / keep-on-non-commit / no-block.
- `tests/phpunit/ActivityPageTest.php` — assert the `currentUserId` boot field.

**Modified JS:**
- `src/admin/activity-log-utils.js` — `buildClaimRequest`, `buildClaimReleaseRequest`, `formatApplyClaimNotice`, `TERMINAL_DECISION_ERROR_CODES`, and the extended `normalizeActivityDiscoveryBadges` (the **feed** queue badge — finding 1).
- `src/admin/activity-log.js` — `pinnedTerminalEntry` state machine; `applyClaimResponse` callback; `submitDecision` terminal-code handling; `GovernanceEvidenceSection` auto-claim/release + badge/note + explicit **Release control**; the feed-badge call-site wiring (`normalizeActivityDiscoveryBadges` gets `currentUserId`); focus/visibility refresh.
- `src/admin/__tests__/activity-log-utils.test.js` — helper + feed-badge tests.
- `src/admin/__tests__/activity-log.test.js` — component behavior tests (incl. feed badge + Release control).

**Modified docs (Task 11):** `docs/reference/abilities-and-routes.md`, `docs/features/activity-and-audit.md`, `docs/reference/activity-state-machine.md`, `docs/SOURCE_OF_TRUTH.md`, `docs/reference/php-backend-architecture.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `CLAUDE.md`, `.github/copilot-instructions.md` (finding 2).

---

## Task 1: `ApplyClaim` transient-backed claim store (PHP)

**Files:**
- Create: `inc/Apply/ApplyClaim.php`
- Create: `tests/phpunit/ApplyClaimTest.php`
- Modify: `tests/phpunit/bootstrap.php` (define `MINUTE_IN_SECONDS`)

**Interfaces:**
- Consumes: `FlavorAgent\Activity\Repository::find()` (returns hydrated entry with `['id']` and `['apply']['status']`), `Repository::maybe_expire_pending_apply(array $entry): array`.
- Produces:
  - `ApplyClaim::TTL` (`int`, `= 5 * MINUTE_IN_SECONDS`)
  - `ApplyClaim::get( string $activity_id ): ?array` → `['userId' => int, 'claimedAt' => string]` or `null`
  - `ApplyClaim::claim( string $activity_id, int $user_id ): array|\WP_Error` → `['claim' => array|null, 'entry' => array]`
  - `ApplyClaim::release( string $activity_id, int $user_id ): array|\WP_Error` → same shape
  - `ApplyClaim::clear( string $activity_id ): void`

- [ ] **Step 1: Define `MINUTE_IN_SECONDS` in the test bootstrap**

`MINUTE_IN_SECONDS` is undefined in `tests/phpunit/bootstrap.php` and unused anywhere in `inc/` today; a class constant `5 * MINUTE_IN_SECONDS` would fatal at autoload under tests. Add the define next to the existing `ARRAY_A` define (`tests/phpunit/bootstrap.php:1134`).

Find:
```php
		define('ARRAY_A', 'ARRAY_A');
```
Replace with:
```php
		define('ARRAY_A', 'ARRAY_A');
	}

	if (! defined('MINUTE_IN_SECONDS')) {
		define('MINUTE_IN_SECONDS', 60);
```
(Keep the surrounding `if (! defined(...))` brace structure consistent with the neighboring defines — verify the closing brace count after editing.)

- [ ] **Step 2: Write the failing test for `ApplyClaimTest`**

Create `tests/phpunit/ApplyClaimTest.php`:
```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository;
use FlavorAgent\Apply\ApplyClaim;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ApplyClaimTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		Repository::install();
	}

	/**
	 * @param array<string, mixed> $apply_overrides
	 * @return array<string, mixed>
	 */
	private function create_pending_entry( array $apply_overrides = [] ): array {
		$entry = [
			'type'            => 'apply_global_styles_suggestion',
			'surface'         => 'global-styles',
			'target'          => [ 'globalStylesId' => '17' ],
			'suggestion'      => 'Darken the palette',
			'before'          => [],
			'after'           => [],
			'executionResult' => 'pending',
			'undo'            => [ 'status' => 'not_applicable' ],
			'request'         => [
				'prompt' => 'darker',
				'apply'  => array_replace(
					[
						'status'      => 'pending',
						'requestedBy' => 7,
						'requestedAt' => gmdate( 'c' ),
						'expiresAt'   => gmdate( 'c', time() + 3600 ),
						'operations'  => [],
						'signatures'  => [ 'baselineConfigHash' => str_repeat( 'c', 64 ) ],
					],
					$apply_overrides
				),
			],
			'document'        => [
				'scopeKey' => 'global_styles:17',
				'postType' => 'global_styles',
				'entityId' => '17',
			],
		];

		$created = Repository::create( $entry );
		$this->assertIsArray( $created );

		return $created;
	}

	public function test_claim_sets_and_get_reads_back_and_passes_the_five_minute_ttl(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];

		$result = ApplyClaim::claim( $id, 7 );

		$this->assertIsArray( $result );
		$this->assertSame( 7, $result['claim']['userId'] );
		$this->assertNotEmpty( $result['claim']['claimedAt'] );
		$this->assertSame( 'pending', $result['entry']['apply']['status'] );

		$live = ApplyClaim::get( $id );
		$this->assertIsArray( $live );
		$this->assertSame( 7, $live['userId'] );

		// NOTE (finding 3): the PHPUnit transient stub records the expiration but
		// never enforces it (bootstrap.php get_transient ignores TTL), so this
		// asserts the 5-minute TTL is *passed*, not that the claim time-expires.
		// Real expiry surfaces as an absent transient — covered by the next test.
		$key = 'flavor_agent_apply_claim_' . md5( $id );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, WordPressTestState::$transient_expirations[ $key ] );
	}

	public function test_get_treats_an_absent_transient_as_no_claim(): void {
		// In production, an expired transient reads as absent. Assert the contract
		// ApplyClaim relies on: a missing transient is "no claim", not an error.
		$this->assertNull( ApplyClaim::get( 'never-claimed' ) );

		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		ApplyClaim::claim( $id, 7 );
		delete_transient( 'flavor_agent_apply_claim_' . md5( $id ) ); // simulate TTL elapse

		$this->assertNull( ApplyClaim::get( $id ) );
	}

	public function test_transient_key_is_md5_hashed_and_within_length_limit_for_long_id(): void {
		$long_id = str_repeat( 'a', 191 );
		$key     = 'flavor_agent_apply_claim_' . md5( $long_id );

		// 'flavor_agent_apply_claim_' (25) + 32-char md5 = 57 chars, well under WordPress's ~172-char limit.
		$this->assertLessThanOrEqual( 172, strlen( $key ) );
		$this->assertSame( 57, strlen( $key ) );
	}

	public function test_second_user_claim_returns_existing_claim_without_overwriting(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];

		ApplyClaim::claim( $id, 7 );

		WordPressTestState::$current_user_id = 9;
		$result = ApplyClaim::claim( $id, 9 );

		$this->assertSame( 7, $result['claim']['userId'] );
		$this->assertSame( 7, ApplyClaim::get( $id )['userId'] );
	}

	public function test_claim_on_non_pending_row_writes_nothing(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		Repository::transition_external_apply( $id, [ 'applyStatus' => 'rejected' ] );

		$result = ApplyClaim::claim( $id, 7 );

		$this->assertNull( $result['claim'] );
		$this->assertSame( 'rejected', $result['entry']['apply']['status'] );
		$this->assertNull( ApplyClaim::get( $id ) );
	}

	public function test_claim_on_overdue_pending_row_expires_first_and_grants_no_claim(): void {
		$created = $this->create_pending_entry( [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] );
		$id      = (string) $created['id'];

		$result = ApplyClaim::claim( $id, 7 );

		$this->assertNull( $result['claim'] );
		$this->assertSame( 'expired', $result['entry']['apply']['status'] );
		$this->assertNull( ApplyClaim::get( $id ) );
	}

	public function test_claim_on_missing_row_returns_404(): void {
		$result = ApplyClaim::claim( 'does-not-exist', 7 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_release_clears_only_the_callers_own_claim(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		ApplyClaim::claim( $id, 7 );

		$result = ApplyClaim::release( $id, 7 );

		$this->assertNull( $result['claim'] );
		$this->assertNull( ApplyClaim::get( $id ) );
	}

	public function test_release_of_foreign_claim_is_a_no_op_leaving_it_intact(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		ApplyClaim::claim( $id, 7 );

		$result = ApplyClaim::release( $id, 9 );

		$this->assertSame( 7, $result['claim']['userId'] );
		$this->assertSame( 7, ApplyClaim::get( $id )['userId'] );
	}

	public function test_clear_is_unconditional_and_idempotent(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		ApplyClaim::claim( $id, 7 );

		ApplyClaim::clear( $id );
		ApplyClaim::clear( $id );

		$this->assertNull( ApplyClaim::get( $id ) );
	}
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/phpunit/ApplyClaimTest.php`
Expected: FAIL — `Error: Class "FlavorAgent\Apply\ApplyClaim" not found`.

- [ ] **Step 4: Implement `ApplyClaim`**

Create `inc/Apply/ApplyClaim.php`:
```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Activity\Repository as ActivityRepository;

/**
 * Advisory, auto-expiring "being reviewed by X" claim for pending external
 * applies. A claim is a best-effort visibility hint — never a lock. It is
 * never read by PendingApplyDecision::decide and never gates a decision.
 * Single-execution is enforced solely by Repository::transition_external_apply's
 * write-boundary pending guard.
 */
final class ApplyClaim {

	public const TTL = 5 * MINUTE_IN_SECONDS;

	private static function key( string $activity_id ): string {
		// md5 is a non-cryptographic cache-key hash: activity_id is varchar(191)
		// and caller-supplied, so concatenating it raw could exceed WordPress's
		// transient-name length limit. The fixed 32-char digest stays well under.
		return 'flavor_agent_apply_claim_' . md5( $activity_id );
	}

	/**
	 * @return array{userId: int, claimedAt: string}|null
	 */
	public static function get( string $activity_id ): ?array {
		$value = get_transient( self::key( $activity_id ) );

		if ( ! is_array( $value ) ) {
			return null;
		}

		$user_id = (int) ( $value['userId'] ?? 0 );

		if ( $user_id <= 0 ) {
			return null;
		}

		return [
			'userId'    => $user_id,
			'claimedAt' => (string) ( $value['claimedAt'] ?? '' ),
		];
	}

	/**
	 * @return array{claim: array{userId: int, claimedAt: string}|null, entry: array<string, mixed>}|\WP_Error
	 */
	public static function claim( string $activity_id, int $user_id ): array|\WP_Error {
		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return self::not_found_error();
		}

		$entry  = ActivityRepository::maybe_expire_pending_apply( $entry );
		$apply  = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
		$status = (string) ( $apply['status'] ?? '' );

		if ( 'pending' !== $status ) {
			return [
				'claim' => null,
				'entry' => $entry,
			];
		}

		$existing = self::get( $activity_id );

		// Another user holds a live claim: report it, never steal. The write is
		// best-effort (no CAS), so a simultaneous claim can still win benignly.
		if ( is_array( $existing ) && $existing['userId'] !== $user_id ) {
			return [
				'claim' => $existing,
				'entry' => $entry,
			];
		}

		$claim = [
			'userId'    => $user_id,
			'claimedAt' => gmdate( 'c' ),
		];

		set_transient( self::key( $activity_id ), $claim, self::TTL );

		return [
			'claim' => $claim,
			'entry' => $entry,
		];
	}

	/**
	 * @return array{claim: array{userId: int, claimedAt: string}|null, entry: array<string, mixed>}|\WP_Error
	 */
	public static function release( string $activity_id, int $user_id ): array|\WP_Error {
		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return self::not_found_error();
		}

		$existing = self::get( $activity_id );

		if ( null === $existing || $existing['userId'] === $user_id ) {
			delete_transient( self::key( $activity_id ) );

			return [
				'claim' => null,
				'entry' => $entry,
			];
		}

		// Foreign live claim — release is not a steal vector.
		return [
			'claim' => $existing,
			'entry' => $entry,
		];
	}

	/**
	 * Unconditional delete, called only from transition_external_apply's
	 * committed-success path so a decided row never shows a stale claim.
	 */
	public static function clear( string $activity_id ): void {
		delete_transient( self::key( $activity_id ) );
	}

	private static function not_found_error(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_activity_not_found',
			'Flavor Agent could not find that activity entry.',
			[ 'status' => 404 ]
		);
	}
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/phpunit/ApplyClaimTest.php`
Expected: PASS (11 tests).

- [ ] **Step 6: Commit**

```bash
git add inc/Apply/ApplyClaim.php tests/phpunit/ApplyClaimTest.php tests/phpunit/bootstrap.php
git commit -m "feat(apply): add advisory ApplyClaim transient store for pending external applies"
```

---

## Task 2: Claim REST routes + handlers + route contract test

**Files:**
- Modify: `inc/REST/Agent_Controller.php` (register routes ~`:351`+, add handlers beside `handle_activity_decision` ~`:700`)
- Modify: `tests/phpunit/AgentRoutesTest.php:23-42` (route-contract assertion)

**Interfaces:**
- Consumes: `ApplyClaim::claim/release` (Task 1), `ActivityPermissions::can_decide_activity_request`, `ActivityPermissions::forbidden_error`.
- Produces: routes `POST`/`DELETE /flavor-agent/v1/activity/{id}/claim`; handlers `Agent_Controller::handle_activity_claim` / `handle_activity_claim_release` returning `{ claim, entry }` `200` or the `ApplyClaim` `WP_Error`.

- [ ] **Step 1: Update the failing route-contract test**

In `tests/phpunit/AgentRoutesTest.php`, extend the expected route list (`:25-33`) and add a methods assertion. Find:
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

		$this->assertRouteMethods( '/flavor-agent/v1/sync-patterns', [ 'GET', 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/activity', [ 'GET', 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/undo', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/decision', [ 'POST' ] );
```
Replace with:
```php
		$this->assertSame(
			[
				'/flavor-agent/v1/activity',
				'/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/claim',
				'/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/decision',
				'/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/undo',
				'/flavor-agent/v1/sync-patterns',
			],
			$registered_routes
		);

		$this->assertRouteMethods( '/flavor-agent/v1/sync-patterns', [ 'GET', 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/activity', [ 'GET', 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/undo', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/decision', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/claim', [ 'POST', 'DELETE' ] );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/phpunit/AgentRoutesTest.php --filter test_register_routes_exposes_the_expected_contract`
Expected: FAIL — the claim route is absent from `$registered_routes`.

- [ ] **Step 3: Register the claim routes**

In `inc/REST/Agent_Controller.php`, after the `/activity/(?P<id>…)/decision` registration block (closes at `:377`), add:
```php
		\register_rest_route(
			self::NAMESPACE,
			'/activity/(?P<id>[A-Za-z0-9._:-]+)/claim',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'handle_activity_claim' ],
					'permission_callback' => [ ActivityPermissions::class, 'can_decide_activity_request' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ __CLASS__, 'handle_activity_claim_release' ],
					'permission_callback' => [ ActivityPermissions::class, 'can_decide_activity_request' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);
```

- [ ] **Step 4: Add the handlers**

In `inc/REST/Agent_Controller.php`, add the `ApplyClaim` import beside the existing `use` block (after `:8 use FlavorAgent\Apply\PendingApplyDecision;`):
```php
use FlavorAgent\Apply\ApplyClaim;
```
Then, immediately after `handle_activity_decision` (closes at `:716`), add:
```php
	public static function handle_activity_claim( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_decide_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$result = ApplyClaim::claim(
			(string) $request->get_param( 'id' ),
			\get_current_user_id()
		);

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	public static function handle_activity_claim_release( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_decide_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$result = ApplyClaim::release(
			(string) $request->get_param( 'id' ),
			\get_current_user_id()
		);

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}
```

- [ ] **Step 5: Run the contract test to verify it passes**

Run: `vendor/bin/phpunit tests/phpunit/AgentRoutesTest.php`
Expected: PASS (the contract test plus the existing not-registered-as-REST recommendation assertions).

- [ ] **Step 6: Add a permission-parity + 404 test**

Append to `tests/phpunit/AgentRoutesTest.php` (inside the class). This mirrors the existing `dispatch_route` helper used by the file — confirm the helper name by reading the file's private helpers around `:127`; if `dispatch_route( $method, $path, $params )` exists, use it as below; otherwise call the handler statics directly with a built `WP_REST_Request`.
```php
	public function test_claim_route_404s_on_missing_row_for_capable_user(): void {
		WordPressTestState::$capabilities['manage_options']    = true;
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/missing/claim' );
		$request->set_param( 'id', 'missing' );

		$response = Agent_Controller::handle_activity_claim( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'flavor_agent_activity_not_found', $response->get_error_code() );
	}

	public function test_claim_route_is_forbidden_without_manage_options(): void {
		WordPressTestState::$capabilities['manage_options'] = false;

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/x/claim' );
		$request->set_param( 'id', 'x' );

		$response = Agent_Controller::handle_activity_claim( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'flavor_agent_activity_forbidden', $response->get_error_code() );
	}
```

- [ ] **Step 7: Run and verify, then commit**

Run: `vendor/bin/phpunit tests/phpunit/AgentRoutesTest.php`
Expected: PASS (all tests, including the two new ones).
```bash
git add inc/REST/Agent_Controller.php tests/phpunit/AgentRoutesTest.php
git commit -m "feat(rest): add POST/DELETE activity/{id}/claim routes gated by can_decide_activity_request"
```

---

## Task 3: Clear the claim on the committed transition + invariant regression tests

**Files:**
- Modify: `inc/Activity/Repository.php` (add `ApplyClaim` import; call `ApplyClaim::clear` after the committed update, ~`:936`)
- Modify: `tests/phpunit/ExternalApplyLifecycleTest.php`

**Interfaces:**
- Consumes: `ApplyClaim::clear` / `ApplyClaim::get` (Task 1).
- Produces: a decided row never carries a live claim; the claim layer never blocks a transition; non-committing transitions leave the claim untouched.

- [ ] **Step 1: Write the failing regression tests**

Append to `tests/phpunit/ExternalApplyLifecycleTest.php` (inside the class; it already has `use FlavorAgent\Activity\Repository;`). Add the `ApplyClaim` import to the `use` block at the top of the file:
```php
use FlavorAgent\Apply\ApplyClaim;
```
Then add:
```php
	public function test_committed_transition_clears_an_active_claim(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		ApplyClaim::claim( $id, 7 );
		$this->assertNotNull( ApplyClaim::get( $id ) );

		Repository::transition_external_apply(
			$id,
			[
				'applyStatus'  => 'rejected',
				'decidedBy'    => 7,
				'decidedAt'    => gmdate( 'c' ),
				'decisionNote' => '',
			]
		);

		$this->assertNull( ApplyClaim::get( $id ) );
	}

	public function test_non_committing_transition_leaves_the_claim_untouched(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];

		// First transition commits the row out of pending.
		Repository::transition_external_apply( $id, [ 'applyStatus' => 'rejected' ] );

		// A claim placed afterwards (directly, since the row is no longer pending)
		// must survive a second, non-committing transition attempt.
		$key = 'flavor_agent_apply_claim_' . md5( $id );
		set_transient( $key, [ 'userId' => 7, 'claimedAt' => gmdate( 'c' ) ], ApplyClaim::TTL );

		$second = Repository::transition_external_apply( $id, [ 'applyStatus' => 'available' ] );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'flavor_agent_apply_invalid_transition', $second->get_error_code() );
		$this->assertNotNull( ApplyClaim::get( $id ) );
	}

	public function test_foreign_claim_does_not_block_a_transition(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];

		// A claim held by another user must not gate the decision path.
		ApplyClaim::claim( $id, 9 );

		WordPressTestState::$current_user_id = 7;
		$updated = Repository::transition_external_apply(
			$id,
			[
				'applyStatus'  => 'rejected',
				'decidedBy'    => 7,
				'decidedAt'    => gmdate( 'c' ),
				'decisionNote' => '',
			]
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'rejected', $updated['apply']['status'] );
	}
```

- [ ] **Step 2: Run to verify the clear-on-commit test fails**

Run: `vendor/bin/phpunit tests/phpunit/ExternalApplyLifecycleTest.php --filter test_committed_transition_clears_an_active_claim`
Expected: FAIL — `ApplyClaim::get( $id )` is still non-null (no clear wired yet).

- [ ] **Step 3: Wire `ApplyClaim::clear` into the committed path**

In `inc/Activity/Repository.php`, add the import near the top `use` declarations:
```php
use FlavorAgent\Apply\ApplyClaim;
```
Then, in `transition_external_apply`, after the notification-cache invalidation (`:936`) and before `$stored = self::find( $activity_id );` (`:938`), add:
```php
		// Committed out of pending: drop any advisory review claim so the decided
		// row never shows a stale "being reviewed" badge. Reached only on the
		// committed-success path — non-committing 404/409/500 returns above never
		// arrive here, so a lost-race transition leaves the claim intact.
		ApplyClaim::clear( $activity_id );
```

- [ ] **Step 4: Run the regression tests to verify they pass**

Run: `vendor/bin/phpunit tests/phpunit/ExternalApplyLifecycleTest.php`
Expected: PASS (existing lifecycle tests plus the three new ones).

- [ ] **Step 5: Commit**

```bash
git add inc/Activity/Repository.php tests/phpunit/ExternalApplyLifecycleTest.php
git commit -m "feat(activity): clear advisory claim on committed external-apply transition"
```

---

## Task 4: Admin feed enrichment — attach `apply.claim` to pending rows

**Files:**
- Modify: `inc/Activity/Repository.php` (add `enrich_admin_entries_with_apply_claims`)
- Modify: `inc/REST/Agent_Controller.php:523-525` (enrich the global-feed result)
- Modify: `tests/phpunit/ActivityRepositoryTest.php`

**Interfaces:**
- Consumes: `ApplyClaim::get` (Task 1).
- Produces: `Repository::enrich_admin_entries_with_apply_claims( array $entries ): array` — sets `apply.claim` (value or `null`) on every entry with `apply.status === 'pending'`; non-pending entries untouched.

- [ ] **Step 1: Write the failing enrichment test**

Append to `tests/phpunit/ActivityRepositoryTest.php` (it already uses `Repository` + `WordPressTestState`; add `use FlavorAgent\Apply\ApplyClaim;` to the imports if absent):
```php
	public function test_enrich_admin_entries_attaches_claim_to_pending_rows_only(): void {
		set_transient(
			'flavor_agent_apply_claim_' . md5( 'pending-1' ),
			[ 'userId' => 7, 'claimedAt' => '2026-06-25T00:00:00+00:00' ],
			ApplyClaim::TTL
		);

		$entries = [
			[ 'id' => 'pending-1', 'apply' => [ 'status' => 'pending' ] ],
			[ 'id' => 'pending-2', 'apply' => [ 'status' => 'pending' ] ],
			[ 'id' => 'applied-1', 'apply' => [ 'status' => 'available' ] ],
			[ 'id' => 'plain-1' ],
		];

		$enriched = Repository::enrich_admin_entries_with_apply_claims( $entries );

		$this->assertSame( 7, $enriched[0]['apply']['claim']['userId'] );
		$this->assertNull( $enriched[1]['apply']['claim'] );
		$this->assertArrayNotHasKey( 'claim', $enriched[2]['apply'] );
		$this->assertArrayNotHasKey( 'apply', $enriched[3] );
	}

	public function test_enrich_admin_entries_skips_the_claim_on_an_overdue_pending_row(): void {
		set_transient(
			'flavor_agent_apply_claim_' . md5( 'overdue-1' ),
			[ 'userId' => 7, 'claimedAt' => '2026-06-25T00:00:00+00:00' ],
			ApplyClaim::TTL
		);

		$entries = [
			[
				'id'    => 'overdue-1',
				'apply' => [
					'status'    => 'pending',
					'expiresAt' => gmdate( 'c', time() - 60 ),
				],
			],
		];

		$enriched = Repository::enrich_admin_entries_with_apply_claims( $entries );

		// Overdue pending rows never advertise a review claim, and the read path
		// performs no DB write to materialize the expiry (that stays on the action
		// paths + the daily prune sweep).
		$this->assertArrayNotHasKey( 'claim', $enriched[0]['apply'] );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/phpunit/ActivityRepositoryTest.php --filter test_enrich_admin_entries_attaches_claim_to_pending_rows_only`
Expected: FAIL — `Repository::enrich_admin_entries_with_apply_claims` not defined.

- [ ] **Step 3: Implement the helper**

In `inc/Activity/Repository.php`, add a public static method (place it directly after `query_admin`, ~`:530`):
```php
	/**
	 * Attach the advisory review claim to each pending external-apply row.
	 * Transient-only, single bounded pass; pending external applies are few.
	 *
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<int, array<string, mixed>>
	 */
	public static function enrich_admin_entries_with_apply_claims( array $entries ): array {
		$now = time();

		return array_map(
			static function ( $entry ) use ( $now ) {
				if ( ! is_array( $entry ) ) {
					return $entry;
				}

				$apply = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : null;

				if ( null === $apply || 'pending' !== (string) ( $apply['status'] ?? '' ) ) {
					return $entry;
				}

				// Pure-read overdue guard (NO db write): an apply past its expiresAt is
				// effectively expired and materializes to 'expired' on the next
				// claim/decision action (or the daily prune sweep). Don't advertise a
				// possibly-stale review claim on it. This deliberately does NOT call
				// maybe_expire_pending_apply — the admin feed is a read projection and
				// must not persist transitions; expiry stays an action-path concern.
				$expires_at = strtotime( (string) ( $apply['expiresAt'] ?? '' ) );

				if ( false !== $expires_at && $expires_at <= $now ) {
					return $entry;
				}

				$entry['apply']['claim'] = ApplyClaim::get( (string) ( $entry['id'] ?? '' ) );

				return $entry;
			},
			$entries
		);
	}
```

- [ ] **Step 4: Wire enrichment into the global-feed response**

In `inc/REST/Agent_Controller.php`, change the global-request return (`:523-525`). Find:
```php
			);

			return new \WP_REST_Response( $result, 200 );
		}
```
Replace with:
```php
			);

			if ( \is_array( $result['entries'] ?? null ) ) {
				$result['entries'] = ActivityRepository::enrich_admin_entries_with_apply_claims( $result['entries'] );
			}

			return new \WP_REST_Response( $result, 200 );
		}
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/phpunit/ActivityRepositoryTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add inc/Activity/Repository.php inc/REST/Agent_Controller.php tests/phpunit/ActivityRepositoryTest.php
git commit -m "feat(activity): enrich pending external-apply admin rows with apply.claim"
```

---

## Task 5: Boot field — `currentUserId` in the admin localize array

**Files:**
- Modify: `inc/Admin/ActivityPage.php` (extract the boot array from `enqueue_assets` `:199-215` into a testable private method; add `currentUserId`)
- Modify: `tests/phpunit/ActivityPageTest.php`

**Interfaces:**
- Produces: `ActivityPage::build_activity_log_boot_data(): array` (private, reflection-tested) containing `currentUserId => get_current_user_id()` plus the existing fields. `flavorAgentActivityLog.currentUserId` reaches JS as a string (`wp_localize_script` stringifies scalars).

> Why extract a method: `enqueue_assets` is private and calls `wp_localize_script` (not stubbed in the test bootstrap), and no test exercises the localize payload today. The codebase already tests boot-data builders by reflection (`get_theme_color_presets`, `ActivityPageTest.php:139-178`). Extracting the array into a named method makes the field testable the same way, without depending on `wp_localize_script` being stubbed. DRY-positive: the boot data lives in one named method.

- [ ] **Step 1: Write the failing test (reflection on the boot-data method)**

Append to `tests/phpunit/ActivityPageTest.php`, mirroring the `get_theme_color_presets` reflection test (`:139-178`):
```php
	public function test_build_activity_log_boot_data_includes_current_user_id(): void {
		WordPressTestState::$current_user_id = 42;

		$method = new \ReflectionMethod( ActivityPage::class, 'build_activity_log_boot_data' );
		$method->setAccessible( true );

		$data = $method->invoke( null );

		$this->assertArrayHasKey( 'currentUserId', $data );
		$this->assertSame( 42, $data['currentUserId'] );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/phpunit/ActivityPageTest.php --filter test_build_activity_log_boot_data_includes_current_user_id`
Expected: FAIL — `ReflectionException: Method ... build_activity_log_boot_data() does not exist`.

- [ ] **Step 3: Extract the boot-data method and add the field**

In `inc/Admin/ActivityPage.php`, replace the `wp_localize_script( … [ … ] )` call (`:199-215`) so the array comes from a new private method. Change:
```php
		wp_localize_script(
			'flavor-agent-activity-log',
			'flavorAgentActivityLog',
			[
				'adminUrl'               => admin_url(),
				'canApproveStyleApplies' => current_user_can( 'edit_theme_options' ),
				'connectorsUrl'          => admin_url( 'options-connectors.php' ),
				'defaultPerPage'         => ActivityRepository::DEFAULT_PER_PAGE,
				'locale'                 => self::resolve_locale(),
				'maxPerPage'             => ActivityRepository::MAX_PER_PAGE,
				'nonce'                  => wp_create_nonce( 'wp_rest' ),
				'restUrl'                => rest_url(),
				'settingsUrl'            => admin_url( 'options-general.php?page=flavor-agent' ),
				'themeColorPresets'      => self::get_theme_color_presets(),
				'timeZone'               => self::resolve_timezone(),
			]
		);
	}
```
to:
```php
		wp_localize_script(
			'flavor-agent-activity-log',
			'flavorAgentActivityLog',
			self::build_activity_log_boot_data()
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_activity_log_boot_data(): array {
		return [
			'adminUrl'               => admin_url(),
			'canApproveStyleApplies' => current_user_can( 'edit_theme_options' ),
			'connectorsUrl'          => admin_url( 'options-connectors.php' ),
			'currentUserId'          => get_current_user_id(),
			'defaultPerPage'         => ActivityRepository::DEFAULT_PER_PAGE,
			'locale'                 => self::resolve_locale(),
			'maxPerPage'             => ActivityRepository::MAX_PER_PAGE,
			'nonce'                  => wp_create_nonce( 'wp_rest' ),
			'restUrl'                => rest_url(),
			'settingsUrl'            => admin_url( 'options-general.php?page=flavor-agent' ),
			'themeColorPresets'      => self::get_theme_color_presets(),
			'timeZone'               => self::resolve_timezone(),
		];
	}
```

- [ ] **Step 4: Run to verify it passes, then commit**

Run: `vendor/bin/phpunit tests/phpunit/ActivityPageTest.php`
Expected: PASS.
```bash
git add inc/Admin/ActivityPage.php tests/phpunit/ActivityPageTest.php
git commit -m "feat(activity): localize currentUserId for advisory claim attribution"
```

---

## Task 6: JS utility helpers — claim request builders, badge notice, terminal codes

**Files:**
- Modify: `src/admin/activity-log-utils.js`
- Modify: `src/admin/__tests__/activity-log-utils.test.js`

**Interfaces:**
- Consumes: existing private `formatUserIdLabel` (`:1663`), existing `getExternalApplyDetails` (`:1581`), `@wordpress/date` `humanTimeDiff`, `@wordpress/i18n` `__`/`sprintf` (already imported).
- Produces (exports):
  - `buildClaimRequest( bootData, activityId ) → { url, method: 'POST', headers }`
  - `buildClaimReleaseRequest( bootData, activityId ) → { url, method: 'DELETE', headers }`
  - `formatApplyClaimNotice( claim, currentUserId, now? ) → { isSelf: boolean, text: string } | null`
  - `TERMINAL_DECISION_ERROR_CODES` (`string[]`)
  - extended `normalizeActivityDiscoveryBadges( entry, currentUserId? )` — emits the `apply-claim` feed badge (finding 1); back-compatible second arg.

- [ ] **Step 1: Write the failing helper tests**

In `src/admin/__tests__/activity-log-utils.test.js`, add the new symbols to the import block at the top and append tests inside the `external apply helpers` describe (or a new describe):
```js
import {
	// ...existing imports...
	buildClaimRequest,
	buildClaimReleaseRequest,
	formatApplyClaimNotice,
	TERMINAL_DECISION_ERROR_CODES,
} from '../activity-log-utils';
```
```js
describe( 'advisory apply claim helpers', () => {
	const bootData = {
		restUrl: 'https://example.test/wp-json/',
		nonce: 'n0nce',
	};

	test( 'buildClaimRequest shapes a POST for apiFetch', () => {
		expect( buildClaimRequest( bootData, 'act/1' ) ).toEqual( {
			url: 'https://example.test/wp-json/flavor-agent/v1/activity/act%2F1/claim',
			method: 'POST',
			headers: { 'X-WP-Nonce': 'n0nce' },
		} );
	} );

	test( 'buildClaimReleaseRequest shapes a DELETE for apiFetch', () => {
		expect( buildClaimReleaseRequest( bootData, 'act-2' ) ).toEqual( {
			url: 'https://example.test/wp-json/flavor-agent/v1/activity/act-2/claim',
			method: 'DELETE',
			headers: { 'X-WP-Nonce': 'n0nce' },
		} );
	} );

	test( 'formatApplyClaimNotice returns self copy when the claim is the viewer’s', () => {
		const notice = formatApplyClaimNotice(
			{ userId: 7, claimedAt: '2026-06-25T00:00:00+00:00' },
			'7'
		);
		expect( notice.isSelf ).toBe( true );
		expect( notice.text ).toMatch( /reviewing/i );
	} );

	test( 'formatApplyClaimNotice labels another reviewer with User #id', () => {
		const notice = formatApplyClaimNotice(
			{ userId: 5, claimedAt: '2026-06-25T00:00:00+00:00' },
			7,
			new Date( '2026-06-25T00:03:00+00:00' )
		);
		expect( notice.isSelf ).toBe( false );
		expect( notice.text ).toContain( 'User #5' );
	} );

	test( 'formatApplyClaimNotice returns null for an absent or invalid claim', () => {
		expect( formatApplyClaimNotice( null, 7 ) ).toBeNull();
		expect( formatApplyClaimNotice( { userId: 0 }, 7 ) ).toBeNull();
	} );

	test( 'TERMINAL_DECISION_ERROR_CODES are exactly the three terminal codes', () => {
		expect( TERMINAL_DECISION_ERROR_CODES ).toEqual( [
			'flavor_agent_apply_invalid_transition',
			'flavor_agent_apply_not_pending',
			'flavor_agent_apply_expired',
		] );
	} );
} );
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js -t 'advisory apply claim helpers'`
Expected: FAIL — the new exports are undefined.

- [ ] **Step 3: Implement the helpers**

In `src/admin/activity-log-utils.js`, add `humanTimeDiff` to the `@wordpress/date` import (or add a new import line if the module isn't imported yet):
```js
import { humanTimeDiff } from '@wordpress/date';
```
Then add (near `buildDecisionRequest`, ~`:2567`):
```js
export const TERMINAL_DECISION_ERROR_CODES = [
	'flavor_agent_apply_invalid_transition',
	'flavor_agent_apply_not_pending',
	'flavor_agent_apply_expired',
];

function buildClaimUrl( bootData, activityId ) {
	return `${
		bootData?.restUrl || ''
	}flavor-agent/v1/activity/${ encodeURIComponent( activityId ) }/claim`;
}

export function buildClaimRequest( bootData, activityId ) {
	return {
		url: buildClaimUrl( bootData, activityId ),
		method: 'POST',
		headers: { 'X-WP-Nonce': bootData?.nonce || '' },
	};
}

export function buildClaimReleaseRequest( bootData, activityId ) {
	return {
		url: buildClaimUrl( bootData, activityId ),
		method: 'DELETE',
		headers: { 'X-WP-Nonce': bootData?.nonce || '' },
	};
}

/**
 * Resolve a pending row's advisory claim into a passive badge notice.
 *
 * @param {Object|null} claim         { userId, claimedAt } from apply.claim, or null.
 * @param {number|string} currentUserId The viewer id (localize stringifies it).
 * @param {Date} [now]                 Injectable "now" for deterministic tests.
 * @return {{isSelf: boolean, text: string}|null} Notice descriptor, or null when absent.
 */
export function formatApplyClaimNotice( claim, currentUserId, now ) {
	if ( ! claim || typeof claim !== 'object' ) {
		return null;
	}

	const claimUserId = Number( claim.userId );

	if ( ! Number.isFinite( claimUserId ) || claimUserId <= 0 ) {
		return null;
	}

	const viewerId = Number( currentUserId );
	const isSelf =
		Number.isFinite( viewerId ) && viewerId > 0 && viewerId === claimUserId;

	if ( isSelf ) {
		return {
			isSelf: true,
			text: __( 'You’re reviewing this.', 'flavor-agent' ),
		};
	}

	const label = formatUserIdLabel( claimUserId );
	const relative =
		typeof claim.claimedAt === 'string' && claim.claimedAt
			? humanTimeDiff( claim.claimedAt, now )
			: '';

	return {
		isSelf: false,
		text: relative
			? sprintf(
					/* translators: 1: user label e.g. "User #5"; 2: relative time e.g. "3 minutes". */
					__( '%1$s is reviewing · %2$s', 'flavor-agent' ),
					label,
					relative
			  )
			: sprintf(
					/* translators: %s: user label e.g. "User #5". */
					__( '%s is reviewing', 'flavor-agent' ),
					label
			  ),
	};
}
```

- [ ] **Step 4: Add the claim badge to the feed-badge generator (finding 1 — the queue badge)**

The DataViews queue renders per-row badges from `normalizeActivityDiscoveryBadges` (`:2765`, consumed at `src/admin/activity-log.js:2921`). The spec requires the "being reviewed by X" badge in **both** the feed and the selected-row panel (`docs/superpowers/specs/…:89`); without this admins cannot see a claim from the queue itself. `normalizeActivityEntry` (`:3153`) passes `entry.apply` through wholesale, so the server-enriched `apply.claim` (Task 4) is already present on feed rows. First add the failing tests to `src/admin/__tests__/activity-log-utils.test.js` (confirm `normalizeActivityDiscoveryBadges` is in the import block — add it if absent):
```js
test( 'normalizeActivityDiscoveryBadges adds a claim badge for a row reviewed by another user', () => {
	const badges = normalizeActivityDiscoveryBadges(
		{ apply: { status: 'pending', claim: { userId: 5, claimedAt: '2026-06-25T00:00:00+00:00' } } },
		7
	);
	const claimBadge = badges.find( ( badge ) => badge.id === 'apply-claim' );
	expect( claimBadge ).toBeTruthy();
	expect( claimBadge.label ).toContain( 'User #5' );
	expect( claimBadge.tone ).toBe( 'warning' );
} );

test( 'normalizeActivityDiscoveryBadges omits the claim badge when there is no claim', () => {
	const badges = normalizeActivityDiscoveryBadges( { apply: { status: 'pending' } }, 7 );
	expect( badges.find( ( badge ) => badge.id === 'apply-claim' ) ).toBeUndefined();
} );
```
Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js -t 'claim badge'`
Expected: FAIL — no `apply-claim` badge; the second argument is ignored.

Then change `normalizeActivityDiscoveryBadges` (`:2765`) to accept `currentUserId` and push the badge (reuses `formatApplyClaimNotice` — same module, no import). Update the signature:
```js
export function normalizeActivityDiscoveryBadges( entry = {}, currentUserId ) {
```
and add the badge immediately after the existing `pending-governance` push block (after `:2786`):
```js
		const claimNotice = formatApplyClaimNotice(
			entry?.apply?.claim,
			currentUserId
		);

		if ( claimNotice ) {
			badges.push( {
				id: 'apply-claim',
				label: claimNotice.text,
				tone: claimNotice.isSelf ? 'info' : 'warning',
			} );
		}
```
Run the same `-t 'claim badge'` command → PASS. Existing single-argument callers stay valid: with no `currentUserId` and no `apply.claim`, no badge is added, so the file's existing `normalizeActivityDiscoveryBadges` tests remain green.

- [ ] **Step 5: Run the full utils suite to verify it passes**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js`
Expected: PASS.

- [ ] **Step 6: Lint and commit**

Run: `npm run lint:js -- --fix src/admin/activity-log-utils.js src/admin/__tests__/activity-log-utils.test.js`
Expected: no errors.
```bash
git add src/admin/activity-log-utils.js src/admin/__tests__/activity-log-utils.test.js
git commit -m "feat(activity-log): add claim request builders, badge notice, terminal-code constant"
```

---

## Component-test harness (real helpers — Tasks 7–10 use these verbatim)

`src/admin/__tests__/activity-log.test.js` already provides the primitives below. The new tests MUST use them — do not invent `flushPromises`/`render`/selection utilities.

- **Render:** `await renderApp( response?, { bootData } = {} )` (`:367`). If `response` is **omitted (`undefined`)**, it does **not** touch `apiFetch`, so a previously-set `apiFetch.mockImplementation(...)` survives — this is how multi-endpoint tests (feed + `/claim` + `/decision`) route by URL/method. If `response` is an array, it becomes the feed via `buildResponse`.
- **Flush:** `await flushEffects()` (`:360`) = `act(async () => { await Promise.resolve(); await Promise.resolve(); })`. There is no `flushPromises`.
- **Select a specific row:** set the deep-link URL **before** `renderApp` so the app auto-selects (and, for a pending row, opens the governance panel → auto-claim fires on mount):
  ```js
  window.history.replaceState( null, '', '/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-9' );
  ```
- **Find a button:** `Array.from( getContainer().querySelectorAll( 'button' ) ).find( ( b ) => b.textContent.includes( 'Approve and apply' ) )` (Reject = `'Reject'`).
- **Click + flush:** `await act( async () => { button.click(); } ); await flushEffects();`
- **Boot the viewer id:** pass `await renderApp( undefined, { bootData: { currentUserId: 7 } } )` so the self/other badge logic resolves (`BOOT_DATA` gets `currentUserId: 7` added once in Task 7 Step 1).
- **Reset:** `apiFetch.mockReset()` runs in the file's `beforeEach` (`:456`) — set your `mockImplementation` inside each test.

The existing reference test to copy is **"shows approve and reject actions for pending external applies and posts the decision"** (`:2425`).

---

## Task 7: JS — pinned terminal-entry state machine

**Files:**
- Modify: `src/admin/activity-log.js` (`ActivityLogApp`: state at ~`:2611`; `handleEntryDecided` ~`:2687`; `selectedEntry` ~`:3202`; selection-clear effect ~`:3221`)
- Modify: `src/admin/__tests__/activity-log.test.js`

**Interfaces:**
- Consumes: existing `handleEntryDecided( activityId, decidedEntry )`, `normalizeAdminEntries`, `isPlainRecord`.
- Produces:
  - state `pinnedTerminalEntry` + setter
  - `selectedEntry` falls back to `pinnedTerminalEntry` when the row leaves `responseData.entries`
  - `handleEntryDecided` pins a terminal (`status !== 'pending'`) decided entry
  - the selection-clear effect never clears/reassigns a pinned selection
  - `applyClaimResponse( activityId, response )` callback (used by Tasks 9–10)

- [ ] **Step 1: Write the failing component test (terminal row survives the pending-only filter)**

In `src/admin/__tests__/activity-log.test.js`, add `currentUserId: 7` to `BOOT_DATA` (`:236` area) and add a test that decides a row, then refetches a feed that no longer contains it, asserting the terminal state stays visible. Mirror the existing render+`apiFetch.mockImplementation` patterns in the file:
```js
test( 'pins the terminal entry so a decided row survives leaving the pending-only feed', async () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-external-apply'
	);

	const pending = createExternalApplyEntry();
	const decided = {
		...pending,
		status: 'rejected',
		apply: { ...pending.apply, status: 'rejected', decidedBy: 7 },
	};

	let feedLoads = 0;

	apiFetch.mockImplementation( ( request ) => {
		if ( request?.url?.includes( '/decision' ) ) {
			return Promise.resolve( { entry: decided } );
		}
		if ( request?.url?.includes( '/claim' ) ) {
			// Defensive: Task 9's auto-claim fires on mount once it lands.
			return Promise.resolve( { claim: { userId: 7 }, entry: pending } );
		}
		feedLoads += 1;
		// 1st feed load has the pending row; after the decision the pending-only
		// feed no longer includes it.
		return Promise.resolve(
			buildResponse( feedLoads <= 1 ? [ pending ] : [] )
		);
	} );

	await renderApp( undefined, { bootData: { currentUserId: 7 } } );

	const rejectButton = Array.from(
		getContainer().querySelectorAll( 'button' )
	).find( ( button ) => button.textContent.trim() === 'Reject' );

	await act( async () => {
		rejectButton.click();
	} );
	await flushEffects();

	// The pinned terminal entry keeps the details panel populated even though the
	// feed refetch returned zero entries.
	expect( getContainer().textContent ).toMatch( /Rejected/i );
} );
```
The row is auto-selected by the `&activity=` deep link set at the top of the test (see the Component-test harness note); the reference flow is the existing `:2425` decision test.

- [ ] **Step 2: Run to verify it fails**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'pins the terminal entry'`
Expected: FAIL — after the empty refetch the details panel clears (selection reset), so "Rejected" is gone.

- [ ] **Step 3: Add `pinnedTerminalEntry` state and the `applyClaimResponse` callback**

In `ActivityLogApp`, after the `locallyDecidedEntryIds` state (`:2612-2614`), add:
```js
	const [ pinnedTerminalEntry, setPinnedTerminalEntry ] = useState( null );
```

- [ ] **Step 4: Pin on terminal decisions inside `handleEntryDecided`**

Replace the `if ( normalizedEntry?.id )` block inside `handleEntryDecided` (`:2714-2723`):
```js
				if ( normalizedEntry?.id ) {
					setResponseData( ( current ) => ( {
						...current,
						entries: current.entries.map( ( existingEntry ) =>
							existingEntry.id === normalizedEntry.id
								? normalizedEntry
								: existingEntry
						),
					} ) );
				}
```
with:
```js
				if ( normalizedEntry?.id ) {
					setResponseData( ( current ) => ( {
						...current,
						entries: current.entries.map( ( existingEntry ) =>
							existingEntry.id === normalizedEntry.id
								? normalizedEntry
								: existingEntry
						),
					} ) );

					// A terminal (decided) entry is pinned so it stays legible after
					// it drops out of the pending-only Approvals filter on refetch.
					if ( normalizedEntry.status !== 'pending' ) {
						setPinnedTerminalEntry( normalizedEntry );
					}
				}
```

- [ ] **Step 5: Add the shared `applyClaimResponse` callback**

Directly after `handleEntryDecided` (`:2729`), add:
```js
	const applyClaimResponse = useCallback(
		( activityId, response ) => {
			if ( ! isPlainRecord( response ) ) {
				return;
			}

			const responseEntry = response.entry;

			// /claim returns ActivityRepository::find()-shaped rows, whose lifecycle
			// status lives at entry.apply.status — there is NO top-level entry.status
			// (Serializer::hydrate_row synthesizes none; only the client-side
			// normalizeActivityEntry does, and this response is NOT normalized).
			// Reading entry.status here would be undefined for every successful
			// pending claim and wrongly route it through handleEntryDecided, hiding
			// the decision controls on a still-pending row.
			const responseApply = isPlainRecord( responseEntry )
				? responseEntry.apply
				: null;
			const responseApplyStatus =
				isPlainRecord( responseApply ) &&
				typeof responseApply.status === 'string'
					? responseApply.status
					: '';

			// Decided/expired elsewhere (or by us): the row came back terminal — pin it.
			if ( responseApplyStatus && responseApplyStatus !== 'pending' ) {
				handleEntryDecided( activityId, responseEntry );
				return;
			}

			// Still pending: merge the live claim onto the selected row so the
			// badge reflects the current reviewer immediately.
			const claim = isPlainRecord( response.claim ) ? response.claim : null;
			setResponseData( ( current ) => ( {
				...current,
				entries: current.entries.map( ( existingEntry ) =>
					existingEntry.id === activityId &&
					isPlainRecord( existingEntry.apply )
						? {
								...existingEntry,
								apply: { ...existingEntry.apply, claim },
						  }
						: existingEntry
				),
			} ) );
		},
		[ handleEntryDecided ]
	);
```
Confirm `isPlainRecord` is already imported in this file (it is used by `handleEntryDecided` at `:2706`). `useCallback` is already imported.

- [ ] **Step 6: Make `selectedEntry` fall back to the pinned entry**

Replace `selectedEntry` (`:3202-3205`):
```js
	const selectedEntry =
		responseData.entries.find(
			( entry ) => entry.id === selectedEntryId
		) || null;
```
with:
```js
	const selectedEntry =
		responseData.entries.find(
			( entry ) => entry.id === selectedEntryId
		) ||
		( pinnedTerminalEntry && pinnedTerminalEntry.id === selectedEntryId
			? pinnedTerminalEntry
			: null );
```

- [ ] **Step 7: Guard the selection-clear effect and clear the pin on navigate**

At the top of the selection-reconciliation effect (`:3221`, immediately inside the `useEffect`), add:
```js
		// A pinned terminal row stays selected even after it leaves the filtered feed.
		if (
			pinnedTerminalEntry?.id &&
			pinnedTerminalEntry.id === selectedEntryId
		) {
			return;
		}
```
Add `pinnedTerminalEntry` to that effect's dependency array (`:3249-3255`).

Then add a new effect right after it to drop the pin when the reviewer navigates to a different row:
```js
	useEffect( () => {
		if ( pinnedTerminalEntry && pinnedTerminalEntry.id !== selectedEntryId ) {
			setPinnedTerminalEntry( null );
		}
	}, [ pinnedTerminalEntry, selectedEntryId ] );
```

- [ ] **Step 8: Run to verify it passes**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'pins the terminal entry'`
Expected: PASS.

- [ ] **Step 9: Run the full file to confirm no regressions, then commit**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js`
Expected: PASS.
```bash
git add src/admin/activity-log.js src/admin/__tests__/activity-log.test.js
git commit -m "feat(activity-log): pin terminal entry so decided rows survive the pending-only filter"
```

---

## Task 8: JS — `submitDecision` terminal-code race-loss handling

**Files:**
- Modify: `src/admin/activity-log.js` (`GovernanceEvidenceSection.submitDecision` ~`:2350`; import the new symbols)
- Modify: `src/admin/__tests__/activity-log.test.js`

**Interfaces:**
- Consumes: `buildClaimRequest`, `TERMINAL_DECISION_ERROR_CODES` (Task 6); `onDecided` (→ `handleEntryDecided`, which now pins, Task 7).
- Produces: on a terminal decision code, `submitDecision` fetches the terminal row via one `POST /claim` and pins it through `onDecided`; on a retryable `500` it keeps the existing inline error (no pin, no release).

- [ ] **Step 1: Write the failing test (race-lost invalid_transition pins instead of erroring)**

Add to `src/admin/__tests__/activity-log.test.js`:
```js
test( 'a race-lost invalid_transition decision pins the terminal entry instead of a generic error', async () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-external-apply'
	);

	const pending = createExternalApplyEntry();
	const terminal = {
		...pending,
		status: 'rejected',
		apply: { ...pending.apply, status: 'rejected', decidedBy: 9 },
	};
	const raceError = Object.assign(
		new Error( 'Flavor Agent external applies only transition out of the pending state once.' ),
		{ code: 'flavor_agent_apply_invalid_transition' }
	);

	apiFetch.mockImplementation( ( request ) => {
		if ( request?.url?.includes( '/decision' ) ) {
			return Promise.reject( raceError );
		}
		if ( request?.url?.includes( '/claim' ) && request?.method === 'POST' ) {
			return Promise.resolve( { claim: null, entry: terminal } );
		}
		if ( request?.url?.includes( '/claim' ) ) {
			return Promise.resolve( { claim: null, entry: terminal } );
		}
		return Promise.resolve( buildResponse( [ pending ] ) );
	} );

	await renderApp( undefined, { bootData: { currentUserId: 7 } } );

	const rejectButton = Array.from(
		getContainer().querySelectorAll( 'button' )
	).find( ( button ) => button.textContent.trim() === 'Reject' );

	await act( async () => {
		rejectButton.click();
	} );
	await flushEffects();

	expect( getContainer().textContent ).not.toMatch(
		/The decision could not be recorded/i
	);
	expect( getContainer().textContent ).toMatch( /Rejected/i );
} );
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'race-lost invalid_transition'`
Expected: FAIL — the generic "could not be recorded" error shows instead of the pinned terminal state.

- [ ] **Step 3: Import the new symbols into `activity-log.js`**

Extend the import from `./activity-log-utils` (`:30-46`) to include **only the two symbols this task uses** (importing `buildClaimReleaseRequest`/`formatApplyClaimNotice` now would trip `no-unused-vars` at this task's lint step — Task 9 adds them):
```js
	buildClaimRequest,
	TERMINAL_DECISION_ERROR_CODES,
```

- [ ] **Step 4: Handle terminal codes in `submitDecision`**

Replace the `catch` block in `submitDecision` (`:2371-2380`):
```js
		} catch ( error ) {
			if ( decisionRequestTokenRef.current !== requestToken ) {
				return;
			}

			setDecisionError(
				error?.message ||
					__( 'The decision could not be recorded.', 'flavor-agent' )
			);
		} finally {
```
with:
```js
		} catch ( error ) {
			if ( decisionRequestTokenRef.current !== requestToken ) {
				return;
			}

			const code = typeof error?.code === 'string' ? error.code : '';

			// Terminal race-loss: the row was decided by another admin. Fetch the
			// terminal row via one claim call and pin it (legible conflict) instead
			// of showing a generic error. invalid_transition is the genuine
			// simultaneous-loss code; not_pending/expired are the re-read cases.
			if ( TERMINAL_DECISION_ERROR_CODES.includes( code ) ) {
				try {
					const claimResponse = await apiFetch(
						buildClaimRequest( bootData, entry.id )
					);

					if ( decisionRequestTokenRef.current !== requestToken ) {
						return;
					}

					onDecided?.( entry.id, claimResponse?.entry );
					return;
				} catch ( claimError ) {
					// Fall through to the inline error if the claim fetch also fails.
				}
			}

			// Retryable failures (500) and any unresolved terminal fetch: leave the
			// row pending, keep the claim, and show the inline retry error.
			setDecisionError(
				error?.message ||
					__( 'The decision could not be recorded.', 'flavor-agent' )
			);
		} finally {
```

- [ ] **Step 5: Run to verify it passes**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'race-lost invalid_transition'`
Expected: PASS.

- [ ] **Step 6: Add the retryable-500 test (claim kept, inline error shown)**

```js
test( 'a retryable 500 decision keeps the claim and shows the inline retry error', async () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-external-apply'
	);

	const pending = createExternalApplyEntry();
	const retryError = Object.assign( new Error( 'Flavor Agent could not update the activity entry.' ), {
		code: 'flavor_agent_activity_update_failed',
		data: { status: 500 },
	} );
	const releaseSpy = jest.fn();

	apiFetch.mockImplementation( ( request ) => {
		if ( request?.url?.includes( '/decision' ) ) {
			return Promise.reject( retryError );
		}
		if ( request?.url?.includes( '/claim' ) && request?.method === 'DELETE' ) {
			releaseSpy();
			return Promise.resolve( { claim: null, entry: pending } );
		}
		if ( request?.url?.includes( '/claim' ) ) {
			return Promise.resolve( { claim: { userId: 7 }, entry: pending } );
		}
		return Promise.resolve( buildResponse( [ pending ] ) );
	} );

	await renderApp( undefined, { bootData: { currentUserId: 7 } } );

	const rejectButton = Array.from(
		getContainer().querySelectorAll( 'button' )
	).find( ( button ) => button.textContent.trim() === 'Reject' );

	await act( async () => {
		rejectButton.click();
	} );
	await flushEffects();

	expect( getContainer().textContent ).toMatch(
		/could not update the activity entry/i
	);
	// Row stays mounted (no abandon) and the decision was submitted, so the claim
	// is never released on a retryable failure.
	expect( releaseSpy ).not.toHaveBeenCalled();
} );
```
Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'retryable 500'`
Expected: PASS.

- [ ] **Step 7: Lint, run the file, commit**

Run: `npm run lint:js -- --fix src/admin/activity-log.js src/admin/__tests__/activity-log.test.js`
Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js`
Expected: PASS.
```bash
git add src/admin/activity-log.js src/admin/__tests__/activity-log.test.js
git commit -m "feat(activity-log): pin terminal row on race-lost decision; keep claim on retryable 500"
```

---

## Task 9: JS — auto-claim/release + reviewer badge in the decision panel

**Files:**
- Modify: `src/admin/activity-log.js` (`GovernanceEvidenceSection` ~`:2287-2483`; pass `onClaimResolved`/`currentUserId` from `ActivityEntryDetails`/`ActivityLogApp`)
- Modify: `src/admin/__tests__/activity-log.test.js`

**Interfaces:**
- Consumes: `buildClaimRequest`, `buildClaimReleaseRequest`, `formatApplyClaimNotice` (Task 6); `applyClaimResponse` (Task 7); `bootData.currentUserId` (Task 5).
- Produces: opening a pending row's decision controls auto-claims; abandoning (entry change/unmount without a submitted decision) releases; a passive badge renders from `entry.apply.claim` vs `currentUserId`; the decision buttons are never disabled by a claim.

- [ ] **Step 1: Write failing tests (badge + auto-claim + release-on-abandon)**

```js
const PENDING_DEEP_LINK =
	'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-external-apply';

test( 'auto-claims on opening a pending decision and shows the "you’re reviewing" badge', async () => {
	window.history.replaceState( null, '', PENDING_DEEP_LINK );

	const pending = createExternalApplyEntry();
	// The server /claim response is an ActivityRepository::find()-shaped row: status
	// lives ONLY at apply.status, with no top-level entry.status. createExternalApplyEntry
	// models the *admin feed* row (which DOES carry a top-level status), so strip it
	// here to exercise the real claim-response shape and catch any regression that
	// reads entry.status instead of apply.status.
	const claimEntry = { ...pending };
	delete claimEntry.status;
	delete claimEntry.statusLabel;
	const claimSpy = jest.fn();

	apiFetch.mockImplementation( ( request ) => {
		if ( request?.url?.includes( '/claim' ) && request?.method === 'POST' ) {
			claimSpy();
			return Promise.resolve( {
				claim: { userId: 7, claimedAt: '2026-06-25T00:00:00+00:00' },
				entry: claimEntry,
			} );
		}
		return Promise.resolve( buildResponse( [ pending ] ) );
	} );

	// The row is auto-selected by the deep link → GovernanceEvidenceSection mounts → auto-claim.
	await renderApp( undefined, { bootData: { currentUserId: 7 } } );

	expect( claimSpy ).toHaveBeenCalled();
	expect( getContainer().textContent ).toMatch( /reviewing this/i );
	// The find()-shaped claim entry has no top-level status; applyClaimResponse must
	// read apply.status (still 'pending'), NOT entry.status — otherwise it pins the
	// row as decided and hides the controls. The Reject control must still be present.
	const rejectButton = Array.from(
		getContainer().querySelectorAll( 'button' )
	).find( ( button ) => button.textContent.trim() === 'Reject' );
	expect( rejectButton ).toBeTruthy();
} );

test( 'shows another reviewer’s claim as a passive note without disabling the buttons', async () => {
	window.history.replaceState( null, '', PENDING_DEEP_LINK );

	const pending = createExternalApplyEntry( {
		apply: {
			...createExternalApplyEntry().apply,
			claim: { userId: 5, claimedAt: '2026-06-25T00:00:00+00:00' },
		},
	} );

	apiFetch.mockImplementation( ( request ) => {
		if ( request?.url?.includes( '/claim' ) ) {
			return Promise.resolve( { claim: { userId: 5 }, entry: pending } );
		}
		return Promise.resolve( buildResponse( [ pending ] ) );
	} );

	await renderApp( undefined, { bootData: { currentUserId: 7 } } );

	expect( getContainer().textContent ).toContain( 'User #5' );
	// The Approve button remains enabled (claims never gate the decision).
	const approveButton = [ ...getContainer().querySelectorAll( 'button' ) ].find(
		( button ) => /approve and apply/i.test( button.textContent )
	);
	expect( approveButton?.disabled ).toBeFalsy();
} );

test( 'releases the claim on abandon (unmount) and not after a decision submit', async () => {
	window.history.replaceState( null, '', PENDING_DEEP_LINK );

	const pending = createExternalApplyEntry();
	const releaseSpy = jest.fn();

	apiFetch.mockImplementation( ( request ) => {
		if ( request?.url?.includes( '/claim' ) && request?.method === 'DELETE' ) {
			releaseSpy();
			return Promise.resolve( { claim: null, entry: pending } );
		}
		if ( request?.url?.includes( '/claim' ) ) {
			return Promise.resolve( { claim: { userId: 7 }, entry: pending } );
		}
		return Promise.resolve( buildResponse( [ pending ] ) );
	} );

	await renderApp( undefined, { bootData: { currentUserId: 7 } } );

	// Unmount = abandon: the auto-claim effect cleanup releases because no decision
	// was submitted. (getRoot().unmount runs the same cleanup as deselect/navigate.)
	await act( async () => {
		getRoot().unmount();
	} );

	expect( releaseSpy ).toHaveBeenCalled();
} );

test( 'a retryable failure then abandon releases the claim (not leaked to TTL)', async () => {
	window.history.replaceState( null, '', PENDING_DEEP_LINK );

	const pending = createExternalApplyEntry();
	const retryError = Object.assign(
		new Error( 'Flavor Agent could not update the activity entry.' ),
		{ code: 'flavor_agent_activity_update_failed', data: { status: 500 } }
	);
	const releaseSpy = jest.fn();

	apiFetch.mockImplementation( ( request ) => {
		if ( request?.url?.includes( '/decision' ) ) {
			return Promise.reject( retryError );
		}
		if ( request?.url?.includes( '/claim' ) && request?.method === 'DELETE' ) {
			releaseSpy();
			return Promise.resolve( { claim: null, entry: pending } );
		}
		if ( request?.url?.includes( '/claim' ) ) {
			return Promise.resolve( { claim: { userId: 7 }, entry: pending } );
		}
		return Promise.resolve( buildResponse( [ pending ] ) );
	} );

	await renderApp( undefined, { bootData: { currentUserId: 7 } } );

	const rejectButton = Array.from(
		getContainer().querySelectorAll( 'button' )
	).find( ( button ) => button.textContent.trim() === 'Reject' );

	// Submit a decision that fails with a retryable 500 — the claim must survive...
	await act( async () => {
		rejectButton.click();
	} );
	await flushEffects();
	expect( releaseSpy ).not.toHaveBeenCalled();

	// ...then abandon the row. The decision never committed (claim still live), so the
	// auto-claim cleanup MUST release it (decisionSubmittedRef stays false on a
	// retryable failure). Without the fix the ref latched true and the claim leaked.
	await act( async () => {
		getRoot().unmount();
	} );

	expect( releaseSpy ).toHaveBeenCalled();
} );

test( 'renders the advisory claim badge in the feed keyed off the viewer (finding 1)', async () => {
	// Self case so the test gates the call-site wiring: with currentUserId threaded
	// into normalizeActivityDiscoveryBadges, the viewer's own claim reads
	// "You're reviewing this"; without it, it would read "User #7 is reviewing".
	const pending = createExternalApplyEntry( {
		id: 'activity-pending',
		suggestion: 'Pending governance row',
		apply: {
			...createExternalApplyEntry().apply,
			claim: { userId: 7, claimedAt: '2026-06-25T00:00:00+00:00' },
		},
	} );

	// mockImplementation (not renderApp([…])) so the auto-claim-on-select returns the
	// claim shape rather than a feed object, leaving apply.claim intact.
	apiFetch.mockImplementation( ( request ) => {
		if ( request?.url?.includes( '/claim' ) ) {
			return Promise.resolve( { claim: { userId: 7 }, entry: pending } );
		}
		return Promise.resolve( buildResponse( [ pending ] ) );
	} );

	await renderApp( undefined, { bootData: { currentUserId: 7 } } );

	const badgeTexts = Array.from(
		getContainer().querySelectorAll( '.flavor-agent-activity-log__entry-badge' )
	).map( ( badge ) => badge.textContent );

	expect( badgeTexts.some( ( text ) => /reviewing this/i.test( text ) ) ).toBe(
		true
	);
} );

test( 'renders a Release control for the claim holder and releases it on click (spec :90)', async () => {
	window.history.replaceState( null, '', PENDING_DEEP_LINK );

	const pending = createExternalApplyEntry( {
		apply: {
			...createExternalApplyEntry().apply,
			claim: { userId: 7, claimedAt: '2026-06-25T00:00:00+00:00' },
		},
	} );
	const releaseSpy = jest.fn();

	apiFetch.mockImplementation( ( request ) => {
		if ( request?.url?.includes( '/claim' ) && request?.method === 'DELETE' ) {
			releaseSpy();
			return Promise.resolve( { claim: null, entry: pending } );
		}
		if ( request?.url?.includes( '/claim' ) ) {
			return Promise.resolve( { claim: { userId: 7 }, entry: pending } );
		}
		return Promise.resolve( buildResponse( [ pending ] ) );
	} );

	await renderApp( undefined, { bootData: { currentUserId: 7 } } );

	const releaseButton = Array.from(
		getContainer().querySelectorAll( 'button' )
	).find( ( button ) => /release review claim/i.test( button.textContent ) );
	expect( releaseButton ).toBeTruthy();

	await act( async () => {
		releaseButton.click();
	} );
	await flushEffects();

	expect( releaseSpy ).toHaveBeenCalled();
} );
```
(`PENDING_DEEP_LINK` is the const declared earlier in this describe block.)

- [ ] **Step 2: Run to verify they fail**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'auto-claims on opening'`
Then also: `... -t 'claim badge in the feed'` and `... -t 'Release control for the claim holder'`
Expected: FAIL — no claim request issued; no feed claim badge; no Release control.

- [ ] **Step 3: Add the remaining utils imports**

Extend the `./activity-log-utils` import (Task 8 added `buildClaimRequest`/`TERMINAL_DECISION_ERROR_CODES`) with the two symbols this task introduces:
```js
	buildClaimReleaseRequest,
	formatApplyClaimNotice,
```

- [ ] **Step 4: Thread `currentUserId` + `onClaimResolved` to the decision panel**

In `ActivityLogApp`'s render of `ActivityEntryDetails` (`:3505-3513`), add props:
```js
							onClaimResolved={ applyClaimResponse }
							currentUserId={ bootData?.currentUserId }
```
In `ActivityEntryDetails` (`:2485-2491`), accept and forward them:
```js
function ActivityEntryDetails( {
	entry,
	bootData,
	onDecided,
	onFilterAction,
	onClaimResolved,
	currentUserId,
	isLocallyDecided = false,
} ) {
```
and on the `GovernanceEvidenceSection` element (`:2544-2548`):
```js
					<GovernanceEvidenceSection
						entry={ entry }
						bootData={ bootData }
						onDecided={ onDecided }
						onClaimResolved={ onClaimResolved }
						currentUserId={ currentUserId }
						isLocallyDecided={ isLocallyDecided }
					/>
```

Then wire the **feed badge** (finding 1): in the `fields` `useMemo`, the `title` field's `render` calls `normalizeActivityDiscoveryBadges( item )` (`src/admin/activity-log.js:2921`). Pass the viewer id so the claim badge resolves self vs other:
```js
					const badges = normalizeActivityDiscoveryBadges(
						item,
						bootData?.currentUserId
					);
```
and add `bootData?.currentUserId` to that `useMemo`'s dependency array (it currently lists `responseData`, `selectedEntryId`, `:3200`) so the field re-renders when the viewer id is available. `normalizeActivityDiscoveryBadges` is already imported (`:40`).

- [ ] **Step 5: Add the auto-claim/release effect and the badge to `GovernanceEvidenceSection`**

Update the signature (`:2287-2292`):
```js
function GovernanceEvidenceSection( {
	entry,
	bootData,
	onDecided,
	onClaimResolved,
	currentUserId,
	isLocallyDecided = false,
} ) {
```
Add a ref beside the existing refs (`:2297-2298`):
```js
	const decisionSubmittedRef = useRef( false );
```
Latch it to `true` **only on outcomes that clear the claim server-side** — never at submit start. A committed decision and all three terminal race-loss codes drop the server-side claim (`transition_external_apply` calls `ApplyClaim::clear` on commit; `not_pending`/`expired` were already cleared when the row left `pending`); a **retryable** 500 does **not**, so an abandon after it must still release. Add the latch in two places inside `submitDecision`:

1. The success path — immediately after `onDecided?.( entry.id, response?.entry );` (`:2370`):
```js
				onDecided?.( entry.id, response?.entry );
				decisionSubmittedRef.current = true;
```
2. The terminal-code branch added in Task 8 — immediately after its `onDecided?.( entry.id, claimResponse?.entry );`, before the `return;`:
```js
					onDecided?.( entry.id, claimResponse?.entry );
					decisionSubmittedRef.current = true;
					return;
```

Do **not** set it on the retryable-500 path (the final `setDecisionError`): leaving it `false` is exactly what lets an abandon-after-retryable-failure release the claim instead of leaking it until the 5-minute TTL. (The auto-claim effect below resets the ref to `false` on every open, so each review starts releasable.)
After the existing `canDecide` declaration (`:2319-2322`), add the auto-claim/release effect plus the badge descriptor:
```js
	useEffect( () => {
		if ( ! canDecide || ! entry?.id ) {
			return undefined;
		}

		decisionSubmittedRef.current = false;
		const activityId = entry.id;
		let active = true;

		apiFetch( buildClaimRequest( bootData, activityId ) )
			.then( ( response ) => {
				if ( active ) {
					onClaimResolved?.( activityId, response );
				}
			} )
			.catch( () => {} );

		return () => {
			active = false;

			// Release on abandon/close only. A submitted decision clears the claim
			// server-side on success; a retryable failure must keep it. The 5-minute
			// TTL covers any missed release.
			if ( decisionSubmittedRef.current ) {
				return;
			}

			apiFetch( buildClaimReleaseRequest( bootData, activityId ) ).catch(
				() => {}
			);
		};
	}, [ canDecide, entry?.id, bootData, onClaimResolved ] );

	const claimNotice = formatApplyClaimNotice(
		entry?.apply?.claim,
		currentUserId
	);

	// Explicit Release control (spec :90): renders only when the viewer holds the
	// claim. There is nothing for a non-holder to release, and we never steal.
	const viewerId = Number( currentUserId );
	const claimUserId = Number( entry?.apply?.claim?.userId );
	const viewerHoldsClaim =
		Number.isFinite( viewerId ) &&
		viewerId > 0 &&
		viewerId === claimUserId;

	const releaseClaim = async () => {
		try {
			const response = await apiFetch(
				buildClaimReleaseRequest( bootData, entry.id )
			);
			onClaimResolved?.( entry.id, response );
		} catch ( error ) {
			// Best-effort; the 5-minute TTL covers a missed release.
		}
	};
```
`useEffect`/`apiFetch` are already imported in this file.

- [ ] **Step 6: Render the passive badge and the explicit Release control**

Inside the `{ canDecide && ( … ) }` decision block, directly under the `<h4>Decision</h4>`/intro `<p>` (after `:2443`), add the passive badge and — when the viewer holds the claim — the Release control:
```js
						{ claimNotice && (
							<p
								className={ `flavor-agent-activity-log__claim-note${
									claimNotice.isSelf ? ' is-self' : ''
								}` }
							>
								{ claimNotice.isSelf
									? claimNotice.text
									: `🟡 ${ claimNotice.text }` }
							</p>
						) }
						{ viewerHoldsClaim && (
							<Button
								variant="tertiary"
								onClick={ releaseClaim }
							>
								{ __( 'Release review claim', 'flavor-agent' ) }
							</Button>
						) }
```
The note and Release control are passive; they never disable the Approve/Reject buttons (those stay bound only to `isSubmitting`). `Button` is already imported in this file.

- [ ] **Step 7: Run to verify the new tests pass**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'reviewing'`
Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'releases the claim on abandon'`
Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'claim badge in the feed'`
Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'Release control for the claim holder'`
Expected: PASS.

- [ ] **Step 8: Add a minimal style for the claim note**

In `src/admin/activity-log.css`, append a rule that matches the file's conventions. **Line-ending caution:** this file is **CRLF / mixed-EOL** (`.gitattributes` `eol=lf` excludes `*.css`; ~887 of 1724 lines carry `\r`, and the tail where this rule appends is CRLF). Do **not** insert LF-only lines — append the rule with **CRLF** endings to match the surrounding lines, then run `git diff --check` and confirm it reports no CR/trailing-whitespace damage (a single mismatched-EOL edit churns the file and trips the whitespace guard). Indentation in this file is **2 spaces** (not tabs), and it writes `var(--token)` with **no inner spaces**. The muted-text token already defined in the file is `--flavor-agent-activity-log-muted` (used at `:128`, `:182`, `:219`, …) — reuse it; do not introduce `--flavor-agent-color-text-muted` (it does not exist):
```css
.flavor-agent-activity-log__claim-note {
  margin: 0;
  font-size: 12px;
  color: var(--flavor-agent-activity-log-muted);
}
```

- [ ] **Step 9: Lint, run the file, commit**

Run: `npm run lint:js -- --fix src/admin/activity-log.js src/admin/__tests__/activity-log.test.js`
Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js`
Expected: PASS.
```bash
git add src/admin/activity-log.js src/admin/activity-log.css src/admin/__tests__/activity-log.test.js
git commit -m "feat(activity-log): auto-claim on open, release on abandon, passive reviewer badge"
```

---

## Task 10: JS — opportunistic focus/visibility refresh

**Files:**
- Modify: `src/admin/activity-log.js` (`ActivityLogApp`: add the refresh effect + a `selectedEntryRef`)
- Modify: `src/admin/__tests__/activity-log.test.js`

**Interfaces:**
- Consumes: `buildClaimRequest` (Task 6), `applyClaimResponse` (Task 7), `setReloadToken`, `selectedEntry`.
- Produces: a debounced `focus`/`visibilitychange` listener bumps `reloadToken`; if a pending row is selected, it re-issues `POST /claim` and routes the response through `applyClaimResponse` (refreshing TTL and detecting decided-elsewhere → pin).

- [ ] **Step 1: Write the failing test**

```js
test( 'a window focus refreshes the feed and re-claims the selected pending row', async () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-external-apply'
	);

	const pending = createExternalApplyEntry();
	const claimSpy = jest.fn();

	apiFetch.mockImplementation( ( request ) => {
		if ( request?.url?.includes( '/claim' ) && request?.method === 'POST' ) {
			claimSpy();
			return Promise.resolve( { claim: { userId: 7 }, entry: pending } );
		}
		return Promise.resolve( buildResponse( [ pending ] ) );
	} );

	// Row auto-selected by the deep link; the leading-edge debounce fires the
	// re-claim synchronously on the first focus event (no fake timers needed).
	await renderApp( undefined, { bootData: { currentUserId: 7 } } );
	claimSpy.mockClear();
	const callsBefore = apiFetch.mock.calls.length;

	await act( async () => {
		window.dispatchEvent( new Event( 'focus' ) );
	} );
	await flushEffects();

	expect( apiFetch.mock.calls.length ).toBeGreaterThan( callsBefore );
	expect( claimSpy ).toHaveBeenCalled();
} );
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'window focus refreshes'`
Expected: FAIL — no focus listener; no extra fetch/claim.

- [ ] **Step 3: Add a `selectedEntryRef` mirror**

After the `selectedEntry` declaration (Task 7, `:3202`), add:
```js
	const selectedEntryRef = useRef( null );
	useEffect( () => {
		selectedEntryRef.current = selectedEntry;
	}, [ selectedEntry ] );
```
Confirm `useRef` is imported in this file (it is — used by `GovernanceEvidenceSection`). If `ActivityLogApp` doesn't yet import it at the top, add `useRef` to the `@wordpress/element` import.

- [ ] **Step 4: Add the focus/visibility refresh effect**

Add a constant near the top of the file (beside other module constants, e.g. after the imports):
```js
const FOCUS_REFRESH_DEBOUNCE_MS = 1000;
```
Then add the effect in `ActivityLogApp` (after the pin-clear effect from Task 7):
```js
	useEffect( () => {
		if (
			typeof window === 'undefined' ||
			typeof document === 'undefined'
		) {
			return undefined;
		}

		let debounceTimer = null;

		const handleFocusOrVisible = () => {
			if ( document.visibilityState === 'hidden' ) {
				return;
			}

			if ( debounceTimer ) {
				return;
			}

			debounceTimer = setTimeout( () => {
				debounceTimer = null;
			}, FOCUS_REFRESH_DEBOUNCE_MS );

			setReloadToken( ( value ) => value + 1 );

			const selected = selectedEntryRef.current;

			if (
				selected?.id &&
				selected.status === 'pending' &&
				bootData?.canApproveStyleApplies
			) {
				apiFetch( buildClaimRequest( bootData, selected.id ) )
					.then( ( response ) =>
						applyClaimResponse( selected.id, response )
					)
					.catch( () => {} );
			}
		};

		window.addEventListener( 'focus', handleFocusOrVisible );
		document.addEventListener( 'visibilitychange', handleFocusOrVisible );

		return () => {
			window.removeEventListener( 'focus', handleFocusOrVisible );
			document.removeEventListener(
				'visibilitychange',
				handleFocusOrVisible
			);

			if ( debounceTimer ) {
				clearTimeout( debounceTimer );
			}
		};
	}, [ bootData, applyClaimResponse ] );
```

- [ ] **Step 5: Run to verify it passes**

Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js -t 'window focus refreshes'`
Expected: PASS.

- [ ] **Step 6: Lint, run the whole admin JS suite, commit**

Run: `npm run lint:js -- --fix src/admin/activity-log.js src/admin/__tests__/activity-log.test.js`
Run: `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js`
Expected: PASS.
```bash
git add src/admin/activity-log.js src/admin/__tests__/activity-log.test.js
git commit -m "feat(activity-log): opportunistic focus/visibility refresh re-claims the selected pending row"
```

---

## Task 11: Documentation + guard updates

**Files (all are part of this change, not follow-ups):**
- Modify: `docs/reference/abilities-and-routes.md`
- Modify: `docs/features/activity-and-audit.md`
- Modify: `docs/reference/activity-state-machine.md`
- Modify: `docs/SOURCE_OF_TRUTH.md:164-168`
- Modify: `docs/reference/php-backend-architecture.md:5`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md:31`
- Modify: `CLAUDE.md` (Key Integration Points → REST API)
- Modify: `.github/copilot-instructions.md` (its `### REST routes` section — finding 2: the Copilot runbook carries the same `/flavor-agent/v1` inventory as `CLAUDE.md` and `check-doc-freshness.sh` does **not** parity-guard the route list, so it would silently go stale)

> All `.md` here are governed by `.gitattributes eol=lf` EXCEPT note that a single Edit can churn a mixed-CRLF `.md`. Check each file's line endings first (`grep -c $'\r' <file>`); if non-zero, reconstruct rather than blanket-edit. `SOURCE_OF_TRUTH.md` and `CLAUDE.md` are the most likely to be large — verify `git diff --check` shows no whitespace/CR damage after each edit.

- [ ] **Step 1: `abilities-and-routes.md` — add the two route rows**

Add `POST /flavor-agent/v1/activity/{id}/claim` and `DELETE …/claim` to the REST routes table, both with permission `can_decide_activity_request` (`manage_options` + the row's contextual capability), and a one-line lifecycle note: "advisory, auto-expiring (5 min) review claim on pending external applies; never gates a decision; cleared on the committed transition."

- [ ] **Step 2: `activity-and-audit.md` — surface note**

Under "What This Surface Can Do," note advisory "being reviewed by X" claims on pending approvals and the legible race-loss (a row decided by another admin resolves to its terminal state rather than a generic error).

- [ ] **Step 3: `activity-state-machine.md` — overlay note**

Add a short note: claims are an advisory overlay on the `pending` state, never a transition input; the only transition guard remains the write-boundary pending check.

- [ ] **Step 4: `SOURCE_OF_TRUTH.md:164-168` — bump the counted inventory**

Change "**4 activity route methods**" to "**6 activity route methods**" and add the new method to the list:
```
- **6 activity route methods** adapt the activity repository: GET `activity` …, POST `activity` …, POST `activity/{id}/undo` …, POST `activity/{id}/decision` …, and POST/DELETE `activity/{id}/claim` (`manage_options` plus the row's mutation capability via `Activity\Permissions::can_decide_activity_request`; acquires/releases an advisory, auto-expiring review claim that never gates a decision)
```
This REST-route count is distinct from and does not touch the guarded 30/31 *ability* count.

- [ ] **Step 5: `php-backend-architecture.md:5` — add to the route tuple**

Extend the `REST\Agent_Controller` route tuple to include `activity/{id}/claim`:
```
`REST\Agent_Controller` — REST routes under `flavor-agent/v1/` (`activity`, `activity/{id}/undo`, `activity/{id}/decision`, `activity/{id}/claim`, `sync-patterns`).
```

- [ ] **Step 6: `FEATURE_SURFACE_MATRIX.md:31` — extend the cell**

Change the "What it provides" cell for the Flavor Agent REST API row to: "Activity read/write/undo, external-apply decision, advisory review-claim coordination, and manual pattern sync."

- [ ] **Step 7: `CLAUDE.md` — add to the per-route permission inventory**

In Key Integration Points → REST API, add to the `can_decide_activity_request` grouping:
"…`activity/{id}/claim` (POST/DELETE, advisory review claim) also uses `manage_options` plus the row's mutation capability via `Activity\Permissions::can_decide_activity_request()`."

- [ ] **Step 8: `.github/copilot-instructions.md` — add to the REST routes section (finding 2)**

This file's `### REST routes` section lists "`activity`, `activity/{id}/undo`, `activity/{id}/decision` (external-apply approval; `manage_options` plus the row's mutation capability), and `sync-patterns`." Add the claim route so it matches `CLAUDE.md`:
> Remaining REST routes live under `flavor-agent/v1/`: `activity`, `activity/{id}/undo`, `activity/{id}/decision` (external-apply approval; `manage_options` plus the row's mutation capability), `activity/{id}/claim` (POST/DELETE advisory review claim, same capability), and `sync-patterns`.

`check-doc-freshness.sh` parity-guards only the webpack entry list and the ability count between `CLAUDE.md` and this file (`scripts/check-doc-freshness.sh:312-324`), **not** the route list — so `check:docs` will pass whether or not this edit is made. Treat this step as required regardless; it is the only guard against this runbook drifting.

- [ ] **Step 9: Run the docs freshness guard**

Run: `npm run check:docs`
Expected: PASS (no stale-doc failures). Note this does not prove Step 8 was done (no route-list parity guard) — confirm the Copilot file edit by eye.

- [ ] **Step 10: Commit**

```bash
git add docs/ CLAUDE.md .github/copilot-instructions.md
git commit -m "docs: record advisory apply-claim routes across every flavor-agent/v1 inventory"
```

---

## Task 12: Cross-surface validation gates

This change touches a shared subsystem (REST contract + activity subsystem + admin JS), so the cross-surface gates apply (`docs/reference/cross-surface-validation-gates.md`).

- [ ] **Step 1: Run the aggregate verify (no E2E)**

Run: `node scripts/verify.js --skip-e2e`
Then inspect: `output/verify/summary.json` — confirm `status: "pass"` and each of `build`, `lint-js`, `unit`, `lint-php`, `test-php` is `pass`. Read the per-step log (not just the summary) for `lint-plugin`; if WP-CLI/WP root is unavailable, re-run with `--skip=lint-plugin` and record the waiver.

Expected: `VERIFY_RESULT={"status":"pass",...}` (or `incomplete` only for an explicitly-waived `lint-plugin`).

- [ ] **Step 2: Confirm the docs guard once more (contract changed)**

Run: `npm run check:docs`
Expected: PASS.

- [ ] **Step 3: E2E note (manual-only)**

WP70 Site Editor browser E2E is manual-only per the coverage topology — the claim/legible-conflict flow needs two concurrent admin sessions, which the seeded Playground admin spec cannot demonstrate honestly. Record an explicit waiver in the PR description (no automated E2E for the two-admin race), or add a thin Playground admin spec ONLY if it can assert auto-claim/badge rendering for a single session without faking the concurrency.

- [ ] **Step 4: Final commit (if verify produced tracked changes) and summary**

If `npm run build` (run by verify) changed nothing tracked (build/ is gitignored), there is nothing to commit here. Summarize the run for review: tests green, gates passed, E2E waiver recorded.

---

## Self-Review (run before handing off)

**Spec coverage** — every spec section maps to a task:
- Claim store (`ApplyClaim` get/claim/release/clear, TTL, md5 key, lazy-expiry, non-pending no-write, forward-compatible precondition) → **Task 1**. **TTL caveat (finding 3):** the in-process stub cannot time-expire a transient, so Task 1 asserts the 5-minute TTL is *passed to* `set_transient` and that an *absent* transient reads as "no claim" — it does **not** assert real elapsed-time expiry. The spec testing bullet (`:119`) and Task 1 say so explicitly.
- REST contract (POST/DELETE claim, `can_decide_activity_request` parity, 404, never 409) → **Task 2**.
- Admin feed integration (`apply.claim` on pending rows only) → **Task 4**.
- Boot data (`currentUserId`) → **Task 5**.
- `ApplyClaim::clear` on committed transition only → **Task 3**.
- Terminal-state pinning incl. the **three** terminal codes (invalid_transition/not_pending/expired) → **Tasks 7 + 8**.
- Legible conflict (race-loss → claim-fetch → pin; retryable 500 keeps claim) → **Task 8**.
- Refresh model (opportunistic focus/visibility, re-claim selected pending row, no polling) → **Task 10**.
- UI — **both badge locations the spec requires (`:89`)**: the **DataViews feed** queue badge (`normalizeActivityDiscoveryBadges`, finding 1) → **Task 6 (generation) + Task 9 (call-site wiring)**; the **selected-row panel** badge/note (own vs other via coerced `currentUserId`), the explicit **Release control** (spec `:90`, renders only for the holder), auto-claim on open, release on abandon, buttons never disabled → **Task 9**. (Earlier draft omitted the feed badge and Release control — both are now in scope; nothing is silently de-scoped.)
- Core invariant regressions (foreign claim doesn't block; clear on committed only; guard unchanged) → **Task 3**.
- Docs + guard updates (all **8** inventories incl. `.github/copilot-instructions.md` — finding 2; 4→6 route-method count) → **Task 11**.
- Gates → **Task 12**.

**Type consistency** — names used across tasks: `ApplyClaim::{get,claim,release,clear,TTL}` (Tasks 1–4); `enrich_admin_entries_with_apply_claims` (Task 4); `build_activity_log_boot_data` (Task 5); `buildClaimRequest`/`buildClaimReleaseRequest`/`formatApplyClaimNotice`/`TERMINAL_DECISION_ERROR_CODES`/`normalizeActivityDiscoveryBadges( entry, currentUserId )` (Tasks 6, 8, 9, 10); `pinnedTerminalEntry`/`applyClaimResponse`/`selectedEntryRef` (Tasks 7, 9, 10); `viewerHoldsClaim`/`releaseClaim` (Task 9); response shape `{ claim, entry }` consistent PHP↔JS. The decision-panel terminal display reuses the existing `details.decidedByLabel`/`decidedAt`/`decisionNote` rows (no new attribution invented).

**Placeholder scan** — every code step contains complete code and assertions. The component tests use the real harness (`renderApp(response?, {bootData})`, `flushEffects()`, the `&activity=` deep-link selection, `getRoot().unmount()`, button-find by `textContent`, and the `.flavor-agent-activity-log__entry-badge` query for feed badges) documented in the **Component-test harness** note; Task 5 uses the same reflection idiom as the existing `get_theme_color_presets` test. No `TBD`/"add appropriate…" placeholders remain. The one runtime-dependent specific an executor must still confirm against live output is the exact relative-time wording from `humanTimeDiff` (tests assert substrings, not exact strings); the muted-text CSS token is now pinned to the existing `--flavor-agent-activity-log-muted`.

**Post-review corrections (2026-06-26 review round):**
- **[High] `applyClaimResponse` read the wrong field.** It checked `response.entry.status`, but `/claim` returns `find()`-shaped rows whose status is at `apply.status` (Serializer adds no top-level `status`). Every successful auto-claim would have been mis-pinned as decided, hiding the controls. Fixed to read `apply.status` (Task 7 Step 5); the Task 9 auto-claim test now uses a find()-shaped claim entry (top-level `status` stripped) and asserts the Reject control survives, so it catches the regression the previous all-`status`-bearing mocks masked.
- **[Medium] Claim leaked on retryable-then-abandon.** `decisionSubmittedRef` was latched at submit start, so an abandon after a retryable 500 skipped the release and the claim leaked to TTL. Now latched only on claim-clearing outcomes (committed success + the three terminal codes), never on the retryable path (Task 9 Step 5); added a "retryable failure then abandon releases" test (Task 9 Step 1) — the prior tests covered retryable-no-abandon and abandon-no-submit but not the combination.
- **[Medium] Lazy-expiry consistency.** Resolved as intentional rather than by code symmetry: `maybe_expire_pending_apply` is a write and is confined to action paths (`claim`/`decide`/`count`/cron), never `query_admin`. Instead of turning the feed/`release` reads into writes, enrichment gains a **pure-read** `expiresAt` guard so overdue rows don't advertise a stale claim (Task 4), documented in Global Constraints. Acting on an overdue row still surfaces the legible terminal `flavor_agent_apply_expired`.
- **[Low] CSS EOL + token.** `src/admin/activity-log.css` is CRLF/mixed (not LF); Step 8 now appends with CRLF + `git diff --check`, uses 2-space indent, and the real token `--flavor-agent-activity-log-muted` with `var(--…)` (no inner spaces) — the previously-named `--flavor-agent-color-text-muted` does not exist in the file.
