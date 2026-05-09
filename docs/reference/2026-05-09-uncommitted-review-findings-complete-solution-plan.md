# Uncommitted Review Findings Complete Solution Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the confirmed uncommitted-review regressions without broadening the patch beyond MCP registration observability and WordPress.org release-readiness documentation.

**Architecture:** Preserve the new MCP failure action as an integration point, but restore a first-party default signal so registration failures are not silent in production. Clarify release documentation so the first-review zip requirements and post-approval WordPress.org listing-asset requirements do not contradict each other.

**Tech Stack:** WordPress plugin PHP, PHPUnit, WordPress.org readme/release docs, Plugin Check, repo verifier.

---

## Confirmed Findings Covered

- MCP server registration failures are silent by default because `inc/MCP/ServerBootstrap.php` now only fires `flavor_agent_mcp_server_registration_failed`, and no production listener is registered.
- `docs/reference/release-submission-and-review.md` says listing assets are post-approval polish while the same document still lists a `readme.txt` Screenshots section as required for the first review artifact.

## File Map

- Modify `inc/MCP/ServerBootstrap.php`
  - Keep `flavor_agent_mcp_server_registration_failed`.
  - Add a first-party failure handler that emits a default log entry and a filter to disable that log when an operator intentionally wants action-only observability.
- Modify `tests/phpunit/MCPServerBootstrapTest.php`
  - Rename the current failure test so it describes the action behavior accurately.
  - Add coverage that a registration failure writes a default diagnostic entry.
  - Add coverage that the new log filter disables that default entry while preserving the action.
- Modify `docs/reference/release-submission-and-review.md`
  - Split first-review zip requirements from post-approval SVN listing assets.
  - Make `== Screenshots ==` conditional on assets existing, rather than required for the current first-review `readme.txt`.
- Optional modify `readme.txt`
  - Only add `== Screenshots ==` captions if screenshot files are created in the same release workflow. Do not add captions without corresponding local `screenshot-N.png` assets.

## Task 1: Restore First-Party MCP Failure Observability

**Files:**
- Modify: `inc/MCP/ServerBootstrap.php`
- Test: `tests/phpunit/MCPServerBootstrapTest.php`

- [x] **Step 1: Rename the existing failure test**

  _Why_: The current test name says failures are exposed "without logging directly", but the complete fix should preserve both extension visibility and first-party observability.

  Change the test method name in `tests/phpunit/MCPServerBootstrapTest.php`:

  ```php
  public function test_register_exposes_registration_failures_to_action_listeners(): void {
  ```

  Leave the existing action assertion intact:

  ```php
  $this->assertSame( $failure, $captured_failure );
  ```

- [x] **Step 2: Add failing coverage for default MCP failure logging**

  _Why_: This pins the regression directly: an MCP adapter failure must leave a first-party diagnostic signal even when no external listener is attached.

  Add this test to `tests/phpunit/MCPServerBootstrapTest.php`:

  ```php
  public function test_register_logs_registration_failures_by_default(): void {
  	$failure = new \WP_Error( 'mcp_failed', 'MCP registration failed.' );
  	$adapter = new class( $failure ) {
  		public function __construct( private \WP_Error $failure ) {}

  		public function create_server( mixed ...$args ): \WP_Error {
  			unset( $args );

  			return $this->failure;
  		}
  	};
  	$log_file         = tempnam( sys_get_temp_dir(), 'flavor-agent-mcp-log-' );
  	$previous_log     = ini_get( 'error_log' );
  	$previous_errors  = ini_get( 'log_errors' );

  	ini_set( 'log_errors', '1' );
  	ini_set( 'error_log', $log_file );

  	try {
  		ServerBootstrap::register( $adapter );
  	} finally {
  		ini_set( 'error_log', false === $previous_log ? '' : (string) $previous_log );
  		ini_set( 'log_errors', false === $previous_errors ? '' : (string) $previous_errors );
  	}

  	$contents = is_string( $log_file ) && file_exists( $log_file )
  		? (string) file_get_contents( $log_file )
  		: '';

  	if ( is_string( $log_file ) && file_exists( $log_file ) ) {
  		unlink( $log_file );
  	}

  	$this->assertStringContainsString( '[flavor-agent] MCP server registration failed: mcp_failed - MCP registration failed.', $contents );
  }
  ```

  Run:

  ```bash
  composer run test:php -- --filter MCPServerBootstrapTest
  ```

  Expected before implementation: this test fails because no log entry is written.

- [x] **Step 3: Add failing coverage for the opt-out filter**

  _Why_: The default signal fixes the regression, while a filter keeps operators and future review work from being trapped with unconditional logging.

  Add this test to `tests/phpunit/MCPServerBootstrapTest.php`:

  ```php
  public function test_register_allows_registration_failure_logging_to_be_disabled(): void {
  	$failure = new \WP_Error( 'mcp_failed', 'MCP registration failed.' );
  	$adapter = new class( $failure ) {
  		public function __construct( private \WP_Error $failure ) {}

  		public function create_server( mixed ...$args ): \WP_Error {
  			unset( $args );

  			return $this->failure;
  		}
  	};
  	$captured_failure = null;
  	$log_file         = tempnam( sys_get_temp_dir(), 'flavor-agent-mcp-log-' );
  	$previous_log     = ini_get( 'error_log' );
  	$previous_errors  = ini_get( 'log_errors' );

  	add_filter( 'flavor_agent_mcp_server_registration_failure_logging_enabled', '__return_false' );
  	add_action(
  		'flavor_agent_mcp_server_registration_failed',
  		static function ( \WP_Error $result ) use ( &$captured_failure ): void {
  			$captured_failure = $result;
  		}
  	);

  	ini_set( 'log_errors', '1' );
  	ini_set( 'error_log', $log_file );

  	try {
  		ServerBootstrap::register( $adapter );
  	} finally {
  		remove_filter( 'flavor_agent_mcp_server_registration_failure_logging_enabled', '__return_false' );
  		ini_set( 'error_log', false === $previous_log ? '' : (string) $previous_log );
  		ini_set( 'log_errors', false === $previous_errors ? '' : (string) $previous_errors );
  	}

  	$contents = is_string( $log_file ) && file_exists( $log_file )
  		? (string) file_get_contents( $log_file )
  		: '';

  	if ( is_string( $log_file ) && file_exists( $log_file ) ) {
  		unlink( $log_file );
  	}

  	$this->assertSame( $failure, $captured_failure );
  	$this->assertSame( '', trim( $contents ) );
  }
  ```

  Run:

  ```bash
  composer run test:php -- --filter MCPServerBootstrapTest
  ```

  Expected before implementation: this test fails because the filter is not implemented.

- [x] **Step 4: Implement the MCP failure handler**

  _Why_: Centralizing the behavior keeps the action, default logging, formatting, and filter in one small unit.

  In `inc/MCP/ServerBootstrap.php`, replace the current failure block:

  ```php
  if ( \is_wp_error( $result ) ) {
  	\do_action( 'flavor_agent_mcp_server_registration_failed', $result );
  }
  ```

  with:

  ```php
  if ( \is_wp_error( $result ) ) {
  	self::handle_registration_failure( $result );
  }
  ```

  Add these private methods above `plugin_version()`:

  ```php
  private static function handle_registration_failure( \WP_Error $result ): void {
  	\do_action( 'flavor_agent_mcp_server_registration_failed', $result );

  	if ( ! self::should_log_registration_failure( $result ) ) {
  		return;
  	}

  	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- MCP registration failures otherwise make the server unavailable with no first-party diagnostic signal.
  	\error_log(
  		\sprintf(
  			'[flavor-agent] MCP server registration failed: %s - %s',
  			$result->get_error_code(),
  			$result->get_error_message()
  		)
  	);
  }

  private static function should_log_registration_failure( \WP_Error $result ): bool {
  	return (bool) \apply_filters(
  		'flavor_agent_mcp_server_registration_failure_logging_enabled',
  		true,
  		$result
  	);
  }
  ```

- [x] **Step 5: Verify MCP behavior**

  Run:

  ```bash
  composer run test:php -- --filter MCPServerBootstrapTest
  ```

  Expected after implementation:

  ```text
  OK
  ```

## Task 2: Resolve Release Documentation Contradiction

**Files:**
- Modify: `docs/reference/release-submission-and-review.md`
- Optional modify: `readme.txt`

- [x] **Step 1: Clarify current first-review `readme.txt` requirements**

  _Why_: The current doc should not require a `== Screenshots ==` section while also saying screenshots are post-approval listing polish.

  In `docs/reference/release-submission-and-review.md`, replace the required-section list under `### readme.txt` with this wording:

  ```md
  Required for the first review zip, in order:

  - Header (Plugin Name, Contributors, Tags, Requires at least, Tested up to, Requires PHP, Stable tag, License, License URI)
  - Description
  - Installation
  - Frequently Asked Questions
  - Development/source-code location when compiled assets ship without the full `src/` tree
  - Changelog
  - Upgrade Notice (only when an upgrade requires user action)

  Required after listing screenshots are created:

  - Screenshots (numbered captions matching `assets/screenshot-N.png` files in SVN)
  ```

- [x] **Step 2: Clarify post-approval asset timing**

  _Why_: The doc should match the WordPress.org model: listing images live in the SVN `assets/` directory, not in the first review zip, unless the submission strategy intentionally includes them early.

  Replace the current `### Banner And Icon` intro with:

  ```md
  Live in `assets/` in SVN, not in the plugin zip. These are required for a polished public listing after approval, not for the first review zip unless the submission strategy changes to include listing assets before approval.
  ```

  Replace the current `### Screenshots` intro with:

  ```md
  Live in `assets/` in SVN as `screenshot-1.png`, `screenshot-2.png`, etc. Captions should be added to `readme.txt` under `== Screenshots ==` only when the matching local screenshot files exist. Do not add screenshot captions without corresponding local `assets/screenshot-N.png` files.
  ```

- [x] **Step 3: Decide whether `readme.txt` needs a Screenshots section in this patch**

  _Why_: The current complete solution is documentation consistency. Adding captions without actual screenshot files would create the same mismatch in the opposite direction.

  Use this decision rule:

  ```text
  If no screenshot files are being created in this implementation pass, do not modify readme.txt for screenshots.
  If screenshot files are created in the same pass, add a == Screenshots == section with one numbered caption per local screenshot file.
  ```

  For the current uncommitted patch, expected decision:

  ```text
  No readme.txt screenshot section change.
  ```

- [x] **Step 4: Verify docs**

  Run:

  ```bash
  npm run check:docs
  ```

  Expected:

  ```text
  > flavor-agent@0.1.0 check:docs
  > bash scripts/check-doc-freshness.sh
  ```

  The command exits `0`.

## Task 3: Final Validation And Release-Gate Evidence

**Files:**
- No new source files.
- Validation covers `inc/MCP/ServerBootstrap.php`, `tests/phpunit/MCPServerBootstrapTest.php`, `docs/reference/release-submission-and-review.md`, and the current release package surface.

- [x] **Step 1: Run whitespace and diff sanity check**

  Run:

  ```bash
  git diff --check
  ```

  Expected: no output and exit `0`.

- [x] **Step 2: Run targeted PHP coverage**

  Run:

  ```bash
  composer run test:php -- --filter MCPServerBootstrapTest
  ```

  Expected: all `MCPServerBootstrapTest` tests pass.

- [x] **Step 3: Run docs freshness check**

  Run:

  ```bash
  npm run check:docs
  ```

  Expected: exit `0`.

- [x] **Step 4: Run Plugin Check**

  Run:

  ```bash
  npm run lint:plugin
  ```

  Expected: Plugin Check exits `0`. Any warning or error is a blocker unless explicitly documented as a waiver.

- [x] **Step 5: Run aggregate fast verifier**

  Run:

  ```bash
  npm run verify -- --skip-e2e
  ```

  Expected: final `VERIFY_RESULT={...}` reports `status` as `pass`. If `lint-plugin` is unavailable in the local environment, rerun the targeted `npm run lint:plugin` result above before treating the gate as green.

## Acceptance Criteria

- MCP adapter registration failures still fire `flavor_agent_mcp_server_registration_failed`.
- MCP adapter registration failures produce a first-party default diagnostic log entry.
- Operators can disable the default MCP registration failure log with `flavor_agent_mcp_server_registration_failure_logging_enabled`.
- The release doc no longer says `readme.txt` must have a `Screenshots` section for the first review zip while the repository has no screenshot assets.
- `readme.txt` is not given screenshot captions unless matching local screenshot files are created in the same workflow.
- `composer run test:php -- --filter MCPServerBootstrapTest`, `npm run check:docs`, `npm run lint:plugin`, `npm run verify -- --skip-e2e`, and `git diff --check` are recorded before completion.

## Out Of Scope

- Creating WordPress.org banner, icon, or screenshot image files.
- Changing the current Playground E2E route matcher work.
- Changing WordPress AI feature-toggle seeding outside the existing test harness.
- Broad release-document rewrites unrelated to the two confirmed findings.
