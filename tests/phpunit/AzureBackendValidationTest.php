<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\AzureOpenAI\BaseHttpClient;
use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AzureBackendValidationTest extends TestCase {

	private string|false $previous_openai_api_key;

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->previous_openai_api_key = getenv( 'OPENAI_API_KEY' );
		putenv( 'OPENAI_API_KEY' );
	}

	protected function tearDown(): void {
		if ( false === $this->previous_openai_api_key ) {
			putenv( 'OPENAI_API_KEY' );
		} else {
			putenv( 'OPENAI_API_KEY=' . $this->previous_openai_api_key );
		}

		parent::tearDown();
	}

	public function test_embedding_validation_returns_remote_error_message(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 404,
			],
			'body'     => wp_json_encode(
				[
					'error' => [
						'message' => 'Embedding deployment not found',
					],
				]
			),
		];

		$result = EmbeddingClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'missing-deployment'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'Embedding deployment not found', $result->get_error_message() );
		$this->assertSame(
			'https://example.openai.azure.com/openai/v1/embeddings',
			WordPressTestState::$last_remote_post['url']
		);
	}

	public function test_parse_retry_after_header_supports_http_dates(): void {
		$now    = time();
		$header = gmdate( 'D, d M Y H:i:s \G\M\T', $now + 7 );
		$parsed = BaseHttpClient::parse_retry_after_header( $header, $now );

		$this->assertSame( 7, $parsed );
	}

	public function test_rank_delegates_to_the_wordpress_ai_client_for_selected_connector_providers(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider' => 'anthropic',
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

		WordPressTestState::$ai_client_generate_text_result = 'connector ranked output';

		$result = ResponsesClient::rank( 'connector system prompt', 'connector user prompt', 'high' );

		$this->assertSame( 'connector ranked output', $result );
		$this->assertSame( 'anthropic', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
		$this->assertSame( 'connector system prompt', WordPressTestState::$last_ai_client_prompt['system'] ?? null );
		$this->assertSame( 'high', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_uses_saved_reasoning_effort_for_selected_connector_providers(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'        => 'anthropic',
			'flavor_agent_azure_reasoning_effort' => 'xhigh',
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

		WordPressTestState::$ai_client_generate_text_result = 'connector ranked output';

		$result = ResponsesClient::rank( 'connector system prompt', 'connector user prompt' );

		$this->assertSame( 'connector ranked output', $result );
		$this->assertSame( 'anthropic', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
		$this->assertSame( 'xhigh', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_maps_openai_native_to_the_openai_connector_without_generic_fallback(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider' => Provider::NATIVE,
		];

		WordPressTestState::$connectors = [
			'openai'    => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
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
			'openai'    => true,
			'anthropic' => true,
		];

		WordPressTestState::$ai_client_generate_text_result = 'OpenAI connector ranked output';

		$result = ResponsesClient::rank( 'connector system prompt', 'connector user prompt', 'high' );

		$this->assertSame( 'OpenAI connector ranked output', $result );
		$this->assertSame( 'openai', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
		$this->assertSame( 'connector system prompt', WordPressTestState::$last_ai_client_prompt['system'] ?? null );
		$this->assertSame( 'high', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_does_not_use_generic_wordpress_ai_client_for_direct_provider(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'       => Provider::AZURE,
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'chat-deployment',
		];

		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = 'WordPress AI client preferred output';

		$result = ResponsesClient::rank( 'fallback system prompt', 'fallback user prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_text_generation_provider', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_embed_batch_returns_a_retryable_rate_limit_error_without_sleeping(): void {
		WordPressTestState::$options               = [
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
		];
		WordPressTestState::$remote_post_responses = [
			[
				'response' => [
					'code' => 429,
				],
				'headers'  => [
					'retry-after' => '0',
				],
				'body'     => wp_json_encode(
					[
						'error' => [
							'message' => 'Rate limited',
						],
					]
				),
			],
		];

		$result = EmbeddingClient::embed_batch( [ 'alpha', 'beta' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertSame(
			'https://example.openai.azure.com/openai/v1/embeddings',
			WordPressTestState::$remote_post_calls[0]['url'] ?? null
		);
		$this->assertSame(
			429,
			$result->get_error_data()['status'] ?? null
		);
		$this->assertTrue( (bool) ( $result->get_error_data()['retryable'] ?? false ) );
		$this->assertSame(
			1,
			$result->get_error_data()['retry_after'] ?? null
		);
	}

	public function test_qdrant_search_returns_a_retryable_rate_limit_error(): void {
		WordPressTestState::$options              = [
			'flavor_agent_qdrant_url' => 'https://example.qdrant.io',
			'flavor_agent_qdrant_key' => 'qdrant-key',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 429,
			],
			'headers'  => [
				'retry-after' => 'Wed, 21 Oct 2015 07:28:00 GMT',
			],
			'body'     => wp_json_encode(
				[
					'status' => [
						'error' => 'Too many requests',
					],
				]
			),
		];

		$result = QdrantClient::search( [ 0.1, 0.2 ], 3, [], 'flavor-agent-test' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qdrant_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] ?? null );
		$this->assertTrue( (bool) ( $result->get_error_data()['retryable'] ?? false ) );
	}

	public function test_embed_batch_rejects_mixed_dimensions(): void {
		WordPressTestState::$options              = [
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'data' => [
						[
							'embedding' => [ 0.1, 0.2 ],
						],
						[
							'embedding' => [ 0.3, 0.4, 0.5 ],
						],
					],
				]
			),
		];

		$result = EmbeddingClient::embed_batch( [ 'alpha', 'beta' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'embedding_dimension_mismatch', $result->get_error_code() );
	}

	public function test_openai_native_embedding_validation_uses_openai_endpoint_and_bearer_auth(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'data' => [
						[
							'embedding' => [ 0.1, 0.2 ],
						],
					],
				]
			),
		];

		$result = EmbeddingClient::validate_configuration(
			null,
			'native-key',
			'text-embedding-3-large',
			Provider::NATIVE
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['dimension'] ?? null );
		$this->assertNotSame( '', $result['signature']['signature_hash'] ?? '' );
		$this->assertSame( 'https://api.openai.com/v1/embeddings', WordPressTestState::$last_remote_post['url'] );
		$this->assertSame(
			'Bearer native-key',
			WordPressTestState::$last_remote_post['args']['headers']['Authorization'] ?? null
		);
	}

	public function test_rank_does_not_fall_back_to_wordpress_ai_client_when_no_chat_connector_is_selected(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = 'Unexpected generic output';

		$result = ResponsesClient::rank( 'fallback system prompt', 'fallback user prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_text_generation_provider', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_uses_saved_reasoning_effort_for_matching_openai_connector(): void {
		WordPressTestState::$options                        = [
			'flavor_agent_openai_provider'        => Provider::NATIVE,
			'flavor_agent_azure_reasoning_effort' => 'high',
		];
		WordPressTestState::$connectors                     = [
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
		WordPressTestState::$ai_client_provider_support     = [
			'openai' => true,
		];
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = 'OpenAI connector output';

		$result = ResponsesClient::rank( 'fallback system prompt', 'fallback user prompt' );

		$this->assertSame( 'OpenAI connector output', $result );
		$this->assertSame( 'openai', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
		$this->assertSame( 'high', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_embedding_validation_wraps_transport_timeout_with_backend_context(): void {
		WordPressTestState::$remote_post_response = new \WP_Error(
			'http_request_failed',
			'cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received'
		);

		$result = EmbeddingClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'embed-deployment'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertStringContainsString(
			'Azure OpenAI embeddings request timed out after 30 seconds while contacting example.openai.azure.com.',
			$result->get_error_message()
		);
	}

	public function test_embedding_validation_rejects_responses_payload_shape(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => 'ok',
				]
			),
		];

		$result = EmbeddingClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'embed-deployment'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'Unexpected Azure OpenAI embeddings validation response format.',
			$result->get_error_message()
		);
	}

	public function test_qdrant_validation_reports_parse_failures(): void {
		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => 'not-json',
		];

		$result = QdrantClient::validate_configuration(
			'https://example.cloud.qdrant.io:6333',
			'qdrant-key'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'Failed to parse Qdrant validation response.', $result->get_error_message() );
		$this->assertSame(
			'https://example.cloud.qdrant.io:6333/collections',
			WordPressTestState::$last_remote_get['url']
		);
	}

	public function test_qdrant_validation_accepts_expected_collections_payload(): void {
		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'status' => 'ok',
					'result' => [
						'collections' => [],
					],
				]
			),
		];

		$result = QdrantClient::validate_configuration(
			'https://example.cloud.qdrant.io:6333',
			'qdrant-key'
		);

		$this->assertTrue( $result );
	}

	public function test_qdrant_validation_rejects_missing_status_flag(): void {
		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'collections' => [],
					],
				]
			),
		];

		$result = QdrantClient::validate_configuration(
			'https://example.cloud.qdrant.io:6333',
			'qdrant-key'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'Qdrant validation response did not contain the expected collections list.',
			$result->get_error_message()
		);
	}

	public function test_qdrant_probe_health_hits_requested_probe(): void {
		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => 'ready',
		];

		$result = QdrantClient::probe_health(
			'readyz',
			'https://example.cloud.qdrant.io:6333',
			'qdrant-key'
		);

		$this->assertSame(
			[
				'probe'   => 'readyz',
				'status'  => 200,
				'message' => 'ready',
			],
			$result
		);
		$this->assertSame(
			'https://example.cloud.qdrant.io:6333/readyz',
			WordPressTestState::$last_remote_get['url']
		);
		$this->assertSame(
			'qdrant-key',
			WordPressTestState::$last_remote_get['args']['headers']['api-key'] ?? null
		);
	}

	public function test_qdrant_probe_health_rejects_unknown_probe(): void {
		$result = QdrantClient::probe_health(
			'broken',
			'https://example.cloud.qdrant.io:6333',
			'qdrant-key'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qdrant_health_probe_invalid', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
	}

	public function test_qdrant_get_telemetry_returns_result_payload(): void {
		WordPressTestState::$options             = [
			'flavor_agent_qdrant_url' => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'qdrant-key',
		];
		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'status' => 'ok',
					'result' => [
						'collections' => [
							'total' => 3,
						],
					],
				]
			),
		];

		$result = QdrantClient::get_telemetry();

		$this->assertSame(
			[
				'collections' => [
					'total' => 3,
				],
			],
			$result
		);
		$this->assertSame(
			'https://example.cloud.qdrant.io:6333/telemetry',
			WordPressTestState::$last_remote_get['url']
		);
	}

	public function test_qdrant_get_collection_optimizations_supports_detail_flags(): void {
		WordPressTestState::$options             = [
			'flavor_agent_qdrant_url' => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'qdrant-key',
		];
		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'status' => 'ok',
					'result' => [
						'queued' => 2,
					],
				]
			),
		];

		$result = QdrantClient::get_collection_optimizations(
			'flavor-agent-patterns-test',
			[ 'queued', 'completed' ]
		);

		$this->assertSame(
			[
				'queued' => 2,
			],
			$result
		);
		$this->assertSame(
			'https://example.cloud.qdrant.io:6333/collections/flavor-agent-patterns-test/optimizations?with=queued%2Ccompleted',
			WordPressTestState::$last_remote_get['url']
		);
	}

	public function test_qdrant_get_collection_optimizations_rejects_unknown_detail_flags(): void {
		WordPressTestState::$options = [
			'flavor_agent_qdrant_url' => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'qdrant-key',
		];

		$result = QdrantClient::get_collection_optimizations(
			'flavor-agent-patterns-test',
			[ 'queued', 'bogus' ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qdrant_optimizations_invalid_detail_flag', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
	}

	public function test_qdrant_ensure_collection_rejects_vector_size_mismatch(): void {
		WordPressTestState::$options             = [
			'flavor_agent_qdrant_url' => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'qdrant-key',
		];
		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'status' => 'ok',
					'result' => [
						'config' => [
							'params' => [
								'vectors' => [
									'size'     => 3,
									'distance' => 'Cosine',
								],
							],
						],
					],
				]
			),
		];

		$result = QdrantClient::ensure_collection( 'flavor-agent-patterns-test', 2 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qdrant_collection_size_mismatch', $result->get_error_code() );
	}

	public function test_qdrant_ensure_collection_indexes_traits_payloads(): void {
		WordPressTestState::$options               = [
			'flavor_agent_qdrant_url' => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'qdrant-key',
		];
		WordPressTestState::$remote_get_response   = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'status' => 'ok',
					'result' => [
						'config' => [
							'params' => [
								'vectors' => [
									'size'     => 2,
									'distance' => 'Cosine',
								],
							],
						],
					],
				]
			),
		];
		WordPressTestState::$remote_post_responses = array_fill(
			0,
			4,
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'status' => 'ok',
						'result' => [],
					]
				),
			]
		);

		$result = QdrantClient::ensure_collection( 'flavor-agent-patterns-test', 2 );

		$this->assertTrue( $result );
		$this->assertSame(
			[ 'blockTypes', 'templateTypes', 'categories', 'traits' ],
			array_map(
				static function ( array $call ): ?string {
					$body = json_decode( (string) ( $call['args']['body'] ?? '' ), true );

					return is_array( $body ) ? ( $body['field_name'] ?? null ) : null;
				},
				WordPressTestState::$remote_post_calls
			)
		);
	}
}
