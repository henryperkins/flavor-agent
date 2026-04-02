<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

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
		$this->assertSame( 'high', $request_body['reasoning']['effort'] ?? null );
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

	public function test_rank_sends_high_reasoning_effort(): void {
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
		$this->assertSame( 'high', $request_body['reasoning']['effort'] ?? null );
		$this->assertSame( 90, WordPressTestState::$last_remote_post['args']['timeout'] ?? null );
	}

	public function test_rank_retries_once_after_rate_limit(): void {
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
			[
				'response' => [
					'code' => 200,
				],
				'body'     => wp_json_encode(
					[
						'output_text' => 'ranked output',
					]
				),
			],
		];

		$result = ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertSame( 'ranked output', $result );
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );
		$this->assertSame(
			'https://example.openai.azure.com/openai/v1/responses',
			WordPressTestState::$remote_post_calls[0]['url'] ?? null
		);
		$this->assertSame(
			90,
			WordPressTestState::$remote_post_calls[1]['args']['timeout'] ?? null
		);
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

	public function test_embed_batch_retries_once_after_rate_limit(): void {
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
			[
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
								'embedding' => [ 0.3, 0.4 ],
							],
						],
					]
				),
			],
		];

		$result = EmbeddingClient::embed_batch( [ 'alpha', 'beta' ] );

		$this->assertSame(
			[
				[ 0.1, 0.2 ],
				[ 0.3, 0.4 ],
			],
			$result
		);
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );
		$this->assertSame(
			'https://example.openai.azure.com/openai/v1/embeddings',
			WordPressTestState::$remote_post_calls[0]['url'] ?? null
		);
		$this->assertSame(
			60,
			WordPressTestState::$remote_post_calls[1]['args']['timeout'] ?? null
		);
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

		$this->assertTrue( $result );
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
		$this->assertSame( 'high', $request_body['reasoning']['effort'] ?? null );
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
			'Azure OpenAI responses request timed out after 90 seconds while contacting example.openai.azure.com.',
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
}
