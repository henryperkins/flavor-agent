# Defensive Fallback Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove overly defensive fallback behavior so explicit configuration failures fail closed, direct tools report live errors, and editor/admin capability state comes from one canonical contract.

**Architecture:** Keep recommendation runtime fallbacks that are intentionally cache-first and non-blocking, but remove fallbacks that hide explicit operator choices or direct-tool failures. Treat WordPress AI Client feature model selection, direct Developer Docs search, editor surface capabilities, and reasoning effort as separate contracts with targeted tests before implementation. Preserve cleanup of old stored options during uninstall without allowing hidden legacy values to affect runtime behavior.

**Tech Stack:** PHP 8.0-compatible WordPress plugin code, PHPUnit shims in `tests/phpunit/bootstrap.php`, `@wordpress/scripts` Jest tests, repository docs checks.

---

## Findings Covered

1. Developer-selected model resolution falls back to provider-managed defaults when the selected model cannot be resolved.
2. Direct WordPress docs search can return recent last-known-current guidance after a live search failure.
3. Editor capability boot data still emits and consumes legacy `canRecommend*` flags in addition to structured `capabilities.surfaces`.
4. Hidden legacy `flavor_agent_azure_reasoning_effort` values still influence settings preservation and runtime ranking.

## Non-Goals

- Do not remove the synced-pattern helper browse fallback in `inc/Context/SyncedPatternRepository.php`; browse helpers and recommendation ranking have different contracts.
- Do not remove Gutenberg `__experimental*` compatibility selectors in `src/patterns/pattern-settings.js`; those remain part of the current WordPress trunk compatibility surface.
- Do not change Guidelines core-to-legacy migration behavior in this pass.
- Do not hand-edit generated files in `build/` or package output in `dist/`.

## Files

- Modify: `inc/LLM/WordPressAIClient.php`
- Modify: `tests/phpunit/WordPressAIClientTest.php`
- Modify: `inc/Cloudflare/AISearchClient.php`
- Modify: `tests/phpunit/AISearchClientTest.php`
- Create: `tests/phpunit/WordPressDocsAbilitiesTest.php`
- Modify: `flavor-agent.php`
- Modify: `src/utils/capability-flags.js`
- Modify: `src/utils/__tests__/capability-flags.test.js`
- Modify: `src/content/__tests__/ContentRecommender.test.js`
- Modify: `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
- Modify: `src/inspector/__tests__/NavigationRecommendations.test.js`
- Modify: `src/patterns/__tests__/PatternRecommender.test.js`
- Modify: `src/templates/__tests__/TemplateRecommender.test.js`
- Modify: `src/template-parts/__tests__/TemplatePartRecommender.test.js`
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`
- Modify: `tests/e2e/flavor-agent.docs-grounding-warning.spec.js`
- Modify: `inc/Admin/Settings/Config.php`
- Modify: `inc/Admin/Settings/Validation.php`
- Modify: `inc/AzureOpenAI/ResponsesClient.php`
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `tests/phpunit/ResponsesClientTest.php`
- Modify: `docs/reference/provider-precedence.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `docs/features/helper-abilities.md`
- Modify: `docs/flavor-agent-readme.md`
- Modify: `docs/features/block-recommendations.md`
- Modify: `docs/features/content-recommendations.md`
- Modify: `docs/features/navigation-recommendations.md`
- Modify: `docs/features/pattern-recommendations.md`
- Modify: `docs/features/template-recommendations.md`
- Modify: `docs/features/template-part-recommendations.md`
- Modify: `docs/features/style-and-theme-intelligence.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`
- Modify: `CLAUDE.md`

---

### Task 1: Fail Closed When Developer-Selected Model Resolution Fails

**Files:**
- Modify: `tests/phpunit/WordPressAIClientTest.php`
- Modify: `inc/LLM/WordPressAIClient.php`
- Modify: `docs/reference/provider-precedence.md`

- [ ] **Step 1: Replace the provider-fallback model test with a fail-closed test**

In `tests/phpunit/WordPressAIClientTest.php`, replace `test_chat_falls_back_to_provider_when_developer_selected_model_resolution_fails()` with this test:

```php
public function test_chat_returns_error_when_developer_selected_model_resolution_fails(): void {
	self::register_ai_provider_connector( 'anthropic', 'Anthropic' );
	WordPressTestState::$options                          = [
		'wpai_feature_flavor-agent_field_developer' => [
			'provider' => 'anthropic',
			'model'    => 'claude-sonnet-4-6',
		],
	];
	WordPressTestState::$ai_client_provider_support       = [
		'anthropic' => true,
	];
	WordPressTestState::$ai_client_model_resolution_error = new \WP_Error(
		'model_not_found',
		'The configured model is no longer available.'
	);
	WordPressTestState::$ai_client_generate_text_result   = '{"explanation":"OK."}';

	$result = WordPressAIClient::chat( 'System.', 'User.' );

	$this->assertInstanceOf( \WP_Error::class, $result );
	$this->assertSame( 'flavor_agent_model_resolution_failed', $result->get_error_code() );
	$this->assertStringContainsString( 'claude-sonnet-4-6', $result->get_error_message() );
	$this->assertSame(
		[
			'status'   => 503,
			'provider' => 'anthropic',
			'model'    => 'claude-sonnet-4-6',
		],
		$result->get_error_data()
	);
	$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
	$this->assertArrayNotHasKey( 'model', WordPressTestState::$last_ai_client_prompt );
}
```

Do not assert that `Provider::active_chat_request_meta()` is `null`; that method is intentionally an always-array request metadata contract and will fall back to current chat configuration when no fresh runtime configuration is recorded. The regression signal for this fail-closed path is the returned `WP_Error` plus the absence of `provider` and `model` on the prompt state.

- [ ] **Step 2: Run the targeted failing test**

Run:

```bash
composer run test:php -- --filter 'WordPressAIClientTest::test_chat_returns_error_when_developer_selected_model_resolution_fails'
```

Expected: the new test fails because `WordPressAIClient::chat()` still succeeds through provider-only fallback.

- [ ] **Step 3: Add a model-resolution error helper**

In `inc/LLM/WordPressAIClient.php`, add this private helper near `apply_provider_model_selection()`:

```php
/**
 * @param array{provider: string, model: string, source: string, modelResolutionStatus: string, modelResolutionError?: string} $selection
 */
private static function model_resolution_error( array $selection, string $message ): \WP_Error {
	$provider = sanitize_key( (string) ( $selection['provider'] ?? '' ) );
	$model    = sanitize_text_field( (string) ( $selection['model'] ?? '' ) );
	$message  = sanitize_text_field( $message );

	return new \WP_Error(
		'flavor_agent_model_resolution_failed',
		sprintf(
			/* translators: 1: provider id, 2: model id, 3: resolution failure. */
			__( 'The configured AI model "%2$s" for provider "%1$s" could not be resolved: %3$s', 'flavor-agent' ),
			$provider,
			$model,
			$message
		),
		[
			'status'   => 503,
			'provider' => $provider,
			'model'    => $model,
		]
	);
}
```

- [ ] **Step 4: Replace provider fallback branches with fail-closed returns**

In `apply_provider_model_selection()`, keep provider-only behavior when no model was requested. When `$model` is non-empty, make these cases return `self::model_resolution_error( $selection, $message )`:

```php
if ( '' !== $model ) {
	if ( ! class_exists( AiClient::class ) || ! is_callable( [ AiClient::class, 'defaultRegistry' ] ) || ! is_callable( [ $prompt, 'using_model' ] ) ) {
		return self::model_resolution_error(
			$selection,
			'The WordPress AI Client cannot apply the configured provider model.'
		);
	}

	try {
		$registry       = AiClient::defaultRegistry();
		$provider_model = is_object( $registry ) && is_callable( [ $registry, 'getProviderModel' ] )
			? $registry->getProviderModel( $provider, $model )
			: null;

		if ( is_wp_error( $provider_model ) ) {
			return self::model_resolution_error( $selection, $provider_model->get_error_message() );
		}

		if ( ! is_object( $provider_model ) ) {
			return self::model_resolution_error(
				$selection,
				'The selected provider model was not returned by the WordPress AI Client registry.'
			);
		}

		$updated_prompt = self::call_prompt_method( $prompt, 'using_model', [ $provider_model ] );

		if ( is_wp_error( $updated_prompt ) ) {
			return self::model_resolution_error( $selection, $updated_prompt->get_error_message() );
		}

		if ( ! is_object( $updated_prompt ) ) {
			return self::model_resolution_error(
				$selection,
				'The selected provider model could not be attached to the prompt.'
			);
		}

		$selection['modelResolutionStatus'] = 'model';
		Provider::record_runtime_chat_configuration(
			[
				'provider' => $provider,
				'model'    => $model,
			]
		);

		return $updated_prompt;
	} catch ( \Throwable $throwable ) {
		return self::model_resolution_error( $selection, $throwable->getMessage() );
	}
}
```

Remove assignments to `model_resolution_failed_provider_fallback`, `modelResolutionError`, and `$selection['model'] = ''` from the model-resolution failure branches.

- [ ] **Step 5: Update provider precedence docs**

In `docs/reference/provider-precedence.md`, replace the sentence that says developer-selected models fall back to provider-managed defaults. Use this text:

```markdown
When the WordPress AI Plugin feature setting selects both a provider and a model, Flavor Agent treats that model as an explicit operator choice. If WordPress AI Client cannot resolve or apply the selected model, the request returns `flavor_agent_model_resolution_failed` instead of falling back to the provider-managed default. Provider-only selections still use the provider's managed default model.
```

- [ ] **Step 6: Verify the model-selection gate**

Run:

```bash
composer run test:php -- --filter WordPressAIClientTest
```

Expected: `WordPressAIClientTest` passes and no assertion expects `model_resolution_failed_provider_fallback`.

---

### Task 2: Remove Last-Known Guidance Grace From Direct Docs Search

**Files:**
- Modify: `tests/phpunit/AISearchClientTest.php`
- Create: `tests/phpunit/WordPressDocsAbilitiesTest.php`
- Modify: `inc/Cloudflare/AISearchClient.php`
- Modify: `docs/features/helper-abilities.md`

- [ ] **Step 1: Replace the live-search grace test with an error test**

In `tests/phpunit/AISearchClientTest.php`, replace `test_live_search_failure_uses_recent_last_known_current_guidance_as_degraded_grace()` with this test:

```php
public function test_live_search_failure_returns_error_even_with_recent_last_known_current_guidance(): void {
	WordPressTestState::$remote_post_response = [
		'response' => [
			'code' => 200,
		],
		'body'     => wp_json_encode(
			[
				'result' => [
					'chunks' => [
						[
							'id'    => 'current-docs',
							'score' => 0.91,
							'item'  => [
								'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
								'metadata' => [],
							],
							'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\ncontent_hash: current-docs\n---\nUse block supports to expose design tools.",
						],
					],
				],
			]
		),
	];

	$primed = AISearchClient::search( 'block supports guidance' );

	$this->assertIsArray( $primed );

	WordPressTestState::$remote_post_response = new \WP_Error(
		'http_request_failed',
		'Cloudflare timed out.'
	);

	$result = AISearchClient::search( 'block supports guidance after failure' );

	$this->assertInstanceOf( \WP_Error::class, $result );
	$this->assertSame( 'http_request_failed', $result->get_error_code() );

	$runtime_state = AISearchClient::get_runtime_state();

	$this->assertSame( 'Cloudflare timed out.', $runtime_state['lastErrorMessage'] );
	$this->assertNotSame( 'grace', $runtime_state['lastServedMode'] ?? '' );
	$this->assertNotSame( 'last-known-current', $runtime_state['lastFallbackType'] ?? '' );
}
```

- [ ] **Step 2: Add an empty trusted-result grace regression**

In `tests/phpunit/AISearchClientTest.php`, add this test next to the live-search failure regression so both current grace branches are covered:

```php
public function test_live_search_empty_trusted_result_returns_empty_guidance_even_with_recent_last_known_current_guidance(): void {
	WordPressTestState::$remote_post_response = [
		'response' => [
			'code' => 200,
		],
		'body'     => wp_json_encode(
			[
				'result' => [
					'chunks' => [
						[
							'id'    => 'current-docs',
							'score' => 0.91,
							'item'  => [
								'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
								'metadata' => [],
							],
							'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\ncontent_hash: current-docs\n---\nUse block supports to expose design tools.",
						],
					],
				],
			]
		),
	];

	$primed = AISearchClient::search( 'block supports guidance' );

	$this->assertIsArray( $primed );

	WordPressTestState::$remote_post_response = [
		'response' => [
			'code' => 200,
		],
		'body'     => wp_json_encode(
			[
				'result' => [
					'chunks' => [],
				],
			]
		),
	];

	$result = AISearchClient::search( 'block supports guidance after empty result' );

	$this->assertIsArray( $result );
	$this->assertSame( [], $result['guidance'] );

	$runtime_state = AISearchClient::get_runtime_state();

	$this->assertSame( 'no_trusted_docs_grounding', $runtime_state['lastErrorCode'] );
	$this->assertSame(
		'Developer Docs grounding returned no trusted official guidance.',
		$runtime_state['lastErrorMessage']
	);
	$this->assertNotSame( 'grace', $runtime_state['lastServedMode'] ?? '' );
	$this->assertNotSame( 'last-known-current', $runtime_state['lastFallbackType'] ?? '' );
}
```

Expected: this test fails before implementation because `search_live()` still substitutes recent last-known-current guidance after a live 200 response normalizes to no trusted chunks.

- [ ] **Step 3: Add a direct ability regression test**

Create `tests/phpunit/WordPressDocsAbilitiesTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\WordPressDocsAbilities;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class WordPressDocsAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_search_wordpress_docs_returns_live_error_instead_of_grace_guidance(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'    => 'current-docs',
								'score' => 0.91,
								'item'  => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
									'metadata' => [],
								],
								'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\ncontent_hash: current-docs\n---\nUse block supports to expose design tools.",
							],
						],
					],
				]
			),
		];

		$primed = AISearchClient::search( 'block supports guidance' );

		$this->assertIsArray( $primed );

		WordPressTestState::$remote_post_response = new \WP_Error(
			'http_request_failed',
			'Cloudflare timed out.'
		);

		$result = WordPressDocsAbilities::search_wordpress_docs(
			[
				'query' => 'block supports guidance after failure',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertSame( 'Cloudflare timed out.', $result->get_error_message() );
	}
}
```

- [ ] **Step 4: Run the targeted failing tests**

Run:

```bash
composer run test:php -- --filter 'AISearchClientTest::test_live_search_failure_returns_error_even_with_recent_last_known_current_guidance|AISearchClientTest::test_live_search_empty_trusted_result_returns_empty_guidance_even_with_recent_last_known_current_guidance|WordPressDocsAbilitiesTest'
```

Expected: the new tests fail because recent last-known guidance is still served for both the request-failure branch and the empty-trusted-result branch.

- [ ] **Step 5: Remove grace guidance branches from live search**

In `inc/Cloudflare/AISearchClient.php`, `search_live()` currently calls `get_last_known_current_guidance_for_grace()` from **two** branches:

- The `is_wp_error( $data )` request-failure branch (around line 200).
- The empty-trusted-result branch (around line 223).

Both must be removed. Request failures should record the error and return the `WP_Error` directly:

```php
if ( is_wp_error( $data ) ) {
	self::record_runtime_search_error( $runtime_mode, $data );
	return $data;
}
```

Empty normalized guidance should retain its diagnostic record and return an empty guidance array (do not fall through to grace):

```php
if ( [] === $guidance ) {
	self::record_runtime_search_empty_result( $runtime_mode );
} else {
	self::record_runtime_search_success( $runtime_mode, $guidance );
}
```

Preserve `record_runtime_search_error()` and `record_runtime_search_empty_result()` exactly as today — the grace removal is strictly about not substituting cached guidance for the live result; per-branch diagnostic recording is unchanged.

Keep `lastKnownCurrentGuidance` writes in `record_runtime_search_success()` so diagnostics can still explain the last current result. Remove `get_last_known_current_guidance_for_grace()` and `LAST_KNOWN_CURRENT_GRACE_TTL` only after confirming no tests or code paths reference them.

- [ ] **Step 6: Update docs-search backend docs**

In `docs/features/helper-abilities.md`, change the docs-search backend description from cache fallback wording to live-search wording:

```markdown
| Docs search backend | `AISearchClient::search()` / `warm_entity()` / `maybe_search_with_cache_fallbacks()` | Direct helper calls return live search errors; recommendation grounding remains cache-first through exact, family, entity, and async warm paths |
```

- [ ] **Step 7: Verify docs-search behavior**

Run:

```bash
composer run test:php -- --filter AISearchClientTest
composer run test:php -- --filter WordPressDocsAbilitiesTest
rg -n 'get_last_known_current_guidance_for_grace|LAST_KNOWN_CURRENT_GRACE_TTL' inc tests --glob '!docs/reference/2026-05-19-defensive-fallback-remediation-plan.md'
```

Expected: direct search failures return `WP_Error`; empty live trusted-result responses return an empty guidance array without grace substitution; recommendation cache tests continue to pass. The `rg` command returns no matches.

---

### Task 3: Remove Legacy Editor Capability Flag Contract

**Files:**
- Modify: `flavor-agent.php`
- Modify: `src/utils/capability-flags.js`
- Modify: `src/utils/__tests__/capability-flags.test.js`
- Modify: `src/content/__tests__/ContentRecommender.test.js`
- Modify: `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
- Modify: `src/inspector/__tests__/NavigationRecommendations.test.js`
- Modify: `src/patterns/__tests__/PatternRecommender.test.js`
- Modify: `src/templates/__tests__/TemplateRecommender.test.js`
- Modify: `src/template-parts/__tests__/TemplatePartRecommender.test.js`
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`
- Modify: `tests/e2e/flavor-agent.docs-grounding-warning.spec.js`
- Modify: `docs/features/block-recommendations.md`
- Modify: `docs/features/content-recommendations.md`
- Modify: `docs/features/navigation-recommendations.md`
- Modify: `docs/features/pattern-recommendations.md`
- Modify: `docs/features/template-recommendations.md`
- Modify: `docs/features/template-part-recommendations.md`
- Modify: `docs/features/style-and-theme-intelligence.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a structured-contract-only JS test**

In `src/utils/__tests__/capability-flags.test.js`, replace legacy-only tests with this regression test:

```js
test( 'ignores legacy recommendation flags when structured capabilities are missing', () => {
	window.flavorAgentData = {
		canRecommendBlocks: true,
		canRecommendPatterns: true,
		settingsUrl:
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		connectorsUrl: 'https://example.test/wp-admin/options-connectors.php',
	};

	expect( getSurfaceCapability( 'block' ) ).toMatchObject( {
		available: false,
		reason: 'plugin_provider_unconfigured',
		actionHref: '',
		actions: [],
	} );
	expect( getSurfaceCapability( 'pattern' ) ).toMatchObject( {
		available: false,
		reason: 'plugin_provider_unconfigured',
		actionHref: '',
		actions: [],
	} );
} );
```

Keep the structured capability tests for navigation, block, pattern, and style-book behavior.

- [ ] **Step 2: Run the targeted failing JS test**

Run:

```bash
npm run test:unit -- --runTestsByPath src/utils/__tests__/capability-flags.test.js
```

Expected: the new legacy-ignore test fails because `getSurfaceCapability()` still reads `canRecommend*` flags.

- [ ] **Step 3: Remove legacy flag reads from the JS helper**

In `src/utils/capability-flags.js`:

1. Delete `LEGACY_FLAG_KEYS`.
2. Delete `getDefaultActions()` if no remaining branch calls it.
3. Compute availability from structured data only:

```js
const available =
	typeof structuredCapability?.available === 'boolean'
		? structuredCapability.available
		: false;
```

4. Remove `hasLegacyAvailability` from reason selection:

```js
if (
	typeof structuredCapability?.reason === 'string' &&
	structuredCapability.reason
) {
	reason = structuredCapability.reason;
} else if ( available ) {
	reason = 'ready';
} else if ( surface === 'block' && hasStructuredCapability ) {
	reason = 'block_backend_unconfigured';
}
```

5. Remove default action fabrication when structured capability data is missing:

```js
if ( actions.length === 0 && structuredSingleAction ) {
	actions = [ structuredSingleAction ];
}
```

- [ ] **Step 4: Stop emitting legacy flags in localized boot data**

In `flavor-agent.php`, remove these keys from the `flavorAgentData` array:

```php
'canRecommendBlocks'           => $surface_capabilities['block']['available'],
'canRecommendPatterns'         => $surface_capabilities['pattern']['available'],
'canRecommendContent'          => $surface_capabilities['content']['available'],
'canRecommendTemplates'        => $surface_capabilities['template']['available'],
'canRecommendTemplateParts'    => $surface_capabilities['templatePart']['available'],
'canRecommendNavigation'       => $surface_capabilities['navigation']['available'],
'canRecommendGlobalStyles'     => $surface_capabilities['globalStyles']['available'],
'canRecommendStyleBook'        => $surface_capabilities['styleBook']['available'],
```

Do not remove `capabilities => [ 'surfaces' => $surface_capabilities ]`.

- [ ] **Step 5: Update first-party component test fixtures to structured capabilities**

Replace `window.flavorAgentData` fixtures that only seed legacy `canRecommend*` booleans with `capabilities.surfaces` payloads in these files:

- `src/content/__tests__/ContentRecommender.test.js`
- `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
- `src/inspector/__tests__/NavigationRecommendations.test.js`
- `src/patterns/__tests__/PatternRecommender.test.js`
- `src/templates/__tests__/TemplateRecommender.test.js`
- `src/template-parts/__tests__/TemplatePartRecommender.test.js`

Use this shape for available surfaces:

```js
window.flavorAgentData = {
	capabilities: {
		surfaces: {
			block: {
				available: true,
				reason: 'ready',
				message: '',
				actions: [],
			},
		},
	},
};
```

Use the matching structured key for each surface:

```js
const STRUCTURED_TEST_SURFACES = {
	block: 'block',
	content: 'content',
	navigation: 'navigation',
	pattern: 'pattern',
	template: 'template',
	'template-part': 'templatePart',
	'global-styles': 'globalStyles',
	'style-book': 'styleBook',
};
```

Use this unavailable shape when a test intentionally exercises the setup notice:

```js
window.flavorAgentData = {
	settingsUrl:
		'https://example.test/wp-admin/options-general.php?page=flavor-agent',
	connectorsUrl: 'https://example.test/wp-admin/options-connectors.php',
	capabilities: {
		surfaces: {
			templatePart: {
				available: false,
				reason: 'plugin_provider_unconfigured',
				message:
					'Template-part recommendations need a text-generation provider configured in Settings > Connectors.',
				actions: [
					{
						label: 'Open Connectors',
						href: 'https://example.test/wp-admin/options-connectors.php',
					},
				],
			},
		},
	},
};
```

For pattern-unavailable tests, use `reason: 'pattern_backend_unconfigured'` and include the Flavor Agent settings action when the test expects pattern storage guidance. For navigation, Global Styles, and Style Book permission tests, preserve `reason: 'missing_theme_capability'` or `reason: 'surface_not_implemented'` exactly where the existing assertion expects those messages.

- [ ] **Step 6: Update E2E capability checks and injected boot data**

In `tests/e2e/flavor-agent.smoke.spec.js`, replace the legacy flag map entries like:

```js
{
	flag: 'canRecommendBlocks',
	capability: 'block',
}
```

with structured capability entries:

```js
{
	capability: 'block',
}
```

Replace direct runtime reads such as:

```js
Boolean( window.flavorAgentData?.canRecommendGlobalStyles )
```

with:

```js
Boolean(
	window.flavorAgentData?.capabilities?.surfaces?.globalStyles?.available
)
```

Replace injected legacy mutations such as:

```js
data.canRecommendBlocks = false;
data.canRecommendPatterns = false;
```

with structured surface mutation:

```js
data.capabilities = data.capabilities || {};
data.capabilities.surfaces = data.capabilities.surfaces || {};
data.capabilities.surfaces.block = {
	...( data.capabilities.surfaces.block || {} ),
	available: false,
	reason: 'plugin_provider_unconfigured',
};
data.capabilities.surfaces.pattern = {
	...( data.capabilities.surfaces.pattern || {} ),
	available: false,
	reason: 'pattern_backend_unconfigured',
};
```

In `tests/e2e/flavor-agent.docs-grounding-warning.spec.js`, replace `data.canRecommendPatterns = true` with:

```js
data.capabilities = data.capabilities || {};
data.capabilities.surfaces = data.capabilities.surfaces || {};
data.capabilities.surfaces.pattern = {
	...( data.capabilities.surfaces.pattern || {} ),
	available: true,
	reason: 'ready',
};
```

- [ ] **Step 7: Update capability docs away from legacy flag names**

Replace user-facing docs that name `window.flavorAgentData.canRecommend*` with the structured contract:

```markdown
`window.flavorAgentData.capabilities.surfaces.<surfaceKey>.available`
```

Use the correct structured keys in each doc:

- `block`
- `content`
- `navigation`
- `pattern`
- `template`
- `templatePart`
- `globalStyles`
- `styleBook`

Update these docs:

- `docs/features/block-recommendations.md`
- `docs/features/content-recommendations.md`
- `docs/features/navigation-recommendations.md`
- `docs/features/pattern-recommendations.md`
- `docs/features/template-recommendations.md`
- `docs/features/template-part-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `CLAUDE.md`

`docs/FEATURE_SURFACE_MATRIX.md` requires special attention: its per-surface `gate` column references `flavorAgentData.canRecommendBlocks`, `flavorAgentData.canRecommendContent`, `flavorAgentData.canRecommendNavigation`, `flavorAgentData.canRecommendTemplates`, `flavorAgentData.canRecommendTemplateParts`, `flavorAgentData.canRecommendGlobalStyles`, and `flavorAgentData.canRecommendStyleBook` inside its table cells. Replace each with the matching structured path (`flavorAgentData.capabilities.surfaces.block.available`, `…content.available`, `…navigation.available`, `…template.available`, `…templatePart.available`, `…globalStyles.available`, `…styleBook.available`).

In `CLAUDE.md`, update the editor localized JS globals bullet to remove the legacy flags from the contract description. Replace:

```markdown
- `flavorAgentData` (editor) → `restUrl`, `nonce`, `settingsUrl`, `connectorsUrl`, `canManageFlavorAgentSettings`, structured `capabilities.surfaces`, legacy per-surface `canRecommend*` flags (Blocks/Patterns/Content/Templates/TemplateParts/Navigation/GlobalStyles/StyleBook), `templatePartAreas`
```

with:

```markdown
- `flavorAgentData` (editor) → `restUrl`, `nonce`, `settingsUrl`, `connectorsUrl`, `canManageFlavorAgentSettings`, structured `capabilities.surfaces` (block, content, pattern, template, templatePart, navigation, globalStyles, styleBook), `templatePartAreas`
```

- [ ] **Step 8: Verify capability flag cleanup**

Run:

```bash
npm run test:unit -- --runTestsByPath src/utils/__tests__/capability-flags.test.js
npm run test:unit -- --runTestsByPath src/content/__tests__/ContentRecommender.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js src/inspector/__tests__/NavigationRecommendations.test.js src/patterns/__tests__/PatternRecommender.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js
npm run lint:js
npx wp-scripts lint-js tests/e2e/flavor-agent.smoke.spec.js tests/e2e/flavor-agent.docs-grounding-warning.spec.js
```

Expected: capability tests pass. `src/utils/capability-flags.js` has no `canRecommend*` references, while `src/utils/__tests__/capability-flags.test.js` keeps only the single intentional legacy-ignore regression from Step 1.

Run the edited E2E specs when the local browser harness is available:

```bash
npm run test:e2e:playground -- tests/e2e/flavor-agent.smoke.spec.js tests/e2e/flavor-agent.docs-grounding-warning.spec.js
npm run test:e2e:wp70 -- tests/e2e/flavor-agent.smoke.spec.js tests/e2e/flavor-agent.docs-grounding-warning.spec.js
```

Expected: both targeted E2E runs pass. If the browser or WP 7.0 harness is unavailable on the current host, record that as an environment blocker and keep `npm run verify -- --skip-e2e` as the fast aggregate gate.

- [ ] **Step 9: Confirm no runtime contract still depends on legacy flags**

Run:

```bash
rg -n "flavorAgentData[^\\n]*canRecommend|\\.canRecommend(Blocks|Patterns|Content|Templates|TemplateParts|Navigation|GlobalStyles|StyleBook)|['\"]canRecommend(Blocks|Patterns|Content|Templates|TemplateParts|Navigation|GlobalStyles|StyleBook)['\"]|LEGACY_FLAG_KEYS" flavor-agent.php src tests docs CLAUDE.md --glob '!docs/reference/2026-05-19-defensive-fallback-remediation-plan.md' --glob '!src/utils/__tests__/capability-flags.test.js'
```

Expected: no matches in production code, component fixtures, E2E helpers, contributor docs, or `CLAUDE.md`. Local variable names such as `canRecommendBlocks` are allowed only if they are derived from `getSurfaceCapability()` and do not read or document legacy boot-data keys. `src/utils/__tests__/capability-flags.test.js` is excluded because it intentionally contains the one legacy-ignore regression.

---

### Task 4: Remove Hidden Legacy Azure Reasoning-Effort Fallback

**Files:**
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `tests/phpunit/ResponsesClientTest.php`
- Modify: `inc/Admin/Settings/Config.php`
- Modify: `inc/Admin/Settings/Validation.php`
- Modify: `inc/AzureOpenAI/ResponsesClient.php`
- Modify: `docs/reference/provider-precedence.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `docs/flavor-agent-readme.md`

- [ ] **Step 1: Replace settings migration test with an ignore test**

In `tests/phpunit/SettingsTest.php`, replace `test_unposted_reasoning_effort_migrates_saved_legacy_azure_option()` with:

```php
public function test_unposted_reasoning_effort_ignores_saved_legacy_azure_option(): void {
	WordPressTestState::$options = [
		'flavor_agent_azure_reasoning_effort' => 'xhigh',
	];
	$_POST                       = [
		'option_page' => Config::OPTION_GROUP,
	];

	$this->assertSame( 'medium', Settings::sanitize_reasoning_effort( null ) );
}
```

- [ ] **Step 2: Replace runtime legacy fallback test with an ignore test**

In `tests/phpunit/ResponsesClientTest.php`, replace `test_rank_falls_back_to_legacy_azure_reasoning_effort_option_when_neutral_option_is_missing()` with:

```php
public function test_rank_ignores_legacy_azure_reasoning_effort_option_when_neutral_option_is_missing(): void {
	$this->configure_openai_connector();
	WordPressTestState::$options['flavor_agent_azure_reasoning_effort'] = 'xhigh';
	WordPressTestState::$ai_client_generate_text_result                 = '{"explanation":"ok"}';

	ResponsesClient::rank( 'system prompt', 'user prompt' );

	$this->assertSame( 'medium', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
}
```

- [ ] **Step 3: Run the targeted failing PHP tests**

Run:

```bash
composer run test:php -- --filter 'SettingsTest::test_unposted_reasoning_effort_ignores_saved_legacy_azure_option|ResponsesClientTest::test_rank_ignores_legacy_azure_reasoning_effort_option_when_neutral_option_is_missing'
```

Expected: both tests fail because the legacy option still wins.

- [ ] **Step 4: Remove the legacy option constant**

In `inc/Admin/Settings/Config.php`, delete:

```php
public const OPTION_LEGACY_AZURE_REASONING_EFFORT            = 'flavor_agent_azure_reasoning_effort';
```

Keep the literal `flavor_agent_azure_reasoning_effort` in `inc/UninstallOptions.php` so old installs still clean up the obsolete option during uninstall.

- [ ] **Step 5: Remove settings preservation fallback**

In `inc/Admin/Settings/Validation.php`, replace `get_saved_reasoning_effort_value()` with:

```php
private static function get_saved_reasoning_effort_value(): string {
	$saved = self::sanitize_reasoning_effort_value(
		get_option( Config::OPTION_REASONING_EFFORT, '' )
	);

	return $saved ?? 'medium';
}
```

- [ ] **Step 6: Remove runtime fallback in the ranking facade**

In `inc/AzureOpenAI/ResponsesClient.php`, replace `saved_reasoning_effort()` with:

```php
private static function saved_reasoning_effort(): string {
	$saved = self::sanitize_reasoning_effort(
		(string) get_option( Config::OPTION_REASONING_EFFORT, '' )
	);

	return $saved ?? 'medium';
}
```

- [ ] **Step 7: Update reasoning-effort docs**

In `docs/reference/provider-precedence.md`, replace the reasoning-effort paragraph with:

```markdown
`flavor_agent_reasoning_effort` is the neutral Connectors-routed chat preference used when Flavor Agent can express the setting through the selected provider's model configuration. Runtime calls use the explicit request value when supplied, then the saved neutral option, and finally `medium`. The obsolete `flavor_agent_azure_reasoning_effort` option is no longer read as a runtime fallback; it remains listed in uninstall cleanup so older private installs can remove stale data.
```

In `docs/features/settings-backends-and-sync.md` and `docs/flavor-agent-readme.md`, remove claims that valid legacy Azure reasoning-effort values are read as fallback or migration sources. State that `flavor_agent_reasoning_effort` is the only saved runtime option and invalid or missing values resolve to `medium`.

- [ ] **Step 8: Verify reasoning-effort cleanup**

Run:

```bash
composer run test:php -- --filter SettingsTest
composer run test:php -- --filter ResponsesClientTest
rg -n 'OPTION_LEGACY_AZURE_REASONING_EFFORT|legacy `flavor_agent_azure_reasoning_effort`|legacy Azure reasoning|fallback/migration source' inc tests docs --glob '!docs/reference/2026-05-19-defensive-fallback-remediation-plan.md'
```

Expected: tests pass. The `rg` command may still show the literal obsolete option in `inc/UninstallOptions.php` and docs that explicitly describe uninstall cleanup; it should not show runtime reads, test expectations, or docs claiming the legacy value affects behavior.

---

### Task 5: Cross-Finding Verification

**Files:**
- Verify all modified files.

- [ ] **Step 1: Run targeted gates for all touched behavior**

Run:

```bash
composer run test:php -- --filter WordPressAIClientTest
composer run test:php -- --filter AISearchClientTest
composer run test:php -- --filter WordPressDocsAbilitiesTest
composer run test:php -- --filter SettingsTest
composer run test:php -- --filter ResponsesClientTest
npm run test:unit -- --runTestsByPath src/utils/__tests__/capability-flags.test.js
npm run test:unit -- --runTestsByPath src/content/__tests__/ContentRecommender.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js src/inspector/__tests__/NavigationRecommendations.test.js src/patterns/__tests__/PatternRecommender.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js
npx wp-scripts lint-js tests/e2e/flavor-agent.smoke.spec.js tests/e2e/flavor-agent.docs-grounding-warning.spec.js
```

Expected: all targeted tests pass.

- [ ] **Step 2: Run lint and docs checks**

Run:

```bash
composer run lint:php
npm run lint:js
npm run check:docs
git diff --check
```

Expected: all commands exit 0.

- [ ] **Step 3: Run the fast aggregate verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: `VERIFY_RESULT` reports success. If Plugin Check prerequisites are unavailable, rerun:

```bash
npm run verify -- --skip-e2e --skip=lint-plugin
```

Record the skipped `lint-plugin` prerequisite as an environment limitation, not as a code failure.

- [ ] **Step 4: Inspect remaining fallback references**

Run:

```bash
rg -n "provider_fallback|last-known-current|get_last_known_current_guidance_for_grace|LAST_KNOWN_CURRENT_GRACE_TTL|flavorAgentData[^\\n]*canRecommend|\\.canRecommend(Blocks|Patterns|Content|Templates|TemplateParts|Navigation|GlobalStyles|StyleBook)|['\"]canRecommend(Blocks|Patterns|Content|Templates|TemplateParts|Navigation|GlobalStyles|StyleBook)['\"]|OPTION_LEGACY_AZURE_REASONING_EFFORT|flavor_agent_azure_reasoning_effort" inc src tests docs flavor-agent.php CLAUDE.md --glob '!docs/reference/2026-05-19-defensive-fallback-remediation-plan.md'
```

Expected:
- No `model_resolution_failed_provider_fallback` references remain.
- `last-known-current` may remain only as diagnostic runtime state from successful current guidance, not as live-search grace behavior.
- `get_last_known_current_guidance_for_grace` and `LAST_KNOWN_CURRENT_GRACE_TTL` do not remain anywhere outside this plan.
- Legacy `canRecommend*` boot-data keys do not appear in production boot data, component fixtures, E2E helpers, or docs. Local variables with similar names are acceptable only when they derive from `getSurfaceCapability()`. The utility test may keep the one intentional legacy-ignore regression.
- `flavor_agent_azure_reasoning_effort` appears only in uninstall cleanup, targeted ignore-regression test setup values, or docs that clearly identify it as obsolete cleanup data. It must not appear as a runtime read or configurable fallback.

- [ ] **Step 5: Commit-ready review**

Run:

```bash
git status --short
git diff --stat
```

Expected: changes are limited to the files listed in this plan. Do not include generated `build/` or `dist/` artifacts unless a separate release packaging task explicitly asks for them.
