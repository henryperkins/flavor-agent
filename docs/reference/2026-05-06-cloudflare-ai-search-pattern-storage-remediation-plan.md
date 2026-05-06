# Cloudflare AI Search Pattern Storage Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the two confirmed Cloudflare AI Search Pattern Storage regressions so managed instance adoption proves embedding-model compatibility and the settings UI surfaces specific, safe conflict guidance.

**Architecture:** Keep `PatternSearchInstanceManager` as the authority for managed AI Search instance creation/adoption, including the AI Search embedding model that Cloudflare reports for an existing instance. Keep `Feedback` responsible for secret-safe validation notices, but preserve whitelisted technical error codes so `Page` can render targeted status-panel guidance without exposing credentials.

**Tech Stack:** WordPress plugin PHP, Settings API, PHPUnit, Cloudflare AI Search REST API, existing Flavor Agent admin settings helpers.

---

## Confirmed Findings

1. `inc/Cloudflare/PatternSearchInstanceManager.php` adopts an existing deterministic AI Search instance after checking only metadata schema and owner marker. It does not verify the existing instance's `embedding_model`, while the settings/docs contract says readiness is validated against account ID, API token, and embedding model.

2. `inc/Admin/Settings/Page.php` has targeted conflict messaging for incompatible schema and owner-marker failures, but `inc/Admin/Settings/Feedback.php` records only the generic `flavor_agent_cloudflare_pattern_ai_search_validation` code. The page never sees the specific `WP_Error` code, so operators get generic "needs attention" guidance instead of the documented conflict recovery path.

## File Responsibility Map

- `inc/Cloudflare/PatternSearchInstanceManager.php`: Normalize supported AI Search embedding models, create managed instances, adopt only compatible existing instances, and return safe `WP_Error` codes for adoption blockers.
- `inc/Patterns/PatternIndex.php`: Store a Cloudflare AI Search sync signature that changes when the effective AI Search embedding model changes.
- `inc/Admin/Settings/Validation.php`: Save the validated managed-instance signature only after manager-level adoption/creation succeeds.
- `inc/Admin/Settings/Feedback.php`: Record secret-safe validation messages and preserve whitelisted status error codes.
- `inc/Admin/Settings/Page.php`: Render the Cloudflare Pattern Storage status panel from current state plus the latest whitelisted validation error code.
- `tests/phpunit/CloudflarePatternSearchInstanceManagerTest.php`: Prove creation/adoption/rejection rules for managed Cloudflare AI Search instances.
- `tests/phpunit/PatternIndexTest.php`: Prove model changes trigger Cloudflare AI Search full reindex behavior.
- `tests/phpunit/SettingsTest.php`: Prove settings validation errors remain secret-safe and targeted status guidance renders for specific conflicts.
- `docs/SOURCE_OF_TRUTH.md`, `docs/features/settings-backends-and-sync.md`, `docs/reference/local-environment-setup.md`, `docs/reference/pattern-recommendation-debugging.md`: Align operator docs with model compatibility and conflict recovery.

## Task 1: Prove And Enforce AI Search Embedding-Model Compatibility

**Files:**
- Modify: `inc/Cloudflare/PatternSearchInstanceManager.php`
- Modify: `inc/Patterns/PatternIndex.php`
- Test: `tests/phpunit/CloudflarePatternSearchInstanceManagerTest.php`
- Test: `tests/phpunit/PatternIndexTest.php`

- [ ] **Step 1: Add the failing manager adoption test**

Add a regression test to `tests/phpunit/CloudflarePatternSearchInstanceManagerTest.php` that queues an existing deterministic instance using the right schema and owner marker but the wrong `embedding_model`.

```php
public function test_ensure_managed_instance_rejects_matching_id_with_different_embedding_model(): void {
	WordPressTestState::$remote_get_responses = [
		$this->instance_list_response(
			[
				$this->managed_instance( '@cf/baai/bge-m3' ),
			]
		),
	];

	$result = PatternSearchInstanceManager::ensure_managed_instance(
		'account-123',
		'token-xyz',
		'@cf/qwen/qwen3-embedding-0.6b'
	);

	$this->assertInstanceOf( \WP_Error::class, $result );
	$this->assertSame( 'cloudflare_pattern_ai_search_embedding_model_mismatch', $result->get_error_code() );
	$this->assertCount( 0, WordPressTestState::$remote_post_calls );
}
```

Change the existing helper in the same test file so it can represent the Cloudflare response model explicitly:

```php
private function managed_instance( string $embedding_model = '@cf/qwen/qwen3-embedding-0.6b' ): array {
	return [
		'id'              => PatternSearchInstanceManager::managed_instance_id(),
		'custom_metadata' => PatternSearchInstanceManager::build_create_payload( $embedding_model )['custom_metadata'],
		'embedding_model' => $embedding_model,
	];
}
```

- [ ] **Step 2: Add the failing PatternIndex signature test**

Add a regression test to `tests/phpunit/PatternIndexTest.php` proving that changing the Embedding Model while Cloudflare AI Search is selected requires a full Cloudflare reindex.

```php
public function test_cloudflare_ai_search_sync_reindexes_when_embedding_model_changes(): void {
	$this->configure_cloudflare_ai_search_backends();
	$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );

	$this->register_pattern( 'theme/hero', $hero );

	$current_patterns = $this->current_patterns();
	$this->save_ready_cloudflare_ai_search_state_for_patterns(
		$current_patterns,
		[
			'cloudflare_ai_search_signature' => PatternSearchInstanceManager::credential_signature(
				'account-123',
				'token-xyz',
				'@cf/qwen/qwen3-embedding-0.6b'
			),
			'pattern_fingerprints'           => [
				PatternIndex::pattern_uuid( 'theme/hero' ) => $this->expected_pattern_fingerprint( $hero ),
			],
		]
	);

	update_option( 'flavor_agent_cloudflare_workers_ai_embedding_model', '@cf/baai/bge-m3', false );

	$this->queue_cloudflare_item_list( [ PatternIndex::pattern_uuid( 'theme/hero' ) ] );
	$this->queue_cloudflare_success_responses( 1 );

	$result = PatternIndex::sync();

	$this->assertSame( 1, $result['indexed'] );
	$this->assertIsArray(
		$this->find_remote_post_call( '/items', 'POST' )
	);
}
```

- [ ] **Step 3: Run the new tests and verify they fail**

Run:

```bash
composer run test:php -- --filter 'CloudflarePatternSearchInstanceManagerTest|PatternIndexTest'
```

Expected before implementation:

```text
FAILURES!
Tests: ...
cloudflare_pattern_ai_search_embedding_model_mismatch
```

The manager test should fail because existing instances do not check `embedding_model`. The PatternIndex test should fail because `cloudflare_ai_search_signature()` currently omits the embedding model.

- [ ] **Step 4: Implement model normalization and adoption rejection**

In `inc/Cloudflare/PatternSearchInstanceManager.php`, replace the private model helper with a public normalizer that existing code and tests can use:

```php
public static function normalize_embedding_model_for_ai_search( string $embedding_model ): string {
	$embedding_model = trim( sanitize_text_field( $embedding_model ) );

	return in_array( $embedding_model, self::SUPPORTED_AI_SEARCH_EMBEDDING_MODELS, true )
		? $embedding_model
		: WorkersAIEmbeddingConfiguration::DEFAULT_MODEL;
}
```

Update `credential_signature()` and `build_create_payload()` to call the new method:

```php
self::normalize_embedding_model_for_ai_search( $embedding_model )
```

Change `assert_compatible_instance()` so schema and model compatibility are both required:

```php
private static function assert_compatible_instance( array $instance, string $embedding_model ): true|\WP_Error {
	$actual   = self::normalize_custom_metadata_schema( $instance['custom_metadata'] ?? [] );
	$expected = self::normalize_custom_metadata_schema( self::expected_custom_metadata() );

	if ( $actual !== $expected ) {
		return new \WP_Error(
			'cloudflare_pattern_ai_search_incompatible_schema',
			'The existing Cloudflare AI Search managed pattern index does not use the Flavor Agent metadata schema.',
			[ 'status' => 409 ]
		);
	}

	$actual_model_raw = trim( sanitize_text_field( (string) ( $instance['embedding_model'] ?? '' ) ) );
	$actual_model     = self::normalize_embedding_model_for_ai_search( $actual_model_raw );
	$expected_model   = self::normalize_embedding_model_for_ai_search( $embedding_model );

	if ( '' === $actual_model_raw || $actual_model !== $expected_model ) {
		return new \WP_Error(
			'cloudflare_pattern_ai_search_embedding_model_mismatch',
			'The existing Cloudflare AI Search managed pattern index uses a different embedding model.',
			[
				'status'         => 409,
				'expected_model' => $expected_model,
				'actual_model'   => $actual_model_raw,
			]
		);
	}

	return true;
}
```

Update both call sites:

```php
$compatible = self::assert_compatible_instance( $instance, $embedding_model );
```

Update `try_adopt_after_create_conflict()` to accept and forward `$embedding_model`:

```php
private static function try_adopt_after_create_conflict( string $account_id, string $api_token, string $embedding_model ): array|\WP_Error
```

and call it from `ensure_managed_instance()`:

```php
return self::try_adopt_after_create_conflict( $config['account_id'], $config['api_token'], $embedding_model );
```

- [ ] **Step 5: Include the effective embedding model in Cloudflare sync drift**

In `inc/Patterns/PatternIndex.php`, change `cloudflare_ai_search_signature()` to use the same manager signature that settings readiness uses:

```php
private static function cloudflare_ai_search_signature(): string {
	return PatternSearchInstanceManager::credential_signature(
		(string) get_option( 'flavor_agent_cloudflare_workers_ai_account_id', '' ),
		(string) get_option( 'flavor_agent_cloudflare_workers_ai_api_token', '' ),
		(string) get_option( 'flavor_agent_cloudflare_workers_ai_embedding_model', '' )
	);
}
```

This makes `do_cloudflare_ai_search_sync()` hit the existing full-reindex branch when the effective AI Search embedding model changes:

```php
|| ( $state['cloudflare_ai_search_signature'] ?? '' ) !== $signature
```

- [ ] **Step 6: Run the manager and sync tests**

Run:

```bash
composer run test:php -- --filter 'CloudflarePatternSearchInstanceManagerTest|PatternIndexTest'
```

Expected:

```text
OK
```

## Task 2: Preserve Specific, Safe Pattern Storage Error Codes For The Settings UI

**Files:**
- Modify: `inc/Admin/Settings/Feedback.php`
- Modify: `inc/Admin/Settings/Page.php`
- Test: `tests/phpunit/SettingsTest.php`

- [ ] **Step 1: Add failing settings UI tests**

Add a test that exercises the normal Settings API error path and proves the status panel renders the specific conflict guidance:

```php
public function test_pattern_ai_search_conflict_status_uses_specific_settings_error_code(): void {
	WordPressTestState::$options = [
		Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
		'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
		'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
	];
	$_POST = [
		'option_page' => Config::OPTION_GROUP,
		Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => '',
	];
	WordPressTestState::$remote_get_response = [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode(
			[
				'result' => [
					[
						'id'              => PatternSearchInstanceManager::managed_instance_id(),
						'custom_metadata' => [
							[
								'field_name' => 'category',
								'data_type'  => 'text',
							],
						],
					],
				],
			]
		),
	];

	Settings::sanitize_cloudflare_pattern_ai_search_instance_id( '' );

	ob_start();
	Settings::render_page();
	$output = (string) ob_get_clean();

	$this->assertStringContainsString( 'Flavor Agent will not adopt', $output );
	$this->assertStringContainsString( PatternSearchInstanceManager::managed_instance_id(), $output );
	$this->assertStringNotContainsString( 'token-xyz', $output );
}
```

Add a second test for the request-scoped feedback path because the settings form posts `flavor_agent_settings_feedback_key`:

```php
public function test_pattern_ai_search_conflict_status_uses_specific_request_scoped_feedback_code(): void {
	WordPressTestState::$current_user_id = 1;
	WordPressTestState::$options = [
		Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
		'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
		'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
	];
	$_POST = [
		'option_page' => Config::OPTION_GROUP,
		'flavor_agent_settings_feedback_key' => 'pattern-conflict',
		Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => '',
	];
	WordPressTestState::$remote_get_response = [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode(
			[
				'result' => [
					[
						'id'              => PatternSearchInstanceManager::managed_instance_id(),
						'custom_metadata' => [
							[
								'field_name' => 'category',
								'data_type'  => 'text',
							],
						],
					],
				],
			]
		),
	];

	Settings::sanitize_cloudflare_pattern_ai_search_instance_id( '' );

	$_POST = [];
	$_GET  = [
		'settings-updated' => 'true',
		'flavor_agent_settings_feedback_key' => 'pattern-conflict',
	];

	ob_start();
	Settings::render_page();
	$output = (string) ob_get_clean();

	$this->assertStringContainsString( 'Flavor Agent will not adopt', $output );
	$this->assertStringNotContainsString( 'token-xyz', $output );
}
```

- [ ] **Step 2: Run the settings tests and verify they fail**

Run:

```bash
composer run test:php -- --filter 'pattern_ai_search_conflict_status|SettingsTest'
```

Expected before implementation:

```text
FAILURES!
```

The failing assertion should show that the status panel does not render "Flavor Agent will not adopt" for the specific conflict.

- [ ] **Step 3: Preserve whitelisted status codes in Feedback**

In `inc/Admin/Settings/Feedback.php`, add a whitelist:

```php
private const PATTERN_AI_SEARCH_STATUS_ERROR_CODES = [
	'cloudflare_pattern_ai_search_incompatible_schema',
	'cloudflare_pattern_ai_search_owner_marker_missing',
	'cloudflare_pattern_ai_search_owner_marker_mismatch',
	'cloudflare_pattern_ai_search_embedding_model_mismatch',
];
```

Add a helper:

```php
private static function get_safe_validation_status_code( string $settings_error_code, \WP_Error $error ): string {
	$error_code = $error->get_error_code();

	if (
		'flavor_agent_cloudflare_pattern_ai_search_validation' === $settings_error_code
		&& in_array( $error_code, self::PATTERN_AI_SEARCH_STATUS_ERROR_CODES, true )
	) {
		return $error_code;
	}

	return $settings_error_code;
}
```

Update `record_section_feedback_messages()` so it preserves an optional safe `code` value:

```php
$code = sanitize_key( (string) ( $entry['code'] ?? '' ) );

$new_entry = [
	'tone'    => $tone,
	'message' => $message,
];

if ( '' !== $code ) {
	$new_entry['code'] = $code;
}

$new_entries[] = $new_entry;
```

Update `report_validation_feedback()` to calculate and store the status code:

```php
$safe_error_message = self::get_safe_validation_error_message( $settings_error_code, $error );
$status_code        = self::get_safe_validation_status_code( $settings_error_code, $error );
```

For request-scoped feedback, include the code on the error entry:

```php
[
	'tone'    => 'error',
	'message' => $safe_error_message,
	'code'    => $status_code,
],
```

For Settings API notices, use the safe status code for the first error and keep the preserved warning generic:

```php
add_settings_error(
	Config::OPTION_GROUP,
	$status_code,
	$safe_error_message,
	'error'
);
add_settings_error(
	Config::OPTION_GROUP,
	$settings_error_code . '_preserved',
	$preserved_message,
	'warning'
);
```

- [ ] **Step 4: Read status codes from settings errors and request-scoped feedback**

In `inc/Admin/Settings/Page.php`, pass feedback into the Cloudflare status panel:

```php
self::render_cloudflare_pattern_ai_search_status_panel( $state, $feedback );
```

Update the method signatures:

```php
private static function render_cloudflare_pattern_ai_search_status_panel( array $state, array $feedback ): void
```

```php
private static function latest_pattern_ai_search_error_code( array $feedback ): string
```

Read whitelisted feedback codes before Settings API errors:

```php
private static function latest_pattern_ai_search_error_code( array $feedback ): string {
	$messages = is_array( $feedback['messages'][ Config::GROUP_PATTERNS ] ?? null )
		? $feedback['messages'][ Config::GROUP_PATTERNS ]
		: [];

	foreach ( array_reverse( $messages ) as $message ) {
		if ( ! is_array( $message ) ) {
			continue;
		}

		$code = sanitize_key( (string) ( $message['code'] ?? '' ) );

		if ( self::is_pattern_ai_search_status_error_code( $code ) ) {
			return $code;
		}
	}

	foreach ( array_reverse( get_settings_errors( Config::OPTION_GROUP ) ) as $error ) {
		if ( ! is_array( $error ) ) {
			continue;
		}

		$code = sanitize_key( (string) ( $error['code'] ?? '' ) );

		if ( self::is_pattern_ai_search_status_error_code( $code ) ) {
			return $code;
		}
	}

	return '';
}
```

Add the page-level whitelist helper:

```php
private static function is_pattern_ai_search_status_error_code( string $code ): bool {
	return in_array(
		$code,
		[
			'cloudflare_pattern_ai_search_incompatible_schema',
			'cloudflare_pattern_ai_search_owner_marker_missing',
			'cloudflare_pattern_ai_search_owner_marker_mismatch',
			'cloudflare_pattern_ai_search_embedding_model_mismatch',
		],
		true
	);
}
```

Include the embedding-model mismatch in the conflict-specific status panel branch:

```php
'cloudflare_pattern_ai_search_embedding_model_mismatch',
```

- [ ] **Step 5: Run settings tests**

Run:

```bash
composer run test:php -- --filter 'SettingsTest|pattern_ai_search_conflict_status'
```

Expected:

```text
OK
```

## Task 3: Align Operator And Debugging Documentation

**Files:**
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `docs/reference/local-environment-setup.md`
- Modify: `docs/reference/pattern-recommendation-debugging.md`

- [ ] **Step 1: Update the source-of-truth contract**

In `docs/SOURCE_OF_TRUTH.md`, update the Cloudflare AI Search Pattern Storage paragraph so it explicitly includes model compatibility:

```markdown
Existing managed instances are adopted only when their metadata schema, embedding model, and Flavor Agent owner marker prove compatibility and ownership; incompatible deterministic instances are blocked until the operator fixes or removes the conflicting Cloudflare instance and saves settings again.
```

- [ ] **Step 2: Update backend and sync docs**

In `docs/features/settings-backends-and-sync.md`, update the save-flow and failure bullets:

```markdown
When Cloudflare AI Search Pattern Storage is selected, Flavor Agent reuses the Embedding Model account/token/model to create or adopt the deterministic managed `flavor-agent-patterns-{site_hash}` instance. Adoption requires matching metadata schema, matching effective AI Search embedding model, and a matching Flavor Agent owner marker.
```

```markdown
Changing the Embedding Model invalidates the Cloudflare AI Search managed signature. If the existing deterministic instance uses a different AI Search embedding model, Flavor Agent blocks adoption and the operator must remove or recreate that instance before syncing.
```

- [ ] **Step 3: Update local setup guidance**

In `docs/reference/local-environment-setup.md`, extend the Cloudflare Pattern AI Search Metadata section:

```markdown
If you change the Embedding Model after creating a managed Cloudflare AI Search pattern index, save Pattern Storage again. Flavor Agent validates that the existing deterministic instance reports the same effective AI Search embedding model. A mismatch is treated as a conflict because Cloudflare indexes cannot be safely reused across embedding models.
```

- [ ] **Step 4: Update debugging guidance**

In `docs/reference/pattern-recommendation-debugging.md`, add the new error code to the Cloudflare AI Search troubleshooting list:

```markdown
- `cloudflare_pattern_ai_search_embedding_model_mismatch`: the deterministic managed AI Search instance exists, but Cloudflare reports an embedding model that does not match the current Embedding Model setting. Remove or recreate the conflicting `flavor-agent-patterns-{site_hash}` instance, then save settings and sync again.
```

Also update any option/signature checklist to say that Cloudflare AI Search readiness depends on:

```markdown
- `flavor_agent_cloudflare_workers_ai_account_id`
- `flavor_agent_cloudflare_workers_ai_api_token`
- `flavor_agent_cloudflare_workers_ai_embedding_model`
- `flavor_agent_cloudflare_pattern_ai_search_instance_id`
- `flavor_agent_cloudflare_pattern_ai_search_validated_signature`
```

- [ ] **Step 5: Run docs checks**

Run:

```bash
npm run check:docs
```

Expected:

```text
no broken links or docs contract failures
```

## Task 4: Final Verification And Release Evidence

**Files:**
- Read: `output/verify/summary.json`
- Optional create: `docs/validation/2026-05-06-cloudflare-ai-search-pattern-storage-remediation.md`

- [ ] **Step 1: Run targeted PHP coverage**

Run:

```bash
composer run test:php -- --filter 'CloudflarePatternSearch|Settings|PatternIndex'
```

Expected:

```text
OK
```

- [ ] **Step 2: Run PHP lint**

Run:

```bash
composer run lint:php
```

Expected:

```text
No PHPCS violations in changed PHP files.
```

- [ ] **Step 3: Run docs validation**

Run:

```bash
npm run check:docs
```

Expected:

```text
Docs check passes.
```

- [ ] **Step 4: Run whitespace validation**

Run:

```bash
git diff --check
```

Expected:

```text
No output.
```

- [ ] **Step 5: Run the aggregate non-E2E verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected:

```text
VERIFY_RESULT={"status":"pass",...}
```

If the verifier is incomplete because local plugin-check prerequisites are unavailable, rerun with the intentional skip and record that choice:

```bash
npm run verify -- --skip-e2e --skip=lint-plugin
```

- [ ] **Step 6: Optional browser evidence when the local WP 7.0 stack is available**

Run:

```bash
npx playwright test -c playwright.wp70.config.js tests/e2e/flavor-agent.settings.spec.js
```

Expected:

```text
1 passed
```

Use this only when the local WordPress runtime described in `docs/reference/local-environment-setup.md` is running with the required companion plugins active.

- [ ] **Step 7: Record validation evidence if this is headed to PR**

Create `docs/validation/2026-05-06-cloudflare-ai-search-pattern-storage-remediation.md` with:

```markdown
# Cloudflare AI Search Pattern Storage Remediation Validation

Date: 2026-05-06

## Summary

- Managed Cloudflare AI Search adoption now rejects deterministic instances with incompatible embedding models.
- Cloudflare AI Search sync signatures now change when the effective Embedding Model changes.
- Settings UI status panels now render specific conflict guidance from whitelisted validation error codes.

## Commands

- `composer run test:php -- --filter 'CloudflarePatternSearch|Settings|PatternIndex'`
- `composer run lint:php`
- `npm run check:docs`
- `git diff --check`
- `npm run verify -- --skip-e2e`

## Result

Record the exact pass/incomplete status and any intentional skips from `output/verify/summary.json`.
```

## Acceptance Criteria

- Existing deterministic AI Search instances are adopted only when schema, owner marker, and effective AI Search embedding model match the current settings.
- Changing `flavor_agent_cloudflare_workers_ai_embedding_model` changes Cloudflare AI Search sync drift detection and forces the full reindex path.
- `flavor_agent_cloudflare_pattern_ai_search_validated_signature` is saved only after create/adopt succeeds against the same effective model.
- The settings page renders the conflict-specific "Flavor Agent will not adopt..." guidance for incompatible schema, missing owner marker, owner mismatch, and embedding-model mismatch.
- Validation notices and status panels never expose account tokens or raw secret material.
- Targeted PHPUnit, PHPCS, docs checks, whitespace checks, and the aggregate non-E2E verifier pass or have explicitly recorded environmental skips.
