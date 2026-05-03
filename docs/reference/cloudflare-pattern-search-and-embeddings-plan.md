# Cloudflare Pattern Search And Embeddings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Cloudflare as a first-class pattern recommendation backend by supporting Workers AI embeddings for the existing Qdrant path and Cloudflare AI Search as a managed pattern retrieval/index path.

**Architecture:** Keep the current docs-grounding `AISearchClient` isolated to trusted `developer.wordpress.org` guidance. Add a separate Workers AI embedding provider that uses Cloudflare's OpenAI-compatible `/ai/v1/embeddings` endpoint, then add a separate private pattern AI Search client for indexing and searching site pattern candidates. Pattern recommendations keep the existing inserter-only, browse/rank-only contract: `visiblePatternNames` remains authoritative, synced patterns are rehydrated from current readable posts, and final ranking still routes through `ResponsesClient` until a measured replacement is justified.

**Tech Stack:** WordPress PHP, WordPress HTTP API, WordPress Settings API, Cloudflare Workers AI, Cloudflare AI Search REST API, Qdrant, PHPUnit, `@wordpress/scripts` unit tests, Playwright, `scripts/verify.js`.

---

## Findings Addressed

- Cloudflare AI Search is a managed retrieval/index service, not a raw drop-in embedding endpoint for `text-embedding-3-large` or `text-embedding-3-small`.
- Raw embedding replacement should use Workers AI's OpenAI-compatible embeddings endpoint, which can fit behind the existing `EmbeddingClient` response parser.
- Current pattern recommendations require `Provider::embedding_configured()` plus Qdrant; this blocks Cloudflare-only retrieval unless backend readiness is split by selected pattern backend.
- Existing `FlavorAgent\Cloudflare\AISearchClient` validates trusted WordPress developer docs and must not be reused for private site pattern content.
- Pattern recommendations must preserve the current `visiblePatternNames`, renderable-results, synced-pattern `read_post`, and ranking-only inserter behavior.
- External-service disclosures, settings ownership labels, and validation gates must be updated because this sends pattern text and recommendation queries to new Cloudflare endpoints.

## Cloudflare References Used

- Workers AI OpenAI-compatible endpoints: `https://developers.cloudflare.com/workers-ai/configuration/open-ai-compatibility/`
- AI Search overview: `https://developers.cloudflare.com/ai-search/`
- AI Search REST API and search response shape: `https://developers.cloudflare.com/ai-search/api/search/rest-api/`
- AI Search result filtering and per-request retrieval options: `https://developers.cloudflare.com/ai-search/configuration/retrieval/result-filtering/`
- AI Search custom metadata: `https://developers.cloudflare.com/ai-search/configuration/indexing/metadata/`
- AI Search model update, April 8, 2026: `https://developers.cloudflare.com/changelog/post/2026-04-09-new-workers-ai-models/`

## Product Decisions

1. **Workers AI embeddings are provider-level.**
   They extend the existing plugin-owned embedding runtime and continue to use Qdrant.

2. **AI Search pattern retrieval is backend-level.**
   It can replace Qdrant retrieval for pattern recommendations, but it owns indexing and search. It does not expose raw vectors to `PatternAbilities`.

3. **Default rollout order is Workers AI first, AI Search second.**
   Workers AI embeddings are smaller and de-risk Cloudflare credentials, validation, settings, state signatures, and docs. AI Search retrieval then changes indexing/search behavior with a working Cloudflare credential path already in place.

4. **Keep Qdrant as the default pattern backend until AI Search parity is proven.**
   Add an explicit pattern retrieval backend setting. Do not silently migrate existing sites from Qdrant to AI Search.

5. **Use `@cf/qwen/qwen3-embedding-0.6b` as the recommended Workers AI embedding model.**
   Cloudflare's April 2026 docs list it as 1024 dimensions with a 4096-token input window. `@cf/google/embeddinggemma-300m` remains a lower-latency option for compact pattern summaries.

6. **Use a private AI Search instance for site pattern content.**
   The built-in public docs endpoint remains only for docs grounding. Pattern content must require site-owner Cloudflare credentials and must never use the public docs endpoint.

## File Structure

### New Files

- `inc/Cloudflare/WorkersAIEmbeddingConfiguration.php`
  - Normalizes Workers AI account ID, API token, model, endpoint, labels, and headers.
- `inc/Cloudflare/PatternSearchClient.php`
  - Private AI Search client for pattern indexing/search. Handles namespaced instance URLs, multipart item upload, item delete, search, response normalization, and validation.
- `inc/Patterns/Retrieval/PatternRetrievalBackend.php`
  - Interface for pattern retrieval backends.
- `inc/Patterns/Retrieval/QdrantPatternRetrievalBackend.php`
  - Adapter around current `EmbeddingClient` plus `QdrantClient` search behavior.
- `inc/Patterns/Retrieval/CloudflareAISearchPatternRetrievalBackend.php`
  - Adapter around `PatternSearchClient::search_patterns()`.
- `inc/Patterns/Retrieval/PatternRetrievalBackendFactory.php`
  - Resolves the configured backend and validates runtime readiness.
- `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php`
  - Provider config, validation, and embedding parser tests for Workers AI.
- `tests/phpunit/CloudflarePatternSearchClientTest.php`
  - AI Search URL, request, upload, delete, search, and normalization tests.

### Modified Files

- `flavor-agent.php`
  - Register dependency-change hooks for new Cloudflare pattern and embedding options.
- `inc/OpenAI/Provider.php`
  - Add `cloudflare_workers_ai` as a direct embedding provider.
- `inc/AzureOpenAI/EmbeddingClient.php`
  - Keep response parsing generic and update labels/comments that currently imply Azure/OpenAI only.
- `inc/AzureOpenAI/EmbeddingSignature.php`
  - No algorithm change expected; tests must prove Cloudflare provider/model/dimension change the signature.
- `inc/Patterns/PatternIndex.php`
  - Split sync into Qdrant and AI Search backend flows; persist backend identity and Cloudflare AI Search signature fields.
- `inc/Abilities/PatternAbilities.php`
  - Replace direct Qdrant retrieval calls with `PatternRetrievalBackendFactory`.
- `inc/Abilities/SurfaceCapabilities.php`
  - Report pattern readiness for either Qdrant or AI Search backend.
- `inc/Admin/Settings/Config.php`
  - Add option names and grouping for Workers AI embeddings and pattern retrieval backend.
- `inc/Admin/Settings/Fields.php`
  - Add fields for Workers AI and pattern AI Search.
- `inc/Admin/Settings/Page.php`
  - Render the new settings with clear ownership labels and prerequisite copy.
- `inc/Admin/Settings/State.php`
  - Include selected pattern backend, Workers AI readiness, and AI Search pattern readiness.
- `inc/Admin/Settings/Validation.php`
  - Validate Workers AI embedding settings and private pattern AI Search settings.
- `inc/Admin/Settings/Feedback.php`
  - Add validation and changed-option feedback messages.
- `src/admin/settings-page-controller.js`
  - Update prerequisite and status copy.
- `src/admin/__tests__/settings-page-controller.test.js`
  - Add settings state tests for Workers AI and AI Search pattern backend.
- `tests/phpunit/SettingsTest.php`
  - Cover fields, validation, status messages, and schedule behavior.
- `tests/phpunit/PatternIndexTest.php`
  - Cover backend-specific sync behavior and stale state transitions.
- `tests/phpunit/PatternAbilitiesTest.php`
  - Cover AI Search retrieval, visible scope, synced rehydration, and no private payload leaks.
- `tests/phpunit/AgentControllerTest.php`
  - Cover REST response metadata and unavailable states for the new backend.
- `tests/phpunit/PluginLifecycleTest.php`
  - Cover dependency hooks and cron scheduling gates.
- `docs/features/pattern-recommendations.md`
  - Document both retrieval backends.
- `docs/features/settings-backends-and-sync.md`
  - Document new setup and sync behavior.
- `docs/reference/abilities-and-routes.md`
  - Update pattern prerequisites and route behavior.
- `docs/reference/provider-precedence.md`
  - Update provider/backends matrix.
- `docs/reference/external-service-disclosure.md`
  - Add Workers AI embeddings and Cloudflare AI Search pattern indexing/search.
- `docs/reference/pattern-recommendation-debugging.md`
  - Add backend-specific debugging paths.
- `docs/reference/cross-surface-validation-gates.md`
  - Update Gate 2 examples if needed.
- `docs/reference/local-environment-setup.md`
  - Add local setup notes.
- `readme.txt`
  - Update external service disclosure and setup copy.

---

## Phase 1: Workers AI Embeddings For The Existing Qdrant Path

### Task 1: Add Workers AI Embedding Provider Contract

**Files:**
- Create: `inc/Cloudflare/WorkersAIEmbeddingConfiguration.php`
- Modify: `inc/OpenAI/Provider.php`
- Test: `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php`

- [ ] **Step 1: Add failing provider configuration tests**

Create `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php` with tests that assert:

```php
public function test_provider_builds_workers_ai_embedding_configuration(): void {
	WordPressTestState::$options['flavor_agent_openai_provider'] = 'cloudflare_workers_ai';
	WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_account_id'] = 'account-123';
	WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_api_token'] = 'token-xyz';
	WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_embedding_model'] = '@cf/qwen/qwen3-embedding-0.6b';

	$config = Provider::embedding_configuration();

	$this->assertSame( 'cloudflare_workers_ai', $config['provider'] );
	$this->assertSame( 'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings', $config['url'] );
	$this->assertSame( '@cf/qwen/qwen3-embedding-0.6b', $config['model'] );
	$this->assertTrue( $config['configured'] );
	$this->assertSame( 'Bearer token-xyz', $config['headers']['Authorization'] );
}

public function test_workers_ai_embedding_signature_includes_provider_model_and_dimension(): void {
	$config = [
		'provider' => 'cloudflare_workers_ai',
		'model'    => '@cf/qwen/qwen3-embedding-0.6b',
	];

	$signature = EmbeddingClient::build_signature_for_dimension( 1024, $config );

	$this->assertSame( 'cloudflare_workers_ai', $signature['provider'] );
	$this->assertSame( '@cf/qwen/qwen3-embedding-0.6b', $signature['model'] );
	$this->assertSame( 1024, $signature['dimension'] );
	$this->assertNotSame( '', $signature['signature_hash'] );
}
```

- [ ] **Step 2: Run the failing tests**

Run:

```bash
composer run test:php -- --filter CloudflareWorkersAIEmbeddingTest
```

Expected: fails because `cloudflare_workers_ai` is not a known provider and the configuration class does not exist.

- [ ] **Step 3: Implement Workers AI configuration**

Add `inc/Cloudflare/WorkersAIEmbeddingConfiguration.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

final class WorkersAIEmbeddingConfiguration {

	public const PROVIDER = 'cloudflare_workers_ai';
	public const DEFAULT_MODEL = '@cf/qwen/qwen3-embedding-0.6b';

	/**
	 * @param array<string, string> $overrides
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	public static function get( array $overrides = [] ): array {
		$account_id = self::option_value( $overrides, 'flavor_agent_cloudflare_workers_ai_account_id' );
		$api_token  = self::option_value( $overrides, 'flavor_agent_cloudflare_workers_ai_api_token' );
		$model      = self::option_value( $overrides, 'flavor_agent_cloudflare_workers_ai_embedding_model' );

		if ( '' === $model ) {
			$model = self::DEFAULT_MODEL;
		}

		$endpoint = '' !== $account_id
			? sprintf( 'https://api.cloudflare.com/client/v4/accounts/%s/ai/v1', rawurlencode( $account_id ) )
			: '';

		return [
			'provider'   => self::PROVIDER,
			'endpoint'   => $endpoint,
			'api_key'    => $api_token,
			'model'      => $model,
			'configured' => '' !== $account_id && '' !== $api_token && '' !== $model,
			'headers'    => [
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			],
			'url'        => '' !== $endpoint ? $endpoint . '/embeddings' : '',
			'label'      => 'Cloudflare Workers AI embeddings',
		];
	}

	/**
	 * @param array<string, string> $overrides
	 */
	private static function option_value( array $overrides, string $option ): string {
		if ( array_key_exists( $option, $overrides ) ) {
			return trim( sanitize_text_field( (string) $overrides[ $option ] ) );
		}

		return trim( sanitize_text_field( (string) get_option( $option, '' ) ) );
	}
}
```

Modify `inc/OpenAI/Provider.php`:

```php
use FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration;
```

Add the provider to direct choices:

```php
public static function direct_choices(): array {
	return [
		self::AZURE                         => 'Azure OpenAI',
		self::NATIVE                        => 'OpenAI Native',
		WorkersAIEmbeddingConfiguration::PROVIDER => 'Cloudflare Workers AI',
	];
}
```

Add the branch in `embedding_configuration()` before the Azure fallback:

```php
if ( WorkersAIEmbeddingConfiguration::PROVIDER === $provider ) {
	return WorkersAIEmbeddingConfiguration::get( $overrides );
}
```

- [ ] **Step 4: Run the provider tests**

Run:

```bash
composer run test:php -- --filter CloudflareWorkersAIEmbeddingTest
```

Expected: pass.

### Task 2: Validate Workers AI Embedding Calls

**Files:**
- Modify: `inc/AzureOpenAI/EmbeddingClient.php`
- Modify: `inc/Admin/Settings/Validation.php`
- Test: `tests/phpunit/CloudflareWorkersAIEmbeddingTest.php`

- [ ] **Step 1: Add failing HTTP validation and batch embedding tests**

Append tests that seed a Workers AI OpenAI-compatible response:

```php
public function test_workers_ai_embedding_validation_uses_openai_compatible_endpoint(): void {
	WordPressTestState::$options['flavor_agent_openai_provider'] = 'cloudflare_workers_ai';
	WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_account_id'] = 'account-123';
	WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_api_token'] = 'token-xyz';
	WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_embedding_model'] = '@cf/qwen/qwen3-embedding-0.6b';
	WordPressTestState::$remote_post_responses[] = [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode(
			[
				'data' => [
					[ 'embedding' => [ 0.1, 0.2, 0.3 ] ],
				],
			]
		),
	];

	$result = EmbeddingClient::validate_configuration( null, null, null, 'cloudflare_workers_ai' );

	$this->assertSame( 3, $result['dimension'] );
	$this->assertSame(
		'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings',
		WordPressTestState::$remote_post_calls[0]['url']
	);
	$this->assertSame( 'Bearer token-xyz', WordPressTestState::$remote_post_calls[0]['args']['headers']['Authorization'] );
}

public function test_workers_ai_embedding_batch_reuses_existing_vector_parser(): void {
	WordPressTestState::$options['flavor_agent_openai_provider'] = 'cloudflare_workers_ai';
	WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_account_id'] = 'account-123';
	WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_api_token'] = 'token-xyz';
	WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_embedding_model'] = '@cf/qwen/qwen3-embedding-0.6b';
	WordPressTestState::$remote_post_responses[] = [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode(
			[
				'data' => [
					[ 'embedding' => [ 0.1, 0.2 ] ],
					[ 'embedding' => [ 0.3, 0.4 ] ],
				],
			]
		),
	];

	$result = EmbeddingClient::embed_batch( [ 'alpha', 'beta' ] );

	$this->assertSame( [ [ 0.1, 0.2 ], [ 0.3, 0.4 ] ], $result );
	$this->assertStringContainsString( '"model":"@cf\/qwen\/qwen3-embedding-0.6b"', WordPressTestState::$remote_post_calls[0]['args']['body'] );
}
```

- [ ] **Step 2: Run the failing tests**

Run:

```bash
composer run test:php -- --filter CloudflareWorkersAIEmbeddingTest
```

Expected: validation fails until `EmbeddingClient::validate_configuration()` can resolve Workers AI config from the provider branch.

- [ ] **Step 3: Update `EmbeddingClient::validate_configuration()`**

Extend the config selection so `cloudflare_workers_ai` uses `Provider::embedding_configuration( $provider, $overrides )` with Cloudflare option keys:

```php
if ( 'cloudflare_workers_ai' === $provider ) {
	$config = Provider::embedding_configuration(
		$provider,
		[
			'flavor_agent_cloudflare_workers_ai_account_id' => (string) ( $endpoint ?? get_option( 'flavor_agent_cloudflare_workers_ai_account_id', '' ) ),
			'flavor_agent_cloudflare_workers_ai_api_token' => (string) ( $api_key ?? get_option( 'flavor_agent_cloudflare_workers_ai_api_token', '' ) ),
			'flavor_agent_cloudflare_workers_ai_embedding_model' => (string) ( $deployment ?? get_option( 'flavor_agent_cloudflare_workers_ai_embedding_model', '' ) ),
		]
	);
} else {
	// Keep the existing native/Azure branch.
}
```

Also update method comments that say vectors are always 3072-dimension.

- [ ] **Step 4: Run the tests**

Run:

```bash
composer run test:php -- --filter CloudflareWorkersAIEmbeddingTest
composer run test:php -- --filter AzureBackendValidationTest
```

Expected: both suites pass; Azure and OpenAI Native behavior is unchanged.

### Task 3: Add Workers AI Settings And Readiness

**Files:**
- Modify: `inc/Admin/Settings/Config.php`
- Modify: `inc/Admin/Settings/Fields.php`
- Modify: `inc/Admin/Settings/Page.php`
- Modify: `inc/Admin/Settings/State.php`
- Modify: `inc/Admin/Settings/Validation.php`
- Modify: `inc/Admin/Settings/Feedback.php`
- Modify: `src/admin/settings-page-controller.js`
- Test: `tests/phpunit/SettingsTest.php`
- Test: `src/admin/__tests__/settings-page-controller.test.js`

- [ ] **Step 1: Add failing PHP settings tests**

Add tests in `SettingsTest` that assert the settings screen renders:

- `name="flavor_agent_cloudflare_workers_ai_account_id"`
- `name="flavor_agent_cloudflare_workers_ai_api_token"`
- `name="flavor_agent_cloudflare_workers_ai_embedding_model"`
- "Cloudflare Workers AI Embeddings"
- "Plugin-owned credentials used for pattern embeddings."

Add validation tests for successful and failed Workers AI probe responses using the same response shape as Task 2.

- [ ] **Step 2: Add failing JS prerequisite tests**

Add tests to `src/admin/__tests__/settings-page-controller.test.js` for:

- Qdrant configured plus no embedding backend still says "Needs embeddings".
- Workers AI configured plus Qdrant configured says pattern sync can run.
- Workers AI configured plus no Qdrant says "Needs Qdrant".

- [ ] **Step 3: Run failing tests**

Run:

```bash
composer run test:php -- --filter SettingsTest
npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js
```

Expected: fails until fields, validation, and copy are wired.

- [ ] **Step 4: Implement settings fields**

Add options to settings config and fields:

```php
'flavor_agent_cloudflare_workers_ai_account_id'
'flavor_agent_cloudflare_workers_ai_api_token'
'flavor_agent_cloudflare_workers_ai_embedding_model'
```

Register sanitizers:

```php
'flavor_agent_cloudflare_workers_ai_account_id' => 'sanitize_text_field',
'flavor_agent_cloudflare_workers_ai_api_token' => 'sanitize_text_field',
'flavor_agent_cloudflare_workers_ai_embedding_model' => 'sanitize_text_field',
```

Add a validation method that calls:

```php
EmbeddingClient::validate_configuration(
	$values['flavor_agent_cloudflare_workers_ai_account_id'],
	$values['flavor_agent_cloudflare_workers_ai_api_token'],
	$values['flavor_agent_cloudflare_workers_ai_embedding_model'],
	'cloudflare_workers_ai'
);
```

- [ ] **Step 5: Run settings tests**

Run:

```bash
composer run test:php -- --filter SettingsTest
npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js
```

Expected: pass.

### Task 4: Wire Workers AI Into Pattern Sync Dependencies

**Files:**
- Modify: `flavor-agent.php`
- Modify: `inc/Patterns/PatternIndex.php`
- Test: `tests/phpunit/PluginLifecycleTest.php`
- Test: `tests/phpunit/PatternIndexTest.php`

- [ ] **Step 1: Add failing dependency tests**

Add tests proving these option updates call `PatternIndex::handle_dependency_change()`:

```php
update_option_flavor_agent_cloudflare_workers_ai_account_id
update_option_flavor_agent_cloudflare_workers_ai_api_token
update_option_flavor_agent_cloudflare_workers_ai_embedding_model
```

Add a `PatternIndexTest` that seeds a ready Qdrant state, changes the active provider from OpenAI Native to Workers AI, and asserts runtime state becomes stale with `embedding_signature_changed`.

- [ ] **Step 2: Implement hooks and state labels**

Register the dependency hooks in `flavor-agent.php` beside the existing Azure, Qdrant, and `home` hooks.

Update state labels that mention only Azure/OpenAI to say "embedding provider".

- [ ] **Step 3: Run targeted tests**

Run:

```bash
composer run test:php -- --filter PluginLifecycleTest
composer run test:php -- --filter PatternIndexTest
```

Expected: pass.

---

## Phase 2: Cloudflare AI Search Pattern Retrieval Backend

### Task 5: Add Private Pattern AI Search Settings Contract

**Files:**
- Modify: `inc/Admin/Settings/Config.php`
- Modify: `inc/Admin/Settings/Fields.php`
- Modify: `inc/Admin/Settings/Page.php`
- Modify: `inc/Admin/Settings/State.php`
- Modify: `inc/Admin/Settings/Validation.php`
- Modify: `inc/Admin/Settings/Feedback.php`
- Test: `tests/phpunit/SettingsTest.php`

- [ ] **Step 1: Add failing settings tests**

Add tests that assert the settings screen renders:

- `flavor_agent_pattern_retrieval_backend` with choices `qdrant` and `cloudflare_ai_search`
- `flavor_agent_cloudflare_pattern_ai_search_account_id`
- `flavor_agent_cloudflare_pattern_ai_search_namespace`
- `flavor_agent_cloudflare_pattern_ai_search_instance_id`
- `flavor_agent_cloudflare_pattern_ai_search_api_token`

Assert copy clearly distinguishes this private pattern backend from the public docs AI Search endpoint.

- [ ] **Step 2: Implement options and UI**

Add a Pattern Retrieval Backend setting:

```php
qdrant
cloudflare_ai_search
```

Add private Cloudflare AI Search fields:

```php
flavor_agent_cloudflare_pattern_ai_search_account_id
flavor_agent_cloudflare_pattern_ai_search_namespace
flavor_agent_cloudflare_pattern_ai_search_instance_id
flavor_agent_cloudflare_pattern_ai_search_api_token
```

Use settings copy:

```text
Private Cloudflare AI Search instance used for site pattern indexing and retrieval. This is separate from the built-in WordPress developer docs endpoint.
```

- [ ] **Step 3: Run settings tests**

Run:

```bash
composer run test:php -- --filter SettingsTest
```

Expected: pass.

### Task 6: Implement `PatternSearchClient`

**Files:**
- Create: `inc/Cloudflare/PatternSearchClient.php`
- Test: `tests/phpunit/CloudflarePatternSearchClientTest.php`

- [ ] **Step 1: Add failing client tests**

Create tests for:

- Config validation rejects missing account, namespace, instance, or token.
- Config builds namespaced URLs:
  - `/accounts/{account}/ai-search/namespaces/{namespace}/instances/{instance}/search`
  - `/accounts/{account}/ai-search/namespaces/{namespace}/instances/{instance}/items`
- Search sends `messages`, `retrieval_type: hybrid`, `fusion_method: rrf`, `max_num_results`, `match_threshold`, and `filters.pattern_name`.
- Search normalizes `result.chunks[]` into candidates with `name`, `score`, `text`, `metadata`, and `source`.
- Upload sends multipart `file`, `metadata`, and `wait_for_completion`.
- Delete calls `/items/{item_id}` and treats `404` as already deleted.

- [ ] **Step 2: Run failing tests**

Run:

```bash
composer run test:php -- --filter CloudflarePatternSearchClientTest
```

Expected: fails because the client does not exist.

- [ ] **Step 3: Implement the client**

`PatternSearchClient` must expose:

```php
public static function is_configured(): bool;
public static function validate_configuration( ?string $account_id = null, ?string $namespace = null, ?string $instance_id = null, ?string $api_token = null ): true|\WP_Error;
public static function upload_pattern( array $pattern, string $item_id, bool $wait = false ): true|\WP_Error;
public static function delete_pattern( string $item_id ): true|\WP_Error;
public static function search_patterns( string $query, array $visible_pattern_names, int $max_results = 50 ): array|\WP_Error;
```

The search request body must use:

```php
[
	'messages' => [
		[
			'role'    => 'user',
			'content' => $query,
		],
	],
	'ai_search_options' => [
		'query_rewrite' => [
			'enabled' => false,
		],
		'retrieval' => [
			'retrieval_type'    => 'hybrid',
			'max_num_results'   => $max_results,
			'match_threshold'   => 0.2,
			'context_expansion' => 0,
			'fusion_method'     => 'rrf',
			'return_on_failure' => true,
			'filters'           => [
				'pattern_name' => [ '$in' => array_values( $visible_pattern_names ) ],
			],
		],
	],
]
```

Use five custom metadata fields only:

```php
pattern_name
candidate_type
source
synced_id
public_safe
```

The markdown file body uploaded for each pattern must include title, description, categories, block types, template types, inferred traits, and sanitized pattern content. Do not store private/draft/trashed synced patterns.

- [ ] **Step 4: Run client tests**

Run:

```bash
composer run test:php -- --filter CloudflarePatternSearchClientTest
```

Expected: pass.

### Task 7: Add Retrieval Backend Abstraction

**Files:**
- Create: `inc/Patterns/Retrieval/PatternRetrievalBackend.php`
- Create: `inc/Patterns/Retrieval/QdrantPatternRetrievalBackend.php`
- Create: `inc/Patterns/Retrieval/CloudflareAISearchPatternRetrievalBackend.php`
- Create: `inc/Patterns/Retrieval/PatternRetrievalBackendFactory.php`
- Modify: `inc/Abilities/PatternAbilities.php`
- Test: `tests/phpunit/PatternAbilitiesTest.php`

- [ ] **Step 1: Add failing PatternAbilities tests**

Add tests for:

- `qdrant` backend uses `EmbeddingClient` and `QdrantClient` as before.
- `cloudflare_ai_search` backend does not call `EmbeddingClient` or `QdrantClient`.
- `cloudflare_ai_search` backend passes `visiblePatternNames` into the AI Search filter.
- Missing visible scope returns empty recommendations before remote calls.
- Synced candidates from AI Search are rehydrated through current `wp_block` posts before ranker input.
- Unreadable synced candidates are omitted and only aggregate diagnostics are returned.

- [ ] **Step 2: Implement interfaces**

Use this interface:

```php
namespace FlavorAgent\Patterns\Retrieval;

interface PatternRetrievalBackend {
	/**
	 * @param string[] $visible_pattern_names
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $context
	 * @return array<int, array{payload: array<string, mixed>, score: float}>|\WP_Error
	 */
	public function search( string $query, array $visible_pattern_names, array $state, array $context ): array|\WP_Error;
}
```

Move the existing Qdrant embed, signature, collection validation, pass A, pass B, and dedupe behavior into `QdrantPatternRetrievalBackend`.

Add `CloudflareAISearchPatternRetrievalBackend` that calls `PatternSearchClient::search_patterns()` and normalizes chunks into the same candidate shape expected by the existing post-filter/ranker flow.

- [ ] **Step 3: Wire `PatternAbilities`**

Replace the direct Step 3 and Step 4 Qdrant code with:

```php
$backend = PatternRetrievalBackendFactory::for_runtime_state( $state );
if ( is_wp_error( $backend ) ) {
	return $backend;
}

$retrieved = $backend->search(
	$query,
	$visible_pattern_names,
	$state,
	[
		'postType' => $post_type,
		'blockName' => $block_name,
		'templateType' => $template_type,
		'insertionContext' => $insertion_context,
		'semanticLimit' => $semantic_limit,
		'structuralLimit' => $structural_limit,
	]
);
if ( is_wp_error( $retrieved ) ) {
	return $retrieved;
}
```

Keep the existing candidate authorization, rehydration, reranking, thresholding, and diagnostics code after retrieval.

- [ ] **Step 4: Run PatternAbilities tests**

Run:

```bash
composer run test:php -- --filter PatternAbilitiesTest
```

Expected: pass.

### Task 8: Add AI Search Pattern Index Sync

**Files:**
- Modify: `inc/Patterns/PatternIndex.php`
- Modify: `flavor-agent.php`
- Test: `tests/phpunit/PatternIndexTest.php`
- Test: `tests/phpunit/PluginLifecycleTest.php`

- [ ] **Step 1: Add failing sync tests**

Add tests for:

- `PatternIndex::recommendation_backends_configured()` returns true for `cloudflare_ai_search` when private pattern AI Search settings and chat are configured, without Qdrant.
- AI Search sync uploads current public-safe registered patterns.
- AI Search sync uploads public-safe synced patterns only.
- AI Search sync deletes stale remote item IDs that no longer appear in the current public-safe corpus.
- AI Search state records selected backend, namespace, instance, fingerprint, indexed count, and last sync time.
- Changing pattern backend from `qdrant` to `cloudflare_ai_search` marks state stale with `pattern_backend_changed`.

- [ ] **Step 2: Update runtime state shape**

Add state fields:

```php
'pattern_backend' => 'qdrant',
'cloudflare_ai_search_namespace' => '',
'cloudflare_ai_search_instance' => '',
'cloudflare_ai_search_signature' => '',
```

Add stale reasons:

```php
pattern_backend_changed
cloudflare_ai_search_instance_changed
cloudflare_ai_search_signature_changed
```

- [ ] **Step 3: Split backend readiness**

Replace the current single readiness check:

```php
Provider::embedding_configured()
&& get_option( 'flavor_agent_qdrant_url', '' )
&& get_option( 'flavor_agent_qdrant_key', '' )
```

with:

```php
if ( self::selected_pattern_backend() === 'cloudflare_ai_search' ) {
	return PatternSearchClient::is_configured();
}

return Provider::embedding_configured()
	&& get_option( 'flavor_agent_qdrant_url', '' )
	&& get_option( 'flavor_agent_qdrant_key', '' );
```

- [ ] **Step 4: Implement AI Search sync flow**

For `cloudflare_ai_search`, `PatternIndex::do_sync()` must:

1. Collect indexable patterns with the existing public-safe corpus collector.
2. Compute fingerprint and per-pattern fingerprints exactly as Qdrant sync does.
3. Mark indexing before remote work.
4. List remote AI Search items for the instance.
5. Upload changed current patterns with `PatternSearchClient::upload_pattern()`.
6. Delete stale item IDs with `PatternSearchClient::delete_pattern()`.
7. Persist ready state.

Do not call `EmbeddingClient` or `QdrantClient` in this branch.

- [ ] **Step 5: Register dependency hooks**

Register `PatternIndex::handle_dependency_change()` for:

```php
update_option_flavor_agent_pattern_retrieval_backend
update_option_flavor_agent_cloudflare_pattern_ai_search_account_id
update_option_flavor_agent_cloudflare_pattern_ai_search_namespace
update_option_flavor_agent_cloudflare_pattern_ai_search_instance_id
update_option_flavor_agent_cloudflare_pattern_ai_search_api_token
```

- [ ] **Step 6: Run sync tests**

Run:

```bash
composer run test:php -- --filter PatternIndexTest
composer run test:php -- --filter PluginLifecycleTest
```

Expected: pass.

### Task 9: Preserve Pattern Surface Behavior

**Files:**
- Modify: `inc/Abilities/PatternAbilities.php`
- Modify: `src/patterns/recommendation-utils.js`
- Test: `tests/phpunit/PatternAbilitiesTest.php`
- Test: `src/patterns/__tests__/recommendation-utils.test.js`
- Test: `src/patterns/__tests__/InserterBadge.test.js`

- [ ] **Step 1: Add behavior parity tests**

Add tests proving AI Search backend results:

- Are filtered to `visiblePatternNames`.
- Preserve `core/block/{id}` names for synced patterns.
- Count badge results only when renderable by current Gutenberg allowed-pattern data.
- Return "not currently exposing those patterns" when backend names are not renderable.
- Do not create apply, undo, or activity semantics for pattern insertion.

- [ ] **Step 2: Keep UI behavior unchanged**

Most UI code should not change. Only update unavailable-state copy when backend prerequisites differ:

- Qdrant backend: needs embeddings and Qdrant.
- AI Search backend: needs private Cloudflare AI Search pattern backend.
- Both: need text generation through Settings > Connectors for reranking.

- [ ] **Step 3: Run tests**

Run:

```bash
composer run test:php -- --filter PatternAbilitiesTest
npm run test:unit -- src/patterns/__tests__/recommendation-utils.test.js src/patterns/__tests__/InserterBadge.test.js
```

Expected: pass.

---

## Phase 3: Documentation, Disclosure, And Validation

### Task 10: Update Docs And Disclosure

**Files:**
- Modify: `docs/features/pattern-recommendations.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `docs/reference/abilities-and-routes.md`
- Modify: `docs/reference/provider-precedence.md`
- Modify: `docs/reference/external-service-disclosure.md`
- Modify: `docs/reference/pattern-recommendation-debugging.md`
- Modify: `docs/reference/local-environment-setup.md`
- Modify: `readme.txt`

- [ ] **Step 1: Update source-of-truth docs**

Document this backend matrix:

| Pattern backend | Embeddings | Vector/index service | Search service | Required settings |
| --- | --- | --- | --- | --- |
| Qdrant | Azure, OpenAI Native, or Workers AI | Qdrant | Qdrant | Embedding provider, Qdrant, Connectors chat |
| Cloudflare AI Search | AI Search managed embedding model | Cloudflare AI Search | Cloudflare AI Search | Private pattern AI Search, Connectors chat |

- [ ] **Step 2: Update disclosure**

Add two distinct Cloudflare rows in `docs/reference/external-service-disclosure.md`:

1. Cloudflare Workers AI embeddings
   - Data: validation probe, pattern index probe, pattern embedding text, pattern recommendation query.
   - Trigger: settings validation, sync, recommendation retrieval.
   - Gate: Workers AI credentials and selected embedding provider.

2. Cloudflare AI Search for private pattern retrieval
   - Data: pattern title, description, categories, block/template metadata, inferred traits, public-safe pattern content, recommendation query, visible pattern names as search filters.
   - Trigger: settings validation, manual/scheduled pattern sync, recommendation retrieval.
   - Gate: selected pattern backend plus private Cloudflare AI Search credentials.

Keep the existing Cloudflare AI Search docs-grounding row separate.

- [ ] **Step 3: Update debugging docs**

Add a first split in `docs/reference/pattern-recommendation-debugging.md`:

```text
Check selected pattern backend first:
- qdrant: inspect embeddings, Qdrant health, collection compatibility, and raw Qdrant hits.
- cloudflare_ai_search: inspect private AI Search credentials, item sync state, search chunks, filters, and synced-pattern rehydration.
```

- [ ] **Step 4: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: pass.

### Task 11: Calibrate Thresholds And Record Backend Evidence

**Files:**
- Modify: `docs/reference/pattern-recommendation-debugging.md`
- Modify: `STATUS.md` if release evidence is recorded there during execution

- [ ] **Step 1: Add calibration guidance**

Document that Workers AI and AI Search scores are not equivalent to OpenAI `text-embedding-3-*` plus Qdrant scores. Recommended evaluation:

```bash
wp eval 'echo wp_json_encode( \FlavorAgent\Patterns\PatternIndex::sync() );'
wp eval 'echo wp_json_encode( \FlavorAgent\Abilities\PatternAbilities::recommend_patterns( array( "postType" => "post", "visiblePatternNames" => array( "theme/hero", "theme/footer-callout" ), "prompt" => "hero for a product launch" ) ) );'
```

Record:

- backend
- model or AI Search instance
- threshold
- number of candidates before rerank
- number of renderable final recommendations
- visible scope size

- [ ] **Step 2: Keep the default threshold conservative**

Do not change `flavor_agent_pattern_recommendation_threshold` default in the first implementation unless targeted tests show AI Search returns no useful results with the current value. If changed, update settings tests and docs in the same task.

### Task 12: Full Verification

**Files:**
- No source edits unless verification finds a regression.

- [ ] **Step 1: Run targeted PHP tests**

Run:

```bash
composer run test:php -- --filter CloudflareWorkersAIEmbeddingTest
composer run test:php -- --filter CloudflarePatternSearchClientTest
composer run test:php -- --filter SettingsTest
composer run test:php -- --filter PatternIndexTest
composer run test:php -- --filter PatternAbilitiesTest
composer run test:php -- --filter AgentControllerTest
composer run test:php -- --filter PluginLifecycleTest
```

Expected: pass.

- [ ] **Step 2: Run targeted JS tests**

Run:

```bash
npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js src/patterns/__tests__/recommendation-utils.test.js src/patterns/__tests__/InserterBadge.test.js src/patterns/__tests__/PatternRecommender.test.js
```

Expected: pass.

- [ ] **Step 3: Run aggregate non-browser verification**

Run:

```bash
node scripts/verify.js --skip-e2e
```

Expected: `VERIFY_RESULT` reports pass. If plugin-check prerequisites are unavailable, record the `incomplete` blocker and rerun with the documented environment.

- [ ] **Step 4: Run browser evidence**

Run:

```bash
npm run test:e2e:playground
```

Expected: pattern inserter smoke passes, including the inserter-search recommendation flow.

If this change touches Site Editor settings or cross-surface provider state during implementation, also run:

```bash
npm run test:e2e:wp70
```

Expected: pass or a recorded blocker/waiver per `docs/reference/cross-surface-validation-gates.md`.

- [ ] **Step 5: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: pass.

---

## Execution Order

1. Phase 1, Tasks 1-4: Workers AI embeddings for existing Qdrant pattern recommendations.
2. Run a checkpoint with targeted PHP/JS tests plus `node scripts/verify.js --skip-e2e`.
3. Phase 2, Tasks 5-9: private AI Search pattern backend.
4. Run a checkpoint with targeted PHP/JS tests plus pattern inserter Playwright.
5. Phase 3, Tasks 10-12: docs, disclosure, calibration, and full validation.

## Acceptance Criteria

- Site owners can select Cloudflare Workers AI as the embedding provider and use the existing Qdrant-backed pattern recommendation flow.
- Site owners can select Cloudflare AI Search as the pattern retrieval backend and use pattern recommendations without Azure/OpenAI embeddings or Qdrant.
- Existing Azure/OpenAI Native plus Qdrant installs continue to work without migration.
- Existing Cloudflare docs grounding continues to use `FlavorAgent\Cloudflare\AISearchClient` and remains restricted to trusted WordPress developer docs.
- Pattern recommendations remain scoped to `visiblePatternNames`.
- Synced pattern recommendations are rehydrated from current readable posts before reranking/output.
- Private, draft, trashed, or unreadable synced pattern content is not uploaded, ranked, or returned.
- Settings, capability notices, disclosures, and docs accurately distinguish:
  - Settings > Connectors text generation
  - plugin-owned embeddings
  - Qdrant retrieval
  - private Cloudflare AI Search pattern retrieval
  - public/trusted Cloudflare AI Search docs grounding
- `npm run check:docs`, targeted PHP/JS tests, `node scripts/verify.js --skip-e2e`, and relevant Playwright evidence pass or record explicit environment blockers.

## Self-Review

- Spec coverage: all findings from the Cloudflare AI Search versus embeddings analysis map to either Phase 1, Phase 2, or Phase 3.
- Placeholder scan: no implementation task is left without files, test targets, and expected verification commands.
- Type consistency: provider id is `cloudflare_workers_ai`; pattern backend id is `cloudflare_ai_search`; private pattern options use the `flavor_agent_cloudflare_pattern_ai_search_*` prefix; docs-grounding options keep the existing `flavor_agent_cloudflare_ai_search_*` prefix.
