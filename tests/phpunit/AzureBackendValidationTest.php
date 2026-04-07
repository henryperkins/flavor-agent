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

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
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

	public function test_responses_validation_uses_responses_endpoint(): void {
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

		$result = ResponsesClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'chat-deployment'
		);

		$this->assertTrue( $result );
		$this->assertSame(
			'https://example.openai.azure.com/openai/v1/responses',
			WordPressTestState::$last_remote_post['url']
		);
		$request_body = json_decode( (string) WordPressTestState::$last_remote_post['args']['body'], true );
		$this->assertIsArray( $request_body );
		$this->assertSame( 16, $request_body['max_output_tokens'] ?? null );
		$this->assertSame( 'medium', $request_body['reasoning']['effort'] ?? null );
	}

	public function test_responses_validation_accepts_incomplete_response_object_without_text(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'object'             => 'response',
					'status'             => 'incomplete',
					'incomplete_details' => [
						'reason' => 'max_output_tokens',
					],
					'output'             => [],
				]
			),
		];

		$result = ResponsesClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'chat-deployment'
		);

		$this->assertTrue( $result );
	}

	public function test_rank_sends_medium_reasoning_effort(): void {
		WordPressTestState::$options              = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'chat-deployment',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => 'ranked output',
				]
			),
		];

		$result = ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertSame( 'ranked output', $result );
		$this->assertSame(
			'https://example.openai.azure.com/openai/v1/responses',
			WordPressTestState::$last_remote_post['url']
		);
		$request_body = json_decode( (string) WordPressTestState::$last_remote_post['args']['body'], true );
		$this->assertIsArray( $request_body );
		$this->assertSame( 'chat-deployment', $request_body['model'] ?? null );
		$this->assertSame( 'system prompt', $request_body['instructions'] ?? null );
		$this->assertSame( 'user prompt', $request_body['input'] ?? null );
		$this->assertSame( 'medium', $request_body['reasoning']['effort'] ?? null );
		$this->assertSame( 180, WordPressTestState::$last_remote_post['args']['timeout'] ?? null );
	}

	public function test_rank_uses_configured_azure_reasoning_effort(): void {
		WordPressTestState::$options              = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'chat-deployment',
			'flavor_agent_azure_reasoning_effort' => 'xhigh',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => 'ranked output',
				]
			),
		];

		$result = ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertSame( 'ranked output', $result );
		$request_body = json_decode( (string) WordPressTestState::$last_remote_post['args']['body'], true );
		$this->assertIsArray( $request_body );
		$this->assertSame( 'xhigh', $request_body['reasoning']['effort'] ?? null );
	}

	public function test_rank_records_usage_metrics_for_direct_responses_calls(): void {
		WordPressTestState::$options              = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'chat-deployment',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => 'ranked output',
					'usage'       => [
						'total_tokens'  => 90,
						'input_tokens'  => 25,
						'output_tokens' => 65,
					],
				]
			),
		];

		$result = ResponsesClient::rank( 'system prompt', 'user prompt' );
		$meta = Provider::active_chat_request_meta();

		$this->assertSame( 'ranked output', $result );
		$this->assertSame( 90, $meta['tokenUsage']['total'] ?? null );
		$this->assertSame( 25, $meta['tokenUsage']['input'] ?? null );
		$this->assertSame( 65, $meta['tokenUsage']['output'] ?? null );
		$this->assertIsInt( $meta['latencyMs'] ?? null );
	}

	public function test_rank_returns_a_retryable_rate_limit_error_without_sleeping(): void {
		WordPressTestState::$options               = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'chat-deployment',
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

		$result = ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertSame(
			'https://example.openai.azure.com/openai/v1/responses',
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

	public function test_parse_retry_after_header_supports_http_dates(): void {
		$now     = time();
		$header  = gmdate( 'D, d M Y H:i:s \G\M\T', $now + 7 );
		$parsed = BaseHttpClient::parse_retry_after_header( $header, $now );

		$this->assertSame( 7, $parsed );
	}

	public function test_rank_extracts_text_from_later_output_item(): void {
		WordPressTestState::$options              = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'chat-deployment',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'object' => 'response',
					'status' => 'completed',
					'output' => [
						[
							'type'    => 'reasoning',
							'content' => [],
						],
						[
							'type'    => 'message',
							'content' => [
								[
									'type' => 'output_text',
									'text' => 'ranked output',
								],
							],
						],
					],
				]
			),
		];

		$result = ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertSame( 'ranked output', $result );
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
			'flavor_agent_openai_provider'         => 'anthropic',
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
		WordPressTestState::$options = [
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
		WordPressTestState::$options = [
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

	public function test_openai_native_rank_uses_bearer_auth_and_responses_endpoint(): void {
		WordPressTestState::$options              = [
			'flavor_agent_openai_provider'          => 'openai_native',
			'flavor_agent_openai_native_api_key'    => 'native-key',
			'flavor_agent_openai_native_chat_model' => 'gpt-5.4',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => 'ranked output',
				]
			),
		];

		$result = ResponsesClient::rank( 'native system prompt', 'native user prompt' );

		$this->assertSame( 'ranked output', $result );
		$this->assertSame( 'https://api.openai.com/v1/responses', WordPressTestState::$last_remote_post['url'] );
		$this->assertSame(
			'Bearer native-key',
			WordPressTestState::$last_remote_post['args']['headers']['Authorization'] ?? null
		);
		$request_body = json_decode( (string) WordPressTestState::$last_remote_post['args']['body'], true );
		$this->assertIsArray( $request_body );
		$this->assertSame( 'gpt-5.4', $request_body['model'] ?? null );
		$this->assertSame( 'medium', $request_body['reasoning']['effort'] ?? null );
	}

	public function test_openai_native_rank_falls_back_to_connector_key(): void {
		WordPressTestState::$options              = [
			'flavor_agent_openai_provider'          => 'openai_native',
			'connectors_ai_openai_api_key'          => 'connector-key',
			'flavor_agent_openai_native_chat_model' => 'gpt-5.4',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => 'connector ranked output',
				]
			),
		];

		$result = ResponsesClient::rank( 'connector system prompt', 'connector user prompt' );

		$this->assertSame( 'connector ranked output', $result );
		$this->assertSame( 'https://api.openai.com/v1/responses', WordPressTestState::$last_remote_post['url'] );
		$this->assertSame(
			'Bearer connector-key',
			WordPressTestState::$last_remote_post['args']['headers']['Authorization'] ?? null
		);
	}

	public function test_rank_falls_back_to_another_configured_direct_provider_when_selected_provider_is_unconfigured(): void {
		WordPressTestState::$options              = [
			'flavor_agent_openai_provider'          => Provider::AZURE,
			'flavor_agent_openai_native_api_key'    => 'native-key',
			'flavor_agent_openai_native_chat_model' => 'gpt-5.4',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => 'direct fallback output',
				]
			),
		];

		$result = ResponsesClient::rank( 'fallback system prompt', 'fallback user prompt' );

		$this->assertSame( 'direct fallback output', $result );
		$this->assertSame( 'https://api.openai.com/v1/responses', WordPressTestState::$last_remote_post['url'] );
		$this->assertSame(
			'Bearer native-key',
			WordPressTestState::$last_remote_post['args']['headers']['Authorization'] ?? null
		);
	}

	public function test_rank_falls_back_to_wordpress_ai_client_when_no_direct_provider_is_configured(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = 'WordPress AI client fallback output';

		$result = ResponsesClient::rank( 'fallback system prompt', 'fallback user prompt' );

		$this->assertSame( 'WordPress AI client fallback output', $result );
		$this->assertSame( 'core_function', WordPressTestState::$last_ai_client_prompt['transport'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_uses_saved_reasoning_effort_for_wordpress_ai_client_fallback(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_reasoning_effort' => 'high',
		];
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = 'WordPress AI client fallback output';

		$result = ResponsesClient::rank( 'fallback system prompt', 'fallback user prompt' );

		$this->assertSame( 'WordPress AI client fallback output', $result );
		$this->assertSame( 'core_function', WordPressTestState::$last_ai_client_prompt['transport'] ?? null );
		$this->assertSame( 'high', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_openai_native_rank_falls_back_to_openai_env_var(): void {
		putenv( 'OPENAI_API_KEY=env-native-key' );

		try {
			WordPressTestState::$options              = [
				'flavor_agent_openai_provider'          => 'openai_native',
				'flavor_agent_openai_native_chat_model' => 'gpt-5.4',
			];
			WordPressTestState::$remote_post_response = [
				'response' => [
					'code' => 200,
				],
				'body'     => wp_json_encode(
					[
						'output_text' => 'env ranked output',
					]
				),
			];

			$result = ResponsesClient::rank( 'env system prompt', 'env user prompt' );

			$this->assertSame( 'env ranked output', $result );
			$this->assertSame(
				'Bearer env-native-key',
				WordPressTestState::$last_remote_post['args']['headers']['Authorization'] ?? null
			);
		} finally {
			putenv( 'OPENAI_API_KEY' );
		}
	}

	public function test_rank_wraps_transport_timeout_with_backend_context(): void {
		WordPressTestState::$options              = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'chat-deployment',
		];
		WordPressTestState::$remote_post_response = new \WP_Error(
			'http_request_failed',
			'cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received'
		);

		$result = ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertStringContainsString(
			'Azure OpenAI responses request timed out after 180 seconds while contacting example.openai.azure.com.',
			$result->get_error_message()
		);
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

	public function test_responses_validation_rejects_embedding_payload_shape(): void {
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

		$result = ResponsesClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'chat-deployment'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'Unexpected Azure OpenAI responses validation response format.',
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

	public function test_qdrant_ensure_collection_rejects_vector_size_mismatch(): void {
		WordPressTestState::$options = [
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
		WordPressTestState::$options = [
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
