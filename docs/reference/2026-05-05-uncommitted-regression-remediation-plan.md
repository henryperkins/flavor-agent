# Uncommitted Regression Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the three confirmed regressions from the uncommitted-changes review: AI feature toggles must not be force-enabled, provider precedence docs and explicit runtime behavior must agree, and Cloudflare AI Search pattern setup must focus the correct settings section.

**Architecture:** Keep production behavior aligned with the canonical WordPress AI Feature/Abilities contract: AI feature enablement is owned by the AI plugin settings, chat is owned by the WordPress AI Client / Connectors runtime, and pattern storage setup is independent from the plugin-owned embedding model when Cloudflare AI Search is selected. Use focused tests first, make the smallest runtime edits, then update docs that currently encode the wrong contract.

**Tech Stack:** WordPress plugin PHP 8.0+, WordPress Settings API, WordPress AI plugin Feature/Abilities contracts, PHPUnit, @wordpress/scripts unit/build tooling, Playwright WP 7.0 harness docs.

---

## Findings Being Addressed

1. `FeatureBootstrap::seed_ai_feature_options()` force-enables missing AI plugin feature toggles on activation and every `admin_init`, bypassing the missing-option default-false contract and writing a global AI plugin option from Flavor Agent.
2. `docs/reference/provider-precedence.md` says `Provider::chat_configuration( $provider )` returns the generic WordPress AI Client runtime for direct embedding providers, but the explicit-provider code path only does that for Cloudflare Workers AI.
3. `State::determine_default_open_group()` opens the Embedding Model section before pattern storage checks, so Cloudflare AI Search pattern setup can point the admin at an irrelevant section when private pattern storage credentials are missing.

## Files And Responsibilities

- Modify `flavor-agent.php`: remove production feature-toggle seeding from activation and `admin_init`.
- Modify `inc/AI/FeatureBootstrap.php`: remove `seed_ai_feature_options()` and keep `recommendation_feature_enabled()` defaulting missing AI feature options to disabled.
- Modify `tests/phpunit/FeatureBootstrapTest.php`: replace seeding tests with assertions that missing options do not write options and do not expose recommendation abilities.
- Modify `tests/phpunit/PluginLifecycleTest.php`: prove activation and admin bootstrap do not create or update upstream AI plugin feature options.
- Modify `scripts/wp70-e2e.js`: explicitly seed AI feature options only inside the WP 7.0 test harness when browser tests need Flavor Agent recommendation UI active.
- Modify `docs/INTEGRATION_GUIDE.md`: remove the claim that Flavor Agent seeds upstream AI feature toggles.
- Modify `inc/OpenAI/Provider.php`: make explicit direct-provider chat configuration mirror runtime configuration, including OpenAI connector preference and generic WordPress AI Client fallback, and update the inline `chat_configuration()` docblock.
- Modify `tests/phpunit/ProviderTest.php`: cover explicit direct-provider chat fallback and explicit legacy connector fail-closed behavior.
- Modify `tests/phpunit/ResponsesClientTest.php`: cover `ResponsesClient::validate_configuration(..., $provider)` for direct embedding providers with the generic WordPress AI Client runtime.
- Modify `docs/reference/provider-precedence.md`: keep the provider precedence contract synchronized with the fixed runtime behavior.
- Modify `inc/Admin/Settings/State.php`: prioritize missing Cloudflare AI Search pattern storage before missing embeddings when choosing the default-open settings section.
- Modify `tests/phpunit/SettingsTest.php`: add regression coverage for Cloudflare AI Search pattern storage focus.
- Create `docs/validation/2026-05-05-uncommitted-regression-remediation.md`: record the exact validation commands and results after implementation.

## Behavior Contract

- Missing `wpai_features_enabled` and `wpai_feature_flavor-agent_enabled` options must mean recommendation abilities are disabled unless a filter explicitly enables them.
- Flavor Agent must not create or update upstream AI plugin feature-toggle options during activation or admin bootstrap.
- Test harnesses may seed AI feature options explicitly as fixtures; production code must not.
- `Provider::chat_configuration()` and `Provider::chat_configuration( $provider )` must agree for direct embedding providers: `openai_native` prefers the OpenAI connector when it is supported, other direct embedding selections use the unpinned WordPress AI Client runtime when available, and unavailable saved legacy connector pins fail closed.
- Cloudflare AI Search pattern storage does not require the Embedding Model. If Cloudflare AI Search storage is selected and incomplete, the default-open settings section must be Patterns.

### Task 1: Stop Production AI Feature Toggle Seeding

**Files:**
- Modify: `tests/phpunit/PluginLifecycleTest.php`
- Modify: `tests/phpunit/FeatureBootstrapTest.php`
- Modify: `inc/AI/FeatureBootstrap.php`
- Modify: `flavor-agent.php`
- Modify: `scripts/wp70-e2e.js`
- Modify: `docs/INTEGRATION_GUIDE.md`
- Test: `tests/phpunit/FeatureBootstrapTest.php`
- Test: `tests/phpunit/PluginLifecycleTest.php`

- [ ] **Step 1: Add lifecycle tests for activation and admin bootstrap**

Edit `tests/phpunit/PluginLifecycleTest.php`. Add these tests after `test_plugin_bootstrap_registers_lifecycle_and_dependency_hooks()`:

```php
public function test_plugin_bootstrap_does_not_register_ai_feature_option_seeding_on_admin_init(): void {
	$this->assertHookRegistered( 'admin_init', [ \FlavorAgent\Settings::class, 'register_settings' ] );
	$this->assertHookNotRegistered( 'admin_init', [ \FlavorAgent\AI\FeatureBootstrap::class, 'seed_ai_feature_options' ] );

	do_action( 'admin_init' );

	$this->assertArrayNotHasKey( 'wpai_features_enabled', WordPressTestState::$options );
	$this->assertArrayNotHasKey( 'wpai_feature_flavor-agent_enabled', WordPressTestState::$options );
	$this->assertArrayNotHasKey( 'wpai_features_enabled', WordPressTestState::$updated_options );
	$this->assertArrayNotHasKey( 'wpai_feature_flavor-agent_enabled', WordPressTestState::$updated_options );
}
```

```php
public function test_activation_does_not_seed_ai_feature_options(): void {
	WordPressTestState::$activation_hooks[ FLAVOR_AGENT_FILE ]();

	$this->assertArrayNotHasKey( 'wpai_features_enabled', WordPressTestState::$options );
	$this->assertArrayNotHasKey( 'wpai_feature_flavor-agent_enabled', WordPressTestState::$options );
	$this->assertArrayNotHasKey( 'wpai_features_enabled', WordPressTestState::$updated_options );
	$this->assertArrayNotHasKey( 'wpai_feature_flavor-agent_enabled', WordPressTestState::$updated_options );
}
```

Add this helper at the bottom of the class, next to `assertHookRegistered()`:

```php
private function assertHookNotRegistered( string $hook_name, callable $unexpected_callback ): void {
	$callbacks = [];

	foreach ( WordPressTestState::$filters[ $hook_name ] ?? [] as $priority_callbacks ) {
		foreach ( $priority_callbacks as $entry ) {
			$callbacks[] = $entry['callback'] ?? null;
		}
	}

	$this->assertNotContains( $unexpected_callback, $callbacks );
}
```

- [ ] **Step 2: Replace feature-toggle seeding tests with missing-option and no-write tests**

Edit `tests/phpunit/FeatureBootstrapTest.php` and remove:

```php
public function test_ai_feature_option_seeding_enables_missing_feature_toggles(): void {
	FeatureBootstrap::seed_ai_feature_options();

	$this->assertSame( true, WordPressTestState::$options['wpai_features_enabled'] ?? null );
	$this->assertSame( true, WordPressTestState::$options['wpai_feature_flavor-agent_enabled'] ?? null );
}

public function test_ai_feature_option_seeding_preserves_explicit_disabled_values(): void {
	WordPressTestState::$options = [
		'wpai_features_enabled'             => false,
		'wpai_feature_flavor-agent_enabled' => false,
	];

	FeatureBootstrap::seed_ai_feature_options();

	$this->assertSame( false, WordPressTestState::$options['wpai_features_enabled'] );
	$this->assertSame( false, WordPressTestState::$options['wpai_feature_flavor-agent_enabled'] );
	$this->assertSame( [], WordPressTestState::$updated_options );
}
```

Add this test in the same location:

```php
public function test_missing_ai_feature_options_do_not_write_or_enable_recommendations(): void {
	FeatureBootstrap::register_global_ability_category();
	FeatureBootstrap::register_global_helper_abilities();

	$this->assertArrayHasKey( 'flavor-agent', WordPressTestState::$registered_ability_categories );
	$this->assertArrayHasKey( 'flavor-agent/introspect-block', WordPressTestState::$registered_abilities );
	$this->assertArrayNotHasKey( 'flavor-agent/recommend-block', WordPressTestState::$registered_abilities );
	$this->assertArrayNotHasKey( 'wpai_features_enabled', WordPressTestState::$options );
	$this->assertArrayNotHasKey( 'wpai_feature_flavor-agent_enabled', WordPressTestState::$options );
	$this->assertSame( [], WordPressTestState::$updated_options );
}
```

- [ ] **Step 3: Run the focused tests and confirm the lifecycle tests fail before implementation**

Run:

```bash
composer run test:php -- --filter 'PluginLifecycleTest|FeatureBootstrapTest'
```

Expected before implementation: FAIL because current `flavor-agent.php` registers `FeatureBootstrap::seed_ai_feature_options()` on `admin_init` and calls it from the activation hook, which writes `wpai_features_enabled` and `wpai_feature_flavor-agent_enabled`.

- [ ] **Step 4: Remove production seeding code**

Edit `inc/AI/FeatureBootstrap.php` and delete these members:

```php
private const GLOBAL_FEATURES_OPTION = 'wpai_features_enabled';

private const FEATURE_OPTION = 'wpai_feature_flavor-agent_enabled';

public static function seed_ai_feature_options(): void {
	if ( ! self::canonical_contracts_available() ) {
		return;
	}

	self::seed_missing_enabled_option( self::GLOBAL_FEATURES_OPTION );
	self::seed_missing_enabled_option( self::FEATURE_OPTION );
}

private static function seed_missing_enabled_option( string $option_name ): void {
	$missing = new \stdClass();

	if ( $missing !== \get_option( $option_name, $missing ) ) {
		return;
	}

	\update_option( $option_name, true );
}
```

Then update `recommendation_feature_enabled()` to use literal hook/option names while preserving the default-false behavior:

```php
public static function recommendation_feature_enabled(): bool {
	if ( ! self::ai_feature_contracts_available() ) {
		return false;
	}

	$features_enabled = (bool) \apply_filters(
		'wpai_features_enabled',
		self::enabled_option( 'wpai_features_enabled', false )
	);

	if ( ! $features_enabled ) {
		return false;
	}

	return (bool) \apply_filters(
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- AI plugin feature filters include the hyphenated feature ID.
		'wpai_feature_flavor-agent_enabled',
		self::enabled_option( 'wpai_feature_flavor-agent_enabled', false )
	);
}
```

Edit `flavor-agent.php` and remove both calls:

```php
add_action( 'admin_init', [ FlavorAgent\AI\FeatureBootstrap::class, 'seed_ai_feature_options' ], 1 );
```

```php
FlavorAgent\AI\FeatureBootstrap::seed_ai_feature_options();
```

- [ ] **Step 5: Keep WP 7.0 browser harness fixtures explicit**

Edit `scripts/wp70-e2e.js`. In `seedFlavorAgentOptions()`, add the two AI feature options to the existing `optionValues` object so tests that need recommendation UI active declare that fixture locally:

```js
const optionValues = {
	wpai_features_enabled: '1',
	'wpai_feature_flavor-agent_enabled': '1',
	flavor_agent_openai_provider: 'azure_openai',
	// keep the existing Flavor Agent option fixtures below this line
};
```

Use the exact surrounding object already in the file. Do not add production PHP hooks to compensate for the removed seeding.

- [ ] **Step 6: Update integration docs**

Edit `docs/INTEGRATION_GUIDE.md` and replace this bullet:

```markdown
- Flavor Agent seeds those two feature toggles to enabled on activation and early admin bootstrap when the canonical AI contracts are active and the options are still missing; explicit disabled values are preserved.
```

with:

```markdown
- Flavor Agent reads those feature toggles and defaults missing values to disabled. The upstream AI plugin settings, host filters, or test fixtures must explicitly enable recommendation feature execution.
```

- [ ] **Step 7: Verify Task 1**

Run:

```bash
composer run test:php -- --filter PluginLifecycleTest
composer run test:php -- --filter FeatureBootstrapTest
npm run check:docs
git diff --check
```

Expected: all commands pass. `PluginLifecycleTest` should prove production lifecycle hooks do not create AI feature options. `FeatureBootstrapTest` should still prove helper abilities register with missing feature options and recommendation abilities do not.

### Task 2: Align Explicit Provider Chat Configuration With Runtime Behavior

**Files:**
- Modify: `tests/phpunit/ProviderTest.php`
- Modify: `tests/phpunit/ResponsesClientTest.php`
- Modify: `inc/OpenAI/Provider.php`
- Modify: `docs/reference/provider-precedence.md`
- Test: `tests/phpunit/ProviderTest.php`
- Test: `tests/phpunit/ResponsesClientTest.php`

- [ ] **Step 1: Add explicit-provider fallback tests**

In `tests/phpunit/ProviderTest.php`, add these tests near the existing chat configuration tests:

```php
public function test_explicit_azure_chat_configuration_uses_generic_wordpress_ai_client_when_supported(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME => Provider::AZURE,
	];
	WordPressTestState::$ai_client_supported = true;

	$config = Provider::chat_configuration( Provider::AZURE );

	$this->assertSame( 'wordpress_ai_client', $config['provider'] );
	$this->assertSame( 'WordPress AI Client', $config['label'] );
	$this->assertSame( 'provider-managed', $config['model'] );
	$this->assertTrue( $config['configured'] );
}
```

```php
public function test_explicit_openai_native_chat_configuration_falls_back_to_generic_client_when_openai_connector_is_not_supported(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME => Provider::NATIVE,
	];
	WordPressTestState::$connectors = [
		'openai' => [
			'name'           => 'OpenAI',
			'description'    => 'OpenAI connector',
			'type'           => 'ai_provider',
			'authentication' => [
				'method'       => 'api_key',
				'setting_name' => 'connectors_ai_openai_api_key',
			],
		],
	];
	WordPressTestState::$ai_client_supported        = true;
	WordPressTestState::$ai_client_provider_support = [
		'openai' => false,
	];

	$config = Provider::chat_configuration( Provider::NATIVE );

	$this->assertSame( 'wordpress_ai_client', $config['provider'] );
	$this->assertTrue( $config['configured'] );
}
```

Add this explicit-provider legacy pin regression test in the same file:

```php
public function test_explicit_legacy_connector_chat_configuration_does_not_fall_back_when_connector_is_unsupported(): void {
	WordPressTestState::$options                    = [
		Provider::OPTION_NAME => 'anthropic',
	];
	WordPressTestState::$connectors                 = [
		'anthropic' => [
			'name'           => 'Anthropic',
			'type'           => 'ai_provider',
			'authentication' => [
				'setting_name' => 'connectors_ai_anthropic_api_key',
			],
		],
	];
	WordPressTestState::$ai_client_supported        = true;
	WordPressTestState::$ai_client_provider_support = [
		'anthropic' => false,
	];

	$config = Provider::chat_configuration( 'anthropic' );

	$this->assertSame( 'anthropic', $config['provider'] );
	$this->assertSame( '', $config['model'] );
	$this->assertFalse( $config['configured'] );
}
```

This is separate from the existing no-argument runtime test because `Provider::chat_configuration( 'anthropic' )` exercises the explicit-provider branch used by compatibility validation paths.

- [ ] **Step 2: Add validate-configuration coverage for explicit direct providers**

In `tests/phpunit/ResponsesClientTest.php`, add this test near `test_validate_configuration_with_explicit_provider_routes_to_that_provider_config()`:

```php
public function test_validate_configuration_with_explicit_direct_provider_uses_generic_wordpress_ai_client(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME => Provider::AZURE,
	];
	WordPressTestState::$ai_client_supported = true;

	$this->assertTrue(
		ResponsesClient::validate_configuration(
			null,
			null,
			null,
			Provider::AZURE
		)
	);
}
```

- [ ] **Step 3: Run focused tests and confirm failure**

Run:

```bash
composer run test:php -- --filter 'ProviderTest|ResponsesClientTest'
```

Expected before implementation: FAIL on at least the explicit Azure provider assertion because `Provider::chat_configuration( Provider::AZURE )` currently returns missing Azure chat config instead of the generic WordPress AI Client runtime.

- [ ] **Step 4: Update explicit provider chat configuration and docblock**

Edit `inc/OpenAI/Provider.php`. First update the `chat_configuration()` docblock above the method to describe the new fallback contract:

```php
/**
 * Chat is owned by Settings > Connectors via the WordPress AI Client. Flavor
 * Agent pins saved connector selections and OpenAI Native to matching
 * connectors when supported, and otherwise lets direct embedding selections use
 * the configured generic WordPress AI Client runtime. Saved legacy connector
 * pins fail closed when their connector is unavailable.
 *
 * @param array<string, string> $overrides Reserved for parity with embedding_configuration().
 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
 */
```

Then replace the body of `chat_configuration()` after `$provider = self::normalize_provider( $provider );` with logic that mirrors `runtime_chat_configuration()`:

```php
$connector_provider = self::selected_chat_connector( $provider );

if (
	'' !== $connector_provider &&
	self::is_connector( $connector_provider ) &&
	WordPressAIClient::is_supported( $connector_provider )
) {
	return self::connector_chat_configuration( $connector_provider );
}

if ( self::is_saved_legacy_connector_pin( $provider ) ) {
	return self::missing_chat_configuration( $provider );
}

if ( ! self::is_connector( $provider ) && WordPressAIClient::is_supported() ) {
	return self::wordpress_ai_client_configuration();
}

return self::missing_chat_configuration( $provider );
```

This intentionally removes the Workers-AI-only special case because the generic direct-provider branch now covers Workers AI, Azure OpenAI, and OpenAI Native. Keep `selected_chat_connector()` unchanged so `openai_native` still prefers the OpenAI connector when it is registered and supported.

- [ ] **Step 5: Keep provider precedence docs in sync**

Edit `docs/reference/provider-precedence.md` and keep these statements:

```markdown
4. Otherwise, direct embedding selections delegate chat to the configured WordPress AI Client runtime without pinning a provider.
```

```markdown
`Provider::chat_configuration( $provider )` reports direct embedding selections as the unpinned WordPress AI Client chat runtime when that runtime is available. Legacy connector selections still resolve only to their matching connector.
```

If implementation wording changed, update only the necessary sentences. Do not reintroduce claims that unselected connector providers can handle saved legacy connector pins.

- [ ] **Step 6: Verify Task 2**

Run:

```bash
composer run test:php -- --filter 'ProviderTest|ResponsesClientTest|AzureBackendValidationTest|PatternAbilitiesTest|InfraAbilitiesTest'
npm run check:docs
git diff --check
```

Expected: all commands pass. Explicit provider validation should now match the documented runtime precedence.

### Task 3: Focus Cloudflare AI Search Pattern Storage Before Embeddings

**Files:**
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `inc/Admin/Settings/State.php`
- Test: `tests/phpunit/SettingsTest.php`

- [ ] **Step 1: Add a default-open regression test**

In `tests/phpunit/SettingsTest.php`, add this test near the other `determine_default_open_group` tests:

```php
public function test_determine_default_open_group_prioritizes_cloudflare_pattern_storage_when_selected_and_missing(): void {
	$state                                      = $this->build_default_open_group_state();
	$state['runtime_chat']                      = [ 'configured' => true ];
	$state['runtime_embedding']                 = [ 'configured' => false ];
	$state['selected_pattern_backend']          = Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH;
	$state['cloudflare_pattern_ai_search_configured'] = false;
	$state['patterns_ready']                    = false;
	$state['qdrant_configured']                 = false;

	$this->assertSame(
		Config::GROUP_PATTERNS,
		State::determine_default_open_group( $state )
	);
}
```

Also add this companion test so the Embedding Model section still opens for Qdrant when both embeddings and Qdrant are incomplete:

```php
public function test_determine_default_open_group_prioritizes_embedding_model_for_qdrant_when_embeddings_are_missing(): void {
	$state                             = $this->build_default_open_group_state();
	$state['runtime_chat']             = [ 'configured' => true ];
	$state['runtime_embedding']        = [ 'configured' => false ];
	$state['selected_pattern_backend'] = Config::PATTERN_BACKEND_QDRANT;
	$state['patterns_ready']           = false;
	$state['qdrant_configured']        = false;

	$this->assertSame(
		Config::GROUP_EMBEDDINGS,
		State::determine_default_open_group( $state )
	);
}
```

- [ ] **Step 2: Run the focused test and confirm failure**

Run:

```bash
composer run test:php -- --filter 'test_determine_default_open_group_prioritizes_cloudflare_pattern_storage_when_selected_and_missing|test_determine_default_open_group_prioritizes_embedding_model_for_qdrant_when_embeddings_are_missing'
```

Expected before implementation: the Cloudflare AI Search test fails because the current default-open logic returns `embeddings`.

- [ ] **Step 3: Add a targeted pattern-storage priority helper**

Edit `inc/Admin/Settings/State.php`. In `determine_default_open_group()`, add a Cloudflare pattern-storage priority check after chat readiness and before embedding readiness:

```php
if ( self::cloudflare_pattern_storage_needs_setup( $state ) ) {
	return Config::GROUP_PATTERNS;
}

if ( empty( $state['runtime_embedding']['configured'] ) ) {
	return Config::GROUP_EMBEDDINGS;
}
```

Add this private helper near `pattern_backends_partially_configured()`:

```php
private static function cloudflare_pattern_storage_needs_setup( array $state ): bool {
	if ( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH !== (string) ( $state['selected_pattern_backend'] ?? '' ) ) {
		return false;
	}

	return empty( $state['cloudflare_pattern_ai_search_configured'] );
}
```

Keep `pattern_backends_partially_configured()` intact unless the new tests expose overlapping behavior. Qdrant should continue to open Embedding Model first when embeddings are missing.

- [ ] **Step 4: Verify Task 3**

Run:

```bash
composer run test:php -- --filter SettingsTest
git diff --check
```

Expected: `SettingsTest` passes and the Cloudflare AI Search missing-storage state resolves to `Config::GROUP_PATTERNS`.

### Task 4: Final Verification And Release Evidence

**Files:**
- Verify: all changed files
- Create: `docs/validation/2026-05-05-uncommitted-regression-remediation.md`

- [ ] **Step 1: Run focused PHP suites**

Run:

```bash
composer run test:php -- --filter 'PluginLifecycleTest|FeatureBootstrapTest|ProviderTest|ResponsesClientTest|SettingsTest|AzureBackendValidationTest|PatternAbilitiesTest|InfraAbilitiesTest'
```

Expected: PASS. These suites cover all three findings plus adjacent chat/pattern/settings behavior.

- [ ] **Step 2: Run full PHP unit tests**

Run:

```bash
composer run test:php
```

Expected: PASS.

- [ ] **Step 3: Run full JS unit tests**

Run:

```bash
npm run test:unit -- --runInBand
```

Expected: PASS. This catches any accidental admin-controller or capability-copy drift.

- [ ] **Step 4: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 5: Run the fast aggregate verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: PASS or a clearly identified environment-only blocker. If `lint-plugin` fails because Plugin Check prerequisites or local DB env are unavailable, inspect `output/verify/summary.json` and follow `docs/reference/local-environment-setup.md` before waiving it.

- [ ] **Step 6: Record validation evidence**

Create `docs/validation/2026-05-05-uncommitted-regression-remediation.md` with this table:

```markdown
# Uncommitted Regression Remediation Validation

Date: 2026-05-05

| Command | Result | Notes |
| --- | --- | --- |
| `composer run test:php -- --filter 'PluginLifecycleTest|FeatureBootstrapTest|ProviderTest|ResponsesClientTest|SettingsTest|AzureBackendValidationTest|PatternAbilitiesTest|InfraAbilitiesTest'` | PASS | Focused coverage for lifecycle feature-toggle writes, provider precedence, settings focus, and adjacent pattern/chat contracts. |
| `composer run test:php` | PASS | Full PHP suite. |
| `npm run test:unit -- --runInBand` | PASS | Full JS unit suite. |
| `npm run check:docs` | PASS | Docs freshness gate. |
| `npm run verify -- --skip-e2e` | PASS | Fast aggregate verifier. |
| `git diff --check` | PASS | Final whitespace gate after validation doc creation. |
```

Only write `PASS` after each command has actually passed in the current workspace.

- [ ] **Step 7: Run the final whitespace check after validation doc creation**

Run:

```bash
git diff --check
```

Expected: PASS. This final run must happen after creating `docs/validation/2026-05-05-uncommitted-regression-remediation.md` so the whitespace evidence includes that new file.

## Acceptance Criteria

- `flavor-agent.php` no longer seeds `wpai_features_enabled` or `wpai_feature_flavor-agent_enabled`.
- `FeatureBootstrap::recommendation_feature_enabled()` still defaults missing feature options to disabled and still honors force-enable/force-disable filters.
- WP 7.0 and PHPUnit fixtures that need recommendation abilities explicitly set the AI feature options.
- `Provider::chat_configuration( Provider::AZURE )` and `Provider::chat_configuration( Provider::NATIVE )` return the generic WordPress AI Client runtime when no supported pinned connector exists and the generic runtime is supported.
- Saved legacy connector pins still fail closed when unavailable.
- `ResponsesClient::validate_configuration( null, null, null, Provider::AZURE )` succeeds when the generic WordPress AI Client runtime is supported.
- `docs/reference/provider-precedence.md` and the `Provider::chat_configuration()` inline docblock describe the same direct-provider behavior implemented in `inc/OpenAI/Provider.php`.
- `State::determine_default_open_group()` opens Patterns when Cloudflare AI Search pattern storage is selected and incomplete, even if Embedding Model is also incomplete.
- Qdrant setup still opens Embedding Model first when Qdrant is selected and embeddings are missing.
- Focused tests, full PHP tests, full JS tests, docs checks, and the fast aggregate verifier have current evidence.

## Commit Plan

Use short imperative commits if the user asks to commit:

```bash
git add flavor-agent.php inc/AI/FeatureBootstrap.php tests/phpunit/FeatureBootstrapTest.php tests/phpunit/PluginLifecycleTest.php scripts/wp70-e2e.js docs/INTEGRATION_GUIDE.md
git commit -m "Stop seeding AI feature toggles"
```

```bash
git add inc/OpenAI/Provider.php tests/phpunit/ProviderTest.php tests/phpunit/ResponsesClientTest.php docs/reference/provider-precedence.md
git commit -m "Align explicit chat provider precedence"
```

```bash
git add inc/Admin/Settings/State.php tests/phpunit/SettingsTest.php
git commit -m "Focus Cloudflare pattern storage setup"
```

```bash
git add docs/validation/2026-05-05-uncommitted-regression-remediation.md
git commit -m "Record regression remediation validation"
```

Skip the validation-doc commit if no validation doc is created.
