<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Cloudflare\PatternSearchInstanceManager;
use FlavorAgent\Patterns\PatternIndex;
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
		$this->assertStringContainsString( '/ai-search/instances', WordPressTestState::$remote_get_calls[0]['url'] );
		$this->assertStringContainsString( '/ai-search/instances', WordPressTestState::$remote_post_calls[0]['url'] );
		$this->assertStringContainsString( '/items', WordPressTestState::$remote_post_calls[1]['url'] );
		$this->assertStringContainsString( '"pattern_name":"__flavor_agent_owner__"', WordPressTestState::$remote_post_calls[1]['args']['body'] );
		$this->assertStringContainsString( '"public_safe":"true"', WordPressTestState::$remote_post_calls[1]['args']['body'] );
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

	public function test_process_managed_instance_provisioning_validates_saved_request_and_marks_ready(): void {
		$signature                   = $this->provisioning_signature();
		WordPressTestState::$options = $this->provisioning_options( $signature );
		$this->seed_usable_pattern_index();

		$this->queue_successful_managed_instance_creation();

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertSame(
			PatternSearchInstanceManager::managed_instance_id(),
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID ] ?? ''
		);
		$this->assertSame(
			$signature,
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE ] ?? ''
		);
		$this->assertSame(
			'ready',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['status'] ?? ''
		);
		$this->assertSame(
			'created',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['managed_status'] ?? ''
		);
		$this->assertNotFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );

		$index_state = PatternIndex::get_state();

		$this->assertSame( 'stale', $index_state['status'] );
		$this->assertSame( 'cloudflare_ai_search_signature_changed', $index_state['stale_reason'] );
		$this->assertSame( 'cloudflare_ai_search_signature_changed', $index_state['stale_reasons'][0] ?? '' );

		$scheduled = wp_next_scheduled( PatternIndex::CRON_HOOK );

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertSame( $scheduled, wp_next_scheduled( PatternIndex::CRON_HOOK ) );
	}

	public function test_process_managed_instance_provisioning_repairs_empty_instance_missing_owner_marker_after_prior_marker_failure(): void {
		$signature                   = $this->provisioning_signature();
		WordPressTestState::$options = $this->provisioning_options(
			$signature,
			[
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE => [
					'status'          => 'error',
					'signature'       => $signature,
					'last_error_code' => 'cloudflare_pattern_ai_search_owner_marker_missing',
					'last_error'      => 'The existing Cloudflare AI Search managed pattern index is missing the Flavor Agent owner marker.',
				],
			]
		);
		$this->seed_usable_pattern_index();

		PatternSearchInstanceManager::schedule_managed_instance_provisioning( $signature );

		WordPressTestState::$remote_get_responses  = [
			$this->instance_list_response( [ $this->managed_instance() ] ),
			$this->owner_marker_response_empty(),
			$this->item_list_response( [] ),
			$this->owner_marker_response(),
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'result' => [ 'id' => 'cloudflare-owner-marker-123' ] ] ),
			],
		];

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertSame(
			$signature,
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE ] ?? ''
		);
		$this->assertSame(
			'ready',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['status'] ?? ''
		);
		$this->assertSame(
			'repaired_owner_marker',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['managed_status'] ?? ''
		);
		$this->assertStringContainsString( 'source=builtin', WordPressTestState::$remote_get_calls[2]['url'] ?? '' );
		$this->assertStringContainsString( 'per_page=50', WordPressTestState::$remote_get_calls[2]['url'] ?? '' );
		$this->assertStringContainsString( '"public_safe":"true"', WordPressTestState::$remote_post_calls[0]['args']['body'] ?? '' );
		$this->assertNotFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );
	}

	public function test_process_managed_instance_provisioning_does_not_repair_missing_owner_marker_when_instance_has_items(): void {
		$signature                   = $this->provisioning_signature();
		WordPressTestState::$options = $this->provisioning_options(
			$signature,
			[
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE => [
					'status'          => 'error',
					'signature'       => $signature,
					'last_error_code' => 'cloudflare_pattern_ai_search_owner_marker_missing',
					'last_error'      => 'The existing Cloudflare AI Search managed pattern index is missing the Flavor Agent owner marker.',
				],
			]
		);

		PatternSearchInstanceManager::schedule_managed_instance_provisioning( $signature );

		WordPressTestState::$remote_get_responses = [
			$this->instance_list_response( [ $this->managed_instance() ] ),
			$this->owner_marker_response_empty(),
			$this->item_list_response( [ [ 'id' => 'unknown-pattern' ] ] ),
		];

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
			WordPressTestState::$options
		);
		$this->assertSame(
			'error',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['status'] ?? ''
		);
		$this->assertSame(
			'cloudflare_pattern_ai_search_owner_marker_missing',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['last_error_code'] ?? ''
		);
		$this->assertStringContainsString(
			'already contains items',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['last_error'] ?? ''
		);
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
	}

	public function test_process_managed_instance_provisioning_records_remote_error_without_validating_signature(): void {
		$signature                   = $this->provisioning_signature();
		WordPressTestState::$options = $this->provisioning_options(
			$signature,
			[
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => 'old-signature',
			]
		);

		WordPressTestState::$remote_get_response = [
			'response' => [ 'code' => 403 ],
			'body'     => wp_json_encode(
				[
					'errors' => [
						[
							'message' => 'Authentication failed',
						],
					],
				]
			),
		];

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
			WordPressTestState::$options
		);
		$this->assertSame(
			'error',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['status'] ?? ''
		);
		$this->assertSame(
			'cloudflare_pattern_ai_search_instance_list_error',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['last_error_code'] ?? ''
		);
		$this->assertSame(
			'Cloudflare AI Search instance list failed (HTTP 403): Authentication failed.',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['last_error'] ?? ''
		);
		$this->assertFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );
	}

	public function test_process_managed_instance_provisioning_marks_stale_when_credentials_change_before_callback(): void {
		$stale_signature             = PatternSearchInstanceManager::credential_signature(
			'account-123',
			'token-old',
			'@cf/qwen/qwen3-embedding-0.6b'
		);
		WordPressTestState::$options = $this->provisioning_options(
			$stale_signature,
			[
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => 'old-signature',
			]
		);

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertSame(
			'stale',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['status'] ?? ''
		);
		$this->assertSame(
			'cloudflare_pattern_ai_search_signature_changed',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['last_error_code'] ?? ''
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
			WordPressTestState::$options
		);
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
		$this->assertFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );
	}

	public function test_process_managed_instance_provisioning_returns_when_backend_changes_before_callback(): void {
		$signature                   = $this->provisioning_signature();
		WordPressTestState::$options = $this->provisioning_options(
			$signature,
			[
				Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_QDRANT,
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => 'existing-signature',
			]
		);

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertSame(
			'provisioning',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['status'] ?? ''
		);
		$this->assertSame(
			$signature,
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['signature'] ?? ''
		);
		$this->assertSame(
			'existing-signature',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE ] ?? ''
		);
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
		$this->assertFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );
	}

	public function test_process_managed_instance_provisioning_schedules_once_after_failed_retry_succeeds(): void {
		$signature                   = $this->provisioning_signature();
		WordPressTestState::$options = $this->provisioning_options( $signature );

		WordPressTestState::$remote_get_response = [
			'response' => [ 'code' => 403 ],
			'body'     => wp_json_encode(
				[
					'errors' => [
						[
							'message' => 'Authentication failed',
						],
					],
				]
			),
		];

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertSame(
			'error',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['status'] ?? ''
		);
		$this->assertFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );

		WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ] = [
			'status'    => 'provisioning',
			'signature' => $signature,
		];
		WordPressTestState::$remote_get_response = [];
		$this->queue_successful_managed_instance_creation();

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertSame(
			$signature,
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE ] ?? ''
		);
		$this->assertSame( 1, $this->scheduled_event_count( PatternIndex::CRON_HOOK ) );
	}

	public function test_owner_marker_upload_is_asynchronous(): void {
		WordPressTestState::$remote_get_responses  = [
			$this->instance_list_response( [] ),
			$this->owner_marker_response(),
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ]
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
		$this->assertSame( 'created', $result['status'] );

		$upload_body = (string) ( WordPressTestState::$remote_post_calls[1]['args']['body'] ?? '' );

		$this->assertStringContainsString( 'name="wait_for_completion"', $upload_body );
		$this->assertStringContainsString( "wait_for_completion\"\r\n\r\nfalse", $upload_body );
		$this->assertStringNotContainsString( "wait_for_completion\"\r\n\r\ntrue", $upload_body );
	}

	public function test_owner_marker_is_confirmed_by_polling_until_indexed(): void {
		add_filter( 'flavor_agent_cloudflare_pattern_ai_search_marker_poll_interval', static fn() => 0 );

		WordPressTestState::$remote_get_responses  = [
			$this->instance_list_response( [] ),
			$this->owner_marker_response_empty(),
			$this->owner_marker_response(),
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ]
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
		$this->assertSame( 'created', $result['status'] );
		$this->assertCount( 3, WordPressTestState::$remote_get_calls );
	}

	public function test_owner_marker_polling_gives_up_after_configured_attempts(): void {
		add_filter( 'flavor_agent_cloudflare_pattern_ai_search_marker_poll_interval', static fn() => 0 );
		add_filter( 'flavor_agent_cloudflare_pattern_ai_search_marker_poll_attempts', static fn() => 2 );

		WordPressTestState::$remote_get_responses  = [
			$this->instance_list_response( [] ),
			$this->owner_marker_response_empty(),
			$this->owner_marker_response_empty(),
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ]
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

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_pattern_ai_search_owner_marker_missing', $result->get_error_code() );
		$this->assertCount( 3, WordPressTestState::$remote_get_calls );
	}

	public function test_async_owner_marker_upload_accepts_accepted_response(): void {
		WordPressTestState::$remote_get_responses  = [
			$this->instance_list_response( [] ),
			$this->owner_marker_response(),
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ]
				),
			],
			[
				'response' => [ 'code' => 202 ],
				'body'     => wp_json_encode( [ 'result' => [ 'id' => 'cloudflare-owner-marker-123' ] ] ),
			],
		];

		$result = PatternSearchInstanceManager::ensure_managed_instance(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'created', $result['status'] );
	}

	public function test_owner_marker_poll_waits_a_fixed_interval_between_attempts(): void {
		$slept = [];
		add_filter(
			'flavor_agent_cloudflare_pattern_ai_search_marker_poll_sleep',
			static function ( $seconds ) use ( &$slept ) {
				$slept[] = $seconds;

				return 0;
			}
		);
		add_filter( 'flavor_agent_cloudflare_pattern_ai_search_marker_poll_attempts', static fn() => 3 );
		add_filter( 'flavor_agent_cloudflare_pattern_ai_search_marker_poll_interval', static fn() => 2 );

		WordPressTestState::$remote_get_responses  = [ $this->instance_list_response( [] ) ];
		WordPressTestState::$remote_get_response   = $this->owner_marker_response_empty();
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ]
				),
			],
			[
				'response' => [ 'code' => 202 ],
				'body'     => wp_json_encode( [ 'result' => [ 'id' => 'cloudflare-owner-marker-123' ] ] ),
			],
		];

		$result = PatternSearchInstanceManager::ensure_managed_instance(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 2, 2 ], $slept, 'The poll interval is fixed, not an escalating backoff.' );
	}

	public function test_owner_marker_poll_retries_transient_read_error_then_succeeds(): void {
		add_filter( 'flavor_agent_cloudflare_pattern_ai_search_marker_poll_interval', static fn() => 0 );

		WordPressTestState::$remote_get_responses  = [
			$this->instance_list_response( [] ),
			[
				'response' => [ 'code' => 503 ],
				'body'     => wp_json_encode( [ 'errors' => [ [ 'message' => 'temporarily unavailable' ] ] ] ),
			],
			$this->owner_marker_response(),
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ]
				),
			],
			[
				'response' => [ 'code' => 202 ],
				'body'     => wp_json_encode( [ 'result' => [ 'id' => 'cloudflare-owner-marker-123' ] ] ),
			],
		];

		$result = PatternSearchInstanceManager::ensure_managed_instance(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'created', $result['status'] );
		$this->assertCount( 3, WordPressTestState::$remote_get_calls );
	}

	public function test_provisioning_reschedules_when_owner_marker_not_yet_indexed(): void {
		add_filter( 'flavor_agent_cloudflare_pattern_ai_search_marker_poll_interval', static fn() => 0 );

		$signature                   = $this->provisioning_signature();
		WordPressTestState::$options = $this->provisioning_options( $signature );

		WordPressTestState::$remote_get_responses  = [ $this->instance_list_response( [] ) ];
		WordPressTestState::$remote_get_response   = $this->owner_marker_response_empty();
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ]
				),
			],
			[
				'response' => [ 'code' => 202 ],
				'body'     => wp_json_encode( [ 'result' => [ 'id' => 'cloudflare-owner-marker-123' ] ] ),
			],
		];

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$state = WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ] ?? [];

		$this->assertSame( 'provisioning', $state['status'] ?? '' );
		$this->assertSame( 'awaiting_owner_marker', $state['managed_status'] ?? '' );
		$this->assertSame( '1', $state['marker_attempts'] ?? '' );
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
			WordPressTestState::$options
		);
		$this->assertSame( 1, $this->scheduled_event_count( PatternSearchInstanceManager::PROVISION_CRON_HOOK ) );
	}

	public function test_provisioning_reschedules_on_transient_remote_error(): void {
		$signature                   = $this->provisioning_signature();
		WordPressTestState::$options = $this->provisioning_options( $signature );

		WordPressTestState::$remote_get_response = [
			'response' => [ 'code' => 503 ],
			'body'     => wp_json_encode( [ 'errors' => [ [ 'message' => 'Service temporarily unavailable' ] ] ] ),
		];

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$state = WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ] ?? [];

		$this->assertSame( 'provisioning', $state['status'] ?? '' );
		$this->assertSame( '1', $state['marker_attempts'] ?? '' );
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
			WordPressTestState::$options
		);
		$this->assertSame( 1, $this->scheduled_event_count( PatternSearchInstanceManager::PROVISION_CRON_HOOK ) );
	}

	public function test_provisioning_gives_up_and_errors_after_reschedule_cap(): void {
		add_filter( 'flavor_agent_cloudflare_pattern_ai_search_marker_poll_interval', static fn() => 0 );

		$signature                   = $this->provisioning_signature();
		WordPressTestState::$options = $this->provisioning_options(
			$signature,
			[
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE => [
					'status'          => 'provisioning',
					'signature'       => $signature,
					'marker_attempts' => (string) PatternSearchInstanceManager::MARKER_PROVISION_MAX_ATTEMPTS,
				],
			]
		);

		WordPressTestState::$remote_get_responses  = [ $this->instance_list_response( [] ) ];
		WordPressTestState::$remote_get_response   = $this->owner_marker_response_empty();
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ]
				),
			],
			[
				'response' => [ 'code' => 202 ],
				'body'     => wp_json_encode( [ 'result' => [ 'id' => 'cloudflare-owner-marker-123' ] ] ),
			],
		];

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$state = WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ] ?? [];

		$this->assertSame( 'error', $state['status'] ?? '' );
		$this->assertSame( 'cloudflare_pattern_ai_search_owner_marker_missing', $state['last_error_code'] ?? '' );
	}

	public function test_provisioning_completes_on_a_later_run_once_marker_is_indexed(): void {
		add_filter( 'flavor_agent_cloudflare_pattern_ai_search_marker_poll_interval', static fn() => 0 );

		$signature                   = $this->provisioning_signature();
		WordPressTestState::$options = $this->provisioning_options( $signature );
		$this->seed_usable_pattern_index();

		WordPressTestState::$remote_get_responses  = [ $this->instance_list_response( [] ) ];
		WordPressTestState::$remote_get_response   = $this->owner_marker_response_empty();
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[ 'result' => PatternSearchInstanceManager::build_create_payload( '@cf/qwen/qwen3-embedding-0.6b' ) ]
				),
			],
			[
				'response' => [ 'code' => 202 ],
				'body'     => wp_json_encode( [ 'result' => [ 'id' => 'cloudflare-owner-marker-123' ] ] ),
			],
		];

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertSame(
			'provisioning',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['status'] ?? ''
		);
		$this->assertSame( 1, $this->scheduled_event_count( PatternSearchInstanceManager::PROVISION_CRON_HOOK ) );

		// Simulate WordPress firing (and removing) the rescheduled single event.
		unset( WordPressTestState::$scheduled_events[ PatternSearchInstanceManager::PROVISION_CRON_HOOK ] );

		WordPressTestState::$remote_get_response   = [];
		WordPressTestState::$remote_get_responses  = [
			$this->instance_list_response( [ $this->managed_instance() ] ),
			$this->owner_marker_response(),
		];
		WordPressTestState::$remote_post_responses = [];

		PatternSearchInstanceManager::process_managed_instance_provisioning();

		$this->assertSame(
			'ready',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['status'] ?? ''
		);
		$this->assertSame(
			'adopted',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['managed_status'] ?? ''
		);
		$this->assertSame(
			$signature,
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE ] ?? ''
		);
	}

	private function provisioning_signature(): string {
		return PatternSearchInstanceManager::credential_signature(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b'
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function provisioning_options( string $signature, array $overrides = [] ): array {
		return array_merge(
			[
				Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
				'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
				'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
				'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE => [
					'status'    => 'provisioning',
					'signature' => $signature,
				],
			],
			$overrides
		);
	}

	private function seed_usable_pattern_index(): void {
		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'         => 'ready',
					'last_synced_at' => gmdate( 'c' ),
				]
			)
		);
	}

	private function queue_successful_managed_instance_creation(): void {
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
	}

	private function scheduled_event_count( string $hook ): int {
		return isset( WordPressTestState::$scheduled_events[ $hook ] ) ? 1 : 0;
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

	private function item_list_response( array $items, int $page = 1, ?int $total_count = null, int $per_page = 100 ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result'      => $items,
					'result_info' => [
						'count'       => count( $items ),
						'page'        => $page,
						'total_count' => $total_count ?? count( $items ),
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

	private function owner_marker_response_empty(): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'result' => [] ] ),
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
