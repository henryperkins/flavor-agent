# Embeddings Provider Configuration Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Make Flavor Agent's embedding runtime, settings UI, tests, and docs consistently reflect a Cloudflare Workers AI-only embedding backend, while fixing the current Workers AI batch-limit risk and preserving deliberate upgrade compatibility.

**Architecture:** Chat remains owned by the WordPress AI Client / `Settings > Connectors`; plugin-owned embeddings remain owned by `Settings > Flavor Agent` and resolve only through Cloudflare Workers AI. Provider-choice state is treated as a legacy compatibility artifact, not as a runtime selector. Embedding requests are capped at the active Workers AI model's documented batch limit before Qdrant indexing sees them.

**Tech Stack:** WordPress plugin PHP, WordPress Settings API, PHPUnit, `@wordpress/scripts`, Cloudflare Workers AI OpenAI-compatible embeddings, Qdrant.

---

## Current Direction

Keep the single-backend design: Cloudflare Workers AI is the only supported first-party plugin-owned embedding backend.

Do not restore OpenAI Native or Azure embedding runtime paths. The current maintained docs already state this contract in `docs/SOURCE_OF_TRUTH.md` and `docs/reference/provider-precedence.md`; the remaining work is to remove stale code/docs and make the implementation less confusing.

External API facts verified on May 5, 2026:

- Cloudflare Workers AI supports OpenAI-compatible `/v1/embeddings` endpoints: https://developers.cloudflare.com/workers-ai/configuration/open-ai-compatibility/
- `@cf/qwen/qwen3-embedding-0.6b` is in the Workers AI model catalog: https://developers.cloudflare.com/workers-ai/models/qwen3-embedding-0.6b/
- The model schema caps array input fields at `maxItems: 32`: https://developers.cloudflare.com/workers-ai/models/qwen3-embedding-0.6b/schema-input.json
- Cloudflare's April 8, 2026 changelog lists Qwen3 embeddings as `1,024` vector dimensions, `4,096` AI Search input tokens, and cosine metric: https://developers.cloudflare.com/changelog/post/2026-04-09-new-workers-ai-models/

## Non-Goals

- Do not add back `openai_native` or `azure_openai` embedding execution.
- Do not add connector-backed embedding execution until WordPress AI / Connectors exposes an embeddings provider contract.
- Do not remove historical option names from uninstall cleanup or migration awareness unless the implementation includes a tested migration path.
- Do not hand-edit generated `build/` or `dist/` files. This plan is PHP/docs/test work unless later UI source changes require `npm run build`.

## File Responsibility Map

- `inc/Cloudflare/WorkersAIEmbeddingConfiguration.php`: source of truth for the Workers AI provider ID, default model, endpoint URL, headers, configured state, and batch-cap constant.
- `inc/OpenAI/Provider.php`: chat runtime facade plus embedding configuration bridge. After cleanup, it should no longer expose provider-choice helpers that imply embeddings are selectable.
- `inc/Admin/Settings/Validation.php`: settings save validation and secret-preservation behavior for Workers AI, Qdrant, and private Cloudflare AI Search.
- `inc/Admin/Settings/Registrar.php`: Settings API registration; add `autoload => false` for secret options and any activation/update fallback required for older WordPress.
- `inc/Admin/Settings/Page.php`: Embedding Model UI; remove the provider hidden input if the provider option is migrated away, or keep it only as an explicitly documented compatibility field.
- `inc/Embeddings/BaseHttpClient.php`, `inc/Embeddings/ConfigurationValidator.php`, `inc/Embeddings/EmbeddingClient.php`, `inc/Embeddings/EmbeddingSignature.php`, `inc/Embeddings/QdrantClient.php`: neutral namespace for embedding/Qdrant utilities.
- `inc/Patterns/PatternIndex.php`: pattern indexing batch producer. It groups Qdrant upsert work separately from the Workers AI request cap enforced by `EmbeddingClient::embed_batch()`.
- `inc/Patterns/Retrieval/QdrantPatternRetrievalBackend.php`: runtime signature check before Qdrant retrieval.
- `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php`: Workers AI embedding configuration/request/legacy-provider tests.
- `tests/phpunit/EmbeddingBackendValidationTest.php`: tests for shared HTTP, embedding, Qdrant, and ranking behavior.
- `tests/phpunit/PatternIndexTest.php`: pattern sync and signature drift coverage.
- `tests/phpunit/SettingsRegistrarTest.php`: Settings API registration assertions.
- `CLAUDE.md`, `docs/SOURCE_OF_TRUTH.md`, `docs/reference/provider-precedence.md`, `docs/features/settings-backends-and-sync.md`, `docs/reference/external-service-disclosure.md`, `docs/flavor-agent-readme.md`, `readme.txt`: docs that must agree on the Workers AI-only embedding model.

## Task 1: Lock The Provider Direction In Tests And Docs

**Files:**
- Modify: `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php`
- Modify: `tests/phpunit/ProviderTest.php`
- Modify: `CLAUDE.md`
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/reference/provider-precedence.md`

- [x] **Step 1: Add a provider contract test**

Add this test to `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php` near the existing provider configuration tests:

```php
public function test_embedding_provider_contract_is_workers_ai_only(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME                        => 'openai_native',
		'flavor_agent_openai_native_api_key'         => 'native-key',
		'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
	];

	$this->assertSame(
		[
			'cloudflare_workers_ai' => 'Cloudflare Workers AI',
		],
		Provider::direct_choices()
	);
	$this->assertSame( 'cloudflare_workers_ai', Provider::get() );
	$this->assertSame( 'cloudflare_workers_ai', Provider::embedding_configuration()['provider'] );
}
```

- [x] **Step 2: Run the focused provider tests**

Run:

```bash
composer test:php -- --filter 'CloudflareWorkersAIEmbeddingTest|ProviderTest'
```

Expected before implementation: the new test passes if the runtime already matches the intended direction; existing tests identify any assumptions that still require connector-choice behavior.

- [x] **Step 3: Update stale docs copy**

Replace the stale `CLAUDE.md` external service rows around the embedding provider selection with this contract:

```markdown
| Provider compatibility option                 | `flavor_agent_openai_provider` is retained only as a legacy compatibility value. Settings saves canonicalize it to `cloudflare_workers_ai`; saved `openai_native`, `azure_openai`, or connector IDs do not select chat or embeddings.                                  |
| Chat (all surfaces)                           | Owned by core `Settings > Connectors` via the WordPress AI Client. No plugin-managed chat credentials.                                                                                                                                                                  |
| Cloudflare Workers AI (embeddings only)       | `flavor_agent_cloudflare_workers_ai_account_id`, `flavor_agent_cloudflare_workers_ai_api_token`, `flavor_agent_cloudflare_workers_ai_embedding_model`                                                                                                                   |
| Qdrant vector DB                              | `flavor_agent_qdrant_url`, `flavor_agent_qdrant_key`                                                                                                                                                                                                                    |
| Private Cloudflare AI Search pattern backend  | `flavor_agent_pattern_retrieval_backend`, `flavor_agent_cloudflare_pattern_ai_search_account_id`, `flavor_agent_cloudflare_pattern_ai_search_namespace`, `flavor_agent_cloudflare_pattern_ai_search_instance_id`, `flavor_agent_cloudflare_pattern_ai_search_api_token` |
| Cloudflare AI Search docs grounding           | `flavor_agent_cloudflare_ai_search_account_id`, `flavor_agent_cloudflare_ai_search_instance_id`, `flavor_agent_cloudflare_ai_search_api_token`, `flavor_agent_cloudflare_ai_search_max_results`                                                                         |
```

Also replace the `CLAUDE.md` architecture bullet that says `AzureOpenAI\` is shared embedding clients for OpenAI Native / Workers AI / legacy Azure with text that says the namespace is legacy-named and scheduled for neutral rename:

```markdown
- `OpenAI\Provider`, legacy-named `AzureOpenAI\` embedding/Qdrant utilities, and `Cloudflare\` Workers AI / AI Search clients — chat routing, plugin-owned Workers AI embeddings, Qdrant vector storage, Cloudflare AI Search docs grounding, private pattern search, and embedding signature cache invalidation. Workers AI is the only first-party embedding backend.
```

- [x] **Step 4: Run docs checks**

Run:

```bash
npm run check:docs
git diff --check -- CLAUDE.md docs/SOURCE_OF_TRUTH.md docs/reference/provider-precedence.md
```

Expected: docs check passes, and `git diff --check` prints no whitespace errors.

## Task 2: Remove Vestigial Provider-Choice Plumbing Without Breaking Compatibility

**Files:**
- Modify: `inc/OpenAI/Provider.php`
- Modify: `inc/Admin/Settings/Validation.php`
- Modify: `inc/Admin/Settings/Page.php`
- Modify: `inc/Admin/Settings/Registrar.php`
- Modify: `tests/phpunit/ProviderTest.php`
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `tests/phpunit/SettingsRegistrarTest.php`

- [x] **Step 1: Decide the compatibility option shape**

Use this rule in implementation:

```text
Keep Provider::OPTION_NAME registered for one release cycle as a canonicalized compatibility option.
Do not use Provider::OPTION_NAME to branch runtime chat or embedding behavior.
Keep uninstall cleanup for old provider values.
Remove connector-choice UI/helper paths that suggest the option is selectable.
```

- [x] **Step 2: Write failing cleanup tests**

Add or update tests so these contracts are explicit:

```php
public function test_provider_choices_do_not_include_connectors_for_embedding_selection(): void {
	WordPressTestState::$connectors = [
		'anthropic' => [
			'name'           => 'Anthropic',
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

	$this->assertSame(
		[
			'cloudflare_workers_ai' => 'Cloudflare Workers AI',
		],
		Provider::choices( 'anthropic' )
	);
}
```

```php
public function test_workers_ai_submission_validation_no_longer_checks_submitted_provider_choice(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME                          => 'anthropic',
		'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
		'flavor_agent_cloudflare_workers_ai_api_token'  => 'token-old',
		'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
	];

	$_POST['option_page'] = Config::OPTION_GROUP;
	$_POST['_wpnonce']    = wp_create_nonce( Config::OPTION_GROUP . '-options' );
	$_POST['flavor_agent_cloudflare_workers_ai_account_id'] = 'account-123';
	$_POST['flavor_agent_cloudflare_workers_ai_api_token'] = 'token-new';
	$_POST['flavor_agent_cloudflare_workers_ai_embedding_model'] = '@cf/qwen/qwen3-embedding-0.6b';

	WordPressTestState::$remote_post_response = [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode(
			[
				'data' => [
					[ 'embedding' => [ 0.1, 0.2, 0.3 ] ],
				],
			]
		),
	];

	$result = Settings::sanitize_cloudflare_workers_ai_api_token( 'token-new' );

	$this->assertSame( 'token-new', $result );
	$this->assertSame(
		'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings',
		WordPressTestState::$last_remote_post['url']
	);
}
```

- [x] **Step 3: Simplify `Provider` choices**

Change `Provider::choices()` to return only `direct_choices()`. Delete `selectable_connector_choices()` and any tests that require connector labels inside embedding provider choices.

```php
public static function choices( ?string $selected_provider = null ): array {
	unset( $selected_provider );

	return self::direct_choices();
}
```

- [x] **Step 4: Remove unreachable header helpers**

Delete these methods from `inc/OpenAI/Provider.php` if `rg "azure_headers|native_headers" inc tests` confirms no callers:

```php
private static function azure_headers( string $api_key ): array
private static function native_headers( string $api_key ): array
```

- [x] **Step 5: Inline direct-provider validation gate**

In `inc/Admin/Settings/Validation.php`, remove `get_submitted_openai_provider()` and `should_validate_direct_provider_submission()`. In `resolve_workers_ai_submission_values()`, replace the provider gate with direct validation of the Workers AI field set:

```php
if ( ! self::should_validate_submission() ) {
	return $values;
}

if (
	'' === $values['flavor_agent_cloudflare_workers_ai_account_id'] ||
	'' === $values['flavor_agent_cloudflare_workers_ai_api_token']
) {
	return $values;
}
```

- [x] **Step 6: Keep or migrate the hidden field deliberately**

If keeping `Provider::OPTION_NAME` for one release cycle, keep the hidden field in `inc/Admin/Settings/Page.php` and update the nearby docs/tests to call it a compatibility canonicalization field. If removing the registered setting now, delete this field:

```php
<input type="hidden" name="<?php echo esc_attr( Provider::OPTION_NAME ); ?>" value="<?php echo esc_attr( WorkersAIEmbeddingConfiguration::PROVIDER ); ?>" />
```

When removing the setting, add an activation/admin migration that calls:

```php
delete_option( Provider::OPTION_NAME );
```

Only choose removal if tests cover existing old saved values no longer affecting chat or embeddings before deletion.

- [x] **Step 7: Run focused settings tests**

Run:

```bash
composer test:php -- --filter 'ProviderTest|SettingsTest|SettingsRegistrarTest|CloudflareWorkersAIEmbeddingTest'
```

Expected: all focused tests pass.

## Task 3: Fix Workers AI Embedding Batch Limits

**Files:**
- Modify: `inc/Cloudflare/WorkersAIEmbeddingConfiguration.php`
- Modify: `inc/Embeddings/EmbeddingClient.php`
- Modify: `inc/Patterns/PatternIndex.php`
- Modify: `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php`
- Modify: `tests/phpunit/PatternIndexTest.php`

- [x] **Step 1: Add a Workers AI batch cap constant**

Add this constant to `inc/Cloudflare/WorkersAIEmbeddingConfiguration.php`:

```php
public const MAX_BATCH_INPUTS = 32;
```

- [x] **Step 2: Write failing batch chunking test**

Add this test to `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php`:

```php
public function test_embed_batch_chunks_workers_ai_requests_at_model_limit(): void {
	WordPressTestState::$options = [
		Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
		'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
		'flavor_agent_cloudflare_workers_ai_api_token'  => 'token-xyz',
		'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
	];

	$inputs = array_map(
		static fn ( int $index ): string => 'pattern text ' . $index,
		range( 1, 65 )
	);

	foreach ( [ 32, 32, 1 ] as $count ) {
		WordPressTestState::$remote_post_responses[] = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'data' => array_map(
						static fn ( int $index ): array => [ 'embedding' => [ (float) $index, (float) ( $index + 1 ) ] ],
						range( 1, $count )
					),
				]
			),
		];
	}

	$result = EmbeddingClient::embed_batch( $inputs );

	$this->assertCount( 65, $result );
	$this->assertCount( 3, WordPressTestState::$remote_post_calls );
	$first_body  = json_decode( WordPressTestState::$remote_post_calls[0]['args']['body'], true );
	$second_body = json_decode( WordPressTestState::$remote_post_calls[1]['args']['body'], true );
	$third_body  = json_decode( WordPressTestState::$remote_post_calls[2]['args']['body'], true );
	$this->assertCount( 32, $first_body['input'] );
	$this->assertCount( 32, $second_body['input'] );
	$this->assertCount( 1, $third_body['input'] );
}
```

- [x] **Step 3: Implement chunking in `EmbeddingClient::embed_batch()`**

Replace the single-request body construction with chunked requests:

```php
$vectors = [];

foreach ( array_chunk( $inputs, WorkersAIEmbeddingConfiguration::MAX_BATCH_INPUTS ) as $input_chunk ) {
	$body = wp_json_encode(
		[
			'model' => $config['model'],
			'input' => $input_chunk,
		]
	);

	$data = self::request( $config['url'], $config['headers'], $body, $config['label'] );
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$chunk_vectors = self::extract_vectors_from_response( $data );
	if ( is_wp_error( $chunk_vectors ) ) {
		return $chunk_vectors;
	}

	$vectors = array_merge( $vectors, $chunk_vectors );
}

return $vectors;
```

Add this import to the top of `EmbeddingClient.php`:

```php
use FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration;
```

- [x] **Step 4: Decide whether `PatternIndex::BATCH_SIZE` remains a Qdrant cap**

Keep `PatternIndex::BATCH_SIZE = 100` for Qdrant upserts if desired, because `EmbeddingClient` now enforces the Workers AI cap internally. Use this comment in `EmbeddingClient`:

```php
 * Embed multiple inputs, chunked to the Workers AI model request limit.
```

- [x] **Step 5: Run embedding and pattern sync tests**

Run:

```bash
composer test:php -- --filter 'CloudflareWorkersAIEmbeddingTest|EmbeddingBackendValidationTest|PatternIndexTest'
```

Expected: all focused tests pass, and the new chunking test records three remote calls for 65 inputs.

## Task 4: Rename Legacy Azure-Named Embedding Utilities In A Staged Way

**Files:**
- Create: `inc/Embeddings/BaseHttpClient.php`
- Create: `inc/Embeddings/ConfigurationValidator.php`
- Create: `inc/Embeddings/EmbeddingClient.php`
- Create: `inc/Embeddings/EmbeddingSignature.php`
- Create: `inc/Embeddings/QdrantClient.php`
- Modify: imports in `inc/Patterns/PatternIndex.php`
- Modify: imports in `inc/Patterns/Retrieval/QdrantPatternRetrievalBackend.php`
- Modify: imports in `inc/Admin/Settings/Validation.php`
- Modify: imports in affected tests
- Modify: `composer.json` only if PSR-4 rules are narrower than `FlavorAgent\\ => inc/`

- [x] **Step 1: Confirm namespace loading**

Run:

```bash
composer dump-autoload --dry-run
```

Expected: Composer can generate autoload metadata. If `composer dump-autoload --dry-run` is unsupported by the installed Composer version, run `composer dump-autoload --no-scripts` instead and confirm exit code `0`.

- [x] **Step 2: Move classes mechanically**

Move the embedding/Qdrant utility classes from `FlavorAgent\AzureOpenAI` to `FlavorAgent\Embeddings`:

```php
namespace FlavorAgent\Embeddings;
```

Preserve class names for the first pass: `EmbeddingClient`, `EmbeddingSignature`, `QdrantClient`, `BaseHttpClient`, and `ConfigurationValidator`.

- [x] **Step 3: Update imports**

Replace imports like:

```php
use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\EmbeddingSignature;
use FlavorAgent\AzureOpenAI\QdrantClient;
```

with:

```php
use FlavorAgent\Embeddings\EmbeddingClient;
use FlavorAgent\Embeddings\EmbeddingSignature;
use FlavorAgent\Embeddings\QdrantClient;
```

- [x] **Step 4: Rename legacy test file or class**

Confirm the backend validation test file is named `tests/phpunit/EmbeddingBackendValidationTest.php` and the class name is:

```php
final class EmbeddingBackendValidationTest extends TestCase {
```

Keep test method names that describe behavior, and rename only methods that explicitly imply Azure is still a runtime backend.

- [x] **Step 5: Run namespace-focused tests**

Run:

```bash
composer test:php -- --filter 'EmbeddingBackendValidationTest|CloudflareWorkersAIEmbeddingTest|PatternIndexTest|PatternAbilitiesTest'
```

Expected: all focused tests pass without references to `FlavorAgent\AzureOpenAI` in runtime imports.

- [x] **Step 6: Search for stale namespace references**

Run:

```bash
rg -n "AzureOpenAI|azure_openai|OpenAI Native|openai_native" inc tests CLAUDE.md docs readme.txt
```

Expected: remaining matches are limited to migration/uninstall docs, legacy option cleanup tests, or explicit historical context. Runtime class imports should use `FlavorAgent\Embeddings`.

## Task 5: Add Secret Option Non-Autoloading

**Files:**
- Modify: `inc/Admin/Settings/Registrar.php`
- Modify: `inc/Admin/Settings/Validation.php`
- Modify: `flavor-agent.php` or the existing lifecycle/migration owner if activation hooks already delegate elsewhere
- Modify: `tests/phpunit/SettingsRegistrarTest.php`
- Modify: `tests/phpunit/PluginLifecycleTest.php`
- Modify: `tests/phpunit/bootstrap.php` if the WordPress option stubs need to track autoload metadata

- [x] **Step 1: Add Settings API autoload assertions**

Extend `tests/phpunit/SettingsRegistrarTest.php` with explicit assertions:

```php
public function test_secret_settings_are_registered_without_autoload(): void {
	Registrar::register_settings();

	$settings = $GLOBALS['wp_registered_settings'];
	$secret_options = [
		'flavor_agent_cloudflare_workers_ai_api_token',
		'flavor_agent_qdrant_key',
		Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN,
	];

	foreach ( $secret_options as $option_name ) {
		$this->assertArrayHasKey( $option_name, $settings );
		$this->assertFalse( $settings[ $option_name ]['autoload'] ?? true );
	}
}
```

- [x] **Step 2: Register secret options with `autoload => false`**

Add this argument to each secret `register_setting()` call in `inc/Admin/Settings/Registrar.php`:

```php
'autoload' => false,
```

Apply it to:

```php
'flavor_agent_cloudflare_workers_ai_api_token'
'flavor_agent_qdrant_key'
Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN
```

- [x] **Step 3: Preserve non-autoloading on direct secret updates**

If any code writes secrets with `update_option()`, use:

```php
update_option( $option_name, $value, false );
```

If current writes happen only through Settings API sanitizers, add lifecycle coverage that ensures missing secret options are created with non-autoload behavior on activation:

```php
add_option( 'flavor_agent_cloudflare_workers_ai_api_token', '', '', 'no' );
add_option( 'flavor_agent_qdrant_key', '', '', 'no' );
add_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN, '', '', 'no' );
```

- [x] **Step 4: Update test stubs if needed**

If `tests/phpunit/bootstrap.php` does not track autoload metadata, extend `WordPressTestState` and the `add_option()` / `update_option()` stubs to record the `$autoload` argument. Keep the existing `update_option()` return behavior:

```php
WordPressTestState::$option_autoload[ $name ] = $autoload;
```

- [x] **Step 5: Run settings/lifecycle tests**

Run:

```bash
composer test:php -- --filter 'SettingsRegistrarTest|PluginLifecycleTest|UninstallTest'
```

Expected: secret settings assert `autoload` false and lifecycle cleanup remains unchanged.

## Task 6: Make Embedding Signature Intent Explicit

**Files:**
- Modify: `inc/Embeddings/EmbeddingSignature.php` or `inc/AzureOpenAI/EmbeddingSignature.php` if Task 4 has not landed yet
- Modify: `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php`
- Modify: `tests/phpunit/PatternIndexTest.php`

- [x] **Step 1: Add a comment documenting account omission**

Add this comment above the payload in `EmbeddingSignature::from_configuration()`:

```php
// The signature intentionally tracks vector compatibility, not credential ownership.
// Workers AI vectors are model/dimension compatible across accounts. Include account
// or API base here only if Flavor Agent adds account-scoped custom embedding models.
```

- [x] **Step 2: Add an invariant test**

Add this test to `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php`:

```php
public function test_embedding_signature_ignores_account_id_for_workers_ai_vector_compatibility(): void {
	$first = EmbeddingClient::build_signature_for_dimension(
		1024,
		[
			'provider' => 'cloudflare_workers_ai',
			'model'    => '@cf/qwen/qwen3-embedding-0.6b',
			'endpoint' => 'https://api.cloudflare.com/client/v4/accounts/account-one/ai/v1',
		]
	);
	$second = EmbeddingClient::build_signature_for_dimension(
		1024,
		[
			'provider' => 'cloudflare_workers_ai',
			'model'    => '@cf/qwen/qwen3-embedding-0.6b',
			'endpoint' => 'https://api.cloudflare.com/client/v4/accounts/account-two/ai/v1',
		]
	);

	$this->assertSame( $first['signature_hash'], $second['signature_hash'] );
}
```

- [x] **Step 3: Run signature tests**

Run:

```bash
composer test:php -- --filter 'CloudflareWorkersAIEmbeddingTest|PatternIndexTest'
```

Expected: signature behavior is explicit and stable.

## Task 7: Simplify Validation Messaging

**Files:**
- Modify: `inc/Embeddings/EmbeddingClient.php`
- Modify: `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php`
- Modify: `tests/phpunit/EmbeddingBackendValidationTest.php`

- [x] **Step 1: Replace dynamic missing-credentials label**

Replace both missing credential messages in `EmbeddingClient` with a literal Workers AI message:

```php
return new \WP_Error(
	'missing_credentials',
	'Cloudflare Workers AI embedding credentials are not configured. Go to Settings > Flavor Agent.',
	[ 'status' => 400 ]
);
```

- [x] **Step 2: Update tests**

Keep the existing assertion that missing connector-backed embedding validation mentions Workers AI:

```php
$this->assertStringContainsString( 'Cloudflare Workers AI', $result->get_error_message() );
```

Add this exact message assertion:

```php
$this->assertSame(
	'Cloudflare Workers AI embedding credentials are not configured. Go to Settings > Flavor Agent.',
	$result->get_error_message()
);
```

- [x] **Step 3: Run focused embedding tests**

Run:

```bash
composer test:php -- --filter 'CloudflareWorkersAIEmbeddingTest|EmbeddingBackendValidationTest'
```

Expected: the active renamed test class passes.

## Task 8: Final Documentation Sweep

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/reference/provider-precedence.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `docs/reference/external-service-disclosure.md`
- Modify: `docs/flavor-agent-readme.md`
- Modify: `readme.txt`

- [x] **Step 1: Search for stale multi-backend claims**

Run:

```bash
rg -n "openai_native|azure_openai|OpenAI Native|Azure OpenAI|Embeddings provider selection|provider selector|connector.*embedding|fallback embedding" CLAUDE.md docs readme.txt
```

Expected: any remaining hits describe historical saved values, uninstall cleanup, or migration compatibility. No hit should claim OpenAI Native, Azure OpenAI, or connector IDs are live embedding backends.

- [x] **Step 2: Normalize the docs language**

Use these exact concepts across docs:

```text
Settings > Connectors owns text generation.
Settings > Flavor Agent owns one plugin-managed Embedding Model.
Cloudflare Workers AI is the only first-party plugin-owned embedding backend.
Qdrant uses the Embedding Model; Cloudflare AI Search pattern storage uses Cloudflare-managed indexing/search.
Saved legacy provider values do not select chat or embeddings.
```

- [x] **Step 3: Confirm external-service disclosures**

Ensure `docs/reference/external-service-disclosure.md`, `docs/flavor-agent-readme.md`, and `readme.txt` disclose:

```text
Cloudflare Workers AI receives validation probe text, pattern index probe text, pattern text, pattern metadata included in embedding text, and Qdrant pattern-search query text when Qdrant pattern storage is used.
```

Do not say Workers AI receives pattern recommendation traffic when the Cloudflare AI Search pattern backend is selected; that backend bypasses plugin-owned embeddings.

- [x] **Step 4: Run docs checks**

Run:

```bash
npm run check:docs
git diff --check -- CLAUDE.md docs readme.txt
```

Expected: docs check passes and no whitespace errors are reported.

## Task 9: Full Verification Gate

**Files:**
- No source edits unless a verification failure identifies a specific defect.

- [x] **Step 1: Run focused PHP test ladder**

Run:

```bash
composer test:php -- --filter 'CloudflareWorkersAIEmbeddingTest|EmbeddingBackendValidationTest|PatternIndexTest|ProviderTest|SettingsTest|SettingsRegistrarTest|PluginLifecycleTest|UninstallTest'
```

Expected: all focused tests pass.

- [x] **Step 2: Run JS/docs/build gates only if touched**

Run these if docs or JS/admin assets changed:

```bash
npm run check:docs
npm run build
npm run test:unit
```

Expected: each command exits `0`.

- [x] **Step 3: Run aggregate non-E2E verify**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: final output includes a `VERIFY_RESULT=` JSON line with a JSON object payload. If plugin-check prerequisites are unavailable, rerun intentionally with:

```bash
npm run verify -- --skip=lint-plugin --skip-e2e
```

Record the incomplete prerequisite in the implementation notes rather than treating it as a silent pass.

- [x] **Step 4: Record release evidence**

Update the PR or implementation summary with:

```text
Focused PHP tests:
- composer test:php -- --filter 'CloudflareWorkersAIEmbeddingTest|EmbeddingBackendValidationTest|PatternIndexTest|ProviderTest|SettingsTest|SettingsRegistrarTest|PluginLifecycleTest|UninstallTest'

Docs:
- npm run check:docs
- git diff --check -- CLAUDE.md docs readme.txt

Aggregate:
- npm run verify -- --skip-e2e
```

Include any intentional waiver for browser E2E or plugin-check prerequisites.

## Completion Criteria

- Runtime embeddings always resolve through Cloudflare Workers AI.
- Saved `openai_native`, `azure_openai`, and connector provider values no longer imply selectable embedding backends in code, UI, or docs.
- `EmbeddingClient::embed_batch()` never sends more than 32 inputs in one Workers AI request for the Qwen3 default model.
- Secret options are registered and initialized without autoloading.
- The legacy Azure namespace no longer appears in runtime embedding/Qdrant imports, or the remaining references are compatibility wrappers scheduled for deletion.
- `CLAUDE.md`, `docs/SOURCE_OF_TRUTH.md`, `docs/reference/provider-precedence.md`, feature docs, and readme surfaces describe the same embedding contract.
- Focused PHPUnit tests, docs checks, and the non-E2E verify gate have passing or explicitly waived evidence.
