# Admin Dashboard Settings Completion Implementation Plan

> **Archive note (2026-05-05):** This plan records the pre-cleanup implementation approach and contains historical examples for Azure-named sanitizer APIs that have since been removed or replaced by neutral settings such as `flavor_agent_reasoning_effort`. Use `docs/features/settings-backends-and-sync.md` and `docs/reference/provider-precedence.md` for the current contract.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the admin dashboard and settings migration so the UI, settings saves, runtime compatibility, copied assets, tests, and docs all match the Connectors-owned chat plus Flavor-Agent-owned embeddings model.

**Architecture:** Keep `Settings > Flavor Agent` as an operator surface for readiness, direct embedding setup, pattern storage, Developer Docs diagnostics, guidelines, and experimental toggles. Preserve legacy connector pins only as an explicit migration state; do not let an ordinary settings save silently clear inactive embedding credentials or legacy chat routing. Treat generated `build/` assets as release artifacts that must be refreshed after source JS changes.

**Tech Stack:** WordPress Settings API, PHP 8.0-compatible plugin code under `inc/`, PHPUnit tests under `tests/phpunit/`, `@wordpress/scripts` unit/build tooling, Playwright admin settings coverage.

---

## Current Findings To Close

1. Inactive direct embedding provider fields can be cleared because the page renders only the selected provider fields while the Settings API still updates every registered option in the group.
2. A saved legacy connector value for `flavor_agent_openai_provider` cannot be represented by the current Embedding Provider select, so a normal save can silently overwrite the pin with the first direct provider choice.
3. `Reasoning Effort` is a Connectors-routed chat option, but it is still rendered inside the Azure embeddings subsection.
4. `src/admin/settings-page-controller.js` has the new `Needs setup` string, but `build/admin.js` still ships `Needs embeddings & Qdrant`.
5. `SettingsTest` and the settings Playwright spec still contain old copy, section numbers, and grouping expectations.
6. Some admin-facing unavailable messages still say "pattern backends", "compatible embedding backend and Qdrant", or "Docs grounding" where the new surface should say `Pattern Storage`, `Embedding Model`, and `Developer Docs`.

## Files And Responsibilities

- Modify `inc/Admin/Settings/Validation.php`: preserve unposted provider-scoped options, keep intentionally posted blank values clearable, and avoid false changed-section feedback for options that were not in the submitted form.
- Modify `inc/OpenAI/Provider.php`: preserve saved legacy connector pins even when their connector is no longer registered, expose a settings-select choice list that includes only direct embedding providers plus the currently saved legacy connector sentinel when needed, and keep runtime chat fail-closed for unavailable pins.
- Modify `inc/Admin/Settings/Registrar.php`: use the new settings-select choice list and stop registering `Reasoning Effort` as a visible settings field.
- Modify `inc/Admin/Settings/Page.php`: render an explicit legacy connector migration notice, remove `Reasoning Effort` from Azure embeddings, and keep the AI Model section read-only.
- Modify `inc/Admin/Settings/State.php`, `inc/Admin/Settings/Feedback.php`, `inc/Abilities/SurfaceCapabilities.php`, and `inc/Abilities/PatternAbilities.php`: align user-facing messages with `AI Model`, `Embedding Model`, `Pattern Storage`, and `Developer Docs`.
- Modify `src/admin/settings-page-controller.js`: keep the already-correct source label and only adjust it if tests expose adjacent stale copy.
- Modify `src/admin/settings.css`, `src/admin/brand.css`, and related settings-page markup only as needed for final UI polish; do not hand-edit generated `build/` CSS or JS.
- Modify `src/patterns/__tests__/PatternRecommender.test.js` and related JS tests if capability copy changes.
- Modify `tests/phpunit/SettingsTest.php`, `tests/phpunit/SettingsRegistrarTest.php`, `tests/e2e/flavor-agent.settings.spec.js`, and any focused provider/runtime tests needed for regression coverage.
- Modify docs that describe settings ownership: `docs/SOURCE_OF_TRUTH.md`, `docs/features/settings-backends-and-sync.md`, `docs/features/pattern-recommendations.md`, `docs/reference/provider-precedence.md`, `docs/reference/local-environment-setup.md`, `readme.txt`, and `docs/flavor-agent-readme.md`.
- Regenerate `build/admin.js` and `build/admin.asset.php` with `npm run build`; do not hand-edit generated files.

## Behavior Contract

- Saving the settings form updates only controls that were rendered and posted, plus the selected provider/storage values.
- If a field is rendered and the user submits an empty value, the empty value is honored unless existing secret-preservation rules intentionally keep a blank password field.
- If a provider field is not posted because its provider subsection was not rendered, its previous option value is preserved and no changed-section success is recorded for that field.
- A saved legacy connector pin remains visible as a legacy migration state even if the connector is no longer registered. It is preserved on save until the admin explicitly chooses a direct embedding provider.
- The Embedding Provider select must not list arbitrary available connectors as new choices. It may list only the saved legacy connector sentinel plus direct embedding providers.
- The AI Model section stays read-only: it reports current Connectors readiness and links to `Settings > Connectors`.
- `Reasoning Effort` remains sanitized and honored by runtime compatibility code for older saved options, but it is no longer rendered in the Embedding Model section.
- Pattern setup copy treats Qdrant and Cloudflare AI Search as Pattern Storage, not second model choices.
- Developer Docs copy treats the built-in public endpoint as the default and shows legacy override fields only when saved legacy values exist.

## UI Polish Contract

- Keep the settings page visually consistent with the existing Flavor Agent admin language in `src/admin/settings.css` and `src/admin/brand.css`: warm surfaces, rounded cards, restrained shadows, compact form tables, and clear section badges.
- Preserve WordPress admin affordances. Use native `button`, `notice`, `form-table`, `details`, `summary`, `description`, and Settings API markup unless a small wrapper is needed for layout.
- Make the first scan useful. The hero, setup status cards, section badges, and default-open section should clearly answer what is ready, what needs setup, and where the admin should go next.
- Treat legacy connector pins and legacy Developer Docs override fields as migration states, not primary setup paths. They should be visible and specific when present, but visually secondary to direct embedding setup and Connectors-owned AI Model readiness.
- Avoid dense walls of controls. Use subsection headings, short descriptions, and advanced panels for diagnostics, ranking, sync details, and legacy override fields.
- Keep warning and error states actionable. Every warning/error notice should name the surface (`AI Model`, `Embedding Model`, `Pattern Storage`, or `Developer Docs`) and identify the next action or destination.
- Ensure keyboard and screen-reader behavior remains native: `details` sections must be operable by keyboard, fields keep labels tied with `label_for`, status text should not rely on color alone, and dynamic status updates should keep existing ARIA behavior.
- Validate responsive behavior at narrow admin widths. Cards should wrap cleanly, form controls should not overflow, long provider/model labels should wrap, and action buttons should remain reachable without horizontal scrolling.
- Keep motion subtle and nonessential. Existing entrance/status animations may remain, but do not add motion that hides state changes or blocks interaction.
- Keep copy short in the UI and move deep operational guidance to Help tabs or docs. Inline descriptions should usually be one sentence.

### Task 1: Add Regression Tests For Settings Save Preservation

**Files:**

- Modify: `tests/phpunit/SettingsTest.php`
- Test: `tests/phpunit/SettingsTest.php`

- [ ] **Step 1: Add a failing test for unposted inactive direct provider values**

Add this test near the existing Azure/OpenAI Native/Workers AI sanitizer tests:

```php
public function test_unposted_inactive_embedding_provider_values_are_preserved_when_openai_native_is_selected(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME                                  => 'openai_native',
		'flavor_agent_azure_openai_endpoint'                   => 'https://old.openai.azure.com/',
		'flavor_agent_azure_openai_key'                        => 'old-azure-key',
		'flavor_agent_azure_embedding_deployment'              => 'old-embed',
		'flavor_agent_azure_reasoning_effort'                  => 'xhigh',
		'flavor_agent_cloudflare_workers_ai_account_id'        => 'workers-account',
		'flavor_agent_cloudflare_workers_ai_api_token'         => 'workers-token',
		'flavor_agent_cloudflare_workers_ai_embedding_model'   => '@cf/baai/bge-large-en-v1.5',
		'flavor_agent_openai_native_api_key'                   => 'native-key',
		'flavor_agent_openai_native_embedding_model'           => 'text-embedding-3-large',
	];
	$_POST                       = [
		'option_page'                                => Config::OPTION_GROUP,
		Provider::OPTION_NAME                        => 'openai_native',
		'flavor_agent_openai_native_api_key'         => '',
		'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
	];

	$this->assertSame( 'openai_native', Settings::sanitize_openai_provider( 'openai_native' ) );
	$this->assertSame( 'https://old.openai.azure.com/', Settings::sanitize_azure_openai_endpoint( null ) );
	$this->assertSame( 'old-azure-key', Settings::sanitize_azure_openai_key( null ) );
	$this->assertSame( 'old-embed', Settings::sanitize_azure_embedding_deployment( null ) );
	$this->assertSame( 'xhigh', Settings::sanitize_azure_reasoning_effort( null ) );
	$this->assertSame( 'workers-account', Settings::sanitize_cloudflare_workers_ai_account_id( null ) );
	$this->assertSame( 'workers-token', Settings::sanitize_cloudflare_workers_ai_api_token( null ) );
	$this->assertSame( '@cf/baai/bge-large-en-v1.5', Settings::sanitize_cloudflare_workers_ai_embedding_model( null ) );
	$this->assertSame( 'native-key', Settings::sanitize_openai_native_api_key( '' ) );
	$this->assertSame( 'text-embedding-3-large', Settings::sanitize_openai_native_embedding_model( 'text-embedding-3-large' ) );
	$this->assertSame( [], WordPressTestState::$remote_post_calls );
}
```

- [ ] **Step 2: Add a failing test that posted blanks still clear rendered non-secret fields**

Add this test beside the previous one:

```php
public function test_posted_blank_embedding_model_field_can_clear_the_rendered_provider_value(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME                        => 'openai_native',
		'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
	];
	$_POST                       = [
		'option_page'                                => Config::OPTION_GROUP,
		Provider::OPTION_NAME                        => 'openai_native',
		'flavor_agent_openai_native_api_key'         => '',
		'flavor_agent_openai_native_embedding_model' => '',
	];

	$this->assertSame( '', Settings::sanitize_openai_native_embedding_model( '' ) );
}
```

- [ ] **Step 3: Add a regression test for direct sanitizer calls outside a settings POST**

Add this test beside the other reasoning-effort sanitizer coverage:

```php
public function test_direct_reasoning_effort_sanitizer_call_still_sanitizes_supplied_value_without_settings_post(): void {
	WordPressTestState::$options = [
		'flavor_agent_azure_reasoning_effort' => 'medium',
	];
	$_POST                       = [];

	$this->assertSame( 'xhigh', Settings::sanitize_azure_reasoning_effort( 'xhigh' ) );
	$this->assertSame( 'medium', Settings::sanitize_azure_reasoning_effort( 'invalid' ) );
}
```

- [ ] **Step 4: Run the focused tests and confirm the new preservation test fails**

Run:

```bash
vendor/bin/phpunit --filter 'test_unposted_inactive_embedding_provider_values_are_preserved|test_posted_blank_embedding_model_field_can_clear|test_direct_reasoning_effort_sanitizer_call_still_sanitizes'
```

Expected before implementation: the unposted Azure endpoint, Azure deployment, Workers AI account, Workers AI model, or reasoning effort assertion fails because missing values are converted to empty/default values. The direct sanitizer test should pass before and after implementation; it protects existing non-form sanitizer behavior.

### Task 2: Preserve Unposted Provider-Scoped Settings

**Files:**

- Modify: `inc/Admin/Settings/Validation.php`
- Test: `tests/phpunit/SettingsTest.php`

- [ ] **Step 1: Add a provider-preservation option map**

Add a class constant near the existing validation constants:

```php
private const UNPOSTED_PROVIDER_OPTION_DEFAULTS = [
	'flavor_agent_azure_openai_endpoint'                 => '',
	'flavor_agent_azure_openai_key'                      => '',
	'flavor_agent_azure_embedding_deployment'            => '',
	'flavor_agent_azure_reasoning_effort'                => 'medium',
	'flavor_agent_openai_native_api_key'                 => '',
	'flavor_agent_openai_native_embedding_model'         => '',
	'flavor_agent_cloudflare_workers_ai_account_id'      => '',
	'flavor_agent_cloudflare_workers_ai_api_token'       => '',
	'flavor_agent_cloudflare_workers_ai_embedding_model' => WorkersAIEmbeddingConfiguration::DEFAULT_MODEL,
];
```

If `Validation.php` does not already import `FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration`, add the `use` statement at the top.

- [ ] **Step 2: Add a helper that preserves only absent fields**

Add this helper near the other POST helpers:

```php
private static function preserve_unposted_provider_option_value( string $option_name ): ?string {
	if ( ! self::should_validate_submission() ) {
		return null;
	}

	if ( self::has_posted_option( $option_name ) ) {
		return null;
	}

	if ( ! array_key_exists( $option_name, self::UNPOSTED_PROVIDER_OPTION_DEFAULTS ) ) {
		return null;
	}

	return sanitize_text_field(
		(string) get_option(
			$option_name,
			self::UNPOSTED_PROVIDER_OPTION_DEFAULTS[ $option_name ]
		)
	);
}
```

- `should_validate_submission()` must be the first guard. These sanitizers are also called directly by tests and helper code outside a Settings API POST; those direct calls must continue to sanitize the supplied value instead of returning the current saved option.

- [ ] **Step 3: Use the helper before normal sanitization**

At the top of each provider sanitizer path, return the preserved value before recording feedback or validating remote credentials:

```php
private static function sanitize_azure_url_option( mixed $value, string $option_name ): string {
	$preserved_value = self::preserve_unposted_provider_option_value( $option_name );
	if ( null !== $preserved_value ) {
		return $preserved_value;
	}

	$sanitized_value = Utils::sanitize_url_value( $value );
	Feedback::mark_section_changed_by_option( $option_name, $sanitized_value );
	// Existing validation flow remains below.
}
```

Apply the same pattern to:

```php
private static function sanitize_azure_text_option( mixed $value, string $option_name ): string
private static function sanitize_openai_native_text_option( mixed $value, string $option_name ): string
private static function sanitize_workers_ai_text_option( mixed $value, string $option_name ): string
public static function sanitize_azure_reasoning_effort( mixed $value ): string
```

For `sanitize_azure_reasoning_effort()`, the first lines should become:

```php
$preserved_value = self::preserve_unposted_provider_option_value( 'flavor_agent_azure_reasoning_effort' );
if ( null !== $preserved_value ) {
	return in_array( $preserved_value, [ 'low', 'medium', 'high', 'xhigh' ], true ) ? $preserved_value : 'medium';
}
```

- [ ] **Step 4: Run the preservation tests**

Run:

```bash
vendor/bin/phpunit --filter 'test_unposted_inactive_embedding_provider_values_are_preserved|test_posted_blank_embedding_model_field_can_clear|test_direct_reasoning_effort_sanitizer_call_still_sanitizes'
```

Expected after implementation: all three tests pass, and `WordPressTestState::$remote_post_calls` remains empty for unposted inactive provider values.

### Task 3: Make Legacy Connector Pins Explicit And Non-Silent

**Files:**

- Modify: `inc/OpenAI/Provider.php`
- Modify: `inc/Admin/Settings/Registrar.php`
- Modify: `inc/Admin/Settings/Page.php`
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `tests/phpunit/ProviderTest.php`
- Test: `tests/phpunit/SettingsTest.php`
- Test: `tests/phpunit/ProviderTest.php`

- [ ] **Step 1: Add a failing page-render test for a saved legacy connector pin**

Replace or extend `test_render_page_keeps_direct_provider_controls_available_for_connector_provider()` with these assertions:

```php
public function test_render_page_preserves_saved_legacy_connector_pin_as_migration_state(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME => 'anthropic',
	];
	WordPressTestState::$connectors = [
		'anthropic' => [
			'name'           => 'Anthropic',
			'description'    => 'Anthropic connector',
			'type'           => 'ai_provider',
			'authentication' => [
				'method'       => 'api_key',
				'setting_name' => 'connectors_ai_anthropic_api_key',
			],
		],
	];
	WordPressTestState::$ai_client_provider_support = [
		'anthropic' => true,
	];

	ob_start();
	Settings::render_page();
	$output = (string) ob_get_clean();

	$this->assertStringContainsString( 'Legacy connector pin: Anthropic', $output );
	$this->assertStringContainsString( 'value="anthropic" selected=', $output );
	$this->assertStringContainsString( 'Choose Azure OpenAI, OpenAI Native, or Cloudflare Workers AI to migrate Embedding Provider to a direct embedding backend.', $output );
	$this->assertStringContainsString( 'name="flavor_agent_azure_embedding_deployment"', $output );
	$this->assertStringContainsString( 'name="flavor_agent_openai_native_embedding_model"', $output );
	$this->assertStringContainsString( 'name="flavor_agent_cloudflare_workers_ai_embedding_model"', $output );
}
```

- [ ] **Step 2: Add a failing page-render test for an unregistered saved legacy connector pin**

Add this test beside the registered connector migration-state test:

```php
public function test_render_page_preserves_unregistered_legacy_connector_pin_as_unavailable_migration_state(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME => 'anthropic',
	];
	WordPressTestState::$connectors                 = [];
	WordPressTestState::$ai_client_provider_support = [];

	ob_start();
	Settings::render_page();
	$output = (string) ob_get_clean();

	$this->assertStringContainsString( 'Legacy connector pin: anthropic', $output );
	$this->assertStringContainsString( 'value="anthropic" selected=', $output );
	$this->assertStringContainsString( 'saved legacy connector is not currently registered', $output );
	$this->assertStringContainsString( 'Choose Azure OpenAI, OpenAI Native, or Cloudflare Workers AI to migrate Embedding Provider to a direct embedding backend.', $output );
}
```

- [ ] **Step 3: Add a failing runtime test for an unregistered saved legacy connector pin**

Add this test to `tests/phpunit/ProviderTest.php` beside the existing connector fail-closed tests:

```php
public function test_unregistered_saved_connector_pin_does_not_fall_back_to_generic_wordpress_ai_client(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME => 'anthropic',
	];
	WordPressTestState::$connectors                 = [];
	WordPressTestState::$ai_client_supported         = true;
	WordPressTestState::$ai_client_provider_support = [];

	$config = Provider::chat_configuration();

	$this->assertSame( 'anthropic', Provider::get() );
	$this->assertSame( 'anthropic', $config['provider'] );
	$this->assertFalse( $config['configured'] );
	$this->assertSame( '', $config['endpoint'] );
	$this->assertSame( '', $config['model'] );
}
```

- [ ] **Step 4: Add Provider helpers for saved legacy pins**

In `inc/OpenAI/Provider.php`, add these helpers near `direct_choices()` and `choices()`:

```php
private static function raw_saved_provider(): string {
	return sanitize_key( (string) get_option( self::OPTION_NAME, self::AZURE ) );
}

public static function is_saved_legacy_connector_pin( ?string $provider = null ): bool {
	$provider = sanitize_key( (string) ( $provider ?? self::raw_saved_provider() ) );

	if ( '' === $provider || isset( self::direct_choices()[ $provider ] ) ) {
		return false;
	}

	return $provider === self::raw_saved_provider();
}

public static function is_connector_or_saved_legacy_pin( ?string $provider = null ): bool {
	$provider = sanitize_key( (string) ( $provider ?? self::get() ) );

	return self::is_connector( $provider ) || self::is_saved_legacy_connector_pin( $provider );
}

public static function legacy_connector_pin_label( string $provider ): string {
	$provider          = sanitize_key( $provider );
	$connector_choices = self::registered_connector_choices();

	if ( isset( $connector_choices[ $provider ] ) ) {
		return $connector_choices[ $provider ];
	}

	return '' !== $provider ? $provider : self::direct_choices()[ self::AZURE ];
}
```

These helpers deliberately preserve only the currently saved non-direct provider ID. A newly submitted arbitrary unknown value still normalizes to Azure unless it matches the saved legacy value.

- [ ] **Step 5: Preserve saved legacy IDs in provider normalization**

Update `Provider::get()`:

```php
public static function get(): string {
	$provider = self::raw_saved_provider();

	if ( isset( self::all_choices()[ $provider ] ) || self::is_saved_legacy_connector_pin( $provider ) ) {
		return $provider;
	}

	return self::AZURE;
}
```

Update `Provider::normalize_provider()`:

```php
public static function normalize_provider( string $provider ): string {
	$provider = sanitize_key( $provider );

	if ( isset( self::all_choices()[ $provider ] ) || self::is_saved_legacy_connector_pin( $provider ) ) {
		return $provider;
	}

	return self::AZURE;
}
```

Update `Provider::label()` so unregistered saved legacy pins do not display as Azure:

```php
public static function label( ?string $provider = null ): string {
	$provider = sanitize_key( (string) ( $provider ?? self::get() ) );
	$choices  = self::all_choices();

	if ( isset( $choices[ $provider ] ) ) {
		return $choices[ $provider ];
	}

	if ( self::is_saved_legacy_connector_pin( $provider ) ) {
		return self::legacy_connector_pin_label( $provider );
	}

	return self::direct_choices()[ self::AZURE ];
}
```

Update connector checks that mean "legacy chat pin" rather than "currently registered connector":

```php
Provider::is_connector_or_saved_legacy_pin( $provider )
```

Use that helper in `Page::render_embedding_model_group()`, `State::get_section_status_blocks()` where it reports unavailable saved connector pins, `Provider::embedding_configuration()`, `Provider::runtime_chat_configuration()`, `Provider::selected_chat_connector()`, `Provider::chat_provider_matches_selection()`, `Provider::connector_meta_for_request_meta()`, and `Validation::should_validate_direct_provider_submission()`.

In `Provider::embedding_configuration()`, the legacy-pin branch must run before the Azure fallback:

```php
if ( self::is_connector_or_saved_legacy_pin( $provider ) ) {
	return [
		'provider'   => $provider,
		'endpoint'   => '',
		'api_key'    => '',
		'model'      => '',
		'configured' => false,
		'headers'    => [],
		'url'        => '',
		'label'      => self::label( $provider ),
	];
}
```

Do not allow an unregistered saved legacy ID to fall through to the Azure embedding configuration path.

- [ ] **Step 6: Keep runtime fail-closed for unregistered pins**

Update `Provider::selected_chat_connector()` so it returns the saved provider ID for registered and unregistered saved legacy pins:

```php
private static function selected_chat_connector( string $provider ): string {
	$provider = self::normalize_provider( $provider );

	if ( self::is_connector_or_saved_legacy_pin( $provider ) ) {
		return $provider;
	}

	if ( self::NATIVE === $provider && self::is_connector( self::OPENAI_CONNECTOR_ID ) ) {
		return self::OPENAI_CONNECTOR_ID;
	}

	return '';
}
```

Update `Provider::runtime_chat_configuration()` so only currently registered connector IDs can route to `connector_chat_configuration()`:

```php
if ( '' !== $connector_provider && self::is_connector( $connector_provider ) && WordPressAIClient::is_supported( $connector_provider ) ) {
	return self::connector_chat_configuration( $connector_provider );
}

if ( self::is_saved_legacy_connector_pin( $selected_provider ) ) {
	return self::missing_chat_configuration( $selected_provider );
}
```

This registered-connector guard is required because a generic WordPress AI Client runtime may report support even when a specific saved legacy connector ID is no longer registered. An unregistered saved legacy pin should flow into `missing_chat_configuration( $selected_provider )`, not the generic WordPress AI Client fallback and not `connector_chat_configuration()`.

- [ ] **Step 7: Add a Provider helper for settings choices**

In `inc/OpenAI/Provider.php`, add a public helper next to `choices()`:

```php
/**
 * @return array<string, string>
 */
public static function embedding_settings_choices( ?string $selected_provider = null ): array {
	$selected_provider = sanitize_key(
		(string) (
			$selected_provider
			?? self::raw_saved_provider()
		)
	);
	$choices           = self::direct_choices();

	if ( self::is_connector_or_saved_legacy_pin( $selected_provider ) ) {
		$connector_label = self::legacy_connector_pin_label( $selected_provider );
		$choices           = [
			$selected_provider => sprintf( 'Legacy connector pin: %s', $connector_label ),
		] + $choices;
	}

	return $choices;
}
```

This helper intentionally excludes unselected connectors so the Embedding Provider select cannot become a second Connectors screen.

- [ ] **Step 8: Use the helper in the Embedding Provider field**

In `inc/Admin/Settings/Registrar.php`, change the Embedding Provider field args from:

```php
'choices' => Provider::direct_choices(),
```

to:

```php
'choices' => Provider::embedding_settings_choices(),
```

- [ ] **Step 9: Render a visible legacy migration notice**

In `inc/Admin/Settings/Page.php`, inside `render_embedding_model_group()` immediately after the description paragraph and before rendering direct provider fields for a legacy pin, add:

```php
if ( Provider::is_connector_or_saved_legacy_pin( (string) $state['selected_provider'] ) ) {
	$legacy_label = Provider::legacy_connector_pin_label( (string) $state['selected_provider'] );
	?>
	<div class="notice notice-warning inline flavor-agent-settings-status">
		<p>
			<?php
			printf(
				/* translators: %s: legacy connector label */
				esc_html__( 'A legacy chat connector pin is saved for %s. It is preserved for backwards compatibility and chat still fails closed if that connector is unavailable. If the saved legacy connector is not currently registered, reinstall or re-enable the connector in Settings > Connectors before using that chat path. Choose Azure OpenAI, OpenAI Native, or Cloudflare Workers AI to migrate Embedding Provider to a direct embedding backend.', 'flavor-agent' ),
				esc_html( $legacy_label )
			);
			?>
		</p>
	</div>
	<?php
	self::render_azure_direct_settings_fields();
	self::render_openai_native_direct_settings_fields();
	self::render_cloudflare_workers_ai_direct_settings_fields();
	return;
}
```

- [ ] **Step 10: Run the legacy connector render and runtime tests**

Run:

```bash
vendor/bin/phpunit --filter 'test_render_page_preserves_saved_legacy_connector_pin_as_migration_state|test_render_page_preserves_unregistered_legacy_connector_pin_as_unavailable_migration_state|test_unregistered_saved_connector_pin_does_not_fall_back_to_generic_wordpress_ai_client'
```

Expected after implementation: the selected connector sentinel renders for registered and unregistered saved legacy pins, direct provider fields remain available, unselected connectors are not listed, and unregistered legacy pins do not collapse to Azure.

### Task 4: Remove Reasoning Effort From Embedding Model UI

**Files:**

- Modify: `inc/Admin/Settings/Page.php`
- Modify: `inc/Admin/Settings/Registrar.php`
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/reference/provider-precedence.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `tests/phpunit/SettingsRegistrarTest.php`
- Test: `tests/phpunit/SettingsTest.php`
- Test: `tests/phpunit/SettingsRegistrarTest.php`

- [ ] **Step 1: Add or update the page-render assertion**

In `test_render_page_moves_setup_guidance_into_wp_help()`, add:

```php
$this->assertStringNotContainsString( 'Reasoning Effort', $output );
$this->assertStringNotContainsString( 'flavor_agent_azure_reasoning_effort', $output );
```

- [ ] **Step 2: Remove the field from the rendered Azure subsection**

In `Page::render_azure_direct_settings_fields()`, change the field list from:

```php
[
	'flavor_agent_azure_openai_endpoint',
	'flavor_agent_azure_openai_key',
	'flavor_agent_azure_embedding_deployment',
	'flavor_agent_azure_reasoning_effort',
]
```

to:

```php
[
	'flavor_agent_azure_openai_endpoint',
	'flavor_agent_azure_openai_key',
	'flavor_agent_azure_embedding_deployment',
]
```

- [ ] **Step 3: Stop registering the visible settings field**

In `Registrar::register_settings()`, keep the `register_setting()` block for `flavor_agent_azure_reasoning_effort` so old values continue to sanitize, but remove the later `add_settings_field()` call whose label is `Reasoning Effort`.

- [ ] **Step 4: Update docs for hidden legacy compatibility**

Use this wording where the docs currently list Reasoning Effort as an Embedding Model control:

```markdown
Reasoning effort remains a backwards-compatible saved option used by the Connectors-routed chat runtime when present, but it is no longer an editable Embedding Model control. Text-generation provider and model readiness belong to `Settings > Connectors`.
```

- [ ] **Step 5: Run the settings render tests**

Run:

```bash
vendor/bin/phpunit --filter 'test_render_page_moves_setup_guidance_into_wp_help|test_register_settings'
```

Expected after implementation: no rendered `Reasoning Effort` field appears, while the sanitizer and runtime tests still pass.

### Task 5: Align Admin Copy, Feedback Groups, And Stale Test Expectations

**Files:**

- Modify: `inc/Admin/Settings/Feedback.php`
- Modify: `inc/Admin/Settings/Page.php`
- Modify: `inc/Admin/Settings/State.php`
- Modify: `inc/Admin/Settings/Help.php`
- Modify: `inc/Abilities/SurfaceCapabilities.php`
- Modify: `inc/Abilities/PatternAbilities.php`
- Modify: `src/patterns/__tests__/PatternRecommender.test.js`
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `tests/e2e/flavor-agent.settings.spec.js`

- [ ] **Step 1: Update the Azure validation focus expectation**

In `test_sanitize_azure_settings_request_scopes_validation_feedback_when_feedback_key_is_present()`, change the expected focus group and messages key from `chat` to `embeddings`:

```php
$this->assertSame( 'embeddings', $feedback['focus_section'] ?? '' );
$this->assertSame(
	[
		[
			'tone'    => 'error',
			'message' => 'Azure validation failed. Check the endpoint, API key, and embedding deployment, then try again.',
		],
		[
			'tone'    => 'warning',
			'message' => 'We kept your previous Azure settings because validation failed.',
		],
	],
	$feedback['messages']['embeddings'] ?? []
);
```

- [ ] **Step 2: Update settings success-copy expectations**

In `test_render_settings_save_summary_keeps_other_success_notices_when_one_section_has_errors()`, change:

```php
$this->assertStringContainsString( 'Docs grounding settings saved.', $output );
```

to:

```php
$this->assertStringContainsString( 'Developer docs settings saved.', $output );
```

In `test_render_page_consumes_request_scoped_feedback_only_for_the_matching_user()`, change `Chat provider saved.` expectations to:

```php
'AI model settings saved.'
```

In `tests/e2e/flavor-agent.settings.spec.js`, change:

```js
await expect(page.locator(".flavor-agent-settings-save-summary")).toContainText(
  "Docs grounding settings saved.",
);
```

to:

```js
await expect(page.locator(".flavor-agent-settings-save-summary")).toContainText(
  "Developer docs settings saved.",
);
```

- [ ] **Step 3: Update section numbering expectations**

In `SettingsTest`, update the structural assertions to the new order:

```php
$this->assertStringContainsString( '5. Guidelines', $output );
$this->assertStringContainsString( '6. Experimental Features', $output );
```

- [ ] **Step 4: Update escaped apostrophe expectation**

In `test_register_contextual_help_uses_native_wp_screen_help_tabs()`, change the Developer Docs assertion to match escaped help output:

```php
$this->assertStringContainsString( 'Developer Docs uses Flavor Agent&#039;s built-in public developer.wordpress.org endpoint.', $screen->help_tabs[1]['content'] );
```

- [ ] **Step 5: Lock default-open priority explicitly**

The intended default-open order remains `AI Model` first whenever chat is missing, because the top section reports Connectors readiness and provides the `Open Connectors` action. Add a both-missing test so the existing chat-first priority is deliberate instead of accidental:

```php
public function test_determine_default_open_group_prioritizes_ai_model_when_chat_and_embeddings_are_missing(): void {
	$state                      = $this->build_default_open_group_state();
	$state['runtime_chat']      = [ 'configured' => false ];
	$state['runtime_embedding'] = [ 'configured' => false ];
	$state['qdrant_configured'] = false;

	$this->assertSame( 'chat', State::determine_default_open_group( $state ) );
}

public function test_determine_default_open_group_prioritizes_embedding_model_when_chat_is_ready_and_embeddings_are_missing(): void {
	$state                      = $this->build_default_open_group_state();
	$state['runtime_chat']      = [ 'configured' => true ];
	$state['runtime_embedding'] = [ 'configured' => false ];
	$state['qdrant_configured'] = true;

	$this->assertSame( 'embeddings', State::determine_default_open_group( $state ) );
}

public function test_determine_default_open_group_prioritizes_ai_model_when_chat_is_missing_after_embeddings_are_ready(): void {
	$state                      = $this->build_default_open_group_state();
	$state['runtime_chat']      = [ 'configured' => false ];
	$state['runtime_embedding'] = [ 'configured' => true ];
	$state['qdrant_configured'] = true;

	$this->assertSame( 'chat', State::determine_default_open_group( $state ) );
}
```

Keep the existing patterns and Developer Docs priority tests, but rename them if their old names still imply `Chat Provider`. No code change is needed for this priority unless product direction changes to make `Embedding Model` open first when both chat and embeddings are missing.

- [ ] **Step 6: Refresh unavailable-state copy**

Replace admin-facing capability strings with these terms:

```php
__( 'Pattern recommendations are not configured yet. Ask an administrator to configure Pattern Storage in Settings > Flavor Agent and a text-generation provider in Settings > Connectors.', 'flavor-agent' )
__( 'Pattern recommendations need Cloudflare AI Search Pattern Storage in Settings > Flavor Agent, plus a usable text-generation provider in Settings > Connectors.', 'flavor-agent' )
__( 'Pattern recommendations need Cloudflare AI Search Pattern Storage in Settings > Flavor Agent.', 'flavor-agent' )
__( 'Pattern recommendations need the Embedding Model and Qdrant Pattern Storage in Settings > Flavor Agent, plus a usable text-generation provider in Settings > Connectors.', 'flavor-agent' )
__( 'Pattern recommendations need the Embedding Model and Qdrant Pattern Storage in Settings > Flavor Agent.', 'flavor-agent' )
```

Use these in `inc/Abilities/SurfaceCapabilities.php` and mirror the same wording in `inc/Abilities/PatternAbilities.php` where the ability returns direct unavailable messages.

- [ ] **Step 7: Refresh Developer Docs diagnostics copy**

In `inc/Admin/Settings/State.php`, change user-facing diagnostics from `Docs grounding...` to `Developer Docs grounding...`:

```php
__( 'Developer Docs grounding is retrying fresh warm requests after a runtime search failure: %s', 'flavor-agent' )
__( 'Developer Docs grounding is retrying fresh warm requests after a runtime search failure.', 'flavor-agent' )
__( 'Developer Docs grounding is warming more specific guidance in the background. Broad cached guidance may still be used until the queue drains.', 'flavor-agent' )
__( 'Developer Docs grounding is on, but live grounding needs attention: %s', 'flavor-agent' )
__( 'Developer Docs grounding is on, but live grounding is currently falling back to broad cached guidance.', 'flavor-agent' )
```

- [ ] **Step 8: Run stale-copy searches**

Run:

```bash
rg -n --glob '!docs/reference/admin-dashboard-settings-complete-solution-plan.md' "Chat provider saved|Docs grounding settings saved|Docs Grounding|compatible embedding backend and Qdrant|pattern backends|Needs embeddings & Qdrant" inc src tests docs readme.txt
```

Expected: no matches for old save-summary strings or old generated-admin status text outside this plan file. Technical docs may still use lower-case `docs grounding` only when describing the subsystem, not as a settings section label.

### Task 6: Refresh Built Admin Assets

**Files:**

- Modify: `build/admin.js`
- Modify: `build/admin.asset.php`
- Test: source and build string search

- [ ] **Step 1: Build the admin bundle**

Run:

```bash
npm run build
```

Expected: `build/admin.js` is regenerated from `src/admin/settings-page-controller.js`.

- [ ] **Step 2: Verify the stale bundled copy is gone**

Run:

```bash
rg -n "Needs embeddings & Qdrant" build/admin.js src/admin/settings-page-controller.js
rg -n "Needs setup" build/admin.js src/admin/settings-page-controller.js
```

Expected: the first command prints no matches; the second command finds the current source and generated bundle.

### Task 6a: Apply Settings UI Polish

**Files:**

- Modify: `inc/Admin/Settings/Page.php` only if markup wrappers or status placement need small adjustments
- Modify: `src/admin/settings.css`
- Modify: `src/admin/brand.css` only if an existing token needs refinement
- Modify: `tests/e2e/flavor-agent.settings.spec.js` if adding stable UI assertions
- Test: desktop/mobile visual checks, build, focused settings E2E when available

- [ ] **Step 1: Review the page at realistic admin widths**

Check the page at desktop width, a narrow split-screen width around 960px, and a mobile/narrow admin width around 390px. Confirm section headers, status cards, form tables, notices, and action buttons wrap without horizontal scrolling.

- [ ] **Step 2: Polish hierarchy without changing behavior**

If the page feels visually noisy after the settings migration, make small CSS-only improvements in `src/admin/settings.css`:

```css
/* Examples of acceptable polish targets, not required exact code. */
.flavor-agent-settings-section__summary { ... }
.flavor-agent-settings-subheading { ... }
.flavor-agent-settings-status { ... }
.flavor-agent-settings-table { ... }
.flavor-agent-settings__actions { ... }
```

Prefer spacing, wrapping, contrast, and grouping improvements over new components. Do not introduce a new visual system.

- [ ] **Step 3: Keep migration states visually secondary but unmistakable**

Legacy connector pin notices and legacy Developer Docs override panels should be easy to find when present, but they should not look like the primary path for new setup. Use existing notice/status styles and short explanatory copy rather than a new callout pattern.

- [ ] **Step 4: Preserve accessibility and native admin behavior**

After any markup or CSS change, verify labels still target controls, `details`/`summary` sections remain keyboard-operable, focus outlines are visible, status badges do not rely on color alone, and long provider/model strings wrap.

- [ ] **Step 5: Verify UI polish changes**

Run:

```bash
npm run build
npm run test:e2e:playground -- tests/e2e/flavor-agent.settings.spec.js
```

Expected: build succeeds and the settings page remains usable at desktop and narrow widths. If the Playwright harness is unavailable, record the blocker and include manual responsive/a11y observations in the closeout.

### Task 7: Update Documentation To Match The Final Flow

**Files:**

- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `docs/features/pattern-recommendations.md`
- Modify: `docs/reference/provider-precedence.md`
- Modify: `docs/reference/local-environment-setup.md`
- Modify: `docs/reference/external-service-disclosure.md` if wording drift appears during the copy scan
- Modify: `docs/flavor-agent-readme.md`
- Modify: `readme.txt`
- Test: `npm run check:docs`

- [ ] **Step 1: Update settings ownership docs**

Make these statements consistent across the docs:

```markdown
- AI Model is read-only readiness for the WordPress AI Client text-generation runtime configured in `Settings > Connectors`.
- Embedding Model is the single direct Azure OpenAI, OpenAI Native, or Cloudflare Workers AI embedding provider used by Flavor Agent semantic features.
- Pattern Storage is infrastructure. Qdrant uses the Embedding Model; Cloudflare AI Search uses a private managed pattern index.
- Developer Docs uses Flavor Agent's built-in public endpoint by default. Legacy Cloudflare override fields render only for saved legacy override values.
- Saved legacy connector pins are preserved as migration state and continue to fail closed if their connector is unavailable. Choosing a direct embedding provider migrates the setting out of that legacy pin state.
- Reasoning effort is not an editable Embedding Model control; older saved values remain runtime-compatible when the Connectors provider supports the mapped custom option.
```

- [ ] **Step 2: Update pattern docs**

Use `Pattern Storage` in operator-facing docs. Keep `backend` only when explaining PHP class internals such as `PatternRetrievalBackendFactory`.

- [ ] **Step 3: Run docs freshness**

Run:

```bash
npm run check:docs
```

Expected: docs freshness passes.

### Task 8: Run Focused Verification

**Files:**

- No code edits in this task
- Test: PHPUnit, JS unit tests, build, docs, whitespace

- [ ] **Step 1: Run focused settings and runtime PHP tests**

Run:

```bash
vendor/bin/phpunit --filter SettingsTest
vendor/bin/phpunit tests/phpunit/SettingsRegistrarTest.php tests/phpunit/ProviderTest.php tests/phpunit/FeatureBootstrapTest.php
```

Expected: all tests pass. If a failure mentions old copy, update the assertion or source copy according to Task 5.

- [ ] **Step 2: Run focused JS tests**

Run:

```bash
npm run test:unit -- settings-page-controller.test.js activity-log-utils.test.js PatternRecommender.test.js --runInBand
```

Expected: all listed suites pass. If `PatternRecommender.test.js` fails, update expected unavailable copy to the Task 5 wording.

- [ ] **Step 3: Run docs and build checks**

Run:

```bash
npm run build
npm run check:docs
git diff --check
```

Expected: build succeeds, docs freshness passes, and whitespace checks pass.

### Task 9: Run Release-Representative Validation

**Files:**

- No code edits in this task
- Test: verifier and browser settings coverage

- [ ] **Step 1: Run the non-E2E verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: the verifier completes with `VERIFY_RESULT` success. If `lint-plugin` is incomplete because the local WordPress root cannot be resolved, rerun with an explicit waiver:

```bash
npm run verify -- --skip-e2e --skip=lint-plugin
```

Record the exact `VERIFY_RESULT` JSON line in the implementation closeout.

- [ ] **Step 2: Run settings Playwright coverage when the local harness is available**

Run:

```bash
npm run test:e2e:playground -- tests/e2e/flavor-agent.settings.spec.js
npm run test:e2e:wp70 -- tests/e2e/flavor-agent.settings.spec.js
```

Expected: both settings specs pass. If the WP 7.0 harness is unavailable, record the environment blocker and keep the playground result plus the non-E2E verifier as the local evidence.

## Completion Checklist

- [ ] Inactive provider values are preserved when their fields are not posted.
- [ ] Posted blank rendered values still clear non-secret fields.
- [ ] Saved legacy connector pins are visible, explicit, and preserved unless the admin chooses a direct embedding provider.
- [ ] `Reasoning Effort` no longer appears in the Embedding Model UI.
- [ ] AI Model remains read-only and links to `Settings > Connectors`.
- [ ] Pattern copy uses `Pattern Storage` and `Embedding Model`.
- [ ] Developer Docs copy uses the built-in public endpoint framing and legacy override gating.
- [ ] Settings UI remains polished at desktop and narrow admin widths, with clear hierarchy, native controls, visible focus states, and no horizontal overflow.
- [ ] `build/admin.js` no longer contains `Needs embeddings & Qdrant`.
- [ ] PHP, JS, docs, build, and targeted browser gates are green or have an explicit environment waiver.
