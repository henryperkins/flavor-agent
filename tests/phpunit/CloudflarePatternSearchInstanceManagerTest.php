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
		$this->assertSame(
			[
				'keyword' => true,
				'vector'  => true,
			],
			$payload['index_method']
		);
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
				[
					'field_name' => 'pattern_name',
					'data_type'  => 'text',
				],
				[
					'field_name' => 'candidate_type',
					'data_type'  => 'text',
				],
				[
					'field_name' => 'source',
					'data_type'  => 'text',
				],
				[
					'field_name' => 'synced_id',
					'data_type'  => 'text',
				],
				[
					'field_name' => 'public_safe',
					'data_type'  => 'boolean',
				],
			],
			$payload['custom_metadata']
		);
	}

	public function test_create_payload_falls_back_when_saved_model_is_not_supported_by_ai_search(): void {
		$this->assertSame(
			'@cf/qwen/qwen3-embedding-0.6b',
			PatternSearchInstanceManager::build_create_payload( '@cf/future/workers-ai-only-model' )['embedding_model']
		);
		$this->assertSame(
			'@cf/baai/bge-m3',
			PatternSearchInstanceManager::build_create_payload( '@cf/baai/bge-m3' )['embedding_model']
		);
	}

	public function test_credential_signature_changes_with_current_workers_ai_credentials(): void {
		$signature = PatternSearchInstanceManager::credential_signature(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

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
		WordPressTestState::$remote_get_responses  = [
			$this->instance_list_response( [] ),
			$this->owner_marker_response(),
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ),
					]
				),
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

		$owner_marker_url = WordPressTestState::$remote_get_calls[1]['url'];
		$query            = [];

		parse_str( (string) parse_url( $owner_marker_url, PHP_URL_QUERY ), $query );

		$this->assertSame( '5', (string) ( $query['per_page'] ?? '' ) );
		$this->assertArrayHasKey( 'metadata_filter', $query );

		$filter = json_decode( (string) $query['metadata_filter'], true );

		$this->assertIsArray( $filter );
		$this->assertSame(
			[ '$eq' => PatternSearchInstanceManager::OWNER_MARKER_NAME ],
			$filter['pattern_name'] ?? null
		);
		$this->assertSame( [ '$eq' => 'flavor_agent_owner' ], $filter['candidate_type'] ?? null );
		$this->assertSame( [ '$eq' => 'flavor_agent' ], $filter['source'] ?? null );
		$this->assertSame(
			[ '$eq' => PatternSearchInstanceManager::site_hash() ],
			$filter['synced_id'] ?? null
		);
	}

	public function test_ensure_managed_instance_rejects_matching_id_without_schema(): void {
		WordPressTestState::$remote_get_responses = [
			$this->instance_list_response(
				[
					[
						'id'              => PatternSearchInstanceManager::managed_instance_id(),
						'custom_metadata' => [
							[
								'field_name' => 'category',
								'data_type'  => 'text',
							],
						],
					],
				]
			),
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
			$this->instance_list_response( [ $this->managed_instance() ] ),
			$this->owner_marker_response(),
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

	public function test_ensure_managed_instance_rejects_matching_id_with_different_embedding_model(): void {
		WordPressTestState::$remote_get_responses = [
			$this->instance_list_response(
				[
					$this->managed_instance( '@cf/baai/bge-m3' ),
				]
			),
			$this->owner_marker_response(),
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

	public function test_ensure_managed_instance_rejects_matching_id_with_mismatched_owner_marker(): void {
		WordPressTestState::$remote_get_responses = [
			$this->instance_list_response( [ $this->managed_instance() ] ),
			$this->owner_marker_response( 'different-site' ),
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

	public function test_create_conflict_adopts_only_after_schema_and_owner_marker_validation(): void {
		WordPressTestState::$remote_get_responses  = [
			$this->instance_list_response( [] ),
			$this->instance_list_response( [ $this->managed_instance() ] ),
			$this->owner_marker_response(),
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 409 ],
				'body'     => wp_json_encode( [ 'errors' => [ [ 'message' => 'already exists' ] ] ] ),
			],
		];

		$result = PatternSearchInstanceManager::ensure_managed_instance(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'adopted_after_conflict', $result['status'] );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertCount( 3, WordPressTestState::$remote_get_calls );
	}

	public function test_create_conflict_rejects_mismatched_owner_marker(): void {
		WordPressTestState::$remote_get_responses  = [
			$this->instance_list_response( [] ),
			$this->instance_list_response( [ $this->managed_instance() ] ),
			$this->owner_marker_response( 'different-site' ),
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 409 ],
				'body'     => wp_json_encode( [ 'errors' => [ [ 'message' => 'already exists' ] ] ] ),
			],
		];

		$result = PatternSearchInstanceManager::ensure_managed_instance(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_pattern_ai_search_owner_marker_mismatch', $result->get_error_code() );
	}

	public function test_instance_discovery_paginates_until_exact_managed_id_is_found(): void {
		WordPressTestState::$remote_get_responses = [
			$this->instance_list_response(
				[
					[
						'id'              => PatternSearchInstanceManager::managed_instance_id() . '-not-it',
						'custom_metadata' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' )['custom_metadata'],
					],
				],
				1,
				200,
				100
			),
			$this->instance_list_response(
				[ $this->managed_instance() ],
				2,
				200,
				100
			),
			$this->owner_marker_response(),
		];

		$result = PatternSearchInstanceManager::ensure_managed_instance(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'adopted', $result['status'] );
		$this->assertStringContainsString( 'search=', WordPressTestState::$remote_get_calls[0]['url'] );
		$this->assertStringContainsString( 'page=1', WordPressTestState::$remote_get_calls[0]['url'] );
		$this->assertStringContainsString( 'per_page=100', WordPressTestState::$remote_get_calls[0]['url'] );
		$this->assertStringContainsString( 'page=2', WordPressTestState::$remote_get_calls[1]['url'] );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
	}

	/**
	 * @param array<int, array<string, mixed>> $instances
	 */
	private function instance_list_response( array $instances, int $page = 1, int $total_count = 0, int $per_page = 100 ): array {
		if ( 0 === $total_count ) {
			$total_count = count( $instances );
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result'      => $instances,
					'result_info' => [
						'count'       => count( $instances ),
						'page'        => $page,
						'total_count' => $total_count,
						'per_page'    => $per_page,
					],
				]
			),
		];
	}

	private function managed_instance( string $embedding_model = '@cf/qwen/qwen3-embedding-0.6b' ): array {
		return [
			'id'              => PatternSearchInstanceManager::managed_instance_id(),
			'custom_metadata' => PatternSearchInstanceManager::build_create_payload( $embedding_model )['custom_metadata'],
			'embedding_model' => $embedding_model,
		];
	}

	private function owner_marker_response( ?string $site_hash = null ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result' => [
						[
							'id'       => 'cloudflare-owner-marker-123',
							'metadata' => [
								'pattern_name'   => PatternSearchInstanceManager::OWNER_MARKER_NAME,
								'candidate_type' => 'flavor_agent_owner',
								'source'         => 'flavor_agent',
								'synced_id'      => $site_hash ?? PatternSearchInstanceManager::site_hash(),
								'public_safe'    => true,
							],
						],
					],
				]
			),
		];
	}
}
