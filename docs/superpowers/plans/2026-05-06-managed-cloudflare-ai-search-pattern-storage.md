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

	public function test_ensure_managed_instance_creates_when_missing(): void {
		WordPressTestState::$remote_get_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'result' => [] ] ),
			],
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ] ),
			],
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'success' => true ] ),
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
}
```

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
	public const OWNER_ITEM_ID = '__flavor_agent_owner__';

	private const REQUEST_TIMEOUT = 20;
	private const SITE_HASH_LENGTH = 16;

	private function __construct() {}

	public static function managed_instance_id(): string {
		return 'flavor-agent-patterns-' . self::site_hash();
	}

	public static function site_hash(): string {
		$url = function_exists( 'home_url' ) ? home_url() : 'local';

		return substr( hash( 'sha256', strtolower( trim( (string) $url ) ) ), 0, self::SITE_HASH_LENGTH );
	}

	public static function build_create_payload( string $embedding_model ): array {
		return [
			'id'                     => self::managed_instance_id(),
			'embedding_model'        => '' !== trim( $embedding_model ) ? trim( $embedding_model ) : WorkersAIEmbeddingConfiguration::DEFAULT_MODEL,
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

			$marker = self::ensure_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

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
			return $created;
		}

		$marker = self::ensure_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

		if ( is_wp_error( $marker ) ) {
			return $marker;
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
	$response = self::request_json(
		self::instances_url( $account_id ),
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

	return is_array( $response['data']['result'] ?? null ) ? $response['data']['result'] : [];
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
			[ 'status' => 502 ]
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

private static function ensure_owner_marker( string $account_id, string $api_token, string $instance_id ): true|\WP_Error {
	$metadata = [
		'pattern_name'   => self::OWNER_ITEM_ID,
		'candidate_type' => 'flavor_agent_owner',
		'source'         => 'flavor_agent',
		'synced_id'      => self::site_hash(),
		'public_safe'    => true,
	];

	$response = self::post_multipart(
		self::instance_items_url( $account_id, $instance_id ),
		self::authorization_headers( $api_token ),
		[
			'metadata'            => self::encode_json( $metadata ),
			'wait_for_completion' => 'true',
		],
		[
			'name'         => 'file',
			'filename'     => self::OWNER_ITEM_ID . '.md',
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
```

- [ ] **Step 4: Finish manager implementation**

Add these method behaviors:

- `normalize_credentials()` trims values and returns `WP_Error( 'missing_cloudflare_pattern_ai_search_credentials', ... )` when account ID or token is empty.
- `list_instances()` calls `GET https://api.cloudflare.com/client/v4/accounts/{account}/ai-search/namespaces/patterns/instances`.
- `create_instance()` calls `POST` to the same collection URL with `build_create_payload()`.
- `assert_compatible_instance()` normalizes `custom_metadata` by lowercase `field_name` and `data_type`, compares it exactly to `expected_custom_metadata()`, and returns `cloudflare_pattern_ai_search_incompatible_schema` on mismatch.
- `ensure_owner_marker()` uploads `__flavor_agent_owner__.md` to `/items` with this metadata:

```php
$metadata = [
	'pattern_name'   => self::OWNER_ITEM_ID,
	'candidate_type' => 'flavor_agent_owner',
	'source'         => 'flavor_agent',
	'synced_id'      => self::site_hash(),
	'public_safe'    => true,
];
```

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
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `tests/phpunit/SettingsRegistrarTest.php`

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
			'body'     => wp_json_encode( [ 'success' => true ] ),
		],
	];
	WordPressTestState::$remote_get_responses = [
		[
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'result' => [] ] ),
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
}
```

In `tests/phpunit/SettingsRegistrarTest.php`, update the critical fields assertion so the Cloudflare AI Search Pattern Storage section no longer renders `Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID` as a default table field:

```php
$this->assertArrayNotHasKey(
	Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
	$fields['flavor_agent_cloudflare_pattern_ai_search']
);
```

Keep the setting registered for storage and uninstall cleanup; this assertion only removes the primary manual setup field.

- [ ] **Step 2: Run settings tests to verify failure**

Run:

```bash
composer run test:php -- --filter 'SettingsTest|SettingsRegistrarTest'
```

Expected: exit `1`; the new managed instance setup test fails because settings validation still expects a submitted index name.

- [ ] **Step 3: Add validation entry point**

In `inc/Admin/Settings/Validation.php`, update `resolve_pattern_ai_search_submission_values()` so empty submitted instance ID is allowed when:

- selected Pattern Storage is `cloudflare_ai_search`;
- submitted or saved Workers AI account ID and token are present;
- the settings submission nonce is valid.

Call:

```php
$managed = PatternSearchInstanceManager::ensure_managed_instance(
	$values['flavor_agent_cloudflare_workers_ai_account_id'],
	$values['flavor_agent_cloudflare_workers_ai_api_token'],
	$workers_ai_values['flavor_agent_cloudflare_workers_ai_embedding_model'] ?? WorkersAIEmbeddingConfiguration::DEFAULT_MODEL
);
```

If successful, set:

```php
$values[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE ] = Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE;
$values[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID ] = $managed['instance_id'];
```

If it returns `WP_Error`, preserve the previous saved instance value and report the error through the existing settings error channel.

- [ ] **Step 4: Update settings UI copy**

In `inc/Admin/Settings/Page.php`, keep Cloudflare AI Search Pattern Storage visible but change the primary message from manual index entry to managed setup state:

```php
self::render_subsection_heading(
	__( 'Cloudflare AI Search Pattern Storage', 'flavor-agent' ),
	__( 'Managed pattern index using the saved Embedding Model credentials.', 'flavor-agent' )
);
```

Show the saved instance ID as advanced/debug information after setup. Do not show account/token fields in Pattern Storage.

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

## Task 3: Protect Owner Marker During Pattern Sync

**Files:**
- Modify: `inc/Cloudflare/PatternSearchClient.php`
- Modify: `inc/Patterns/PatternIndex.php`
- Modify: `tests/phpunit/CloudflarePatternSearchClientTest.php`
- Modify: `tests/phpunit/PatternIndexTest.php`

- [ ] **Step 1: Add failing cleanup regression**

In `tests/phpunit/PatternIndexTest.php`, add a Cloudflare AI Search sync test that queues remote IDs containing:

```php
[
	\FlavorAgent\Cloudflare\PatternSearchInstanceManager::OWNER_ITEM_ID,
	PatternIndex::pattern_uuid( 'theme/retired-pattern' ),
]
```

Register one current pattern, run `PatternIndex::sync()`, and assert:

```php
$this->assertNull( $this->find_optional_remote_post_call( '/items/' . rawurlencode( \FlavorAgent\Cloudflare\PatternSearchInstanceManager::OWNER_ITEM_ID ), 'DELETE' ) );
$this->assertIsArray( $this->find_remote_post_call( '/items/' . rawurlencode( PatternIndex::pattern_uuid( 'theme/retired-pattern' ) ), 'DELETE' ) );
```

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

Expected: exit `1`; the owner marker is currently treated as stale and deleted.

- [ ] **Step 3: Skip owner marker in cleanup**

In `inc/Patterns/PatternIndex.php`, update the Cloudflare AI Search delete loop:

```php
foreach ( $remote_ids as $remote_id ) {
	$remote_id = sanitize_text_field( (string) $remote_id );

	if ( \FlavorAgent\Cloudflare\PatternSearchInstanceManager::OWNER_ITEM_ID === $remote_id ) {
		continue;
	}

	if ( '' !== $remote_id && ! isset( $current_ids[ $remote_id ] ) ) {
		$to_delete[] = $remote_id;
	}
}
```

- [ ] **Step 4: Keep owner marker out of retrieval**

In `inc/Cloudflare/PatternSearchClient.php`, make sure normalized search chunks drop the owner marker even if a remote response returns it:

```php
if ( \FlavorAgent\Cloudflare\PatternSearchInstanceManager::OWNER_ITEM_ID === $name ) {
	continue;
}
```

Place this immediately after `$name` is normalized from metadata in `normalize_chunks()`.

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
