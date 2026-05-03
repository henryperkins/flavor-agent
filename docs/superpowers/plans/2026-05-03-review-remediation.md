# WP 7.0 Integration Branch — Review Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Address the regressions and quality issues identified in the 2026-05-03 code review of the uncommitted WP 7.0 / WordPress AI Client integration branch, before this work lands on `master`.

**Architecture:** The fixes split across PHP backend (Provider credential discovery, WordPressAIClient SDK fallback, RequestTrace consolidation, Prompt JSON parsing tests) and JS frontend (apply-state defense, shared-reducer cross-surface tests, sentinel cleanup). Each task is self-contained — order is by severity, not by dependency.

**Tech Stack:** PHP 8.0+ / WordPress 7.0 nightly, PHPUnit, Jest (`@wordpress/scripts`), `@wordpress/data` store, `@wordpress/api-fetch`.

**Pre-flight:** Run `git status` and confirm the branch under review still has the diff captured at `/tmp/flavor-agent-diff.txt` (3,901 lines, 37 files modified, 6 untracked). All file:line references below assume that diff state.

**Verification gate:** After every task that changes code, run the targeted test command shown in the task. After the final task, run `npm run verify -- --skip-e2e` and confirm `output/verify/summary.json` shows `status: pass`. E2E browser harnesses are out of scope for this plan; if the changes touch executable surfaces, schedule a separate Playwright run per `docs/reference/cross-surface-validation-gates.md`.

**Commit isolation:** Tasks below use `git add <file>` and `git commit`. The branch has 37 files of pre-existing uncommitted integration work. Without baseline isolation, each remediation commit would scoop up the surrounding integration diff and produce misleading history. Task 0 captures the baseline as a single commit so every subsequent task commits only its own delta.

---

## Task 0: Commit the integration-branch baseline as a single isolated commit

**Why:** Each remediation task touches files (`Provider.php`, `WordPressAIClient.php`, `Agent_Controller.php`, `Prompt.php`, `store/index.js`, etc.) that already have substantial uncommitted changes from the WP 7.0 / WordPress AI Client integration branch. If we run `git add inc/OpenAI/Provider.php` for Task 2, it would commit *all* of Provider.php's uncommitted changes — not just the connector_filter fix. Capturing the baseline first lets each remediation commit isolate its own diff.

**Files:**
- All 37 modified + 6 untracked files in the working tree

- [ ] **Step 1: Verify the working tree state**

```bash
cd /home/dev/flavor-agent && git status --short
```

Expected: 37 lines starting with ` M ` and 6 lines starting with `??`. Confirm the count matches the plan's preamble. If the count differs, pause and ask the user before proceeding — the diff baseline may have shifted since the plan was written.

- [ ] **Step 2: Stage everything except `INTEGRATION_GUIDE.md`**

`INTEGRATION_GUIDE.md` is upstream WordPress AI plugin documentation that doesn't belong in the repo (Task 1 removes it). Don't capture it in the baseline.

```bash
git add -A
git reset HEAD INTEGRATION_GUIDE.md
```

If `git reset HEAD INTEGRATION_GUIDE.md` errors because the file isn't currently tracked at HEAD, that's expected — just confirm via `git status --short INTEGRATION_GUIDE.md` that the file is back to `??` (untracked) after the reset. If it shows `A` (staged for add), repeat the reset.

- [ ] **Step 3: Commit as a single integration-branch baseline**

```bash
git commit -m "$(cat <<'EOF'
chore: WP 7.0 / WordPress AI Client integration baseline

Captures the in-progress integration work as a single commit so the
review-remediation tasks that follow can isolate their own diffs.
This commit is the subject of the 2026-05-03 review and the diff
captured at /tmp/flavor-agent-diff.txt; subsequent commits address
the findings in docs/superpowers/plans/2026-05-03-review-remediation.md.
EOF
)"
```

- [ ] **Step 4: Verify the working tree is now clean apart from `INTEGRATION_GUIDE.md`**

```bash
git status --short
```

Expected: a single `?? INTEGRATION_GUIDE.md` line. Subsequent `git add <file>` calls will only stage the remediation diffs introduced by tasks below.

---

## Task 1: Remove `INTEGRATION_GUIDE.md` from repo root

**Why:** It's verbatim upstream `WordPress/ai` plugin documentation (v0.8.0) referencing companion files (`ARCHITECTURE_OVERVIEW.md`, `experiments/`) that don't exist here, and `includes/Abilities/*` paths that don't match Flavor Agent's `inc/Abilities/*`. It does not belong at the project root.

**Files:**
- Delete: `INTEGRATION_GUIDE.md`

- [ ] **Step 1: Confirm it's untracked**

```bash
git status --short INTEGRATION_GUIDE.md
```

Expected: `?? INTEGRATION_GUIDE.md`. If the line shows `??` the file is untracked and `rm` is sufficient. If anything else appears (`A`, `M`, `D`), pause and ask the user before deleting.

- [ ] **Step 2: Remove the file**

```bash
rm /home/dev/flavor-agent/INTEGRATION_GUIDE.md
```

- [ ] **Step 3: Verify removal**

```bash
git status --short INTEGRATION_GUIDE.md
```

Expected: empty output (file no longer present, nothing to track).

- [ ] **Step 4: Commit**

This is a deletion of an untracked file, so there's nothing to commit. Skip to Task 2.

---

## Task 2: Stop the upstream filter from suppressing a saved DB key in `Provider::connector_api_key_source`

**Why:** When `wp_options` has a saved key but `wpai_is_{slug}_connector_configured` returns `false` (legitimate cases: validation cooldown, OAuth refresh, plugin self-disable), the new code at `inc/OpenAI/Provider.php:944-967` returns `'none'`, blanking out the saved key. The filter is documented upstream as "per-connector configured status, used by the AI Status widget" — a status flag, not credential gating. The fix: env > constant > database always wins; the filter only elevates "no key" → `'connector_filter'`.

**Files:**
- Modify: `inc/OpenAI/Provider.php:944-967`
- Modify (test): `tests/phpunit/ProviderTest.php`

- [ ] **Step 1: Add a failing test for the regression**

Append to the test class in `tests/phpunit/ProviderTest.php` (after the existing `test_openai_connector_status_respects_upstream_connector_configured_filter` test):

```php
public function test_openai_connector_status_keeps_database_key_when_filter_returns_false(): void {
	WordPressTestState::$options    = [
		'connectors_ai_openai_api_key' => 'sk-saved-key',
	];
	WordPressTestState::$connectors = [
		'openai' => [
			'name'           => 'OpenAI',
			'type'           => 'ai_provider',
			'authentication' => [
				'method'       => 'api_key',
				'setting_name' => 'connectors_ai_openai_api_key',
			],
		],
	];

	add_filter( 'wpai_is_openai_connector_configured', '__return_false', 10, 2 );

	$status = Provider::openai_connector_status();

	$this->assertSame( 'database', $status['keySource'], 'Saved DB key must not be suppressed by a false filter result.' );
	$this->assertTrue( $status['configured'] );
}
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
cd /home/dev/flavor-agent && vendor/bin/phpunit --filter test_openai_connector_status_keeps_database_key_when_filter_returns_false
```

Expected: 1 failure. The current code returns `'none'` for `keySource` because the false filter result short-circuits ahead of the DB check.

- [ ] **Step 3: Reorder the source resolution so DB always wins**

Replace the body of `connector_api_key_source` in `inc/OpenAI/Provider.php` from `$db_value = self::option_value(...)` through the closing brace of the function with:

```php
		$db_value = self::option_value( $overrides, $setting_name );

		if ( '' !== $db_value ) {
			return 'database';
		}

		$connector ??= self::registered_connectors()[ $connector_id ] ?? [];
		$filtered    = apply_filters(
			'wpai_is_' . sanitize_key( $connector_id ) . '_connector_configured',
			false,
			is_array( $connector ) ? $connector : []
		);

		return true === (bool) $filtered ? 'connector_filter' : 'none';
	}
```

(The env/constant loop above this section stays unchanged. The new logic: env → constant → database short-circuit, then ask the filter only when no key exists at all.)

- [ ] **Step 4: Run the failing test plus the existing connector_filter test**

```bash
vendor/bin/phpunit --filter 'test_openai_connector_status_(respects_upstream|keeps_database|checks_environment)'
```

Expected: 3 passes. The `respects_upstream_connector_configured_filter` test still works because that test uses an empty `setting_name`, so the DB check returns `''` and the filter elevates to `'connector_filter'`.

- [ ] **Step 5: Run the full Provider suite**

```bash
vendor/bin/phpunit tests/phpunit/ProviderTest.php
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add inc/OpenAI/Provider.php tests/phpunit/ProviderTest.php
git commit -m "$(cat <<'EOF'
fix(provider): stop wpai connector filter from suppressing saved DB keys

The wpai_is_{slug}_connector_configured filter is documented as a status
flag for the AI Status widget. Treating a false return as "blank out the
saved key" was a regression — env/constant/database now win, and the
filter only elevates the "no key" case to connector_filter.
EOF
)"
```

---

## Task 3: Preserve `model_options` in `WordPressAIClient::make_prompt` SDK fallback path

**Why:** When `WordPress\AI\get_ai_service()` is missing or `create_textgen_prompt` throws (older or partial AI plugin installs), the `catch (\Throwable)` at `inc/LLM/WordPressAIClient.php:284` swallows the error and falls through to bare `wp_ai_client_prompt($user_prompt)`. The `$options` array — sanitized `temperature`, `max_tokens`, `top_p`, etc. — is silently dropped; only `system_instruction` is reapplied later via `apply_system_instruction`. Tuning vanishes without warning.

**Files:**
- Modify: `inc/LLM/WordPressAIClient.php:265-308`
- Modify (test): `tests/phpunit/WordPressAIClientTest.php`

- [ ] **Step 1: Read the current `make_prompt` implementation**

```bash
sed -n '265,320p' /home/dev/flavor-agent/inc/LLM/WordPressAIClient.php
```

Note the structure: `try { get_ai_service()->create_textgen_prompt($user_prompt, $options) }` then `if (! function_exists('wp_ai_client_prompt'))` returns WP_Error, then bare `wp_ai_client_prompt($user_prompt)` is returned. Look for the existing helper that maps an option key to the builder method (e.g., `apply_temperature`, `using_max_tokens`). If a centralized "apply options to builder" helper exists already, the new code in Step 3 will call it; if not, write one inline.

- [ ] **Step 2: Add a failing test asserting model_options survive the fallback**

Append to `tests/phpunit/WordPressAIClientTest.php` (after `test_chat_routes_through_ai_service_with_supported_generation_options`):

```php
public function test_chat_preserves_model_options_when_ai_service_throws(): void {
	WordPressTestState::$ai_client_supported            = true;
	WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';
	WordPressTestState::$ai_service_call_throws        = new \RuntimeException( 'AI service unavailable' );

	$result = WordPressAIClient::chat(
		'System.',
		'User.',
		null,
		null,
		null,
		[
			'temperature' => 0.4,
			'max_tokens'  => 250,
		]
	);

	$this->assertSame( '{"explanation":"OK."}', $result );
	$this->assertSame( 0.4, WordPressTestState::$last_ai_client_prompt['model_config']['temperature'] ?? null );
	$this->assertSame( 250, WordPressTestState::$last_ai_client_prompt['model_config']['max_tokens'] ?? null );
}
```

In `tests/phpunit/bootstrap.php`, add the new state field next to `public static array $ai_service_calls`:

```php
public static ?\Throwable $ai_service_call_throws = null;
```

And in the `WordPressTestState::reset()` method, add a reset line:

```php
self::$ai_service_call_throws = null;
```

In the `FakeAIService::create_textgen_prompt` method (in `bootstrap.php`'s `WordPress\AI` namespace), throw the configured exception before recording the call, so the chat() fallback path is exercised:

```php
public function create_textgen_prompt( ?string $prompt = null, array $options = [] ): \WP_AI_Client_Prompt_Builder {
	if ( null !== WordPressTestState::$ai_service_call_throws ) {
		throw WordPressTestState::$ai_service_call_throws;
	}

	WordPressTestState::$ai_service_calls[] = [ /* ...existing... */ ];
	// ... existing body ...
}
```

The test reads `$last_ai_client_prompt['model_config']` — confirm the existing FakeBuilder records model config in that key. If it doesn't, search `bootstrap.php` for `last_ai_client_prompt` and adjust the assertion to whatever shape the test stub uses for builder method captures (e.g., `last_ai_client_prompt['using_temperature']`, `['system']`, etc.).

- [ ] **Step 3: Run the test and confirm it fails**

```bash
vendor/bin/phpunit --filter test_chat_preserves_model_options_when_ai_service_throws
```

Expected: 1 failure. The temperature/max_tokens are not applied to the bare `wp_ai_client_prompt` builder.

- [ ] **Step 4: Apply options to the fallback builder via `using_model_config(ModelConfig::fromArray(...))`**

The production code at `inc/LLM/WordPressAIClient.php:442-456` already routes options through `ModelConfig::fromArray(...)` and `using_model_config(...)`. Mirror that pattern in the fallback path so we don't duplicate option-mapping logic and so the FakeBuilder's `using_model_config` handler captures the call in tests.

In `inc/LLM/WordPressAIClient.php`, update `make_prompt` to apply options after the bare `wp_ai_client_prompt(...)` fallback. Replace the function body:

```php
private static function make_prompt( string $user_prompt, array $options = [] ): mixed {
	if ( function_exists( 'WordPress\\AI\\get_ai_service' ) ) {
		try {
			$service = \WordPress\AI\get_ai_service();

			if ( is_object( $service ) && is_callable( [ $service, 'create_textgen_prompt' ] ) ) {
				$prompt = $service->create_textgen_prompt( $user_prompt, $options );

				if ( is_wp_error( $prompt ) ) {
					return $prompt;
				}

				if ( is_object( $prompt ) ) {
					return $prompt;
				}
			}
		} catch ( \Throwable $throwable ) {
			// Fall back to the raw SDK entry point below for older or partial installs.
		}
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new \WP_Error(
			'wp_ai_client_unavailable',
			'The WordPress AI client is not available.',
			[ 'status' => 500 ]
		);
	}

	$prompt = \wp_ai_client_prompt( $user_prompt );

	if ( ! is_object( $prompt ) ) {
		return $prompt;
	}

	return self::apply_options_to_prompt_builder( $prompt, $options );
}

/**
 * Apply the sanitized model options to a prompt builder via using_model_config.
 *
 * Note: `system_instruction` is intentionally excluded — chat() calls
 * apply_system_instruction() on the returned builder, so applying it here
 * would set it twice on the raw-SDK fallback path.
 *
 * @param array<string, mixed> $options
 */
private static function apply_options_to_prompt_builder( object $prompt, array $options ): object {
	$model_options = $options;
	unset( $model_options['system_instruction'] );

	if ( [] === $model_options ) {
		return $prompt;
	}

	if (
		! class_exists( ModelConfig::class )
		|| ! is_callable( [ ModelConfig::class, 'fromArray' ] )
		|| ! is_callable( [ $prompt, 'using_model_config' ] )
	) {
		return $prompt;
	}

	try {
		$model_config = ModelConfig::fromArray( $model_options );
	} catch ( \Throwable ) {
		return $prompt;
	}

	return $prompt->using_model_config( $model_config );
}
```

The `ModelConfig` import is already at the top of the file (`use WordPress\AiClient\Providers\Models\DTO\ModelConfig;`). The existing FakeBuilder handles `using_model_config` and stores the resulting array in `state['model_config']`.

Update the test from Step 2 to read from `model_config` rather than the per-option keys (which the FakeBuilder doesn't expose):

```php
public function test_chat_preserves_model_options_when_ai_service_throws(): void {
	WordPressTestState::$ai_client_supported            = true;
	WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';
	WordPressTestState::$ai_service_call_throws        = new \RuntimeException( 'AI service unavailable' );

	$result = WordPressAIClient::chat(
		'System.',
		'User.',
		null,
		null,
		null,
		[
			'temperature' => 0.4,
			'max_tokens'  => 250,
		]
	);

	$this->assertSame( '{"explanation":"OK."}', $result );
	$this->assertSame(
		0.4,
		WordPressTestState::$last_ai_client_prompt['model_config']['temperature'] ?? null
	);
	$this->assertSame(
		250,
		WordPressTestState::$last_ai_client_prompt['model_config']['max_tokens'] ?? null
	);
}
```

- [ ] **Step 5: Run the test and confirm it passes**

```bash
vendor/bin/phpunit --filter test_chat_preserves_model_options_when_ai_service_throws
```

Expected: pass.

- [ ] **Step 6: Run the full WordPressAIClientTest suite**

```bash
vendor/bin/phpunit tests/phpunit/WordPressAIClientTest.php
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add inc/LLM/WordPressAIClient.php tests/phpunit/WordPressAIClientTest.php tests/phpunit/bootstrap.php
git commit -m "$(cat <<'EOF'
fix(ai-client): preserve model options when AI service fallback fires

When WordPress\AI\get_ai_service() is unavailable or throws, the SDK
fallback path was discarding the sanitized model options (temperature,
max_tokens, top_*, penalties, stop_sequences). They are now reapplied
to the raw wp_ai_client_prompt builder via ModelConfig::fromArray and
using_model_config, mirroring the production path. system_instruction
is excluded so chat()'s existing apply_system_instruction call doesn't
set it twice.
EOF
)"
```

---

## Task 4: Defend `applySuggestion` and `applyBlockStructuralSuggestion` against synchronous throws between `'applying'` and the next dispatch

**Why:** The new line-1399 / line-1595 guards in `src/store/index.js` early-return when status is already `'applying'`. If any synchronous helper between `setBlockApplyState(clientId, 'applying')` and the next state-dispatching branch (`getBlockSuggestionExecutionInfo`, `buildSafeAttributeUpdates`, `blockEditorDispatch.updateBlockAttributes`, the structural-operation executor) throws, the reducer is left in `'applying'` and the line-1399 guard then silently no-ops every future apply for that block. `guardSurfaceApplyResolvedFreshness` already dispatches `'error'` on its own failure paths, so the gap is bounded but real.

**Files:**
- Modify: `src/store/index.js:1392-1590` (`applySuggestion`) and `:1592-1750` (`applyBlockStructuralSuggestion`)
- Modify (test): `src/store/__tests__/store-actions.test.js`

- [ ] **Step 1: Read both thunks end-to-end**

```bash
sed -n '1392,1750p' /home/dev/flavor-agent/src/store/index.js
```

Identify every code path that runs after `localDispatch(actions.setBlockApplyState(clientId, 'applying'))` is dispatched. List each branch that does or does not dispatch a follow-up state. The gaps are the synchronous helper calls listed above.

- [ ] **Step 2: Add a failing test asserting state recovers when `updateBlockAttributes` throws**

Append to `src/store/__tests__/store-actions.test.js`:

```js
test( 'applySuggestion resets apply state when updateBlockAttributes throws synchronously', async () => {
	apiFetch.mockResolvedValue( {
		payload: {
			executionContract: {
				resolvedContextSignature: 'resolved-sig',
			},
		},
	} );

	const updateBlockAttributes = jest.fn( () => {
		throw new Error( 'simulated editor crash' );
	} );
	const dispatch = jest.fn();
	const select = {
		getBlockApplyStatus: jest.fn().mockReturnValue( null ),
		getActivityScopeKey: jest.fn(),
		getBlockResolvedContextSignature: jest
			.fn()
			.mockReturnValue( 'resolved-sig' ),
		getBlockRecommendations: jest.fn().mockReturnValue( {
			prompt: '',
			blockContext: {},
			executionContract: { configAttributeKeys: [ 'content' ] },
		} ),
		getBlockRecommendationContextSignature: jest.fn(),
		getBlockRequestToken: jest.fn().mockReturnValue( 1 ),
	};
	const registry = {
		select: jest.fn( () => ( {
			getBlockAttributes: jest.fn().mockReturnValue( {
				content: 'old',
			} ),
			getBlocks: jest.fn().mockReturnValue( [] ),
		} ) ),
		dispatch: jest.fn().mockReturnValue( {
			updateBlockAttributes,
		} ),
	};

	await expect(
		actions.applySuggestion(
			'block-1',
			{
				label: 'Refresh content',
				panel: 'general',
				type: 'attribute_change',
				attributeUpdates: { content: 'new' },
			},
			'resolved-sig',
			{
				clientId: 'block-1',
				editorContext: { block: { name: 'core/paragraph' } },
			}
		)( { dispatch, registry, select } )
	).rejects.toThrow( 'simulated editor crash' );

	const errorDispatch = dispatch.mock.calls.find( ( [ action ] ) =>
		action?.type === 'SET_BLOCK_APPLY_STATE' &&
		action?.applyStatus === 'error'
	);

	expect( errorDispatch ).toBeDefined();
	expect( errorDispatch[ 0 ] ).toEqual(
		expect.objectContaining( {
			applyStatus: 'error',
			clientId: 'block-1',
		} )
	);
} );
```

- [ ] **Step 3: Run the test and confirm it fails**

```bash
cd /home/dev/flavor-agent && npm run test:unit -- --runInBand --testPathPattern store-actions -t 'updateBlockAttributes throws'
```

Expected: 1 failure. Currently the throw propagates without dispatching `'error'`, leaving state in `'applying'`.

- [ ] **Step 4: Wrap the full synchronous post-`'applying'` section in try/catch in `applySuggestion`**

The throw gap covers more than just `updateBlockAttributes`. After `setBlockApplyState(clientId, 'applying')` is dispatched at line 1440, every synchronous helper that runs before the next state-dispatching branch is in scope: `select.getBlockRecommendations`, `registry.select('core/block-editor').getBlockAttributes`, `getBlockSuggestionExecutionInfo`, `buildSafeAttributeUpdates`, `attributeSnapshotsMatch`, `registry.dispatch('core/block-editor').updateBlockAttributes`. Wrap the full section.

In `src/store/index.js`, replace the block from immediately after the `if ( ! resolvedFreshness.ok )` early return (currently line 1462) through the end of the `if ( Object.keys( allowedUpdates ).length > 0 )` block (currently line 1532) with the same code wrapped in a try/catch:

```js
if ( ! resolvedFreshness.ok ) {
	return false;
}

try {
	const storedRecommendationPayload =
		select.getBlockRecommendations( clientId ) || null;
	const storedRecommendations = storedRecommendationPayload || {};
	const blockContext = storedRecommendations.blockContext || {};
	const executionContract =
		storedRecommendations.executionContract || null;
	const blockEditorSelect =
		registry?.select?.( 'core/block-editor' ) || {};
	const blockEditorDispatch =
		registry?.dispatch?.( 'core/block-editor' ) || {};
	const currentAttributes =
		blockEditorSelect.getBlockAttributes?.( clientId ) || {};
	const execution = getBlockSuggestionExecutionInfo(
		suggestion,
		blockContext,
		executionContract
	);
	const allowedUpdates = execution.allowedUpdates;
	let nextAttributes = null;
	let didApply = false;
	let isNoOp = false;

	if ( execution.isAdvisoryOnly ) {
		localDispatch(
			actions.setBlockApplyState(
				clientId,
				'error',
				advisoryApplyMessage
			)
		);
		return false;
	}

	if ( Object.keys( allowedUpdates ).length > 0 ) {
		const safeUpdates = buildSafeAttributeUpdates(
			currentAttributes,
			allowedUpdates
		);
		const proposedNextAttributes = {
			...currentAttributes,
			...safeUpdates,
		};

		if (
			Object.keys( safeUpdates ).length > 0 &&
			attributeSnapshotsMatch(
				currentAttributes,
				proposedNextAttributes
			)
		) {
			isNoOp = true;
		}

		if (
			Object.keys( safeUpdates ).length > 0 &&
			! isNoOp &&
			typeof blockEditorDispatch.updateBlockAttributes ===
				'function'
		) {
			nextAttributes = proposedNextAttributes;
			blockEditorDispatch.updateBlockAttributes(
				clientId,
				safeUpdates
			);
			didApply = true;
		}
	}

	if ( ! didApply ) {
		// ...existing no-op / error branches stay inside the try block...
	}

	// ...existing logStoreActivityEntry + setBlockApplyState('success') tail
	// also stays inside the try block...
} catch ( error ) {
	localDispatch(
		actions.setBlockApplyState(
			clientId,
			'error',
			error?.message || applyErrorMessage
		)
	);
	throw error;
}
```

Move the entire body from line 1466 down through the existing success-tail dispatch into the try block (which means the `await logStoreActivityEntry(...)` and the final `setBlockApplyState(clientId, 'success', ...)` are also inside try). The catch dispatches `'error'` and re-throws so callers and tests can observe the original exception.

Repeat the same wrapping in `applyBlockStructuralSuggestion` — the equivalent post-`setBlockApplyState('applying')` synchronous section starts immediately after the resolved-freshness check in that thunk and runs through the structural-operation executor and activity log.

- [ ] **Step 5: Run the test and confirm it passes**

```bash
npm run test:unit -- --runInBand --testPathPattern store-actions -t 'updateBlockAttributes throws'
```

Expected: pass.

- [ ] **Step 6: Run the full store-actions suite**

```bash
npm run test:unit -- --runInBand --testPathPattern store-actions
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/store/index.js src/store/__tests__/store-actions.test.js
git commit -m "$(cat <<'EOF'
fix(store): clear 'applying' state when synchronous editor calls throw

The new applying-while-applying guard early-returns on a sticky
'applying' state. Wrap the synchronous block-editor dispatches in
try/catch so any unexpected throw transitions the apply state to
'error' before re-throwing, preventing future applies from being
silently no-op'd.
EOF
)"
```

---

## Task 5: Gate the entire trace lifecycle behind an observer-attached check

**Why:** `WordPressAIClient::chat` and `Agent_Controller::handle_recommend_block` unconditionally build heavy trace contexts (JSON-encoding 20-100 KB payloads; deep-walking `editorContext`) and call `RequestTrace::start` / `event` / `finish` on every request. Each `event()` runs `runtime_snapshot()` (5 syscalls) and `sanitize_context()`. The default state has no observer (`flavor_agent_diagnostic_trace_enabled` defaults false; no listener on `flavor_agent_diagnostic_trace`). The fix gates the *entire* trace lifecycle, not just context construction.

**Files:**
- Modify: `tests/phpunit/bootstrap.php` — add a `has_action` shim
- Modify: `inc/Support/RequestTrace.php` — add `is_consumed()`
- Modify: `inc/LLM/WordPressAIClient.php` — gate `start`/`event`/`finish` calls
- Modify: `inc/REST/Agent_Controller.php` — gate `start`/`event`/`finish` calls
- Modify (test): `tests/phpunit/RequestTraceTest.php`, `tests/phpunit/WordPressAIClientTest.php`

- [ ] **Step 1: Add a `has_action` shim to the PHPUnit bootstrap**

The bootstrap's listener machinery already stores callbacks in `WordPressTestState::$filters[$hook_name]`, and `add_action` is an alias of `add_filter` (`tests/phpunit/bootstrap.php:1888-1891`). The new `is_consumed()` helper will call `has_action`, which is not yet shimmed. Add it just below the `remove_all_filters` shim (around line 1988):

```php
if ( ! function_exists( 'has_action' ) ) {
	function has_action( string $hook_name, $callback = false ) {
		if ( empty( WordPressTestState::$filters[ $hook_name ] ) ) {
			return false;
		}

		if ( false === $callback ) {
			return true;
		}

		foreach ( WordPressTestState::$filters[ $hook_name ] as $priority => $entries ) {
			foreach ( $entries as $entry ) {
				if ( ( $entry['callback'] ?? null ) === $callback ) {
					return $priority;
				}
			}
		}

		return false;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook_name, $callback = false ) {
		return has_action( $hook_name, $callback );
	}
}
```

This mirrors WordPress core's contract: returns `false` when no listener, `true` when present without a callback filter, or the priority integer when matched against a specific callback.

- [ ] **Step 2: Add a failing test for the new `RequestTrace::is_consumed` helper**

Append to `tests/phpunit/RequestTraceTest.php`:

```php
public function test_is_consumed_returns_false_when_no_observer_or_log_filter(): void {
	$this->assertFalse( RequestTrace::is_consumed() );
}

public function test_is_consumed_returns_true_when_action_listener_attached(): void {
	add_action( 'flavor_agent_diagnostic_trace', static fn () => null );

	$this->assertTrue( RequestTrace::is_consumed() );
}

public function test_is_consumed_returns_true_when_log_filter_enables_writes(): void {
	add_filter( 'flavor_agent_diagnostic_trace_enabled', '__return_true' );

	$this->assertTrue( RequestTrace::is_consumed() );
}
```

- [ ] **Step 3: Run the tests and confirm they fail**

```bash
vendor/bin/phpunit --filter test_is_consumed
```

Expected: 3 errors (method not found).

- [ ] **Step 4: Add `is_consumed()` to `RequestTrace`**

In `inc/Support/RequestTrace.php`, add this static method (place it near `is_active()`):

```php
public static function is_consumed(): bool {
	if ( function_exists( 'has_action' ) && false !== has_action( self::ACTION_HOOK ) ) {
		return true;
	}

	return self::should_write_error_log();
}
```

`should_write_error_log()` is already private and gates the `error_log` path; reusing it here keeps the two consumption channels in one decision.

- [ ] **Step 5: Run the new tests and confirm they pass**

```bash
vendor/bin/phpunit --filter test_is_consumed
```

Expected: 3 passes.

- [ ] **Step 6: Instrument the bootstrap shim to count `do_action` invocations and add the failing trace-skip test**

A listener-based test can't observe whether `RequestTrace::start` ran when no listener was attached (because `do_action` without listeners is a no-op for callbacks). To verify the gate, instrument the bootstrap's `do_action` shim with an invocation counter that increments regardless of listener presence.

In `tests/phpunit/bootstrap.php`, add a counter field to `WordPressTestState`:

```php
/** @var array<string, int> */
public static array $do_action_counts = [];
```

Reset it in `WordPressTestState::reset()`:

```php
self::$do_action_counts = [];
```

In the `do_action` shim (around line 1894-1913), increment the counter before the early-return:

```php
function do_action( string $hook_name, ...$args ): void {
	WordPressTestState::$do_action_counts[ $hook_name ] =
		( WordPressTestState::$do_action_counts[ $hook_name ] ?? 0 ) + 1;

	if ( empty( WordPressTestState::$filters[ $hook_name ] ) ) {
		return;
	}

	// ... existing iteration loop unchanged ...
}
```

Now the counter records every `do_action('flavor_agent_diagnostic_trace', $entry)` call, including those that no listener observes.

Append two tests to `tests/phpunit/WordPressAIClientTest.php`:

```php
public function test_chat_emits_trace_events_when_observer_is_attached(): void {
	WordPressTestState::$ai_client_supported            = true;
	WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';

	$events = [];
	add_action(
		'flavor_agent_diagnostic_trace',
		static function ( array $entry ) use ( &$events ): void {
			$events[] = $entry['event'] ?? '';
		}
	);

	WordPressAIClient::chat( 'sys', 'user' );

	$this->assertSame(
		[ 'ai.chat.start', 'ai.chat.request_ready', 'ai.chat.response_ready', 'ai.chat.finish' ],
		$events
	);
}

public function test_chat_skips_trace_lifecycle_when_no_observer_is_attached(): void {
	WordPressTestState::$ai_client_supported            = true;
	WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';

	WordPressAIClient::chat( 'sys', 'user' );

	$this->assertSame(
		0,
		WordPressTestState::$do_action_counts['flavor_agent_diagnostic_trace'] ?? 0,
		'RequestTrace::emit should not fire when no observer is attached.'
	);
}
```

The "emits" test verifies the listener path. The "skips" test reads the bootstrap counter, which records every `do_action` invocation regardless of listener presence — so it observes whether `RequestTrace::emit()` (and therefore `start`/`event`/`finish`) ran at all.

- [ ] **Step 7: Run the failing tests**

```bash
vendor/bin/phpunit --filter 'test_chat_(emits_trace_events_when|skips_trace_lifecycle_when)'
```

Expected: the "skips" test fails (the production code calls `RequestTrace::start` unconditionally, which calls `RequestTrace::event` → `do_action`, incrementing the counter). The "emits" test passes because the existing diff already wires up the listener path.

- [ ] **Step 8: Gate the chat trace lifecycle in `WordPressAIClient::chat`**

In `inc/LLM/WordPressAIClient.php`, locate the trace plumbing inside `chat()` (the diff added the new `RequestTrace::start` / `RequestTrace::event` / `RequestTrace::finish` calls around lines 138-260 of the file). Wrap *every* `RequestTrace::*` call site in `if ( RequestTrace::is_consumed() )` (or, equivalently, capture the boolean once at the top of `chat()` into `$trace_consumed = RequestTrace::is_consumed();` and reuse). The `build_chat_trace_context` call should be inside the same gate so its work is never paid when no observer is present:

```php
$trace_consumed = RequestTrace::is_consumed();
$chat_trace_context = $trace_consumed
	? self::build_chat_trace_context(
		$system_prompt,
		$user_prompt,
		$provider,
		$reasoning_effort,
		$schema,
		$request_timeout_seconds,
		$schema_union_count
	)
	: [];
$owns_trace = $trace_consumed && ! RequestTrace::is_active();

if ( $trace_consumed ) {
	if ( $owns_trace ) {
		RequestTrace::start( 'wordpress-ai-client', $chat_trace_context, 'ai.chat.start' );
	} else {
		RequestTrace::event( 'ai.chat.start', $chat_trace_context );
	}

	RequestTrace::event( 'ai.chat.request_ready', $chat_trace_context );
}
```

Repeat the pattern for every subsequent `RequestTrace::event(...)` and `RequestTrace::finish(...)` site in `chat()` — wrap each in `if ( $trace_consumed )`. (The `$owns_trace` variable continues to gate finish vs. event for nested traces.) When `$trace_consumed` is false, the entire trace lifecycle is skipped.

- [ ] **Step 9: Apply the same gating in `Agent_Controller::handle_recommend_block`**

In `inc/REST/Agent_Controller.php`, locate the diff-added `RequestTrace::start(...)` at the top of `handle_recommend_block`. Wrap it and every subsequent `RequestTrace::event(...)` / `RequestTrace::finish(...)` call in `if ( $trace_consumed )` using the same pattern:

```php
$trace_consumed = RequestTrace::is_consumed();

if ( $trace_consumed ) {
	RequestTrace::start(
		'recommend-block',
		self::build_recommend_block_trace_context( $request, $resolve_signature_only ),
		'rest.recommend_block.start'
	);
}
```

The `build_recommend_block_trace_context` call (which itself calls the recursive `sanitize_structured_value`) is inside the gate, so the deep walk only runs when something is observing the trace.

- [ ] **Step 10: Run the gated tests plus the full PHPUnit suite**

```bash
vendor/bin/phpunit tests/phpunit/RequestTraceTest.php tests/phpunit/WordPressAIClientTest.php tests/phpunit/AgentControllerTest.php
```

Expected: all green. Pay special attention to `test_handle_recommend_block_emits_layered_diagnostic_trace_events` and `test_chat_emits_structured_diagnostic_trace_events_when_enabled` — both register listeners before the chat/REST call (so `is_consumed()` returns true), and they must continue to pass.

- [ ] **Step 11: Commit**

```bash
git add inc/Support/RequestTrace.php inc/LLM/WordPressAIClient.php inc/REST/Agent_Controller.php \
	tests/phpunit/RequestTraceTest.php tests/phpunit/WordPressAIClientTest.php tests/phpunit/bootstrap.php
git commit -m "$(cat <<'EOF'
perf(trace): skip the trace lifecycle when no observer is attached

The diagnostic trace previously ran start/event/finish on every chat
and recommend-block request, including JSON-encoding the request body,
deep-walking editorContext, and emitting runtime snapshots per event.
Gate the entire lifecycle behind RequestTrace::is_consumed() so the
work only happens when an action listener is attached or the
diagnostic_trace_enabled filter is on. Adds a has_action shim to the
PHPUnit bootstrap so tests can verify the gate.
EOF
)"
```

---

## Task 6: Consolidate the duplicate `throwable_context` helper into `RequestTrace`

**Why:** `WordPressAIClient::build_throwable_trace_context` (`inc/LLM/WordPressAIClient.php:1080-1089`) and `Agent_Controller::build_trace_throwable_context` (`inc/REST/Agent_Controller.php:1121-1130`) are byte-for-byte identical. The two files' `*_error_*` siblings, however, differ meaningfully: `WordPressAIClient::build_error_trace_context` returns only `code`/`message`/`status`; `Agent_Controller::build_trace_error_context` *summarizes* `requestMeta` through `summarize_trace_request_meta` (a whitelist of `provider`, `model`, `transport`, `requestSummary`, `responseSummary`, `errorSummary`) to keep raw API keys, response bodies, and other internals out of the trace. Consolidating them into a single `wp_error_context` helper would either drop the summarization (privacy/size regression) or bake Agent_Controller's whitelist into a shared helper that WordPressAIClient doesn't want. Keep the error helpers per-caller; only consolidate `throwable_context`.

**Files:**
- Modify: `inc/Support/RequestTrace.php` — add `throwable_context` static helper
- Modify: `inc/LLM/WordPressAIClient.php` — delete local `build_throwable_trace_context`, route to `RequestTrace::throwable_context`
- Modify: `inc/REST/Agent_Controller.php` — delete local `build_trace_throwable_context`, route to `RequestTrace::throwable_context`
- Modify (test): `tests/phpunit/RequestTraceTest.php`

- [ ] **Step 1: Add a failing test for the new `RequestTrace::throwable_context` helper**

Append to `tests/phpunit/RequestTraceTest.php`:

```php
public function test_throwable_context_returns_canonical_shape(): void {
	$throwable = new \RuntimeException( 'boom' );
	$context   = RequestTrace::throwable_context( $throwable );

	$this->assertSame( \RuntimeException::class, $context['throwable']['class'] );
	$this->assertSame( 'boom', $context['throwable']['message'] );
	$this->assertNotEmpty( $context['throwable']['file'] );
	$this->assertIsInt( $context['throwable']['line'] );
}
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
vendor/bin/phpunit --filter test_throwable_context_returns_canonical_shape
```

Expected: error (method not found).

- [ ] **Step 3: Implement the helper in `RequestTrace`**

In `inc/Support/RequestTrace.php`, add this public static method:

```php
/**
 * @return array{throwable: array{class: string, message: string, file: string, line: int}}
 */
public static function throwable_context( \Throwable $throwable ): array {
	return [
		'throwable' => [
			'class'   => get_class( $throwable ),
			'message' => $throwable->getMessage(),
			'file'    => $throwable->getFile(),
			'line'    => $throwable->getLine(),
		],
	];
}
```

- [ ] **Step 4: Run the test and confirm it passes**

```bash
vendor/bin/phpunit --filter test_throwable_context_returns_canonical_shape
```

Expected: pass.

- [ ] **Step 5: Replace `build_throwable_trace_context` in `WordPressAIClient.php`**

Delete the private static method `build_throwable_trace_context` from `inc/LLM/WordPressAIClient.php`. Replace every call site (search for `build_throwable_trace_context(`) with `RequestTrace::throwable_context(...)`. Leave `build_error_trace_context` alone — its caller-specific shape is intentional. Run:

```bash
grep -n 'build_throwable_trace_context' /home/dev/flavor-agent/inc/LLM/WordPressAIClient.php
```

Expected after the edit: no matches.

- [ ] **Step 6: Replace `build_trace_throwable_context` in `Agent_Controller.php`**

Delete the private static method `build_trace_throwable_context` from `inc/REST/Agent_Controller.php`. Replace every call site with `RequestTrace::throwable_context(...)`. Leave `build_trace_error_context` and `summarize_trace_request_meta` alone — `summarize_trace_request_meta` whitelists keys (`provider`, `model`, `transport`, `requestSummary`, `responseSummary`, `errorSummary`) to keep raw `requestMeta` values out of trace events; copying the whole array would be a privacy/size regression. Confirm:

```bash
grep -n 'build_trace_throwable_context' /home/dev/flavor-agent/inc/REST/Agent_Controller.php
```

Expected: no matches.

- [ ] **Step 7: Run the full PHPUnit suite to confirm nothing regresses**

```bash
vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add inc/Support/RequestTrace.php inc/LLM/WordPressAIClient.php inc/REST/Agent_Controller.php tests/phpunit/RequestTraceTest.php
git commit -m "$(cat <<'EOF'
refactor(trace): consolidate duplicate throwable_context helper

WordPressAIClient::build_throwable_trace_context and
Agent_Controller::build_trace_throwable_context were byte-identical.
Move the helper to RequestTrace::throwable_context(). The error-context
helpers stay per-caller because Agent_Controller summarizes requestMeta
through a whitelist that WordPressAIClient does not need.
EOF
)"
```

---

## Task 7: Add cross-surface tests for the "idle preserves stale" reducer change

**Why:** The reducer change in `src/store/executable-surfaces.js:746-767` (idle no longer clears `reviewStaleReason`; only `'fresh'` clears it) is shared across **template, template-part, global-styles, and style-book** surfaces. New tests cover only navigation. Without sibling tests, a future surface refactor that dispatches `'idle'` from any of the four shared-reducer surfaces could re-introduce the bug uncaught.

**Files:**
- Modify (test): `src/store/__tests__/store-actions.test.js`

- [ ] **Step 1: Identify the four shared-reducer surfaces and their action creators**

```bash
grep -n "createExecutableSurfaceReviewFreshnessAction\|reduceExecutableSurface\|reviewFreshnessStatusKey" /home/dev/flavor-agent/src/store/executable-surfaces.js | head -40
```

Expected: locate the four definitions for template, template-part, global-styles, style-book. Capture each surface's action creator name (e.g., `setTemplateReviewFreshnessState`, `setTemplatePartReviewFreshnessState`, `setGlobalStylesReviewFreshnessState`, `setStyleBookReviewFreshnessState`) and selector names (`getTemplateReviewFreshnessStatus`, `getTemplateReviewStaleReason`, etc.).

- [ ] **Step 2: Add a parameterized test covering all four surfaces**

Append to `src/store/__tests__/store-actions.test.js`:

```js
describe.each( [
	{
		surface: 'template',
		setRecommendations: 'setTemplateRecommendations',
		setReviewFreshness: 'setTemplateReviewFreshnessState',
		getStatus: 'getTemplateReviewFreshnessStatus',
		getStaleReason: 'getTemplateReviewStaleReason',
		idArg: 'home',
		reviewSig: 'review-template',
	},
	{
		surface: 'template-part',
		setRecommendations: 'setTemplatePartRecommendations',
		setReviewFreshness: 'setTemplatePartReviewFreshnessState',
		getStatus: 'getTemplatePartReviewFreshnessStatus',
		getStaleReason: 'getTemplatePartReviewStaleReason',
		idArg: 'header',
		reviewSig: 'review-template-part',
	},
	{
		surface: 'global-styles',
		setRecommendations: 'setGlobalStylesRecommendations',
		setReviewFreshness: 'setGlobalStylesReviewFreshnessState',
		getStatus: 'getGlobalStylesReviewFreshnessStatus',
		getStaleReason: 'getGlobalStylesReviewStaleReason',
		idArg: 'global',
		reviewSig: 'review-global-styles',
	},
	{
		surface: 'style-book',
		setRecommendations: 'setStyleBookRecommendations',
		setReviewFreshness: 'setStyleBookReviewFreshnessState',
		getStatus: 'getStyleBookReviewFreshnessStatus',
		getStaleReason: 'getStyleBookReviewStaleReason',
		idArg: 'book',
		reviewSig: 'review-style-book',
	},
] )(
	'$surface review freshness preserves server-stale reason on idle transitions',
	( spec ) => {
		test( 'idle does not clear stored stale reason', () => {
			let state = reducer(
				undefined,
				actions[ spec.setRecommendations ](
					spec.idArg,
					{ suggestions: [], explanation: '' },
					'Prompt',
					1,
					'request-sig',
					spec.reviewSig
				)
			);

			state = reducer(
				state,
				actions[ spec.setReviewFreshness ]( 'stale', 2, 'server-review' )
			);

			state = reducer(
				state,
				actions[ spec.setReviewFreshness ]( 'idle', 3 )
			);

			expect( selectors[ spec.getStatus ]( state, spec.idArg ) ).toBe(
				'idle'
			);
			expect(
				selectors[ spec.getStaleReason ]( state, spec.idArg )
			).toBe( 'server-review' );
		} );
	}
);
```

If any of the spec's action creator or selector names don't exist (verify via the grep in Step 1), update the spec for that surface. The parameterization is the point — same assertion, four surfaces.

- [ ] **Step 3: Run the new tests**

```bash
npm run test:unit -- --runInBand --testPathPattern store-actions -t 'review freshness preserves server-stale reason on idle transitions'
```

Expected: 4 passes (one per surface). The reducer change is already in place from the diff under review, so these tests should pass on first run — the value is preventing future regressions.

- [ ] **Step 4: Commit**

```bash
git add src/store/__tests__/store-actions.test.js
git commit -m "$(cat <<'EOF'
test(store): cover idle-preserves-stale across all shared-reducer surfaces

The setReviewFreshnessState reducer change applies to template,
template-part, global-styles, and style-book — not just navigation.
Add a parameterized test asserting that an idle transition preserves
a stored server-stale reason for each of the four surfaces.
EOF
)"
```

---

## Task 8: Expand `RequestTrace` test coverage

**Why:** `tests/phpunit/RequestTraceTest.php` currently has one test. The new file covers ~300 lines of code with sanitization caps, fatal-error capture, filter-throws handling, and runtime snapshot logic — none of which are tested.

**Files:**
- Modify (test): `tests/phpunit/RequestTraceTest.php`

- [ ] **Step 1: Add tests for sanitization caps**

Append to `tests/phpunit/RequestTraceTest.php`:

```php
public function test_event_truncates_strings_beyond_max_bytes(): void {
	$captured = [];
	add_filter( 'flavor_agent_diagnostic_trace_enabled', '__return_false' );
	add_action(
		'flavor_agent_diagnostic_trace',
		static function ( array $entry ) use ( &$captured ): void {
			$captured[] = $entry;
		},
		10,
		1
	);

	RequestTrace::start( 'test-surface', [], 'trace.start' );
	RequestTrace::event(
		'trace.event',
		[ 'longString' => str_repeat( 'a', 1000 ) ]
	);
	RequestTrace::finish();

	$event_entry = $captured[1] ?? [];
	$this->assertStringEndsWith( '...', $event_entry['context']['longString'] ?? '' );
	$this->assertSame( 303, strlen( $event_entry['context']['longString'] ?? '' ) ); // 300 + '...'
}

public function test_event_truncates_arrays_beyond_max_items(): void {
	$captured = [];
	add_filter( 'flavor_agent_diagnostic_trace_enabled', '__return_false' );
	add_action(
		'flavor_agent_diagnostic_trace',
		static function ( array $entry ) use ( &$captured ): void {
			$captured[] = $entry;
		}
	);

	RequestTrace::start( 'test-surface', [], 'trace.start' );
	RequestTrace::event( 'trace.event', [ 'items' => range( 1, 100 ) ] );
	RequestTrace::finish();

	$event_entry = $captured[1] ?? [];
	$this->assertSame( 70, $event_entry['context']['items']['_truncated'] ?? null );
}

public function test_event_caps_recursion_depth(): void {
	$captured = [];
	add_filter( 'flavor_agent_diagnostic_trace_enabled', '__return_false' );
	add_action(
		'flavor_agent_diagnostic_trace',
		static function ( array $entry ) use ( &$captured ): void {
			$captured[] = $entry;
		}
	);

	$deep = 'leaf';
	for ( $i = 0; $i < 10; $i++ ) {
		$deep = [ 'next' => $deep ];
	}

	RequestTrace::start( 'test-surface', [], 'trace.start' );
	RequestTrace::event( 'trace.event', [ 'tree' => $deep ] );
	RequestTrace::finish();

	// MAX_CONTEXT_DEPTH = 4. The event context root is depth 0; tree's value
	// is sanitized at depth 1; each nested 'next' value adds one depth. The
	// '[truncated]' sentinel appears at depth 4, which is reached after three
	// 'next' hops from $context['tree'].
	$walked = $captured[1]['context']['tree'] ?? null;
	for ( $i = 0; $i < 3; $i++ ) {
		$this->assertIsArray( $walked );
		$walked = $walked['next'];
	}
	$this->assertSame( '[truncated]', $walked );
}
```

- [ ] **Step 2: Add a test for the filter-throws path in `should_write_error_log`**

Append:

```php
public function test_should_write_error_log_returns_false_when_filter_throws(): void {
	add_filter(
		'flavor_agent_diagnostic_trace_enabled',
		static function (): bool {
			throw new \RuntimeException( 'filter exploded' );
		}
	);

	RequestTrace::start( 'test-surface', [], 'trace.start' );

	// No assertion needed beyond "no exception escapes"; this test fails by
	// throwing if should_write_error_log doesn't catch the filter exception.
	$this->expectNotToPerformAssertions();

	RequestTrace::finish();
}
```

- [ ] **Step 3: Add a test for `is_active()` toggling correctly across start/finish**

Append:

```php
public function test_is_active_toggles_with_start_and_finish(): void {
	$this->assertFalse( RequestTrace::is_active() );

	RequestTrace::start( 'test-surface' );
	$this->assertTrue( RequestTrace::is_active() );

	RequestTrace::finish();
	$this->assertFalse( RequestTrace::is_active() );
}
```

- [ ] **Step 4: Run the new tests**

```bash
vendor/bin/phpunit tests/phpunit/RequestTraceTest.php
```

Expected: all green. If any test reveals real bugs in `RequestTrace.php`, stop and surface them before proceeding — they should be addressed before merging the branch.

- [ ] **Step 5: Commit**

```bash
git add tests/phpunit/RequestTraceTest.php
git commit -m "$(cat <<'EOF'
test(trace): cover RequestTrace sanitization caps and filter-throws path

The new RequestTrace utility had only one test (the WP_DEBUG gate).
Add coverage for MAX_STRING_BYTES truncation, MAX_ARRAY_ITEMS '_truncated'
marker, MAX_CONTEXT_DEPTH '[truncated]' marker, the filter-throws guard
in should_write_error_log, and start/finish state toggling.
EOF
)"
```

---

## Task 9: Document and adversarially test `Prompt::extract_json_object` limits

**Why:** The new `extract_json_object` (`inc/LLM/Prompt.php:883-892`) uses naive `strpos('{')` + `strrpos('}')`. On responses with multiple top-level objects (e.g., LLM emits an example object before the real one) or string literals containing `}`, the slice produces invalid JSON. The `WP_Error` fall-through prevents data corruption, but the function name implies more than it delivers, and the recovery is fragile. We won't replace the algorithm (the cost of a stack-based brace-balanced scanner isn't justified given the WP_Error fallback), but we'll lock in the limits and document the contract.

**Files:**
- Modify: `inc/LLM/Prompt.php` — add a doc comment to `extract_json_object`
- Modify (test): `tests/phpunit/PromptRulesTest.php`

- [ ] **Step 1: Add adversarial parse tests**

Append to `tests/phpunit/PromptRulesTest.php`:

```php
public function test_parse_response_returns_wp_error_when_response_has_two_top_level_json_objects(): void {
	$result = Prompt::parse_response(
		'Example: {"a":1} Real: {"settings":[],"styles":[],"block":[],"explanation":"OK"}'
	);

	$this->assertInstanceOf( \WP_Error::class, $result );
	$this->assertSame( 'parse_error', $result->get_error_code() );
}

public function test_parse_response_returns_wp_error_when_string_literal_contains_unbalanced_brace(): void {
	$result = Prompt::parse_response(
		'Note: use {} for empty. Then: {"settings":[],"styles":[],"block":[],"explanation":"OK"}'
	);

	$this->assertInstanceOf( \WP_Error::class, $result );
	$this->assertSame( 'parse_error', $result->get_error_code() );
}

public function test_parse_response_recovers_when_json_object_is_followed_by_trailing_prose(): void {
	$result = Prompt::parse_response(
		'{"settings":[],"styles":[],"block":[],"explanation":"OK"} (end of message)'
	);

	$this->assertIsArray( $result );
	$this->assertSame( 'OK', $result['explanation'] );
}
```

These lock in the current contract: prose surrounding a single JSON object is recovered; multiple top-level objects or unbalanced braces fall through to `WP_Error`.

- [ ] **Step 2: Run the tests**

```bash
vendor/bin/phpunit --filter 'test_parse_response_(returns_wp_error_when|recovers_when_json_object_is_followed)'
```

Expected: all green (the existing implementation already produces these outcomes).

- [ ] **Step 3: Document the contract on `extract_json_object`**

In `inc/LLM/Prompt.php`, add a doc comment immediately above `private static function extract_json_object`:

```php
/**
 * Recover a single JSON object from a response that may include surrounding prose.
 *
 * Naive scan: returns the substring between the first `{` and the last `}`. This
 * handles the common case where the model prefixes/suffixes the JSON payload with
 * a sentence or two. It does NOT handle multiple top-level objects, unbalanced
 * braces inside string literals, or code-fenced JSON containing prose. Those
 * cases fall through to the `parse_error` WP_Error path in `decode_response_json`.
 */
```

- [ ] **Step 4: Run the full PromptRulesTest suite**

```bash
vendor/bin/phpunit tests/phpunit/PromptRulesTest.php
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/Prompt.php tests/phpunit/PromptRulesTest.php
git commit -m "$(cat <<'EOF'
test(prompt): lock in extract_json_object recovery contract

Document that extract_json_object handles only single-object prose
recovery and add adversarial tests for the two-object and
unbalanced-brace cases that fall through to WP_Error.
EOF
)"
```

---

## Task 10: Cleanup batch — sentinels, magic numbers, narrative comments, dead code

**Why:** Four small smells worth fixing as one bundled commit so the cleanup doesn't sprawl across the history. Each is independent.

**Files:**
- Modify: `src/inspector/use-suggestion-apply-feedback.js` — `'pending'` sentinel
- Modify: `inc/LLM/Prompt.php` — `0.35` magic literal
- Modify: `inc/AzureOpenAI/ResponsesClient.php` — narrative class comment
- Modify: `src/store/index.js` — drop `API_FETCH_INVALID_JSON_MESSAGE` string match
- Modify: `inc/LLM/WritingPrompt.php` — adopt `WordPressAIPolicy::sanitize_textarea_content`

- [ ] **Step 1: Replace the `'pending'` string sentinel with a Symbol**

In `src/inspector/use-suggestion-apply-feedback.js`, define a module-level sentinel and use it:

```js
const PENDING_FALLBACK_KEY = Symbol( 'pending' );

// ...inside handleApply...
pendingKeyRef.current = key || PENDING_FALLBACK_KEY;
setPendingKey( pendingKeyRef.current );
```

In the consumers that compare `pendingKey === key` (specifically `src/inspector/SuggestionChips.js` around `const isPending = pendingKey === key`), the comparison still works because Symbols compare by identity — only the fallback case fails to match real keys, which is the desired behavior. Confirm there are no other consumers by:

```bash
grep -n 'pendingKey' /home/dev/flavor-agent/src/inspector/*.js
```

- [ ] **Step 2: Run the SuggestionChips test suite**

```bash
npm run test:unit -- --runInBand --testPathPattern SuggestionChips
```

Expected: all green. The "suppresses duplicate clicks while apply is pending" test confirms the deduping still works.

- [ ] **Step 3: Promote the `0.35` confidence to a documented constant**

In `inc/LLM/Prompt.php`, add a class constant near the existing private constants:

```php
/**
 * Confidence floor for suggestions recovered from a plain-text block lane response.
 * Below the standard 0.5 mid-confidence threshold to signal "model returned prose
 * instead of structured JSON; treat as low-confidence advisory."
 */
private const PLAIN_TEXT_RECOVERY_CONFIDENCE = 0.35;
```

Replace the literal in `build_plain_text_advisory_suggestion`:

```php
'confidence' => self::PLAIN_TEXT_RECOVERY_CONFIDENCE,
```

- [ ] **Step 4: Replace the "Workstream C" narrative comment**

In `inc/AzureOpenAI/ResponsesClient.php`, replace the class doc comment (currently starting "After Workstream C of the WP 7.0 overlap remediation, ...") with:

```php
/**
 * Thin compatibility facade for legacy callers. Chat is owned by core
 * `Settings > Connectors` via the WordPress AI Client; this class only
 * translates the legacy `rank()` signature into a WordPressAIClient::chat()
 * call.
 */
```

- [ ] **Step 5: Drop the stringly-typed `apiFetch` error message check**

In `src/store/index.js`, locate the constants:

```js
const API_FETCH_INVALID_JSON_MESSAGE =
	'The response is not a valid JSON response.';
```

Delete the constant. In `buildBlockRecommendationFailureDiagnostics`, change:

```js
const isInvalidJsonResponse =
	errorCode === 'invalid_json' ||
	rawMessage === API_FETCH_INVALID_JSON_MESSAGE;
```

to:

```js
const isInvalidJsonResponse = errorCode === 'invalid_json';
```

The `errorCode` check is sufficient on `@wordpress/api-fetch` 7+ (which is the floor for WP 7.0). The string-equality check was dead on EN locales and silently broken on translated installs.

- [ ] **Step 6: Migrate `WritingPrompt::sanitize_editorial_text` to `WordPressAIPolicy::sanitize_textarea_content` without weakening the type guard**

The current signature accepts `mixed $value` and short-circuits to `''` when the input is not a string. `parse_response()` passes JSON-decoded fields directly, which can be any type, so under `declare(strict_types=1)` a string-typed signature would TypeError at runtime. Keep the `mixed` signature and the `is_string` guard; only route the string through the policy helper.

In `inc/LLM/WritingPrompt.php`, locate `sanitize_editorial_text` (currently at line 328-334) and replace the body:

```php
private static function sanitize_editorial_text( mixed $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}

	return WordPressAIPolicy::sanitize_textarea_content( $value );
}
```

Add `use FlavorAgent\Support\WordPressAIPolicy;` at the top of the file if it isn't already imported.

- [ ] **Step 7: Run the targeted suites**

```bash
npm run test:unit -- --runInBand --testPathPattern '(SuggestionChips|store-actions)' \
&& vendor/bin/phpunit tests/phpunit/PromptRulesTest.php tests/phpunit/ContentAbilitiesTest.php
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add src/inspector/use-suggestion-apply-feedback.js \
	inc/LLM/Prompt.php inc/AzureOpenAI/ResponsesClient.php \
	src/store/index.js inc/LLM/WritingPrompt.php
git commit -m "$(cat <<'EOF'
chore: cleanup five low-severity review findings

- Replace 'pending' string sentinel in apply-feedback with a Symbol
- Promote 0.35 plain-text recovery confidence to a documented constant
- Replace 'Workstream C' narrative class comment in ResponsesClient
- Drop the dead string-equality match on apiFetch's i18n error message
- Route WritingPrompt::sanitize_editorial_text through WordPressAIPolicy
EOF
)"
```

---

## Task 11: Final verification

- [ ] **Step 1: Run the aggregate verifier without E2E**

```bash
cd /home/dev/flavor-agent && npm run verify -- --skip-e2e
```

Expected: stdout ends with a `VERIFY_RESULT={...}` line containing `"status":"pass"`. If `lint-plugin` is reported as `incomplete` because WP-CLI / WP root isn't reachable from this environment, re-run with `--skip=lint-plugin` and document the skip in the PR description.

- [ ] **Step 2: Inspect `output/verify/summary.json`**

```bash
cat output/verify/summary.json | head -60
```

Expected: `"status":"pass"`, all per-step entries `"status":"pass"` or `"status":"skipped"` (if explicitly skipped), no `"status":"fail"`.

- [ ] **Step 3: Run `npm run check:docs`**

```bash
npm run check:docs
```

Expected: clean. If it flags stale docs, update the affected `docs/reference/*` or `docs/features/*` doc and re-run before final commit.

- [ ] **Step 4: Confirm no lingering references to deleted symbols**

```bash
grep -rn 'INTEGRATION_GUIDE\|API_FETCH_INVALID_JSON_MESSAGE\|build_throwable_trace_context\|build_trace_throwable_context\|build_error_trace_context\|build_trace_error_context\|Workstream C' \
	/home/dev/flavor-agent/inc /home/dev/flavor-agent/src /home/dev/flavor-agent/docs 2>/dev/null
```

Expected: no matches outside this plan file.

---

## Deferred (intentionally not in this plan)

The review surfaced several lower-priority items that are real but not urgent enough to gate this branch:

| Finding | Why deferred |
|--------|--------------|
| Bare-fenced-JSON cleaning duplicated in 4 sibling prompt classes (`TemplatePrompt`, `StylePrompt`, `TemplatePartPrompt`, `NavigationPrompt`) | Refactor benefits all surfaces but is a separate cleanup PR; the per-surface code still works. |
| Sister REST handlers (`recommend-content`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, `recommend-style`, `recommend-pattern`) lack the trace + try/catch wrapper that `recommend-block` got | Should be unified via a `with_trace($surface, $callable)` helper after Task 6 lands; track in a follow-up issue. |
| Reducer reference-equality early-return in `setReviewFreshnessState` and the navigation parallel reducer | Optimization, not correctness; `useSelect`'s shallow equality cushions the impact. Tackle alongside the bare-JSON consolidation. |
| `Activity\Serializer::normalize_structured_value` has no depth/size cap | After Task 5 gates trace context construction, the unbounded walk is no longer a hot path. Keep on the radar for a defense-in-depth pass. |
| `RequestTrace::finish()` clears `$active`, silently dropping post-finish fatals | Document-only fix; the "trace ends at `finish()`" boundary is the intended contract. Add a doc comment in a follow-up. |
| Chip-level `pendingKey` and store-level `getBlockApplyStatus === 'applying'` guard solve the same problem at two layers | Defense-in-depth is acceptable; revisit only if a non-chip caller appears. |
| `BlockTypeIntrospectorTest` thin coverage (uppercase tokens, multi-style precedence) | Add when the introspector is next touched. |
| `WordPressAIPolicy::ability_name_for_schema_name` schema map duplicates REST routes / abilities | Centralize when the next surface is added; today the `match` is a five-second update. |
| Duplicate parallel reducer for navigation review-freshness vs. the shared `reduceExecutableSurface` | Migration work, not a fix; tracked under "navigation-on-shared-reducer" in `STATUS.md`. |

Document each as a follow-up issue or a `// TODO` only if the team wants explicit tracking; otherwise the reasoning above is the record.
