# Managed Cloudflare AI Search Pattern Storage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Flavor Agent create or adopt its own Cloudflare AI Search pattern-storage instance after Embedding Model credentials are saved, removing the required manual index-name setup.

**Architecture:** Add a focused Cloudflare AI Search instance manager that owns list/create/adopt/owner-marker checks. Keep `PatternSearchClient` responsible for item upload, item delete, item list, and search. Wire settings readiness to the manager so Cloudflare AI Search Pattern Storage becomes a managed setup state instead of a manual text field.

**Tech Stack:** WordPress plugin PHP, WordPress Settings API, Cloudflare AI Search REST API, PHPUnit, existing `WordPressTestState` HTTP fakes, repo-native docs checks.

---

## Approved Design

Design source: `docs/superpowers/specs/2026-05-06-managed-cloudflare-ai-search-pattern-storage-design.md`.

The managed instance target is:

```json
{
  "id": "flavor-agent-patterns-{site_hash}",
  "embedding_model": "@cf/qwen/qwen3-embedding-0.6b",
  "chunk": true,
  "chunk_size": 1024,
  "chunk_overlap": 15,
  "custom_metadata": [
    { "field_name": "pattern_name", "data_type": "text" },
    { "field_name": "candidate_type", "data_type": "text" },
    { "field_name": "source", "data_type": "text" },
    { "field_name": "synced_id", "data_type": "text" },
    { "field_name": "public_safe", "data_type": "boolean" }
  ],
  "fusion_method": "rrf",
  "index_method": {
    "keyword": true,
    "vector": true
  },
  "indexing_options": {
    "keyword_tokenizer": "porter"
  },
  "max_num_results": 50,
  "retrieval_options": {
    "keyword_match_mode": "or"
  },
  "rewrite_query": false,
  "reranking": false,
  "cache": false,
  "public_endpoint_params": {
    "enabled": false,
    "search_endpoint": {
      "disabled": true
    },
    "chat_completions_endpoint": {
      "disabled": true
    },
    "mcp": {
      "disabled": true
    }
  }
}
```

Additional constraints from the approved design:

- Runtime search, sync, and managed setup use the Cloudflare account ID and API token saved under Embedding Model. Legacy pattern-specific Cloudflare AI Search account ID, namespace, and API token options are cleanup-only and must not override the Embedding Model values.
- Existing managed instances are adopted only after the schema and an already-present owner marker are read and validated. Do not upload or overwrite the owner marker before adoption.
- Newly-created instances upload the owner marker and then read it back before the instance ID is saved. Do not assume the uploaded filename becomes the Cloudflare item ID; discover the marker by validated metadata.
- A saved managed instance is ready only when it matches the current Embedding Model account/token signature and a fresh manager validation has succeeded. A failed managed setup must not leave an old instance ID paired with new Workers AI credentials as an apparently ready configuration.
- Cloudflare AI Search cleanup deletes only stale IDs that Flavor Agent already knows from the previous sync state. Unknown remote items are preserved.
- The create payload uses the saved embedding model only when it is in Cloudflare AI Search's supported embedding-model enum; otherwise it falls back to `@cf/qwen/qwen3-embedding-0.6b`.
- Cloudflare instance discovery uses Cloudflare's `search`, `page`, and `per_page` query parameters, filters the returned IDs exactly, and follows pagination until the managed ID is found or the result set is exhausted.

## Files

- Create: `inc/Cloudflare/PatternSearchInstanceManager.php`
- Modify: `inc/Cloudflare/PatternSearchClient.php`
- Modify: `inc/Patterns/PatternIndex.php`
- Modify: `inc/Admin/Settings/Config.php`
- Modify: `inc/Admin/Settings/Validation.php`
- Modify: `inc/Admin/Settings/State.php`
- Modify: `inc/Admin/Settings/Page.php`
- Modify: `inc/Admin/Settings/Registrar.php`
- Modify: `inc/Admin/Settings/Help.php`
- Modify: `inc/Settings.php`
- Modify: `inc/UninstallOptions.php`
- Modify: `tests/phpunit/CloudflarePatternSearchClientTest.php`
- Create: `tests/phpunit/CloudflarePatternSearchInstanceManagerTest.php`
- Modify: `tests/phpunit/PatternIndexTest.php`
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `tests/phpunit/SettingsRegistrarTest.php`
- Modify: `tests/phpunit/UninstallTest.php`
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/features/pattern-recommendations.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `docs/reference/local-environment-setup.md`

## Task 1: Add Managed Instance Manager Tests And Class

**Files:**
- Create: `tests/phpunit/CloudflarePatternSearchInstanceManagerTest.php`
- Create: `inc/Cloudflare/PatternSearchInstanceManager.php`

- [ ] **Step 1: Write failing tests for ID, payload, and adoption**

Create `tests/phpunit/CloudflarePatternSearchInstanceManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Cloudflare\PatternSearchInstanceManager;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class CloudflarePatternSearchInstanceManagerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
	}

	public function test_build_managed_instance_id_is_stable_and_cloudflare_safe(): void {
		$id = PatternSearchInstanceManager::managed_instance_id();

		$this->assertMatchesRegularExpression( '/^flavor-agent-patterns-[a-f0-9]{16}$/', $id );
		$this->assertSame( $id, PatternSearchInstanceManager::managed_instance_id() );
		$this->assertLessThanOrEqual( 64, strlen( $id ) );
	}

	public function test_create_payload_uses_approved_configuration(): void {
		$payload = PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' );

		$this->assertSame( PatternSearchInstanceManager::managed_instance_id(), $payload['id'] );
		$this->assertSame( '@cf/qwen/qwen3-embedding-0.6b', $payload['embedding_model'] );
		$this->assertTrue( $payload['chunk'] );
		$this->assertSame( 1024, $payload['chunk_size'] );
		$this->assertSame( 15, $payload['chunk_overlap'] );
		$this->assertSame( [ 'keyword' => true, 'vector' => true ], $payload['index_method'] );
		$this->assertSame( [ 'keyword_tokenizer' => 'porter' ], $payload['indexing_options'] );
		$this->assertSame( [ 'keyword_match_mode' => 'or' ], $payload['retrieval_options'] );
		$this->assertSame( 'rrf', $payload['fusion_method'] );
		$this->assertSame( 50, $payload['max_num_results'] );
		$this->assertFalse( $payload['rewrite_query'] );
		$this->assertFalse( $payload['reranking'] );
		$this->assertFalse( $payload['cache'] );
		$this->assertArrayNotHasKey( 'type', $payload );
		$this->assertArrayNotHasKey( 'source', $payload );
		$this->assertArrayNotHasKey( 'token_id', $payload );
		$this->assertSame(
			[
				[ 'field_name' => 'pattern_name', 'data_type' => 'text' ],
				[ 'field_name' => 'candidate_type', 'data_type' => 'text' ],
				[ 'field_name' => 'source', 'data_type' => 'text' ],
				[ 'field_name' => 'synced_id', 'data_type' => 'text' ],
				[ 'field_name' => 'public_safe', 'data_type' => 'boolean' ],
			],
			$payload['custom_metadata']
		);
	}

	public function test_create_payload_falls_back_when_saved_model_is_not_supported_by_ai_search(): void {
		$payload = PatternSearchInstanceManager::build_create_payload( '@cf/future/workers-ai-only-model' );

		$this->assertSame( '@cf/qwen/qwen3-embedding-0.6b', $payload['embedding_model'] );

		$payload = PatternSearchInstanceManager::build_create_payload( '@cf/baai/bge-m3' );

		$this->assertSame( '@cf/baai/bge-m3', $payload['embedding_model'] );
	}

	public function test_credential_signature_changes_with_current_workers_ai_credentials(): void {
		$signature = PatternSearchInstanceManager::credential_signature( 'account-123', 'token-xyz', '@cf/qwen/qwen3-embedding-0.6b' );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $signature );
		$this->assertNotSame(
			$signature,
			PatternSearchInstanceManager::credential_signature( 'account-456', 'token-xyz', '@cf/qwen/qwen3-embedding-0.6b' )
		);
		$this->assertNotSame(
			$signature,
			PatternSearchInstanceManager::credential_signature( 'account-123', 'token-new', '@cf/qwen/qwen3-embedding-0.6b' )
		);
	}

	public function test_ensure_managed_instance_creates_when_missing(): void {
		WordPressTestState::$remote_get_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'result' => [] ] ),
			],
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'result' => [
							'id'       => 'cloudflare-owner-marker-123',
							'metadata' => [
								'pattern_name'   => PatternSearchInstanceManager::OWNER_MARKER_NAME,
								'candidate_type' => 'flavor_agent_owner',
								'source'         => 'flavor_agent',
								'synced_id'      => PatternSearchInstanceManager::site_hash(),
								'public_safe'    => true,
							],
						]
					]
				),
			],
		];
			WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ] ),
			],
			[
				'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [ 'result' => [ 'id' => 'cloudflare-owner-marker-123' ] ] ),
				],
			];

		$result = PatternSearchInstanceManager::ensure_managed_instance(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertIsArray( $result );
		$this->assertSame( PatternSearchInstanceManager::managed_instance_id(), $result['instance_id'] );
		$this->assertSame( 'created', $result['status'] );
		$this->assertStringContainsString( '/ai-search/namespaces/patterns/instances', WordPressTestState::$remote_get_calls[0]['url'] );
		$this->assertStringContainsString( '/ai-search/namespaces/patterns/instances', WordPressTestState::$remote_post_calls[0]['url'] );
		$this->assertStringContainsString( '/items', WordPressTestState::$remote_post_calls[1]['url'] );
			$this->assertStringContainsString( '/items?', WordPressTestState::$remote_get_calls[1]['url'] );
			$this->assertStringContainsString( 'metadata_filter=', WordPressTestState::$remote_get_calls[1]['url'] );
		}

	public function test_ensure_managed_instance_rejects_matching_id_without_schema(): void {
	WordPressTestState::$remote_get_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'result' => [
							[
								'id'              => PatternSearchInstanceManager::managed_instance_id(),
								'custom_metadata' => [
									[ 'field_name' => 'category', 'data_type' => 'text' ],
								],
							],
						],
					]
				),
			],
		];

		$result = PatternSearchInstanceManager::ensure_managed_instance(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_pattern_ai_search_incompatible_schema', $result->get_error_code() );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
	}

	public function test_ensure_managed_instance_adopts_only_when_existing_owner_marker_matches(): void {
			WordPressTestState::$remote_get_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'result' => [
							[
								'id'              => PatternSearchInstanceManager::managed_instance_id(),
								'custom_metadata' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' )['custom_metadata'],
							],
						],
					]
				),
			],
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'result' => [
							'id'       => 'cloudflare-owner-marker-123',
							'metadata' => [
								'pattern_name'   => PatternSearchInstanceManager::OWNER_MARKER_NAME,
								'candidate_type' => 'flavor_agent_owner',
								'source'         => 'flavor_agent',
								'synced_id'      => PatternSearchInstanceManager::site_hash(),
								'public_safe'    => true,
							],
						],
					]
				),
			],
		];

		$result = PatternSearchInstanceManager::ensure_managed_instance(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'adopted', $result['status'] );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
	}

	public function test_ensure_managed_instance_rejects_matching_id_with_missing_or_mismatched_owner_marker(): void {
		WordPressTestState::$remote_get_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'result' => [
							[
								'id'              => PatternSearchInstanceManager::managed_instance_id(),
								'custom_metadata' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' )['custom_metadata'],
							],
						],
					]
				),
			],
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'result' => [
							'id'       => 'other-site-owner-marker',
							'metadata' => [
								'pattern_name'   => PatternSearchInstanceManager::OWNER_MARKER_NAME,
								'candidate_type' => 'flavor_agent_owner',
								'source'         => 'flavor_agent',
								'synced_id'      => 'different-site',
								'public_safe'    => true,
							],
						],
					]
				),
			],
		];

		$result = PatternSearchInstanceManager::ensure_managed_instance(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_pattern_ai_search_owner_marker_mismatch', $result->get_error_code() );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
	}
}
```

Also add a create-conflict regression in the same test class:

- Queue the initial list response as empty.
- Queue the create response as HTTP `409`.
- Queue a second list response containing the managed instance with the approved schema.
- Queue an owner-marker GET response with matching metadata.
- Assert the result status is `adopted_after_conflict`.
- Repeat the conflict path with a mismatched owner marker and assert it returns `cloudflare_pattern_ai_search_owner_marker_mismatch`.

Also add an instance-discovery pagination regression:

- Queue a first list response with `result_info` showing more pages but no exact managed ID.
- Queue a second list response containing `PatternSearchInstanceManager::managed_instance_id()` with the approved schema.
- Queue an owner-marker metadata-filter response with matching metadata.
- Assert the first list URL includes `search=`, `page=1`, and `per_page=100`; assert the second list URL includes `page=2`.
- Assert the result status is `adopted` and no create request is sent.

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
composer run test:php -- --filter CloudflarePatternSearchInstanceManagerTest
```

Expected: exit `1` with `Class "FlavorAgent\Cloudflare\PatternSearchInstanceManager" not found`.

- [ ] **Step 3: Add the manager class**

Create `inc/Cloudflare/PatternSearchInstanceManager.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Embeddings\BaseHttpClient;

final class PatternSearchInstanceManager extends BaseHttpClient {
	public const OWNER_MARKER_NAME = '__flavor_agent_owner__';

	private const REQUEST_TIMEOUT = 20;
	private const SITE_HASH_LENGTH = 16;
	private const SUPPORTED_AI_SEARCH_EMBEDDING_MODELS = [
		'@cf/qwen/qwen3-embedding-0.6b',
		'@cf/baai/bge-m3',
		'@cf/baai/bge-large-en-v1.5',
		'@cf/google/embeddinggemma-300m',
		'google-ai-studio/gemini-embedding-001',
		'google-ai-studio/gemini-embedding-2-preview',
		'openai/text-embedding-3-small',
		'openai/text-embedding-3-large',
	];

	private function __construct() {}

	public static function managed_instance_id(): string {
		return 'flavor-agent-patterns-' . self::site_hash();
	}

	public static function site_hash(): string {
		$url = function_exists( 'home_url' ) ? home_url() : 'local';

		return substr( hash( 'sha256', strtolower( trim( (string) $url ) ) ), 0, self::SITE_HASH_LENGTH );
	}

	public static function credential_signature( string $account_id, string $api_token, string $embedding_model ): string {
		$payload = [
			self::site_hash(),
			trim( sanitize_text_field( $account_id ) ),
			wp_hash( trim( sanitize_text_field( $api_token ) ) ),
			self::ai_search_embedding_model( $embedding_model ),
			Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE,
		];

		return hash( 'sha256', implode( "\n", $payload ) );
	}

	public static function build_create_payload( string $embedding_model ): array {
		return [
			'id'                     => self::managed_instance_id(),
			'embedding_model'        => self::ai_search_embedding_model( $embedding_model ),
			'chunk'                  => true,
			'chunk_size'             => 1024,
			'chunk_overlap'          => 15,
			'custom_metadata'        => self::expected_custom_metadata(),
			'fusion_method'          => 'rrf',
			'index_method'           => [
				'keyword' => true,
				'vector'  => true,
			],
			'indexing_options'       => [
				'keyword_tokenizer' => 'porter',
			],
			'max_num_results'        => 50,
			'retrieval_options'      => [
				'keyword_match_mode' => 'or',
			],
			'rewrite_query'          => false,
			'reranking'              => false,
			'cache'                  => false,
			'public_endpoint_params' => [
				'enabled'                   => false,
				'search_endpoint'           => [ 'disabled' => true ],
				'chat_completions_endpoint' => [ 'disabled' => true ],
				'mcp'                       => [ 'disabled' => true ],
			],
		];
	}

	public static function ensure_managed_instance( string $account_id, string $api_token, string $embedding_model ): array|\WP_Error {
		$config = self::normalize_credentials( $account_id, $api_token );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$instances = self::list_instances( $config['account_id'], $config['api_token'] );

		if ( is_wp_error( $instances ) ) {
			return $instances;
		}

		foreach ( $instances as $instance ) {
			if ( self::managed_instance_id() !== (string) ( $instance['id'] ?? '' ) ) {
				continue;
			}

			$compatible = self::assert_compatible_instance( $instance );

			if ( is_wp_error( $compatible ) ) {
				return $compatible;
			}

			$marker = self::validate_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

			if ( is_wp_error( $marker ) ) {
				return $marker;
			}

				return [
					'instance_id' => self::managed_instance_id(),
					'status'      => 'adopted',
				];
			}

		$created = self::create_instance( $config['account_id'], $config['api_token'], $embedding_model );

		if ( is_wp_error( $created ) ) {
			if ( self::is_create_conflict_error( $created ) ) {
				return self::try_adopt_after_create_conflict( $config['account_id'], $config['api_token'] );
			}

			return $created;
		}

		$marker = self::write_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

		if ( is_wp_error( $marker ) ) {
			return $marker;
		}

		$validated_marker = self::validate_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

		if ( is_wp_error( $validated_marker ) ) {
			return $validated_marker;
		}

		return [
			'instance_id' => self::managed_instance_id(),
			'status'      => 'created',
		];
	}

	private static function expected_custom_metadata(): array {
		return [
			[ 'field_name' => 'pattern_name', 'data_type' => 'text' ],
			[ 'field_name' => 'candidate_type', 'data_type' => 'text' ],
			[ 'field_name' => 'source', 'data_type' => 'text' ],
			[ 'field_name' => 'synced_id', 'data_type' => 'text' ],
			[ 'field_name' => 'public_safe', 'data_type' => 'boolean' ],
		];
	}

	private static function ai_search_embedding_model( string $embedding_model ): string {
		$embedding_model = trim( sanitize_text_field( $embedding_model ) );

		return in_array( $embedding_model, self::SUPPORTED_AI_SEARCH_EMBEDDING_MODELS, true )
			? $embedding_model
			: WorkersAIEmbeddingConfiguration::DEFAULT_MODEL;
	}
}
```

Add these private methods in the same class:

```php
private static function normalize_credentials( string $account_id, string $api_token ): array|\WP_Error {
	$account_id = trim( sanitize_text_field( $account_id ) );
	$api_token  = trim( sanitize_text_field( $api_token ) );

	if ( '' === $account_id || '' === $api_token ) {
		return new \WP_Error(
			'missing_cloudflare_pattern_ai_search_credentials',
			'Cloudflare AI Search Pattern Storage needs the Embedding Model account ID and API token.',
			[ 'status' => 400 ]
		);
	}

	return [
		'account_id' => $account_id,
		'api_token'  => $api_token,
	];
}

private static function instances_url( string $account_id ): string {
	return sprintf(
		'https://api.cloudflare.com/client/v4/accounts/%s/ai-search/namespaces/%s/instances',
		rawurlencode( $account_id ),
		rawurlencode( Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE )
	);
}

private static function instance_items_url( string $account_id, string $instance_id ): string {
	return self::instances_url( $account_id ) . '/' . rawurlencode( $instance_id ) . '/items';
}

private static function authorization_headers( string $api_token ): array {
	return [ 'Authorization' => 'Bearer ' . $api_token ];
}

private static function list_instances( string $account_id, string $api_token ): array|\WP_Error {
	$instances = [];
	$page      = 1;
	$per_page  = 100;

	do {
		$response = self::request_json(
			add_query_arg(
				[
					'search'   => self::managed_instance_id(),
					'page'     => $page,
					'per_page' => $per_page,
				],
				self::instances_url( $account_id )
			),
			[
				'method'  => 'GET',
				'headers' => self::authorization_headers( $api_token ),
			],
			'Cloudflare AI Search instance list',
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_instance_list_error',
				'Cloudflare AI Search instance list failed.',
				[ 'status' => 502 ]
			);
		}

		foreach ( (array) ( $response['data']['result'] ?? [] ) as $instance ) {
			if ( is_array( $instance ) && self::managed_instance_id() === (string) ( $instance['id'] ?? '' ) ) {
				$instances[] = $instance;
			}
		}

		$result_info = is_array( $response['data']['result_info'] ?? null ) ? $response['data']['result_info'] : [];
		$total_count = max( 0, (int) ( $result_info['total_count'] ?? count( (array) ( $response['data']['result'] ?? [] ) ) ) );
		$page_size   = max( 1, (int) ( $result_info['per_page'] ?? $per_page ) );
		$total_pages = max( 1, (int) ceil( $total_count / $page_size ) );
		++$page;
	} while ( $page <= $total_pages && [] === $instances );

	return $instances;
}

private static function create_instance( string $account_id, string $api_token, string $embedding_model ): true|\WP_Error {
	$response = self::post_json(
		self::instances_url( $account_id ),
		array_merge( self::authorization_headers( $api_token ), [ 'Content-Type' => 'application/json' ] ),
		self::encode_json( self::build_create_payload( $embedding_model ) ),
		'Cloudflare AI Search instance create',
		self::REQUEST_TIMEOUT
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( ! in_array( $response['status'], [ 200, 201 ], true ) ) {
		return new \WP_Error(
			'cloudflare_pattern_ai_search_instance_create_error',
			'Cloudflare AI Search managed pattern index could not be created.',
			[
				'status'      => 502,
				'http_status' => $response['status'],
			]
		);
	}

	return true;
}

private static function encode_json( mixed $value ): string {
	$encoded = wp_json_encode( $value );

	return is_string( $encoded ) ? $encoded : '';
}

private static function normalize_custom_metadata_schema( mixed $schema ): array {
	if ( ! is_array( $schema ) ) {
		return [];
	}

	$normalized = [];

	foreach ( $schema as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		$normalized[] = [
			'field_name' => strtolower( sanitize_key( (string) ( $field['field_name'] ?? '' ) ) ),
			'data_type'  => strtolower( sanitize_key( (string) ( $field['data_type'] ?? '' ) ) ),
		];
	}

	usort(
		$normalized,
		static fn( array $a, array $b ): int => strcmp( $a['field_name'], $b['field_name'] )
	);

	return $normalized;
}

private static function assert_compatible_instance( array $instance ): true|\WP_Error {
	$actual   = self::normalize_custom_metadata_schema( $instance['custom_metadata'] ?? [] );
	$expected = self::normalize_custom_metadata_schema( self::expected_custom_metadata() );

	if ( $actual !== $expected ) {
		return new \WP_Error(
			'cloudflare_pattern_ai_search_incompatible_schema',
			'The existing Cloudflare AI Search managed pattern index does not use the Flavor Agent metadata schema.',
			[ 'status' => 409 ]
		);
	}

	return true;
}

private static function owner_marker_metadata(): array {
	$metadata = [
		'pattern_name'   => self::OWNER_MARKER_NAME,
		'candidate_type' => 'flavor_agent_owner',
		'source'         => 'flavor_agent',
		'synced_id'      => self::site_hash(),
		'public_safe'    => true,
	];

	return $metadata;
}

private static function owner_marker_filter(): array {
	return [
		'pattern_name'   => [ '$eq' => self::OWNER_MARKER_NAME ],
		'candidate_type' => [ '$eq' => 'flavor_agent_owner' ],
		'source'         => [ '$eq' => 'flavor_agent' ],
		'synced_id'      => [ '$eq' => self::site_hash() ],
	];
}

private static function owner_marker_list_url( string $account_id, string $instance_id ): string {
	return add_query_arg(
		[
			'metadata_filter' => self::encode_json( self::owner_marker_filter() ),
			'per_page'        => 5,
		],
		self::instance_items_url( $account_id, $instance_id )
	);
}

private static function write_owner_marker( string $account_id, string $api_token, string $instance_id ): true|\WP_Error {
	$metadata = self::owner_marker_metadata();

	$response = self::post_multipart(
		self::instance_items_url( $account_id, $instance_id ),
		self::authorization_headers( $api_token ),
		[
			'metadata'            => self::encode_json( $metadata ),
			'wait_for_completion' => 'true',
		],
		[
			'name'         => 'file',
			'filename'     => self::OWNER_MARKER_NAME . '.md',
			'contents'     => "# Flavor Agent Owner\n\nSite hash: " . self::site_hash() . "\n",
			'content_type' => 'text/markdown; charset=UTF-8',
		],
		'Cloudflare AI Search owner marker upload',
		self::REQUEST_TIMEOUT
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( ! in_array( $response['status'], [ 200, 201 ], true ) ) {
		return new \WP_Error(
			'cloudflare_pattern_ai_search_owner_marker_error',
			'Flavor Agent could not write the Cloudflare AI Search owner marker.',
			[ 'status' => 502 ]
		);
	}

	return true;
}

private static function validate_owner_marker( string $account_id, string $api_token, string $instance_id ): true|\WP_Error {
	$response = self::request_json(
		self::owner_marker_list_url( $account_id, $instance_id ),
		[
			'method'  => 'GET',
			'headers' => self::authorization_headers( $api_token ),
		],
		'Cloudflare AI Search owner marker read',
		self::REQUEST_TIMEOUT
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
		return new \WP_Error(
			'cloudflare_pattern_ai_search_owner_marker_error',
			'Flavor Agent could not read the Cloudflare AI Search owner marker.',
			[ 'status' => 502 ]
		);
	}

	$items = is_array( $response['data']['result'] ?? null ) ? $response['data']['result'] : [];

	if ( [] === $items ) {
		return new \WP_Error(
			'cloudflare_pattern_ai_search_owner_marker_missing',
			'The existing Cloudflare AI Search managed pattern index is missing the Flavor Agent owner marker.',
			[ 'status' => 409 ]
		);
	}

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$metadata = is_array( $item['metadata'] ?? null )
			? $item['metadata']
			: ( is_array( $item['item']['metadata'] ?? null ) ? $item['item']['metadata'] : [] );

		if ( self::normalize_owner_marker_metadata( $metadata ) === self::owner_marker_metadata() ) {
			return true;
		}
	}

	return new \WP_Error(
		'cloudflare_pattern_ai_search_owner_marker_mismatch',
		'The existing Cloudflare AI Search managed pattern index belongs to a different Flavor Agent install.',
		[ 'status' => 409 ]
	);
}

private static function normalize_owner_marker_metadata( array $metadata ): array {
	return [
		'pattern_name'   => sanitize_text_field( (string) ( $metadata['pattern_name'] ?? '' ) ),
		'candidate_type' => sanitize_text_field( (string) ( $metadata['candidate_type'] ?? '' ) ),
		'source'         => sanitize_text_field( (string) ( $metadata['source'] ?? '' ) ),
		'synced_id'      => sanitize_text_field( (string) ( $metadata['synced_id'] ?? '' ) ),
		'public_safe'    => rest_sanitize_boolean( $metadata['public_safe'] ?? false ),
	];
}

private static function is_create_conflict_error( \WP_Error $error ): bool {
	$data = $error->get_error_data();

	return is_array( $data ) && 409 === (int) ( $data['http_status'] ?? 0 );
}

private static function try_adopt_after_create_conflict( string $account_id, string $api_token ): array|\WP_Error {
	$instances = self::list_instances( $account_id, $api_token );

	if ( is_wp_error( $instances ) ) {
		return $instances;
	}

	foreach ( $instances as $instance ) {
		if ( self::managed_instance_id() !== (string) ( $instance['id'] ?? '' ) ) {
			continue;
		}

		$compatible = self::assert_compatible_instance( $instance );
		if ( is_wp_error( $compatible ) ) {
			return $compatible;
		}

		$marker = self::validate_owner_marker( $account_id, $api_token, self::managed_instance_id() );
		if ( is_wp_error( $marker ) ) {
			return $marker;
		}

		return [
			'instance_id' => self::managed_instance_id(),
			'status'      => 'adopted_after_conflict',
		];
	}

	return new \WP_Error(
		'cloudflare_pattern_ai_search_instance_create_conflict',
		'Cloudflare reported a managed pattern index conflict, but the instance could not be adopted safely.',
		[ 'status' => 409 ]
	);
}
```

- [ ] **Step 4: Finish manager implementation**

Add these method behaviors:

- `normalize_credentials()` trims values and returns `WP_Error( 'missing_cloudflare_pattern_ai_search_credentials', ... )` when account ID or token is empty.
- `list_instances()` calls `GET https://api.cloudflare.com/client/v4/accounts/{account}/ai-search/namespaces/patterns/instances` with `search`, `page`, and `per_page` query parameters. It filters returned IDs exactly to `managed_instance_id()` and follows pagination until the managed ID is found or the result set is exhausted.
- `create_instance()` calls `POST` to the same collection URL with `build_create_payload()`.
- `build_create_payload()` resolves unsupported saved embedding models to `WorkersAIEmbeddingConfiguration::DEFAULT_MODEL` before sending the AI Search create request.
- `assert_compatible_instance()` normalizes `custom_metadata` by lowercase `field_name` and `data_type`, compares it exactly to `expected_custom_metadata()`, and returns `cloudflare_pattern_ai_search_incompatible_schema` on mismatch.
- `validate_owner_marker()` lists `/items` with a `metadata_filter` for the owner-marker metadata below and returns `cloudflare_pattern_ai_search_owner_marker_missing` or `cloudflare_pattern_ai_search_owner_marker_mismatch` without writing anything when an existing instance cannot prove ownership. Do not use `GET /items/__flavor_agent_owner__`; Cloudflare item IDs are not assumed to equal uploaded filenames.
- `write_owner_marker()` uploads `__flavor_agent_owner__.md` to `/items` with this metadata, treats the returned item ID as opaque, and the create flow then calls `validate_owner_marker()` before saving the instance ID:

```php
$metadata = [
	'pattern_name'   => self::OWNER_MARKER_NAME,
	'candidate_type' => 'flavor_agent_owner',
	'source'         => 'flavor_agent',
	'synced_id'      => self::site_hash(),
	'public_safe'    => true,
];
```

- `create_instance()` includes the Cloudflare HTTP status in error data. When the create response is `409`, `ensure_managed_instance()` re-lists and adopts only if schema and owner-marker validation pass.

- [ ] **Step 5: Run manager tests**

Run:

```bash
composer run test:php -- --filter CloudflarePatternSearchInstanceManagerTest
```

Expected: exit `0`.

## Task 2: Wire Managed Setup Into Settings Validation

**Files:**
- Modify: `inc/Admin/Settings/Config.php`
- Modify: `inc/Admin/Settings/Validation.php`
- Modify: `inc/Admin/Settings/State.php`
- Modify: `inc/Admin/Settings/Page.php`
- Modify: `inc/Admin/Settings/Registrar.php`
- Modify: `inc/Admin/Settings/Help.php`
- Modify: `inc/Settings.php`
- Modify: `inc/UninstallOptions.php`
- Modify: `inc/Cloudflare/PatternSearchClient.php`
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `tests/phpunit/SettingsRegistrarTest.php`
- Modify: `tests/phpunit/CloudflarePatternSearchClientTest.php`
- Modify: `tests/phpunit/UninstallTest.php`

- [ ] **Step 1: Add failing settings tests**

In `tests/phpunit/SettingsTest.php`, add:

```php
public function test_cloudflare_pattern_storage_save_auto_creates_managed_instance(): void {
	$_POST = [
		'option_page' => Config::OPTION_GROUP,
		'_wpnonce'    => wp_create_nonce( Config::OPTION_GROUP . '-options' ),
		Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
		'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
		'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
	];
	WordPressTestState::$remote_post_responses = [
		[
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'data' => [ [ 'embedding' => [ 0.1, 0.2, 0.3 ] ] ] ] ),
		],
		[
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result' => \FlavorAgent\Cloudflare\PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ),
				]
			),
		],
		[
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'result' => [ 'id' => 'cloudflare-owner-marker-123' ] ] ),
		],
	];
	WordPressTestState::$remote_get_responses = [
		[
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'result' => [] ] ),
		],
		[
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result' => [
						[
							'id'       => 'cloudflare-owner-marker-123',
							'metadata' => [
								'pattern_name'   => \FlavorAgent\Cloudflare\PatternSearchInstanceManager::OWNER_MARKER_NAME,
								'candidate_type' => 'flavor_agent_owner',
								'source'         => 'flavor_agent',
								'synced_id'      => \FlavorAgent\Cloudflare\PatternSearchInstanceManager::site_hash(),
								'public_safe'    => true,
							],
						],
					],
				]
			),
		],
	];

	$this->assertSame( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH, Settings::sanitize_pattern_retrieval_backend( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH ) );
	$this->assertSame( 'account-123', Settings::sanitize_cloudflare_workers_ai_account_id( 'account-123' ) );
	$this->assertSame( 'token-xyz', Settings::sanitize_cloudflare_workers_ai_api_token( 'token-xyz' ) );
	$this->assertSame( '@cf/qwen/qwen3-embedding-0.6b', Settings::sanitize_cloudflare_workers_ai_embedding_model( '@cf/qwen/qwen3-embedding-0.6b' ) );
	$this->assertSame(
		\FlavorAgent\Cloudflare\PatternSearchInstanceManager::managed_instance_id(),
		Settings::sanitize_cloudflare_pattern_ai_search_instance_id( '' )
	);
	$this->assertStringContainsString( '/ai-search/namespaces/patterns/instances', WordPressTestState::$remote_get_calls[0]['url'] );
	$this->assertStringContainsString( '/items?', WordPressTestState::$remote_get_calls[1]['url'] );
	$this->assertStringContainsString( 'metadata_filter=', WordPressTestState::$remote_get_calls[1]['url'] );
	}
	```

Add a second settings regression that exercises the real posted Settings API shape instead of only calling the instance sanitizer directly:

- Call `Settings::register_settings()` so `$GLOBALS['wp_registered_settings']` contains the actual sanitize callbacks.
- Build `$_POST` with `option_page`, nonce, `Config::OPTION_PATTERN_RETRIEVAL_BACKEND`, Workers AI account ID/token/model, and the hidden `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID` field set to the current saved value or `''`.
- Invoke registered sanitize callbacks for the posted options in the same order WordPress would process the settings group.
- Assert the managed instance ID is saved, the validated credential signature option is saved, and the owner-marker validation URL uses `/items?metadata_filter=`.

Add a stale-readiness regression for credential changes:

- Seed an old managed instance ID and old validated signature in options.
- Post new valid Workers AI account/token/model values with Cloudflare AI Search selected.
- Queue the manager response as an owner-marker mismatch or permission error.
- Assert the old instance ID may remain for debugging, but `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE` is cleared or no longer matches the current credential signature.
- Assert `PatternSearchClient::is_configured()` and the admin state report Cloudflare AI Search Pattern Storage as not ready, and that a settings error is recorded.

In `tests/phpunit/SettingsRegistrarTest.php`, update the critical fields assertion so the Cloudflare AI Search Pattern Storage section no longer renders `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID` as a default table field:

```php
$this->assertArrayNotHasKey(
	Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
	$fields['flavor_agent_cloudflare_pattern_ai_search']
);
```

Keep the setting registered for storage and uninstall cleanup; this assertion only removes the primary manual setup field.

Update the existing `SettingsTest::test_sanitize_private_pattern_ai_search_settings_uses_saved_private_credentials_when_present()` into a legacy-ignore regression:

```php
$this->assertSame(
	'https://api.cloudflare.com/client/v4/accounts/workers-account/ai-search/namespaces/patterns/instances/pattern-index/search',
	WordPressTestState::$remote_post_calls[0]['url']
);
$this->assertSame(
	'Bearer workers-token',
	WordPressTestState::$remote_post_calls[0]['args']['headers']['Authorization'] ?? null
);
```

The saved `flavor_agent_cloudflare_pattern_ai_search_account_id`, namespace, and API token values should remain in the fixture only to prove they are ignored.

- [ ] **Step 2: Run settings tests to verify failure**

Run:

```bash
composer run test:php -- --filter 'SettingsTest|SettingsRegistrarTest'
```

Expected: exit `1`; the new managed instance setup test fails because settings validation still expects a submitted index name.

- [ ] **Step 3: Add validation entry point**

In `inc/Admin/Settings/Config.php`, add an internal readiness option:

```php
public const OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE = 'flavor_agent_cloudflare_pattern_ai_search_validated_signature';
```

Add the new option to `inc/UninstallOptions.php` and update `tests/phpunit/UninstallTest.php` so uninstall cleanup covers it. Do not render the signature option or treat it as operator-editable state.

In `inc/Admin/Settings/Validation.php`, update `resolve_pattern_ai_search_submission_values()` so empty submitted instance ID is allowed when:

- selected Pattern Storage is `cloudflare_ai_search`;
- submitted or saved Workers AI account ID and token are present;
- the settings submission nonce is valid.

Do not resolve account ID, API token, or namespace from `flavor_agent_cloudflare_pattern_ai_search_*` legacy options. They remain cleanup-only. `resolve_pattern_ai_search_submission_values()` should pull `$workers_ai_values = self::resolve_workers_ai_submission_values()` and build the managed setup request from those values plus `Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE`.

Call:

```php
$workers_ai_values = self::resolve_workers_ai_submission_values();

$managed = PatternSearchInstanceManager::ensure_managed_instance(
	$workers_ai_values['flavor_agent_cloudflare_workers_ai_account_id'] ?? '',
	$workers_ai_values['flavor_agent_cloudflare_workers_ai_api_token'] ?? '',
	$workers_ai_values['flavor_agent_cloudflare_workers_ai_embedding_model'] ?? WorkersAIEmbeddingConfiguration::DEFAULT_MODEL
);
```

If successful, set:

```php
$values[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE ] = Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE;
$values[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID ] = $managed['instance_id'];
update_option(
	Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
	PatternSearchInstanceManager::credential_signature(
		$workers_ai_values['flavor_agent_cloudflare_workers_ai_account_id'] ?? '',
		$workers_ai_values['flavor_agent_cloudflare_workers_ai_api_token'] ?? '',
		$workers_ai_values['flavor_agent_cloudflare_workers_ai_embedding_model'] ?? WorkersAIEmbeddingConfiguration::DEFAULT_MODEL
	),
	false
);
```

If it returns `WP_Error`, preserve the previous saved instance value only as debug context, delete `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE`, and report the error through the existing settings error channel. The admin state and runtime config must treat this as not ready.

Make sure this managed setup path runs when an operator selects Cloudflare AI Search even though the manual index-name field is no longer visible:

- render a hidden `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID` field that submits the current saved value;
- keep the setting registered so WordPress invokes `sanitize_cloudflare_pattern_ai_search_instance_id()` on the real settings form submission;
- have `sanitize_pattern_retrieval_backend()` call the cached resolver when Cloudflare AI Search is posted and the hidden field is unexpectedly absent, so direct/backend-only submissions do not silently skip managed setup.

In `inc/Cloudflare/PatternSearchClient.php`, update `get_config()` so runtime search, sync, list, upload, and delete use:

- `flavor_agent_cloudflare_workers_ai_account_id`
- `Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE`
- `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID`
- `flavor_agent_cloudflare_workers_ai_api_token`

Remove the fallback precedence for `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID`, `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE`, and `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN` from runtime config. Leave those option names in `UninstallOptions` for cleanup.

Before returning config, compute `PatternSearchInstanceManager::credential_signature()` from the current Workers AI account ID, API token, and embedding model. If it does not match `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE`, return a `WP_Error( 'cloudflare_pattern_ai_search_not_validated', ... )` so `PatternSearchClient::is_configured()`, sync, and search cannot use an old instance ID with new credentials.

In `inc/Admin/Settings/State.php`, update Cloudflare AI Search readiness helpers so `cloudflare_pattern_ai_search_index_configured()` requires both a non-empty managed instance ID and a matching validated credential signature. Keep `determine_default_open_group()` opening `patterns` when Cloudflare AI Search is selected but this readiness check fails.

- [ ] **Step 4: Update settings UI copy**

In `inc/Admin/Settings/Page.php`, keep Cloudflare AI Search Pattern Storage visible but change the primary message from manual index entry to managed setup state:

```php
self::render_subsection_heading(
	__( 'Cloudflare AI Search Pattern Storage', 'flavor-agent' ),
	__( 'Managed pattern index using the saved Embedding Model credentials.', 'flavor-agent' )
);
```

Show the saved instance ID as advanced/debug information after setup. Do not show account/token fields in Pattern Storage.

Add a managed status panel in the Pattern Storage section and cover it with `SettingsTest` page-rendering assertions:

- **Ready:** show the managed instance ID and "Managed pattern index ready" when the saved instance ID and validated credential signature match current Workers AI credentials.
- **Needs Embedding Model credentials:** show the existing Embedding Model prerequisite action when Workers AI account ID/token are missing.
- **Create action:** when Cloudflare AI Search is selected, Workers AI credentials are present, and no validated signature exists, show a submit action labelled "Create managed pattern index" that posts the hidden instance field and runs the manager through the normal settings save path.
- **Failed or incompatible:** when the latest settings save recorded a manager `WP_Error`, show the settings error plus "Managed pattern index needs attention"; keep the old instance ID only in advanced/debug text, not as a ready state.
- **Creating/adopting:** during the submitted save cycle, use the existing settings error/notice channel to report "creating", "adopted", or "ready" status from the manager result.

Update any old copy that says operators must complete a Cloudflare AI Search pattern index name before syncing. The sync prerequisite should now say Embedding Model credentials must be saved and the managed pattern index must be ready.

In `inc/Admin/Settings/Help.php`, replace the old manual-index text with:

```php
'<p>' . esc_html__( 'Private AI Search pattern storage reuses the Embedding Model account and token. Flavor Agent creates and owns a dedicated pattern index when Cloudflare AI Search Pattern Storage is selected.', 'flavor-agent' ) . '</p>',
```

- [ ] **Step 5: Run focused settings tests**

Run:

```bash
composer run test:php -- --filter 'SettingsTest|SettingsRegistrarTest'
```

Expected: exit `0`.

## Task 3: Protect Owner Marker And Unknown Items During Pattern Sync

**Files:**
- Modify: `inc/Cloudflare/PatternSearchClient.php`
- Modify: `inc/Patterns/PatternIndex.php`
- Modify: `tests/phpunit/CloudflarePatternSearchClientTest.php`
- Modify: `tests/phpunit/PatternIndexTest.php`

- [ ] **Step 1: Add failing cleanup regression**

In `tests/phpunit/PatternIndexTest.php`, add a Cloudflare AI Search sync test that queues remote IDs containing:

```php
[
	\FlavorAgent\Cloudflare\PatternSearchInstanceManager::OWNER_MARKER_NAME,
	'cloudflare-owner-marker-123',
	PatternIndex::pattern_uuid( 'theme/retired-pattern' ),
	'manual-operator-note',
]
```

Register one current pattern, run `PatternIndex::sync()`, and assert:

```php
$this->assertNull( $this->find_optional_remote_post_call( '/items/' . rawurlencode( \FlavorAgent\Cloudflare\PatternSearchInstanceManager::OWNER_MARKER_NAME ), 'DELETE' ) );
$this->assertNull( $this->find_optional_remote_post_call( '/items/' . rawurlencode( 'cloudflare-owner-marker-123' ), 'DELETE' ) );
$this->assertNull( $this->find_optional_remote_post_call( '/items/' . rawurlencode( 'manual-operator-note' ), 'DELETE' ) );
$this->assertIsArray( $this->find_remote_post_call( '/items/' . rawurlencode( PatternIndex::pattern_uuid( 'theme/retired-pattern' ) ), 'DELETE' ) );
```

Seed the saved `pattern_fingerprints` state with `PatternIndex::pattern_uuid( 'theme/retired-pattern' )` so that retired pattern is a known Flavor Agent item. Do not seed `manual-operator-note` or `cloudflare-owner-marker-123`; the test must prove unknown remote items are preserved even when Cloudflare assigns an opaque ID to the owner marker.

If `find_remote_post_call()` currently assumes a call exists, add a non-throwing helper:

```php
private function find_optional_remote_post_call( string $url_suffix, string $method ): ?array {
	foreach ( WordPressTestState::$remote_post_calls as $call ) {
		if (
			str_ends_with( (string) ( $call['url'] ?? '' ), $url_suffix )
			&& strtoupper( (string) ( $call['args']['method'] ?? 'POST' ) ) === strtoupper( $method )
		) {
			return $call;
		}
	}

	return null;
}
```

- [ ] **Step 2: Run PatternIndex test to verify failure**

Run:

```bash
composer run test:php -- --filter PatternIndexTest
```

Expected: exit `1`; the owner marker and unknown remote item are currently treated as stale and deleted.

- [ ] **Step 3: Skip owner marker in cleanup**

In `inc/Patterns/PatternIndex.php`, update the Cloudflare AI Search delete loop:

```php
foreach ( $remote_ids as $remote_id ) {
	$remote_id = sanitize_text_field( (string) $remote_id );

	if ( \FlavorAgent\Cloudflare\PatternSearchInstanceManager::OWNER_MARKER_NAME === $remote_id ) {
		continue;
	}

	if ( '' === $remote_id || ! isset( $previous_pattern_fingerprints[ $remote_id ] ) ) {
		continue;
	}

	if ( ! isset( $current_ids[ $remote_id ] ) ) {
		$to_delete[] = $remote_id;
	}
}
```

- [ ] **Step 4: Keep owner marker out of retrieval**

In `inc/Cloudflare/PatternSearchClient.php`, make sure normalized search chunks drop the owner marker even if a remote response returns it. Skip by owner metadata rather than item ID:

```php
if ( 'flavor_agent_owner' === (string) ( $metadata['candidate_type'] ?? '' ) ) {
	continue;
}
```

Place this immediately after metadata is normalized in `normalize_chunks()`. Add a regression with a returned chunk whose item ID is an opaque value but whose metadata contains `candidate_type: flavor_agent_owner`; assert it is not returned as a recommendation.

- [ ] **Step 5: Run focused sync tests**

Run:

```bash
composer run test:php -- --filter 'PatternIndexTest|CloudflarePatternSearchClientTest'
```

Expected: exit `0`.

## Task 4: Update Docs And Source Of Truth

**Files:**
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/features/pattern-recommendations.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `docs/reference/local-environment-setup.md`

- [ ] **Step 1: Update source-of-truth settings contract**

In `docs/SOURCE_OF_TRUTH.md`, replace manual index-name language for private Cloudflare AI Search with:

```markdown
| Private Cloudflare AI Search | Managed pattern indexing and retrieval | Pattern recommendations when the Cloudflare AI Search pattern backend is selected | Managed `flavor-agent-patterns-{site_hash}` AI Search instance; account/token come from the Embedding Model settings |
```

Update the Patterns bullet to say:

```markdown
- **Patterns** -- Pattern Storage selector, Qdrant URL/key, managed Cloudflare AI Search pattern-index status, backend-specific ranking thresholds, max results, and the `Sync Pattern Catalog` status/metrics/manual trigger panel. Pattern Storage is infrastructure, not another AI model choice.
```

- [ ] **Step 2: Update local environment setup**

In `docs/reference/local-environment-setup.md`, replace the manual dashboard setup under "Cloudflare Pattern AI Search Metadata" with:

```markdown
Before selecting Cloudflare AI Search as the pattern retrieval backend, save Cloudflare account ID and API token values under Embedding Model. When Cloudflare AI Search Pattern Storage is selected, Flavor Agent creates or adopts a dedicated managed AI Search instance named `flavor-agent-patterns-{site_hash}` in the `patterns` namespace. The token must have AI Search permissions in addition to Workers AI embedding access.

The managed instance uses built-in storage, Cloudflare-managed R2 and Vectorize resources, hybrid keyword/vector indexing, 1024-token chunks, 15 percent overlap, and exactly these five custom metadata fields:
```

Keep the existing five-field metadata table.

- [ ] **Step 3: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: exit `0`.

## Task 5: Final Verification

**Files:**
- Verify all files changed by Tasks 1-4.

- [ ] **Step 1: Run focused PHPUnit**

Run:

```bash
composer run test:php -- --filter 'CloudflarePatternSearchInstanceManagerTest|CloudflarePatternSearchClientTest|PatternIndexTest|SettingsTest|SettingsRegistrarTest|UninstallTest'
```

Expected: exit `0`.

- [ ] **Step 2: Run docs validation**

Run:

```bash
npm run check:docs
```

Expected: exit `0`.

- [ ] **Step 3: Run fast aggregate verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: exit `0` or a known unrelated baseline failure recorded with the exact failing step and `output/verify/summary.json` timestamp.

- [ ] **Step 4: Inspect final diff**

Run:

```bash
git diff -- docs/superpowers/specs/2026-05-06-managed-cloudflare-ai-search-pattern-storage-design.md docs/superpowers/plans/2026-05-06-managed-cloudflare-ai-search-pattern-storage.md inc/Cloudflare inc/Patterns inc/Admin tests/phpunit docs
```

Expected: diff contains managed AI Search instance lifecycle, owner-marker protection, settings readiness updates, and matching docs. It must not include hand-edited `build/` or `dist/` artifacts.
