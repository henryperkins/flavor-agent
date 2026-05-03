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

7. **Workers AI does NOT participate in the implicit runtime fallback chain.**
   `Provider::runtime_embedding_configuration()` currently iterates `direct_choices()` order and uses the first configured entry when the selected provider is unconfigured. Workers AI is added to `direct_choices()` for selectability, but `runtime_embedding_configuration()` must explicitly skip Workers AI when it is not the selected provider. **Why:** silent fallback to Cloudflare from a site whose admin selected Azure or OpenAI Native would route pattern text to a backend the operator did not opt into and is not disclosed. Sites must explicitly select Workers AI to use it.

8. **Workers AI credentials are read from option storage only in this plan.**
   Env-var/constant resolution (parity with `Provider::native_effective_api_key_metadata()`) is explicitly out of scope. Operators set the account ID and token through `Settings > Flavor Agent`. Re-evaluate after Phase 2 if CI/devcontainer use cases ask for env-var support.

9. **AI Search scores are NOT comparable to Qdrant cosine similarity.**
   Cloudflare AI Search returns RRF-fused hybrid scores with their own match-threshold semantics. The existing `flavor_agent_pattern_recommendation_threshold` is calibrated for Qdrant. Phase 2 introduces a separate `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search` option (default `0.2`, matching the AI Search request `match_threshold`) so each backend can be tuned independently. The shared threshold setting remains Qdrant-only.

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
- `inc/Admin/Settings/Registrar.php`
  - Register the new options with `register_setting()` and wire field rows. **This file owns option registration and field-row registration; `Fields.php` only renders inputs.**
- `inc/Admin/Settings/Fields.php`
  - Add render callbacks if any new field shapes are needed (re-use existing `render_text_field` where possible).
- `inc/Admin/Settings/Page.php`
  - Render the new settings sections with clear ownership labels and prerequisite copy.
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
- `docs/reference/wordpress-ai-roadmap-tracking.md`
  - Refresh per the doc's documented procedure to confirm no active conflicts with WordPress org project 240 (AI Planning & Roadmap) before adding `cloudflare_workers_ai` as a built-in `direct_choices()` entry.
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

- [x] **Step 1: Add failing provider configuration tests**

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

- [x] **Step 2: Run the failing tests**

Run:

```bash
composer run test:php -- --filter CloudflareWorkersAIEmbeddingTest
```

Expected: fails because `cloudflare_workers_ai` is not a known provider and the configuration class does not exist.

- [x] **Step 3: Implement Workers AI configuration**

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

Update `Provider::runtime_embedding_configuration()` to skip Workers AI during the fallback iteration so it is never used as an implicit fallback for sites that selected Azure or OpenAI Native (see Product Decision 7):

```php
foreach ( array_keys( self::direct_choices() ) as $candidate ) {
	if ( $candidate === $selected_provider ) {
		continue;
	}

	if ( WorkersAIEmbeddingConfiguration::PROVIDER === $candidate ) {
		continue; // Workers AI must be explicitly selected.
	}

	$candidate_config = self::embedding_configuration( $candidate, $overrides );
	// ...
}
```

Add a `CloudflareWorkersAIEmbeddingTest` case proving that a site with `flavor_agent_openai_provider = azure_openai` and blank Azure credentials does NOT fall back to a fully-configured Workers AI installation.

- [x] **Step 4: Run the provider tests**

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

- [x] **Step 1: Add failing HTTP validation and batch embedding tests**

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

- [x] **Step 2: Run the failing tests**

Run:

```bash
composer run test:php -- --filter CloudflareWorkersAIEmbeddingTest
```

Expected: validation fails until `EmbeddingClient::validate_configuration()` can resolve Workers AI config from the provider branch.

- [x] **Step 3: Update `EmbeddingClient::validate_configuration()` and `embed_batch()`**

The current `validate_configuration()` uses a single ternary on `Provider::is_native()` (`inc/AzureOpenAI/EmbeddingClient.php:32-44`) that hard-splits into Native vs. Azure overrides. Replace that ternary with an explicit three-branch override builder, then call `Provider::embedding_configuration()` once:

```php
if ( 'cloudflare_workers_ai' === $provider ) {
	$overrides = [
		'flavor_agent_cloudflare_workers_ai_account_id' => (string) ( $endpoint ?? get_option( 'flavor_agent_cloudflare_workers_ai_account_id', '' ) ),
		'flavor_agent_cloudflare_workers_ai_api_token'  => (string) ( $api_key ?? get_option( 'flavor_agent_cloudflare_workers_ai_api_token', '' ) ),
		'flavor_agent_cloudflare_workers_ai_embedding_model' => (string) ( $deployment ?? get_option( 'flavor_agent_cloudflare_workers_ai_embedding_model', '' ) ),
	];
} elseif ( Provider::is_native( $provider ) ) {
	$overrides = [
		'flavor_agent_openai_native_api_key'         => (string) ( $api_key ?? get_option( 'flavor_agent_openai_native_api_key', '' ) ),
		'flavor_agent_openai_native_embedding_model' => (string) ( $deployment ?? get_option( 'flavor_agent_openai_native_embedding_model', '' ) ),
	];
} else {
	$overrides = [
		'flavor_agent_azure_openai_endpoint'      => (string) ( $endpoint ?? get_option( 'flavor_agent_azure_openai_endpoint', '' ) ),
		'flavor_agent_azure_openai_key'           => (string) ( $api_key ?? get_option( 'flavor_agent_azure_openai_key', '' ) ),
		'flavor_agent_azure_embedding_deployment' => (string) ( $deployment ?? get_option( 'flavor_agent_azure_embedding_deployment', '' ) ),
	];
}

$config = Provider::embedding_configuration( $provider, $overrides );
```

Also update the user-visible connector-rejection error strings that hard-code "Choose Azure OpenAI or OpenAI Native":

- `inc/AzureOpenAI/EmbeddingClient.php:25` (in `validate_configuration()`)
- `inc/AzureOpenAI/EmbeddingClient.php:99` (in `embed_batch()`)

Replace with: `"Choose Azure OpenAI, OpenAI Native, or Cloudflare Workers AI in Settings > Flavor Agent for pattern recommendations."`

Update doc comments on `embed()` and `embed_batch()` that claim vectors are always 3072-dimension. Workers AI's `@cf/qwen/qwen3-embedding-0.6b` returns 1024-dimension vectors.

- [x] **Step 4: Run the tests**

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

- [x] **Step 1: Add failing PHP settings tests**

Add tests in `SettingsTest` that assert the settings screen renders:

- `name="flavor_agent_cloudflare_workers_ai_account_id"`
- `name="flavor_agent_cloudflare_workers_ai_api_token"`
- `name="flavor_agent_cloudflare_workers_ai_embedding_model"`
- "Cloudflare Workers AI Embeddings"
- "Plugin-owned credentials used for pattern embeddings."

Add validation tests for successful and failed Workers AI probe responses using the same response shape as Task 2.

- [x] **Step 2: Add failing JS prerequisite tests**

Add tests to `src/admin/__tests__/settings-page-controller.test.js` for:

- Qdrant configured plus no embedding backend still says "Needs embeddings".
- Workers AI configured plus Qdrant configured says pattern sync can run.
- Workers AI configured plus no Qdrant says "Needs Qdrant".

- [x] **Step 3: Run failing tests**

Run:

```bash
composer run test:php -- --filter SettingsTest
npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js
```

Expected: fails until fields, validation, and copy are wired.

- [x] **Step 4: Implement settings fields**

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

- [x] **Step 5: Run settings tests**

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

- [x] **Step 1: Add failing dependency tests**

Add tests proving these option updates call `PatternIndex::handle_dependency_change()`:

```php
update_option_flavor_agent_cloudflare_workers_ai_account_id
update_option_flavor_agent_cloudflare_workers_ai_api_token
update_option_flavor_agent_cloudflare_workers_ai_embedding_model
```

Add a `PatternIndexTest` that seeds a ready Qdrant state, changes the active provider from OpenAI Native to Workers AI, and asserts runtime state becomes stale with `embedding_signature_changed`.

- [x] **Step 2: Implement hooks and state labels**

Append the new option names to the existing option-name array in `flavor-agent.php` (currently the `foreach` at lines 91-111) that registers `update_option_*` actions for `PatternIndex::handle_dependency_change()`. Do not introduce a second loop.

```php
foreach ( [
	'flavor_agent_openai_provider',
	// ... existing Azure / Native / OpenAI connector / Qdrant / home entries ...
	'flavor_agent_cloudflare_workers_ai_account_id',
	'flavor_agent_cloudflare_workers_ai_api_token',
	'flavor_agent_cloudflare_workers_ai_embedding_model',
] as $flavor_agent_option_name ) {
	add_action( "update_option_{$flavor_agent_option_name}", [ FlavorAgent\Patterns\PatternIndex::class, 'handle_dependency_change' ], 10, 3 );
}
```

Update `PatternIndex` state labels and stale-reason copy that mention only Azure/OpenAI to say "embedding provider".

- [x] **Step 3: Run targeted tests**

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

- [x] **Step 1: Add failing settings tests**

Add tests that assert the settings screen renders:

- `flavor_agent_pattern_retrieval_backend` with choices `qdrant` and `cloudflare_ai_search`
- `flavor_agent_cloudflare_pattern_ai_search_account_id`
- `flavor_agent_cloudflare_pattern_ai_search_namespace`
- `flavor_agent_cloudflare_pattern_ai_search_instance_id`
- `flavor_agent_cloudflare_pattern_ai_search_api_token`

Assert copy clearly distinguishes this private pattern backend from the public docs AI Search endpoint.

- [x] **Step 2: Implement options and UI**

In `inc/Admin/Settings/Registrar.php`, register the pattern retrieval backend selector:

```php
register_setting( 'flavor_agent_settings', 'flavor_agent_pattern_retrieval_backend', [
	'type'              => 'string',
	'sanitize_callback' => static fn ( $v ) => in_array( (string) $v, [ 'qdrant', 'cloudflare_ai_search' ], true ) ? (string) $v : 'qdrant',
	'default'           => 'qdrant',
] );
```

Register the private Cloudflare AI Search options:

```php
register_setting( 'flavor_agent_settings', 'flavor_agent_cloudflare_pattern_ai_search_account_id',  [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
register_setting( 'flavor_agent_settings', 'flavor_agent_cloudflare_pattern_ai_search_namespace',   [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
register_setting( 'flavor_agent_settings', 'flavor_agent_cloudflare_pattern_ai_search_instance_id', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
register_setting( 'flavor_agent_settings', 'flavor_agent_cloudflare_pattern_ai_search_api_token',   [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
```

Register the per-backend threshold introduced by Product Decision 9:

```php
register_setting( 'flavor_agent_settings', 'flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search', [
	'type'              => 'number',
	'sanitize_callback' => static fn ( $v ) => max( 0.0, min( 1.0, (float) $v ) ),
	'default'           => 0.2,
] );
```

Add field rows for each option using the existing `add_settings_field()` pattern in `Registrar.php`.

Use settings copy:

```text
Private Cloudflare AI Search instance used for site pattern indexing and retrieval. This is separate from the built-in WordPress developer docs endpoint.
```

- [x] **Step 3: Run settings tests**

Run:

```bash
composer run test:php -- --filter SettingsTest
```

Expected: pass.

### Task 6: Implement `PatternSearchClient`

**Files:**
- Create: `inc/Cloudflare/PatternSearchClient.php`
- Test: `tests/phpunit/CloudflarePatternSearchClientTest.php`

- [x] **Step 1: Add failing client tests**

Create tests for:

- Config validation rejects missing account, namespace, instance, or token.
- Config builds namespaced URLs:
  - `/accounts/{account}/ai-search/namespaces/{namespace}/instances/{instance}/search`
  - `/accounts/{account}/ai-search/namespaces/{namespace}/instances/{instance}/items`
- Search sends `messages`, `retrieval_type: hybrid`, `fusion_method: rrf`, `max_num_results`, `match_threshold`, and `filters.pattern_name`.
- Search normalizes `result.chunks[]` into candidates with `name`, `score`, `text`, `metadata`, and `source`.
- Upload sends multipart `file`, `metadata`, and `wait_for_completion`.
- Delete calls `/items/{item_id}` and treats `404` as already deleted.

- [x] **Step 2: Run failing tests**

Run:

```bash
composer run test:php -- --filter CloudflarePatternSearchClientTest
```

Expected: fails because the client does not exist.

- [x] **Step 3: Implement the client**

`PatternSearchClient` must expose this surface (`is_configured()` mirrors `AISearchClient::is_configured()` so the Settings page can probe with submitted values before persisting):

```php
public static function is_configured(
	?string $account_id = null,
	?string $namespace = null,
	?string $instance_id = null,
	?string $api_token = null
): bool;
public static function validate_configuration( ?string $account_id = null, ?string $namespace = null, ?string $instance_id = null, ?string $api_token = null ): true|\WP_Error;
public static function upload_pattern( array $pattern, string $item_id, bool $wait = false ): true|\WP_Error;
public static function delete_pattern( string $item_id ): true|\WP_Error;
public static function search_patterns( string $query, array $visible_pattern_names, int $max_results = 50 ): array|\WP_Error;
```

All HTTP requests must go through `FlavorAgent\AzureOpenAI\BaseHttpClient::post_json_with_retry()` (or the multipart equivalent) for consistent retry/backoff behavior with existing transports. Do not introduce a separate retry implementation.

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

Cloudflare AI Search metadata filters require fields to be declared as filterable in the AI Search instance schema. The Cloudflare API today does not expose programmatic schema management for custom metadata, so:

- `validate_configuration()` must probe the instance with a search using a `filters.pattern_name` clause. If the API returns a schema error, surface a settings-page error explicitly instructing the operator to add `pattern_name` (and the other four fields) as filterable metadata in the AI Search dashboard for that instance.
- `docs/reference/local-environment-setup.md` (Modified Files) must list the exact dashboard steps to declare these five fields filterable before the plugin's first sync.

The markdown file body uploaded for each pattern must include title, description, categories, block types, template types, inferred traits, and sanitized pattern content. Do not store private/draft/trashed synced patterns.

- [x] **Step 4: Run client tests**

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

- [x] **Step 1: Add failing PatternAbilities tests**

Add tests for:

- `qdrant` backend uses `EmbeddingClient` and `QdrantClient` as before.
- `cloudflare_ai_search` backend does not call `EmbeddingClient` or `QdrantClient`.
- `cloudflare_ai_search` backend passes `visiblePatternNames` into the AI Search filter.
- Missing visible scope returns empty recommendations before remote calls.
- Synced candidates from AI Search are rehydrated through current `wp_block` posts before ranker input.
- Unreadable synced candidates are omitted and only aggregate diagnostics are returned.

- [x] **Step 2: Implement interfaces**

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

- [x] **Step 3: Wire `PatternAbilities`**

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

- [x] **Step 4: Run PatternAbilities tests**

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
5. Upload changed current patterns with `PatternSearchClient::upload_pattern( $pattern, $item_id, /* wait */ true )`. **Each upload must pass `wait_for_completion=true` to avoid a race between the upload and the delete pass.** The plan's Qdrant path is synchronous; AI Search is async by default. Without `wait`, a stale-delete pass executed in the same sync run could delete items that haven't finished indexing.
6. Delete stale item IDs with `PatternSearchClient::delete_pattern()`. Re-list remote items after step 5 if `wait_for_completion=true` is unavailable for any backend reason; reconcile only against the post-wait list.
7. Persist ready state.

Re-uploading the same `item_id` overwrites the existing item in Cloudflare AI Search. Use the deterministic `pattern_uuid()` already exposed by `PatternIndex` to produce stable item IDs, and add a `CloudflarePatternSearchClientTest` case proving repeat uploads do not produce duplicates.

Do not call `EmbeddingClient` or `QdrantClient` in this branch.

- [ ] **Step 5: Register dependency hooks**

Append the following option names to the same `foreach` loop in `flavor-agent.php` (lines 91-111) extended in Task 4 — do not introduce a second loop:

```php
'flavor_agent_pattern_retrieval_backend',
'flavor_agent_cloudflare_pattern_ai_search_account_id',
'flavor_agent_cloudflare_pattern_ai_search_namespace',
'flavor_agent_cloudflare_pattern_ai_search_instance_id',
'flavor_agent_cloudflare_pattern_ai_search_api_token',
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
- **Defense-in-depth: synced candidates returned by AI Search are dropped if the underlying `wp_block` post is currently private/draft/trashed/unreadable, even if the AI Search index still contains them.** Status changes between syncs must never leak private content. Add an explicit test that flips a synced pattern to `draft` after upload and asserts it is filtered out of recommendations before the next sync.
- **Activity-log parity:** every recommendation request still emits a `request_diagnostic` activity row, and the row's metadata records the `pattern_backend` (`qdrant` or `cloudflare_ai_search`) plus the resolved chat/embedding provider. Add a `PatternAbilitiesTest` assertion against the activity-row payload for both backends. (See active project memory: pattern recommendations already emit `request_diagnostic` rows; this change must not regress that.)

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
   - **Public-safety guarantee is two-layer.** At sync time, only public, published, non-trashed synced patterns are uploaded; status changes between syncs may leave a previously-uploaded pattern in the AI Search index until the next sync run. Retrieval-time rehydration (Task 7 / Task 9) is the defense-in-depth layer: any synced candidate returned by AI Search is dropped if the current `wp_block` post is no longer publicly readable. Disclosure copy must state both layers explicitly.

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

- [ ] **Step 2: Use the per-backend threshold introduced by Product Decision 9**

`flavor_agent_pattern_recommendation_threshold` remains Qdrant-only. AI Search uses the new `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search` option (default `0.2`, registered in Task 5 Step 2). RRF-fused hybrid scores from AI Search are not on the same scale as Qdrant cosine similarity, so a shared threshold would zero out recommendations on one backend or over-permit on the other.

`PatternAbilities` must select the threshold by active backend before applying the post-rerank cutoff. Add a `PatternAbilitiesTest` case proving:

- The Qdrant backend reads `flavor_agent_pattern_recommendation_threshold`.
- The AI Search backend reads `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search`.
- Lowering the AI Search threshold to `0` returns more candidates without affecting Qdrant.

If the AI Search default of `0.2` proves wrong during calibration evidence collection (Step 1), update the option default and the settings copy in the same task.

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

**Cut line for the 2026-05-31 WordPress.org submission window.** Phase 1 alone is a coherent ship: Workers AI as a third embedding provider for the existing Qdrant pattern flow, with disclosure and verification updates scoped to that change. Phase 2 + Phase 3 are larger (new client, retrieval abstraction, dual-backend disclosure, threshold calibration) and are appropriate for a follow-up dot release after submission. If submission readiness is at risk after Phase 1, defer Phase 2 + Phase 3 explicitly rather than landing them partially. Record the cut decision and the deferred scope in `STATUS.md`.

## Acceptance Criteria

- Site owners can select Cloudflare Workers AI as the embedding provider and use the existing Qdrant-backed pattern recommendation flow.
- Site owners can select Cloudflare AI Search as the pattern retrieval backend and use pattern recommendations without Azure/OpenAI embeddings or Qdrant.
- Existing Azure/OpenAI Native plus Qdrant installs continue to work without migration.
- Existing Cloudflare docs grounding continues to use `FlavorAgent\Cloudflare\AISearchClient` and remains restricted to trusted WordPress developer docs.
- Pattern recommendations remain scoped to `visiblePatternNames`.
- Synced pattern recommendations are rehydrated from current readable posts before reranking/output.
- Private, draft, trashed, or unreadable synced pattern content is not uploaded, ranked, or returned. Defense-in-depth applies at both index time AND retrieval time, so post-status changes between sync runs cannot leak private content.
- Workers AI is never used as an implicit runtime fallback. A site whose admin selected Azure or OpenAI Native and left credentials blank does not silently route pattern text to Cloudflare.
- Each backend uses its own recommendation threshold (`flavor_agent_pattern_recommendation_threshold` for Qdrant; `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search` for AI Search).
- AI Activity log integration is preserved: every pattern recommendation request emits a `request_diagnostic` row tagged with the active `pattern_backend` and the resolved chat/embedding provider for both backends.
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
- File targeting verified against the live tree: option registration lives in `inc/Admin/Settings/Registrar.php`; `Fields.php` is a renderer; the dependency-hook list lives in a single `foreach` at `flavor-agent.php:91-111`; `EmbeddingClient::validate_configuration()` uses a Native-vs-Azure ternary that must be expanded into a three-branch builder.

### Risks and Known Unknowns

- **Cloudflare AI Search filter schema is dashboard-managed.** No public API for declaring custom-metadata filterability today. If Cloudflare changes that during execution, `PatternSearchClient::validate_configuration()` should be revisited to manage the schema programmatically instead of surfacing a setup error. (Cloudflare changelog: 2026-04-09 changelog post listed in references; check for newer entries before kickoff.)
- **`wait_for_completion` timing on large pattern catalogs.** Sites with very large pattern libraries (high hundreds) may exceed reasonable per-request timeouts when waiting for ingestion. If observed during evidence collection, batch upload + post-batch list reconciliation may need to replace per-item `wait`.
- **Threshold calibration is empirical.** The `0.2` AI Search default mirrors the request-side `match_threshold`. Real-world calibration may show this is too lenient for the post-rerank cutoff specifically; treat the default as a starting point, not a load-bearing decision.
- **WordPress AI Client provider abstractions in flux.** WordPress org project 240 may introduce a first-class abstraction that supersedes the plugin-owned `Provider::direct_choices()` mechanism. Check `docs/reference/wordpress-ai-roadmap-tracking.md` before adding `cloudflare_workers_ai` and again before Phase 2 kickoff.
- **Workers AI model choice is opinionated.** `@cf/qwen/qwen3-embedding-0.6b` is selected based on April 2026 Cloudflare docs; if the recommended model changes, the embedding signature will change, triggering a full re-index for every site after upgrade. Note this in user-visible upgrade notes once a model rotation actually happens.
